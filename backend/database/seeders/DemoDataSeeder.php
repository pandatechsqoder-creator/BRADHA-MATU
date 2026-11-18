<?php

namespace Database\Seeders;

use App\Models\Branch;
use App\Models\Department;
use App\Models\Invitation;
use App\Models\Marketing\AnalyticsEvent;
use App\Models\Marketing\AutomationStep;
use App\Models\Marketing\AutomationWorkflow;
use App\Models\Marketing\ConversionEvent;
use App\Models\Marketing\MarketingCampaign;
use App\Models\Marketing\MarketingLead;
use App\Models\Marketing\TrackingPreference;
use App\Models\Role;
use App\Models\StockBin;
use App\Models\StockLocation;
use App\Models\StockTakerAvailability;
use App\Models\StockTakerProfile;
use App\Models\StockTakerServiceArea;
use App\Models\StockTakerSkill;
use App\Models\StockTakerTrainingCompletion;
use App\Models\StockZone;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Local/staging demo data ONLY. Never run in production.
 * Shared local password: Password1!
 */
class DemoDataSeeder extends Seeder
{
    public function run(): void
    {
        $tenants = [
            ['name' => 'Demo Supermarket', 'industry' => 'Retail / Supermarket', 'business_type' => 'Limited company', 'operating_mode' => 'brathamatu_pos', 'status' => 'active', 'email' => 'ops@demosupermarket.test', 'branches' => [
                ['Main Branch — Mombasa Road', 'DSM-01', 'Mombasa Road, Nairobi'],
                ['Westlands Branch', 'DSM-02', 'Westlands, Nairobi'],
            ]],
            ['name' => 'Demo Pharmacy', 'industry' => 'Pharmacy / Healthcare', 'business_type' => 'Partnership', 'operating_mode' => 'manual', 'status' => 'active', 'email' => 'ops@demopharmacy.test', 'branches' => [
                ['Pharmacy — CBD', 'DPH-01', 'Kimathi Street, Nairobi'],
            ]],
            ['name' => 'Demo Hardware', 'industry' => 'Hardware / Construction', 'business_type' => 'Sole proprietorship', 'operating_mode' => 'external', 'status' => 'active', 'email' => 'ops@demohardware.test', 'branches' => [
                ['Hardware Yard', 'DHW-01', 'Industrial Area, Nairobi'],
            ]],
            ['name' => 'Demo Warehouse', 'industry' => 'Logistics / Warehousing', 'business_type' => 'Limited company', 'operating_mode' => 'external', 'status' => 'active', 'email' => 'ops@demowarehouse.test', 'branches' => [
                ['Central Warehouse', 'DWH-01', 'Mlolongo, Machakos'],
            ]],
            ['name' => 'Demo Restaurant', 'industry' => 'Hospitality / Food', 'business_type' => 'Limited company', 'operating_mode' => 'brathamatu_pos', 'status' => 'active', 'email' => 'ops@demorestaurant.test', 'branches' => [
                ['Kilimani Restaurant', 'DRS-01', 'Kilimani, Nairobi'],
            ]],
            ['name' => 'Demo SME', 'industry' => 'General Retail', 'business_type' => 'Sole proprietorship', 'operating_mode' => 'manual', 'status' => 'active', 'email' => 'ops@demosme.test', 'branches' => [
                ['Shop — Ngara', 'DSME-01', 'Ngara, Nairobi'],
            ]],
            ['name' => 'Demo Enterprise', 'industry' => 'Distribution / FMCG', 'business_type' => 'Public limited company', 'operating_mode' => 'external', 'status' => 'active', 'email' => 'ops@demoenterprise.test', 'branches' => [
                ['Nairobi HQ', 'DE-01', 'Upper Hill, Nairobi'],
                ['Mombasa Depot', 'DE-02', 'Changamwe, Mombasa'],
                ['Kisumu Depot', 'DE-03', 'Kondele, Kisumu'],
            ]],
        ];

        $tenantModels = [];

        foreach ($tenants as $data) {
            $branches = $data['branches'];
            unset($data['branches']);

            $tenant = Tenant::firstOrCreate(['name' => $data['name']], [
                ...$data,
                'timezone' => 'Africa/Nairobi',
                'created_by' => User::where('email', 'superadmin@brathamatu.test')->value('id'),
            ]);
            $tenantModels[$tenant->name] = $tenant;

            foreach ($branches as [$name, $code, $address]) {
                Branch::firstOrCreate(
                    ['tenant_id' => $tenant->id, 'code' => $code],
                    ['name' => $name, 'address' => $address],
                );
            }
        }

        $accounts = [
            ['superadmin@brathamatu.test', 'Brathamatu Super Admin', 'super-admin', null],
            ['ops@brathamatu.test', 'Brathamatu Operations Manager', 'operations-manager', null],
            ['finance@brathamatu.test', 'Brathamatu Finance Manager', 'finance-manager', null],
            ['officer@brathamatu.test', 'Brathamatu Finance Officer', 'finance-officer', null],
            ['support@brathamatu.test', 'Brathamatu Support Agent', 'support-agent', null],
            ['auditor@brathamatu.test', 'Brathamatu Compliance Auditor', 'auditor', null],
            ['supervisor@brathamatu.test', 'Brathamatu Stock-taking Supervisor', 'supervisor', null],
            ['workforce@brathamatu.test', 'Brathamatu Workforce Manager', 'workforce-manager', null],
            ['editor@brathamatu.test', 'Brathamatu Content Editor', 'content-editor', null],
            ['marketing@brathamatu.test', 'Brathamatu Marketing Manager', 'marketing-manager', null],
            ['publisher@brathamatu.test', 'Brathamatu Content Publisher', 'content-publisher', null],
            ['applicant@brathamatu.test', 'Demo Applicant', 'applicant', null],
            ['owner@demosupermarket.test', 'Demo Supermarket Owner', 'business-owner', 'Demo Supermarket'],
            ['inventory@demosupermarket.test', 'Demo Inventory Manager', 'inventory-manager', 'Demo Supermarket'],
            ['branch@demosupermarket.test', 'Demo Branch Manager', 'branch-manager', 'Demo Supermarket'],
            ['cashier@demosupermarket.test', 'Demo POS Cashier', 'pos-cashier', 'Demo Supermarket'],
            ['owner@demopharmacy.test', 'Demo Pharmacy Owner', 'business-owner', 'Demo Pharmacy'],
            ['worker@brathamatu.test', 'Demo Verified Stock Taker', 'verified-stock-taker', null],
            ['senior.worker@brathamatu.test', 'Demo Senior Stock Taker', 'senior-stock-taker', null],
        ];

        foreach ($accounts as [$email, $name, $roleSlug, $tenantName]) {
            $user = User::firstOrCreate(
                ['email' => $email],
                [
                    'name' => $name,
                    'password' => Hash::make('Password1!'),
                    'email_verified_at' => now(),
                ],
            );

            $role = Role::where('slug', $roleSlug)->firstOrFail();
            $tenantId = $tenantName ? $tenantModels[$tenantName]->id : null;

            DB::table('user_roles')->updateOrInsert(
                ['user_id' => $user->id, 'role_id' => $role->id, 'tenant_id' => $tenantId],
                ['created_at' => now(), 'updated_at' => now()],
            );

            if ($tenantId) {
                DB::table('tenant_memberships')->insertOrIgnore([
                    'tenant_id' => $tenantId,
                    'user_id' => $user->id,
                    'status' => 'active',
                    'joined_at' => now(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }

        $supermarket = $tenantModels['Demo Supermarket'];
        $mainBranch = Branch::where('tenant_id', $supermarket->id)->where('code', 'DSM-01')->first();
        $westlands = Branch::where('tenant_id', $supermarket->id)->where('code', 'DSM-02')->first();

        if ($mainBranch) {
            Department::firstOrCreate(
                ['tenant_id' => $supermarket->id, 'name' => 'Grocery & Fresh'],
                ['branch_id' => $mainBranch->id, 'code' => 'GRO'],
            );
            Department::firstOrCreate(
                ['tenant_id' => $supermarket->id, 'name' => 'General Merchandise'],
                ['branch_id' => $westlands?->id, 'code' => 'GEN'],
            );

            $warehouse = Warehouse::firstOrCreate(
                ['tenant_id' => $supermarket->id, 'code' => 'DSM-WH1'],
                ['branch_id' => $mainBranch->id, 'name' => 'Main Store', 'address' => 'Mombasa Road, Nairobi'],
            );

            $location = StockLocation::firstOrCreate(
                ['tenant_id' => $supermarket->id, 'code' => 'DSM-A1'],
                ['branch_id' => $mainBranch->id, 'warehouse_id' => $warehouse->id, 'name' => 'Aisle 1 — Dry Goods', 'type' => 'aisle'],
            );

            $zone = StockZone::firstOrCreate(
                ['tenant_id' => $supermarket->id, 'stock_location_id' => $location->id, 'code' => 'Z-DRY'],
                ['name' => 'Dry Goods'],
            );

            StockBin::firstOrCreate(
                ['tenant_id' => $supermarket->id, 'stock_zone_id' => $zone->id, 'code' => 'B-01'],
                ['name' => 'Bin 01'],
            );

            $branchManager = User::where('email', 'branch@demosupermarket.test')->first();
            if ($branchManager) {
                DB::table('user_branch_scopes')->insertOrIgnore([
                    'tenant_id' => $supermarket->id,
                    'user_id' => $branchManager->id,
                    'branch_id' => $mainBranch->id,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            $branchRole = Role::where('slug', 'branch-manager')->first();
            if ($branchRole) {
                Invitation::firstOrCreate(
                    ['tenant_id' => $supermarket->id, 'email' => 'new.manager@demosupermarket.test'],
                    [
                        'uuid' => (string) Str::ulid(),
                        'role_id' => $branchRole->id,
                        'branch_id' => $mainBranch->id,
                        'token_hash' => hash('sha256', Str::random(48)),
                        'invited_by' => User::where('email', 'owner@demosupermarket.test')->value('id'),
                        'status' => 'pending',
                        'expires_at' => now()->addDays(7),
                    ],
                );
            }
        }

        $supermarket->settings()->firstOrCreate(['key' => 'stocktake.deposit_policy'], [
            'value' => ['type' => 'percentage', 'value' => 30, 'minimum' => 1000, 'currency' => 'KES'],
        ]);

        $this->seedAnalyticsDemo($supermarket);
        $this->seedAutomationDemo();

        $workforce = [
            [
                'email' => 'worker@brathamatu.test',
                'legal_name' => 'Demo Verified Stock Taker',
                'phone' => '+254700000101',
                'city' => 'Nairobi',
                'county' => 'Nairobi',
                'level' => 'verified',
                'verification_status' => 'verified',
                'skills' => ['general_merchandise', 'fresh_produce', 'barcode_counting'],
                'areas' => ['Nairobi', 'Westlands'],
                'training' => true,
            ],
            [
                'email' => 'senior.worker@brathamatu.test',
                'legal_name' => 'Demo Senior Stock Taker',
                'phone' => '+254700000102',
                'city' => 'Mombasa',
                'county' => 'Mombasa',
                'level' => 'senior_supervisor',
                'verification_status' => 'verified',
                'skills' => ['general_merchandise', 'pharmacy', 'electronics', 'team_lead'],
                'areas' => ['Mombasa', 'Nairobi'],
                'training' => true,
            ],
            [
                'email' => 'applicant@brathamatu.test',
                'legal_name' => 'Demo Applicant',
                'phone' => '+254700000103',
                'city' => 'Nairobi',
                'county' => 'Nairobi',
                'level' => 'applicant',
                'verification_status' => 'unverified',
                'skills' => [],
                'areas' => ['Nairobi'],
                'training' => false,
            ],
        ];

        foreach ($workforce as $seed) {
            $user = User::where('email', $seed['email'])->first();
            if ($user === null) {
                continue;
            }

            $verified = $seed['verification_status'] === 'verified';
            $profile = StockTakerProfile::updateOrCreate(
                ['user_id' => $user->id],
                [
                    'legal_name' => $seed['legal_name'],
                    'phone' => $seed['phone'],
                    'city' => $seed['city'],
                    'county' => $seed['county'],
                    'country' => 'Kenya',
                    'languages' => ['English', 'Swahili'],
                    'experience_years' => $verified ? 3 : 0,
                    'level' => $seed['level'],
                    'status' => 'active',
                    'verification_status' => $seed['verification_status'],
                    'phone_verified_at' => $verified ? now() : null,
                    'identity_verified_at' => $verified ? now() : null,
                    'training_completed_at' => $seed['training'] ? now() : null,
                    'terms_accepted_at' => now(),
                ],
            );

            foreach ($seed['skills'] as $skill) {
                StockTakerSkill::firstOrCreate(
                    ['profile_id' => $profile->id, 'skill' => $skill],
                    ['level' => 'advanced', 'years_experience' => 3, 'verified_at' => $verified ? now() : null],
                );
            }

            foreach ($seed['areas'] as $index => $area) {
                StockTakerServiceArea::firstOrCreate(
                    ['profile_id' => $profile->id, 'area' => $area],
                    ['city' => $area, 'county' => $area, 'is_primary' => $index === 0],
                );
            }

            if ($seed['training']) {
                StockTakerTrainingCompletion::updateOrCreate(
                    ['profile_id' => $profile->id, 'module' => 'core_induction'],
                    [
                        'status' => 'completed',
                        'score' => 92,
                        'completed_at' => now(),
                        'notes' => 'Seeded local demo training completion.',
                    ],
                );
            }

            foreach (range(0, 6) as $weekday) {
                StockTakerAvailability::firstOrCreate(
                    ['profile_id' => $profile->id, 'weekday' => $weekday, 'start_time' => '08:00:00'],
                    ['end_time' => '18:00:00', 'timezone' => 'Africa/Nairobi', 'active' => true],
                );
            }
        }
    }

    private function seedAnalyticsDemo(Tenant $tenant): void
    {
        if (AnalyticsEvent::query()->where('session_id', 'demo-visit-1')->exists()) {
            return;
        }

        $marketing = User::where('email', 'marketing@brathamatu.test')->first();

        $campaign = MarketingCampaign::firstOrCreate(['name' => 'Demo Supermarket Launch'], [
            'objective' => 'lead_generation',
            'channel' => 'website',
            'status' => 'active',
            'audience' => ['summary' => 'Nairobi supermarkets with two or more branches'],
            'budget_amount' => 5000,
            'budget_currency' => 'KES',
            'starts_at' => now()->subDays(10),
            'ends_at' => now()->addDays(20),
            'utm' => ['source' => 'demo', 'medium' => 'website', 'campaign' => 'demo-supermarket-launch'],
            'owner_id' => $marketing?->id,
            'approver_id' => User::where('email', 'superadmin@brathamatu.test')->value('id'),
            'created_by' => $marketing?->id,
            'approved_at' => now()->subDays(11),
            'activated_at' => now()->subDays(10),
            'results' => [
                'reach' => 12000,
                'impressions' => 45000,
                'clicks' => 860,
                'leads' => 24,
                'conversions' => 9,
                'spend' => 15000,
            ],
        ]);

        $leads = [
            ['LEAD-DEMO-A01', 'demo-analytics-1', 'quote', 'new', 'Demo Groceries Ltd', 'Demo Lead One', 'lead.one@example.test'],
            ['LEAD-DEMO-A02', 'demo-analytics-2', 'pos_demo', 'qualified', 'Demo Retail Partners', 'Demo Lead Two', 'lead.two@example.test'],
            ['LEAD-DEMO-A03', 'demo-analytics-3', 'contact', 'won', 'Demo Mini Mart', 'Demo Lead Three', 'lead.three@example.test'],
        ];

        foreach ($leads as $index => [$reference, $token, $type, $status, $business, $contact, $email]) {
            $lead = MarketingLead::firstOrCreate(['submission_token' => $token], [
                'reference' => $reference,
                'type' => $type,
                'status' => $status,
                'business_name' => $business,
                'contact_name' => $contact,
                'email' => $email,
                'phone' => '+2547000002'.str_pad((string) $index, 2, '0', STR_PAD_LEFT),
                'source' => 'website',
                'utm_source' => 'demo',
                'utm_medium' => 'website',
                'utm_campaign' => 'demo-supermarket-launch',
                'landing_page' => '/',
                'consent_marketing' => true,
                'consent_transactional' => true,
                'consent_at' => now()->subDays(6 - $index),
                'last_activity_at' => now()->subDays(6 - $index),
                'created_at' => now()->subDays(6 - $index),
                'updated_at' => now()->subDays(6 - $index),
            ]);

            ConversionEvent::firstOrCreate(
                ['event_name' => ['quote' => 'quote_request', 'pos_demo' => 'pos_demo_request', 'contact' => 'contact_submission'][$type] ?? $type.'_request', 'lead_id' => $lead->id],
                [
                    'campaign_id' => $campaign->id,
                    'occurred_at' => now()->subDays(6 - $index),
                    'consent_state' => ['analytics' => true, 'basis' => 'form_submission'],
                    'metadata' => ['form' => $type, 'path' => '/', 'reference' => $reference],
                    'created_at' => now()->subDays(6 - $index),
                ],
            );
        }

        $paths = ['/', '/stocktaking', '/stocktaking/supermarkets', '/pos'];
        foreach (range(1, 3) as $visitor) {
            $session = 'demo-visit-'.$visitor;
            TrackingPreference::updateOrCreate(['session_id' => $session], [
                'analytics' => true,
                'marketing' => false,
                'policy_version' => '2026-11',
            ]);

            foreach (range(0, 5) as $dayOffset) {
                foreach ($paths as $pathIndex => $path) {
                    if (($visitor + $dayOffset + $pathIndex) % 3 !== 0 && $pathIndex > 0) {
                        continue;
                    }
                    AnalyticsEvent::create([
                        'event_name' => 'page_view',
                        'occurred_at' => now()->subDays($dayOffset)->setTime(9 + $pathIndex, 15),
                        'session_id' => $session,
                        'campaign_id' => $campaign->id,
                        'path' => $path,
                        'utm' => ['utm_source' => 'demo', 'utm_medium' => 'website', 'utm_campaign' => 'demo-supermarket-launch'],
                        'device' => $visitor === 2 ? 'mobile' : 'desktop',
                        'consent_state' => ['analytics' => true, 'policy_version' => '2026-11', 'basis' => 'banner'],
                        'created_at' => now()->subDays($dayOffset),
                    ]);
                }
            }
        }

        AnalyticsEvent::create([
            'event_name' => 'cta_click',
            'occurred_at' => now()->subDay()->setTime(11, 20),
            'session_id' => 'demo-visit-1',
            'campaign_id' => $campaign->id,
            'path' => '/',
            'utm' => ['utm_source' => 'demo', 'utm_medium' => 'website', 'utm_campaign' => 'demo-supermarket-launch'],
            'device' => 'desktop',
            'consent_state' => ['analytics' => true, 'policy_version' => '2026-11', 'basis' => 'banner'],
            'payload' => ['label' => 'Request a stock-taking quote'],
            'created_at' => now()->subDay(),
        ]);
    }

    private function seedAutomationDemo(): void
    {
        $marketing = User::where('email', 'marketing@brathamatu.test')->first();

        $workflow = AutomationWorkflow::firstOrCreate(['name' => 'Quote follow-up'], [
            'description' => 'Acknowledge quotation and POS demo requests, wait 30 minutes, then prepare the follow-up.',
            'trigger' => 'lead.created',
            'status' => 'active',
            'conditions' => ['lead_type' => ['quote', 'pos_demo']],
            'created_by' => $marketing?->id,
            'updated_by' => $marketing?->id,
            'activated_at' => now()->subDays(5),
        ]);

        if ($workflow->steps()->exists()) {
            return;
        }

        $steps = [
            ['type' => 'notify_internal', 'config' => [
                'recipients' => 'lead_managers',
                'title' => 'New {type} request: {reference}',
                'body' => '{contact_name} · {business_name}',
            ]],
            ['type' => 'wait', 'config' => ['minutes' => 30]],
            ['type' => 'add_activity', 'config' => [
                'body' => 'Automation: call {contact_name} about the {type} request ({reference}).',
            ]],
            ['type' => 'notify_external', 'config' => [
                'channel' => 'email',
                'title' => 'Next step with BRATHAMATU',
                'body' => 'Hello {contact_name}, thank you for your request {reference}.',
            ]],
        ];

        foreach ($steps as $index => $step) {
            AutomationStep::create([
                'workflow_id' => $workflow->id,
                'position' => $index + 1,
                'type' => $step['type'],
                'config' => $step['config'],
            ]);
        }
    }
}
