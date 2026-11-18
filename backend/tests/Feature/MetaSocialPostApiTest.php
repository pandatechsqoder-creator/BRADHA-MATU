<?php

namespace Tests\Feature;

use App\Models\Marketing\MediaAsset;
use App\Models\Marketing\MetaInstagramAccount;
use App\Models\Marketing\MetaPage;
use App\Models\Marketing\OAuthConnection;
use App\Models\Marketing\SocialPost;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class MetaSocialPostApiTest extends TestCase
{
    use RefreshDatabase;

    private User $manager;

    private MetaPage $page;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
        $this->manager = $this->userWithRole('marketing-manager');

        $connection = OAuthConnection::create([
            'provider' => 'meta',
            'account_label' => 'BRATHAMATU Page Admin',
            'credentials' => [
                'access_token' => 'long-lived-token',
                'expires_at' => now()->addDays(50)->toIso8601String(),
            ],
            'scopes' => ['pages_manage_posts'],
            'status' => 'connected',
            'connected_by' => $this->manager->id,
        ]);

        $this->page = MetaPage::create([
            'connection_id' => $connection->id,
            'external_id' => 'page-1',
            'name' => 'BRATHAMATU',
            'page_token' => 'page-access-token',
            'status' => 'active',
        ]);
    }

    public function test_post_lifecycle_from_draft_to_published_on_facebook(): void
    {
        $postId = $this->createPost();

        $this->as($this->manager)->postJson("/api/v1/platform/marketing/social-posts/{$postId}/submit-review")
            ->assertOk()
            ->assertJsonPath('data.post.status', 'review');

        $this->as($this->manager)->postJson("/api/v1/platform/marketing/social-posts/{$postId}/approve")
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'SOCIAL_APPROVAL_CONFLICT');

        $approver = $this->userWithRole('content-manager');
        $this->as($approver)->postJson("/api/v1/platform/marketing/social-posts/{$postId}/approve", ['notes' => 'Approved for launch'])
            ->assertOk()
            ->assertJsonPath('data.post.status', 'approved');

        $this->as($this->manager)->postJson("/api/v1/platform/marketing/social-posts/{$postId}/schedule", [
            'scheduled_at' => now()->addDay()->toIso8601String(),
        ])->assertOk()->assertJsonPath('data.post.status', 'scheduled');

        Http::fake([
            'graph.facebook.com/*/page-1/feed*' => Http::response(['id' => 'page-1_999']),
        ]);

        $this->as($this->manager)->postJson("/api/v1/platform/marketing/social-posts/{$postId}/publish")
            ->assertOk()
            ->assertJsonPath('data.post.status', 'published')
            ->assertJsonPath('data.post.external_id', 'page-1_999');

        Http::assertSent(fn ($request) => str_contains($request->url(), 'page-1/feed')
            && $request->data()['message'] === 'Launch week: stock-taking that protects margin.');
    }

    public function test_draft_cannot_be_published_without_approval(): void
    {
        $postId = $this->createPost();

        $this->as($this->manager)->postJson("/api/v1/platform/marketing/social-posts/{$postId}/publish")
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'SOCIAL_APPROVAL_REQUIRED');
    }

    public function test_schedule_requires_future_time_and_approval(): void
    {
        $postId = $this->createPost();

        $this->as($this->manager)->postJson("/api/v1/platform/marketing/social-posts/{$postId}/schedule", [
            'scheduled_at' => now()->addDay()->toIso8601String(),
        ])->assertStatus(422)->assertJsonPath('error.code', 'SOCIAL_APPROVAL_REQUIRED');

        $this->approve($postId);

        $this->as($this->manager)->postJson("/api/v1/platform/marketing/social-posts/{$postId}/schedule", [
            'scheduled_at' => now()->subHour()->toIso8601String(),
        ])->assertStatus(422)->assertJsonPath('error.code', 'VALIDATION_FAILED');
    }

    public function test_reject_requires_reason_and_returns_post_to_rejected(): void
    {
        $postId = $this->createPost();
        $this->as($this->manager)->postJson("/api/v1/platform/marketing/social-posts/{$postId}/submit-review")->assertOk();

        $approver = $this->userWithRole('content-manager');

        $this->as($approver)->postJson("/api/v1/platform/marketing/social-posts/{$postId}/reject", [])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_FAILED');

        $this->as($approver)->postJson("/api/v1/platform/marketing/social-posts/{$postId}/reject", ['notes' => 'Tone is off-brand.'])
            ->assertOk()
            ->assertJsonPath('data.post.status', 'rejected');
    }

    public function test_editors_cannot_approve_and_tenants_cannot_access_social_posts(): void
    {
        $postId = $this->createPost();

        $editor = $this->userWithRole('content-editor');
        $this->as($editor)->postJson("/api/v1/platform/marketing/social-posts/{$postId}/approve")
            ->assertForbidden();

        $tenant = $this->userWithRole('business-owner');
        $this->as($tenant)->getJson('/api/v1/platform/marketing/social-posts')->assertForbidden();
        $this->as($tenant)->postJson('/api/v1/platform/marketing/social-posts', ['caption' => 'Leak?'])->assertForbidden();
    }

    public function test_instagram_requires_jpeg_media_and_publishes_container_then_media(): void
    {
        $instagram = MetaInstagramAccount::create([
            'connection_id' => $this->page->connection_id,
            'meta_page_id' => $this->page->id,
            'external_id' => 'ig-1',
            'username' => 'brathamatu',
            'status' => 'active',
        ]);

        $this->as($this->manager)->postJson('/api/v1/platform/marketing/social-posts', [
            'caption' => 'Counted and reconciled.',
            'meta_page_id' => 'page-1',
            'meta_instagram_account_id' => 'ig-1',
        ])->assertStatus(422)->assertJsonPath('error.code', 'META_INSTAGRAM_MEDIA_REQUIRED');

        $asset = MediaAsset::create([
            'disk' => 'public',
            'path' => 'marketing/social/launch.jpg',
            'filename' => 'launch.jpg',
            'mime' => 'image/jpeg',
            'extension' => 'jpg',
            'size_bytes' => 2048,
            'checksum' => hash('sha256', 'launch'),
            'uploaded_by' => $this->manager->id,
        ]);

        $postId = $this->createPost([
            'meta_instagram_account_id' => 'ig-1',
            'media_asset_id' => $asset->uuid,
            'caption' => 'Counted and reconciled.',
        ]);

        $this->approve($postId);

        Http::fake([
            'graph.facebook.com/*/ig-1/media_publish*' => Http::response(['id' => 'media-1']),
            'graph.facebook.com/*/ig-1/media*' => Http::response(['id' => 'container-1']),
        ]);

        $this->as($this->manager)->postJson("/api/v1/platform/marketing/social-posts/{$postId}/publish")
            ->assertOk()
            ->assertJsonPath('data.post.status', 'published')
            ->assertJsonPath('data.post.external_id', 'media-1');

        Http::assertSent(fn ($request) => str_ends_with((string) parse_url($request->url(), PHP_URL_PATH), '/ig-1/media')
            && ($request->data()['image_url'] ?? '') !== '');
        Http::assertSent(fn ($request) => str_contains($request->url(), 'ig-1/media_publish')
            && $request->data()['creation_id'] === 'container-1');

        $this->assertGreaterThan(0, (int) $asset->id);
        $this->assertNotNull($instagram->fresh());
    }

    public function test_meta_api_failure_marks_post_failed(): void
    {
        $failedId = $this->createPost();
        $this->approve($failedId);

        Http::fake([
            'graph.facebook.com/*' => Http::response(['error' => ['code' => 100, 'message' => 'Invalid message']], 500),
        ]);

        $this->as($this->manager)->postJson("/api/v1/platform/marketing/social-posts/{$failedId}/publish")
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'META_API_ERROR');

        $failed = SocialPost::query()->where('uuid', $failedId)->firstOrFail();
        $this->assertSame('failed', $failed->status);
        $this->assertNotNull($failed->error);
    }

    public function test_scheduler_publishes_due_social_posts(): void
    {
        SocialPost::create([
            'meta_page_id' => $this->page->id,
            'caption' => 'Due post',
            'status' => 'scheduled',
            'scheduled_at' => now()->subMinute(),
            'created_by' => $this->manager->id,
        ]);

        Http::fake([
            'graph.facebook.com/*' => Http::response(['id' => 'page-1_1000']),
        ]);

        $this->artisan('marketing:publish-scheduled')->assertSuccessful();

        $scheduled = SocialPost::query()->where('caption', 'Due post')->firstOrFail();
        $this->assertSame('published', $scheduled->status, (string) $scheduled->error);
        $this->assertSame('page-1_1000', $scheduled->external_id);
    }

    public function test_index_returns_options_and_status_counts(): void
    {
        $this->createPost();
        $this->createPost(['caption' => 'Second draft']);

        $this->as($this->manager)->getJson('/api/v1/platform/marketing/social-posts')
            ->assertOk()
            ->assertJsonCount(2, 'data.posts')
            ->assertJsonPath('data.counts.draft', 2)
            ->assertJsonPath('data.options.pages.0.name', 'BRATHAMATU');
    }

    private function createPost(array $overrides = []): string
    {
        $response = $this->as($this->manager)->postJson('/api/v1/platform/marketing/social-posts', array_merge([
            'caption' => 'Launch week: stock-taking that protects margin.',
            'meta_page_id' => 'page-1',
        ], $overrides));

        $response->assertCreated();

        return $response->json('data.post.id');
    }

    private function approve(string $postId): void
    {
        $this->as($this->manager)->postJson("/api/v1/platform/marketing/social-posts/{$postId}/submit-review")->assertOk();

        $approver = User::factory()->create();
        $approver->assignRole('content-manager');

        $this->as($approver)->postJson("/api/v1/platform/marketing/social-posts/{$postId}/approve")->assertOk();
    }

    private function userWithRole(string $role): User
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
