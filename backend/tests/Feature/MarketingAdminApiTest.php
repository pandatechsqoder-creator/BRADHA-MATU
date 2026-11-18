<?php

namespace Tests\Feature;

use App\Models\Marketing\CmsBanner;
use App\Models\Marketing\CmsPage;
use App\Models\Marketing\MediaAsset;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class MarketingAdminApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
    }

    public function test_page_workflow_requires_review_publish_permissions_and_separation_of_duties(): void
    {
        $author = $this->platformUser('content-manager');
        $editor = $this->platformUser('content-editor');
        $reviewer = $this->platformUser('content-manager');

        $page = $this->createPage($author, 'launch-page', 'Launch page');

        $this->as($editor)
            ->postJson("/api/v1/platform/marketing/pages/{$page['id']}/approve")
            ->assertForbidden();

        $this->as($editor)
            ->postJson("/api/v1/platform/marketing/pages/{$page['id']}/publish")
            ->assertForbidden();

        $this->as($author)
            ->postJson("/api/v1/platform/marketing/pages/{$page['id']}/submit-review", ['change_summary' => 'Ready'])
            ->assertOk()
            ->assertJsonPath('data.page.status', 'review');

        $this->as($author)
            ->postJson("/api/v1/platform/marketing/pages/{$page['id']}/approve")
            ->assertStatus(422)
            ->assertJsonPath('error.details.workflow.0', 'A page cannot be approved by the person who created it while separation of duties is enabled.');

        $this->as($reviewer)
            ->postJson("/api/v1/platform/marketing/pages/{$page['id']}/approve")
            ->assertOk()
            ->assertJsonPath('data.page.status', 'approved')
            ->assertJsonPath('data.version.status', 'approved');

        $this->as($reviewer)
            ->postJson("/api/v1/platform/marketing/pages/{$page['id']}/publish")
            ->assertOk()
            ->assertJsonPath('data.page.status', 'published')
            ->assertJsonPath('data.page.is_live', true);

        $this->get('/launch-page')
            ->assertOk()
            ->assertSee('Launch page');

        $this->as($reviewer)
            ->postJson("/api/v1/platform/marketing/pages/{$page['id']}/unpublish")
            ->assertOk()
            ->assertJsonPath('data.page.status', 'draft')
            ->assertJsonPath('data.page.is_live', false);

        $this->get('/launch-page')->assertNotFound();
    }

    public function test_tenant_users_cannot_reach_marketing_admin(): void
    {
        $user = $this->platformUser('business-owner');

        $this->as($user)
            ->getJson('/api/v1/platform/marketing/pages')
            ->assertForbidden();
    }

    public function test_editing_a_live_page_keeps_the_published_snapshot_live(): void
    {
        $author = $this->platformUser('content-manager');
        $reviewer = $this->platformUser('content-manager');

        $page = $this->createPage($author, 'snapshot-page', 'Snapshot page', 'Version one body');
        $this->submitApprovePublish($page['id'], $author, $reviewer);

        $this->get('/snapshot-page')->assertOk()->assertSee('Version one body');

        $this->as($author)
            ->patchJson("/api/v1/platform/marketing/pages/{$page['id']}", ['title' => 'Snapshot page v2'])
            ->assertOk()
            ->assertJsonPath('data.page.status', 'draft')
            ->assertJsonPath('data.page.is_live', true);

        $this->as($author)
            ->putJson("/api/v1/platform/marketing/pages/{$page['id']}/sections", [
                'sections' => [
                    ['type' => 'rich_text', 'content' => ['heading' => 'Snapshot page v2', 'paragraphs' => ['Version two body']]],
                ],
            ])
            ->assertOk();

        $this->get('/snapshot-page')
            ->assertOk()
            ->assertSee('Version one body')
            ->assertDontSee('Version two body');

        $this->as($author)->postJson("/api/v1/platform/marketing/pages/{$page['id']}/submit-review")->assertOk();
        $this->as($reviewer)->postJson("/api/v1/platform/marketing/pages/{$page['id']}/approve")->assertOk();
        $this->as($reviewer)->postJson("/api/v1/platform/marketing/pages/{$page['id']}/publish")->assertOk();

        $this->get('/snapshot-page')
            ->assertOk()
            ->assertSee('Version two body')
            ->assertDontSee('Version one body');
    }

    public function test_versions_are_immutable_and_can_be_restored_into_a_draft(): void
    {
        $author = $this->platformUser('content-manager');
        $reviewer = $this->platformUser('content-manager');

        $page = $this->createPage($author, 'restore-page', 'Restore page', 'Original body');
        $this->submitApprovePublish($page['id'], $author, $reviewer);

        $versions = $this->as($author)
            ->getJson("/api/v1/platform/marketing/pages/{$page['id']}/versions")
            ->assertOk()
            ->json('data.versions');

        $published = collect($versions)->firstWhere('status', 'published');
        $original = collect($versions)->firstWhere('status', 'review');

        $this->as($author)
            ->patchJson("/api/v1/platform/marketing/pages/{$page['id']}", ['title' => 'Changed title'])
            ->assertOk();

        $this->as($author)
            ->postJson("/api/v1/platform/marketing/pages/{$page['id']}/versions/{$original['id']}/restore")
            ->assertOk()
            ->assertJsonPath('data.page.status', 'draft')
            ->assertJsonPath('data.page.title', 'Restore page');

        $this->as($author)
            ->getJson("/api/v1/platform/marketing/pages/{$page['id']}/versions")
            ->assertOk()
            ->assertJsonPath('data.current_version_id', $published['id'])
            ->assertJsonCount(4, 'data.versions');

        $snapshot = CmsPage::where('uuid', $page['id'])->firstOrFail()->currentVersion->snapshot;
        $this->assertSame('Restore page', $snapshot['page']['title']);
    }

    public function test_scheduled_publishing_runs_from_the_command(): void
    {
        $author = $this->platformUser('content-manager');
        $reviewer = $this->platformUser('content-manager');

        $page = $this->createPage($author, 'scheduled-page', 'Scheduled page');
        $this->as($author)->postJson("/api/v1/platform/marketing/pages/{$page['id']}/submit-review")->assertOk();
        $this->as($reviewer)->postJson("/api/v1/platform/marketing/pages/{$page['id']}/approve")->assertOk();

        $this->as($reviewer)
            ->postJson("/api/v1/platform/marketing/pages/{$page['id']}/schedule", [
                'scheduled_at' => now()->addMinute()->toIso8601String(),
            ])
            ->assertOk()
            ->assertJsonPath('data.page.status', 'scheduled');

        $this->get('/scheduled-page')->assertNotFound();

        $this->travel(2)->minutes();
        $this->artisan('marketing:publish-scheduled')->assertExitCode(0);

        $this->as($author)
            ->getJson("/api/v1/platform/marketing/pages/{$page['id']}")
            ->assertOk()
            ->assertJsonPath('data.page.status', 'published');

        $this->get('/scheduled-page')->assertOk();
    }

    public function test_preview_links_are_signed_expiring_and_noindex(): void
    {
        $author = $this->platformUser('content-manager');
        $page = $this->createPage($author, 'preview-page', 'Preview page', 'Unpublished body');

        $preview = $this->as($author)
            ->getJson("/api/v1/platform/marketing/pages/{$page['id']}/preview")
            ->assertOk()
            ->json('data');

        $this->assertStringContainsString('signature=', $preview['url']);

        $this->get($preview['url'])
            ->assertOk()
            ->assertSee('Unpublished body')
            ->assertSee('Preview of an unpublished version')
            ->assertSee('name="robots" content="noindex, nofollow"', false)
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow');

        $this->get($preview['url'].'&tampered=1')->assertForbidden();
    }

    public function test_unknown_section_types_are_rejected(): void
    {
        $author = $this->platformUser('content-manager');
        $page = $this->createPage($author, 'bad-section-page', 'Bad section page');

        $response = $this->as($author)
            ->putJson("/api/v1/platform/marketing/pages/{$page['id']}/sections", [
                'sections' => [['type' => 'raw_html', 'content' => ['html' => '<script>alert(1)</script>']]],
            ])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_FAILED');

        $this->assertArrayHasKey('sections.0.type', $response->json('error.details'));
    }

    public function test_media_upload_replace_usage_and_delivery(): void
    {
        Storage::fake('local');

        $author = $this->platformUser('content-manager');

        $upload = $this->as($author)->post(
            '/api/v1/platform/marketing/media/assets',
            [
                'file' => UploadedFile::fake()->image('logo.png', 120, 60),
                'alt_text' => 'Brand logo',
            ],
            ['Accept' => 'application/json'],
        )->assertCreated()
            ->assertJsonPath('data.asset.alt_text', 'Brand logo')
            ->assertJsonPath('data.asset.version', 1)
            ->json('data.asset');

        $this->assertSame(120, $upload['width']);
        $this->assertSame(60, $upload['height']);

        $this->get('/media/'.$upload['id'])
            ->assertOk()
            ->assertHeader('X-Content-Type-Options', 'nosniff');

        $page = $this->createPage($author, 'media-page', 'Media page', 'Uses the '.$upload['id']);

        $this->as($author)
            ->getJson("/api/v1/platform/marketing/media/assets/{$upload['id']}/usages")
            ->assertOk()
            ->assertJsonCount(1, 'data.usages')
            ->assertJsonPath('data.usages.0.usable_type', 'CmsPage');

        $this->as($author)->post(
            "/api/v1/platform/marketing/media/assets/{$upload['id']}/replace",
            ['file' => UploadedFile::fake()->image('logo-new.png', 80, 80)],
            ['Accept' => 'application/json'],
        )->assertOk()
            ->assertJsonPath('data.asset.version', 2)
            ->assertJsonPath('data.usage_count', 1);

        $this->as($author)
            ->getJson("/api/v1/platform/marketing/media/assets/{$upload['id']}/versions")
            ->assertOk()
            ->assertJsonCount(2, 'data.versions');

        $this->as($author)
            ->deleteJson("/api/v1/platform/marketing/media/assets/{$upload['id']}")
            ->assertOk()
            ->assertJsonPath('data.usage_count', 1);

        $this->get('/media/'.$upload['id'])->assertNotFound();
    }

    public function test_duplicate_media_uploads_return_the_existing_asset(): void
    {
        Storage::fake('local');
        $author = $this->platformUser('content-manager');

        $file = UploadedFile::fake()->image('same.png', 32, 32);

        $first = $this->as($author)->post(
            '/api/v1/platform/marketing/media/assets',
            ['file' => $file],
            ['Accept' => 'application/json'],
        )->assertCreated()->json('data.asset');

        $second = $this->as($author)->post(
            '/api/v1/platform/marketing/media/assets',
            ['file' => UploadedFile::fake()->image('same.png', 32, 32)],
            ['Accept' => 'application/json'],
        )->assertCreated()
            ->assertJsonPath('data.duplicate', true)
            ->json('data.asset');

        $this->assertSame($first['id'], $second['id']);
        $this->assertSame(1, MediaAsset::count());
    }

    public function test_menu_items_require_one_target_and_two_levels(): void
    {
        $author = $this->platformUser('content-manager');
        $this->as($author)->postJson('/api/v1/platform/marketing/menus', [
            'key' => 'primary',
            'name' => 'Primary navigation',
        ])->assertCreated();

        $page = $this->createPage($author, 'menu-target', 'Menu target');

        $parent = $this->as($author)
            ->postJson('/api/v1/platform/marketing/menus/primary/items', [
                'label' => 'Stock Taking',
                'page_id' => $page['id'],
            ])
            ->assertCreated()
            ->json('data.item');

        $this->as($author)
            ->postJson('/api/v1/platform/marketing/menus/primary/items', [
                'label' => 'Ambiguous',
                'page_id' => $page['id'],
                'url' => '/elsewhere',
            ])
            ->assertStatus(422);

        $child = $this->as($author)
            ->postJson('/api/v1/platform/marketing/menus/primary/items', [
                'label' => 'Second level',
                'url' => '/second',
                'parent_id' => $parent['id'],
            ])
            ->assertCreated()
            ->json('data.item');

        $response = $this->as($author)
            ->postJson('/api/v1/platform/marketing/menus/primary/items', [
                'label' => 'Third level',
                'url' => '/third',
                'parent_id' => $child['id'],
            ])
            ->assertStatus(422);

        $this->assertArrayHasKey('parent_id', $response->json('error.details'));

        $this->as($author)
            ->deleteJson("/api/v1/platform/marketing/menus/primary/items/{$parent['id']}")
            ->assertOk();
    }

    public function test_testimonial_approval_requires_review_permission_and_activates_the_section(): void
    {
        $editor = $this->platformUser('content-editor');
        $reviewer = $this->platformUser('content-manager');

        $testimonial = $this->as($editor)
            ->postJson('/api/v1/platform/marketing/testimonials', [
                'quote' => 'The count matched our records.',
                'author_name' => 'A. Customer',
            ])
            ->assertCreated()
            ->assertJsonPath('data.testimonial.is_approved', false)
            ->json('data.testimonial');

        $this->as($editor)
            ->patchJson("/api/v1/platform/marketing/testimonials/{$testimonial['id']}", ['is_approved' => true])
            ->assertStatus(422);

        $this->as($reviewer)
            ->patchJson("/api/v1/platform/marketing/testimonials/{$testimonial['id']}", ['is_approved' => true])
            ->assertOk()
            ->assertJsonPath('data.testimonial.is_approved', true);
    }

    public function test_activating_a_banner_deactivates_the_previous_one(): void
    {
        $author = $this->platformUser('content-manager');

        $home = CmsPage::create([
            'slug' => 'home',
            'title' => 'Home',
            'type' => 'page',
            'template' => 'public.page',
            'status' => 'published',
            'is_home' => true,
            'published_at' => now(),
        ]);
        $home->sections()->create([
            'type' => 'rich_text',
            'sort_order' => 0,
            'content' => ['heading' => 'Welcome'],
            'settings' => [],
            'is_visible' => true,
        ]);

        $first = $this->as($author)
            ->postJson('/api/v1/platform/marketing/banners', [
                'title' => 'First notice',
                'is_active' => true,
            ])
            ->assertCreated()
            ->json('data.banner');

        $second = $this->as($author)
            ->postJson('/api/v1/platform/marketing/banners', [
                'title' => 'Second notice',
                'is_active' => true,
            ])
            ->assertCreated()
            ->json('data.banner');

        $this->assertFalse(CmsBanner::where('uuid', $first['id'])->firstOrFail()->is_active);
        $this->assertTrue(CmsBanner::where('uuid', $second['id'])->firstOrFail()->is_active);

        $this->get('/')
            ->assertOk()
            ->assertSee('Second notice')
            ->assertDontSee('First notice');
    }

    public function test_approved_testimonials_and_visible_faqs_render_on_public_pages(): void
    {
        $editor = $this->platformUser('content-editor');
        $reviewer = $this->platformUser('content-manager');

        $this->as($editor)->postJson('/api/v1/platform/marketing/faqs', [
            'question' => 'Do you cover my town?',
            'answer' => 'Coverage depends on the confirmed service areas.',
        ])->assertCreated();

        $this->as($editor)->postJson('/api/v1/platform/marketing/faqs', [
            'question' => 'Hidden question',
            'answer' => 'Hidden answer',
            'is_visible' => false,
        ])->assertCreated();

        $testimonial = $this->as($editor)->postJson('/api/v1/platform/marketing/testimonials', [
            'quote' => 'The reconciliation report was clear.',
            'author_name' => 'B. Customer',
        ])->assertCreated()->json('data.testimonial');

        $this->as($reviewer)
            ->patchJson("/api/v1/platform/marketing/testimonials/{$testimonial['id']}", ['is_approved' => true])
            ->assertOk();

        $home = CmsPage::create([
            'slug' => 'home',
            'title' => 'Home',
            'type' => 'page',
            'template' => 'public.page',
            'status' => 'published',
            'is_home' => true,
            'published_at' => now(),
        ]);

        foreach (['faq_library', 'testimonials'] as $index => $type) {
            $home->sections()->create([
                'type' => $type,
                'sort_order' => $index,
                'content' => ['heading' => ucfirst($type)],
                'settings' => [],
                'is_visible' => true,
            ]);
        }

        $this->get('/')
            ->assertOk()
            ->assertSee('Do you cover my town?')
            ->assertSee('Coverage depends on the confirmed service areas.')
            ->assertDontSee('Hidden question')
            ->assertSee('The reconciliation report was clear.')
            ->assertSee('B. Customer');
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
    private function createPage(User $author, string $slug, string $title, string $body = 'Seeded body'): array
    {
        return $this->as($author)
            ->postJson('/api/v1/platform/marketing/pages', [
                'slug' => $slug,
                'title' => $title,
                'sections' => [
                    ['type' => 'rich_text', 'content' => ['heading' => $title, 'paragraphs' => [$body]]],
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
