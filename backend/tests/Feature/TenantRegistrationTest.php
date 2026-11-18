<?php

namespace Tests\Feature;

use App\Mail\VerificationCodeMail;
use App\Models\Branch;
use App\Models\RegistrationAttempt;
use App\Models\Tenant;
use App\Services\Tenancy\TenantRegistrationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class TenantRegistrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_complete_tenant_registration_flow_creates_owner_and_domain(): void
    {
        Mail::fake();

        $start = $this->postJson('/api/v1/public/registration', [
            'business_name' => 'Green Valley Supermarket',
            'display_name' => 'Green Valley',
            'industry' => 'Retail',
            'country' => 'KE',
            'county' => 'Nairobi',
            'owner_name' => 'Amina Odhiambo',
            'owner_email' => 'amina@example.co.ke',
            'owner_phone' => '254700000000',
            'slug' => 'greenvalley',
            'domain_accepted' => true,
            'password' => 'StrongPass1!',
            'password_confirmation' => 'StrongPass1!',
        ]);

        $start->assertCreated()
            ->assertJsonPath('data.slug', 'greenvalley')
            ->assertJsonMissingPath('data.code');

        Mail::assertQueued(VerificationCodeMail::class, 1);
        $mail = Mail::queued(VerificationCodeMail::class)->first();
        $attemptUuid = $start->json('data.attempt_uuid');
        $this->assertDatabaseHas('registration_attempts', ['uuid' => $attemptUuid]);

        $this->postJson('/api/v1/public/registration/verify', [
            'attempt_uuid' => $attemptUuid,
            'code' => $mail->code,
        ])->assertOk()
            ->assertJsonPath('data.completed', true);

        $tenant = Tenant::where('slug', 'greenvalley')->firstOrFail();
        $this->assertDatabaseHas('tenants', ['slug' => 'greenvalley', 'status' => 'active']);
        $this->assertDatabaseHas('users', ['email' => 'amina@example.co.ke', 'must_change_password' => false]);
        $this->assertDatabaseHas('tenant_domains', ['slug' => 'greenvalley', 'status' => 'allocated']);
        $branch = Branch::where('tenant_id', $tenant->id)->where('is_default', true)->firstOrFail();
        $this->assertSame('Head Office', $branch->name);
        $this->assertDatabaseHas('stock_locations', [
            'tenant_id' => $tenant->id,
            'branch_id' => $branch->id,
            'name' => 'Main Store',
            'is_default' => true,
        ]);
    }

    public function test_duplicate_owner_email_is_rejected(): void
    {
        Mail::fake();

        $payload = [
            'business_name' => 'First Business',
            'owner_name' => 'Owner One',
            'owner_email' => 'taken@example.com',
            'slug' => 'firstbusiness',
            'domain_accepted' => true,
            'password' => 'StrongPass1!',
            'password_confirmation' => 'StrongPass1!',
        ];

        $this->postJson('/api/v1/public/registration', $payload)->assertCreated();

        $payload['slug'] = 'secondbusiness';
        $payload['business_name'] = 'Second Business';

        $this->postJson('/api/v1/public/registration', $payload)
            ->assertStatus(422)
            ->assertJsonPath('error.details.owner_email.0', 'An account already exists for this email address.');
    }

    public function test_duplicate_domain_request_returns_conflict_alternatives(): void
    {
        Mail::fake();

        $payload = [
            'business_name' => 'Green Valley Supermarket',
            'owner_name' => 'Owner One',
            'owner_email' => 'owner1@example.com',
            'slug' => 'greenvalley',
            'domain_accepted' => true,
            'password' => 'StrongPass1!',
            'password_confirmation' => 'StrongPass1!',
        ];

        $this->postJson('/api/v1/public/registration', $payload)->assertCreated();

        $payload['owner_name'] = 'Owner Two';
        $payload['owner_email'] = 'owner2@example.com';

        $this->postJson('/api/v1/public/registration', $payload)
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'DOMAIN_CONFLICT')
            ->assertJsonStructure(['error' => ['details' => ['suggestions']]]);
    }

    public function test_verification_rejects_invalid_code_and_resend_has_cooldown(): void
    {
        Mail::fake();

        $start = $this->postJson('/api/v1/public/registration', [
            'business_name' => 'Cool Store',
            'owner_name' => 'Owner Name',
            'owner_email' => 'cool@example.com',
            'slug' => 'coolstore',
            'domain_accepted' => true,
            'password' => 'StrongPass1!',
            'password_confirmation' => 'StrongPass1!',
        ]);

        $attemptUuid = $start->json('data.attempt_uuid');

        $this->postJson('/api/v1/public/registration/verify', [
            'attempt_uuid' => $attemptUuid,
            'code' => '000000',
        ])->assertStatus(422)
            ->assertJsonPath('error.details.code.0', 'That verification code is not valid.');

        $this->postJson('/api/v1/public/registration/resend-code', [
            'attempt_uuid' => $attemptUuid,
        ])->assertStatus(422)
            ->assertJsonStructure(['error' => ['details' => ['attempt']]]);

        $this->assertDatabaseHas('registration_attempts', ['uuid' => $attemptUuid, 'status' => 'verification_pending']);
    }

    public function test_idempotency_key_returns_the_existing_attempt(): void
    {
        Mail::fake();

        $payload = [
            'business_name' => 'Idempotent Store',
            'owner_name' => 'Owner Name',
            'owner_email' => 'idempotent@example.com',
            'slug' => 'idempotentstore',
            'domain_accepted' => true,
            'password' => 'StrongPass1!',
            'password_confirmation' => 'StrongPass1!',
        ];

        $headers = ['Idempotency-Key' => 'tenant-registration-001'];
        $first = $this->withHeaders($headers)->postJson('/api/v1/public/registration', $payload)->assertCreated();
        $second = $this->withHeaders($headers)->postJson('/api/v1/public/registration', $payload)->assertCreated();

        $this->assertSame($first->json('data.attempt_uuid'), $second->json('data.attempt_uuid'));
        $this->assertTrue($second->json('data.duplicate'));
    }

    public function test_reusing_an_idempotency_key_for_different_details_returns_422(): void
    {
        Mail::fake();

        $payload = [
            'business_name' => 'Idempotent Store',
            'owner_name' => 'Owner Name',
            'owner_email' => 'idempotent@example.com',
            'slug' => 'idempotentstore',
            'domain_accepted' => true,
            'password' => 'StrongPass1!',
            'password_confirmation' => 'StrongPass1!',
        ];
        $headers = ['Idempotency-Key' => 'tenant-registration-002'];

        $this->withHeaders($headers)->postJson('/api/v1/public/registration', $payload)->assertCreated();
        $payload['business_name'] = 'Different Store';

        $this->withHeaders($headers)->postJson('/api/v1/public/registration', $payload)
            ->assertUnprocessable()
            ->assertJsonPath(
                'error.details.Idempotency-Key.0',
                'This idempotency key was already used with different registration details.',
            );

        $this->assertDatabaseCount('registration_attempts', 1);
    }

    public function test_expired_registration_releases_its_email_and_domain_for_a_retry(): void
    {
        Mail::fake();

        $payload = [
            'business_name' => 'Expired Store',
            'owner_name' => 'Owner Name',
            'owner_email' => 'expired@example.com',
            'slug' => 'expiredstore',
            'domain_accepted' => true,
            'password' => 'StrongPass1!',
            'password_confirmation' => 'StrongPass1!',
        ];
        $headers = ['Idempotency-Key' => 'tenant-registration-expiring'];

        $first = $this->withHeaders($headers)->postJson('/api/v1/public/registration', $payload)->assertCreated();
        RegistrationAttempt::where('uuid', $first->json('data.attempt_uuid'))
            ->update(['expires_at' => now()->subMinute()]);

        $this->assertSame(1, app(TenantRegistrationService::class)->expireAbandonedRegistrations());
        $this->assertDatabaseHas('registration_attempts', [
            'uuid' => $first->json('data.attempt_uuid'),
            'status' => 'expired',
        ]);

        $retry = $this->withHeaders($headers)->postJson('/api/v1/public/registration', $payload)->assertCreated();

        $this->assertNotSame($first->json('data.attempt_uuid'), $retry->json('data.attempt_uuid'));
        $this->assertDatabaseCount('domain_reservations', 2);
    }

    public function test_registration_requires_explicit_domain_acceptance(): void
    {
        $this->postJson('/api/v1/public/registration', [
            'business_name' => 'Accept Store',
            'owner_name' => 'Owner Name',
            'owner_email' => 'accept@example.com',
            'slug' => 'acceptstore',
            'domain_accepted' => false,
            'password' => 'StrongPass1!',
            'password_confirmation' => 'StrongPass1!',
        ])->assertUnprocessable()
            ->assertJsonStructure(['error' => ['details' => ['domain_accepted']]]);

        $this->assertDatabaseCount('registration_attempts', 0);
    }
}
