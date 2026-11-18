<?php

namespace App\Http\Controllers\Api\V1\Tenant;

use App\Http\Controllers\Api\V1\Tenant\Concerns\ChecksTenantAccess;
use App\Http\Controllers\Controller;
use App\Models\ReportRun;
use App\Models\Tenant;
use App\Services\Reporting\AnalyticsService;
use App\Services\Reporting\ReportService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

final class ReportController extends Controller
{
    use ChecksTenantAccess;

    public function index(Request $request, Tenant $tenant, ReportService $reports): JsonResponse
    {
        $this->assertCan($request, $tenant, 'report.view');

        $runs = ReportRun::query()
            ->where('tenant_id', $tenant->id)
            ->with('user')
            ->latest('generated_at')
            ->limit(20)
            ->get()
            ->map(fn (ReportRun $run) => [
                'id' => $run->uuid,
                'report_type' => $run->report_type,
                'format' => $run->format,
                'row_count' => $run->row_count,
                'generated_at' => $run->generated_at?->toIso8601String(),
                'generated_by' => $run->user?->name,
            ]);

        return ApiResponse::success([
            'available' => $reports->availableReports('tenant'),
            'runs' => $runs,
            'can_export' => $request->user()->hasPermission('report.export', $tenant->id),
        ]);
    }

    public function show(Request $request, Tenant $tenant, string $type, ReportService $reports): JsonResponse
    {
        $this->assertCan($request, $tenant, 'report.view');

        return ApiResponse::success($reports->generate(
            'tenant',
            $type,
            $tenant,
            $this->filters($request),
            $request->user(),
        ));
    }

    public function export(Request $request, Tenant $tenant, string $type, ReportService $reports): Response
    {
        $this->assertCan($request, $tenant, 'report.export');

        $report = $reports->generate('tenant', $type, $tenant, $this->filters($request), $request->user(), 'csv');
        $reports->auditExport('tenant', $tenant, $request->user(), $type);

        return response($reports->toCsv($report), 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="brathamatu-'.$type.'-'.now()->format('Ymd-His').'.csv"',
        ]);
    }

    public function analytics(Request $request, Tenant $tenant, AnalyticsService $analytics): JsonResponse
    {
        $this->assertCan($request, $tenant, 'report.view');

        return ApiResponse::success($analytics->tenantOverview($tenant, $request->user()));
    }

    /**
     * @return array<string, mixed>
     */
    private function filters(Request $request): array
    {
        $data = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:5000'],
        ]);

        return array_filter($data, fn ($value) => $value !== null);
    }
}
