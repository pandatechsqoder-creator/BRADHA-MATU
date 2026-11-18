<?php

namespace App\Services\Reporting;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

final class AnalyticsService
{
    /**
     * Tenant-facing analytics overview: KPIs, trends and deterministic insights.
     *
     * @return array<string, mixed>
     */
    public function tenantOverview(Tenant $tenant, ?User $user = null): array
    {
        $stockValue = (float) DB::table('inventory_balances as b')
            ->join('product_variants as v', 'v.id', '=', 'b.variant_id')
            ->join('products as p', 'p.id', '=', 'v.product_id')
            ->where('b.tenant_id', $tenant->id)
            ->selectRaw('COALESCE(SUM(b.quantity * COALESCE(p.cost_price, 0)), 0) as value')
            ->value('value');

        $products = DB::table('products')->where('tenant_id', $tenant->id)->where('status', 'active')->count();

        $jobs = DB::table('stocktake_jobs')
            ->where('tenant_id', $tenant->id)
            ->selectRaw('COUNT(*) as total')
            ->selectRaw("SUM(CASE WHEN status IN ('completed','closed') THEN 1 ELSE 0 END) as completed")
            ->selectRaw("SUM(CASE WHEN status IN ('completed','closed','cancelled') THEN 0 ELSE 1 END) as active")
            ->first();

        $sales30 = DB::table('pos_sales')
            ->where('tenant_id', $tenant->id)
            ->where('created_at', '>=', now()->subDays(30))
            ->whereNotIn('status', ['voided', 'refunded'])
            ->selectRaw('COUNT(*) as count, COALESCE(SUM(total_amount), 0) as gross')
            ->first();

        $openDisputes = DB::table('discrepancies')
            ->where('tenant_id', $tenant->id)
            ->where('status', 'open')
            ->count();

        $openTickets = DB::table('support_tickets')
            ->where('tenant_id', $tenant->id)
            ->whereNotIn('status', ['resolved', 'closed'])
            ->count();

        $unread = $user ? DB::table('notifications')->where('user_id', $user->id)->whereNull('read_at')->count() : 0;

        $salesSeries = $this->dailySeries('pos_sales', 'total_amount', $tenant->id, 14, ['voided', 'refunded']);
        $jobSeries = $this->dailySeries('stocktake_jobs', null, $tenant->id, 14);

        $topProducts = DB::table('inventory_balances as b')
            ->join('product_variants as v', 'v.id', '=', 'b.variant_id')
            ->join('products as p', 'p.id', '=', 'v.product_id')
            ->where('b.tenant_id', $tenant->id)
            ->groupBy('p.name')
            ->orderByRaw('SUM(b.quantity * COALESCE(p.cost_price, 0)) DESC')
            ->limit(5)
            ->get([DB::raw('p.name as label'), DB::raw('COALESCE(SUM(b.quantity * COALESCE(p.cost_price, 0)), 0) as value')]);

        return $this->payload('tenant', $tenant->name, $user, [
            ['key' => 'stock_value', 'label' => 'Stock value', 'value' => round($stockValue, 2), 'format' => 'money', 'hint' => 'Balances × cost price'],
            ['key' => 'active_products', 'label' => 'Active products', 'value' => $products, 'format' => 'number', 'hint' => 'Catalogue items'],
            ['key' => 'active_jobs', 'label' => 'Active stock-takes', 'value' => (int) ($jobs->active ?? 0), 'format' => 'number', 'hint' => (int) ($jobs->completed ?? 0).' completed to date'],
            ['key' => 'sales_30d', 'label' => 'POS sales (30d)', 'value' => round((float) ($sales30->gross ?? 0), 2), 'format' => 'money', 'hint' => (int) ($sales30->count ?? 0).' receipts'],
            ['key' => 'open_disputes', 'label' => 'Open discrepancies', 'value' => $openDisputes, 'format' => 'number', 'hint' => 'Awaiting investigation'],
            ['key' => 'open_tickets', 'label' => 'Open support tickets', 'value' => $openTickets, 'format' => 'number', 'hint' => 'Not resolved or closed'],
            ['key' => 'unread_notifications', 'label' => 'Unread notifications', 'value' => $unread, 'format' => 'number', 'hint' => 'For your account'],
        ], [
            ['key' => 'sales', 'label' => 'POS sales', 'format' => 'money', 'points' => $salesSeries],
            ['key' => 'jobs', 'label' => 'Stock-take activity', 'format' => 'number', 'points' => $jobSeries],
        ], [
            ['key' => 'stock_value_by_product', 'label' => 'Top stock positions', 'format' => 'money', 'items' => $topProducts->map(fn ($row) => ['label' => $row->label, 'value' => round((float) $row->value, 2)])->all()],
        ], $this->tenantInsights($stockValue, $openDisputes, $openTickets, (float) ($sales30->gross ?? 0)));
    }

    /**
     * Platform-facing analytics overview.
     *
     * @return array<string, mixed>
     */
    public function platformOverview(?User $user = null): array
    {
        $tenants = DB::table('tenants')
            ->selectRaw('COUNT(*) as total')
            ->selectRaw("SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) as active")
            ->first();

        $jobs = DB::table('stocktake_jobs')
            ->selectRaw('COUNT(*) as total')
            ->selectRaw("SUM(CASE WHEN status IN ('completed','closed') THEN 1 ELSE 0 END) as completed")
            ->selectRaw("SUM(CASE WHEN status IN ('completed','closed','cancelled') THEN 0 ELSE 1 END) as active")
            ->first();

        $revenue30 = DB::table('payments')
            ->where('created_at', '>=', now()->subDays(30))
            ->whereIn('status', ['succeeded', 'paid', 'completed', 'verified'])
            ->sum('amount');

        $openTickets = DB::table('support_tickets')->whereNotIn('status', ['resolved', 'closed'])->count();
        $overdueTickets = DB::table('support_tickets')
            ->whereNull('first_response_at')
            ->whereNotIn('status', ['resolved', 'closed'])
            ->where('sla_due_at', '<', now())
            ->count();

        $unread = $user ? DB::table('notifications')->where('user_id', $user->id)->whereNull('read_at')->count() : 0;

        $revenueSeries = DB::table('payments')
            ->where('created_at', '>=', now()->subDays(14)->startOfDay())
            ->whereIn('status', ['succeeded', 'paid', 'completed', 'verified'])
            ->selectRaw('DATE(created_at) as day, COALESCE(SUM(amount), 0) as value')
            ->groupBy('day')
            ->orderBy('day')
            ->get()
            ->keyBy('day');

        $jobSeries = $this->dailySeries('stocktake_jobs', null, null, 14);

        $tenantsByMode = DB::table('tenants')
            ->selectRaw('operating_mode as label, COUNT(*) as value')
            ->groupBy('operating_mode')
            ->orderByDesc('value')
            ->get()
            ->map(fn ($row) => ['label' => $row->label, 'value' => (int) $row->value])
            ->all();

        return $this->payload('platform', null, $user, [
            ['key' => 'tenants', 'label' => 'Tenants', 'value' => (int) ($tenants->total ?? 0), 'format' => 'number', 'hint' => (int) ($tenants->active ?? 0).' active'],
            ['key' => 'active_jobs', 'label' => 'Active stock-takes', 'value' => (int) ($jobs->active ?? 0), 'format' => 'number', 'hint' => (int) ($jobs->completed ?? 0).' completed to date'],
            ['key' => 'revenue_30d', 'label' => 'Collected (30d)', 'value' => round((float) $revenue30, 2), 'format' => 'money', 'hint' => 'Verified payments'],
            ['key' => 'open_tickets', 'label' => 'Open support tickets', 'value' => $openTickets, 'format' => 'number', 'hint' => $overdueTickets.' past SLA'],
            ['key' => 'unread_notifications', 'label' => 'Unread notifications', 'value' => $unread, 'format' => 'number', 'hint' => 'For your account'],
        ], [
            ['key' => 'revenue', 'label' => 'Collected revenue', 'format' => 'money', 'points' => $this->fillSeries($revenueSeries, 14)],
            ['key' => 'jobs', 'label' => 'Stock-take activity', 'format' => 'number', 'points' => $jobSeries],
        ], [
            ['key' => 'tenants_by_mode', 'label' => 'Tenants by operating mode', 'format' => 'number', 'items' => $tenantsByMode],
        ], $this->platformInsights($openTickets, $overdueTickets, (int) ($tenants->total ?? 0)));
    }

    /**
     * Declares the analytics/AI interface surface. Providers stay unconfigured
     * until a tenant opts in and credentials exist.
     *
     * @return array<string, mixed>
     */
    public function intelligence(): array
    {
        return [
            'enabled' => (bool) config('services.ai.enabled', false),
            'provider' => config('services.ai.provider'),
            'interfaces' => [
                ['key' => 'demand_forecast', 'label' => 'Demand forecasting', 'status' => 'available', 'description' => 'Projects SKU demand from POS and movement history.'],
                ['key' => 'variance_anomaly', 'label' => 'Variance anomaly detection', 'status' => 'available', 'description' => 'Flags stock-take variances outside expected tolerance.'],
                ['key' => 'pricing_recommendation', 'label' => 'Pricing recommendations', 'status' => 'preview', 'description' => 'Suggests price adjustments from cost and sales velocity.'],
                ['key' => 'support_triage', 'label' => 'Support triage', 'status' => 'preview', 'description' => 'Classifies and routes incoming tickets by urgency.'],
            ],
            'note' => 'No AI provider is configured. Analytics insights shown here are deterministic aggregates, not model output.',
        ];
    }

    private function payload(string $scope, ?string $tenantName, ?User $user, array $kpis, array $series, array $breakdowns, array $insights): array
    {
        return [
            'scope' => $scope,
            'tenant' => $tenantName,
            'user' => $user?->name,
            'generated_at' => now()->toIso8601String(),
            'kpis' => $kpis,
            'series' => $series,
            'breakdowns' => $breakdowns,
            'insights' => $insights,
            'ai' => $this->intelligence(),
        ];
    }

    /**
     * @return array<int, array{date: string, value: float|int}>
     */
    private function dailySeries(string $table, ?string $amountColumn, ?int $tenantId, int $days, array $excludedStatuses = []): array
    {
        $query = DB::table($table)
            ->where('created_at', '>=', now()->subDays($days - 1)->startOfDay());

        if ($tenantId !== null) {
            $query->where('tenant_id', $tenantId);
        }
        if ($excludedStatuses !== []) {
            $query->whereNotIn('status', $excludedStatuses);
        }

        $value = $amountColumn
            ? 'COALESCE(SUM('.$amountColumn.'), 0)'
            : 'COUNT(*)';

        $rows = $query
            ->selectRaw("DATE(created_at) as day, {$value} as value")
            ->groupBy('day')
            ->orderBy('day')
            ->get()
            ->keyBy('day');

        return $this->fillSeries($rows, $days, $amountColumn !== null);
    }

    /**
     * @return array<int, array{date: string, value: float|int}>
     */
    private function fillSeries($rows, int $days, bool $decimal = false): array
    {
        $points = [];
        for ($i = $days - 1; $i >= 0; $i--) {
            $date = Carbon::today()->subDays($i)->toDateString();
            $raw = $rows[$date]->value ?? 0;
            $points[] = ['date' => $date, 'value' => $decimal ? round((float) $raw, 2) : (int) $raw];
        }

        return $points;
    }

    private function tenantInsights(float $stockValue, int $openDisputes, int $openTickets, float $sales30): array
    {
        $insights = [];

        if ($openDisputes > 0) {
            $insights[] = [
                'key' => 'discrepancies',
                'severity' => $openDisputes >= 10 ? 'warning' : 'info',
                'title' => "{$openDisputes} ".($openDisputes === 1 ? 'discrepancy needs' : 'discrepancies need').' attention',
                'detail' => 'Review open variances so results can be accepted before the period closes.',
            ];
        }

        if ($openTickets > 0) {
            $insights[] = [
                'key' => 'support',
                'severity' => 'info',
                'title' => "{$openTickets} open support ticket".($openTickets === 1 ? '' : 's'),
                'detail' => 'Track replies in the support centre to keep within SLA.',
            ];
        }

        if ($sales30 === 0.0) {
            $insights[] = [
                'key' => 'sales',
                'severity' => 'info',
                'title' => 'No POS sales recorded in 30 days',
                'detail' => 'If you operate a shop, open a shift and record sales to see daily trends.',
            ];
        }

        $insights[] = [
            'key' => 'stock_value',
            'severity' => 'success',
            'title' => 'Stock valued at '.number_format($stockValue, 2),
            'detail' => 'Based on current balances and catalogue cost prices.',
        ];

        return $insights;
    }

    private function platformInsights(int $openTickets, int $overdue, int $tenants): array
    {
        $insights = [];

        if ($overdue > 0) {
            $insights[] = [
                'key' => 'sla',
                'severity' => 'warning',
                'title' => "{$overdue} ticket".($overdue === 1 ? '' : 's').' past first-response SLA',
                'detail' => 'Assign an agent and reply to restore SLA compliance.',
            ];
        } elseif ($openTickets > 0) {
            $insights[] = [
                'key' => 'tickets',
                'severity' => 'info',
                'title' => "{$openTickets} open support ticket".($openTickets === 1 ? '' : 's'),
                'detail' => 'All within first-response SLA.',
            ];
        }

        $insights[] = [
            'key' => 'tenants',
            'severity' => 'success',
            'title' => "{$tenants} tenants onboarded",
            'detail' => 'Reporting covers tenant activity, jobs and collected revenue.',
        ];

        return $insights;
    }
}
