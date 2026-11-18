<?php

namespace App\Http\Controllers\Api\V1\Tenant;

use App\Http\Controllers\Api\V1\Tenant\Concerns\ChecksTenantAccess;
use App\Http\Controllers\Controller;
use App\Models\ProductCategory;
use App\Models\Tenant;
use App\Services\Audit\AuditLogger;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class ProductCategoryController extends Controller
{
    use ChecksTenantAccess;

    public function index(Request $request, Tenant $tenant): JsonResponse
    {
        $this->assertCan($request, $tenant, 'catalogue.view');

        $categories = ProductCategory::query()
            ->where('tenant_id', $tenant->id)
            ->withCount('products')
            ->orderBy('name')
            ->get();

        return ApiResponse::success($categories->map(fn (ProductCategory $category) => [
            'id' => $category->uuid,
            'name' => $category->name,
            'code' => $category->code,
            'status' => $category->status,
            'products_count' => $category->products_count,
        ])->values());
    }

    public function store(Request $request, Tenant $tenant, AuditLogger $audit): JsonResponse
    {
        $this->assertCan($request, $tenant, 'catalogue.update');

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120', Rule::unique('product_categories', 'name')->where('tenant_id', $tenant->id)],
            'code' => ['nullable', 'string', 'max:40'],
            'parent_id' => ['nullable', 'string'],
            'status' => ['nullable', Rule::in(['active', 'inactive'])],
        ]);

        $parent = null;
        if (! empty($data['parent_id'])) {
            $parent = ProductCategory::query()
                ->where('tenant_id', $tenant->id)
                ->where('uuid', $data['parent_id'])
                ->first();

            if ($parent === null) {
                return ApiResponse::error('VALIDATION_FAILED', 'The supplied data is invalid.', 422, [
                    'parent_id' => ['The selected parent category does not belong to this business.'],
                ]);
            }
        }

        $category = ProductCategory::create([
            'tenant_id' => $tenant->id,
            'parent_id' => $parent?->id,
            'name' => $data['name'],
            'code' => $data['code'] ?? null,
            'status' => $data['status'] ?? 'active',
        ]);

        $audit->log('product_category.created', $category, null, null, ['name' => $category->name], $tenant->id);

        return ApiResponse::success([
            'id' => $category->uuid,
            'name' => $category->name,
            'code' => $category->code,
            'status' => $category->status,
            'products_count' => 0,
        ], 201);
    }
}
