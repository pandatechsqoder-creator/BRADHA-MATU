<?php

namespace App\Http\Controllers\Api\V1\Platform\Marketing;

use App\Http\Controllers\Controller;
use App\Models\Marketing\CmsCta;
use App\Services\Audit\AuditLogger;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class MarketingCtaController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function index(): JsonResponse
    {
        return ApiResponse::success([
            'ctas' => CmsCta::query()->orderBy('key')->get()
                ->map(fn (CmsCta $cta) => $this->payload($cta))->all(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate($this->rules());
        $cta = CmsCta::create($data);

        $this->audit->log('marketing.cta.created', $cta, null, null, $this->payload($cta));

        return ApiResponse::success(['cta' => $this->payload($cta)], 201);
    }

    public function update(CmsCta $cta, Request $request): JsonResponse
    {
        $data = $request->validate($this->rules(partial: true, cta: $cta));
        $before = $this->payload($cta);
        $cta->update($data);

        $this->audit->log('marketing.cta.updated', $cta, null, $before, $this->payload($cta));

        return ApiResponse::success(['cta' => $this->payload($cta)]);
    }

    public function destroy(CmsCta $cta): JsonResponse
    {
        $key = $cta->key;
        $cta->delete();
        $this->audit->log('marketing.cta.deleted', $cta, null, ['key' => $key]);

        return ApiResponse::success(['deleted' => true]);
    }

    /**
     * @return array<string, mixed>
     */
    private function rules(bool $partial = false, ?CmsCta $cta = null): array
    {
        $presence = $partial ? 'sometimes' : 'required';

        return [
            'key' => [$presence, 'alpha_dash', 'max:60', Rule::unique('cms_ctas', 'key')->ignore($cta?->id)],
            'label' => [$presence, 'string', 'max:120'],
            'url' => [$presence, 'string', 'max:500', 'regex:/^(\/|https?:\/\/)/'],
            'form_type' => ['nullable', 'string', 'max:60'],
            'style' => ['sometimes', Rule::in(['primary', 'secondary', 'link'])],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(CmsCta $cta): array
    {
        return [
            'id' => $cta->uuid,
            'key' => $cta->key,
            'label' => $cta->label,
            'url' => $cta->url,
            'form_type' => $cta->form_type,
            'style' => $cta->style,
            'is_active' => $cta->is_active,
        ];
    }
}
