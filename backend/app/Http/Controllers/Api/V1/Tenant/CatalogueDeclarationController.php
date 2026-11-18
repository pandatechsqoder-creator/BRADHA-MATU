<?php

namespace App\Http\Controllers\Api\V1\Tenant;

use App\Http\Controllers\Api\V1\Tenant\Concerns\ChecksTenantAccess;
use App\Http\Controllers\Controller;
use App\Models\Catalogue;
use App\Models\CatalogueDeclaration;
use App\Models\Tenant;
use App\Services\Audit\AuditLogger;
use App\Services\Catalogue\CataloguePricingService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class CatalogueDeclarationController extends Controller
{
    use ChecksTenantAccess;

    public function store(
        Request $request,
        Tenant $tenant,
        Catalogue $catalogue,
        CataloguePricingService $pricing,
        AuditLogger $audit,
    ): JsonResponse {
        $this->assertCan($request, $tenant, 'catalogue.confirm');
        $this->assertBelongsToTenant($tenant, $catalogue);

        $data = $request->validate([
            'declaration_text' => ['required', 'string', 'min:20', 'max:4000'],
            'exceptions' => ['nullable', 'string', 'max:2000'],
            'declaration_version' => ['nullable', 'string', 'max:20'],
            'confirm_accuracy' => ['accepted'],
        ]);

        $version = $catalogue->currentVersion;
        if ($version === null) {
            return ApiResponse::error('CATALOGUE_NOT_READY', 'Create a catalogue version before declaring it accurate.', 422);
        }

        $already = CatalogueDeclaration::query()
            ->where('tenant_id', $tenant->id)
            ->where('catalogue_version_id', $version->id)
            ->exists();

        if ($already) {
            return ApiResponse::error('ALREADY_DECLARED', 'This catalogue version has already been declared.', 422);
        }

        $declaration = DB::transaction(function () use ($request, $tenant, $catalogue, $version, $data, $pricing): CatalogueDeclaration {
            $declaration = CatalogueDeclaration::create([
                'tenant_id' => $tenant->id,
                'catalogue_id' => $catalogue->id,
                'catalogue_version_id' => $version->id,
                'declared_by' => $request->user()->id,
                'declaration_text' => $data['declaration_text'],
                'declaration_version' => $data['declaration_version'] ?? '1.0',
                'exceptions' => $data['exceptions'] ?? null,
                'device' => [
                    'user_agent' => substr((string) $request->userAgent(), 0, 500),
                    'platform' => 'web',
                ],
                'ip_address' => $request->ip(),
                'accepted_at' => now(),
            ]);

            $catalogue->update(['confirmed_at' => now()]);

            $pricing->invoiceFor($catalogue, $version, $request->user());

            return $declaration;
        });

        $audit->log('catalogue.declared', $declaration, null, null, [
            'version_number' => $version->version_number,
            'product_count' => $version->product_count,
            'exceptions' => $declaration->exceptions,
        ], $tenant->id);

        $invoice = $catalogue->invoices()->latest('id')->first();

        return ApiResponse::success([
            'declaration' => [
                'id' => $declaration->uuid,
                'declaration_version' => $declaration->declaration_version,
                'declaration_text' => $declaration->declaration_text,
                'exceptions' => $declaration->exceptions,
                'accepted_at' => $declaration->accepted_at?->toIso8601String(),
                'declared_by' => $request->user()->name,
                'version_number' => $version->version_number,
            ],
            'invoice' => $invoice ? [
                'id' => $invoice->uuid,
                'number' => $invoice->number,
                'product_count' => $invoice->product_count,
                'subtotal' => (float) $invoice->subtotal,
                'tax' => (float) $invoice->tax,
                'total' => (float) $invoice->total,
                'currency' => $invoice->currency,
                'status' => $invoice->status,
                'issued_at' => $invoice->issued_at?->toIso8601String(),
            ] : null,
        ], 201);
    }
}
