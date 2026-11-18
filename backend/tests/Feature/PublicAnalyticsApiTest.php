<?php

namespace Tests\Feature;

use App\Models\Marketing\AnalyticsEvent;
use App\Models\Marketing\ConversionEvent;
use App\Models\Marketing\MarketingCampaign;
use App\Models\Marketing\TrackingPreference;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

class PublicAnalyticsApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        RateLimiter::clear('public-events:127.0.0.1');
    }

    public function test_events_are_rejected_without_analytics_consent(): void
    {
        $this->postJson('/api/v1/public/analytics/events', [
            'session_id' => 'visitor-12345678',
            'events' => [['name' => 'page_view', 'path' => '/']],
        ])->assertStatus(422)->assertJsonPath('error.code', 'CONSENT_REQUIRED');

        $this->assertSame(0, AnalyticsEvent::query()->count());
    }

    public function test_consent_then_events_are_stored_without_pii(): void
    {
        $this->postJson('/api/v1/public/analytics/consent', [
            'session_id' => 'visitor-12345678',
            'analytics' => true,
            'policy_version' => '2026-11',
        ])->assertOk()->assertJsonPath('data.analytics', true);

        $this->postJson('/api/v1/public/analytics/events', [
            'session_id' => 'visitor-12345678',
            'events' => [[
                'name' => 'cta_click',
                'url' => 'https://brathamatu.test/stocktaking?utm_source=demo',
                'utm' => ['utm_source' => 'demo', 'utm_campaign' => 'demo-launch'],
                'device' => 'mobile',
                'payload' => [
                    'label' => 'Request a quote',
                    'email' => 'person@example.test',
                    'phone' => '+254700000000',
                    'contact_name' => 'Private Person',
                ],
            ]],
        ])->assertStatus(202)->assertJsonPath('data.accepted', 1);

        $event = AnalyticsEvent::query()->firstOrFail();
        $this->assertSame('cta_click', $event->event_name);
        $this->assertSame('/stocktaking', $event->path);
        $this->assertSame('Request a quote', $event->payload['label']);
        $this->assertArrayNotHasKey('email', $event->payload);
        $this->assertArrayNotHasKey('phone', $event->payload);
        $this->assertArrayNotHasKey('contact_name', $event->payload);
        $this->assertTrue($event->consent_state['analytics']);
        $this->assertNotNull($event->ip_hash);
        $this->assertNotSame('127.0.0.1', $event->ip_hash);
    }

    public function test_events_are_attributed_to_a_matching_campaign(): void
    {
        $campaign = MarketingCampaign::create([
            'name' => 'Demo Launch',
            'objective' => 'lead_generation',
            'channel' => 'website',
            'status' => 'active',
            'utm' => ['source' => 'demo', 'medium' => 'website', 'campaign' => 'demo-launch'],
        ]);

        TrackingPreference::create(['session_id' => 'visitor-abcdefgh', 'analytics' => true]);
        $this->postJson('/api/v1/public/analytics/events', [
            'session_id' => 'visitor-abcdefgh',
            'events' => [[
                'name' => 'page_view',
                'path' => '/',
                'utm' => ['utm_source' => 'demo', 'utm_campaign' => 'demo-launch'],
            ]],
        ])->assertStatus(202);

        $this->assertSame($campaign->id, AnalyticsEvent::query()->firstOrFail()->campaign_id);
    }

    public function test_withdrawal_stops_future_tracking(): void
    {
        TrackingPreference::create(['session_id' => 'visitor-withdraw1', 'analytics' => true]);

        $payload = [
            'session_id' => 'visitor-withdraw1',
            'events' => [['name' => 'page_view', 'path' => '/']],
        ];

        $this->postJson('/api/v1/public/analytics/events', $payload)->assertStatus(202);
        $this->assertSame(1, AnalyticsEvent::query()->count());

        $this->postJson('/api/v1/public/analytics/consent', [
            'session_id' => 'visitor-withdraw1',
            'analytics' => false,
        ])->assertOk()->assertJsonPath('data.analytics', false);

        $this->postJson('/api/v1/public/analytics/events', $payload)
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'CONSENT_REQUIRED');

        $this->assertSame(1, AnalyticsEvent::query()->count());
    }

    public function test_unknown_events_are_rejected(): void
    {
        TrackingPreference::create(['session_id' => 'visitor-unknown1', 'analytics' => true]);

        $this->postJson('/api/v1/public/analytics/events', [
            'session_id' => 'visitor-unknown1',
            'events' => [['name' => 'keystroke_capture', 'path' => '/']],
        ])->assertStatus(422);
    }

    public function test_form_submission_records_a_reconciled_server_side_conversion(): void
    {
        $campaign = MarketingCampaign::create([
            'name' => 'Demo Launch',
            'objective' => 'lead_generation',
            'channel' => 'website',
            'status' => 'active',
            'utm' => ['source' => 'demo', 'medium' => 'website', 'campaign' => 'demo-launch'],
        ]);

        $this->postJson('/api/v1/public/forms/quote', [
            'contact_name' => 'Amina Yusuf',
            'business_name' => 'Green Valley Supermarket',
            'email' => 'amina@example.test',
            'consent_transactional' => true,
            'consent_marketing' => true,
            'utm_source' => 'demo',
            'utm_medium' => 'website',
            'utm_campaign' => 'demo-launch',
            'landing_page' => 'https://brathamatu.test/request-quote',
        ])->assertCreated();

        $conversion = ConversionEvent::query()->firstOrFail();
        $this->assertSame('quote_request', $conversion->event_name);
        $this->assertNotNull($conversion->lead_id);
        $this->assertSame($campaign->id, $conversion->campaign_id);
        $this->assertSame('/request-quote', $conversion->metadata['path']);
        $this->assertTrue($conversion->consent_state['analytics']);
    }
}
