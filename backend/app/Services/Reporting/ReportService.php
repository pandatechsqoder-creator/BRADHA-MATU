<?php

namespace App\Services\Reporting;

use App\Models\ReportRun;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class ReportService
{
    public const TENANT_REPORTS = [
        'inventory_valuation',
        'stock_movements',
        'pos_sales',
        'stocktake_jobs',
    ];

    public const PLATFORM_REPORTS = [
        'tenant_activity',
        'platform_jobs',
        'platform_revenue',
    ];

    public function __construct(private readonly AuditLogger $audit) {}

    public function availableReports(string $scope): array
    {
        return $scope === 'platform' ? self::PLATFORM_REPORTS : self::TENANT_REPORTS;
    }

    /**
     * @return array{report_type: string, scope: string, period: array, columns: array, rows: array, summary: array, row_count: int, generated_at: string}
     */
    public function generate(string $scope, string $type, ?Tenant $tenant, array $filters, ?User $user, string $format = 'json'): array
    {
        $allowed = $this->availableReports($scope);
        if (! in_array($type, $allowed, true)) {
            throw ValidationException::withMessages(['type' => 'Unknown report type.']);
        }

        [$from, $to] = $this->period($filters);
        $limit = min(max((int) ($filters['limit'] ?? 1000), 1), 5000);

        $report = $scope === 'platform'
            ? $this->platformReport($type, $from, $to, $limit)
            : $this->tenantReport($tenant, $type, $from, $to, $limit);

        $payload = [
            'report_type' => $type,
            'scope' => $scope,
            'period' => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
            'columns' => $report['columns'],
            'rows' => $report['rows'],
            'summary' => $report['summary'],
            'row_count' => count($report['rows']),
            'generated_at' => now()->toIso8601String(),
        ];

        $run = ReportRun::create([
            'tenant_id' => $tenant?->id,
            'user_id' => $user?->id,
            'report_type' => $type,
            'format' => $format,
            'filters' => ['from' => $payload['period']['from'], 'to' => $payload['period']['to'], 'limit' => $limit],
            'row_count' => $payload['row_count'],
            'generated_at' => now(),
        ]);

        if ($scope === 'platform' && $run->tenant_id !== null) {
            $run->forceFill(['tenant_id' => null])->saveQuietly();
        }

        $log = $this->audit->log('report.generated', null, null, null, [
            'report_type' => $type,
            'scope' => $scope,
            'format' => $format,
            'row_count' => $payload['row_count'],
        ], $tenant?->id, $user?->id);

        if ($scope === 'platform' && $log->tenant_id !== null) {
            $log->forceFill(['tenant_id' => null])->saveQuietly();
        }

        return $payload;
    }

    public function toCsv(array $report): string
    {
        $handle = fopen('php://temp', 'r+');
        fputcsv($handle, array_map(fn (array $column) => $column['label'], $report['columns']), ',', '"', '\\');

        foreach ($report['rows'] as $row) {
            fputcsv(
                $handle,
                array_map(fn (array $column) => $row[$column['key']] ?? '', $report['columns']),
                ',',
                '"',
                '\\',
            );
        }

        rewind($handle);
        $csv = (string) stream_get_contents($handle);
        fclose($handle);

        return "\xEF\xBB\xBF".$csv;
    }

    public function auditExport(string $scope, ?Tenant $tenant, User $user, string $type): void
    {
        $log = $this->audit->log('report.exported', null, null, null, [
            'report_type' => $type,
            'scope' => $scope,
        ], $tenant?->id, $user->id);

        if ($scope === 'platform' && $log->tenant_id !== null) {
            $log->forceFill(['tenant_id' => null])->saveQuietly();
        }
    }

    private function period(array $filters): array
    {
        $from = isset($filters['from']) ? Carbon::parse($filters['from'])->startOfDay() : now()->subDays(30)->startOfDay();
        $to = isset($filters['to']) ? Carbon::parse($filters['to'])->endOfDay() : now()->endOfDay();

        if ($from->greaterThan($to)) {
            throw ValidationException::withMessages(['from' => 'The start date must be before the end date.']);
        }

        return [$from, $to];
    }

    private function tenantReport(?Tenant $tenant, string $type, Carbon $from, Carbon $to, int $limit): array
    {
        return match ($type) {
            'inventory_valuation' => $this->inventoryValuation($tenant, $limit),
            'stock_movements' => $this->stockMovements($tenant, $from, $to, $limit),
            'pos_sales' => $this->posSales($tenant, $from, $to, $limit),
            'stocktake_jobs' => $this->stocktakeJobs($tenant, $from, $to, $limit),
        };
    }

    private function inventoryValuation(Tenant $tenant, int $limit): array
    {
        $rows = DB::table('inventory_balances as b')
            ->join('product_variants as v', 'v.id', '=', 'b.variant_id')
            ->join('products as p', 'p.id', '=', 'v.product_id')
            ->leftJoin('stock_locations as l', 'l.id', '=', 'b.stock_location_id')
            ->where('b.tenant_id', $tenant->id)
            ->orderByRaw('(b.quantity * COALESCE(p.cost_price, 0)) DESC')
            ->limit($limit)
            ->get([
                'v.sku',
                'p.name as product',
                'l.name as location',
                'b.quantity',
                'p.cost_price',
            ])
            ->map(fn ($row) => [
                'sku' => $row->sku,
                'product' => $row->product,
                'location' => $row->location ?? 'Default',
                'quantity' => round((float) $row->quantity, 3),
                'cost_price' => round((float) ($row->cost_price ?? 0), 2),
                'value' => round((float) $row->quantity * (float) ($row->cost_price ?? 0), 2),
            ])
            ->all();

        $total = array_sum(array_column($rows, 'value'));

        return [
            'columns' => [
                ['key' => 'sku', 'label' => 'SKU', 'type' => 'string'],
                ['key' => 'product', 'label' => 'Product', 'type' => 'string'],
                ['key' => 'location', 'label' => 'Location', 'type' => 'string'],
                ['key' => 'quantity', 'label' => 'Quantity', 'type' => 'number'],
                ['key' => 'cost_price', 'label' => 'Unit cost', 'type' => 'money'],
                ['key' => 'value', 'label' => 'Stock value', 'type' => 'money'],
            ],
            'rows' => $rows,
            'summary' => [
                ['key' => 'total_value', 'label' => 'Total stock value', 'value' => round($total, 2), 'format' => 'money'],
                ['key' => 'item_count', 'label' => 'Stock lines', 'value' => count($rows), 'format' => 'number'],
                ['key' => 'total_quantity', 'label' => 'Total quantity', 'value' => round(array_sum(array_column($rows, 'quantity')), 3), 'format' => 'number'],
            ],
        ];
    }

    private function stockMovements(Tenant $tenant, Carbon $from, Carbon $to, int $limit): array
    {
        $rows = DB::table('inventory_movement_lines as ml')
            ->join('inventory_movements as m', 'm.id', '=', 'ml.movement_id')
            ->join('product_variants as v', 'v.id', '=', 'ml.variant_id')
            ->join('products as p', 'p.id', '=', 'v.product_id')
            ->where('m.tenant_id', $tenant->id)
            ->whereBetween('m.occurred_at', [$from, $to])
            ->orderByDesc('m.occurred_at')
            ->limit($limit)
            ->get([
                'm.occurred_at',
                'm.reference',
                'm.type',
                'm.source',
                'v.sku',
                'p.name as product',
                'ml.quantity',
                'ml.unit_cost',
            ])
            ->map(fn ($row) => [
                'occurred_at' => Carbon::parse($row->occurred_at)->toDateString(),
                'reference' => $row->reference,
                'type' => $row->type,
                'source' => $row->source,
                'sku' => $row->sku,
                'product' => $row->product,
                'quantity' => round((float) $row->quantity, 3),
                'unit_cost' => round((float) ($row->unit_cost ?? 0), 2),
            ])
            ->all();

        $inbound = array_sum(array_map(fn ($row) => max($row['quantity'], 0), $rows));
        $outbound = abs(array_sum(array_map(fn ($row) => min($row['quantity'], 0), $rows)));

        return [
            'columns' => [
                ['key' => 'occurred_at', 'label' => 'Date', 'type' => 'date'],
                ['key' => 'reference', 'label' => 'Reference', 'type' => 'string'],
                ['key' => 'type', 'label' => 'Type', 'type' => 'string'],
                ['key' => 'source', 'label' => 'Source', 'type' => 'string'],
                ['key' => 'sku', 'label' => 'SKU', 'type' => 'string'],
                ['key' => 'product', 'label' => 'Product', 'type' => 'string'],
                ['key' => 'quantity', 'label' => 'Quantity', 'type' => 'number'],
                ['key' => 'unit_cost', 'label' => 'Unit cost', 'type' => 'money'],
            ],
            'rows' => $rows,
            'summary' => [
                ['key' => 'movement_lines', 'label' => 'Movement lines', 'value' => count($rows), 'format' => 'number'],
                ['key' => 'inbound', 'label' => 'Units in', 'value' => round($inbound, 3), 'format' => 'number'],
                ['key' => 'outbound', 'label' => 'Units out', 'value' => round($outbound, 3), 'format' => 'number'],
            ],
        ];
    }

    private function posSales(Tenant $tenant, Carbon $from, Carbon $to, int $limit): array
    {
        $rows = DB::table('pos_sales')
            ->where('tenant_id', $tenant->id)
            ->whereBetween('created_at', [$from, $to])
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get([
                'created_at',
                'reference',
                'receipt_number',
                'status',
                'payment_method',
                'subtotal',
                'tax_amount',
                'discount_amount',
                'total_amount',
            ])
            ->map(fn ($row) => [
                'date' => Carbon::parse($row->created_at)->toDateString(),
                'reference' => $row->reference,
                'receipt' => $row->receipt_number,
                'status' => $row->status,
                'payment_method' => $row->payment_method,
                'subtotal' => round((float) $row->subtotal, 2),
                'tax' => round((float) $row->tax_amount, 2),
                'discount' => round((float) $row->discount_amount, 2),
                'total' => round((float) $row->total_amount, 2),
            ])
            ->all();

        return [
            'columns' => [
                ['key' => 'date', 'label' => 'Date', 'type' => 'date'],
                ['key' => 'reference', 'label' => 'Sale', 'type' => 'string'],
                ['key' => 'receipt', 'label' => 'Receipt', 'type' => 'string'],
                ['key' => 'status', 'label' => 'Status', 'type' => 'string'],
                ['key' => 'payment_method', 'label' => 'Payment', 'type' => 'string'],
                ['key' => 'subtotal', 'label' => 'Subtotal', 'type' => 'money'],
                ['key' => 'tax', 'label' => 'Tax', 'type' => 'money'],
                ['key' => 'discount', 'label' => 'Discount', 'type' => 'money'],
                ['key' => 'total', 'label' => 'Total', 'type' => 'money'],
            ],
            'rows' => $rows,
            'summary' => [
                ['key' => 'sales_count', 'label' => 'Sales', 'value' => count($rows), 'format' => 'number'],
                ['key' => 'gross_total', 'label' => 'Gross total', 'value' => round(array_sum(array_column($rows, 'total')), 2), 'format' => 'money'],
                ['key' => 'tax_total', 'label' => 'Tax', 'value' => round(array_sum(array_column($rows, 'tax')), 2), 'format' => 'money'],
                ['key' => 'discount_total', 'label' => 'Discounts', 'value' => round(array_sum(array_column($rows, 'discount')), 2), 'format' => 'money'],
            ],
        ];
    }

    private function stocktakeJobs(Tenant $tenant, Carbon $from, Carbon $to, int $limit): array
    {
        $rows = DB::table('stocktake_jobs')
            ->where('tenant_id', $tenant->id)
            ->whereBetween('created_at', [$from, $to])
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get([
                'reference',
                'title',
                'status',
                'job_type',
                'mode',
                'quote_total',
                'currency',
                'created_at',
                'accepted_at',
                'closed_at',
            ])
            ->map(fn ($row) => [
                'reference' => $row->reference,
                'title' => $row->title,
                'status' => $row->status,
                'job_type' => $row->job_type,
                'mode' => $row->mode,
                'quote_total' => round((float) ($row->quote_total ?? 0), 2),
                'created_at' => Carbon::parse($row->created_at)->toDateString(),
                'closed_at' => $row->closed_at ? Carbon::parse($row->closed_at)->toDateString() : '',
            ])
            ->all();

        $completed = count(array_filter($rows, fn ($row) => $row['status'] === 'completed'));

        return [
            'columns' => [
                ['key' => 'reference', 'label' => 'Reference', 'type' => 'string'],
                ['key' => 'title', 'label' => 'Title', 'type' => 'string'],
                ['key' => 'status', 'label' => 'Status', 'type' => 'string'],
                ['key' => 'job_type', 'label' => 'Type', 'type' => 'string'],
                ['key' => 'mode', 'label' => 'Mode', 'type' => 'string'],
                ['key' => 'quote_total', 'label' => 'Quote total', 'type' => 'money'],
                ['key' => 'created_at', 'label' => 'Created', 'type' => 'date'],
                ['key' => 'closed_at', 'label' => 'Closed', 'type' => 'date'],
            ],
            'rows' => $rows,
            'summary' => [
                ['key' => 'jobs', 'label' => 'Jobs', 'value' => count($rows), 'format' => 'number'],
                ['key' => 'completed', 'label' => 'Completed', 'value' => $completed, 'format' => 'number'],
                ['key' => 'quote_total', 'label' => 'Quoted value', 'value' => round(array_sum(array_column($rows, 'quote_total')), 2), 'format' => 'money'],
            ],
        ];
    }

    private function platformReport(string $type, Carbon $from, Carbon $to, int $limit): array
    {
        return match ($type) {
            'tenant_activity' => $this->tenantActivity($from, $to, $limit),
            'platform_jobs' => $this->platformJobs($from, $to, $limit),
            'platform_revenue' => $this->platformRevenue($from, $to, $limit),
        };
    }

    private function tenantActivity(Carbon $from, Carbon $to, int $limit): array
    {
        $rows = DB::table('tenants as t')
            ->select('t.name', 't.status', 't.operating_mode', 't.industry', 't.created_at')
            ->selectSub(function ($query): void {
                $query->from('tenant_memberships')
                    ->whereColumn('tenant_memberships.tenant_id', 't.id')
                    ->where('tenant_memberships.status', 'active')
                    ->selectRaw('count(*)');
            }, 'members')
            ->selectSub(function ($query) use ($from, $to): void {
                $query->from('stocktake_jobs')
                    ->whereColumn('stocktake_jobs.tenant_id', 't.id')
                    ->whereBetween('stocktake_jobs.created_at', [$from, $to])
                    ->selectRaw('count(*)');
            }, 'jobs')
            ->orderBy('t.name')
            ->limit($limit)
            ->get()
            ->map(fn ($row) => [
                'tenant' => $row->name,
                'status' => $row->status,
                'operating_mode' => $row->operating_mode,
                'industry' => $row->industry,
                'members' => (int) $row->members,
                'jobs' => (int) $row->jobs,
                'joined' => Carbon::parse($row->created_at)->toDateString(),
            ])
            ->all();

        return [
            'columns' => [
                ['key' => 'tenant', 'label' => 'Tenant', 'type' => 'string'],
                ['key' => 'status', 'label' => 'Status', 'type' => 'string'],
                ['key' => 'operating_mode', 'label' => 'Mode', 'type' => 'string'],
                ['key' => 'industry', 'label' => 'Industry', 'type' => 'string'],
                ['key' => 'members', 'label' => 'Members', 'type' => 'number'],
                ['key' => 'jobs', 'label' => 'Jobs in period', 'type' => 'number'],
                ['key' => 'joined', 'label' => 'Joined', 'type' => 'date'],
            ],
            'rows' => $rows,
            'summary' => [
                ['key' => 'tenants', 'label' => 'Tenants', 'value' => count($rows), 'format' => 'number'],
                ['key' => 'active_tenants', 'label' => 'Active tenants', 'value' => count(array_filter($rows, fn ($row) => $row['status'] === 'active')), 'format' => 'number'],
                ['key' => 'jobs', 'label' => 'Jobs in period', 'value' => array_sum(array_column($rows, 'jobs')), 'format' => 'number'],
            ],
        ];
    }

    private function platformJobs(Carbon $from, Carbon $to, int $limit): array
    {
        $rows = DB::table('stocktake_jobs as j')
            ->join('tenants as t', 't.id', '=', 'j.tenant_id')
            ->whereBetween('j.created_at', [$from, $to])
            ->orderByDesc('j.created_at')
            ->limit($limit)
            ->get(['j.reference', 't.name as tenant', 'j.title', 'j.status', 'j.priority', 'j.quote_total', 'j.created_at'])
            ->map(fn ($row) => [
                'reference' => $row->reference,
                'tenant' => $row->tenant,
                'title' => $row->title,
                'status' => $row->status,
                'priority' => $row->priority,
                'quote_total' => round((float) ($row->quote_total ?? 0), 2),
                'created_at' => Carbon::parse($row->created_at)->toDateString(),
            ])
            ->all();

        return [
            'columns' => [
                ['key' => 'reference', 'label' => 'Reference', 'type' => 'string'],
                ['key' => 'tenant', 'label' => 'Tenant', 'type' => 'string'],
                ['key' => 'title', 'label' => 'Title', 'type' => 'string'],
                ['key' => 'status', 'label' => 'Status', 'type' => 'string'],
                ['key' => 'priority', 'label' => 'Priority', 'type' => 'string'],
                ['key' => 'quote_total', 'label' => 'Quote total', 'type' => 'money'],
                ['key' => 'created_at', 'label' => 'Created', 'type' => 'date'],
            ],
            'rows' => $rows,
            'summary' => [
                ['key' => 'jobs', 'label' => 'Jobs', 'value' => count($rows), 'format' => 'number'],
                ['key' => 'quoted', 'label' => 'Quoted value', 'value' => round(array_sum(array_column($rows, 'quote_total')), 2), 'format' => 'money'],
            ],
        ];
    }

    private function platformRevenue(Carbon $from, Carbon $to, int $limit): array
    {
        $rows = DB::table('payments as p')
            ->join('tenants as t', 't.id', '=', 'p.tenant_id')
            ->whereBetween('p.created_at', [$from, $to])
            ->orderByDesc('p.created_at')
            ->limit($limit)
            ->get(['p.created_at', 't.name as tenant', 'p.purpose', 'p.method', 'p.status', 'p.amount', 'p.currency'])
            ->map(fn ($row) => [
                'date' => Carbon::parse($row->created_at)->toDateString(),
                'tenant' => $row->tenant,
                'purpose' => $row->purpose,
                'method' => $row->method,
                'status' => $row->status,
                'amount' => round((float) $row->amount, 2),
                'currency' => $row->currency,
            ])
            ->all();

        $collected = array_sum(array_map(
            fn ($row) => in_array($row['status'], ['succeeded', 'paid', 'completed', 'verified'], true) ? $row['amount'] : 0,
            $rows,
        ));

        return [
            'columns' => [
                ['key' => 'date', 'label' => 'Date', 'type' => 'date'],
                ['key' => 'tenant', 'label' => 'Tenant', 'type' => 'string'],
                ['key' => 'purpose', 'label' => 'Purpose', 'type' => 'string'],
                ['key' => 'method', 'label' => 'Method', 'type' => 'string'],
                ['key' => 'status', 'label' => 'Status', 'type' => 'string'],
                ['key' => 'amount', 'label' => 'Amount', 'type' => 'money'],
                ['key' => 'currency', 'label' => 'Currency', 'type' => 'string'],
            ],
            'rows' => $rows,
            'summary' => [
                ['key' => 'payments', 'label' => 'Payments', 'value' => count($rows), 'format' => 'number'],
                ['key' => 'collected', 'label' => 'Collected', 'value' => round($collected, 2), 'format' => 'money'],
                ['key' => 'pending', 'label' => 'Pending value', 'value' => round(array_sum(array_column($rows, 'amount')) - $collected, 2), 'format' => 'money'],
            ],
        ];
    }
}
