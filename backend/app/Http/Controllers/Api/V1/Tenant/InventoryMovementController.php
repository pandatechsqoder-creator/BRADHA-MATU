<?php

namespace App\Http\Controllers\Api\V1\Tenant;

use App\Http\Controllers\Api\V1\Tenant\Concerns\ChecksTenantAccess;
use App\Http\Controllers\Controller;
use App\Models\InventoryMovement;
use App\Models\StockLocation;
use App\Models\Tenant;
use App\Services\Audit\AuditLogger;
use App\Services\Catalogue\TabularFileReader;
use App\Services\Inventory\InventoryService;
use App\Support\ApiResponse;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Throwable;

final class InventoryMovementController extends Controller
{
    use ChecksTenantAccess;

    private const IMPORT_ALIASES = [
        'external_id' => ['external id', 'external_id', 'external reference', 'movement id', 'transaction id', 'reference id'],
        'type' => ['type', 'movement type', 'movement', 'transaction type'],
        'stock_location' => ['stock location', 'location', 'store', 'warehouse'],
        'occurred_at' => ['occurred at', 'occurred_at', 'date', 'movement date', 'timestamp', 'date time'],
        'reference' => ['reference', 'ref', 'document', 'document number', 'invoice'],
        'sku' => ['sku', 'item code', 'product code', 'product sku', 'code'],
        'quantity' => ['quantity', 'qty', 'units', 'count'],
        'batch_number' => ['batch', 'batch number', 'batch no', 'lot', 'lot number'],
        'expires_on' => ['expires on', 'expiry date', 'expiry', 'best before', 'expiry_date'],
        'manufactured_on' => ['manufactured on', 'manufactured_on', 'production date', 'manufacture date'],
        'serial_number' => ['serial', 'serial number', 'serial_number', 'imei'],
        'unit_cost' => ['unit cost', 'cost', 'cost price'],
        'notes' => ['notes', 'note', 'comment', 'description'],
    ];

    private const IMPORT_REQUIRED = ['type', 'stock_location', 'sku', 'quantity'];

    public function index(Request $request, Tenant $tenant, InventoryController $presenter): JsonResponse
    {
        $this->assertCan($request, $tenant, 'inventory.view');
        $withCost = $request->user()->hasPermission('catalogue.update', $tenant->id);

        $filters = $request->validate([
            'type' => ['nullable', Rule::in(InventoryService::MOVEMENT_TYPES)],
            'source' => ['nullable', 'in:manual,import,brathamatu_pos,external'],
            'stock_location' => ['nullable', 'string'],
            'search' => ['nullable', 'string', 'max:120'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        $query = InventoryMovement::query()
            ->where('inventory_movements.tenant_id', $tenant->id)
            ->with(['location', 'branch', 'cutoff', 'lines.variant.product', 'lines.variant.baseUnit'])
            ->when($filters['type'] ?? null, fn ($query, $type) => $query->where('type', $type))
            ->when($filters['source'] ?? null, fn ($query, $source) => $query->where('source', $source))
            ->when($filters['stock_location'] ?? null, fn ($query, $location) => $query->whereHas(
                'location',
                fn ($inner) => $inner->where('uuid', $location),
            ))
            ->when($filters['from'] ?? null, fn ($query, $from) => $query->where('occurred_at', '>=', CarbonImmutable::parse($from)->startOfDay()))
            ->when($filters['to'] ?? null, fn ($query, $to) => $query->where('occurred_at', '<=', CarbonImmutable::parse($to)->endOfDay()))
            ->when($filters['search'] ?? null, function ($query, $search): void {
                $query->where(function ($inner) use ($search): void {
                    $inner->where('reference', 'like', "%{$search}%")
                        ->orWhere('external_id', 'like', "%{$search}%")
                        ->orWhereHas('lines.variant', fn ($variant) => $variant->where('sku', 'like', "%{$search}%"));
                });
            })
            ->orderByDesc('occurred_at')
            ->orderByDesc('id');

        $page = $query->paginate(50);

        return ApiResponse::success(
            collect($page->items())
                ->map(fn (InventoryMovement $movement) => $presenter->movementPayload($movement, $withCost))
                ->values(),
            200,
            [
                'current_page' => $page->currentPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
                'last_page' => $page->lastPage(),
            ],
        );
    }

    public function store(Request $request, Tenant $tenant, InventoryService $inventory, InventoryController $presenter): JsonResponse
    {
        $this->assertCan($request, $tenant, 'inventory.movement.create');
        $withCost = $request->user()->hasPermission('catalogue.update', $tenant->id);

        $data = $this->validateMovement($request);

        if (($data['type'] ?? null) === 'adjustment' && ! $request->user()->hasPermission('inventory.adjust', $tenant->id)) {
            return ApiResponse::error('FORBIDDEN', 'You are not allowed to post adjustments.', 403);
        }

        $movement = $inventory->recordMovement($tenant, $data, $request->user());

        return ApiResponse::success($presenter->movementPayload($movement, $withCost), 201);
    }

    public function show(Request $request, Tenant $tenant, InventoryMovement $movement, InventoryController $presenter): JsonResponse
    {
        $this->assertCan($request, $tenant, 'inventory.view');
        $this->assertBelongsToTenant($tenant, $movement);

        $withCost = $request->user()->hasPermission('catalogue.update', $tenant->id);
        $movement->load(['location', 'branch', 'cutoff', 'lines.variant.product', 'lines.variant.baseUnit']);

        return ApiResponse::success($presenter->movementPayload($movement, $withCost));
    }

    public function import(
        Request $request,
        Tenant $tenant,
        TabularFileReader $reader,
        InventoryService $inventory,
        AuditLogger $audit,
        InventoryController $presenter,
    ): JsonResponse {
        $this->assertCan($request, $tenant, 'inventory.movement.create');

        $data = $request->validate([
            'file' => ['required', 'file', 'max:10240', 'mimes:csv,txt,xlsx'],
            'source' => ['nullable', Rule::in(['import', 'external'])],
        ]);

        $file = $request->file('file');
        $extension = strtolower($file->getClientOriginalExtension() ?: 'csv');

        try {
            $parsed = $reader->read($file->getRealPath(), $extension);
        } catch (Throwable $exception) {
            return ApiResponse::error('IMPORT_FAILED', $exception->getMessage(), 422);
        }

        $mapping = $this->autoMap($parsed['headers']);
        $missing = array_values(array_filter(
            self::IMPORT_REQUIRED,
            fn (string $field) => ! isset($mapping[$field]),
        ));

        if ($missing !== []) {
            return ApiResponse::error(
                'IMPORT_HEADERS_MISSING',
                'The file is missing required columns.',
                422,
                ['headers' => array_map(fn (string $field) => "Map a column for {$field}.", $missing)],
            );
        }

        $groups = $this->groupRows($parsed['rows'], $mapping);
        $created = [];
        $skipped = 0;
        $errors = [];
        $source = $data['source'] ?? 'import';
        $withCost = $request->user()->hasPermission('catalogue.update', $tenant->id);

        foreach ($groups as $group) {
            $rowNumbers = array_column($group['rows'], 'row_number');
            $header = $group['header'];
            $externalId = trim((string) ($header['external_id'] ?? ''));

            $conflicts = [];
            foreach (['type', 'stock_location', 'occurred_at'] as $field) {
                $values = array_unique(array_map(
                    fn (array $row) => strtolower(trim((string) ($row[$field] ?? ''))),
                    $group['rows'],
                ));
                if (count($values) > 1) {
                    $conflicts[] = $field;
                }
            }

            if ($conflicts !== []) {
                foreach ($rowNumbers as $rowNumber) {
                    $errors[] = [
                        'row' => $rowNumber,
                        'message' => 'Rows sharing an external reference must use the same movement type, stock location and date.',
                    ];
                }
                continue;
            }

            if ($externalId !== '' && InventoryMovement::query()
                ->where('tenant_id', $tenant->id)
                ->where('source', $source)
                ->where('external_id', $externalId)
                ->exists()) {
                $skipped++;
                continue;
            }

            $location = $this->findLocation($tenant, $header['stock_location'] ?? '');

            if ($location === null) {
                foreach ($rowNumbers as $rowNumber) {
                    $errors[] = ['row' => $rowNumber, 'message' => 'Stock location not found: '.($header['stock_location'] ?? '').'.'];
                }
                continue;
            }

            $payload = [
                'type' => strtolower(trim((string) ($header['type'] ?? ''))),
                'stock_location_id' => $location->uuid,
                'external_id' => $externalId !== '' ? $externalId : null,
                'reference' => $header['reference'] ?? null,
                'occurred_at' => $header['occurred_at'] ?? null,
                'notes' => $header['notes'] ?? null,
                'lines' => array_map(fn (array $row) => [
                    'sku' => $row['sku'] ?? null,
                    'quantity' => $row['quantity'] ?? null,
                    'batch_number' => $row['batch_number'] ?? null,
                    'expires_on' => $row['expires_on'] ?? null,
                    'manufactured_on' => $row['manufactured_on'] ?? null,
                    'serial_number' => $row['serial_number'] ?? null,
                    'unit_cost' => $row['unit_cost'] !== '' ? $row['unit_cost'] : null,
                ], $group['rows']),
            ];

            try {
                $created[] = $inventory->recordMovement($tenant, $payload, $request->user(), $source, false);
            } catch (ValidationException $exception) {
                $message = implode(' ', Arr::flatten($exception->errors()));
                foreach ($rowNumbers as $rowNumber) {
                    $errors[] = ['row' => $rowNumber, 'message' => $message];
                }
            }
        }

        if ($created !== []) {
            $audit->log('inventory.movements.imported', null, null, null, [
                'file' => $file->getClientOriginalName(),
                'source' => $source,
                'created' => count($created),
                'skipped' => $skipped,
                'error_rows' => count($errors),
            ], $tenant->id);
        }

        return ApiResponse::success([
            'status' => 'completed',
            'source' => $source,
            'created_movements' => count($created),
            'skipped_movements' => $skipped,
            'error_rows' => count($errors),
            'errors' => array_slice($errors, 0, 100),
            'movements' => array_map(
                fn (InventoryMovement $movement) => $presenter->movementPayload($movement, $withCost),
                array_slice($created, 0, 50),
            ),
        ], 201);
    }

    /**
     * @return array<string, mixed>
     */
    private function validateMovement(Request $request): array
    {
        return $request->validate([
            'stock_location_id' => ['required', 'string'],
            'type' => ['required', Rule::in(InventoryService::MOVEMENT_TYPES)],
            'occurred_at' => ['nullable', 'date'],
            'external_id' => ['nullable', 'string', 'max:120'],
            'reference' => ['nullable', 'string', 'max:120'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.variant_id' => ['nullable', 'string'],
            'lines.*.sku' => ['nullable', 'string', 'max:120'],
            'lines.*.quantity' => ['required', 'numeric'],
            'lines.*.batch_number' => ['nullable', 'string', 'max:80'],
            'lines.*.expires_on' => ['nullable', 'date'],
            'lines.*.manufactured_on' => ['nullable', 'date'],
            'lines.*.serial_number' => ['nullable', 'string', 'max:120'],
            'lines.*.unit_cost' => ['nullable', 'numeric', 'min:0'],
            'lines.*.metadata' => ['nullable', 'array'],
        ]);
    }

    private function findLocation(Tenant $tenant, string $value): ?StockLocation
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }

        return StockLocation::query()
            ->where('tenant_id', $tenant->id)
            ->where(function ($query) use ($value): void {
                $query->where('uuid', $value)->orWhereRaw('LOWER(name) = ?', [strtolower($value)]);
            })
            ->first();
    }

    /**
     * @param  list<string>  $headers
     * @return array<string, string>
     */
    private function autoMap(array $headers): array
    {
        $normalised = array_map(fn (string $header) => $this->normalise($header), $headers);
        $mapping = [];

        foreach (self::IMPORT_ALIASES as $field => $aliases) {
            foreach ($aliases as $alias) {
                $index = array_search($alias, $normalised, true);
                if ($index !== false) {
                    $mapping[$field] = $headers[$index];
                    break;
                }
            }
        }

        return $mapping;
    }

    private function normalise(string $value): string
    {
        $value = strtolower(trim($value));
        $value = str_replace(['_', '-'], ' ', $value);

        return preg_replace('/\s+/', ' ', $value) ?? '';
    }

    /**
     * @param  list<array<string, string>>  $rows
     * @param  array<string, string>  $mapping
     * @return list<array{header: array<string, string>, rows: list<array<string, string>>}>
     */
    private function groupRows(array $rows, array $mapping): array
    {
        $value = function (array $row, string $field) use ($mapping): string {
            $column = $mapping[$field] ?? null;

            return $column !== null ? trim((string) ($row[$column] ?? '')) : '';
        };

        $groups = [];

        foreach ($rows as $row) {
            $externalId = $value($row, 'external_id');
            $key = $externalId !== '' ? 'ext:'.$externalId : 'row:'.$row['__row_number'];
            $line = [];
            foreach (array_keys(self::IMPORT_ALIASES) as $field) {
                $line[$field] = $value($row, $field);
            }
            $line['row_number'] = (string) ($row['__row_number'] ?? '');

            if (! isset($groups[$key])) {
                $groups[$key] = ['header' => $line, 'rows' => []];
            }

            $groups[$key]['rows'][] = $line;
        }

        return array_values($groups);
    }
}
