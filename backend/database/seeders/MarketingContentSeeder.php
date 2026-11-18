<?php

namespace Database\Seeders;

use App\Models\Marketing\BlogCategory;
use App\Models\Marketing\BlogPost;
use App\Models\Marketing\BlogTag;
use App\Models\Marketing\CmsMenu;
use App\Models\Marketing\CmsMenuItem;
use App\Models\Marketing\CmsPage;
use App\Models\Marketing\CmsPageSection;
use App\Models\Marketing\CmsPageVersion;
use App\Models\Marketing\EditorialItem;
use App\Models\Marketing\MarketingSetting;
use App\Models\Marketing\MediaAsset;
use App\Models\Marketing\ResourceDownload;
use App\Models\Marketing\SeoMetadata;
use App\Services\Marketing\MarketingPageService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

final class MarketingContentSeeder extends Seeder
{
    private const CONTENT_REVISION = 6;

    public function run(): void
    {
        $this->migrateLegacyBrand();
        $this->refreshSeededSections();
        $this->settings();
        $this->pages();
        $this->landingPages();
        $this->ensureFormSections();
        $this->blog();
        $this->menus();
        $this->markContentRevision();
    }

    private function migrateLegacyBrand(): void
    {
        $stored = MarketingSetting::query()->where('key', 'brand.revision')->value('value');

        if ($stored !== null && (int) json_decode($stored, true) >= 1) {
            return;
        }

        if (CmsPage::query()->where('slug', 'panda-pos')->exists() && ! CmsPage::query()->where('slug', 'brathamatu-pos')->exists()) {
            CmsPage::query()->where('slug', 'panda-pos')->update(['slug' => 'brathamatu-pos']);
        }

        CmsPage::query()->where('slug', 'like', 'panda-pos/%')->get()->each(function (CmsPage $page) {
            $slug = Str::replaceFirst('panda-pos/', 'brathamatu-pos/', $page->slug);

            if (! CmsPage::query()->where('slug', $slug)->exists()) {
                $page->update(['slug' => $slug]);
            }
        });

        CmsMenuItem::query()->where('label', 'PANDA POS')->update(['label' => 'BRATHAMATU POS']);
        CmsMenuItem::query()->where('label', 'PANDA TECH Stocktake')->update(['label' => 'BRATHAMATU Stocktake']);
        CmsMenuItem::query()->where('label', 'PANDA TECH')->update(['label' => 'BRATHAMATU']);

        MarketingSetting::updateOrCreate(
            ['key' => 'brand.revision'],
            ['group' => 'system', 'value' => 1, 'is_public' => false],
        );
    }

    private function refreshSeededSections(): void
    {
        $stored = MarketingSetting::query()->where('key', 'content.revision')->value('value');
        $revision = $stored === null ? 1 : (int) json_decode($stored, true);

        if ($revision >= self::CONTENT_REVISION) {
            return;
        }

        CmsPageSection::query()->delete();
        CmsPageVersion::query()->delete();
        CmsPage::query()->update(['current_version_id' => null]);
    }

    private function markContentRevision(): void
    {
        MarketingSetting::updateOrCreate(
            ['key' => 'content.revision'],
            ['group' => 'system', 'value' => self::CONTENT_REVISION, 'is_public' => false],
        );
    }

    private function settings(): void
    {
        $settings = [
            ['brand', 'brand.name', 'BRATHAMATU'],
            ['brand', 'brand.stocktake', 'BRATHAMATU Stocktake'],
            ['brand', 'brand.pos', 'BRATHAMATU POS'],
            ['brand', 'brand.tagline', 'Professional stock-taking and point-of-sale for growing businesses'],
            ['brand', 'brand.short_description', 'Professional stock-taking services and BRATHAMATU POS for retail, wholesale and warehouse operations.'],
            ['seo', 'seo.title_suffix', ' | BRATHAMATU'],
            ['seo', 'seo.default_description', 'Professional stock-taking services and BRATHAMATU POS for businesses in Kenya.'],
        ];

        foreach (['email', 'phone', 'whatsapp', 'address'] as $field) {
            $value = config('marketing.contact.'.$field);
            if ($value !== null && $value !== '') {
                $settings[] = ['contact', 'contact.'.$field, $value];
            }
        }

        foreach (['facebook', 'instagram', 'linkedin', 'x'] as $network) {
            $value = config('marketing.social.'.$network);
            if ($value !== null && $value !== '') {
                $settings[] = ['social', 'social.'.$network, $value];
            }
        }

        foreach ($settings as [$group, $key, $value]) {
            MarketingSetting::updateOrCreate(
                ['key' => $key],
                ['group' => $group, 'value' => $value, 'is_public' => true],
            );
        }

        MarketingSetting::updateOrCreate(
            ['key' => 'marketing.separation_of_duties'],
            ['group' => 'marketing', 'value' => true, 'is_public' => false],
        );
    }

    private function pages(): void
    {
        $this->publishPage('home', 'Home', [
            $this->hero(
                'Stock-taking and retail operations',
                'Accurate stock counts, without slowing your business',
                'BRATHAMATU Stocktake provides professional physical counts for retail, wholesale and warehouse operations. BRATHAMATU POS brings sales, inventory and stock-taking together.',
                [
                    'On-demand, monthly, annual and custom recurring counts',
                    'Independent verification, recounts and cycle counting',
                    'Works alongside BRATHAMATU POS or your existing systems',
                ],
                ['label' => 'Request a stock-taking quote', 'url' => '/request-quote'],
                ['label' => 'Explore BRATHAMATU POS', 'url' => '/brathamatu-pos'],
                'Stock-taking options',
                ['On-demand counts', 'Monthly stock taking', 'Annual stock taking', 'Custom recurring schedules'],
            ),
            $this->serviceGrid(
                'Stock-taking services built around your schedule',
                'Choose the frequency that fits your business. Recurring customers can still request additional on-demand counts when they need them.',
                [
                    ['On-demand stock taking', 'A count when you need one, without committing to a fixed schedule.', '/stock-taking/on-demand'],
                    ['Monthly stock taking', 'Regular monthly counts that keep recorded quantities close to reality.', '/stock-taking/monthly'],
                    ['Annual stock taking', 'A complete yearly count for reporting, audit preparation and year-end review.', '/stock-taking/annual'],
                    ['Recurring stock taking', 'A custom schedule for businesses that need counts more or less often than monthly.', '/stock-taking/recurring'],
                ],
            ),
            $this->richText('Two connected products, one operating rhythm', [
                'BRATHAMATU Stocktake is a professional stock-taking service. Our teams count physical stock, verify results and help you understand the difference between recorded and actual quantities.',
                'BRATHAMATU POS is a point-of-sale platform for sales, products, inventory workflows, branches and stock-taking service requests. Used together, sales, stock movements and physical counts share one view of inventory.',
                'You do not have to use BRATHAMATU POS to book a stock take, and you do not have to book a stock take to use BRATHAMATU POS.',
            ]),
            $this->posFeatures(
                'BRATHAMATU POS for day-to-day operations',
                'Sell, receive stock and keep branches aligned. BRATHAMATU POS is built for businesses that need practical inventory control.',
                [
                    ['Sales and receipts', 'Process sales, take payments and issue receipts from the terminal.'],
                    ['Product catalogue and barcodes', 'Organise products, variants and barcodes so selling and counting use the same records.'],
                    ['Inventory and branches', 'Track stock movements across branches, warehouses and stock locations.'],
                    ['Stock-taking integration', 'Request and authorise stock-taking services from inside the POS workflow.'],
                    ['Purchases and suppliers', 'Record purchases, manage suppliers and keep incoming stock accounted for.'],
                    ['Reporting', 'Review sales, stock and branch performance in one place.'],
                ],
            ),
            $this->industriesSection(
                'Built for inventory-heavy businesses',
                'Different industries count differently. The process, scope and reporting are adapted to how your stock is stored and sold.',
                [
                    ['Retail shops', 'Counts arranged around opening hours, shelves and back-room stock.'],
                    ['Supermarkets', 'High-volume counts with barcode capture and exception reporting.'],
                    ['Pharmacies', 'Careful counting for regulated products, batches and expiry-sensitive stock.'],
                    ['Wholesale and distribution', 'Warehouse-focused counts that follow how goods are received, stored and dispatched.'],
                    ['Hardware', 'Counts that handle bulk items, loose quantities and mixed units.'],
                    ['Restaurants and hospitality', 'Stock checks for food, beverage and consumable inventory.'],
                ],
            ),
            $this->serviceGrid(
                'Why businesses choose BRATHAMATU',
                'The service is designed around the realities of counting physical stock, not only the software around it.',
                [
                    ['Scope matched to your catalogue', 'Counts are planned against your products, units and locations rather than a fixed template.'],
                    ['Independent checks where needed', 'Verification and recounts provide a separate check when accuracy or audit requirements demand it.'],
                    ['Flexible frequency', 'Choose a single count, a recurring schedule or a combination of both as your stock changes.'],
                    ['Connected records', 'Use BRATHAMATU POS or keep your existing systems; counts can work alongside either.'],
                ],
            ),
            $this->process(
                'How a stock take works',
                'A clear sequence from first enquiry to final report.',
                [
                    ['Share your requirements', 'Tell us what you need counted, where it is stored and when you would like the count.'],
                    ['Receive a quotation', 'We review the scope, locations and catalogue size, then prepare a quotation.'],
                    ['Agree the schedule', 'Confirm dates, access arrangements and the people who will be available on site.'],
                    ['Count and verify', 'The team counts physical stock and records exceptions and discrepancies.'],
                    ['Review results', 'You receive the count outcome, discrepancy information and next-step recommendations.'],
                ],
            ),
            $this->faq('Questions businesses ask', [
                ['What types of stock-taking do you provide?', 'We provide on-demand, monthly, annual and custom recurring stock-taking, along with full counts, cycle counting, branch and warehouse counts, independent verification and recounts.'],
                ['Can a recurring customer request an extra count?', 'Yes. Booking a recurring schedule does not prevent you from requesting additional on-demand stock-taking when you need it.'],
                ['Do we need to use BRATHAMATU POS?', 'No. Stock-taking services are available to businesses using BRATHAMATU POS and to businesses using other systems or manual records.'],
                ['What information should we prepare?', 'Business name, the locations to be counted, an approximate product catalogue size, the systems you use and your preferred dates.'],
                ['How do we request a quotation?', 'Use the quote request page to describe your requirements. We will review the scope and prepare a quotation.'],
            ]),
            $this->richText('Practical guidance for better stock control', [
                'The resource hub collects guidance on preparing for a count, choosing a count frequency, cycle counting and keeping inventory records aligned between counts.',
                'Guides are being prepared. Until they are published, the team can answer questions about your current process and the right starting point.',
            ], [], ['label' => 'Visit the resource hub', 'url' => '/resources']),
            $this->contactSection(
                'Talk to BRATHAMATU',
                'Share your stock-taking or POS requirements and the team will take it from there.',
                ['Stock-taking quotations', 'BRATHAMATU POS demonstrations', 'Recurring schedule enquiries'],
            ),
            $this->cta(
                'Planning a count? Start with your scope.',
                'Tell us what you need counted, where, and when. We will use that to prepare the right service and quotation.',
                ['label' => 'Request a stock-taking quote', 'url' => '/request-quote'],
                ['label' => 'Book a POS demo', 'url' => '/book-demo'],
            ),
        ], [
            'title' => 'BRATHAMATU | Professional stock-taking and BRATHAMATU POS',
            'description' => 'Professional stock-taking services and BRATHAMATU POS for retail, wholesale and warehouse businesses in Kenya. Request an on-demand, monthly, annual or recurring count.',
            'priority' => 1.0,
            'change_frequency' => 'weekly',
        ], isHome: true);

        $this->publishPage('stock-taking', 'Stock Taking', [
            $this->hero(
                'Stock taking',
                'Stock-taking services for every frequency and scope',
                'From a single on-demand count to a recurring schedule across branches, BRATHAMATU Stocktake adapts the service to how your business operates.',
                [],
                ['label' => 'Request a stock-taking quote', 'url' => '/request-quote'],
                ['label' => 'See how it works', 'url' => '/how-it-works'],
                'Service options',
                ['On-demand stock taking', 'Monthly stock taking', 'Annual stock taking', 'Custom recurring schedules', 'Verification and recounts'],
            ),
            $this->richText('Choosing the right stock-taking service', [
                'The right frequency depends on how quickly your stock changes, how many locations you operate and what your reporting requires. A fast-moving retail business may need monthly counts, while a smaller operation may only need an annual count.',
                'Every service can include independent verification and recounts where discrepancies need investigation. Recurring customers can still request additional on-demand counts.',
            ]),
            $this->serviceGrid('Stock-taking services', 'Explore the scope that matches your business.', [
                ['On-demand stock taking', 'Counts scheduled when you need them, without a fixed recurring commitment.', '/stock-taking/on-demand'],
                ['Monthly stock taking', 'Monthly physical counts that keep inventory records closely aligned with actual stock.', '/stock-taking/monthly'],
                ['Annual stock taking', 'A complete yearly count for reporting, audit preparation and year-end review.', '/stock-taking/annual'],
                ['Recurring stock taking', 'A custom schedule for weekly, quarterly or other recurring counts.', '/stock-taking/recurring'],
                ['Full stock taking', 'A complete physical count across the agreed catalogue and locations.', '/stock-taking/full'],
                ['Cycle counting', 'Periodic counting of selected products, categories or locations instead of counting everything at once.', '/stock-taking/cycle-counting'],
                ['Branch stock taking', 'Counts for businesses operating multiple branches with separate stock.', '/stock-taking/branch'],
                ['Warehouse stock taking', 'Counts for warehouses and distribution operations, including stock locations.', '/stock-taking/warehouse'],
                ['Independent verification', 'A separate physical count to verify reported quantities or support an audit.', '/stock-taking/independent-verification'],
                ['Recounts', 'Focused recounts when discrepancies need to be investigated.', '/stock-taking/recounts'],
            ]),
            $this->process('How the service runs', 'Each count follows a defined sequence.', [
                ['Scope', 'Agree the products, locations and count method.'],
                ['Quotation', 'Receive a quotation based on the agreed scope.'],
                ['Schedule', 'Choose the date and prepare site access.'],
                ['Count', 'The team counts and records physical stock.'],
                ['Report', 'Review outcomes, discrepancies and follow-up actions.'],
            ]),
            $this->faq('Stock-taking questions', [
                ['Can we combine frequencies?', 'Yes. A recurring schedule can sit alongside additional on-demand counts when your stock position needs an extra check.'],
                ['Do you count across multiple branches?', 'Branch stock taking can cover multiple locations within one engagement when the scope is agreed in advance.'],
                ['What happens when counts do not match?', 'Discrepancies are recorded, and a recount or focused investigation can be arranged.'],
                ['Can counts be arranged outside normal trading hours?', 'Scheduling is agreed per business so that access and disruption are planned in advance.'],
            ]),
            $this->cta(
                'Tell us what you need counted.',
                'Share your locations, approximate catalogue size and preferred dates so we can prepare a quotation.',
                ['label' => 'Request a stock-taking quote', 'url' => '/request-quote'],
                ['label' => 'Contact the team', 'url' => '/contact'],
            ),
        ], [
            'title' => 'Stock-taking services in Kenya',
            'description' => 'On-demand, monthly, annual and recurring stock-taking services for retail, wholesale and warehouse businesses. Independent verification and recounts available.',
            'priority' => 0.9,
        ]);

        $this->publishPage('brathamatu-pos', 'BRATHAMATU POS', [
            $this->hero(
                'BRATHAMATU POS',
                'Sales, stock and branches in one place',
                'BRATHAMATU POS supports sales, products, inventory workflows, branches and stock-taking service requests for growing businesses.',
                [],
                ['label' => 'Book a BRATHAMATU POS demo', 'url' => '/book-demo'],
                ['label' => 'Explore stock taking', 'url' => '/stock-taking'],
                'Designed for',
                ['Retail and supermarkets', 'Pharmacies', 'Wholesale and distribution', 'Hardware', 'Restaurants', 'Multi-branch operations'],
            ),
            $this->posFeatures('What BRATHAMATU POS covers', 'The operational features a growing business needs.', [
                ['Sales and receipts', 'Process sales, take payments and issue receipts from the terminal.'],
                ['Product catalogue and barcodes', 'Organise products, variants, units and barcodes.', '/brathamatu-pos/barcode-catalogue'],
                ['Inventory workflows', 'Record stock movements and keep branch stock aligned.', '/brathamatu-pos/inventory-management'],
                ['Multi-branch operations', 'Manage terminals, stock and reporting across locations.', '/brathamatu-pos/multi-branch'],
                ['Purchases and suppliers', 'Record incoming stock and manage supplier records.'],
                ['Stock-taking integration', 'Request and authorise stock-taking services from the POS workflow.', '/brathamatu-pos/stocktaking-integration'],
            ]),
            $this->industriesSection('BRATHAMATU POS by industry', 'The same platform, adapted to how each business sells and counts.', [
                ['Retail', 'Everyday sales, barcodes and product management.', '/brathamatu-pos/retail'],
                ['Supermarkets', 'High-volume selling with stock workflows.', '/brathamatu-pos/supermarket'],
                ['Pharmacies', 'Product catalogue support for regulated inventory.', '/brathamatu-pos/pharmacy'],
                ['Wholesale', 'Bulk sales and distribution-focused inventory.', '/brathamatu-pos/wholesale'],
                ['Hardware', 'Mixed units and bulk items.', '/brathamatu-pos/hardware'],
                ['Restaurants', 'Fast sales workflows and consumable stock.', '/brathamatu-pos/restaurant'],
                ['Multi-branch', 'Terminals and stock across locations.', '/brathamatu-pos/multi-branch'],
            ]),
            $this->richText('POS and stock-taking together', [
                'When BRATHAMATU POS is used alongside BRATHAMATU Stocktake, physical counts can be matched against the inventory records maintained through day-to-day sales and stock movements.',
                'Stock-taking services can also be requested without BRATHAMATU POS, and BRATHAMATU POS can be used without booking a stock take.',
            ]),
            $this->process('From demonstration to daily use', 'A practical onboarding path for the workflows you need.', [
                ['See the workflows', 'A demonstration focuses on the sales, stock and branch workflows relevant to your business.'],
                ['Confirm fit', 'Review how BRATHAMATU POS would fit your catalogue, terminals and operating model.'],
                ['Prepare your data', 'Agree the product catalogue, barcodes, users and branch structure needed for setup.'],
                ['Go live', 'Begin using the configured workflows and request stock-taking services from the POS when needed.'],
            ]),
            $this->richText('What you can prepare before a demo', [
                'You do not need a complete data set to see a demonstration. A short description of your operation is enough to focus the walkthrough.',
            ], [
                'Business type and number of branches or terminals',
                'Approximate number of products and how they are grouped',
                'Whether barcodes are already in use',
                'The sales, purchasing and stock workflows you want to review',
            ]),
            $this->faq('BRATHAMATU POS questions', [
                ['Is BRATHAMATU POS only for supermarkets?', 'No. The platform supports retail, supermarket, pharmacy, wholesale, hardware, restaurant and multi-branch operations.'],
                ['Can we request a stock take from BRATHAMATU POS?', 'Yes. Stock-taking services can be requested and authorised from the POS workflow.'],
                ['Does BRATHAMATU POS require BRATHAMATU Stocktake?', 'No. The products work independently, but they are designed to work well together.'],
                ['How do we see a demonstration?', 'Use the demo request page to share your business type and requirements.'],
            ]),
            $this->cta(
                'See BRATHAMATU POS with your own products.',
                'Request a demonstration and we will walk through the workflows that matter to your business.',
                ['label' => 'Book a BRATHAMATU POS demo', 'url' => '/book-demo'],
                ['label' => 'Request a stock-taking quote', 'url' => '/request-quote'],
            ),
        ], [
            'title' => 'BRATHAMATU POS | Point of sale and inventory management',
            'description' => 'BRATHAMATU POS supports sales, products, inventory, branches and stock-taking service requests for retail, wholesale and multi-branch businesses.',
            'priority' => 0.9,
        ]);

        $this->publishPage('how-it-works', 'How It Works', [
            $this->hero(
                'How it works',
                'A clear process from enquiry to stock-count report',
                'Every stock take follows agreed scope, scheduling and reporting steps so that the count is planned and the outcome is understandable.',
                [],
                ['label' => 'Start a quote request', 'url' => '/request-quote'],
                ['label' => 'Contact the team', 'url' => '/contact'],
            ),
            $this->process('The stock-taking journey', 'What to expect at each stage.', [
                ['1. Share your requirements', 'Tell us the business, locations, approximate catalogue size, systems in use and preferred dates.'],
                ['2. Scope review', 'We review what needs to be counted, how the stock is stored and any access constraints.'],
                ['3. Quotation', 'You receive a quotation based on the agreed scope.'],
                ['4. Scheduling', 'Dates and site arrangements are confirmed before the count.'],
                ['5. Count and verification', 'Physical stock is counted, exceptions recorded and discrepancies reviewed.'],
                ['6. Results review', 'The count outcome is shared and next steps are agreed.'],
            ]),
            $this->richText('What happens on the day', [
                'The counting team works to the agreed scope and records quantities against the product catalogue. Exceptions, unknown products and count discrepancies are captured for review.',
                'Where independent verification or a recount is included, a separate check is performed so that reported quantities can be confirmed.'],
            ),
            $this->richText('Where BRATHAMATU POS fits', [
                'A stock take does not require BRATHAMATU POS, and a BRATHAMATU POS demonstration does not commit you to a count. The two are separate services that can be used together.',
                'Where BRATHAMATU POS is in use, the stock records maintained through sales, purchases and stock movements can be compared with the physical quantities recorded during the count. That gives the count a clearer starting point and helps identify where records and shelves differ.',
            ], [], ['label' => 'Explore BRATHAMATU POS', 'url' => '/brathamatu-pos']),
            $this->faq('Before your first count', [
                ['What should we prepare?', 'Have the locations, approximate catalogue size, systems in use and preferred dates ready. Product records and barcodes help, but a first count can be scoped without a perfect data set.'],
                ['How long does a count take?', 'The time depends on the catalogue, locations and count method. The expected duration is part of the scope and scheduling discussion.'],
                ['What happens after the count?', 'The count outcome, exceptions and discrepancies are reviewed with you, and any recount or follow-up work is agreed.'],
                ['Can the count happen outside trading hours?', 'Scheduling is agreed per business so that access, trading hours and disruption are planned in advance.'],
            ]),
            $this->cta(
                'Have a scope in mind?',
                'Send the basics and we will help shape the right stock-taking service.',
                ['label' => 'Request a stock-taking quote', 'url' => '/request-quote'],
                ['label' => 'Explore services', 'url' => '/stock-taking'],
            ),
        ], [
            'title' => 'How stock taking works',
            'description' => 'Understand the BRATHAMATU stock-taking process: scope review, quotation, scheduling, physical counting, verification and results review.',
            'priority' => 0.7,
        ]);

        $this->publishPage('industries', 'Industries', [
            $this->hero(
                'Industries',
                'Stock-taking and POS for inventory-heavy businesses',
                'The count process and the POS workflow should match how the business actually stores, sells and moves stock.',
                [],
                ['label' => 'Request a stock-taking quote', 'url' => '/request-quote'],
                ['label' => 'Book a POS demo', 'url' => '/book-demo'],
            ),
            $this->industriesSection('Industries we serve', 'Counts and POS workflows adapted to each operation.', [
                ['Retail shops', 'Shelf and back-room counts arranged around trading hours.'],
                ['Supermarkets', 'High-volume counting with barcode capture and exception reporting.'],
                ['Pharmacies', 'Careful product, batch and expiry-sensitive inventory.'],
                ['Wholesale and distribution', 'Warehouse-oriented counts and stock movement visibility.'],
                ['Hardware', 'Bulk items, loose quantities and mixed units.'],
                ['Restaurants and hospitality', 'Food, beverage and consumable stock checks.'],
            ]),
            $this->process('How we adapt the service', 'The scope is shaped around the operation.', [
                ['Understand the stock', 'Products, units, batches, locations and how stock moves.'],
                ['Agree the method', 'Full count, cycle count, verification or a combination.'],
                ['Plan access', 'Trading hours, storage areas and site contacts.'],
                ['Count and report', 'Record quantities, exceptions and discrepancies.'],
            ]),
            $this->richText('Industry-specific pages', [
                'Detailed industry pages are being prepared. In the meantime, the quote request page lets you describe your industry, locations and catalogue so the service can be scoped correctly.',
            ]),
            $this->faq('Industry questions', [
                ['Can a count cover several branches?', 'Yes. Multi-branch counts can be scoped across locations when access and timing are agreed in advance.'],
                ['Do you count items by batch or expiry date?', 'Where batches or expiry dates matter, they can be included in the counting scope and recording method.'],
                ['What if our stock is stored in more than one area?', 'Shelf, back-room, warehouse and other stock locations can be included so the count covers all agreed areas.'],
                ['Does BRATHAMATU POS work for a single shop?', 'Yes. BRATHAMATU POS supports single-location businesses as well as multi-branch operations.'],
            ]),
            $this->cta(
                'Not sure which service fits your industry?',
                'Describe your operation and we will recommend the right approach.',
                ['label' => 'Request a stock-taking quote', 'url' => '/request-quote'],
                ['label' => 'Contact us', 'url' => '/contact'],
            ),
        ], [
            'title' => 'Stock-taking and POS by industry',
            'description' => 'Stock-taking and BRATHAMATU POS for retail, supermarkets, pharmacies, wholesale, hardware, restaurants and multi-branch operations.',
            'priority' => 0.7,
        ]);

        $this->publishPage('pricing', 'Pricing', [
            $this->hero(
                'Pricing',
                'Quotations based on what actually needs counting',
                'Stock-taking is priced against the scope of the work. We do not publish a single price because catalogue size, locations, frequency and verification requirements change the effort involved.',
                [],
                ['label' => 'Request a quotation', 'url' => '/request-quote'],
                ['label' => 'Book a POS demo', 'url' => '/book-demo'],
            ),
            $this->richText('What shapes a quotation', [
                'A quotation is prepared after reviewing the scope. The main factors are the number of locations, the approximate catalogue size, how stock is stored, how often you need counts and whether independent verification or recounts are included.',
                'No price is displayed on this website until approved pricing is configured. Use the quote request page to receive a quotation for your requirements.',
            ]),
            $this->faq('Pricing questions', [
                ['Why is pricing not listed?', 'Scope varies significantly between businesses. A quotation based on your locations, catalogue and frequency is more accurate than a single published price.'],
                ['Do recurring schedules cost less per count?', 'Recurring schedules are quoted based on the agreed frequency and scope. Request a quotation for your schedule.'],
                ['Are verification and recounts separate?', 'They can be included in the scope or requested separately. The quotation will make the inclusions clear.'],
            ]),
            $this->cta(
                'Get a quotation for your scope.',
                'Share your locations, catalogue size, preferred frequency and dates.',
                ['label' => 'Request a quotation', 'url' => '/request-quote'],
                ['label' => 'Contact the team', 'url' => '/contact'],
            ),
        ], [
            'title' => 'Stock-taking pricing and quotations',
            'description' => 'Stock-taking quotations are based on locations, catalogue size, frequency and verification requirements. Request a quotation for your business.',
            'priority' => 0.6,
        ]);

        $this->publishPage('resources', 'Resources', [
            $this->hero(
                'Resources',
                'Practical guidance for better stock control',
                'Guides, articles and resources for businesses preparing for stock counts and improving inventory accuracy.',
                [],
                ['label' => 'Talk to the team', 'url' => '/contact'],
                ['label' => 'Request a quotation', 'url' => '/request-quote'],
            ),
            $this->richText('What you will find here', [
                'This hub collects practical articles on stock-taking preparation, cycle counting, inventory accuracy and choosing a count approach that suits your operation.',
                'Browse the latest articles below, or use the categories to narrow the list. For guidance on a specific count, contact the team.',
            ]),
            $this->cta(
                'Need guidance now?',
                'Tell us about your stock and current process, and we will point you in the right direction.',
                ['label' => 'Contact the team', 'url' => '/contact'],
                ['label' => 'Request a quotation', 'url' => '/request-quote'],
            ),
        ], [
            'title' => 'Stock-taking resources and guides',
            'description' => 'Guides and resources on stock-taking preparation, cycle counting and inventory accuracy from BRATHAMATU.',
            'priority' => 0.5,
        ]);

        $this->publishPage('about', 'About Us', [
            $this->hero(
                'About BRATHAMATU',
                'Professional stock-taking, supported by practical software',
                'BRATHAMATU provides stock-taking services and BRATHAMATU POS for businesses that need reliable physical inventory counts and day-to-day operational control.',
                [],
                ['label' => 'Request a stock-taking quote', 'url' => '/request-quote'],
                ['label' => 'Contact us', 'url' => '/contact'],
            ),
            $this->richText('What we focus on', [
                'Our work centres on accurate physical counts, independent verification and clear reporting. Stock taking is a process, not only a number: the scope, method and follow-up all matter.',
                'BRATHAMATU POS extends that operating discipline into sales, products, inventory workflows and branches, so businesses can keep records and physical stock aligned between counts.',
            ]),
            $this->serviceGrid('How we work', 'Four principles shape every engagement.', [
                ['Accuracy', 'Counts are recorded against the agreed catalogue and exceptions are captured.'],
                ['Independence', 'Verification and recounts provide a separate check where required.'],
                ['Practical scheduling', 'Dates and access are planned around how the business operates.'],
                ['Clear reporting', 'Results and discrepancies are presented in a form the business can act on.'],
            ]),
            $this->cta(
                'Work with BRATHAMATU.',
                'Start with your stock-taking scope or a BRATHAMATU POS demonstration.',
                ['label' => 'Request a stock-taking quote', 'url' => '/request-quote'],
                ['label' => 'Book a POS demo', 'url' => '/book-demo'],
            ),
        ], [
            'title' => 'About BRATHAMATU',
            'description' => 'BRATHAMATU provides professional stock-taking services and BRATHAMATU POS for retail, wholesale and warehouse businesses.',
            'priority' => 0.5,
        ]);

        $this->publishPage('contact', 'Contact', [
            $this->hero(
                'Contact',
                'Contact BRATHAMATU',
                'Share your stock-taking or BRATHAMATU POS requirements and the team will take it from there.',
                [],
                ['label' => 'Request a quotation', 'url' => '/request-quote'],
                ['label' => 'Book a demo', 'url' => '/book-demo'],
            ),
            $this->contactSection(
                'Get in touch',
                'Direct contact details are shown here once confirmed. You can also use the quote request page to describe your requirements.',
                ['Stock-taking quotations', 'BRATHAMATU POS demonstrations', 'Partnership and general enquiries'],
            ),
            $this->richText('What to include in your message', [
                'For stock-taking enquiries, include your business name, locations, approximate catalogue size and preferred dates. For BRATHAMATU POS, include your business type, number of branches and the workflows you want to see.',
            ], ['Business name and contact person', 'Locations to be covered', 'Approximate product catalogue size', 'Preferred dates or frequency']),
            $this->formSectionPresets()['contact'],
            $this->formSectionPresets()['callback'],
            $this->formSectionPresets()['partnership'],
            $this->faq('Contact questions', [
                ['Will you sign a confidentiality agreement?', 'Confidentiality requirements can be raised with your enquiry and reviewed as part of the engagement terms before work begins.'],
                ['Can we start with a single branch?', 'Yes. A stock take can be scoped to one location, with additional branches added later when the scope is agreed.'],
                ['How soon can a count be scheduled?', 'Scheduling depends on the scope, location and team availability. Share your preferred dates and the team will confirm what is possible.'],
                ['What should a BRATHAMATU POS enquiry include?', 'Your business type, number of branches or terminals, and the sales, stock or reporting workflows you want to review.'],
            ]),
            $this->cta(
                'Ready to start?',
                'Request a quotation or a BRATHAMATU POS demonstration.',
                ['label' => 'Request a stock-taking quote', 'url' => '/request-quote'],
                ['label' => 'Book a POS demo', 'url' => '/book-demo'],
            ),
        ], [
            'title' => 'Contact BRATHAMATU',
            'description' => 'Contact BRATHAMATU for stock-taking quotations, BRATHAMATU POS demonstrations and general business enquiries.',
            'priority' => 0.6,
        ]);

        $this->publishPage('request-quote', 'Request a Quote', [
            $this->hero(
                'Request a quote',
                'Request a stock-taking quotation',
                'Tell us what needs counting, where and when. We will review the scope and prepare a quotation.',
                [],
                ['label' => 'Book a POS demo', 'url' => '/book-demo'],
                ['label' => 'Explore services', 'url' => '/stock-taking'],
            ),
            $this->richText('What to prepare', [
                'A quotation is prepared from the scope of the work. The more detail you provide at the start, the more accurate the quotation can be.',
            ], [
                'Business name and contact person',
                'Number of branches, warehouses or stock locations',
                'Approximate product catalogue size',
                'Systems used for sales and inventory',
                'Preferred dates or stock-taking frequency',
                'Whether independent verification or recounts are required',
            ]),
            $this->process('After you send your request', 'The quotation process is straightforward.', [
                ['Review', 'We review the scope and identify any details to confirm.'],
                ['Clarify', 'We may ask about locations, catalogue structure or access.'],
                ['Quote', 'You receive a quotation for the agreed scope.'],
                ['Schedule', 'Dates are confirmed once the quotation is accepted.'],
            ]),
            $this->formSectionPresets()['quote'],
        ], [
            'title' => 'Request a stock-taking quote',
            'description' => 'Request a stock-taking quotation from BRATHAMATU. Share your locations, catalogue size, preferred frequency and dates.',
            'priority' => 0.7,
        ], type: 'landing');

        $this->publishPage('book-demo', 'Book a Demo', [
            $this->hero(
                'BRATHAMATU POS demo',
                'Book a BRATHAMATU POS demonstration',
                'See how sales, products, inventory, branches and stock-taking requests work together.',
                [],
                ['label' => 'Request a stock-taking quote', 'url' => '/request-quote'],
                ['label' => 'Explore stock taking', 'url' => '/stock-taking'],
            ),
            $this->richText('What the demonstration covers', [
                'A demonstration is shaped around your business type and the workflows you want to see. We can cover sales and receipts, product catalogues and barcodes, inventory movements, branches and stock-taking requests.',
            ], [
                'Sales and receipt workflows',
                'Product catalogue and barcode setup',
                'Inventory and branch workflows',
                'Stock-taking service requests from the POS',
            ]),
            $this->process('How the demo works', 'A short, focused walkthrough.', [
                ['Share your context', 'Business type, branches and the workflows you want to see.'],
                ['Choose a time', 'We confirm a suitable demonstration time.'],
                ['Walkthrough', 'The relevant BRATHAMATU POS workflows are demonstrated.'],
                ['Questions', 'Discuss fit, setup and next steps.'],
            ]),
            $this->formSectionPresets()['pos_demo'],
        ], [
            'title' => 'Book a BRATHAMATU POS demo',
            'description' => 'Book a BRATHAMATU POS demonstration covering sales, products, inventory, branches and stock-taking service requests.',
            'priority' => 0.7,
        ], type: 'landing');

        $this->publishPage('faqs', 'FAQs', [
            $this->hero(
                'FAQs',
                'Frequently asked questions',
                'Answers about stock-taking services, BRATHAMATU POS and how to get started.',
                [],
                ['label' => 'Request a quote', 'url' => '/request-quote'],
                ['label' => 'Contact us', 'url' => '/contact'],
            ),
            $this->faq('Stock-taking and BRATHAMATU POS', [
                ['What is BRATHAMATU Stocktake?', 'A professional stock-taking service covering physical counts, independent verification, cycle counting and recounts.'],
                ['What stock-taking frequencies are available?', 'On-demand, monthly, annual and custom recurring schedules.'],
                ['Can recurring customers request extra counts?', 'Yes. A recurring schedule does not prevent additional on-demand counts.'],
                ['Do we need BRATHAMATU POS to book a stock take?', 'No. The stock-taking service works with BRATHAMATU POS and with other systems or manual records.'],
                ['What is BRATHAMATU POS?', 'A point-of-sale platform supporting sales, products, inventory workflows, branches and stock-taking service requests.'],
                ['How do we choose a count frequency?', 'It depends on how quickly stock changes, how many locations you operate and what your reporting requires. The quote request page lets you describe your situation so the right frequency can be recommended.'],
                ['Can a count cover one branch?', 'Yes. A count can be scoped to a single location or across several branches, depending on what needs to be verified.'],
                ['Can counts be arranged outside trading hours?', 'Scheduling is agreed per business so access and disruption are planned in advance.'],
                ['How are confidentiality requirements handled?', 'Raise confidentiality or agreement requirements with your enquiry so they can be reviewed as part of the engagement terms.'],
                ['What should we prepare for a BRATHAMATU POS demonstration?', 'Your business type, number of branches or terminals and the workflows you want to see. A complete product data set is not required for a demonstration.'],
                ['How do we request a quotation or demo?', 'Use the quote request page for stock-taking or the demo page for BRATHAMATU POS.'],
            ]),
            $this->cta(
                'Still have a question?',
                'Send it to the team and we will help.',
                ['label' => 'Contact us', 'url' => '/contact'],
                ['label' => 'Request a quote', 'url' => '/request-quote'],
            ),
        ], [
            'title' => 'Frequently asked questions',
            'description' => 'Answers about BRATHAMATU stock-taking services, frequencies, independent verification and BRATHAMATU POS.',
            'priority' => 0.6,
        ]);

        $this->publishPage('privacy', 'Privacy Notice', [
            $this->legalDocument('Privacy Notice', [
                ['heading' => 'Who we are', 'paragraphs' => [
                    'BRATHAMATU provides stock-taking services and BRATHAMATU POS. This notice explains how personal information is handled when you use this website, request a quotation, ask about a demonstration or contact the team.',
                    'The legal entity name, registered address and data-protection contact for BRATHAMATU are being confirmed. They will be published here before this notice is treated as final. Until then, use the contact page for privacy questions.',
                ]],
                ['heading' => 'Information we collect', 'paragraphs' => [
                    'We ask only for information that is relevant to the enquiry. Please do not send sensitive personal information or third-party data unless it is necessary and you are authorised to share it.',
                ], 'bullets' => [
                    'Details you submit in an enquiry: your name, business name, role, email address, phone number, locations, approximate catalogue size, systems in use, preferred dates and your message.',
                    'Service and demonstration context you choose to share, such as business type, number of branches, terminals and workflows.',
                    'Technical information recorded by the web server, such as IP address, browser type, requested pages and timestamps.',
                    'Cookie and similar storage information described in the Cookie and Tracking Notice.',
                ]],
                ['heading' => 'How we use information', 'paragraphs' => [
                    'We do not use enquiry details for unrelated marketing unless you have asked to receive marketing communications or consent is required and has been given.',
                ], 'bullets' => [
                    'Respond to enquiries, prepare quotations and arrange demonstrations.',
                    'Plan, schedule and deliver stock-taking services.',
                    'Operate, secure and improve the website.',
                    'Comply with legal, tax, accounting and regulatory obligations.',
                    'Send service-related communications about an enquiry or engagement.',
                ]],
                ['heading' => 'Lawful basis and consent', 'paragraphs' => [
                    'Where a form asks for consent, that choice is recorded with the submission and can be withdrawn. Depending on the context, information is otherwise processed because it is needed to respond to your request, take steps towards or perform a contract, meet legal obligations, or because there is a legitimate interest in operating and securing the website.',
                    'Where consent is the basis for processing, it can be withdrawn at any time. Withdrawal does not affect processing that happened before it.',
                ]],
                ['heading' => 'Sharing information', 'paragraphs' => [
                    'We do not sell personal information. Providers that process information on our behalf are expected to handle it only for the agreed purpose and with appropriate safeguards.',
                ], 'bullets' => [
                    'Hosting, email, IT and security providers that support the website and communications.',
                    'Professional advisers and service providers engaged for a stock-taking or POS engagement.',
                    'Authorities or other parties where disclosure is required by law or necessary to protect legal rights.',
                ]],
                ['heading' => 'Retention', 'paragraphs' => [
                    'Information is kept only for as long as it is needed for the purpose it was collected, including any period required by law or for the establishment or defence of legal claims. Specific retention periods are being confirmed and will be recorded in the internal retention schedule before launch.',
                    'Enquiries that do not lead to an engagement are removed when they are no longer needed for follow-up or record-keeping.',
                ]],
                ['heading' => 'Your rights', 'paragraphs' => [
                    'To make a request, use the contact page and describe what you need. We may need to confirm your identity before acting on a request.',
                ], 'bullets' => [
                    'Ask for a copy of the personal information we hold about you.',
                    'Ask for inaccurate information to be corrected.',
                    'Ask for information to be deleted or processing restricted where the law allows.',
                    'Object to processing based on legitimate interests.',
                    'Withdraw consent where processing relies on consent.',
                    'Complain to the relevant data-protection authority.',
                ]],
                ['heading' => 'Cookies and analytics', 'paragraphs' => [
                    'The Cookie and Tracking Notice explains the cookies and similar technologies used on this website, including any that require consent. This site does not currently load third-party analytics or marketing tags.',
                ]],
                ['heading' => 'Changes to this notice', 'paragraphs' => [
                    'This notice may be updated as the website, services or legal requirements change. The last updated date at the top shows when the current version was published.',
                ]],
            ], [
                'This notice explains how BRATHAMATU handles personal information when you use this website, request a stock-taking quotation or ask about BRATHAMATU POS.',
                'It is a working notice prepared for review. The legal entity name, registered address, data-protection contact and retention periods are being confirmed and will be added before launch.',
            ]),
        ], [
            'title' => 'Privacy Notice',
            'description' => 'How BRATHAMATU handles personal information submitted through website enquiries, quotation requests and BRATHAMATU POS demonstration requests.',
            'priority' => 0.1,
            'noindex' => true,
        ], type: 'legal');

        $this->publishPage('terms', 'Terms of Service', [
            $this->legalDocument('Terms of Service', [
                ['heading' => 'Use of this website', 'paragraphs' => [
                    'You may use this website for lawful business enquiries and to learn about BRATHAMATU services and BRATHAMATU POS. You must not attempt to disrupt the site, gain unauthorised access, introduce malicious code, scrape content at scale, or use the site in a way that infringes the rights of others.',
                    'You are responsible for the accuracy of information you submit and for having authority to share it.',
                ]],
                ['heading' => 'Information on this website', 'paragraphs' => [
                    'Content is provided for general information. It is not a quotation, a statement of availability, or professional advice for a specific situation. Descriptions of services and features may change as the offering develops.',
                    'No price is displayed until approved pricing is configured. A quotation is prepared separately from the scope of the work.',
                ]],
                ['heading' => 'Quotations, scheduling and services', 'paragraphs' => [
                    'A quotation is based on the scope, locations and information available at the time. It may be revised if the scope changes or if the information provided is incomplete.',
                    'Dates and site arrangements are confirmed before a count. Stock-taking work is delivered under the quotation and any separate engagement terms agreed for the job.',
                    'Where independent verification or a recount is included, the scope of that check is stated in the quotation or engagement terms.',
                ]],
                ['heading' => 'BRATHAMATU POS', 'paragraphs' => [
                    'Information about BRATHAMATU POS on this website and in a demonstration is for evaluation. Access to and use of BRATHAMATU POS is governed by the separate agreement that applies to the customer account.',
                    'Features, integrations and workflows described may be updated. A demonstration does not create a right to a particular feature or configuration.',
                ]],
                ['heading' => 'Stock-taking results', 'paragraphs' => [
                    'A stock take records the physical stock observed within the agreed scope at the time of the count. Results depend on the information, access and site conditions available during the count.',
                    'Count outcomes are not a guarantee of future stock levels, sales or business performance. Discrepancies are recorded and can be investigated through a recount or agreed follow-up.',
                ]],
                ['heading' => 'Intellectual property', 'paragraphs' => [
                    'The BRATHAMATU and BRATHAMATU POS names, branding, website content and materials are protected by intellectual property law. You may view and share links to the site, but you may not copy, republish or commercially exploit content without written permission.',
                    'Information and records you provide remain yours. We may use them as needed to deliver the agreed services.',
                ]],
                ['heading' => 'Third-party links', 'paragraphs' => [
                    'This website may link to third-party sites. Those sites are not controlled by BRATHAMATU and their terms and privacy practices apply.',
                ]],
                ['heading' => 'Liability', 'paragraphs' => [
                    'Nothing in these terms excludes liability that cannot be excluded by law, including liability for fraud or for death or personal injury caused by negligence.',
                    'To the extent permitted by law, BRATHAMATU is not liable for indirect or consequential loss arising from use of this website. Liability for paid services is set out in the applicable engagement terms.',
                ]],
                ['heading' => 'Privacy and cookies', 'paragraphs' => [
                    'How personal information is handled is described in the Privacy Notice. The Cookie and Tracking Notice explains cookies and similar technologies.',
                ]],
                ['heading' => 'Changes to these terms', 'paragraphs' => [
                    'These terms may be updated as the website and services change. The last updated date at the top shows the current version. Continued use of the website after an update means the revised terms apply.',
                ]],
                ['heading' => 'Contact', 'paragraphs' => [
                    'Questions about these terms can be sent through the contact page. The legal entity name, registered address and governing law are being confirmed and will be published here before launch.',
                ]],
            ], [
                'These terms govern use of the BRATHAMATU website and enquiries made through it.',
                'They are a working draft prepared for review. The legal entity, governing law and dispute process are being confirmed and will be added before launch.',
            ]),
        ], [
            'title' => 'Terms of Service',
            'description' => 'Terms for using the BRATHAMATU website and making stock-taking or BRATHAMATU POS enquiries. Legal entity and governing law details are pending confirmation.',
            'priority' => 0.1,
            'noindex' => true,
        ], type: 'legal');

        $this->publishPage('cookies', 'Cookie and Tracking Notice', [
            $this->legalDocument('Cookie and Tracking Notice', [
                ['heading' => 'What cookies are', 'paragraphs' => [
                    'Cookies are small text files that a website stores in your browser. They can help a site work, remember choices or measure use. Similar technologies include local storage and pixels.',
                ]],
                ['heading' => 'Essential cookies', 'paragraphs' => [
                    'Some cookies are needed for the site to operate securely. This website may set a session cookie and a cross-site request forgery token so that pages load correctly and forms can be protected. These cookies are not used for advertising.',
                    'Essential cookies cannot be switched off through the website. You can block or delete them in your browser, but parts of the site may then stop working.',
                ]],
                ['heading' => 'Analytics and marketing', 'paragraphs' => [
                    'This site does not currently load third-party analytics or marketing tags. If optional analytics or marketing cookies are introduced, they will be listed here and will only be set after any consent required by applicable law has been obtained.',
                ]],
                ['heading' => 'Consent and preferences', 'paragraphs' => [
                    'Where a cookie requires consent, a preference control will be provided before it is set. You will be able to accept or reject optional cookies and change your choice later.',
                    'The cookie and marketing consent policy is being confirmed by the business. It will be reflected in this notice and the preference control before optional tracking is enabled.',
                ]],
                ['heading' => 'Third-party services', 'paragraphs' => [
                    'Hosting, security and other providers that support the website may process technical request data. Any cookies set by third-party services will be listed here once confirmed.',
                ]],
                ['heading' => 'Managing cookies', 'paragraphs' => [
                    'You can manage or delete cookies in your browser settings. Instructions vary by browser. Blocking all cookies may affect site features such as form protection and session handling.',
                ]],
                ['heading' => 'Changes to this notice', 'paragraphs' => [
                    'This notice may be updated when technologies or providers change. The last updated date shows the current version.',
                ]],
            ], [
                'This notice explains how cookies and similar technologies are used on the BRATHAMATU website.',
                'It is a working notice prepared for review and will be updated when optional analytics, marketing tags or a preference centre are enabled.',
            ]),
        ], [
            'title' => 'Cookie and Tracking Notice',
            'description' => 'How the BRATHAMATU website uses cookies and similar technologies, including essential cookies and the approach to optional analytics and marketing.',
            'priority' => 0.1,
            'noindex' => true,
        ], type: 'legal');
    }

    private function landingPages(): void
    {
        $this->stocktakingLandingPages();
        $this->posLandingPages();
    }

    private function stocktakingLandingPages(): void
    {
        $this->publishPage('stock-taking/on-demand', 'On-Demand Stock Taking', [
            $this->hero(
                'Stock-taking service',
                'Stock taking when you need it, not only on a fixed schedule',
                'A one-off physical count for a stock decision, reporting requirement or discrepancy that cannot wait for the next scheduled count.',
                [
                    'Count one location or several agreed stock areas',
                    'Full catalogue or selected products and categories',
                    'Independent verification and recounts available',
                ],
                ['label' => 'Request a stock-taking quote', 'url' => '/request-quote'],
                ['label' => 'Compare stock-taking options', 'url' => '/stock-taking'],
                'Common triggers',
                ['A discrepancy needs checking', 'A reporting or year-end deadline', 'A handover, move or system change', 'A lender or insurer requests a count'],
            ),
            $this->richText('When an on-demand count fits', [
                'On-demand stock taking is a single engagement. You choose when the count happens, and the scope is agreed for that count rather than a fixed annual calendar.',
                'It can cover the whole catalogue or focus on the products, categories or locations where accuracy matters most. If a recurring schedule is not appropriate yet, a one-off count gives you a verified starting point.',
                'The service works with BRATHAMATU POS, other POS or ERP systems, and manual stock records. The count does not require a particular system.',
            ], [
                'You need a verified quantity for a specific decision',
                'You want to test a count scope before committing to a schedule',
                'A discrepancy needs an independent check',
            ]),
            $this->serviceGrid('What an on-demand count can cover', 'The scope is agreed before the count so expectations are clear.', [
                ['Locations', 'Count a single shop, selected branches or several stock areas.'],
                ['Catalogue scope', 'Include the full catalogue or focus on chosen products and categories.'],
                ['Verification', 'Add an independent check where the reported quantity needs confirmation.'],
                ['Recounts', 'Investigate specific discrepancies with a focused recount.'],
            ]),
            $this->process('How an on-demand count runs', 'A single count still follows a defined sequence.', [
                ['Share the requirement', 'Explain what prompted the count, where the stock is held and when you need it.'],
                ['Agree the scope', 'Confirm the catalogue, locations, count method and verification requirements.'],
                ['Receive a quotation', 'The quotation reflects the agreed scope and access arrangements.'],
                ['Count and report', 'The team counts the agreed stock, records exceptions and reviews the outcome with you.'],
            ]),
            $this->faq('On-demand stock-taking questions', [
                ['Do we have to commit to a recurring schedule?', 'No. An on-demand count is a single engagement. A recurring schedule can be discussed later if your reporting needs become more regular.'],
                ['Can a recurring customer request an extra count?', 'Yes. An existing recurring arrangement does not prevent an additional on-demand count when a specific stock question needs answering.'],
                ['Can the count cover several branches?', 'Yes. Multiple locations can be included when access, timing and the per-branch scope are agreed in advance.'],
                ['What happens if we have no reliable stock records?', 'A physical count can still be planned. Existing records help with preparation, but an on-demand count can establish a verified starting position.'],
            ]),
            $this->cta(
                'Need a count outside your normal schedule?',
                'Describe the stock, locations and timing so the scope and quotation can be prepared.',
                ['label' => 'Request a stock-taking quote', 'url' => '/request-quote'],
                ['label' => 'Contact the team', 'url' => '/contact'],
            ),
        ], [
            'title' => 'On-demand stock taking',
            'description' => 'Request a one-off physical stock count for a discrepancy, reporting deadline, handover or system change. Scope your locations, catalogue and verification needs.',
            'priority' => 0.7,
        ], type: 'landing');

        $this->publishPage('stock-taking/monthly', 'Monthly Stock Taking', [
            $this->hero(
                'Stock-taking service',
                'Monthly stock taking that keeps records close to reality',
                'A regular physical count for businesses whose stock changes often enough that an annual count alone is not enough.',
                [
                    'A recurring monthly counting rhythm',
                    'Scope adjusted to the products and locations that matter',
                    'Verification and recounts where differences appear',
                ],
                ['label' => 'Request a monthly count quote', 'url' => '/request-quote'],
                ['label' => 'See how the service works', 'url' => '/how-it-works'],
                'Monthly count focus',
                ['Fast-moving products', 'High-value categories', 'Stock discrepancies', 'Branch or location comparisons'],
            ),
            $this->richText('Why count every month', [
                'When stock moves quickly, an annual count can leave a long gap between the physical position and the records used to make purchasing, sales and reporting decisions.',
                'A monthly count creates a more frequent checkpoint. The scope can cover the whole catalogue or concentrate on the categories, locations or value bands where accuracy has the greatest effect.',
                'Monthly customers can still request additional on-demand counts when a specific discrepancy or event needs a separate check.',
            ], [
                'Stock changes significantly between annual counts',
                'Discrepancies need to be identified earlier',
                'Monthly reporting depends on a recent physical position',
            ]),
            $this->serviceGrid('What a monthly count can include', 'The recurring scope is agreed so each count is consistent and comparable.', [
                ['Recurring scope', 'Agree the products, categories and locations counted each month.'],
                ['Exception capture', 'Record unknown items, damaged stock and quantity differences for review.'],
                ['Record comparison', 'Compare physical quantities with the records available from your systems.'],
                ['Review and follow-up', 'Discuss recurring differences and agree whether a recount or deeper review is needed.'],
            ]),
            $this->process('How monthly stock taking is set up', 'A repeatable rhythm with a defined scope.', [
                ['Set the baseline', 'Agree the catalogue, locations and records available for comparison.'],
                ['Confirm the monthly scope', 'Decide what is counted every month and what is reviewed less often.'],
                ['Run the monthly counts', 'The team counts the agreed stock and records exceptions.'],
                ['Review the results', 'Compare outcomes across months and agree any scope adjustments.'],
            ]),
            $this->faq('Monthly stock-taking questions', [
                ['Does every product have to be counted every month?', 'Not necessarily. The monthly scope can cover the full catalogue or focus on selected products, categories and locations. The choice is agreed before the schedule starts.'],
                ['Can the scope change after the first month?', 'Yes, when the change is agreed in advance so the schedule and quotation can be updated.'],
                ['Can we book an extra count between monthly visits?', 'Yes. Monthly customers can request an additional on-demand count when a specific issue needs attention.'],
                ['Do we need BRATHAMATU POS for monthly counting?', 'No. The service can work with BRATHAMATU POS, another system or manual records.'],
            ]),
            $this->cta(
                'Build a monthly counting rhythm.',
                'Share your locations, catalogue size and the stock areas that need regular attention.',
                ['label' => 'Request a stock-taking quote', 'url' => '/request-quote'],
                ['label' => 'Contact the team', 'url' => '/contact'],
            ),
        ], [
            'title' => 'Monthly stock taking services',
            'description' => 'Monthly physical stock counts for fast-moving and high-value inventory. Agree a repeatable scope, compare results over time and add on-demand counts when needed.',
            'priority' => 0.7,
        ], type: 'landing');

        $this->publishPage('stock-taking/annual', 'Annual Stock Taking', [
            $this->hero(
                'Stock-taking service',
                'Annual stock taking for a clear year-end position',
                'A complete yearly count that supports reporting, audit preparation and a structured review of inventory accuracy.',
                [
                    'A count planned around your reporting calendar',
                    'Full catalogue and agreed location coverage',
                    'Verification and discrepancy review available',
                ],
                ['label' => 'Request an annual count quote', 'url' => '/request-quote'],
                ['label' => 'Explore stock-taking services', 'url' => '/stock-taking'],
                'Annual count planning',
                ['Year-end reporting', 'Audit preparation', 'Board or lender reporting', 'A full inventory baseline'],
            ),
            $this->richText('What an annual count is for', [
                'An annual stock take provides a physical position at a defined point in the reporting year. It is usually broader than a targeted count because the aim is to cover the agreed catalogue and locations comprehensively.',
                'The result can support year-end reporting and audit preparation. It is a stock-taking service, not a statutory audit, and the count is planned around the records and access arrangements you provide.',
                'Where the business already counts monthly or on a cycle, the annual count can still provide a common year-end reference across the full agreed scope.',
            ], [
                'A complete year-end quantity is required',
                'Auditors or lenders have asked for a physical count',
                'The business needs a full inventory baseline',
            ]),
            $this->serviceGrid('What the annual count covers', 'The scope is set around the reporting requirement.', [
                ['Catalogue coverage', 'Count the agreed products, variants and units across the full scope.'],
                ['Location coverage', 'Include shops, warehouses, back rooms and other agreed stock areas.'],
                ['Verification', 'Add an independent check or recount where quantities need confirmation.'],
                ['Discrepancy review', 'Record differences and agree the follow-up required before reporting.'],
            ]),
            $this->process('How the annual count is planned', 'Preparation matters when the scope is broad.', [
                ['Confirm the reporting date', 'Agree the date or period the physical position needs to represent.'],
                ['Define the catalogue and locations', 'Set the product, unit and location scope, including access constraints.'],
                ['Plan stock movements', 'Agree how sales, receipts and transfers will be handled around the count.'],
                ['Count, verify and report', 'Count the agreed stock, review differences and provide the outcome.'],
            ]),
            $this->faq('Annual stock-taking questions', [
                ['Is an annual stock take the same as an audit?', 'No. BRATHAMATU provides a physical stock-taking service. The count can support audit preparation, but it is not a statutory audit or an audit opinion.'],
                ['When should the annual count take place?', 'The timing depends on your reporting year, operational calendar and access. The date is agreed as part of the scope.'],
                ['Can the annual count cover multiple branches?', 'Yes. Multi-branch coverage can be included, with access and timing agreed for each location.'],
                ['Can we combine annual and monthly counting?', 'Yes. A monthly rhythm can run through the year, with a broader annual count providing the full year-end reference.'],
            ]),
            $this->cta(
                'Plan your annual stock take.',
                'Tell us the reporting date, locations and catalogue so the scope can be prepared.',
                ['label' => 'Request a stock-taking quote', 'url' => '/request-quote'],
                ['label' => 'See how it works', 'url' => '/how-it-works'],
            ),
        ], [
            'title' => 'Annual stock taking and year-end counts',
            'description' => 'Plan a comprehensive annual physical stock count for year-end reporting and audit preparation, with verification, multi-location coverage and discrepancy review.',
            'priority' => 0.7,
        ], type: 'landing');

        $this->publishPage('stock-taking/recurring', 'Recurring Stock Taking', [
            $this->hero(
                'Stock-taking service',
                'Recurring stock taking on a schedule that fits your operation',
                'A custom counting rhythm for businesses that need more than an annual count but not necessarily a monthly one.',
                [
                    'Weekly, quarterly or another agreed cadence',
                    'Scope matched to how quickly your stock changes',
                    'Additional on-demand counts when needed',
                ],
                ['label' => 'Request a recurring count quote', 'url' => '/request-quote'],
                ['label' => 'Compare stock-taking options', 'url' => '/stock-taking'],
                'Schedule examples',
                ['Weekly high-value checks', 'Quarterly full counts', 'Seasonal stock reviews', 'Mixed frequency by category'],
            ),
            $this->richText('A schedule built around your stock', [
                'Recurring stock taking is not limited to a standard monthly or annual package. The frequency can be set around the rate at which stock changes, the reporting cycle and the locations involved.',
                'Different categories can follow different rhythms. High-value or fast-moving products might be counted more often, while slower stock follows a broader schedule.',
                'A recurring arrangement does not restrict additional on-demand counts. If a discrepancy appears between scheduled visits, a separate count can be requested.',
            ], [
                'Stock changes at a pace that does not suit monthly counts',
                'Different categories need different counting frequencies',
                'You want a predictable counting rhythm without a fixed package',
            ]),
            $this->serviceGrid('What a recurring schedule defines', 'The schedule is documented before counting begins.', [
                ['Frequency', 'Agree the counting interval for each scope or category.'],
                ['Coverage', 'Define the products, locations and stock areas included in each visit.'],
                ['Verification', 'Decide where independent checks or recounts are required.'],
                ['Review', 'Compare results across visits and adjust the schedule when the operation changes.'],
            ]),
            $this->process('How a recurring service starts', 'A defined rhythm rather than an open-ended arrangement.', [
                ['Map the operation', 'Review stock movement, locations, catalogue and reporting needs.'],
                ['Agree the cadence', 'Set the frequency and scope for each part of the count.'],
                ['Run the schedule', 'Counts follow the agreed calendar and access arrangements.'],
                ['Review and refine', 'Use the results to confirm whether the frequency and scope remain appropriate.'],
            ]),
            $this->faq('Recurring stock-taking questions', [
                ['Can the frequency be changed later?', 'Yes, when the change is agreed in advance so the schedule and quotation can be adjusted.'],
                ['Is there a minimum commitment?', 'Commercial terms are confirmed in the quotation for the agreed schedule. The scope and cadence are discussed before any commitment.'],
                ['Can different categories be counted at different frequencies?', 'Yes. The schedule can combine more frequent checks for selected categories with broader counts at longer intervals.'],
                ['Can we still request an on-demand count?', 'Yes. A recurring schedule does not prevent additional on-demand counts when a specific issue needs investigating.'],
            ]),
            $this->cta(
                'Set a counting schedule that fits.',
                'Describe how often your stock changes and which locations need coverage.',
                ['label' => 'Request a stock-taking quote', 'url' => '/request-quote'],
                ['label' => 'Contact the team', 'url' => '/contact'],
            ),
        ], [
            'title' => 'Recurring stock taking schedules',
            'description' => 'Custom recurring stock-taking schedules for weekly, quarterly or category-specific counts, with agreed coverage and additional on-demand counts when needed.',
            'priority' => 0.7,
        ], type: 'landing');

        $this->publishPage('stock-taking/full', 'Full Stock Taking', [
            $this->hero(
                'Stock-taking service',
                'A complete physical count across your agreed stock',
                'A full stock take covering the catalogue and locations in scope, used when a comprehensive physical position is required.',
                [
                    'Full catalogue scope across agreed units and variants',
                    'All agreed stock locations included',
                    'Exception capture and verification available',
                ],
                ['label' => 'Request a full stock take quote', 'url' => '/request-quote'],
                ['label' => 'Explore stock-taking services', 'url' => '/stock-taking'],
                'A full count supports',
                ['Year-end or reporting', 'A new inventory baseline', 'System or process changes', 'A comprehensive accuracy review'],
            ),
            $this->richText('When a full count is appropriate', [
                'A full stock take is broader than a targeted or cycle count. It covers the agreed catalogue and stock locations rather than selected products or areas.',
                'It is often used at a reporting date, after a significant process change or when the business needs a complete baseline. Because the scope is wider, preparation and access planning matter.',
                'A full count does not require every item in the business to be identical in method. Products, units and locations can still be grouped in a practical way as long as the agreed scope is covered.',
            ], [
                'You need a complete physical position for the agreed scope',
                'The current records need a fresh baseline',
                'Several locations or stock areas must be covered together',
            ]),
            $this->serviceGrid('What a full stock take includes', 'The boundaries are agreed before counting starts.', [
                ['Catalogue coverage', 'Count the products, variants and units included in the agreed scope.'],
                ['Location coverage', 'Cover shops, warehouses, back rooms and other agreed stock areas.'],
                ['Exceptions', 'Record unknown, damaged or unlabelled items for review rather than leaving them out.'],
                ['Verification', 'Use independent checks or recounts where the result needs additional confirmation.'],
            ]),
            $this->process('How a full stock take is delivered', 'A wider scope needs a clear plan.', [
                ['Define the boundary', 'Agree exactly which catalogue, units and locations are included.'],
                ['Prepare the site', 'Confirm access, stock organisation and the records available for comparison.'],
                ['Count the scope', 'The team works through the agreed areas and records quantities and exceptions.'],
                ['Review the outcome', 'Differences, exceptions and any further work are reviewed with you.'],
            ]),
            $this->faq('Full stock-taking questions', [
                ['Does a full count mean every item in the business?', 'It means the full catalogue and locations agreed in the scope. Areas outside the agreed scope are documented so the boundary is clear.'],
                ['How long does a full count take?', 'Duration depends on catalogue size, locations, storage method and access. It is addressed during scope and scheduling.'],
                ['Can the business keep trading during the count?', 'Trading and stock movement arrangements are agreed in advance so the count method and timing suit the operation.'],
                ['Do we need complete product records first?', 'Complete records help preparation and comparison, but the count can still capture the physical stock and flag exceptions.'],
            ]),
            $this->cta(
                'Need a complete physical position?',
                'Share your catalogue size, locations and reporting date so the full scope can be planned.',
                ['label' => 'Request a stock-taking quote', 'url' => '/request-quote'],
                ['label' => 'Contact the team', 'url' => '/contact'],
            ),
        ], [
            'title' => 'Full physical stock taking',
            'description' => 'A full physical stock count across the agreed catalogue, units and locations, with exception capture, verification and a clear count boundary.',
            'priority' => 0.7,
        ], type: 'landing');

        $this->publishPage('stock-taking/cycle-counting', 'Cycle Counting', [
            $this->hero(
                'Stock-taking service',
                'Cycle counting for focused, ongoing stock control',
                'Count selected products, categories or locations on a regular basis instead of waiting for one large full count.',
                [
                    'Target the stock where accuracy matters most',
                    'Spread counting across the operating calendar',
                    'Use results to focus recounts and process improvements',
                ],
                ['label' => 'Request a cycle-counting quote', 'url' => '/request-quote'],
                ['label' => 'Compare stock-taking options', 'url' => '/stock-taking'],
                'Cycle counting can focus on',
                ['Fast-moving products', 'High-value categories', 'Problem locations', 'Items with recurring differences'],
            ),
            $this->richText('How cycle counting differs from a full count', [
                'Cycle counting divides the stock into a schedule of smaller counts. Instead of counting the entire catalogue at one time, the team counts selected products, categories or locations at agreed intervals.',
                'This can make counting easier to fit around trading and focus attention on the areas where record accuracy has the greatest effect. It does not automatically replace a full count when a complete physical position is required.',
                'The selection can be based on value, movement, previous discrepancies or a rotating schedule. The method is agreed before counting begins.',
            ], [
                'You want more frequent checking without counting everything at once',
                'Certain products or locations carry more risk',
                'A full count is still needed periodically for complete coverage',
            ]),
            $this->serviceGrid('What a cycle-counting scope can include', 'Selection and frequency are agreed up front.', [
                ['Selection', 'Choose products, categories, value bands or locations to count.'],
                ['Frequency', 'Set the counting interval for each selected group.'],
                ['Exception capture', 'Record unknown items, damage and quantity differences.'],
                ['Follow-up', 'Use recurring differences to decide where a recount or process review is needed.'],
            ]),
            $this->process('How cycle counting is set up', 'A repeatable method for selected stock.', [
                ['Select the scope', 'Agree which products, categories or locations will be counted.'],
                ['Set the rhythm', 'Decide how often each group is counted and how results are reviewed.'],
                ['Count and compare', 'Physical quantities are recorded and compared with the available records.'],
                ['Adjust the focus', 'Recurring differences can change the selection or frequency for the next cycle.'],
            ]),
            $this->faq('Cycle-counting questions', [
                ['Do we still need a full stock take?', 'That depends on your reporting and assurance requirements. Cycle counting covers selected areas; a full count may still be needed when a complete physical position is required.'],
                ['How do you choose which items to count?', 'Selection can be based on value, sales velocity, previous discrepancies, storage location or a rotating schedule agreed with your team.'],
                ['Can cycle counting cover locations instead of products?', 'Yes. The scope can focus on stock areas, branches or warehouses as well as product groups.'],
                ['How often should cycle counts happen?', 'The frequency depends on stock movement, risk and reporting needs. It is agreed as part of the scope.'],
            ]),
            $this->cta(
                'Focus counting where it matters most.',
                'Tell us which products or locations need closer attention and how often.',
                ['label' => 'Request a stock-taking quote', 'url' => '/request-quote'],
                ['label' => 'Contact the team', 'url' => '/contact'],
            ),
        ], [
            'title' => 'Cycle counting services',
            'description' => 'Cycle counting for selected products, categories or locations, with an agreed frequency, exception capture and follow-up on recurring stock differences.',
            'priority' => 0.7,
        ], type: 'landing');

        $this->publishPage('stock-taking/branch', 'Branch Stock Taking', [
            $this->hero(
                'Stock-taking service',
                'Branch stock taking across every location in scope',
                'A coordinated count for businesses operating multiple branches, with a clear scope and result for each location.',
                [
                    'Per-branch catalogue and stock-area scope',
                    'Scheduling across one or several locations',
                    'Branch-level and consolidated result review',
                ],
                ['label' => 'Request a branch count quote', 'url' => '/request-quote'],
                ['label' => 'Explore stock-taking services', 'url' => '/stock-taking'],
                'Multi-branch coverage',
                ['Retail branches', 'Regional stores', 'Mixed shop and back-room stock', 'Several locations on different dates'],
            ),
            $this->richText('Counting across more than one branch', [
                'Multi-branch stock taking needs more than repeating a single-branch count. Each location can have a different layout, catalogue mix, access window and set of records.',
                'The scope is agreed per branch so the count reflects what is actually held there. Results can be reviewed branch by branch and across the full scope, depending on the reporting you need.',
                'A multi-branch engagement can start with one location and expand later when the process and access arrangements are proven.',
            ], [
                'Each branch holds its own stock and records',
                'Different locations need different access times',
                'You need to compare branch results within one engagement',
            ]),
            $this->serviceGrid('What a branch count covers', 'Every location has a defined boundary.', [
                ['Branch scope', 'Agree the catalogue, stock areas and records for each location.'],
                ['Scheduling', 'Plan access and counting dates across the branches included.'],
                ['Exception capture', 'Record unknown, damaged or transferred stock for review.'],
                ['Result review', 'Review outcomes per branch and across the agreed scope.'],
            ]),
            $this->process('How a multi-branch count is planned', 'One engagement, location-specific plans.', [
                ['List the locations', 'Identify every branch, stock area and site contact in scope.'],
                ['Agree per-branch scope', 'Confirm the catalogue and access arrangements for each location.'],
                ['Schedule the counts', 'Plan dates that reflect how each branch operates.'],
                ['Count and consolidate', 'Count each location and review the results at branch and scope level.'],
            ]),
            $this->faq('Branch stock-taking questions', [
                ['Do all branches have to be counted on the same day?', 'No. Dates can differ between locations when that suits access and operations. The schedule is agreed in advance.'],
                ['Can we start with a single branch?', 'Yes. A count can begin with one location and additional branches can be added later when the scope is agreed.'],
                ['Can results be separated by branch?', 'Yes. The reporting can distinguish each location within the agreed engagement.'],
                ['How is stock moving between branches handled?', 'Transfers and shared stock are discussed during scope planning so the count boundary and comparison method are clear.'],
            ]),
            $this->cta(
                'Coordinate counts across your branches.',
                'Share the locations, stock areas and access requirements for each site.',
                ['label' => 'Request a stock-taking quote', 'url' => '/request-quote'],
                ['label' => 'Contact the team', 'url' => '/contact'],
            ),
        ], [
            'title' => 'Multi-branch stock taking',
            'description' => 'Coordinate physical stock counts across multiple branches with per-location scope, access planning and branch-level result review.',
            'priority' => 0.7,
        ], type: 'landing');

        $this->publishPage('stock-taking/warehouse', 'Warehouse Stock Taking', [
            $this->hero(
                'Stock-taking service',
                'Warehouse stock taking built around how goods move',
                'A physical count for warehouses and distribution operations where stock is held in defined locations and moves through receiving, storage and dispatch.',
                [
                    'Agreed warehouse zones and stock locations',
                    'Bulk, mixed-unit and pallet-based counting',
                    'Exception capture for unlabelled or misplaced stock',
                ],
                ['label' => 'Request a warehouse count quote', 'url' => '/request-quote'],
                ['label' => 'Explore stock-taking services', 'url' => '/stock-taking'],
                'Warehouse count focus',
                ['Storage locations', 'Receiving and dispatch stock', 'Bulk quantities', 'Transfer and quarantine areas'],
            ),
            $this->richText('Counting in a working warehouse', [
                'Warehouse stock is rarely static. Goods may be arriving, being picked, transferred or dispatched while the count is planned, so the method needs to account for movement.',
                'The scope is built around the warehouse layout and the stock locations that matter. Bulk items, mixed units and shared storage areas are documented so the count boundary is clear.',
                'Existing warehouse records, stock lists and location labels help preparation, but the physical stock remains the source for the count.',
            ], [
                'Stock is spread across defined storage locations',
                'Goods are received, stored and dispatched continuously',
                'Transfers or quarantine areas need separate treatment',
            ]),
            $this->serviceGrid('What a warehouse count can include', 'The method follows the storage layout.', [
                ['Stock locations', 'Cover agreed zones, aisles, bins or other designated areas.'],
                ['Bulk and mixed units', 'Record the units and quantities used for the agreed products.'],
                ['Movement handling', 'Agree how receiving, dispatch and transfers will be treated during the count.'],
                ['Verification', 'Add independent checks or recounts for selected areas or high-value stock.'],
            ]),
            $this->process('How a warehouse count is planned', 'Map the space before counting it.', [
                ['Map the stock areas', 'Identify the zones, locations and records included in the scope.'],
                ['Agree the method', 'Confirm units, movement handling, access and verification requirements.'],
                ['Count the locations', 'The team counts the agreed areas and records exceptions.'],
                ['Review and report', 'Differences and unresolved items are reviewed so follow-up is clear.'],
            ]),
            $this->faq('Warehouse stock-taking questions', [
                ['Can the count happen outside receiving or dispatch hours?', 'Timing is agreed around warehouse operations and access so the method suits the site.'],
                ['Can damaged or quarantine stock be included?', 'Yes. Agreed areas such as damage, returns or quarantine stock can be included or excluded with the boundary documented.'],
                ['Do we need a warehouse management system?', 'No specific system is required. Existing records can help with comparison, but the physical count can proceed without a WMS.'],
                ['Can the count cover only part of the warehouse?', 'Yes. Selected zones, locations or product groups can be scoped instead of the whole site.'],
            ]),
            $this->cta(
                'Plan a warehouse count.',
                'Share the site layout, stock locations and movement pattern so the method can be agreed.',
                ['label' => 'Request a stock-taking quote', 'url' => '/request-quote'],
                ['label' => 'Contact the team', 'url' => '/contact'],
            ),
        ], [
            'title' => 'Warehouse stock taking',
            'description' => 'Warehouse and distribution stock counts built around storage locations, bulk units, receiving and dispatch movement, exception capture and verification.',
            'priority' => 0.7,
        ], type: 'landing');

        $this->publishPage('stock-taking/independent-verification', 'Independent Verification', [
            $this->hero(
                'Stock-taking service',
                'Independent verification for quantities you need to confirm',
                'A separate physical check of reported stock quantities, used when an internal count or record needs an independent reference.',
                [
                    'A separate count of the agreed scope',
                    'Full or sample-based verification',
                    'Clear reporting of differences',
                ],
                ['label' => 'Request a verification quote', 'url' => '/request-quote'],
                ['label' => 'Explore stock-taking services', 'url' => '/stock-taking'],
                'Verification can support',
                ['Reported inventory checks', 'Board or lender requests', 'Audit preparation', 'Post-count confirmation'],
            ),
            $this->richText('What independent verification means', [
                'Independent verification is a separate physical check of quantities that have already been reported, counted internally or recorded in a system. The purpose is to provide an independent reference rather than rely on the same source twice.',
                'The scope can cover the full agreed catalogue or a sample selected for value, risk or reporting materiality. The method, selection and reporting are agreed before work begins.',
                'This is a stock-taking verification service. It is not a statutory audit, and it does not replace the professional judgement or opinion of an auditor.',
            ], [
                'A reported quantity needs an independent physical check',
                'An internal count needs a second reference point',
                'A lender, board or auditor has requested supporting verification',
            ]),
            $this->serviceGrid('How verification can be scoped', 'The assurance requirement shapes the method.', [
                ['Full verification', 'Count the complete agreed scope independently of the original count.'],
                ['Sample verification', 'Check a defined sample selected by value, category, location or risk.'],
                ['Comparison', 'Compare the independent count with the reported or recorded quantities.'],
                ['Reporting', 'Document the differences, exceptions and unresolved items found.'],
            ]),
            $this->process('How an independent check runs', 'Separation from the original count is the starting point.', [
                ['Agree the assertion', 'Confirm which quantities are being verified and the source being compared.'],
                ['Set the scope and method', 'Decide between full and sample verification, locations and units.'],
                ['Count independently', 'The team performs its own physical count of the agreed scope.'],
                ['Report differences', 'The outcome is compared with the reported position and differences are documented.'],
            ]),
            $this->faq('Independent verification questions', [
                ['How is the verification independent?', 'The verification count is planned and performed separately from the count or records being checked, using the agreed scope and method.'],
                ['Is this the same as an audit?', 'No. It is a physical stock verification service. It can support audit preparation, but it is not a statutory audit or audit opinion.'],
                ['Can you verify only a sample?', 'Yes. Sample verification can be agreed when a full independent count is not required by the reporting or assurance need.'],
                ['What do we receive after verification?', 'You receive the verification outcome, the comparison with the reported quantities and the differences or exceptions identified.'],
            ]),
            $this->cta(
                'Need an independent stock check?',
                'Explain what quantity is being verified and who needs the result.',
                ['label' => 'Request a stock-taking quote', 'url' => '/request-quote'],
                ['label' => 'Contact the team', 'url' => '/contact'],
            ),
        ], [
            'title' => 'Independent stock verification',
            'description' => 'Independent physical stock verification for reported quantities, internal counts, lender requests and audit preparation, with full or sample-based scope.',
            'priority' => 0.7,
        ], type: 'landing');

        $this->publishPage('stock-taking/recounts', 'Recounts and Discrepancy Investigation', [
            $this->hero(
                'Stock-taking service',
                'Recounts and discrepancy investigation when numbers do not agree',
                'A focused recount for products, locations or categories where the first count or the records show a difference that needs to be understood.',
                [
                    'Recount the specific items in question',
                    'Review where and how the difference appears',
                    'Document the outcome and remaining exceptions',
                ],
                ['label' => 'Request a recount quote', 'url' => '/request-quote'],
                ['label' => 'Explore stock-taking services', 'url' => '/stock-taking'],
                'A recount can address',
                ['A disputed quantity', 'A large value difference', 'A recurring category discrepancy', 'An unclear stock location'],
            ),
            $this->richText('What a recount is for', [
                'A recount is not simply a repeat of the whole process. It focuses on the products, locations or categories where a difference has appeared and needs a second physical reference.',
                'The team can review the original scope, the records used and the areas counted, then plan a focused recount. The aim is to confirm the physical quantity and document any difference that remains.',
                'Where the same discrepancy keeps returning, the review can also identify whether a clearer scope, better labels or a process change should be discussed.',
            ], [
                'The first count and the records do not agree',
                'A specific high-value or high-risk line needs checking',
                'A discrepancy keeps returning after earlier counts',
            ]),
            $this->serviceGrid('What a recount can cover', 'The investigation is targeted, not open-ended.', [
                ['Targeted lines', 'Recount specific products, variants or categories in question.'],
                ['Targeted locations', 'Check the storage areas or branches where the difference appears.'],
                ['Method review', 'Confirm the units, labels and boundaries used in the original count.'],
                ['Outcome report', 'Document the recount result, remaining differences and next steps.'],
            ]),
            $this->process('How a discrepancy review runs', 'Start with the difference, then narrow the check.', [
                ['Review the difference', 'Understand the original count, the comparison source and the size of the gap.'],
                ['Define the recount scope', 'Select the products, locations and records that need a second check.'],
                ['Perform the recount', 'Count the agreed scope again and record what is physically present.'],
                ['Agree next steps', 'Review the result and decide whether further investigation or a process change is needed.'],
            ]),
            $this->faq('Recount and discrepancy questions', [
                ['Can you recount only the items that differ?', 'Yes. A recount can focus on the specific products, categories or locations in question instead of repeating the full count.'],
                ['What happens if the recount still differs?', 'The remaining difference is documented, and the next step can be a further review of records, labels, movement or the count boundary.'],
                ['How quickly can a recount be arranged?', 'Timing depends on the scope, location and team availability. Share the difference and preferred timing so the options can be confirmed.'],
                ['Can we observe the recount?', 'Access and observation arrangements are agreed in advance so they do not compromise the independence of the check.'],
            ]),
            $this->cta(
                'Investigate a stock difference.',
                'Tell us which products or locations do not agree and what has already been checked.',
                ['label' => 'Request a stock-taking quote', 'url' => '/request-quote'],
                ['label' => 'Contact the team', 'url' => '/contact'],
            ),
        ], [
            'title' => 'Recounts and discrepancy investigation',
            'description' => 'Focused stock recounts and discrepancy investigation for specific products, categories or locations, with documented outcomes and next-step review.',
            'priority' => 0.7,
        ], type: 'landing');
    }

    private function posLandingPages(): void
    {
        $this->publishPage('brathamatu-pos/retail', 'Retail POS', [
            $this->hero(
                'BRATHAMATU POS',
                'Retail POS for sales, stock and everyday control',
                'Sell at the counter, keep product records organised and see the stock movements that sit between one physical count and the next.',
                [
                    'Barcode-based sales and receipts',
                    'Product catalogue with variants and units',
                    'Stock movements, purchases and stock-count requests',
                ],
                ['label' => 'Book a retail POS demo', 'url' => '/book-demo'],
                ['label' => 'Explore BRATHAMATU POS', 'url' => '/brathamatu-pos'],
                'Retail workflows',
                ['Counter sales', 'Product and barcode setup', 'Purchases and suppliers', 'Returns and stock movements'],
            ),
            $this->richText('Where retail POS fits', [
                'A retail operation needs sales and stock records to reflect the same products. BRATHAMATU POS brings terminal sales, product setup and inventory workflows into one operating system.',
                'The catalogue can represent the way products are actually sold, including variants and units. Sales, receipts, purchases and returns then contribute to the stock position used between counts.',
                'Retailers can use the POS on its own or combine it with BRATHAMATU Stocktake. Booking a stock take is optional, and a stock take can still be arranged without the POS.',
            ], [
                'You sell through a counter or terminal',
                'Products need a consistent catalogue and barcode setup',
                'Sales and stock records need to stay connected',
            ]),
            $this->posFeatures('Retail workflows in BRATHAMATU POS', 'The core controls for a retail operation.', [
                ['Sales and receipts', 'Process sales and issue receipts from the terminal.'],
                ['Product catalogue', 'Organise products, variants, units and barcodes for selling and counting.'],
                ['Inventory movements', 'Record sales, purchases, returns and transfers against stock.'],
                ['Purchases and suppliers', 'Capture incoming stock and maintain supplier records.'],
                ['Returns', 'Record returned goods without losing the link to the original stock movement.'],
                ['Stock-taking requests', 'Request a physical count from the POS workflow when one is needed.', '/brathamatu-pos/stocktaking-integration'],
            ]),
            $this->process('From retail demo to daily use', 'A practical setup path.', [
                ['See the retail workflows', 'Review sales, receipts, catalogue and stock processes relevant to your shop.'],
                ['Prepare the catalogue', 'Agree products, variants, units, barcodes, users and branch structure.'],
                ['Configure and go live', 'Set up the terminal and branch workflows you need.'],
                ['Count and reconcile', 'Use stock movement records alongside physical counts when accuracy needs checking.'],
            ]),
            $this->faq('Retail POS questions', [
                ['Is BRATHAMATU POS suitable for a single shop?', 'Yes. It supports single-location retailers as well as businesses operating several branches.'],
                ['Do we need to book a stock take to use the POS?', 'No. The POS can be used without a stock-taking engagement, and a stock take can be arranged separately.'],
                ['Does the POS support returns?', 'Yes. Returns are part of the sales and stock movement workflow.'],
                ['Can we see the POS before deciding?', 'Yes. Request a demonstration focused on your retail catalogue, terminals and workflows.'],
            ]),
            $this->cta(
                'See retail POS with your own products.',
                'Request a demonstration of the sales, catalogue and stock workflows that matter to your shop.',
                ['label' => 'Book a BRATHAMATU POS demo', 'url' => '/book-demo'],
                ['label' => 'Request a stock-taking quote', 'url' => '/request-quote'],
            ),
        ], [
            'title' => 'Retail POS software',
            'description' => 'BRATHAMATU POS for retail sales, receipts, product catalogues, barcodes, purchases, returns and stock movements, with optional stock-taking integration.',
            'priority' => 0.7,
        ], type: 'landing');

        $this->publishPage('brathamatu-pos/supermarket', 'Supermarket POS', [
            $this->hero(
                'BRATHAMATU POS',
                'Supermarket POS for high-volume selling and stock visibility',
                'Run busy checkout sales while keeping the product catalogue and stock movements connected to the wider inventory operation.',
                [
                    'Fast sales and receipt workflows',
                    'Barcode-oriented product catalogue',
                    'Branch stock and stock-taking service requests',
                ],
                ['label' => 'Book a supermarket POS demo', 'url' => '/book-demo'],
                ['label' => 'Explore BRATHAMATU POS', 'url' => '/brathamatu-pos'],
                'Supermarket focus',
                ['High-volume sales', 'Barcoded products', 'Supplier and purchase records', 'Stock counts by category'],
            ),
            $this->richText('Built for a busy selling floor', [
                'Supermarket operations combine a large catalogue, frequent sales and a stock position that changes throughout the day. The POS needs to keep checkout work moving while preserving the product and stock records behind it.',
                'BRATHAMATU POS supports barcode-based selling, receipts, product setup, purchases and inventory movements across the business. That gives the team a consistent catalogue to sell from and count against.',
                'Where physical stock needs checking, a stock-taking service can be requested from the POS workflow or arranged separately. The POS does not require a stock-taking schedule.',
            ], [
                'A large catalogue is sold through barcodes',
                'Stock moves quickly across categories and suppliers',
                'Sales records and physical counts need a common catalogue',
            ]),
            $this->posFeatures('Supermarket POS workflows', 'The operational foundation for a high-volume store.', [
                ['Checkout sales', 'Process sales and issue receipts from configured terminals.'],
                ['Barcode selling', 'Use product barcodes to keep checkout selection tied to the catalogue.'],
                ['Catalogue management', 'Organise a broad product range, variants and units.'],
                ['Purchases and suppliers', 'Record incoming stock and maintain supplier information.'],
                ['Branch stock', 'Keep stock movements visible across the store or branch structure.'],
                ['Stock-taking integration', 'Request a count from the POS when stock accuracy needs checking.', '/brathamatu-pos/stocktaking-integration'],
            ]),
            $this->process('Preparing a supermarket demonstration', 'Focus the walkthrough on the scale of your operation.', [
                ['Share the store context', 'Describe branches, terminals, product range and the workflows you want to review.'],
                ['Review the catalogue model', 'Discuss how products, variants, units and barcodes are structured.'],
                ['Walk through selling and stock', 'See sales, receipts, purchases and stock movements in sequence.'],
                ['Agree setup needs', 'Confirm the catalogue, users, terminals and branch configuration for a rollout.'],
            ]),
            $this->faq('Supermarket POS questions', [
                ['Can the POS support multiple terminals?', 'Terminals and users are configured as part of the branch setup. The demonstration can cover the structure you need.'],
                ['Does it support barcoded products?', 'Yes. Barcodes can be used in the product catalogue and sales workflow.'],
                ['Can we request stock takes from the POS?', 'Yes. Stock-taking services can be requested from the POS workflow when the integration is in use.'],
                ['Is the POS limited to supermarkets?', 'No. The platform also supports retail, pharmacy, wholesale, hardware, restaurant and multi-branch operations.'],
            ]),
            $this->cta(
                'Review supermarket POS fit.',
                'Request a demonstration shaped around your catalogue, terminals and stock operation.',
                ['label' => 'Book a BRATHAMATU POS demo', 'url' => '/book-demo'],
                ['label' => 'Request a stock-taking quote', 'url' => '/request-quote'],
            ),
        ], [
            'title' => 'Supermarket POS software',
            'description' => 'BRATHAMATU POS for supermarket checkout sales, barcoded catalogues, receipts, purchases, branch stock and optional physical stock-count requests.',
            'priority' => 0.7,
        ], type: 'landing');

        $this->publishPage('brathamatu-pos/pharmacy', 'Pharmacy POS', [
            $this->hero(
                'BRATHAMATU POS',
                'Pharmacy POS for product and inventory control',
                'Sell and track pharmacy products with a catalogue that supports batch and expiry details, connected to inventory movements and physical counts.',
                [
                    'Batch and expiry-aware product tracking',
                    'Sales, receipts and inventory movements',
                    'Stock-taking requests for regulated inventory',
                ],
                ['label' => 'Book a pharmacy POS demo', 'url' => '/book-demo'],
                ['label' => 'Explore BRATHAMATU POS', 'url' => '/brathamatu-pos'],
                'Pharmacy workflows',
                ['Batch-tracked products', 'Expiry-sensitive stock', 'Purchases and suppliers', 'Physical stock verification'],
            ),
            $this->richText('Where pharmacy POS fits', [
                'Pharmacy stock needs more than a product name and quantity. Batches and expiry dates can change what is available to sell and what needs attention during a physical count.',
                'BRATHAMATU POS supports batch and expiry tracking in the product and inventory model. Sales, purchases, returns and adjustments contribute to the stock position, while physical counts can check what is actually on the shelf.',
                'The stock-taking service can include batch or expiry detail in the count scope. The exact recording method is agreed before the count begins.',
            ], [
                'Products are tracked by batch or expiry date',
                'Stock accuracy affects both selling and reporting',
                'Physical counts need to reflect the same product detail as the POS',
            ]),
            $this->posFeatures('Pharmacy POS capabilities', 'Product detail linked to sales and stock.', [
                ['Batch tracking', 'Maintain batch information for products that require it.'],
                ['Expiry dates', 'Record expiry details as part of the product and inventory records.'],
                ['Sales and receipts', 'Sell from the configured catalogue and issue receipts.'],
                ['Inventory movements', 'Record receipts, sales, returns, transfers and adjustments.'],
                ['Purchases and suppliers', 'Capture incoming stock and supplier records.'],
                ['Stock-taking requests', 'Request a physical count that can include batch or expiry scope.', '/brathamatu-pos/stocktaking-integration'],
            ]),
            $this->process('Evaluating pharmacy POS fit', 'The demonstration focuses on product detail and stock control.', [
                ['Describe the product model', 'Share how products, batches and expiry dates are managed today.'],
                ['Review the workflows', 'Walk through sales, receipts, purchases and stock movements.'],
                ['Discuss the count scope', 'Agree whether batch or expiry detail needs to be captured in physical counts.'],
                ['Plan the setup', 'Confirm catalogue, branch, user and terminal requirements before rollout.'],
            ]),
            $this->faq('Pharmacy POS questions', [
                ['Does BRATHAMATU POS track batches and expiry dates?', 'Yes. Batch and expiry tracking are part of the inventory model and can be used for products that require them.'],
                ['Can a stock take include batch or expiry detail?', 'Yes. The count scope can include batch or expiry information when that detail matters to the business.'],
                ['Can the POS be used without a stock take?', 'Yes. The POS and the stock-taking service are separate and can be used independently.'],
                ['Is the POS a regulatory compliance system?', 'The POS supports sales and inventory operations. Regulatory obligations remain with the business and its professional advisers.'],
            ]),
            $this->cta(
                'Review pharmacy POS workflows.',
                'Request a demonstration covering batches, expiry dates, sales and stock control.',
                ['label' => 'Book a BRATHAMATU POS demo', 'url' => '/book-demo'],
                ['label' => 'Request a stock-taking quote', 'url' => '/request-quote'],
            ),
        ], [
            'title' => 'Pharmacy POS software',
            'description' => 'BRATHAMATU POS for pharmacy sales, receipts, batch and expiry tracking, inventory movements and stock-taking requests for regulated products.',
            'priority' => 0.7,
        ], type: 'landing');

        $this->publishPage('brathamatu-pos/wholesale', 'Wholesale POS', [
            $this->hero(
                'BRATHAMATU POS',
                'Wholesale POS for bulk sales and distribution workflows',
                'Manage trade sales, product records and incoming stock while keeping the movement of goods across storage and branches visible.',
                [
                    'Bulk sales and receipt workflows',
                    'Customer, supplier and purchase records',
                    'Stock locations, transfers and physical counts',
                ],
                ['label' => 'Book a wholesale POS demo', 'url' => '/book-demo'],
                ['label' => 'Explore BRATHAMATU POS', 'url' => '/brathamatu-pos'],
                'Wholesale focus',
                ['Trade customer sales', 'Bulk quantities', 'Receiving and dispatch', 'Stock transfers'],
            ),
            $this->richText('Where wholesale POS fits', [
                'Wholesale operations sell in larger quantities and move stock between suppliers, storage areas and customers. The records need to follow those movements rather than only record the final sale.',
                'BRATHAMATU POS supports sales, receipts, customer and supplier records, purchases, stock transfers and inventory movements across branches or storage locations.',
                'Physical counts can be arranged when the recorded position needs checking. The count scope can focus on bulk storage, selected product groups or the full warehouse operation.',
            ], [
                'Sales are made in bulk or trade quantities',
                'Goods move between storage areas and branches',
                'Purchase and sales records need to meet in one stock position',
            ]),
            $this->posFeatures('Wholesale POS workflows', 'Controls for sales and stock movement.', [
                ['Bulk sales', 'Process sales in the quantities used for your trade customers.'],
                ['Customer records', 'Maintain customer information alongside sales.'],
                ['Purchases and suppliers', 'Record incoming stock and supplier details.'],
                ['Stock transfers', 'Move stock between branches or storage locations.'],
                ['Inventory movements', 'Track receipts, sales, returns, transfers and adjustments.'],
                ['Stock-taking integration', 'Request a count for warehouse or selected stock when needed.', '/brathamatu-pos/stocktaking-integration'],
            ]),
            $this->process('Assessing wholesale POS fit', 'Focus on how goods and records move.', [
                ['Describe the operation', 'Share the customer types, product range and storage structure.'],
                ['Review movement workflows', 'See purchases, receiving, transfers, sales and returns.'],
                ['Agree the branch model', 'Confirm how warehouses, branches and stock locations should be represented.'],
                ['Plan the rollout', 'Set the catalogue, users and terminal requirements.'],
            ]),
            $this->faq('Wholesale POS questions', [
                ['Can the POS handle bulk quantities?', 'Yes. Sales and stock records use the units and quantities configured for your products.'],
                ['Can stock be transferred between locations?', 'Yes. Stock transfers are part of the inventory movement workflow.'],
                ['Can we count one warehouse at a time?', 'Yes. A stock take can be scoped to selected locations or product groups.'],
                ['Do we need to use BRATHAMATU Stocktake?', 'No. The POS works independently, and stock-taking services can be added when physical verification is needed.'],
            ]),
            $this->cta(
                'See wholesale workflows in action.',
                'Request a demonstration covering bulk sales, purchases, transfers and stock control.',
                ['label' => 'Book a BRATHAMATU POS demo', 'url' => '/book-demo'],
                ['label' => 'Request a stock-taking quote', 'url' => '/request-quote'],
            ),
        ], [
            'title' => 'Wholesale POS software',
            'description' => 'BRATHAMATU POS for wholesale sales, customer and supplier records, bulk stock, transfers and physical stock counts across warehouse locations.',
            'priority' => 0.7,
        ], type: 'landing');

        $this->publishPage('brathamatu-pos/hardware', 'Hardware POS', [
            $this->hero(
                'BRATHAMATU POS',
                'Hardware POS for mixed units, bulk items and stock control',
                'Sell products that come in different units and quantities while keeping the catalogue and stock movements aligned.',
                [
                    'Products with variants and mixed units',
                    'Barcode and counter sales workflows',
                    'Purchases, stock movements and physical counts',
                ],
                ['label' => 'Book a hardware POS demo', 'url' => '/book-demo'],
                ['label' => 'Explore BRATHAMATU POS', 'url' => '/brathamatu-pos'],
                'Hardware stock',
                ['Products sold by unit', 'Bulk and loose quantities', 'Variants and pack sizes', 'Supplier and purchase records'],
            ),
            $this->richText('Where hardware POS fits', [
                'Hardware stock often mixes items sold singly, in packs or in bulk quantities. The product model has to reflect those differences so sales and physical counts use the same units.',
                'BRATHAMATU POS supports products, variants, units and barcodes, along with purchases, sales and inventory movements. That gives the business a consistent catalogue for the counter and the stock room.',
                'When stock needs checking, a physical count can be scoped around the product groups and storage areas that matter. The count can be arranged independently of the POS rollout.',
            ], [
                'Products are sold in different units or pack sizes',
                'The catalogue mixes small items and bulk stock',
                'Counter records and stock-room counts need common units',
            ]),
            $this->posFeatures('Hardware POS capabilities', 'The catalogue and movement tools behind the counter.', [
                ['Variants and units', 'Represent products in the units used for buying and selling.'],
                ['Barcode sales', 'Use barcodes where products carry them.'],
                ['Counter sales and receipts', 'Process sales and issue receipts.'],
                ['Purchases and suppliers', 'Record incoming stock and supplier details.'],
                ['Inventory movements', 'Track sales, purchases, returns, transfers and adjustments.'],
                ['Stock-taking integration', 'Request a count for selected product groups or locations.', '/brathamatu-pos/stocktaking-integration'],
            ]),
            $this->process('Preparing a hardware demonstration', 'Focus on how your products are bought and sold.', [
                ['Describe the product range', 'Share the units, variants and pack structures used in the business.'],
                ['Review sales and purchasing', 'See how counter sales and incoming stock connect to the catalogue.'],
                ['Discuss stock locations', 'Agree how the shop, store room and other areas should be represented.'],
                ['Plan the setup', 'Confirm the catalogue and terminal requirements for rollout.'],
            ]),
            $this->faq('Hardware POS questions', [
                ['Can products be sold in different units?', 'The catalogue supports variants and units. The exact structure is agreed during setup so buying and selling quantities match.'],
                ['Do all products need a barcode?', 'Barcodes are used where products have them. The catalogue model can be discussed around your actual product range.'],
                ['Can one business use several stock locations?', 'Yes. Branches and stock locations can be represented in the inventory workflow.'],
                ['Can stock takes focus on one category?', 'Yes. A count can be scoped to selected categories, products or storage areas.'],
            ]),
            $this->cta(
                'Review hardware POS fit.',
                'Request a demonstration focused on units, variants, purchases and stock control.',
                ['label' => 'Book a BRATHAMATU POS demo', 'url' => '/book-demo'],
                ['label' => 'Request a stock-taking quote', 'url' => '/request-quote'],
            ),
        ], [
            'title' => 'Hardware POS software',
            'description' => 'BRATHAMATU POS for hardware retail with product variants, mixed units, bulk stock, barcode sales, purchases and physical stock-count requests.',
            'priority' => 0.7,
        ], type: 'landing');

        $this->publishPage('brathamatu-pos/restaurant', 'Restaurant POS', [
            $this->hero(
                'BRATHAMATU POS',
                'Restaurant POS for fast sales and consumable stock',
                'Keep service moving at the counter while recording the sales and stock movements behind food, beverage and consumable inventory.',
                [
                    'Quick sales and receipt workflows',
                    'Product catalogue for menu and consumables',
                    'Purchases, stock movements and physical counts',
                ],
                ['label' => 'Book a restaurant POS demo', 'url' => '/book-demo'],
                ['label' => 'Explore BRATHAMATU POS', 'url' => '/brathamatu-pos'],
                'Restaurant workflows',
                ['Counter and service sales', 'Menu product setup', 'Supplier purchases', 'Consumable stock checks'],
            ),
            $this->richText('Where restaurant POS fits', [
                'Restaurant operations depend on fast service and a clear record of what was sold. The stock side is equally practical: food, beverage and consumable items need purchases and usage recorded consistently.',
                'BRATHAMATU POS supports sales, receipts, a product catalogue, purchases, suppliers and inventory movements. Products and consumables can be set up in the structure that matches the operation.',
                'Physical counts can check the stock you actually hold. The scope can focus on beverages, dry goods, consumables or the full stock area, depending on what needs verification.',
            ], [
                'Service speed matters at the counter',
                'Consumable stock needs a practical purchase and movement record',
                'Sales and stock records should share one product catalogue',
            ]),
            $this->posFeatures('Restaurant POS workflows', 'Sales discipline linked to stock records.', [
                ['Fast sales', 'Process service sales and issue receipts from the terminal.'],
                ['Product catalogue', 'Organise menu items, beverages and consumables for sale and stock records.'],
                ['Purchases and suppliers', 'Record incoming stock and supplier details.'],
                ['Inventory movements', 'Capture sales, purchases, returns, adjustments and transfers.'],
                ['Stock locations', 'Represent the storage areas used by the operation.'],
                ['Stock-taking integration', 'Request a physical count for the stock areas in scope.', '/brathamatu-pos/stocktaking-integration'],
            ]),
            $this->process('Preparing a restaurant demonstration', 'Focus the walkthrough on service and stock.', [
                ['Describe the operation', 'Share the service style, product range and stock areas.'],
                ['Review sales workflows', 'See how products are sold and receipts are issued.'],
                ['Review stock workflows', 'Walk through purchases, suppliers and inventory movements.'],
                ['Discuss counts', 'Agree which stock areas or product groups need physical verification.'],
            ]),
            $this->faq('Restaurant POS questions', [
                ['Can the POS manage food and beverage stock?', 'Yes. Products and consumables can be represented in the catalogue and linked to purchases and stock movements.'],
                ['Does the POS handle ingredient-level recipes?', 'Stock is tracked at the product level you configure. Any ingredient-level requirement should be discussed in the demonstration so fit can be confirmed.'],
                ['Can we count only the bar or store room?', 'Yes. A stock take can be scoped to selected storage areas or product groups.'],
                ['Does the POS work for more than one outlet?', 'Yes. Branches and stock locations can be represented for multi-outlet operations.'],
            ]),
            $this->cta(
                'See restaurant POS workflows.',
                'Request a demonstration covering service sales, purchases and stock checks.',
                ['label' => 'Book a BRATHAMATU POS demo', 'url' => '/book-demo'],
                ['label' => 'Request a stock-taking quote', 'url' => '/request-quote'],
            ),
        ], [
            'title' => 'Restaurant POS software',
            'description' => 'BRATHAMATU POS for restaurant sales, receipts, menu and consumable product setup, supplier purchases, stock movements and physical stock counts.',
            'priority' => 0.7,
        ], type: 'landing');

        $this->publishPage('brathamatu-pos/multi-branch', 'Multi-Branch POS', [
            $this->hero(
                'BRATHAMATU POS',
                'Multi-branch POS with one operational view',
                'Run terminals and stock workflows across branches while keeping product records, transfers and reporting connected.',
                [
                    'Branch and terminal configuration',
                    'Stock transfers between locations',
                    'One catalogue across the operation',
                ],
                ['label' => 'Book a multi-branch POS demo', 'url' => '/book-demo'],
                ['label' => 'Explore BRATHAMATU POS', 'url' => '/brathamatu-pos'],
                'Multi-branch controls',
                ['Branch setup', 'Terminal workflows', 'Stock transfers', 'Cross-branch reporting'],
            ),
            $this->richText('Managing more than one location', [
                'Multiple branches create two needs at once: each location must operate its own sales and stock workflow, while the business needs a consistent product and inventory view across the operation.',
                'BRATHAMATU POS supports branch structure, terminals, shared product catalogues and stock transfers between locations. That keeps the operating model consistent without treating every branch as a separate business.',
                'Physical counts can follow the same structure. A count can cover one branch, selected locations or the full branch network, with results reviewed per location or across the scope.',
            ], [
                'Each branch needs its own sales and stock workflow',
                'Products and stock records need a common structure',
                'Transfers and branch performance need to be visible',
            ]),
            $this->posFeatures('Multi-branch POS controls', 'A shared foundation with location-level operations.', [
                ['Branch setup', 'Represent each trading location within the organisation.'],
                ['Terminals and users', 'Configure the terminals and access needed at each branch.'],
                ['Shared catalogue', 'Use a consistent product structure across locations.'],
                ['Stock transfers', 'Move stock between branches and storage locations.'],
                ['Inventory by location', 'Keep stock movements connected to the branch structure.'],
                ['Stock-taking requests', 'Request counts for one branch or the wider network.', '/brathamatu-pos/stocktaking-integration'],
            ]),
            $this->process('Planning a multi-branch rollout', 'Start with the structure, then the workflow.', [
                ['Map the branch network', 'List the locations, storage areas and operating patterns.'],
                ['Agree the catalogue', 'Confirm the shared product structure and branch-specific needs.'],
                ['Configure terminals and users', 'Set up each location for its sales and stock workflow.'],
                ['Go live and verify', 'Start branch operations and use physical counts where stock accuracy needs checking.'],
            ]),
            $this->faq('Multi-branch POS questions', [
                ['Can each branch have its own stock?', 'Yes. Branches and stock locations are represented separately within the shared inventory structure.'],
                ['Can stock be transferred between branches?', 'Yes. Stock transfers are part of the inventory movement workflow.'],
                ['Can we roll out one branch first?', 'Yes. The demonstration and setup can focus on one location before additional branches are added.'],
                ['Can reports distinguish branches?', 'The branch structure supports location-level operations and stock visibility. Reporting requirements can be reviewed in the demonstration.'],
            ]),
            $this->cta(
                'See how multi-branch control works.',
                'Request a demonstration covering branch setup, transfers and stock requests.',
                ['label' => 'Book a BRATHAMATU POS demo', 'url' => '/book-demo'],
                ['label' => 'Request a stock-taking quote', 'url' => '/request-quote'],
            ),
        ], [
            'title' => 'Multi-branch POS software',
            'description' => 'BRATHAMATU POS for multiple branches with shared catalogues, terminals, stock transfers, location-level inventory and physical count requests.',
            'priority' => 0.7,
        ], type: 'landing');

        $this->publishPage('brathamatu-pos/inventory-management', 'Inventory Management', [
            $this->hero(
                'BRATHAMATU POS',
                'Inventory management connected to sales and stock counts',
                'Keep stock movements, locations and physical counting connected so the records behind the business can be followed and checked.',
                [
                    'Stock locations and inventory movements',
                    'Batch, serial and expiry tracking where needed',
                    'Physical stock-count requests from the POS',
                ],
                ['label' => 'Book an inventory demo', 'url' => '/book-demo'],
                ['label' => 'Explore BRATHAMATU POS', 'url' => '/brathamatu-pos'],
                'Inventory controls',
                ['Stock locations', 'Purchases and transfers', 'Adjustments and returns', 'Cycle or full counts'],
            ),
            $this->richText('The inventory record behind the sale', [
                'Inventory management is the discipline of keeping the recorded stock position connected to what actually happens: purchases arrive, goods are sold, returns come back, stock moves between locations and adjustments are made.',
                'BRATHAMATU POS records those movements against the product catalogue and stock locations. Depending on the product, tracking can include batches, serials or expiry dates.',
                'Physical counts provide the reality check. A count can compare the physical position with the records and identify where the movement history and the shelf no longer agree.',
            ], [
                'Stock records need to follow purchases, sales and transfers',
                'Different products need different tracking detail',
                'Physical counts and system records must use the same catalogue',
            ]),
            $this->posFeatures('Inventory management capabilities', 'From movement records to physical verification.', [
                ['Inventory movements', 'Track purchases, sales, returns, transfers, adjustments and other stock events.'],
                ['Stock locations', 'Represent branches, warehouses and storage areas.'],
                ['Batch and serial tracking', 'Use the tracking mode appropriate to each product.'],
                ['Expiry dates', 'Maintain expiry information where products require it.'],
                ['Purchases and suppliers', 'Record incoming stock with supplier context.'],
                ['Stock-taking integration', 'Request cycle or full counts from the POS workflow.', '/brathamatu-pos/stocktaking-integration'],
            ]),
            $this->process('Building a reliable inventory workflow', 'Set the structure, then keep it checked.', [
                ['Define the product model', 'Agree products, variants, units and the tracking each needs.'],
                ['Set the locations', 'Configure branches, warehouses and storage areas.'],
                ['Record movements', 'Use purchases, sales, returns, transfers and adjustments consistently.'],
                ['Verify physically', 'Use counts to compare the recorded position with actual stock.'],
            ]),
            $this->faq('Inventory management questions', [
                ['Does inventory management require the POS to be used for sales?', 'The inventory workflow is part of BRATHAMATU POS and is designed to work with its sales and purchasing records. Fit for an external sales system can be discussed separately.'],
                ['What tracking modes are supported?', 'Products can use quantity, batch, serial or expiry-related tracking depending on what the item needs.'],
                ['Can stock be managed across locations?', 'Yes. Branches and stock locations are part of the inventory structure.'],
                ['Can we count stock without a full stock take?', 'Yes. Selected products, categories or locations can be counted through a cycle-counting or on-demand scope.'],
            ]),
            $this->cta(
                'Review inventory management fit.',
                'Request a demonstration focused on movements, locations and physical counts.',
                ['label' => 'Book a BRATHAMATU POS demo', 'url' => '/book-demo'],
                ['label' => 'Request a stock-taking quote', 'url' => '/request-quote'],
            ),
        ], [
            'title' => 'Inventory management software',
            'description' => 'BRATHAMATU POS inventory management with stock movements, locations, batch, serial and expiry tracking, transfers and physical count requests.',
            'priority' => 0.7,
        ], type: 'landing');

        $this->publishPage('brathamatu-pos/barcode-catalogue', 'Barcode and Product Catalogue', [
            $this->hero(
                'BRATHAMATU POS',
                'Barcode and product catalogue management for POS',
                'Build a product structure that supports selling, purchasing and physical counting from the same records.',
                [
                    'Products, variants, units and barcodes',
                    'Catalogue setup and import workflows',
                    'A common reference for sales and stock counts',
                ],
                ['label' => 'Book a catalogue demo', 'url' => '/book-demo'],
                ['label' => 'Explore BRATHAMATU POS', 'url' => '/brathamatu-pos'],
                'Catalogue foundations',
                ['Product records', 'Variants and units', 'Barcode assignment', 'Stock tracking mode'],
            ),
            $this->richText('Why the catalogue comes first', [
                'Sales, purchases and stock counts all depend on the product catalogue. If the same item is described differently in each workflow, the records cannot be compared reliably.',
                'BRATHAMATU POS provides a structured catalogue for products, variants, units and barcodes. The catalogue can be built for the way the business sells and stocked, including the tracking method each product needs.',
                'Catalogue setup and import workflows help bring existing product data into the platform. The data can then be used by terminals, purchases, inventory movements and physical counts.',
            ], [
                'Product data needs one consistent structure',
                'Barcodes must map to the products being sold',
                'Counts should use the same products and units as the POS',
            ]),
            $this->posFeatures('Catalogue and barcode capabilities', 'The product foundation for the operating workflow.', [
                ['Product records', 'Maintain the products used for selling and counting.'],
                ['Variants and units', 'Represent size, pack or other selling differences.'],
                ['Barcodes', 'Assign barcodes to the products that need them.'],
                ['Catalogue import', 'Bring existing product data into the platform through supported import workflows.'],
                ['Tracking modes', 'Set quantity, batch, serial or expiry-related tracking per product.'],
                ['Stock-taking integration', 'Count against the same catalogue used at the terminal.', '/brathamatu-pos/stocktaking-integration'],
            ]),
            $this->process('Preparing a catalogue rollout', 'Data quality is the main input.', [
                ['Review the existing data', 'Identify products, variants, units, barcodes and tracking needs.'],
                ['Agree the structure', 'Decide how the catalogue should represent the real product range.'],
                ['Load and validate', 'Use the agreed setup or import workflow and check the result.'],
                ['Use it consistently', 'Sell, purchase and count from the same catalogue.'],
            ]),
            $this->faq('Barcode and catalogue questions', [
                ['Can we bring an existing catalogue across?', 'Supported catalogue import workflows can be used to bring existing product data into the platform. The data is reviewed during setup.'],
                ['Do all products need a barcode?', 'Barcodes are configured where they are used. The catalogue structure can be discussed around the actual product range.'],
                ['Can different products use different tracking?', 'Yes. Tracking can vary by product, including quantity, batch, serial or expiry-related needs.'],
                ['Will stock counts use the same catalogue?', 'Yes. Physical counts are scoped against the product records used in the POS.'],
            ]),
            $this->cta(
                'Get the product catalogue right first.',
                'Request a demonstration covering products, variants, barcodes and import workflows.',
                ['label' => 'Book a BRATHAMATU POS demo', 'url' => '/book-demo'],
                ['label' => 'Request a stock-taking quote', 'url' => '/request-quote'],
            ),
        ], [
            'title' => 'Barcode and catalogue management',
            'description' => 'BRATHAMATU POS product catalogue management with variants, units, barcodes, import workflows and tracking options shared by sales and stock counts.',
            'priority' => 0.7,
        ], type: 'landing');

        $this->publishPage('brathamatu-pos/stocktaking-integration', 'POS and Stock-Taking Integration', [
            $this->hero(
                'BRATHAMATU POS',
                'Stock-taking integration inside the BRATHAMATU POS workflow',
                'Request and authorise a physical stock count from the same platform that holds the sales, product and inventory records.',
                [
                    'Stock-count requests from the POS workflow',
                    'Count scope linked to the product catalogue',
                    'Physical results reviewed alongside inventory records',
                ],
                ['label' => 'Book an integration demo', 'url' => '/book-demo'],
                ['label' => 'Request a stock-taking quote', 'url' => '/request-quote'],
                'What connects',
                ['Count requests', 'Authorisation', 'Catalogue and stock context', 'Results review'],
            ),
            $this->richText('One workflow for records and physical counts', [
                'A physical stock take and a POS are useful on their own, but they are stronger when they share the same product and stock context. The count can then be scoped against the records the business already uses.',
                'With BRATHAMATU POS, a stock-taking service can be requested through the POS workflow and authorised by the appropriate users. The service scope uses the catalogue and branch structure already maintained in the platform.',
                'The integration does not force the two products to be used together. Stock-taking can be booked without the POS, and the POS can be used without booking a count.',
            ], [
                'Count requests should not live in a separate product list',
                'The count scope should match the POS catalogue',
                'Authorisation and service records should stay connected',
            ]),
            $this->posFeatures('How the integration works', 'A connected path from request to count.', [
                ['Request a count', 'Start a stock-taking request from the POS workflow.'],
                ['Authorise the service', 'Use the available permissions to approve the count request.'],
                ['Use catalogue context', 'Scope physical counting against the products and branches maintained in the POS.'],
                ['Schedule the count', 'Agree timing and access for the service.'],
                ['Review the outcome', 'Compare the physical result with the inventory context behind the request.'],
                ['Keep independence', 'The POS and stock-taking service remain usable separately.', '/stock-taking'],
            ]),
            $this->process('From POS request to physical count', 'The service follows a connected sequence.', [
                ['Raise the request', 'Describe the stock, locations and timing from the POS workflow.'],
                ['Review and authorise', 'The responsible users confirm the scope and service request.'],
                ['Count the stock', 'The stock-taking team performs the agreed physical count.'],
                ['Review the result', 'The outcome is discussed alongside the records that prompted the count.'],
            ]),
            $this->faq('POS and stock-taking integration questions', [
                ['Do we need BRATHAMATU POS to book a stock take?', 'No. A stock take can be requested without the POS. The integration is for businesses that want the request and records connected.'],
                ['Can a count be requested from the POS?', 'Yes. Stock-taking services can be requested and authorised from the POS workflow.'],
                ['Who authorises a stock-taking request?', 'Authorisation uses the user permissions configured for the organisation. The roles and controls are reviewed during setup.'],
                ['Can the count cover multiple branches?', 'Yes. The stock-taking scope can include selected branches or locations when access and timing are agreed.'],
            ]),
            $this->cta(
                'Connect physical counts to the POS.',
                'Request a demonstration of the request, authorisation and results workflow.',
                ['label' => 'Book a BRATHAMATU POS demo', 'url' => '/book-demo'],
                ['label' => 'Request a stock-taking quote', 'url' => '/request-quote'],
            ),
        ], [
            'title' => 'POS and stock-taking integration',
            'description' => 'Connect BRATHAMATU POS with professional stock taking: request and authorise physical counts, scope them against the catalogue and review results together.',
            'priority' => 0.7,
        ], type: 'landing');
    }

    private function blog(): void
    {
        $planning = BlogCategory::updateOrCreate(
            ['slug' => 'stock-count-planning'],
            ['name' => 'Stock count planning', 'description' => 'Preparing people, records and locations before a physical count.', 'sort_order' => 1],
        );
        $accuracy = BlogCategory::updateOrCreate(
            ['slug' => 'inventory-accuracy'],
            ['name' => 'Inventory accuracy', 'description' => 'Habits and controls that keep recorded stock aligned with physical stock.', 'sort_order' => 2],
        );

        $articles = [
            [
                'slug' => 'preparing-for-a-stock-count',
                'title' => 'Preparing for a stock count: a practical checklist',
                'excerpt' => 'A clear count starts before the counting team arrives. Use this checklist to align records, locations and responsibilities.',
                'category' => $planning,
                'published_at' => '2026-11-04 09:00:00',
                'tags' => ['stock count', 'preparation', 'checklist'],
                'body' => <<<'MD'
A physical stock count is only as reliable as the preparation behind it. When records, locations and responsibilities are aligned beforehand, counting moves faster and the results are easier to reconcile.

## Agree the scope

Start by confirming which locations, branches or storage areas are included. Decide whether the count covers every item or a defined subset, and record that scope so everyone works from the same definition. If some areas are excluded, write down why.

## Freeze or track movement

Stock that moves during a count creates differences that are hard to explain later. Agree a cut-off for receipts, transfers and sales, or put a simple process in place to record any movement that happens while counting is underway.

## Clean and label locations

Counting is easier when every location is identifiable and reachable. Check that shelves are labelled consistently, that mixed locations are separated, and that damaged or quarantined stock is clearly marked. Photographs of ambiguous areas can help settle questions afterwards.

## Prepare the count file

Export the item list for the agreed scope and check that item codes, descriptions and units of measure are consistent. Highlight items with known issues such as alternative packaging, loose units or recent relocations.

## Brief the counting team

Explain the counting method, how to record quantities and what to do when an item is not where the file says it should be. Make it clear who answers questions and who authorises adjustments. Brief anyone who handles stock during the count as well.

## Plan for recounts

Set a simple rule for when a line is recounted, for example where the first count differs from the expected quantity. Recounts should be independent of the first count so that a misread is caught rather than repeated.

## Close the loop

After the count, reconcile the physical quantities with the records, review the largest differences and agree follow-up actions. Keep the signed count sheets and the final reconciliation together so the count can be explained later.

Preparation does not remove every difference, but it makes the remaining differences understandable and gives the counting team a consistent process to follow.
MD,
                'seo_description' => 'Practical preparation steps for a physical stock count: scope, cut-off, location labelling, count files and team briefing.',
            ],
            [
                'slug' => 'cycle-counting-and-annual-stock-takes',
                'title' => 'Cycle counting and annual stock takes: choosing an approach',
                'excerpt' => 'Annual counts and cycle counts answer different questions. Many operations use both, with clear rules for each.',
                'category' => $planning,
                'published_at' => '2026-11-08 10:30:00',
                'tags' => ['cycle counting', 'stock take', 'inventory'],
                'body' => <<<'MD'
A stock take is a point-in-time check of physical stock. Cycle counting spreads smaller counts across the year. They are often presented as alternatives, but they serve different purposes and can work together.

## What an annual stock take provides

A full count gives a complete snapshot of stock at a chosen date. That is useful for financial reporting, for resetting records after a period of change, and for reviewing the whole inventory at once. The trade-off is disruption: a full count usually requires a freeze, extra people and a plan for recounts.

## What cycle counting provides

Cycle counting checks a smaller group of items on a regular schedule. Because the scope is smaller, counting can fit around normal trading and problems can be corrected sooner. A cycle count is most useful when items are grouped sensibly and when differences lead to an investigation rather than a quiet adjustment.

## Combining the two

Many operations run regular cycle counts through the year and a full count at a reporting date. The cycle counts keep records honest between full counts, while the full count provides the complete snapshot. The important part is to define which items are counted, how often, and what happens when a difference is found.

## Rules that make either approach work

- Counts are only meaningful if movement during the count is controlled or recorded.
- Each count needs an owner who is responsible for the result.
- Differences should be investigated, not just written off.
- The counting method should be written down and followed consistently.

## Choosing a starting point

If records are unreliable or a reporting deadline is close, a full count can be the right reset. If the main problem is that small errors accumulate between counts, a cycle-counting schedule may suit better. Either way, start with a scope and a rule set that the team can actually follow.

The approach matters less than consistency. A modest count done carefully and repeated on schedule is usually more useful than an ambitious plan that is abandoned after the first period.
MD,
                'seo_description' => 'Compare full stock takes and cycle counting, and learn how to combine them with clear scope, ownership and count rules.',
            ],
            [
                'slug' => 'reducing-stock-discrepancies',
                'title' => 'Reducing stock discrepancies with everyday habits',
                'excerpt' => 'Most differences come from small, repeated process gaps rather than one-off mistakes. These habits help close them.',
                'category' => $accuracy,
                'published_at' => '2026-11-12 08:30:00',
                'tags' => ['inventory accuracy', 'process', 'stock control'],
                'body' => <<<'MD'
Stock discrepancies rarely come from a single dramatic error. More often they build up from small gaps: a delivery booked to the wrong location, a return put back on the shelf without a record, or a substitute item counted under the original code.

## One location, one home

Every item should have a defined primary location. Mixed locations make counting slower and make it easier to miss stock. When an item genuinely has more than one location, record both clearly and check them during counts.

## Record movement as it happens

Transfers, returns and write-offs are easiest to record at the moment they happen. A simple rule such as "no movement without a record" prevents a backlog of untracked changes that has to be reconstructed later.

## Use consistent units

Differences often appear when items are bought, stored and sold in different units. Define the counting unit for each item, note pack sizes clearly, and check that the count file uses the same unit as the shelf label.

## Separate damaged and quarantined stock

Stock that cannot be sold should be physically separated and clearly marked. When it is mixed with sellable stock, counts become ambiguous and adjustments are harder to justify.

## Review differences quickly

When a count finds a difference, review it while the details are fresh: check recent movement, ask the team, and look at whether the item has alternative packaging or codes. The goal is to understand the cause, not simply to force the number to match.

## Make counts routine

Short, regular counts are easier to fit into daily work than a single large event. They also surface problems earlier, when the cause is still easy to find. Rotating which items are counted keeps coverage broad without disrupting operations.

## Keep a simple record

Note what was counted, when, by whom, and what was found. A short written trail makes it easier to spot recurring problem items and to show how a difference was investigated.

Inventory accuracy is a process outcome. The habits that support it are unglamorous, but they are what make the numbers trustworthy over time.
MD,
                'seo_description' => 'Everyday habits that reduce stock discrepancies: defined locations, recorded movement, consistent units and quick difference reviews.',
            ],
        ];

        $posts = [];
        foreach ($articles as $article) {
            $post = BlogPost::updateOrCreate(
                ['slug' => $article['slug']],
                [
                    'title' => $article['title'],
                    'excerpt' => $article['excerpt'],
                    'body' => $article['body'],
                    'format' => 'markdown',
                    'status' => 'published',
                    'author_name' => 'BRATHAMATU Editorial',
                    'category_id' => $article['category']->id,
                    'seo_title' => $article['title'],
                    'seo_description' => $article['seo_description'],
                    'robots_index' => true,
                    'robots_follow' => true,
                    'noindex' => false,
                    'published_at' => Carbon::parse($article['published_at']),
                    'scheduled_at' => null,
                ],
            );

            $tagIds = collect($article['tags'])->map(fn (string $name) => BlogTag::firstOrCreate(
                ['slug' => Str::slug($name)],
                ['name' => $name],
            )->id);
            $post->tags()->sync($tagIds->all());

            $posts[] = $post;
        }

        $posts[0]->relatedPosts()->sync([$posts[1]->id => ['sort_order' => 0], $posts[2]->id => ['sort_order' => 1]]);
        $posts[1]->relatedPosts()->sync([$posts[0]->id => ['sort_order' => 0]]);
        $posts[2]->relatedPosts()->sync([$posts[0]->id => ['sort_order' => 0], $posts[1]->id => ['sort_order' => 1]]);

        $checklist = <<<'TXT'
BRATHAMATU — Stock count preparation checklist

1. Confirm the scope: locations, branches and item groups included.
2. Agree a movement cut-off or a way to record movement during the count.
3. Check that locations are labelled and reachable.
4. Export the count file and confirm item codes, descriptions and units.
5. Separate damaged, quarantined and returned stock.
6. Brief the counting team on method, records and escalation.
7. Set a recount rule for lines that differ from the expected quantity.
8. Reconcile results, review the largest differences and assign follow-up actions.
9. File the signed count sheets with the reconciliation.

This checklist is general guidance for planning a physical stock count.
TXT;

        Storage::disk('public')->put('resources/stock-count-preparation-checklist.txt', $checklist);

        $asset = MediaAsset::updateOrCreate(
            ['checksum' => hash('sha256', $checklist)],
            [
                'disk' => 'public',
                'path' => 'resources/stock-count-preparation-checklist.txt',
                'filename' => 'stock-count-preparation-checklist.txt',
                'mime' => 'text/plain',
                'extension' => 'txt',
                'size_bytes' => strlen($checklist),
                'alt_text' => 'Stock count preparation checklist',
                'description' => 'A nine-step checklist for planning a physical stock count.',
            ],
        );

        ResourceDownload::updateOrCreate(
            ['slug' => 'stock-count-preparation-checklist'],
            [
                'title' => 'Stock count preparation checklist',
                'description' => 'A nine-step checklist covering scope, movement cut-off, location readiness, team briefing, recounts and reconciliation.',
                'media_asset_id' => $asset->id,
                'requires_consent' => true,
                'is_published' => true,
                'published_at' => Carbon::parse('2026-11-05 09:00:00'),
            ],
        );

        EditorialItem::updateOrCreate(
            ['title' => 'Plan the next inventory accuracy article'],
            [
                'brief' => 'Outline a follow-up article on count frequency by item group. Confirm examples with the operations team before drafting.',
                'due_on' => '2026-12-05',
                'status' => 'planned',
            ],
        );

        EditorialItem::updateOrCreate(
            ['title' => 'Review checklist download copy'],
            [
                'brief' => 'Check that the checklist wording stays general and does not promise specific outcomes.',
                'due_on' => '2026-11-28',
                'status' => 'review',
                'blog_post_id' => $posts[0]->id,
            ],
        );
    }

    private function menus(): void
    {
        $primary = CmsMenu::updateOrCreate(['key' => 'primary'], ['name' => 'Primary navigation']);
        $footer = CmsMenu::updateOrCreate(['key' => 'footer'], ['name' => 'Footer navigation']);
        $legal = CmsMenu::updateOrCreate(['key' => 'legal'], ['name' => 'Legal navigation']);

        if ($primary->items()->doesntExist()) {
            $items = [
                ['Home', 'home'],
                ['Stock Taking', 'stock-taking'],
                ['BRATHAMATU POS', 'brathamatu-pos'],
                ['How It Works', 'how-it-works'],
                ['Industries', 'industries'],
                ['Pricing', 'pricing'],
                ['Resources', 'resources'],
                ['About Us', 'about'],
                ['Contact', 'contact'],
            ];

            foreach ($items as $index => [$label, $slug]) {
                $primary->items()->create([
                    'label' => $label,
                    'page_id' => CmsPage::where('slug', $slug)->value('id'),
                    'sort_order' => $index,
                ]);
            }
        }

        if ($footer->items()->doesntExist()) {
            $items = [
                ['Stock Taking', 'stock-taking'],
                ['BRATHAMATU POS', 'brathamatu-pos'],
                ['How It Works', 'how-it-works'],
                ['Industries', 'industries'],
                ['Pricing', 'pricing'],
                ['Resources', 'resources'],
                ['About Us', 'about'],
                ['FAQs', 'faqs'],
                ['Contact', 'contact'],
                ['Request a Quote', 'request-quote'],
            ];

            foreach ($items as $index => [$label, $slug]) {
                $footer->items()->create([
                    'label' => $label,
                    'page_id' => CmsPage::where('slug', $slug)->value('id'),
                    'sort_order' => $index,
                ]);
            }
        }

        if ($legal->items()->doesntExist()) {
            $items = [
                ['Privacy Notice', 'privacy'],
                ['Terms of Service', 'terms'],
                ['Cookie Notice', 'cookies'],
            ];

            foreach ($items as $index => [$label, $slug]) {
                $legal->items()->create([
                    'label' => $label,
                    'page_id' => CmsPage::where('slug', $slug)->value('id'),
                    'sort_order' => $index,
                ]);
            }
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $sections
     * @param  array<string, mixed>  $seo
     */
    private function publishPage(string $slug, string $title, array $sections, array $seo, bool $isHome = false, string $type = 'page'): CmsPage
    {
        $noindex = (bool) ($seo['noindex'] ?? false);
        $page = CmsPage::firstOrNew(['slug' => $slug]);
        $page->fill([
            'title' => $title,
            'type' => $type,
            'template' => 'public.page',
            'status' => 'published',
            'is_home' => $isHome,
            'requires_auth' => false,
            'noindex' => $noindex,
            'published_at' => $page->published_at ?? now(),
        ]);
        $page->save();

        if ($page->sections()->doesntExist()) {
            foreach ($sections as $index => $section) {
                $page->sections()->create([
                    'type' => $section['type'],
                    'sort_order' => $index,
                    'content' => $section['content'],
                    'settings' => $section['settings'] ?? [],
                    'is_visible' => true,
                ]);
            }
        }

        SeoMetadata::updateOrCreate(
            ['seoable_type' => CmsPage::class, 'seoable_id' => $page->id],
            [
                'title' => $seo['title'] ?? null,
                'description' => $seo['description'] ?? null,
                'robots_index' => ! $noindex,
                'robots_follow' => true,
                'priority' => $seo['priority'] ?? 0.5,
                'change_frequency' => $seo['change_frequency'] ?? 'monthly',
            ],
        );

        app(MarketingPageService::class)
            ->ensurePublishedVersion($page->fresh(['sections', 'seo']));

        return $page;
    }

    /**
     * @param  array<int, string>  $bullets
     * @param  array{label: string, url: string}|null  $primary
     * @param  array{label: string, url: string}|null  $secondary
     * @param  array<int, string>  $panelItems
     * @return array<string, mixed>
     */
    private function hero(
        ?string $eyebrow,
        string $headline,
        string $subheadline,
        array $bullets,
        ?array $primary = null,
        ?array $secondary = null,
        ?string $panelTitle = null,
        array $panelItems = [],
    ): array {
        return [
            'type' => 'hero',
            'content' => array_filter([
                'eyebrow' => $eyebrow,
                'headline' => $headline,
                'subheadline' => $subheadline,
                'bullets' => $bullets,
                'primary_cta' => $primary,
                'secondary_cta' => $secondary,
                'panel' => $panelTitle !== null ? ['title' => $panelTitle, 'items' => $panelItems] : null,
            ], fn ($value) => $value !== null && $value !== []),
        ];
    }

    /**
     * @param  array<int, array{0: string, 1: string, 2?: string}>  $items
     * @return array<string, mixed>
     */
    private function serviceGrid(string $heading, string $intro, array $items): array
    {
        return [
            'type' => 'service_grid',
            'content' => [
                'heading' => $heading,
                'intro' => $intro,
                'items' => array_map(fn (array $item) => array_filter([
                    'title' => $item[0],
                    'body' => $item[1],
                    'url' => $item[2] ?? null,
                ], fn ($value) => $value !== null), $items),
            ],
        ];
    }

    /**
     * @param  array<int, array{0: string, 1: string, 2?: string}>  $items
     * @return array<string, mixed>
     */
    private function industriesSection(string $heading, string $intro, array $items): array
    {
        return [
            'type' => 'industries',
            'content' => [
                'heading' => $heading,
                'intro' => $intro,
                'items' => array_map(fn (array $item) => array_filter([
                    'title' => $item[0],
                    'body' => $item[1],
                    'url' => $item[2] ?? null,
                ], fn ($value) => $value !== null), $items),
            ],
        ];
    }

    /**
     * @param  array<int, array{0: string, 1: string, 2?: string}>  $items
     * @return array<string, mixed>
     */
    private function posFeatures(string $heading, string $intro, array $items): array
    {
        return [
            'type' => 'pos_features',
            'content' => [
                'heading' => $heading,
                'intro' => $intro,
                'items' => array_map(fn (array $item) => array_filter([
                    'title' => $item[0],
                    'body' => $item[1],
                    'url' => $item[2] ?? null,
                ], fn ($value) => $value !== null), $items),
            ],
        ];
    }

    /**
     * @param  array<int, array{0: string, 1: string}>  $steps
     * @return array<string, mixed>
     */
    private function process(string $heading, string $intro, array $steps): array
    {
        return [
            'type' => 'process',
            'content' => [
                'heading' => $heading,
                'intro' => $intro,
                'steps' => array_map(fn (array $step) => ['title' => $step[0], 'body' => $step[1]], $steps),
            ],
        ];
    }

    /**
     * @param  array<int, array{0: string, 1: string}>  $items
     * @return array<string, mixed>
     */
    private function faq(string $heading, array $items): array
    {
        return [
            'type' => 'faq',
            'content' => [
                'heading' => $heading,
                'items' => array_map(fn (array $item) => ['question' => $item[0], 'answer' => $item[1]], $items),
            ],
        ];
    }

    /**
     * @param  array<int, string>  $bullets
     * @param  array{label: string, url: string}|null  $cta
     * @return array<string, mixed>
     */
    private function richText(string $heading, array $paragraphs, array $bullets = [], ?array $cta = null): array
    {
        return [
            'type' => 'rich_text',
            'content' => array_filter([
                'heading' => $heading,
                'paragraphs' => $paragraphs,
                'bullets' => $bullets,
                'cta' => $cta,
            ], fn ($value) => $value !== [] && $value !== null),
        ];
    }

    /**
     * @param  array<int, string>  $items
     * @return array<string, mixed>
     */
    private function cta(string $heading, string $body, ?array $primary = null, ?array $secondary = null): array
    {
        return [
            'type' => 'cta',
            'content' => [
                'heading' => $heading,
                'body' => $body,
                'primary_cta' => $primary,
                'secondary_cta' => $secondary,
            ],
        ];
    }

    /**
     * @param  array<int, string>  $items
     * @param  array{label: string, url: string}|null  $cta
     * @return array<string, mixed>
     */
    private function ensureFormSections(): void
    {
        $mapping = [
            'request-quote' => ['quote'],
            'book-demo' => ['pos_demo'],
            'contact' => ['contact', 'callback', 'partnership'],
        ];
        $presets = $this->formSectionPresets();

        foreach ($mapping as $slug => $kinds) {
            $page = CmsPage::query()->where('slug', $slug)->with('sections')->first();
            if ($page === null) {
                continue;
            }

            $touched = false;
            foreach ($kinds as $kind) {
                if ($page->sections->contains(fn (CmsPageSection $section) => ($section->content['form'] ?? null) === $kind)) {
                    continue;
                }

                $placeholder = in_array($kind, ['quote', 'pos_demo'], true)
                    ? $page->sections->first(fn (CmsPageSection $section) => $section->type === 'contact')
                    : null;

                if ($placeholder !== null) {
                    $placeholder->update([
                        'type' => 'form',
                        'content' => $presets[$kind]['content'],
                        'settings' => [],
                        'is_visible' => true,
                    ]);
                } else {
                    $page->sections()->create([
                        'type' => 'form',
                        'sort_order' => (int) $page->sections->max('sort_order') + 1,
                        'content' => $presets[$kind]['content'],
                        'settings' => [],
                        'is_visible' => true,
                    ]);
                }

                $touched = true;
                $page->load('sections');
            }

            if ($touched && $page->status === 'published') {
                $page->forceFill(['current_version_id' => null])->save();
                app(MarketingPageService::class)->ensurePublishedVersion($page->fresh(['sections', 'seo']));
            }
        }
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function formSectionPresets(): array
    {
        return [
            'quote' => $this->formSection('quote', 'Send your request', 'Share the scope, locations and preferred dates. The team will review the details and prepare a quotation.', [
                'Stock-taking quotations', 'Recurring schedules', 'Independent verification and recounts',
            ], 'Send request'),
            'pos_demo' => $this->formSection('pos_demo', 'Request a demonstration', 'Tell us about your business and the workflows you want to see in BRATHAMATU POS.', [
                'Retail and supermarket', 'Pharmacy and wholesale', 'Hardware, restaurant and multi-branch',
            ], 'Request a demo'),
            'contact' => $this->formSection('contact', 'Send an enquiry', 'Use this form for general enquiries. For stock-taking and POS requests, the dedicated forms collect the details needed for a quotation or demonstration.', [], 'Send message'),
            'callback' => $this->formSection('callback', 'Request a callback', 'Leave your phone number and a convenient time to discuss your requirements.', [], 'Request a callback'),
            'partnership' => $this->formSection('partnership', 'Partnership enquiry', 'Tell us about your organisation and the partnership you have in mind.', [], 'Send enquiry'),
        ];
    }

    /**
     * @param  array<int, string>  $items
     * @return array<string, mixed>
     */
    private function formSection(string $kind, string $heading, string $body, array $items = [], string $submitLabel = 'Send request'): array
    {
        return [
            'type' => 'form',
            'content' => [
                'form' => $kind,
                'heading' => $heading,
                'body' => $body,
                'items' => $items,
                'submit_label' => $submitLabel,
            ],
        ];
    }

    private function contactSection(string $heading, string $body, array $items = [], ?array $cta = null): array
    {
        return [
            'type' => 'contact',
            'content' => [
                'heading' => $heading,
                'body' => $body,
                'items' => $items,
                'cta' => $cta ?? ['label' => 'Request a quote', 'url' => '/request-quote'],
            ],
        ];
    }

    /**
     * @param  array<int, array{heading: string, paragraphs?: array<int, string>, bullets?: array<int, string>}>  $blocks
     * @param  array<int, string>  $intro
     * @return array<string, mixed>
     */
    private function legalDocument(string $heading, array $blocks, array $intro = []): array
    {
        return [
            'type' => 'legal',
            'content' => [
                'heading' => $heading,
                'updated' => '16 November 2026',
                'intro' => $intro,
                'blocks' => $blocks,
            ],
        ];
    }
}
