<?php

namespace Tests\Feature;

use App\Models\Marketing\CoreWebVital;
use App\Models\Marketing\MarketingRedirect;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

class SeoOperationsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $reviewer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
        $this->admin = $this->platformUser('super-admin');
        $this->reviewer = $this->platformUser('super-admin');
    }

    public function test_slug_change_on_publish_creates_a_301_redirect(): void
    {
        $pageId = $this->createDraft('old-path', 'Old path page');

        $this->publish($pageId);
        $this->get('/old-path')->assertOk()->assertSee('Old path page');

        $this->as($this->admin)
            ->patchJson("/api/v1/platform/marketing/pages/{$pageId}", ['slug' => 'new-path'])
            ->assertOk();

        $this->submitApprovePublish($pageId);

        $this->assertDatabaseHas('marketing_redirects', [
            'from_path' => '/old-path',
            'to_path' => '/new-path',
            'status_code' => 301,
            'is_active' => true,
        ]);

        $this->get('/old-path')->assertStatus(301)->assertRedirect('/new-path');
        $this->get('/new-path')->assertOk()->assertSee('Old path page');
    }

    public function test_redirect_chains_resolve_and_manual_redirects_are_permissioned_and_audited(): void
    {
        $this->as($this->admin)
            ->postJson('/api/v1/platform/marketing/seo/redirects', [
                'from_path' => '/one',
                'to_path' => '/two',
            ])
            ->assertCreated()
            ->assertJsonPath('data.redirect.from_path', '/one');

        $second = $this->as($this->admin)
            ->postJson('/api/v1/platform/marketing/seo/redirects', [
                'from_path' => '/two',
                'to_path' => '/three',
            ])
            ->assertCreated()
            ->json('data.redirect');

        $this->get('/one')->assertStatus(301)->assertRedirect('/three');
        $this->assertSame(1, MarketingRedirect::query()->where('from_path', '/one')->value('hits'));

        $this->as($this->admin)
            ->postJson('/api/v1/platform/marketing/seo/redirects', [
                'from_path' => '/three',
                'to_path' => '/one',
            ])
            ->assertStatus(422)
            ->assertJsonStructure(['error' => ['details' => ['to_path']]]);

        $editor = $this->platformUser('content-editor');
        $this->as($editor)
            ->getJson('/api/v1/platform/marketing/seo/redirects')
            ->assertOk()
            ->assertJsonCount(2, 'data.redirects');
        $this->as($editor)
            ->postJson('/api/v1/platform/marketing/seo/redirects', [
                'from_path' => '/blocked',
                'to_path' => '/three',
            ])
            ->assertForbidden();

        $this->as($this->admin)
            ->patchJson("/api/v1/platform/marketing/seo/redirects/{$second['id']}", ['is_active' => false])
            ->assertOk()
            ->assertJsonPath('data.redirect.is_active', false);

        $this->get('/one')->assertStatus(301)->assertRedirect('/two');
        $this->get('/two')->assertNotFound();

        $this->as($this->admin)
            ->deleteJson("/api/v1/platform/marketing/seo/redirects/{$second['id']}")
            ->assertOk();

        $this->assertDatabaseHas('audit_logs', ['action' => 'marketing.redirect.created']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'marketing.redirect.updated']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'marketing.redirect.deleted']);
    }

    public function test_diagnostics_detect_missing_duplicate_broken_canonical_orphan_and_chain_issues(): void
    {
        config(['marketing.indexable' => true]);

        $alpha = $this->createDraft('alpha-page', 'Alpha', [
            'title' => 'Shared title',
            'description' => null,
            'canonical_url' => config('app.url').'/ghost-page',
        ], [
            ['type' => 'rich_text', 'content' => ['heading' => 'Alpha', 'bullets' => ['/beta-page', '/missing-page']]],
        ]);
        $this->publish($alpha);

        $beta = $this->createDraft('beta-page', 'Beta', [
            'title' => 'Beta title',
            'description' => 'Shared description',
        ]);
        $this->publish($beta);

        $gamma = $this->createDraft('gamma-page', 'Gamma', [
            'title' => 'Shared title',
            'description' => 'Shared description',
        ]);
        $this->publish($gamma);

        MarketingRedirect::create(['from_path' => '/chain-a', 'to_path' => '/chain-b', 'status_code' => 301, 'is_active' => true]);
        MarketingRedirect::create(['from_path' => '/chain-b', 'to_path' => '/chain-c', 'status_code' => 301, 'is_active' => true]);

        $response = $this->as($this->admin)
            ->getJson('/api/v1/platform/marketing/seo/diagnostics')
            ->assertOk();

        $response
            ->assertJsonPath('data.summary.missing_metadata', 1)
            ->assertJsonPath('data.summary.duplicate_titles', 1)
            ->assertJsonPath('data.summary.duplicate_descriptions', 1)
            ->assertJsonPath('data.summary.redirect_chains', 1);

        $issues = $response->json('data.issues');
        $this->assertSame('/alpha-page', $issues['missing_metadata'][0]['path']);
        $this->assertContains('/alpha-page', $issues['duplicate_titles'][0]['paths']);
        $this->assertContains('/gamma-page', $issues['duplicate_titles'][0]['paths']);
        $this->assertContains('/missing-page', array_column($issues['broken_links'], 'path'));
        $this->assertSame('/alpha-page', $issues['broken_canonicals'][0]['path']);
        $this->assertSame(['/chain-a', '/chain-b', '/chain-c'], $issues['redirect_chains'][0]['chain']);

        $orphans = array_column($issues['orphan_pages'], 'path');
        $this->assertContains('/alpha-page', $orphans);
        $this->assertContains('/gamma-page', $orphans);
        $this->assertNotContains('/beta-page', $orphans);
    }

    public function test_web_vitals_beacon_stores_samples_and_aggregation_reports_percentiles(): void
    {
        RateLimiter::clear('web-vitals:127.0.0.1');

        foreach ([1000, 2000, 3000, 4000] as $index => $lcp) {
            $this->postJson('/api/v1/public/web-vitals', [
                'url' => 'http://localhost/pricing',
                'device' => $index % 2 === 0 ? 'mobile' : 'desktop',
                'lcp' => $lcp,
                'cls' => 0.05,
                'inp' => 120,
                'ttfb' => 300,
            ])->assertStatus(202)->assertJsonPath('data.path', '/pricing');
        }

        $this->postJson('/api/v1/public/web-vitals', [])->assertStatus(422);

        $this->assertSame(4, CoreWebVital::query()->count());

        $summary = $this->as($this->admin)
            ->getJson('/api/v1/platform/marketing/seo/vitals?days=7')
            ->assertOk()
            ->json('data');

        $this->assertSame(4, $summary['summary']['samples']);
        $this->assertSame(3000.0, (float) $summary['summary']['lcp_p75']);
        $this->assertSame(50.0, (float) $summary['summary']['lcp_good_pct']);
        $this->assertSame('/pricing', $summary['by_path'][0]['path']);
        $this->assertSame(4, $summary['by_path'][0]['samples']);

        $tenant = $this->platformUser('business-owner');
        $this->as($tenant)
            ->getJson('/api/v1/platform/marketing/seo/vitals')
            ->assertForbidden();
    }

    public function test_web_vitals_beacon_is_rate_limited(): void
    {
        RateLimiter::clear('web-vitals:127.0.0.1');

        for ($i = 0; $i < 60; $i++) {
            $this->postJson('/api/v1/public/web-vitals', ['url' => 'http://localhost/'])->assertStatus(202);
        }

        $this->postJson('/api/v1/public/web-vitals', ['url' => 'http://localhost/'])
            ->assertStatus(429)
            ->assertJsonPath('error.code', 'RATE_LIMITED');
    }

    /**
     * @param  array<string, mixed>  $seo
     * @param  array<int, array<string, mixed>>  $sections
     */
    private function createDraft(string $slug, string $title, array $seo = [], array $sections = []): string
    {
        return $this->as($this->admin)
            ->postJson('/api/v1/platform/marketing/pages', [
                'slug' => $slug,
                'title' => $title,
                'sections' => $sections ?: [
                    ['type' => 'rich_text', 'content' => ['heading' => $title, 'paragraphs' => ['Seeded body']]],
                ],
                'seo' => array_merge([
                    'title' => $title,
                    'description' => 'A test page.',
                    'robots_index' => true,
                ], $seo),
            ])
            ->assertCreated()
            ->json('data.page.id');
    }

    private function publish(string $pageId): void
    {
        $this->as($this->admin)->postJson("/api/v1/platform/marketing/pages/{$pageId}/submit-review")->assertOk();
        $this->as($this->reviewer)->postJson("/api/v1/platform/marketing/pages/{$pageId}/approve")->assertOk();
        $this->as($this->reviewer)->postJson("/api/v1/platform/marketing/pages/{$pageId}/publish")->assertOk();
    }

    private function submitApprovePublish(string $pageId): void
    {
        $this->publish($pageId);
    }

    private function platformUser(string $role): User
    {
        $user = User::factory()->create();
        $user->assignRole($role);

        return $user;
    }

    private function as(User $user): static
    {
        $this->app->make('auth')->forgetGuards();
        $this->app->forgetScopedInstances();

        return $this->withToken($user->createToken('test')->plainTextToken);
    }
}
