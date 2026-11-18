<?php

namespace App\Http\Controllers\Api\V1\Platform\Marketing;

use App\Http\Controllers\Controller;
use App\Models\Marketing\MarketingRedirect;
use App\Services\Audit\AuditLogger;
use App\Services\Marketing\SeoDiagnosticsService;
use App\Services\Marketing\SeoRedirectService;
use App\Services\Marketing\SeoVitalsService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class SeoController extends Controller
{
    public function __construct(
        private readonly SeoDiagnosticsService $diagnostics,
        private readonly SeoVitalsService $vitals,
        private readonly SeoRedirectService $redirects,
        private readonly AuditLogger $audit,
    ) {}

    public function diagnostics(): JsonResponse
    {
        return ApiResponse::success($this->diagnostics->report());
    }

    public function vitals(Request $request): JsonResponse
    {
        $data = $request->validate([
            'days' => ['nullable', 'integer', 'min:1', 'max:90'],
            'path' => ['nullable', 'string', 'max:500'],
        ]);

        return ApiResponse::success($this->vitals->summary((int) ($data['days'] ?? 7), $data['path'] ?? null));
    }

    public function redirects(): JsonResponse
    {
        return ApiResponse::success(['redirects' => $this->redirects->list()]);
    }

    public function storeRedirect(Request $request): JsonResponse
    {
        $data = $request->validate([
            'from_path' => ['required', 'string', 'max:500'],
            'to_path' => ['required', 'string', 'max:500'],
            'status_code' => ['nullable', Rule::in(SeoRedirectService::STATUS_CODES)],
            'reason' => ['nullable', 'string', 'max:255'],
        ]);

        $redirect = $this->redirects->create($data, $request->user());
        $this->audit->log('marketing.redirect.created', $redirect, null, null, [
            'from_path' => $redirect->from_path,
            'to_path' => $redirect->to_path,
        ]);

        return ApiResponse::success(['redirect' => $this->redirects->present($redirect)], 201);
    }

    public function updateRedirect(MarketingRedirect $redirect, Request $request): JsonResponse
    {
        $data = $request->validate([
            'to_path' => ['sometimes', 'string', 'max:500'],
            'status_code' => ['sometimes', Rule::in(SeoRedirectService::STATUS_CODES)],
            'is_active' => ['sometimes', 'boolean'],
            'reason' => ['nullable', 'string', 'max:255'],
        ]);

        $before = ['to_path' => $redirect->to_path, 'status_code' => $redirect->status_code, 'is_active' => $redirect->is_active];
        $redirect = $this->redirects->update($redirect, $data);
        $this->audit->log('marketing.redirect.updated', $redirect, null, $before, [
            'to_path' => $redirect->to_path,
            'status_code' => $redirect->status_code,
            'is_active' => $redirect->is_active,
        ]);

        return ApiResponse::success(['redirect' => $this->redirects->present($redirect)]);
    }

    public function destroyRedirect(MarketingRedirect $redirect): JsonResponse
    {
        $this->audit->log('marketing.redirect.deleted', $redirect, null, [
            'from_path' => $redirect->from_path,
            'to_path' => $redirect->to_path,
        ]);

        $this->redirects->delete($redirect);

        return ApiResponse::success(['deleted' => true]);
    }
}
