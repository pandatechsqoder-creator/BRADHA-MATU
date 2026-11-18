<?php

namespace Tests\Feature;

use App\Models\Marketing\CmsMenu;
use App\Models\Marketing\CmsMenuItem;
use App\Models\Marketing\CmsPage;
use App\Models\Marketing\CmsPageSection;
use App\Models\Marketing\MarketingSetting;
use App\Models\Marketing\SeoMetadata;
use Database\Seeders\MarketingContentSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class PublicWebsiteTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(MarketingContentSeeder::class);
    }

    public function test_public_forms_render_without_javascript_on_quote_demo_and_contact_pages(): void
    {
        $this->get('/request-quote')
            ->assertOk()
            ->assertSee('data-form="quote"', false)
            ->assertSee('name="consent_transactional"', false)
            ->assertSee('name="form_token"', false);

        $this->get('/book-demo')
            ->assertOk()
            ->assertSee('data-form="pos_demo"', false);

        $this->get('/contact')
            ->assertOk()
            ->assertSee('data-form="contact"', false)
            ->assertSee('data-form="callback"', false)
            ->assertSee('data-form="partnership"', false);
    }

    public function test_homepage_renders_server_side_content_with_chrome_and_sections(): void
    {
        $response = $this->get('/');

        $response->assertOk()
            ->assertSee('Accurate stock counts, without slowing your business')
            ->assertSee('Stock-taking services built around your schedule')
            ->assertSee('BRATHAMATU POS for day-to-day operations')
            ->assertSee('How a stock take works')
            ->assertSee('Questions businesses ask')
            ->assertSee('Planning a count? Start with your scope.')
            ->assertSee('Skip to content')
            ->assertSee('class="hero-panel"', false)
            ->assertSee('class="process"', false)
            ->assertSee('class="faq-item"', false)
            ->assertSee('cta-band', false);
        $response->assertSee('<link rel="canonical" href="'.rtrim(url('/'), '/').'/">', false)
            ->assertSee('name="robots" content="noindex, follow"', false)
            ->assertSee('BRATHAMATU | Professional stock-taking and BRATHAMATU POS');
    }

    public function test_indexable_environment_marks_published_pages_as_indexable(): void
    {
        config(['marketing.indexable' => true]);

        $this->get('/')
            ->assertOk()
            ->assertSee('name="robots" content="index, follow"', false);
    }

    public function test_published_page_renders_with_breadcrumbs_and_navigation(): void
    {
        $response = $this->get('/stock-taking');

        $response->assertOk()
            ->assertSee('Stock-taking services for every frequency and scope')
            ->assertSee('aria-label="Breadcrumb"', false)
            ->assertSee('On-demand stock taking')
            ->assertSee('Independent verification')
            ->assertSee('aria-current="page"', false);
    }

    public function test_draft_page_is_not_routable_and_is_noindex(): void
    {
        $this->makePage('internal-draft', ['status' => 'draft', 'published_at' => null, 'noindex' => true]);

        $response = $this->get('/internal-draft');

        $response->assertNotFound()
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow')
            ->assertSee('Page not found');
    }

    public function test_service_and_pos_landing_pages_are_published_with_ctas_and_hub_links(): void
    {
        $pages = [
            '/stock-taking/on-demand' => ['Stock taking when you need it, not only on a fixed schedule', '/request-quote'],
            '/stock-taking/monthly' => ['Monthly stock taking that keeps records close to reality', '/request-quote'],
            '/stock-taking/annual' => ['Annual stock taking for a clear year-end position', '/request-quote'],
            '/stock-taking/recurring' => ['Recurring stock taking on a schedule that fits your operation', '/request-quote'],
            '/stock-taking/full' => ['A complete physical count across your agreed stock', '/request-quote'],
            '/stock-taking/cycle-counting' => ['Cycle counting for focused, ongoing stock control', '/request-quote'],
            '/stock-taking/branch' => ['Branch stock taking across every location in scope', '/request-quote'],
            '/stock-taking/warehouse' => ['Warehouse stock taking built around how goods move', '/request-quote'],
            '/stock-taking/independent-verification' => ['Independent verification for quantities you need to confirm', '/request-quote'],
            '/stock-taking/recounts' => ['Recounts and discrepancy investigation when numbers do not agree', '/request-quote'],
            '/brathamatu-pos/retail' => ['Retail POS for sales, stock and everyday control', '/book-demo'],
            '/brathamatu-pos/supermarket' => ['Supermarket POS for high-volume selling and stock visibility', '/book-demo'],
            '/brathamatu-pos/pharmacy' => ['Pharmacy POS for product and inventory control', '/book-demo'],
            '/brathamatu-pos/wholesale' => ['Wholesale POS for bulk sales and distribution workflows', '/book-demo'],
            '/brathamatu-pos/hardware' => ['Hardware POS for mixed units, bulk items and stock control', '/book-demo'],
            '/brathamatu-pos/restaurant' => ['Restaurant POS for fast sales and consumable stock', '/book-demo'],
            '/brathamatu-pos/multi-branch' => ['Multi-branch POS with one operational view', '/book-demo'],
            '/brathamatu-pos/inventory-management' => ['Inventory management connected to sales and stock counts', '/book-demo'],
            '/brathamatu-pos/barcode-catalogue' => ['Barcode and product catalogue management for POS', '/book-demo'],
            '/brathamatu-pos/stocktaking-integration' => ['Stock-taking integration inside the BRATHAMATU POS workflow', '/request-quote'],
        ];

        foreach ($pages as $path => [$heading, $cta]) {
            $this->get($path)
                ->assertOk()
                ->assertSee($heading)
                ->assertSee('href="'.$cta.'"', false);
        }

        $this->get('/stock-taking')
            ->assertOk()
            ->assertSee('href="/stock-taking/on-demand"', false)
            ->assertSee('href="/stock-taking/recounts"', false);

        $this->get('/brathamatu-pos')
            ->assertOk()
            ->assertSee('href="/brathamatu-pos/retail"', false)
            ->assertSee('href="/brathamatu-pos/stocktaking-integration"', false);

        $this->assertSame(0, CmsPage::where('status', 'draft')->count());
    }

    public function test_unknown_public_path_returns_branded_noindex_404(): void
    {
        $this->get('/this-page-does-not-exist')
            ->assertNotFound()
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow')
            ->assertSee('Page not found')
            ->assertSee('Go to the homepage');
    }

    public function test_private_application_paths_are_noindex(): void
    {
        $this->get('/platform/marketing/pages')
            ->assertNotFound()
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow');
    }

    public function test_robots_txt_disallows_crawling_outside_production(): void
    {
        $this->get('/robots.txt')
            ->assertOk()
            ->assertHeader('Content-Type', 'text/plain; charset=UTF-8')
            ->assertSee("User-agent: *\nDisallow: /\n", false);
    }

    public function test_robots_txt_allows_public_site_and_excludes_private_paths_when_indexable(): void
    {
        config(['marketing.indexable' => true]);

        $this->get('/robots.txt')
            ->assertOk()
            ->assertSee('Disallow: /platform')
            ->assertSee('Disallow: /api/')
            ->assertSee('Sitemap: '.url('/sitemap.xml'));
    }

    public function test_sitemap_contains_only_published_indexable_pages(): void
    {
        $this->get('/sitemap.xml')
            ->assertOk()
            ->assertHeader('Content-Type', 'application/xml; charset=UTF-8')
            ->assertSee('<loc>'.rtrim(url('/'), '/').'/</loc>', false)
            ->assertSee('<loc>'.url('/stock-taking').'</loc>', false)
            ->assertSee('<loc>'.url('/stock-taking/on-demand').'</loc>', false)
            ->assertSee('<loc>'.url('/brathamatu-pos/retail').'</loc>', false)
            ->assertDontSee('/privacy')
            ->assertDontSee('/terms')
            ->assertDontSee('/cookies');
    }

    public function test_sitemap_excludes_published_pages_marked_noindex_or_scheduled_in_the_future(): void
    {
        $noindex = $this->makePage('hidden-published', ['noindex' => true]);
        SeoMetadata::create([
            'seoable_type' => CmsPage::class,
            'seoable_id' => $noindex->id,
            'robots_index' => false,
        ]);

        $this->makePage('future-page', ['published_at' => now()->addDay()]);

        $this->get('/sitemap.xml')
            ->assertOk()
            ->assertDontSee('/hidden-published')
            ->assertDontSee('/future-page');
    }

    public function test_published_noindex_page_renders_but_is_marked_noindex(): void
    {
        config(['marketing.indexable' => true]);
        $this->makePage('internal-page', ['noindex' => true]);

        $this->get('/internal-page')
            ->assertOk()
            ->assertSee('name="robots" content="noindex, follow"', false)
            ->assertSee('Internal test page');
    }

    public function test_seo_metadata_overrides_generated_title_and_description(): void
    {
        $page = $this->makePage('custom-seo', ['title' => 'Custom page']);
        SeoMetadata::create([
            'seoable_type' => CmsPage::class,
            'seoable_id' => $page->id,
            'title' => 'A custom title for search',
            'description' => 'A custom meta description.',
        ]);

        $this->get('/custom-seo')
            ->assertOk()
            ->assertSee('A custom title for search')
            ->assertSee('name="description" content="A custom meta description."', false);
    }

    public function test_cms_content_is_escaped_in_public_html(): void
    {
        $page = $this->makePage('escaped-page');
        $page->sections()->create([
            'type' => 'hero',
            'sort_order' => 0,
            'content' => ['headline' => '<script>alert(1)</script>'],
            'is_visible' => true,
        ]);

        $this->get('/escaped-page')
            ->assertOk()
            ->assertDontSee('<script>alert(1)</script>', false)
            ->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false);
    }

    public function test_menu_items_pointing_to_unpublished_pages_are_hidden(): void
    {
        $draft = $this->makePage('hidden-draft', ['status' => 'draft', 'published_at' => null, 'noindex' => true]);
        $primary = CmsMenu::where('key', 'primary')->firstOrFail();
        $primary->items()->create([
            'label' => 'Hidden draft link',
            'page_id' => $draft->id,
            'sort_order' => 99,
        ]);

        $this->get('/')
            ->assertOk()
            ->assertDontSee('Hidden draft link');
    }

    public function test_canonical_base_redirects_non_canonical_hosts(): void
    {
        config([
            'marketing.canonical_base' => 'https://www.brathamatu.test',
            'marketing.canonical_host' => 'www.brathamatu.test',
        ]);

        $this->get('/stock-taking')
            ->assertStatus(301)
            ->assertRedirect('https://www.brathamatu.test/stock-taking');
    }

    public function test_legal_pages_render_with_noindex_and_are_linked_from_the_footer(): void
    {
        config(['marketing.indexable' => true]);

        $pages = [
            '/privacy' => 'Privacy Notice',
            '/terms' => 'Terms of Service',
            '/cookies' => 'Cookie and Tracking Notice',
        ];

        foreach ($pages as $path => $heading) {
            $this->get($path)
                ->assertOk()
                ->assertSee($heading)
                ->assertSee('Last updated: 16 November 2026')
                ->assertSee('name="robots" content="noindex, follow"', false);
        }

        $this->get('/')
            ->assertOk()
            ->assertSee('Privacy Notice')
            ->assertSee('Terms of Service')
            ->assertSee('Cookie Notice');
    }

    public function test_quote_and_demo_pages_offer_their_own_capture_forms(): void
    {
        $this->get('/request-quote')
            ->assertOk()
            ->assertSee('Send your request')
            ->assertSee('data-form="quote"', false)
            ->assertSee('action="'.url('/forms/quote').'"', false);

        $this->get('/book-demo')
            ->assertOk()
            ->assertSee('Request a demonstration')
            ->assertSee('data-form="pos_demo"', false)
            ->assertSee('action="'.url('/forms/pos-demo').'"', false);
    }

    public function test_published_core_pages_have_unique_titles_and_descriptions(): void
    {
        $titles = [];
        $descriptions = [];

        foreach (CmsPage::query()->published()->with('seo')->get() as $page) {
            $title = $page->seo?->title ?: $page->title;
            $description = $page->seo?->description;

            $this->assertNotEmpty($description, $page->slug.' has no meta description');
            $this->assertArrayNotHasKey($title, $titles, 'Duplicate title: '.$title);
            $this->assertArrayNotHasKey($description, $descriptions, 'Duplicate description: '.$description);

            $titles[$title] = true;
            $descriptions[$description] = true;
        }
    }

    public function test_marketing_content_seeder_is_idempotent(): void
    {
        $counts = [
            'pages' => CmsPage::count(),
            'sections' => CmsPageSection::count(),
            'menu_items' => CmsMenuItem::count(),
        ];

        $this->seed(MarketingContentSeeder::class);

        $this->assertSame($counts['pages'], CmsPage::count());
        $this->assertSame($counts['sections'], CmsPageSection::count());
        $this->assertSame($counts['menu_items'], CmsMenuItem::count());
        $this->assertSame(6, (int) json_decode(MarketingSetting::where('key', 'content.revision')->value('value'), true));
    }

    public function test_missing_marketing_tables_do_not_break_404_rendering(): void
    {
        Schema::drop('cms_page_sections');
        Schema::drop('cms_pages');
        Schema::drop('marketing_settings');
        Schema::drop('cms_menu_items');
        Schema::drop('cms_menus');

        $this->app->forgetScopedInstances();

        $this->get('/this-page-does-not-exist')
            ->assertNotFound()
            ->assertSee('Page not found');

        $this->get('/')
            ->assertNotFound()
            ->assertSee('Page not found');
    }

    private function makePage(string $slug, array $attributes = []): CmsPage
    {
        $page = CmsPage::create(array_merge([
            'slug' => $slug,
            'title' => 'Internal test page',
            'type' => 'page',
            'template' => 'public.page',
            'status' => 'published',
            'published_at' => now(),
            'noindex' => false,
            'requires_auth' => false,
        ], $attributes));

        return $page;
    }
}
