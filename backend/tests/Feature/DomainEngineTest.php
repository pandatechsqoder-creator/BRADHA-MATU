<?php

namespace Tests\Feature;

use App\Models\DomainReservation;
use App\Models\Tenant;
use App\Models\TenantDomain;
use App\Models\User;
use App\Services\Tenancy\DomainEngine;
use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

class DomainEngineTest extends TestCase
{
    use RefreshDatabase;

    public function test_suggestions_are_generated_from_the_business_name(): void
    {
        $response = $this->getJson('/api/v1/public/domains/suggestions?business_name=Green%20Valley%20Supermarket&location=Nairobi');

        $response->assertOk()
            ->assertJsonPath('data.suggestions.0.slug', 'greenvalley')
            ->assertJsonFragment(['slug' => 'greenvalleysupermarket'])
            ->assertJsonFragment(['slug' => 'greenvalleyshop'])
            ->assertJsonFragment(['slug' => 'greenvalley2']);
    }

    public function test_reserved_and_existing_names_are_unavailable(): void
    {
        $domains = app(DomainEngine::class);

        $this->assertFalse($domains->isAvailable('admin'));

        Tenant::create(['name' => 'Green Valley', 'slug' => 'greenvalley', 'status' => 'active']);

        $this->assertFalse($domains->isAvailable('greenvalley'));
    }

    public function test_reservation_is_atomic_and_second_request_gets_conflict_alternatives(): void
    {
        $domains = app(DomainEngine::class);

        $first = $domains->reserve('greenvalley', 'Green Valley Supermarket', 'owner@example.com');
        $second = $domains->reserve('greenvalley', 'Green Valley Supermarket', 'other@example.com');

        $this->assertTrue($first['reserved']);
        $this->assertTrue($second['conflict']);
        $this->assertContains('greenvalley2', array_column($second['suggestions'], 'slug'));
    }

    public function test_invalid_and_reserved_slugs_are_rejected_by_the_api(): void
    {
        $this->postJson('/api/v1/public/domains/check', [
            'business_name' => 'Green Valley',
            'slug' => 'admin',
        ])->assertStatus(422)
            ->assertJsonPath('error.details.slug.0', 'This domain name is reserved or prohibited.');

        $this->postJson('/api/v1/public/domains/check', [
            'business_name' => 'Green Valley',
            'slug' => 'green valley!',
        ])->assertStatus(422)
            ->assertJsonStructure(['error' => ['details' => ['slug']]]);
    }

    public function test_expired_reservation_can_be_reused(): void
    {
        $domains = app(DomainEngine::class);
        $first = $domains->reserve('renewme', 'Renew Me', 'owner@example.com', null, null, -1);

        $this->assertTrue($first['reserved']);
        $this->assertSame(1, $domains->expireReservations());
        $this->assertTrue($domains->isAvailable('renewme'));

        $second = $domains->reserve('renewme', 'Renew Me', 'new-owner@example.com');

        $this->assertTrue($second['reserved']);
        $this->assertSame(2, DomainReservation::where('slug', 'renewme')->count());
        $this->assertDatabaseHas('domain_allocation_logs', [
            'slug' => 'renewme',
            'action' => 'reservation_expired',
        ]);
    }

    public function test_repeated_reservation_for_the_same_registration_attempt_is_idempotent(): void
    {
        $domains = app(DomainEngine::class);

        $first = $domains->reserve('retrybusiness', 'Retry Business', 'owner@example.com', '01J9Z000000000000000000000');
        $retry = $domains->reserve('retrybusiness', 'Retry Business', 'owner@example.com', '01J9Z000000000000000000000');

        $this->assertTrue($first['reserved']);
        $this->assertTrue($retry['reserved']);
        $this->assertSame($first['expires_at'], $retry['expires_at']);
        $this->assertSame(1, DomainReservation::where('slug', 'retrybusiness')->count());
    }

    public function test_conflict_alternatives_prioritize_the_next_numbered_name(): void
    {
        $domains = app(DomainEngine::class);
        $domains->reserve('greenvalley', 'Green Valley Supermarket', 'owner@example.com');

        $conflict = $domains->reserve('greenvalley', 'Green Valley Supermarket', 'other@example.com');

        $this->assertTrue($conflict['conflict']);
        $this->assertSame('greenvalley2', $conflict['suggestions'][0]['slug']);
    }

    public function test_normalization_transliterates_names_and_obeys_configured_slug_length(): void
    {
        config([
            'tenancy.minimum_slug_length' => 3,
            'tenancy.maximum_slug_length' => 12,
        ]);

        $domains = app(DomainEngine::class);

        $this->assertSame('josemarket', $domains->normalize('José Market'));

        $this->expectException(\InvalidArgumentException::class);
        $domains->normalize('AB');
    }

    public function test_allocated_tenant_subdomain_is_resolved_and_unknown_subdomains_are_rejected(): void
    {
        config(['tenancy.base_domain' => 'communitygroup.co.ke']);
        $tenant = Tenant::create(['name' => 'Green Valley', 'slug' => 'greenvalley', 'status' => 'active']);
        TenantDomain::create([
            'tenant_id' => $tenant->id,
            'slug' => 'greenvalley',
            'subdomain' => 'greenvalley',
            'status' => 'allocated',
            'allocated_at' => now(),
        ]);

        $this->getJson('https://greenvalley.communitygroup.co.ke/api/v1/public/domains/suggestions?business_name=Another%20Business')
            ->assertOk();

        $this->getJson('https://notallocated.communitygroup.co.ke/api/v1/public/domains/suggestions?business_name=Another%20Business')
            ->assertNotFound();
    }

    public function test_tenant_context_uses_the_host_tenant_and_rejects_a_mismatched_tenant_header(): void
    {
        $tenant = Tenant::create(['name' => 'Green Valley', 'slug' => 'greenvalley', 'status' => 'active']);
        $otherTenant = Tenant::create(['name' => 'Blue Market', 'slug' => 'bluemarket', 'status' => 'active']);
        $user = User::factory()->create();
        $tenant->members()->attach($user->id, ['status' => 'active', 'joined_at' => now()]);

        $request = Request::create('https://greenvalley.communitygroup.co.ke/api/v1/tenant');
        $request->setUserResolver(fn () => $user);
        $request->attributes->set('tenant_host_id', $tenant->id);
        $this->assertSame($tenant->id, (new TenantContext($request))->id());

        $request->headers->set('X-Tenant-Id', $otherTenant->uuid);
        $this->assertNull((new TenantContext($request))->id());
    }

    public function test_allocated_tenant_web_hosts_are_not_redirected_to_the_canonical_parent_host(): void
    {
        config([
            'tenancy.base_domain' => 'communitygroup.co.ke',
            'marketing.canonical_base' => 'https://communitygroup.co.ke',
        ]);
        $tenant = Tenant::create(['name' => 'Green Valley', 'slug' => 'greenvalley', 'status' => 'active']);
        TenantDomain::create([
            'tenant_id' => $tenant->id,
            'slug' => 'greenvalley',
            'subdomain' => 'greenvalley',
            'status' => 'allocated',
            'allocated_at' => now(),
        ]);

        $this->get('https://greenvalley.communitygroup.co.ke/robots.txt')->assertOk();
    }
}
