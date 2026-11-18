<?php

namespace Tests\Feature;

use App\Models\Marketing\LeadActivity;
use App\Models\Marketing\MarketingConsent;
use App\Models\Marketing\MarketingLead;
use App\Models\StocktakeJob;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

class LeadCaptureTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
        RateLimiter::clear('form-ip:127.0.0.1');
    }

    public function test_quote_submission_stores_lead_consent_attribution_and_activity(): void
    {
        $response = $this->postJson('/api/v1/public/forms/quote', [
            'contact_name' => 'Amina Yusuf',
            'business_name' => 'Green Valley Supermarket',
            'email' => 'AMINA@example.test',
            'phone' => '0712 345 678',
            'branches_count' => 4,
            'catalogue_size' => 8000,
            'service_requested' => 'Recurring schedules',
            'stocktake_frequency' => 'Monthly',
            'message' => 'We need a monthly count across four branches.',
            'consent_transactional' => true,
            'consent_marketing' => true,
            'utm_source' => 'google',
            'utm_medium' => 'cpc',
            'utm_campaign' => 'stocktake-november',
            'landing_page' => 'https://brathamatu.test/request-quote',
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.created', true)
            ->assertJsonPath('data.message', 'Thank you. Your quotation request has been received and the team will review it.');

        $reference = $response->json('data.reference');
        $lead = MarketingLead::query()->where('reference', $reference)->firstOrFail();

        $this->assertSame('quote', $lead->type);
        $this->assertSame('new', $lead->status);
        $this->assertSame('amina@example.test', $lead->email);
        $this->assertSame('google', $lead->utm_source);
        $this->assertSame('stocktake-november', $lead->utm_campaign);
        $this->assertSame('https://brathamatu.test/request-quote', $lead->landing_page);
        $this->assertTrue($lead->consent_marketing);
        $this->assertTrue($lead->consent_transactional);
        $this->assertNotNull($lead->consent_at);

        $consents = MarketingConsent::query()
            ->where('subject_type', 'lead')
            ->where('subject_id', $lead->id)
            ->pluck('status', 'channel')
            ->all();
        $this->assertSame(['marketing' => 'granted', 'email' => 'granted'], $consents);

        $this->assertSame(1, LeadActivity::query()->where('lead_id', $lead->id)->where('type', 'note')->count());
        $this->assertSame(0, StocktakeJob::query()->count());
    }

    public function test_duplicate_submissions_are_idempotent(): void
    {
        $payload = [
            'contact_name' => 'Brian Otieno',
            'business_name' => 'Otieno Hardware',
            'email' => 'brian@example.test',
            'phone' => '0722000111',
            'message' => 'Looking for a quarterly count.',
            'consent_transactional' => true,
        ];

        $first = $this->postJson('/api/v1/public/forms/quote', $payload)->assertCreated();
        $second = $this->postJson('/api/v1/public/forms/quote', $payload)
            ->assertCreated()
            ->assertJsonPath('data.created', false);

        $this->assertSame($first->json('data.reference'), $second->json('data.reference'));
        $this->assertSame(1, MarketingLead::query()->count());
        $this->assertSame(2, MarketingConsent::query()->count());
    }

    public function test_idempotency_key_header_reuses_the_original_reference(): void
    {
        $payload = [
            'contact_name' => 'Carol Wanjiku',
            'email' => 'carol@example.test',
            'message' => 'Please call about a stock count.',
            'consent_transactional' => true,
        ];

        $first = $this->withHeader('Idempotency-Key', 'form-run-1')
            ->postJson('/api/v1/public/forms/contact', $payload)
            ->assertCreated();

        $this->withHeader('Idempotency-Key', 'form-run-1')
            ->postJson('/api/v1/public/forms/contact', $payload)
            ->assertCreated()
            ->assertJsonPath('data.reference', $first->json('data.reference'));
    }

    public function test_honeypot_submissions_are_silently_discarded(): void
    {
        $response = $this->postJson('/api/v1/public/forms/contact', [
            'website' => 'https://spam.example',
            'contact_name' => 'Bot',
            'email' => 'bot@example.test',
            'message' => 'Buy cheap stocktake.',
            'consent_transactional' => true,
        ]);

        $response->assertCreated()->assertJsonPath('data.reference', 'RECEIVED');
        $this->assertSame(0, MarketingLead::query()->count());
    }

    public function test_validation_requires_contact_details_and_consent(): void
    {
        $this->postJson('/api/v1/public/forms/quote', [
            'contact_name' => 'No Contact',
        ])->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_FAILED')
            ->assertJsonStructure(['error' => ['details' => ['email', 'phone', 'business_name', 'consent_transactional']]]);

        $this->postJson('/api/v1/public/forms/contact', [
            'contact_name' => 'No Message',
            'email' => 'person@example.test',
            'consent_transactional' => true,
        ])->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_FAILED')
            ->assertJsonStructure(['error' => ['details' => ['message']]]);
    }

    public function test_web_form_submission_redirects_with_reference(): void
    {
        $response = $this->post('/forms/pos-demo', [
            'contact_name' => 'Dennis Mwangi',
            'business_name' => 'Mwangi Pharmacy',
            'email' => 'dennis@example.test',
            'phone' => '0733111222',
            'industry' => 'Pharmacy',
            'message' => 'Show the sales and inventory workflow.',
            'consent_transactional' => '1',
            'consent_marketing' => '1',
            'landing_page' => 'http://localhost/book-demo',
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('lead_success');

        $lead = MarketingLead::query()->where('email', 'dennis@example.test')->firstOrFail();
        $this->assertSame('pos_demo', $lead->type);
        $this->assertSame('Pharmacy', $lead->industry);
    }

    public function test_public_form_submissions_are_rate_limited_per_ip(): void
    {
        for ($i = 1; $i <= 12; $i++) {
            $this->postJson('/api/v1/public/forms/contact', [
                'contact_name' => 'Person '.$i,
                'email' => "person{$i}@example.test",
                'message' => 'Enquiry number '.$i,
                'consent_transactional' => true,
            ])->assertCreated();
        }

        $this->postJson('/api/v1/public/forms/contact', [
            'contact_name' => 'Person 13',
            'email' => 'person13@example.test',
            'message' => 'Enquiry number 13',
            'consent_transactional' => true,
        ])->assertStatus(429)
            ->assertJsonPath('error.code', 'RATE_LIMITED');
    }

    public function test_public_form_api_routes_are_post_only(): void
    {
        $this->getJson('/api/v1/public/forms/quote')->assertNotFound();
    }
}
