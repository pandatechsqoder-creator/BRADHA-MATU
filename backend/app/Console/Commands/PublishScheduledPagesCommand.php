<?php

namespace App\Console\Commands;

use App\Exceptions\MarketingIntegrationException;
use App\Models\Marketing\BlogPost;
use App\Models\Marketing\CmsPage;
use App\Models\Marketing\SocialPost;
use App\Services\Marketing\BlogService;
use App\Services\Marketing\MarketingPageService;
use App\Services\Marketing\Meta\SocialPostService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

final class PublishScheduledPagesCommand extends Command
{
    protected $signature = 'marketing:publish-scheduled';

    protected $description = 'Publish CMS pages and blog posts whose scheduled publication time has passed.';

    public function handle(MarketingPageService $pages, BlogService $blog, SocialPostService $social): int
    {
        $due = CmsPage::query()
            ->where('status', 'scheduled')
            ->whereNotNull('scheduled_at')
            ->where('scheduled_at', '<=', now())
            ->orderBy('scheduled_at')
            ->get();

        $published = 0;

        foreach ($due as $page) {
            try {
                $pages->publish($page, null, 'Scheduled publish');
                $published++;
                $this->line(sprintf('%s published (scheduled for %s)', $page->slug, $page->scheduled_at?->toDateTimeString()));
            } catch (ValidationException $exception) {
                Log::warning('Scheduled CMS publish failed', [
                    'page' => $page->uuid,
                    'errors' => $exception->errors(),
                ]);
                $this->error(sprintf('%s could not be published: %s', $page->slug, collect($exception->errors())->flatten()->first()));
            }
        }

        $posts = BlogPost::query()
            ->where('status', 'scheduled')
            ->whereNotNull('scheduled_at')
            ->where('scheduled_at', '<=', now())
            ->orderBy('scheduled_at')
            ->get();

        $postsPublished = 0;

        foreach ($posts as $post) {
            try {
                $blog->publishScheduled($post);
                $postsPublished++;
                $this->line(sprintf('Article %s published (scheduled for %s)', $post->slug, $post->scheduled_at?->toDateTimeString()));
            } catch (ValidationException $exception) {
                Log::warning('Scheduled article publish failed', [
                    'post' => $post->uuid,
                    'errors' => $exception->errors(),
                ]);
                $this->error(sprintf('Article %s could not be published: %s', $post->slug, collect($exception->errors())->flatten()->first()));
            }
        }

        $this->publishDueSocialPosts($social);

        if ($due->isEmpty() && $posts->isEmpty()) {
            $this->info('No scheduled pages, articles or social posts are due.');

            return self::SUCCESS;
        }

        $this->info(sprintf('%d of %d scheduled pages and %d of %d scheduled articles published.', $published, $due->count(), $postsPublished, $posts->count()));

        return self::SUCCESS;
    }

    private function publishDueSocialPosts(SocialPostService $social): void
    {
        $due = SocialPost::query()
            ->where('status', 'scheduled')
            ->whereNotNull('scheduled_at')
            ->where('scheduled_at', '<=', now())
            ->orderBy('scheduled_at')
            ->get();

        foreach ($due as $post) {
            try {
                $social->publishScheduled($post);
                $this->line(sprintf('Social post %s published (scheduled for %s)', $post->uuid, $post->scheduled_at?->toDateTimeString()));
            } catch (MarketingIntegrationException $exception) {
                Log::warning('Scheduled social post publish failed', [
                    'post' => $post->uuid,
                    'code' => $exception->errorCode,
                    'message' => $exception->getMessage(),
                ]);
                $this->error(sprintf('Social post %s could not be published: %s', $post->uuid, $exception->getMessage()));
            }
        }
    }
}
