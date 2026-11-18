<?php

namespace Tests\Feature;

use App\Models\Marketing\BlogPost;
use App\Models\Marketing\MediaAsset;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class BlogApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
    }

    public function test_article_workflow_publishes_markup_public_pages_feed_and_sitemap(): void
    {
        $author = $this->platformUser('content-manager');
        $reviewer = $this->platformUser('content-manager');
        $category = $this->createCategory($author, 'stock-count-planning', 'Stock count planning');
        $post = $this->createPost($author, $category['id'], 'count-preparation', 'Count preparation guide');

        $this->as($author)->postJson("/api/v1/platform/marketing/posts/{$post['id']}/submit-review")->assertOk();
        $this->as($reviewer)->postJson("/api/v1/platform/marketing/posts/{$post['id']}/approve")->assertOk();
        $this->as($reviewer)->postJson("/api/v1/platform/marketing/posts/{$post['id']}/publish")
            ->assertOk()
            ->assertJsonPath('data.post.status', 'published');

        $show = $this->getJson('/api/v1/public/articles/count-preparation')
            ->assertOk()
            ->assertJsonPath('data.article.title', 'Count preparation guide')
            ->assertJsonPath('data.structured_data.@context', 'https://schema.org');

        $graph = collect($show->json('data.structured_data.@graph'))->keyBy('@type');
        $this->assertArrayHasKey('Article', $graph);
        $this->assertSame('Count preparation guide', $graph['Article']['headline']);
        $this->assertSame('BRATHAMATU Editorial', $graph['Article']['author']['name']);
        $this->assertSame('Stock count planning', $graph['Article']['articleSection']);
        $this->assertStringContainsString('Stock Count', $graph['Article']['keywords']);

        $json = json_encode($graph->all());
        foreach (['aggregateRating', 'ratingValue', 'offers', 'price', 'review'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $json);
        }

        $this->get('/resources')
            ->assertOk()
            ->assertSee('Count preparation guide')
            ->assertSee('Latest articles');

        $this->get('/resources/count-preparation')
            ->assertOk()
            ->assertSee('application/ld+json', false)
            ->assertSee('"@type":"Article"', false)
            ->assertSee('Before the count', false)
            ->assertSee('Checklist', false);

        $this->get('/rss.xml')
            ->assertOk()
            ->assertHeader('Content-Type', 'application/rss+xml; charset=UTF-8')
            ->assertSee('Count preparation guide');

        $this->get('/sitemap.xml')
            ->assertOk()
            ->assertSee('/resources/count-preparation', false)
            ->assertSee('/resources/categories/stock-count-planning', false);
    }

    public function test_article_cannot_be_approved_by_its_author(): void
    {
        $author = $this->platformUser('content-manager');
        $category = $this->createCategory($author, 'inventory-accuracy', 'Inventory accuracy');
        $post = $this->createPost($author, $category['id'], 'separation-check', 'Separation check');

        $this->as($author)->postJson("/api/v1/platform/marketing/posts/{$post['id']}/submit-review")->assertOk();
        $this->as($author)->postJson("/api/v1/platform/marketing/posts/{$post['id']}/approve")
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_FAILED');
    }

    public function test_scheduled_article_publishes_when_due(): void
    {
        $author = $this->platformUser('content-manager');
        $reviewer = $this->platformUser('content-manager');
        $category = $this->createCategory($author, 'planning', 'Planning');
        $post = $this->createPost($author, $category['id'], 'scheduled-article', 'Scheduled article');

        $this->as($author)->postJson("/api/v1/platform/marketing/posts/{$post['id']}/submit-review")->assertOk();
        $this->as($reviewer)->postJson("/api/v1/platform/marketing/posts/{$post['id']}/approve")->assertOk();
        $this->as($reviewer)->postJson("/api/v1/platform/marketing/posts/{$post['id']}/schedule", [
            'scheduled_at' => now()->addDay()->toIso8601String(),
        ])->assertOk()->assertJsonPath('data.post.status', 'scheduled');

        BlogPost::query()->where('uuid', $post['id'])->update([
            'scheduled_at' => now()->subMinute(),
        ]);

        $this->artisan('marketing:publish-scheduled')->assertSuccessful();

        $this->assertSame('published', BlogPost::query()->where('uuid', $post['id'])->value('status'));
        $this->get('/resources/scheduled-article')->assertOk();
    }

    public function test_unpublished_article_disappears_from_public_surfaces(): void
    {
        $author = $this->platformUser('content-manager');
        $reviewer = $this->platformUser('content-manager');
        $category = $this->createCategory($author, 'planning', 'Planning');
        $post = $this->createPost($author, $category['id'], 'temporary-article', 'Temporary article');

        $this->as($author)->postJson("/api/v1/platform/marketing/posts/{$post['id']}/submit-review")->assertOk();
        $this->as($reviewer)->postJson("/api/v1/platform/marketing/posts/{$post['id']}/approve")->assertOk();
        $this->as($reviewer)->postJson("/api/v1/platform/marketing/posts/{$post['id']}/publish")->assertOk();
        $this->get('/resources/temporary-article')->assertOk();

        $this->as($reviewer)->postJson("/api/v1/platform/marketing/posts/{$post['id']}/unpublish")
            ->assertOk()
            ->assertJsonPath('data.post.status', 'draft');

        $this->get('/resources/temporary-article')->assertNotFound();
        $this->getJson('/api/v1/public/articles/temporary-article')->assertNotFound();
        $this->get('/resources')->assertDontSee('Temporary article');
    }

    public function test_taxonomy_endpoints_and_public_category_listing(): void
    {
        $editor = $this->platformUser('content-editor');
        $category = $this->createCategory($editor, 'guides', 'Guides');

        $this->as($editor)->getJson('/api/v1/platform/marketing/post-categories')
            ->assertOk()
            ->assertJsonPath('data.categories.0.slug', 'guides');

        $this->as($editor)->postJson('/api/v1/platform/marketing/post-tags', ['name' => 'Stock control'])
            ->assertCreated()
            ->assertJsonPath('data.tag.slug', 'stock-control');

        $this->getJson('/api/v1/public/article-categories')->assertOk();

        $this->as($editor)->postJson('/api/v1/platform/marketing/post-categories', [
            'name' => 'Guides',
        ])->assertStatus(422);
    }

    public function test_blog_endpoints_reject_tenant_roles(): void
    {
        $tenant = $this->platformUser('business-owner');

        $this->as($tenant)->getJson('/api/v1/platform/marketing/posts')->assertForbidden();
        $this->as($tenant)->postJson('/api/v1/platform/marketing/posts', [])->assertForbidden();
        $this->as($tenant)->getJson('/api/v1/platform/marketing/resource-downloads')->assertForbidden();
        $this->as($tenant)->getJson('/api/v1/platform/marketing/editorial-items')->assertForbidden();
    }

    public function test_resource_download_requires_consent_and_counts_downloads(): void
    {
        $asset = MediaAsset::create([
            'disk' => 'public',
            'path' => 'resources/checklist.txt',
            'filename' => 'checklist.txt',
            'mime' => 'text/plain',
            'extension' => 'txt',
            'size_bytes' => 100,
            'checksum' => str_repeat('a', 64),
        ]);

        $resource = \App\Models\Marketing\ResourceDownload::create([
            'slug' => 'preparation-checklist',
            'title' => 'Preparation checklist',
            'description' => 'A checklist for planning a stock count.',
            'media_asset_id' => $asset->id,
            'requires_consent' => true,
            'is_published' => true,
            'published_at' => now(),
        ]);

        $this->get('/resources/downloads/preparation-checklist')
            ->assertOk()
            ->assertSee('I agree to receive this download');

        $this->post('/resources/downloads/preparation-checklist')
            ->assertSessionHasErrors('consent');

        $response = $this->post('/resources/downloads/preparation-checklist', ['consent' => 'accepted']);
        $response->assertRedirect();
        $this->assertStringEndsWith('/media/'.$asset->uuid, $response->headers->get('Location'));

        $this->assertSame(1, $resource->fresh()->download_count);
    }

    public function test_filtered_index_and_tag_archives_are_noindex(): void
    {
        $author = $this->platformUser('content-manager');
        $category = $this->createCategory($author, 'planning', 'Planning');
        $post = $this->createPost($author, $category['id'], 'tagged-article', 'Tagged article', ['cycle count']);

        BlogPost::query()->where('uuid', $post['id'])->update([
            'status' => 'published',
            'published_at' => Carbon::now()->subDay(),
        ]);

        $this->get('/resources?q=tagged')
            ->assertOk()
            ->assertSee('<meta name="robots" content="noindex, follow">', false);

        $this->get('/resources/tags/cycle-count')
            ->assertOk()
            ->assertSee('Tagged article')
            ->assertSee('<meta name="robots" content="noindex, follow">', false);

        $this->get('/resources/categories/planning')
            ->assertOk()
            ->assertSee('Tagged article');
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
    private function createCategory(User $user, string $slug, string $name): array
    {
        return $this->as($user)->postJson('/api/v1/platform/marketing/post-categories', [
            'slug' => $slug,
            'name' => $name,
        ])->assertCreated()->json('data.category');
    }

    /**
     * @param  array<int, string>  $tags
     * @return array<string, mixed>
     */
    private function createPost(User $user, string $categoryId, string $slug, string $title, array $tags = ['stock count']): array
    {
        return $this->as($user)->postJson('/api/v1/platform/marketing/posts', [
            'title' => $title,
            'slug' => $slug,
            'excerpt' => 'A practical guide for planning a physical stock count.',
            'body' => "# {$title}\n\n## Before the count\n\nPrepare locations and records.\n\n- Checklist item one\n- Checklist item two\n",
            'format' => 'markdown',
            'category_id' => $categoryId,
            'author_name' => 'BRATHAMATU Editorial',
            'tags' => $tags,
            'seo_description' => 'A practical guide for planning a physical stock count.',
        ])->assertCreated()->json('data.post');
    }
}
