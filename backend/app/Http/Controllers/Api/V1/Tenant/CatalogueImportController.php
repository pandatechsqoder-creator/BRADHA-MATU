<?php

namespace App\Http\Controllers\Api\V1\Tenant;

use App\Http\Controllers\Api\V1\Tenant\Concerns\ChecksTenantAccess;
use App\Http\Controllers\Controller;
use App\Jobs\ProcessCatalogueImport;
use App\Models\CatalogueImport;
use App\Models\CatalogueVersion;
use App\Models\Tenant;
use App\Services\Audit\AuditLogger;
use App\Services\Catalogue\CatalogueImportProcessor;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

final class CatalogueImportController extends Controller
{
    use ChecksTenantAccess;

    public function index(Request $request, Tenant $tenant): JsonResponse
    {
        $this->assertCan($request, $tenant, 'catalogue.view');

        $imports = CatalogueImport::query()
            ->where('tenant_id', $tenant->id)
            ->latest('id')
            ->limit(30)
            ->get();

        return ApiResponse::success($imports->map(fn (CatalogueImport $import) => $this->payload($import))->values());
    }

    public function store(Request $request, Tenant $tenant, AuditLogger $audit): JsonResponse
    {
        $this->assertCan($request, $tenant, 'catalogue.import');

        $data = $request->validate([
            'file' => ['required', 'file', 'max:10240', 'mimes:csv,txt,xlsx'],
            'partial_policy' => ['nullable', Rule::in(['partial', 'all_or_nothing'])],
        ]);

        $file = $request->file('file');
        $extension = strtolower($file->getClientOriginalExtension() ?: 'csv');
        $storedName = (string) Str::ulid().'.'.$extension;
        $path = $file->storeAs("catalogue-imports/{$tenant->id}", $storedName, 'local');

        $import = CatalogueImport::create([
            'tenant_id' => $tenant->id,
            'uploaded_by' => $request->user()->id,
            'original_name' => $file->getClientOriginalName(),
            'path' => $path,
            'disk' => 'local',
            'extension' => $extension,
            'mime' => $file->getClientMimeType(),
            'size' => $file->getSize(),
            'status' => 'queued',
            'partial_policy' => $data['partial_policy'] ?? 'partial',
        ]);

        ProcessCatalogueImport::dispatch($import->id);

        $audit->log('catalogue.import.uploaded', $import, null, null, [
            'file' => $import->original_name,
            'size' => $import->size,
        ], $tenant->id);

        return ApiResponse::success($this->payload($import), 202);
    }

    public function show(Request $request, Tenant $tenant, CatalogueImport $catalogueImport, CatalogueImportProcessor $processor): JsonResponse
    {
        $this->assertCan($request, $tenant, 'catalogue.view');
        $this->assertBelongsToTenant($tenant, $catalogueImport);

        $preview = $processor->preview($catalogueImport, 20);

        return ApiResponse::success([
            ...$this->payload($catalogueImport),
            'headers' => $catalogueImport->headers ?? [],
            'mapping' => $preview['mapping'],
            'canonical_fields' => collect(CatalogueImportProcessor::CANONICAL_FIELDS)
                ->map(fn ($label, $field) => ['field' => $field, 'label' => $label])
                ->values()
                ->all(),
            'rows' => $preview['rows'],
        ]);
    }

    public function updateMapping(Request $request, Tenant $tenant, CatalogueImport $catalogueImport, CatalogueImportProcessor $processor): JsonResponse
    {
        $this->assertCan($request, $tenant, 'catalogue.import');
        $this->assertBelongsToTenant($tenant, $catalogueImport);

        if (in_array($catalogueImport->status, ['committed', 'processing'], true)) {
            return ApiResponse::error('IMPORT_LOCKED', 'This import can no longer be re-mapped.', 422);
        }

        $data = $request->validate([
            'mapping' => ['required', 'array'],
            'mapping.*' => ['nullable', 'string'],
        ]);

        foreach (['name', 'sku'] as $required) {
            if (empty($data['mapping'][$required])) {
                return ApiResponse::error('MAPPING_INCOMPLETE', 'Map product name and SKU before continuing.', 422, [
                    'mapping' => ["The {$required} column must be mapped."],
                ]);
            }
        }

        $import = $processor->revalidate($catalogueImport, array_filter($data['mapping'], fn ($value) => $value !== null && $value !== ''));

        return ApiResponse::success($this->payload($import));
    }

    public function commit(Request $request, Tenant $tenant, CatalogueImport $catalogueImport, CatalogueImportProcessor $processor, AuditLogger $audit): JsonResponse
    {
        $this->assertCan($request, $tenant, 'catalogue.import');
        $this->assertBelongsToTenant($tenant, $catalogueImport);

        $data = $request->validate([
            'duplicate_strategy' => ['nullable', Rule::in(['skip', 'update'])],
        ]);

        $version = $processor->commit($catalogueImport, $data['duplicate_strategy'] ?? 'skip', $request->user());

        $audit->log('catalogue.import.committed', $catalogueImport, null, null, [
            'version_number' => $version->version_number,
            'product_count' => $version->product_count,
            'source' => $catalogueImport->original_name,
        ], $tenant->id);

        return ApiResponse::success([
            'import' => $this->payload($catalogueImport->fresh()),
            'version' => [
                'id' => $version->uuid,
                'version_number' => $version->version_number,
                'product_count' => $version->product_count,
                'status' => $version->status,
            ],
        ], 201);
    }

    public function errors(Request $request, Tenant $tenant, CatalogueImport $catalogueImport, CatalogueImportProcessor $processor): Response
    {
        $this->assertCan($request, $tenant, 'catalogue.view');
        $this->assertBelongsToTenant($tenant, $catalogueImport);

        $csv = $processor->errorReport($catalogueImport);

        return response($csv, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="catalogue-import-errors-'.$catalogueImport->uuid.'.csv"',
        ]);
    }

    private function payload(CatalogueImport $import): array
    {
        return [
            'id' => $import->uuid,
            'original_name' => $import->original_name,
            'extension' => $import->extension,
            'size' => $import->size,
            'status' => $import->status,
            'partial_policy' => $import->partial_policy,
            'total_rows' => (int) ($import->total_rows ?? 0),
            'valid_rows' => (int) ($import->valid_rows ?? 0),
            'error_rows' => (int) ($import->error_rows ?? 0),
            'duplicate_rows' => (int) ($import->duplicate_rows ?? 0),
            'error_message' => $import->error_message,
            'committed_version_id' => $import->committedVersion?->uuid,
            'created_at' => $import->created_at?->toIso8601String(),
        ];
    }
}
