<?php

namespace App\Http\Controllers\Api\V1\Tenant;

use App\Http\Controllers\Api\V1\Tenant\Concerns\ChecksTenantAccess;
use App\Http\Controllers\Controller;
use App\Models\Catalogue;
use App\Models\Product;
use App\Models\ProductBarcode;
use App\Models\ProductCategory;
use App\Models\ProductVariant;
use App\Models\Tenant;
use App\Models\UnitConversion;
use App\Services\Audit\AuditLogger;
use App\Services\Catalogue\CatalogueVersionService;
use App\Services\Catalogue\UnitService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class ProductController extends Controller
{
    use ChecksTenantAccess;

    public function index(Request $request, Tenant $tenant): JsonResponse
    {
        $this->assertCan($request, $tenant, 'catalogue.view');

        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:120'],
            'status' => ['nullable', Rule::in(['active', 'inactive', 'discontinued'])],
            'category' => ['nullable', 'string'],
            'include' => ['nullable', Rule::in(['variants'])],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        $query = Product::query()
            ->where('tenant_id', $tenant->id)
            ->with(['category', 'variants.baseUnit', 'variants.packagingUnit', 'variants.barcodes'])
            ->when($filters['search'] ?? null, function ($query, $search): void {
                $query->where(function ($inner) use ($search): void {
                    $inner->where('name', 'like', "%{$search}%")
                        ->orWhere('brand', 'like', "%{$search}%")
                        ->orWhereHas('variants', fn ($variant) => $variant->where('sku', 'like', "%{$search}%"))
                        ->orWhereHas('variants.barcodes', fn ($barcode) => $barcode->where('barcode', 'like', "%{$search}%"));
                });
            })
            ->when($filters['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->when($filters['category'] ?? null, function ($query, $category): void {
                $query->whereHas('category', fn ($inner) => $inner->where('uuid', $category)->orWhere('name', $category));
            })
            ->orderBy('name');

        $page = $query->paginate(24);
        $withCost = $this->canViewCost($request, $tenant);
        $detailed = ($filters['include'] ?? null) === 'variants';

        return ApiResponse::success(
            collect($page->items())->map(fn (Product $product) => $this->payload($product, $withCost, $detailed))->values(),
            200,
            [
                'current_page' => $page->currentPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
                'last_page' => $page->lastPage(),
            ],
        );
    }

    public function show(Request $request, Tenant $tenant, Product $product): JsonResponse
    {
        $this->assertCan($request, $tenant, 'catalogue.view');
        $this->assertBelongsToTenant($tenant, $product);

        $product->load(['category', 'variants.baseUnit', 'variants.packagingUnit', 'variants.barcodes']);

        return ApiResponse::success($this->payload($product, $this->canViewCost($request, $tenant), true));
    }

    public function store(
        Request $request,
        Tenant $tenant,
        UnitService $units,
        CatalogueVersionService $versions,
        AuditLogger $audit,
    ): JsonResponse {
        $this->assertCan($request, $tenant, 'catalogue.create');

        $data = $request->validate([
            'name' => ['required', 'string', 'max:200'],
            'sku' => ['required', 'string', 'max:80', Rule::unique('product_variants', 'sku')->where('tenant_id', $tenant->id)],
            'variant_name' => ['nullable', 'string', 'max:120'],
            'barcode' => ['nullable', 'string', 'max:80', Rule::unique('product_barcodes', 'barcode')->where('tenant_id', $tenant->id)],
            'barcode_type' => ['nullable', Rule::in(['ean13', 'upc', 'code128', 'internal'])],
            'brand' => ['nullable', 'string', 'max:120'],
            'category' => ['nullable', 'string', 'max:120'],
            'category_id' => ['nullable', 'string'],
            'description' => ['nullable', 'string', 'max:2000'],
            'base_unit' => ['nullable', 'string', 'max:20'],
            'packaging_unit' => ['nullable', 'string', 'max:20'],
            'packaging_factor' => ['nullable', 'numeric', 'gt:0'],
            'tracking_mode' => ['nullable', Rule::in(['quantity', 'batch', 'serial', 'expiry', 'combination'])],
            'status' => ['nullable', Rule::in(['active', 'inactive', 'discontinued'])],
            'supplier_ref' => ['nullable', 'string', 'max:120'],
            'cost_price' => ['nullable', 'numeric', 'min:0'],
            'attributes' => ['nullable', 'array'],
        ]);

        if (! empty($data['packaging_unit']) && empty($data['packaging_factor'])) {
            throw ValidationException::withMessages([
                'packaging_factor' => ['Provide the number of base units in one packaging unit.'],
            ]);
        }

        $product = DB::transaction(function () use ($data, $request, $tenant, $units, $versions): Product {
            $category = $this->resolveCategory($tenant, $data['category_id'] ?? null, $data['category'] ?? null);

            $product = Product::create([
                'tenant_id' => $tenant->id,
                'name' => $data['name'],
                'brand' => $data['brand'] ?? null,
                'category_id' => $category?->id,
                'description' => $data['description'] ?? null,
                'status' => $data['status'] ?? 'active',
                'tracking_mode' => $data['tracking_mode'] ?? 'quantity',
                'supplier_ref' => $data['supplier_ref'] ?? null,
                'cost_price' => $data['cost_price'] ?? null,
                'source_system' => 'manual',
                'created_by' => $request->user()->id,
            ]);

            $baseUnit = $units->ensure($tenant->id, $data['base_unit'] ?? 'piece');
            $packagingUnit = ! empty($data['packaging_unit'])
                ? $units->ensure($tenant->id, $data['packaging_unit'])
                : null;
            $factor = $packagingUnit !== null ? (float) $data['packaging_factor'] : null;

            if ($packagingUnit !== null && $factor !== null) {
                UnitConversion::firstOrCreate(
                    [
                        'tenant_id' => $tenant->id,
                        'from_unit_id' => $packagingUnit->id,
                        'to_unit_id' => $baseUnit->id,
                    ],
                    ['factor' => $factor],
                );
            }

            $variant = ProductVariant::create([
                'tenant_id' => $tenant->id,
                'product_id' => $product->id,
                'name' => $data['variant_name'] ?? null,
                'sku' => $data['sku'],
                'base_unit_id' => $baseUnit->id,
                'packaging_unit_id' => $packagingUnit?->id,
                'packaging_factor' => $factor,
                'attributes' => $data['attributes'] ?? null,
                'status' => ($data['status'] ?? 'active') === 'discontinued' ? 'inactive' : ($data['status'] ?? 'active'),
            ]);

            if (! empty($data['barcode'])) {
                ProductBarcode::create([
                    'tenant_id' => $tenant->id,
                    'variant_id' => $variant->id,
                    'barcode' => $data['barcode'],
                    'type' => $data['barcode_type'] ?? 'internal',
                    'is_primary' => true,
                ]);
            }

            $catalogue = $this->catalogueFor($tenant, $request->user()->id);
            $versions->publish($catalogue, $request->user(), 'manual', 'Product added: '.$product->name);

            return $product;
        });

        $audit->log('product.created', $product, null, null, [
            'name' => $product->name,
            'sku' => $data['sku'],
        ], $tenant->id);

        $product->load(['category', 'variants.baseUnit', 'variants.packagingUnit', 'variants.barcodes']);

        return ApiResponse::success($this->payload($product, $this->canViewCost($request, $tenant), true), 201);
    }

    public function update(
        Request $request,
        Tenant $tenant,
        Product $product,
        UnitService $units,
        CatalogueVersionService $versions,
        AuditLogger $audit,
    ): JsonResponse {
        $this->assertCan($request, $tenant, 'catalogue.update');
        $this->assertBelongsToTenant($tenant, $product);

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:200'],
            'sku' => ['sometimes', 'string', 'max:80', Rule::unique('product_variants', 'sku')->where('tenant_id', $tenant->id)->ignore($product->variants()->value('id'))],
            'variant_name' => ['nullable', 'string', 'max:120'],
            'brand' => ['nullable', 'string', 'max:120'],
            'category' => ['nullable', 'string', 'max:120'],
            'category_id' => ['nullable', 'string'],
            'description' => ['nullable', 'string', 'max:2000'],
            'base_unit' => ['nullable', 'string', 'max:20'],
            'packaging_unit' => ['nullable', 'string', 'max:20'],
            'packaging_factor' => ['nullable', 'numeric', 'gt:0'],
            'tracking_mode' => ['sometimes', Rule::in(['quantity', 'batch', 'serial', 'expiry', 'combination'])],
            'status' => ['sometimes', Rule::in(['active', 'inactive', 'discontinued'])],
            'supplier_ref' => ['nullable', 'string', 'max:120'],
            'cost_price' => ['nullable', 'numeric', 'min:0'],
            'attributes' => ['nullable', 'array'],
        ]);

        $variant = $product->variants()->orderBy('id')->first();

        $changed = DB::transaction(function () use ($data, $request, $tenant, $product, $variant, $units, $versions, $audit): bool {
            $before = $product->only(['name', 'brand', 'category_id', 'description', 'status', 'tracking_mode', 'supplier_ref', 'cost_price']);

            $category = array_key_exists('category', $data) || array_key_exists('category_id', $data)
                ? $this->resolveCategory($tenant, $data['category_id'] ?? null, $data['category'] ?? null)
                : null;

            $product->fill(array_filter([
                'name' => $data['name'] ?? null,
                'brand' => array_key_exists('brand', $data) ? $data['brand'] : null,
                'category_id' => $category?->id,
                'description' => array_key_exists('description', $data) ? $data['description'] : null,
                'status' => $data['status'] ?? null,
                'tracking_mode' => $data['tracking_mode'] ?? null,
                'supplier_ref' => array_key_exists('supplier_ref', $data) ? $data['supplier_ref'] : null,
                'cost_price' => array_key_exists('cost_price', $data) ? $data['cost_price'] : null,
            ], fn ($value, $key) => array_key_exists($key, $data) || ($key === 'category_id' && $category !== null), ARRAY_FILTER_USE_BOTH));

            if ($product->isDirty()) {
                $product->save();
            }

            if ($variant !== null) {
                $variantChanges = [];
                if (array_key_exists('sku', $data)) {
                    $variantChanges['sku'] = $data['sku'];
                }
                if (array_key_exists('variant_name', $data)) {
                    $variantChanges['name'] = $data['variant_name'];
                }
                if (array_key_exists('attributes', $data)) {
                    $variantChanges['attributes'] = $data['attributes'];
                }
                if (! empty($data['base_unit'])) {
                    $variantChanges['base_unit_id'] = $units->ensure($tenant->id, $data['base_unit'])->id;
                }
                if (! empty($data['packaging_unit'])) {
                    $packaging = $units->ensure($tenant->id, $data['packaging_unit']);
                    $variantChanges['packaging_unit_id'] = $packaging->id;
                    if (! empty($data['packaging_factor'])) {
                        $variantChanges['packaging_factor'] = (float) $data['packaging_factor'];
                        UnitConversion::firstOrCreate(
                            [
                                'tenant_id' => $tenant->id,
                                'from_unit_id' => $packaging->id,
                                'to_unit_id' => $variantChanges['base_unit_id'] ?? $variant->base_unit_id,
                            ],
                            ['factor' => (float) $data['packaging_factor']],
                        );
                    }
                }
                if (isset($data['status'])) {
                    $variantChanges['status'] = $data['status'] === 'discontinued' ? 'inactive' : $data['status'];
                }

                if ($variantChanges !== []) {
                    $variant->fill($variantChanges);
                    if ($variant->isDirty()) {
                        $variant->save();
                    }
                }
            }

            $changed = $product->wasChanged() || ($variant?->wasChanged() ?? false);
            if ($changed) {
                $catalogue = $this->catalogueFor($tenant, $request->user()->id);
                $versions->publish($catalogue, $request->user(), 'manual', 'Product updated: '.$product->name);
                $audit->log('product.updated', $product, null, $before, $product->only(array_keys($before)), $tenant->id);
            }

            return $changed;
        });

        $product->load(['category', 'variants.baseUnit', 'variants.packagingUnit', 'variants.barcodes']);

        return ApiResponse::success([
            ...$this->payload($product, $this->canViewCost($request, $tenant), true),
            'version_created' => $changed,
        ]);
    }

    public function destroy(Request $request, Tenant $tenant, Product $product, CatalogueVersionService $versions, AuditLogger $audit): JsonResponse
    {
        $this->assertCan($request, $tenant, 'catalogue.update');
        $this->assertBelongsToTenant($tenant, $product);

        $previousStatus = $product->status;

        $product->update(['status' => 'discontinued']);
        $product->variants()->update(['status' => 'inactive']);

        $catalogue = $this->catalogueFor($tenant, $request->user()->id);
        $versions->publish($catalogue, $request->user(), 'manual', 'Product discontinued: '.$product->name);

        $audit->log('product.discontinued', $product, 'Product marked discontinued; history preserved.', ['status' => $previousStatus], ['status' => 'discontinued'], $tenant->id);

        return ApiResponse::success(['status' => 'discontinued']);
    }

    public function addBarcode(Request $request, Tenant $tenant, Product $product, CatalogueVersionService $versions, AuditLogger $audit): JsonResponse
    {
        $this->assertCan($request, $tenant, 'catalogue.update');
        $this->assertBelongsToTenant($tenant, $product);

        $variant = $product->variants()->orderBy('id')->first();
        if ($variant === null) {
            return ApiResponse::error('NO_VARIANT', 'This product has no sellable variant.', 422);
        }

        $data = $request->validate([
            'barcode' => ['required', 'string', 'max:80', Rule::unique('product_barcodes', 'barcode')->where('tenant_id', $tenant->id)],
            'type' => ['nullable', Rule::in(['ean13', 'upc', 'code128', 'internal'])],
            'is_primary' => ['nullable', 'boolean'],
        ]);

        if ($data['is_primary'] ?? false) {
            ProductBarcode::query()->where('variant_id', $variant->id)->update(['is_primary' => false]);
        }

        $barcode = ProductBarcode::create([
            'tenant_id' => $tenant->id,
            'variant_id' => $variant->id,
            'barcode' => $data['barcode'],
            'type' => $data['type'] ?? 'internal',
            'is_primary' => $data['is_primary'] ?? false,
        ]);

        $catalogue = $this->catalogueFor($tenant, $request->user()->id);
        $versions->publish($catalogue, $request->user(), 'manual', 'Barcode added to '.$product->name);

        $audit->log('product.barcode_added', $barcode, null, null, ['barcode' => $barcode->barcode], $tenant->id);

        return ApiResponse::success([
            'id' => $barcode->uuid,
            'barcode' => $barcode->barcode,
            'type' => $barcode->type,
            'is_primary' => $barcode->is_primary,
        ], 201);
    }

    public function removeBarcode(Request $request, Tenant $tenant, Product $product, ProductBarcode $barcode, CatalogueVersionService $versions, AuditLogger $audit): JsonResponse
    {
        $this->assertCan($request, $tenant, 'catalogue.update');
        $this->assertBelongsToTenant($tenant, $product);
        $this->assertBelongsToTenant($tenant, $barcode);

        if (! $product->variants()->whereKey($barcode->variant_id)->exists()) {
            abort(404);
        }

        $barcode->delete();

        $catalogue = $this->catalogueFor($tenant, $request->user()->id);
        $versions->publish($catalogue, $request->user(), 'manual', 'Barcode removed from '.$product->name);

        $audit->log('product.barcode_removed', $product, null, ['barcode' => $barcode->barcode], null, $tenant->id);

        return ApiResponse::success(['status' => 'removed']);
    }

    private function payload(Product $product, bool $withCost, bool $detailed = false): array
    {
        $variants = $product->variants->sortBy('id')->values();
        $primary = $variants->first();
        $barcodes = $primary?->barcodes ?? collect();
        $primaryBarcode = $barcodes->firstWhere('is_primary', true) ?? $barcodes->first();

        $payload = [
            'id' => $product->uuid,
            'name' => $product->name,
            'brand' => $product->brand,
            'category' => $product->category ? [
                'id' => $product->category->uuid,
                'name' => $product->category->name,
            ] : null,
            'description' => $product->description,
            'status' => $product->status,
            'tracking_mode' => $product->tracking_mode,
            'supplier_ref' => $product->supplier_ref,
            'source_system' => $product->source_system,
            'sku' => $primary?->sku,
            'variant_name' => $primary?->name,
            'barcode' => $primaryBarcode?->barcode,
            'barcode_type' => $primaryBarcode?->type,
            'base_unit' => $primary?->baseUnit?->code,
            'packaging_unit' => $primary?->packagingUnit?->code,
            'packaging_factor' => $primary?->packaging_factor !== null ? (float) $primary->packaging_factor : null,
            'variants_count' => $variants->count(),
            'created_at' => $product->created_at?->toIso8601String(),
            'updated_at' => $product->updated_at?->toIso8601String(),
        ];

        if ($withCost) {
            $payload['cost_price'] = $product->cost_price !== null ? (float) $product->cost_price : null;
        }

        if ($detailed) {
            $payload['barcodes'] = $barcodes->map(fn (ProductBarcode $barcode) => [
                'id' => $barcode->uuid,
                'barcode' => $barcode->barcode,
                'type' => $barcode->type,
                'is_primary' => $barcode->is_primary,
            ])->values();
            $payload['variants'] = $variants->map(fn (ProductVariant $variant) => [
                'id' => $variant->uuid,
                'name' => $variant->name,
                'sku' => $variant->sku,
                'status' => $variant->status,
                'base_unit' => $variant->baseUnit?->code,
                'packaging_unit' => $variant->packagingUnit?->code,
                'packaging_factor' => $variant->packaging_factor !== null ? (float) $variant->packaging_factor : null,
                'attributes' => (object) ($variant->attributes ?? []),
                'barcodes' => $variant->barcodes->map(fn (ProductBarcode $barcode) => [
                    'id' => $barcode->uuid,
                    'barcode' => $barcode->barcode,
                    'type' => $barcode->type,
                    'is_primary' => $barcode->is_primary,
                ])->values(),
            ])->values();
        }

        return $payload;
    }

    private function resolveCategory(Tenant $tenant, ?string $categoryId, ?string $categoryName): ?ProductCategory
    {
        if (! empty($categoryId)) {
            $category = ProductCategory::query()
                ->where('tenant_id', $tenant->id)
                ->where('uuid', $categoryId)
                ->first();

            if ($category === null) {
                throw ValidationException::withMessages(['category_id' => ['The selected category does not belong to this business.']]);
            }

            return $category;
        }

        $name = trim((string) $categoryName);
        if ($name === '') {
            return null;
        }

        return ProductCategory::firstOrCreate(
            ['tenant_id' => $tenant->id, 'name' => $name],
            ['status' => 'active'],
        );
    }

    private function canViewCost(Request $request, Tenant $tenant): bool
    {
        return $request->user()->hasPermission('catalogue.update', $tenant->id);
    }

    private function catalogueFor(Tenant $tenant, ?int $userId): Catalogue
    {
        $catalogue = Catalogue::query()->where('tenant_id', $tenant->id)->orderByDesc('id')->first();

        return $catalogue ?? Catalogue::create([
            'tenant_id' => $tenant->id,
            'name' => 'Main catalogue',
            'status' => 'draft',
            'created_by' => $userId,
        ]);
    }
}
