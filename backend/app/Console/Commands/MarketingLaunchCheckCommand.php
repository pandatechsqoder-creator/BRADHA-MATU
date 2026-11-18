<?php

namespace App\Console\Commands;

use App\Models\Marketing\BlogPost;
use App\Models\Marketing\CmsPage;
use App\Models\Marketing\Ga4Property;
use App\Models\Marketing\GoogleSearchProperty;
use App\Models\Marketing\OAuthConnection;
use App\Services\Marketing\MarketingSettings;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

final class MarketingLaunchCheckCommand extends Command
{
    protected $signature = 'marketing:launch-check';

    protected $description = 'Report production launch readiness: configuration, content, contact details, integrations and backups.';

    /**
     * @var array<int, array{name: string, status: string, message: string}>
     */
    private array $results = [];

    public function handle(MarketingSettings $settings): int
    {
        $this->checkEnvironment();
        $this->checkIndexing();
        $this->checkContent();
        $this->checkContact($settings);
        $this->checkIntegrations();
        $this->checkBackups();
        $this->checkOutboundProvider();

        $this->table(
            ['Check', 'Status', 'Detail'],
            array_map(fn (array $row) => [$row['name'], strtoupper($row['status']), $row['message']], $this->results),
        );

        $failed = collect($this->results)->contains(fn (array $row) => $row['status'] === 'fail');
        $warned = collect($this->results)->contains(fn (array $row) => $row['status'] === 'warn');

        $summary = ['status' => $failed ? 'not_ready' : ($warned ? 'ready_with_warnings' : 'ready'), 'checks' => $this->results, 'time' => now()->toIso8601String()];
        $this->line(json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    private function checkEnvironment(): void
    {
        if (! app()->environment('production')) {
            $this->add('environment', 'warn', 'Running in '.app()->environment().'; production-only checks are not enforced here.');

            return;
        }

        $issues = [];
        if (config('app.debug')) {
            $issues[] = 'APP_DEBUG is true';
        }
        if (! str_starts_with((string) config('app.url'), 'https://')) {
            $issues[] = 'APP_URL is not HTTPS';
        }
        if (! config('session.secure')) {
            $issues[] = 'SESSION_SECURE_COOKIE is off';
        }

        $this->add('environment', $issues === [] ? 'ok' : 'fail', $issues === [] ? 'Production configuration and secure cookies are set.' : implode('; ', $issues).'.');
    }

    private function checkIndexing(): void
    {
        $indexable = (bool) config('marketing.indexable');

        if (app()->environment('production')) {
            $this->add('indexing', $indexable ? 'ok' : 'fail', $indexable ? 'Public pages are indexable in production.' : 'MARKETING_INDEXABLE is off in production.');

            return;
        }

        $this->add('indexing', $indexable ? 'warn' : 'ok', $indexable ? 'Indexing is enabled outside production; do not expose this environment publicly.' : 'Indexing is disabled outside production.');
    }

    private function checkContent(): void
    {
        $home = CmsPage::query()->where('is_home', true)->where('status', 'published')->exists();
        $pages = CmsPage::query()->published()->count();
        $posts = BlogPost::query()->where('status', 'published')->count();

        $this->add('homepage', $home ? 'ok' : 'fail', $home ? 'A published home page exists.' : 'No published page is marked as home.');
        $this->add('published_pages', $pages >= 5 ? 'ok' : 'warn', $pages.' published page(s) are live.');
        $this->add('published_articles', $posts > 0 ? 'ok' : 'warn', $posts.' published article(s).');
    }

    private function checkContact(MarketingSettings $settings): void
    {
        $contact = $settings->contact();
        $email = trim((string) ($contact['email'] ?? ''));
        $phone = trim((string) ($contact['phone'] ?? ''));

        if ($email === '' && $phone === '') {
            $this->add('contact_details', 'fail', 'No contact email or phone is configured; the public site shows a pending state and launch is blocked until the owner confirms real details.');

            return;
        }

        $this->add('contact_details', 'ok', 'Contact details are configured and rendered from settings.');
    }

    private function checkIntegrations(): void
    {
        $google = OAuthConnection::query()->where('provider', 'google')->get()->contains(fn (OAuthConnection $connection) => $connection->isConnected());
        $meta = OAuthConnection::query()->where('provider', 'meta')->get()->contains(fn (OAuthConnection $connection) => $connection->isConnected());

        $this->add('google_connection', $google ? 'ok' : 'warn', $google ? 'Google is connected.' : 'Google is not connected; the workspace shows setup-required.');
        $this->add('meta_connection', $meta ? 'ok' : 'warn', $meta ? 'Meta is connected.' : 'Meta is not connected; the workspace shows setup-required.');

        $search = GoogleSearchProperty::query()->exists();
        $ga4 = Ga4Property::query()->exists();
        $this->add('search_console', $search ? 'ok' : 'warn', $search ? 'A Search Console property is synced.' : 'No Search Console property is synced.');
        $this->add('ga4', $ga4 ? 'ok' : 'warn', $ga4 ? 'A GA4 property is synced.' : 'No GA4 property is synced.');
    }

    private function checkBackups(): void
    {
        $files = collect(Storage::disk('local')->files('backups'))
            ->filter(fn (string $path) => str_ends_with($path, '.json.gz'))
            ->sortByDesc(fn (string $path) => Storage::disk('local')->lastModified($path));

        $latest = $files->first();

        if ($latest === null) {
            $this->add('backups', 'warn', 'No backup snapshot exists yet; run db:backup and store copies off-host.');

            return;
        }

        $ageHours = (int) floor((now()->timestamp - Storage::disk('local')->lastModified($latest)) / 3600);
        $this->add('backups', $ageHours <= 48 ? 'ok' : 'warn', 'Latest backup is '.$ageHours.' hour(s) old at '.$latest.'.');
    }

    private function checkOutboundProvider(): void
    {
        $this->add('outbound_provider', 'warn', 'No email/SMS/WhatsApp provider is approved; outbound automation steps stay blocked by design.');
    }

    private function add(string $name, string $status, string $message): void
    {
        $this->results[] = ['name' => $name, 'status' => $status, 'message' => $message];
    }
}
