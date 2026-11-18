<?php

namespace App\Http\Controllers\Api\V1\Tenant;

use App\Http\Controllers\Api\V1\Tenant\Concerns\ChecksTenantAccess;
use App\Http\Controllers\Controller;
use App\Models\Catalogue;
use App\Models\CatalogueDeclaration;
use App\Models\CatalogueImport;
use App\Models\CatalogueInvoice;
use App\Models\CatalogueVersion;
use App\Models\ProductBarcode;
use App\Models\ProductCategory;
use App\Models\ProductVariant;
use App\Models\Tenant;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class CatalogueController extends Controller
{
    use ChecksTenantAccess;

    public function summary(Request $request, Tenant $tenant): JsonResponse
    {
        $this->assertCan($request, $tenant, 'catalogue.view');

        $catalogue = Catalogue::query()
            ->where('tenant_id', $tenant->id)
            ->with(['versions' => fn ($query) => $query->limit(10)])
            ->latest('id')
            ->first();

        $currentVersion = $catalogue?->currentVersion;
        $productCount = ProductVariant::query()
            ->where('tenant_id', $tenant->id)
            ->where('status', 'active')
            ->count();

        $latestDeclaration = $catalogue
            ? CatalogueDeclaration::query()
                ->where('tenant_id', $tenant->id)
                ->where('catalogue_id', $catalogue->id)
                ->with('declaredBy')
                ->latest('accepted_at')
                ->first()
            : null;

        $invoice = $catalogue
            ? CatalogueInvoice::query()
                ->where('tenant_id', $tenant->id)
                ->where('catalogue_id', $catalogue->id)
                ->latest('id')
                ->first()
            : null;

        $latestImport = CatalogueImport::query()
            ->where('tenant_id', $tenant->id)
            ->latest('id')
            ->first();

        $confirmed = $catalogue?->confirmed_at !== null;
        $reasons = [];
        if ($catalogue === null || $currentVersion === null) {
            $reasons[] = 'Import or add products to create a catalogue version.';
        }
        if ($productCount === 0) {
            $reasons[] = 'Your catalogue has no active products.';
        }
        if ($catalogue !== null && ! $confirmed) {
            $reasons[] = 'Declare that the catalogue is accurate.';
        }
        if ($latestImport !== null && in_array($latestImport->status, ['queued', 'processing'], true)) {
            $reasons[] = 'An import is still being processed.';
        }

        return ApiResponse::success([
            'id' => $catalogue?->uuid,
            'name' => $catalogue?->name ?? 'Main catalogue',
            'status' => $catalogue?->status ?? 'draft',
            'confirmed' => $confirmed,
            'confirmed_at' => $catalogue?->confirmed_at?->toIso8601String(),
            'current_version' => $currentVersion ? $this->versionPayload($currentVersion) : null,
            'versions' => $catalogue
                ? $catalogue->versions->map(fn (CatalogueVersion $version) => $this->versionPayload($version))->values()
                : [],
            'product_count' => $productCount,
            'categories_count' => ProductCategory::query()->where('tenant_id', $tenant->id)->count(),
            'barcodes_count' => ProductBarcode::query()->where('tenant_id', $tenant->id)->count(),
            'latest_import' => $latestImport ? $this->importSummary($latestImport) : null,
            'latest_declaration' => $latestDeclaration ? $this->declarationPayload($latestDeclaration) : null,
            'invoice' => $invoice ? $this->invoicePayload($invoice) : null,
            'readiness' => [
                'has_catalogue' => $catalogue !== null && $currentVersion !== null,
                'has_products' => $productCount > 0,
                'confirmed' => $confirmed,
                'ready' => $catalogue !== null && $currentVersion !== null && $productCount > 0 && $confirmed,
                'reasons' => $reasons,
            ],
        ]);
    }

    public function versions(Request $request, Tenant $tenant, Catalogue $catalogue): JsonResponse
    {
        $this->assertCan($request, $tenant, 'catalogue.view');
        $this->assertBelongsToTenant($tenant, $catalogue);

        $versions = CatalogueVersion::query()
            ->where('tenant_id', $tenant->id)
            ->where('catalogue_id', $catalogue->id)
            ->orderByDesc('version_number')
            ->limit(50)
            ->get();

        return ApiResponse::success($versions->map(fn (CatalogueVersion $version) => $this->versionPayload($version))->values());
    }

    public function declarations(Request $request, Tenant $tenant, Catalogue $catalogue): JsonResponse
    {
        $this->assertCan($request, $tenant, 'catalogue.view');
        $this->assertBelongsToTenant($tenant, $catalogue);

        $declarations = CatalogueDeclaration::query()
            ->where('tenant_id', $tenant->id)
            ->where('catalogue_id', $catalogue->id)
            ->with('declaredBy')
            ->orderByDesc('accepted_at')
            ->get();

        return ApiResponse::success($declarations->map(fn (CatalogueDeclaration $declaration) => $this->declarationPayload($declaration))->values());
    }

    private function versionPayload(CatalogueVersion $version): array
    {
        return [
            'id' => $version->uuid,
            'version_number' => $version->version_number,
            'status' => $version->status,
            'product_count' => $version->product_count,
            'source' => $version->source,
            'notes' => $version->notes,
            'created_at' => $version->created_at?->toIso8601String(),
        ];
    }

    private function importSummary(CatalogueImport $import): array
    {
        return [
            'id' => $import->uuid,
            'original_name' => $import->original_name,
            'status' => $import->status,
            'total_rows' => (int) ($import->total_rows ?? 0),
            'valid_rows' => (int) ($import->valid_rows ?? 0),
            'error_rows' => (int) ($import->error_rows ?? 0),
            'duplicate_rows' => (int) ($import->duplicate_rows ?? 0),
            'created_at' => $import->created_at?->toIso8601String(),
        ];
    }

    private function declarationPayload(CatalogueDeclaration $declaration): array
    {
        return [
            'id' => $declaration->uuid,
            'declaration_version' => $declaration->declaration_version,
            'declaration_text' => $declaration->declaration_text,
            'exceptions' => $declaration->exceptions,
            'accepted_at' => $declaration->accepted_at?->toIso8601String(),
            'declared_by' => $declaration->declaredBy?->name,
            'version_number' => $declaration->version?->version_number,
            'ip_address' => $declaration->ip_address,
        ];
    }

    private function invoicePayload(CatalogueInvoice $invoice): array
    {
        return [
            'id' => $invoice->uuid,
            'number' => $invoice->number,
            'product_count' => $invoice->product_count,
            'subtotal' => (float) $invoice->subtotal,
            'tax' => (float) $invoice->tax,
            'total' => (float) $invoice->total,
            'currency' => $invoice->currency,
            'status' => $invoice->status,
            'issued_at' => $invoice->issued_at?->toIso8601String(),
        ];
    }
}
