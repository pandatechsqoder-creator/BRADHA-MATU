<?php

namespace App\Http\Controllers\Api\V1\Platform;

use App\Http\Controllers\Controller;
use App\Models\ReportRun;
use App\Models\Tenant;
use App\Services\Reporting\AnalyticsService;
use App\Services\Reporting\ReportService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

final class PlatformReportController extends Controller
{
    public function index(Request $request, ReportService $reports): JsonResponse
    {
        $runs = ReportRun::query()
            ->withoutGlobalScopes()
            ->with(['user', 'tenant'])
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
                'tenant' => $run->tenant?->name,
            ]);

        return ApiResponse::success([
            'available' => $reports->availableReports('platform'),
            'runs' => $runs,
            'can_export' => $request->user()->hasPermission('report.export'),
        ]);
    }

    public function show(Request $request, string $type, ReportService $reports): JsonResponse
    {
        return ApiResponse::success($reports->generate(
            'platform',
            $type,
            null,
            $this->filters($request),
            $request->user(),
        ));
    }

    public function export(Request $request, string $type, ReportService $reports): Response
    {
        $report = $reports->generate('platform', $type, null, $this->filters($request), $request->user(), 'csv');
        $reports->auditExport('platform', null, $request->user(), $type);

        return response($reports->toCsv($report), 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="brathamatu-platform-'.$type.'-'.now()->format('Ymd-His').'.csv"',
        ]);
    }

    public function analytics(Request $request, AnalyticsService $analytics): JsonResponse
    {
        return ApiResponse::success($analytics->platformOverview($request->user()));
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
