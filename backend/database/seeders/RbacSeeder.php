<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Seeder;

class RbacSeeder extends Seeder
{
    /**
     * Permission catalogue from master spec §2.4 plus domain extensions.
     */
    private const PERMISSIONS = [
        'tenant.view', 'tenant.update', 'tenant.users.manage', 'tenant.approve',
        'branch.view', 'branch.create', 'branch.update',
        'catalogue.view', 'catalogue.create', 'catalogue.update', 'catalogue.import', 'catalogue.confirm',
        'catalogue.snapshot.view',
        'inventory.view', 'inventory.movement.create', 'inventory.adjust', 'inventory.snapshot.create',
        'stocktake.schedule.manage',
        'stocktake.request.create', 'stocktake.request.submit', 'stocktake.request.cancel',
        'stocktake.quote.view', 'stocktake.quote.accept', 'stocktake.quote.issue',
        'stocktake.job.review', 'stocktake.job.approve', 'stocktake.job.assign', 'stocktake.job.monitor',
        'stocktake.count.view', 'stocktake.count.submit',
        'stocktake.count.recount.request', 'stocktake.results.review', 'stocktake.results.accept',
        'stocktake.dispute.create', 'stocktake.dispute.resolve',
        'finance.invoice.view', 'finance.payment.record', 'finance.refund.approve',
        'finance.ledger.view', 'finance.payout.prepare', 'finance.payout.approve',
        'pos.sales.create', 'pos.refund.create', 'pos.shift.open', 'pos.shift.close', 'pos.manage',
        'report.view', 'report.export',
        'audit.view', 'settings.manage', 'integration.manage', 'pricing.manage',
        'cms.view', 'cms.edit', 'cms.review', 'cms.publish', 'media.manage', 'seo.manage',
        'blog.manage',
        'integrations.google.manage', 'integrations.meta.manage',
        'social.publish', 'social.approve',
        'campaigns.view', 'campaigns.manage', 'campaigns.approve', 'campaigns.spend',
        'analytics.view',
        'automation.view', 'automation.manage',
        'leads.view', 'leads.manage', 'leads.export',
        'marketing.audit.view', 'marketing.settings.manage',
        'support.view', 'support.manage', 'support.request',
        'workforce.view', 'workforce.manage', 'workforce.verify', 'workforce.profile.view',
        'assignment.view', 'assignment.accept', 'sop.acknowledge', 'job.checkin', 'sop.manage',
        'count.exception.create', 'earnings.view', 'qa.review', 'verification.manage',
    ];

    private const WORKER_BASE = [
        'workforce.profile.view', 'assignment.view', 'assignment.accept', 'sop.acknowledge',
        'stocktake.count.view', 'stocktake.count.submit', 'job.checkin',
        'count.exception.create', 'earnings.view',
    ];

    public function run(): void
    {
        foreach (self::PERMISSIONS as $slug) {
            Permission::firstOrCreate(['slug' => $slug]);
        }

        $all = self::PERMISSIONS;

        $tenantAll = array_values(array_filter($all, fn ($p) => ! in_array($p, [
            'tenant.approve', 'stocktake.job.review', 'stocktake.job.approve', 'stocktake.job.assign',
            'stocktake.job.monitor', 'stocktake.quote.issue', 'stocktake.dispute.resolve',
            'finance.ledger.view', 'finance.payout.prepare', 'finance.payout.approve',
            'workforce.view', 'workforce.manage', 'workforce.verify', 'qa.review', 'verification.manage',
            'pricing.manage', 'settings.manage',
            'cms.view', 'cms.edit', 'cms.review', 'cms.publish', 'media.manage', 'seo.manage',
            'blog.manage', 'integrations.google.manage', 'integrations.meta.manage',
            'leads.view', 'leads.manage', 'leads.export',
            'marketing.audit.view', 'marketing.settings.manage',
            'social.publish', 'social.approve',
            'campaigns.view', 'campaigns.manage', 'campaigns.approve', 'campaigns.spend', 'analytics.view',
            'automation.view', 'automation.manage',
            'support.view', 'support.manage', 'assignment.view', 'assignment.accept',
            'sop.acknowledge', 'job.checkin', 'count.exception.create', 'earnings.view',
            'workforce.profile.view', 'audit.view',
        ], true)));

        $roles = [
            // Platform
            ['super-admin', 'Platform Super Admin', 'platform', $all],
            ['platform-owner', 'Platform Owner / Director', 'platform', ['report.view', 'report.export', 'finance.ledger.view', 'audit.view', 'tenant.view', 'stocktake.job.monitor', 'pricing.manage', 'settings.manage', 'campaigns.view', 'campaigns.approve', 'analytics.view', 'automation.view', 'automation.manage']],
            ['operations-manager', 'Platform Operations Manager', 'platform', ['tenant.view', 'tenant.approve', 'stocktake.job.review', 'stocktake.job.approve', 'stocktake.job.assign', 'stocktake.job.monitor', 'stocktake.request.create', 'stocktake.request.submit', 'workforce.view', 'workforce.manage', 'stocktake.schedule.manage', 'report.view', 'catalogue.view', 'stocktake.quote.view', 'stocktake.quote.issue', 'sop.manage']],
            ['job-reviewer', 'Job Reviewer', 'platform', ['stocktake.job.review', 'catalogue.view', 'stocktake.quote.view', 'stocktake.request.submit', 'tenant.view']],
            ['workforce-manager', 'Workforce Manager', 'platform', ['workforce.view', 'workforce.manage', 'workforce.verify', 'stocktake.job.assign', 'assignment.view', 'report.view', 'sop.manage']],
            ['supervisor', 'Stock-taking Supervisor', 'platform', ['stocktake.job.monitor', 'stocktake.count.view', 'qa.review', 'stocktake.count.recount.request', 'verification.manage', 'workforce.view', 'sop.manage']],
            ['finance-manager', 'Platform Finance Manager', 'platform', ['finance.invoice.view', 'finance.payment.record', 'finance.refund.approve', 'finance.ledger.view', 'finance.payout.prepare', 'finance.payout.approve', 'stocktake.quote.view', 'stocktake.quote.issue', 'pricing.manage', 'report.view', 'report.export', 'tenant.view']],
            ['finance-officer', 'Finance Officer', 'platform', ['finance.invoice.view', 'finance.payment.record', 'finance.ledger.view', 'finance.payout.prepare', 'report.view', 'tenant.view']],
            ['support-manager', 'Support Manager', 'platform', ['support.view', 'support.manage', 'stocktake.dispute.resolve', 'tenant.view']],
            ['support-agent', 'Support Agent', 'platform', ['support.view', 'tenant.view']],
            ['auditor', 'Auditor / Compliance Officer', 'platform', ['audit.view', 'report.view', 'report.export', 'finance.ledger.view', 'stocktake.job.monitor', 'tenant.view']],
            ['integration-admin', 'Integration Administrator', 'platform', ['integration.manage', 'tenant.view']],
            ['content-manager', 'Content / Marketing Manager', 'platform', ['cms.view', 'cms.edit', 'cms.review', 'cms.publish', 'media.manage', 'seo.manage', 'blog.manage', 'leads.view', 'leads.manage', 'leads.export', 'marketing.audit.view', 'marketing.settings.manage', 'social.publish', 'social.approve', 'campaigns.view', 'campaigns.manage', 'campaigns.approve', 'campaigns.spend', 'analytics.view', 'automation.view', 'automation.manage']],
            ['marketing-manager', 'Marketing Manager', 'platform', ['cms.view', 'cms.edit', 'cms.review', 'cms.publish', 'media.manage', 'seo.manage', 'blog.manage', 'leads.view', 'leads.manage', 'leads.export', 'marketing.audit.view', 'marketing.settings.manage', 'social.publish', 'social.approve', 'campaigns.view', 'campaigns.manage', 'campaigns.approve', 'campaigns.spend', 'analytics.view', 'automation.view', 'automation.manage']],
            ['content-editor', 'Content Editor', 'platform', ['cms.view', 'cms.edit', 'media.manage', 'seo.manage', 'blog.manage', 'leads.view', 'social.publish', 'campaigns.view', 'campaigns.manage']],
            ['content-publisher', 'Content Publisher', 'platform', ['cms.view', 'cms.edit', 'cms.review', 'cms.publish', 'media.manage', 'seo.manage', 'blog.manage', 'leads.view', 'leads.manage', 'social.publish', 'campaigns.view', 'campaigns.manage']],
            ['seo-manager', 'SEO Manager', 'platform', ['cms.view', 'cms.edit', 'media.manage', 'seo.manage', 'blog.manage', 'leads.view', 'social.publish', 'campaigns.view', 'campaigns.manage', 'analytics.view', 'automation.view']],
            ['read-only-executive', 'Read-only Executive', 'platform', ['report.view', 'campaigns.view', 'analytics.view', 'automation.view']],
            ['lead-agent', 'Lead Agent', 'platform', ['leads.view', 'leads.manage', 'campaigns.view', 'analytics.view', 'automation.view']],
            ['marketing-integration-admin', 'Marketing Integration Administrator', 'platform', ['integrations.google.manage', 'integrations.meta.manage', 'cms.view', 'marketing.audit.view', 'social.publish', 'social.approve', 'campaigns.view', 'analytics.view', 'automation.view', 'automation.manage']],

            // Tenant
            ['business-owner', 'Business Owner', 'tenant', $tenantAll],
            ['business-admin', 'Business Administrator', 'tenant', ['tenant.view', 'tenant.update', 'tenant.users.manage', 'branch.view', 'branch.create', 'branch.update', 'catalogue.view', 'catalogue.create', 'catalogue.update', 'catalogue.import', 'catalogue.confirm', 'catalogue.snapshot.view', 'inventory.view', 'inventory.movement.create', 'inventory.snapshot.create', 'stocktake.request.create', 'stocktake.request.submit', 'stocktake.request.cancel', 'stocktake.quote.view', 'integration.manage', 'stocktake.quote.accept', 'stocktake.schedule.manage', 'stocktake.results.review', 'stocktake.results.accept', 'stocktake.dispute.create', 'report.view', 'report.export', 'finance.invoice.view', 'finance.payment.record', 'support.request']],
            ['business-finance-manager', 'Business Finance Manager', 'tenant', ['tenant.view', 'finance.invoice.view', 'finance.payment.record', 'stocktake.quote.view', 'stocktake.quote.accept', 'report.view', 'report.export', 'support.request']],
            ['branch-manager', 'Branch Manager', 'tenant', ['branch.view', 'branch.update', 'catalogue.view', 'stocktake.request.create', 'stocktake.request.submit', 'stocktake.results.review', 'stocktake.count.view', 'report.view', 'inventory.view', 'support.request']],
            ['inventory-manager', 'Inventory Manager', 'tenant', ['catalogue.view', 'catalogue.create', 'catalogue.update', 'catalogue.import', 'catalogue.confirm', 'catalogue.snapshot.view', 'inventory.view', 'inventory.movement.create', 'inventory.adjust', 'inventory.snapshot.create', 'branch.view', 'stocktake.request.create', 'report.view', 'support.request']],
            ['stock-controller', 'Stock Controller', 'tenant', ['catalogue.view', 'inventory.view', 'inventory.movement.create', 'stocktake.count.view', 'branch.view', 'support.request']],
            ['department-manager', 'Department / Warehouse Manager', 'tenant', ['branch.view', 'catalogue.view', 'stocktake.count.view', 'report.view', 'inventory.view', 'support.request']],
            ['job-requester', 'Job Requester', 'tenant', ['stocktake.request.create', 'stocktake.request.submit', 'catalogue.view', 'branch.view', 'support.request']],
            ['results-reviewer', 'Results Reviewer', 'tenant', ['stocktake.results.review', 'stocktake.results.accept', 'stocktake.count.recount.request', 'stocktake.dispute.create', 'stocktake.count.view', 'report.view', 'support.request']],
            ['tenant-auditor', 'Tenant Auditor', 'tenant', ['report.view', 'catalogue.view', 'stocktake.count.view', 'tenant.view']],
            ['pos-manager', 'POS Manager', 'tenant', ['pos.sales.create', 'pos.refund.create', 'pos.shift.open', 'pos.shift.close', 'pos.manage', 'integration.manage', 'catalogue.view', 'inventory.view', 'report.view', 'stocktake.request.create', 'branch.view', 'support.request']],
            ['pos-cashier', 'POS Cashier', 'tenant', ['pos.sales.create', 'pos.shift.open', 'pos.shift.close', 'pos.refund.create', 'catalogue.view']],
            ['tenant-read-only', 'Tenant Read-only User', 'tenant', ['tenant.view', 'branch.view', 'catalogue.view', 'report.view']],

            // Workforce
            ['applicant', 'Applicant', 'workforce', ['workforce.profile.view']],
            ['trainee-stock-taker', 'Trainee Stock Taker', 'workforce', self::WORKER_BASE],
            ['verified-stock-taker', 'Verified Stock Taker', 'workforce', self::WORKER_BASE],
            ['professional-stock-taker', 'Professional Stock Taker', 'workforce', self::WORKER_BASE],
            ['senior-stock-taker', 'Senior Stock Taker', 'workforce', self::WORKER_BASE],
            ['workforce-supervisor', 'Stock-taking Supervisor (Workforce)', 'workforce', array_merge(self::WORKER_BASE, ['qa.review', 'verification.manage'])],
            ['agency-manager', 'Agency Manager', 'workforce', ['workforce.profile.view', 'workforce.view', 'assignment.view']],
            ['agency-stock-taker', 'Agency Stock Taker', 'workforce', self::WORKER_BASE],
        ];

        foreach ($roles as [$slug, $name, $scope, $perms]) {
            $role = Role::firstOrCreate(['slug' => $slug], ['name' => $name, 'scope' => $scope]);
            $ids = Permission::whereIn('slug', $perms)->pluck('id');
            $role->permissions()->sync($ids);
        }
    }
}
