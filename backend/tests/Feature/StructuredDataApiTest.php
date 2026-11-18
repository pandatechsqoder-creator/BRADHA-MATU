<?php

namespace Tests\Feature;

use App\Models\Marketing\CmsPage;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class StructuredDataApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
    }

    public function test_structured_data_endpoint_returns_a_valid_graph_matching_the_page(): void
    {
        $author = $this->platformUser('content-manager');
        $page = $this->createPage($author, 'about-brathamatu', 'About BRATHAMATU');

        $response = $this->as($author)
            ->getJson("/api/v1/platform/marketing/pages/{$page['id']}/structured-data")
            ->assertOk()
            ->assertJsonPath('data.validation.status', 'valid')
            ->assertJsonPath('data.structured_data.@context', 'https://schema.org')
            ->assertJsonPath('data.page.slug', 'about-brathamatu');

        $graph = collect($response->json('data.structured_data.@graph'))->keyBy('@type');

        $this->assertSame(['Organization', 'WebSite', 'WebPage', 'BreadcrumbList'], $graph->keys()->all());
        $this->assertSame('About BRATHAMATU', $graph['WebPage']['name']);
        $this->assertSame('A test page.', $graph['WebPage']['description']);
        $this->assertSame('About BRATHAMATU', collect($graph['BreadcrumbList']['itemListElement'])->last()['name']);

        $json = json_encode($graph->all());
        foreach (['aggregateRating', 'ratingValue', 'offers', 'price', 'review'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $json);
        }
    }

    public function test_faq_markup_matches_the_visible_faq_section(): void
    {
        $author = $this->platformUser('content-manager');
        $reviewer = $this->platformUser('content-manager');

        $page = $this->as($author)->postJson('/api/v1/platform/marketing/pages', [
            'slug' => 'faq-markup',
            'title' => 'FAQ markup',
            'sections' => [
                ['type' => 'rich_text', 'content' => ['heading' => 'FAQ markup', 'paragraphs' => ['Body']]],
                ['type' => 'faq', 'content' => ['heading' => 'Questions', 'items' => [
                    ['question' => 'Do you count every aisle?', 'answer' => 'Yes, every aisle is counted.'],
                ]]],
            ],
            'seo' => ['title' => 'FAQ markup', 'description' => 'Answers to common questions.'],
        ])->assertCreated()->json('data.page');

        $this->as($author)
            ->getJson("/api/v1/platform/marketing/pages/{$page['id']}/structured-data")
            ->assertOk()
            ->assertJsonPath('data.validation.status', 'valid')
            ->assertJsonPath('data.validation.types', ['organization', 'website', 'webpage', 'breadcrumb', 'faq']);

        $this->submitApprovePublish($page['id'], $author, $reviewer);

        $this->get('/faq-markup')
            ->assertOk()
            ->assertSee('application/ld+json', false)
            ->assertSee('Do you count every aisle?', false)
            ->assertSee('FAQPage', false)
            ->assertSee('<meta name="robots" content="noindex, follow">', false);
    }

    public function test_validation_runs_are_recorded_and_listed(): void
    {
        $author = $this->platformUser('content-manager');
        $page = $this->createPage($author, 'validation-runs', 'Validation runs');

        $this->as($author)
            ->postJson("/api/v1/platform/marketing/pages/{$page['id']}/structured-data/validate")
            ->assertOk()
            ->assertJsonPath('data.validation.status', 'valid')
            ->assertJsonPath('data.run.status', 'valid');

        $this->as($author)
            ->getJson("/api/v1/platform/marketing/pages/{$page['id']}/structured-data/runs")
            ->assertOk()
            ->assertJsonCount(1, 'data.runs')
            ->assertJsonPath('data.runs.0.status', 'valid')
            ->assertJsonPath('data.runs.0.json_ld.@context', 'https://schema.org');
    }

    public function test_requested_article_markup_flags_a_blocking_issue_and_prevents_publishing(): void
    {
        $author = $this->platformUser('content-manager');
        $reviewer = $this->platformUser('content-manager');
        $page = $this->createPage($author, 'article-markup', 'Article markup');

        $this->as($author)
            ->putJson("/api/v1/platform/marketing/pages/{$page['id']}/structured-data", [
                'types' => ['article', 'local_business'],
            ])
            ->assertOk()
            ->assertJsonPath('data.settings.types', ['article', 'local_business']);

        $this->as($author)
            ->postJson("/api/v1/platform/marketing/pages/{$page['id']}/structured-data/validate")
            ->assertOk()
            ->assertJsonPath('data.validation.status', 'invalid')
            ->assertJsonPath('data.validation.issues.0.type', 'article')
            ->assertJsonPath('data.run.status', 'invalid');

        $this->as($author)
            ->putJson("/api/v1/platform/marketing/pages/{$page['id']}/structured-data", ['types' => ['article']])
            ->assertOk();

        $this->as($author)->postJson("/api/v1/platform/marketing/pages/{$page['id']}/submit-review")->assertOk();
        $this->as($reviewer)->postJson("/api/v1/platform/marketing/pages/{$page['id']}/approve")->assertOk();

        $this->as($reviewer)
            ->postJson("/api/v1/platform/marketing/pages/{$page['id']}/publish")
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_FAILED')
            ->assertJsonPath('error.details.structured_data.0', 'Structured data must be valid before publishing. Article/BlogPosting markup is generated from blog posts; publish the content as a blog post instead.');

        $this->assertSame('approved', CmsPage::query()->where('uuid', $page['id'])->value('status'));
    }

    public function test_preview_renders_json_ld_without_canonical_indexing(): void
    {
        $author = $this->platformUser('content-manager');
        $page = $this->createPage($author, 'preview-markup', 'Preview markup');

        $this->as($author)->postJson("/api/v1/platform/marketing/pages/{$page['id']}/submit-review")->assertOk();

        $version = $this->as($author)
            ->getJson("/api/v1/platform/marketing/pages/{$page['id']}/versions")
            ->assertOk()
            ->json('data.versions.0');

        $url = URL::temporarySignedRoute('marketing.preview', now()->addMinutes(10), [
            'page' => $page['id'],
            'version' => $version['id'],
        ]);

        $this->get($url)
            ->assertOk()
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow')
            ->assertSee('application/ld+json', false)
            ->assertSee('WebPage', false)
            ->assertSee('Preview markup', false);
    }

    public function test_structured_data_requires_marketing_permissions(): void
    {
        $author = $this->platformUser('content-manager');
        $page = $this->createPage($author, 'permission-markup', 'Permission markup');
        $tenant = $this->platformUser('business-owner');

        $this->as($tenant)
            ->getJson("/api/v1/platform/marketing/pages/{$page['id']}/structured-data")
            ->assertForbidden();

        $this->as($tenant)
            ->putJson("/api/v1/platform/marketing/pages/{$page['id']}/structured-data", ['types' => []])
            ->assertForbidden();

        $this->as($tenant)
            ->postJson("/api/v1/platform/marketing/pages/{$page['id']}/structured-data/validate")
            ->assertForbidden();

        $this->as($author)
            ->putJson("/api/v1/platform/marketing/pages/{$page['id']}/structured-data", ['types' => ['service']])
            ->assertOk();
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

    /**
     * @return array<string, mixed>
     */
    private function createPage(User $author, string $slug, string $title): array
    {
        return $this->as($author)
            ->postJson('/api/v1/platform/marketing/pages', [
                'slug' => $slug,
                'title' => $title,
                'sections' => [
                    ['type' => 'rich_text', 'content' => ['heading' => $title, 'paragraphs' => ['Seeded body']]],
                ],
                'seo' => [
                    'title' => $title,
                    'description' => 'A test page.',
                    'robots_index' => true,
                ],
            ])
            ->assertCreated()
            ->json('data.page');
    }

    private function submitApprovePublish(string $pageId, User $author, User $reviewer): void
    {
        $this->as($author)->postJson("/api/v1/platform/marketing/pages/{$pageId}/submit-review")->assertOk();
        $this->as($reviewer)->postJson("/api/v1/platform/marketing/pages/{$pageId}/approve")->assertOk();
        $this->as($reviewer)->postJson("/api/v1/platform/marketing/pages/{$pageId}/publish")->assertOk();
    }
}
