<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Marketing\SeoVitalsService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class PublicVitalsController extends Controller
{
    public function store(Request $request, SeoVitalsService $vitals): JsonResponse
    {
        $data = $request->validate([
            'url' => ['required', 'string', 'max:500'],
            'device' => ['nullable', 'string', 'in:desktop,mobile,tablet,unknown'],
            'lcp' => ['nullable', 'numeric', 'min:0', 'max:120000'],
            'cls' => ['nullable', 'numeric', 'min:0', 'max:10'],
            'inp' => ['nullable', 'numeric', 'min:0', 'max:120000'],
            'ttfb' => ['nullable', 'numeric', 'min:0', 'max:120000'],
            'metric' => ['nullable', 'array'],
            'measured_at' => ['nullable', 'date'],
        ]);

        $vital = $vitals->record($data, $request);

        return ApiResponse::success([
            'id' => $vital->id,
            'path' => $vital->path,
        ], 202);
    }
}
