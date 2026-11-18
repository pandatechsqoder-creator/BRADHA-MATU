<?php

namespace App\Services\Integrations;

use App\Models\Catalogue;
use App\Models\CatalogueVersion;
use App\Models\IntegrationConflict;
use App\Models\IntegrationConnection;
use App\Models\IntegrationMapping;
use App\Models\IntegrationSyncRun;
use App\Models\IntegrationWebhookEvent;
use App\Models\InventoryBalance;
use App\Models\InventoryMovement;
use App\Models\InventoryMovementLine;
use App\Models\Product;
use App\Models\ProductBarcode;
use App\Models\ProductCategory;
use App\Models\ProductVariant;
use App\Models\StockLocation;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Catalogue\CatalogueVersionService;
use App\Services\Catalogue\UnitService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

final class IntegrationService
{
    public const PROVIDERS = ['custom', 'csv', 'erpnext', 'odoo', 'quickbooks', 'sap', 'sage', 'generic_api'];

    public const ENTITIES = ['products', 'movements'];

    public const PRODUCT_FIELDS = [
        'name' => 'Product name',
        'sku' => 'SKU',
        'barcode' => 'Barcode',
        'barcode_type' => 'Barcode type',
        'brand' => 'Brand',
        'category' => 'Category',
        'description' => 'Description',
        'unit' => 'Base unit',
        'packaging_unit' => 'Packaging unit',
        'packaging_factor' => 'Units per package',
        'tracking_mode' => 'Tracking mode',
        'status' => 'Status',
        'supplier_ref' => 'Supplier reference',
        'cost_price' => 'Cost price',
    ];

    public const MOVEMENT_FIELDS = [
        'sku' => 'SKU',
        'quantity' => 'Signed quantity',
        'reference' => 'Reference',
        'occurred_at' => 'Occurred at',
        'unit_cost' => 'Unit cost',
        'notes' => 'Notes',
    ];

    public function __construct(
        private readonly UnitService $units,
        private readonly CatalogueVersionService $versions,
        private readonly AuditLogger $audit,
    ) {}

    public function listConnections(Tenant $tenant): array
    {
        return IntegrationConnection::withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->withCount([
                'runs',
                'conflicts' => fn ($query) => $query->where('resolution', 'pending'),
            ])
            ->orderBy('name')
            ->get()
            ->map(fn (IntegrationConnection $connection) => $this->connectionPayload($connection))
            ->all();
    }

    public function createConnection(Tenant $tenant, array $data, User $user): array
    {
        $connection = IntegrationConnection::create([
            'tenant_id' => $tenant->id,
            'created_by' => $user->id,
            'name' => $data['name'],
            'provider' => $data['provider'] ?? 'custom',
            'type' => $data['type'] ?? 'csv',
            'status' => $data['status'] ?? 'active',
            'credentials' => $data['credentials'] ?? null,
            'settings' => $data['settings'] ?? null,
            'webhook_secret' => $data['webhook_secret'] ?? Str::random(40),
        ]);

        $this->audit->log('integration.connection_created', $connection, null, null, [
            'provider' => $connection->provider,
            'type' => $connection->type,
        ], $tenant->id, $user->id);

        return $this->connectionPayload($connection->fresh());
    }

    public function updateConnection(IntegrationConnection $connection, array $data, User $user): array
    {
        $before = $connection->only(['name', 'provider', 'type', 'status']);
        $connection->fill(collect($data)->only([
            'name', 'provider', 'type', 'status', 'credentials', 'settings', 'webhook_secret',
        ])->all())->save();

        $this->audit->log('integration.connection_updated', $connection, null, $before, $connection->only([
            'name', 'provider', 'type', 'status',
        ]), $connection->tenant_id, $user->id);

        return $this->connectionPayload($connection->fresh());
    }

    public function deleteConnection(IntegrationConnection $connection, User $user): void
    {
        $this->audit->log('integration.connection_deleted', $connection, null, [
            'name' => $connection->name,
        ], null, $connection->tenant_id, $user->id);
        $connection->delete();
    }

    public function testConnection(IntegrationConnection $connection): array
    {
        if ($connection->type === 'csv') {
            return [
                'status' => 'ok',
                'message' => 'CSV connections accept mapped rows through the sync endpoint.',
            ];
        }

        $url = (string) ($connection->settings['url'] ?? '');
        $this->assertSafeUrl($url);

        try {
            $request = Http::timeout(8)->acceptJson();
            $token = $connection->credentials['token'] ?? null;
            if (is_string($token) && $token !== '') {
                $request = $request->withToken($token);
            }

            $response = $request->get($url);

            return [
                'status' => $response->successful() ? 'ok' : 'error',
                'http_status' => $response->status(),
                'message' => $response->successful()
                    ? 'Remote endpoint reachable.'
                    : 'Remote endpoint returned HTTP '.$response->status().'.',
            ];
        } catch (Throwable $error) {
            return ['status' => 'error', 'message' => $error->getMessage()];
        }
    }

    public function mapping(IntegrationConnection $connection, string $entity): array
    {
        $mapping = IntegrationMapping::withoutGlobalScopes()
            ->where('connection_id', $connection->id)
            ->where('entity', $entity)
            ->first();

        return [
            'entity' => $entity,
            'field_map' => (object) ($mapping?->field_map ?? []),
            'options' => (object) ($mapping?->options ?? []),
            'canonical' => $entity === 'movements' ? self::MOVEMENT_FIELDS : self::PRODUCT_FIELDS,
        ];
    }

    public function saveMapping(IntegrationConnection $connection, string $entity, array $fieldMap, array $options = []): array
    {
        IntegrationMapping::withoutGlobalScopes()->updateOrCreate(
            ['connection_id' => $connection->id, 'entity' => $entity],
            [
                'tenant_id' => $connection->tenant_id,
                'field_map' => $fieldMap,
                'options' => $options,
            ],
        );

        return $this->mapping($connection, $entity);
    }

    public function listRuns(IntegrationConnection $connection, int $limit = 30): array
    {
        return IntegrationSyncRun::withoutGlobalScopes()
            ->where('connection_id', $connection->id)
            ->latest('id')
            ->limit($limit)
            ->get()
            ->map(fn (IntegrationSyncRun $run) => $this->runPayload($run))
            ->all();
    }

    public function listConflicts(IntegrationConnection $connection, ?string $resolution = null): array
    {
        return IntegrationConflict::withoutGlobalScopes()
            ->where('connection_id', $connection->id)
            ->when($resolution, fn ($query, $value) => $query->where('resolution', $value))
            ->latest('id')
            ->limit(100)
            ->get()
            ->map(fn (IntegrationConflict $conflict) => $this->conflictPayload($conflict))
            ->all();
    }

    public function runSync(IntegrationConnection $connection, array $input, ?User $user = null): array
    {
        $entity = $input['entity'];
        $direction = $input['direction'] ?? 'import';

        $run = IntegrationSyncRun::create([
            'tenant_id' => $connection->tenant_id,
            'connection_id' => $connection->id,
            'triggered_by' => $user?->id,
            'entity' => $entity,
            'direction' => $direction,
            'status' => 'running',
            'started_at' => now(),
        ]);

        try {
            $rows = $direction === 'pull'
                ? $this->fetchRemoteRows($connection)
                : (array) ($input['rows'] ?? []);

            $fieldMap = $this->fieldMapFor($connection, $entity);

            $upserted = 0;
            $skipped = 0;
            $conflicts = 0;

            foreach ($rows as $row) {
                if (! is_array($row)) {
                    $skipped++;
                    continue;
                }

                $record = $this->applyMap($row, $fieldMap);

                if ($entity === 'products') {
                    $result = $this->upsertProduct($connection, $run, $record);
                } else {
                    $result = $this->applyMovement($connection, $run, $record);
                }

                match ($result) {
                    'upserted' => $upserted++,
                    'conflict' => $conflicts++,
                    default => $skipped++,
                };
            }

            if ($entity === 'products' && $upserted > 0 && $user !== null) {
                $this->publishCatalogue($connection, $user);
            }

            $run->forceFill([
                'status' => $conflicts > 0 ? 'partial' : 'completed',
                'records_in' => count($rows),
                'records_upserted' => $upserted,
                'records_skipped' => $skipped,
                'conflict_count' => $conflicts,
                'message' => $conflicts > 0
                    ? "{$upserted} row(s) applied, {$conflicts} conflict(s) need review."
                    : "{$upserted} row(s) applied.",
                'summary' => ['entity' => $entity, 'direction' => $direction],
                'finished_at' => now(),
            ])->save();

            $connection->forceFill([
                'last_synced_at' => now(),
                'last_error' => null,
                'status' => $connection->status === 'error' ? 'active' : $connection->status,
            ])->save();

            $this->audit->log('integration.sync_completed', $connection, null, null, [
                'run' => $run->uuid,
                'entity' => $entity,
                'upserted' => $upserted,
                'conflicts' => $conflicts,
            ], $connection->tenant_id, $user?->id);

            return $this->runPayload($run->fresh());
        } catch (Throwable $error) {
            $run->forceFill([
                'status' => 'failed',
                'message' => $error->getMessage(),
                'finished_at' => now(),
            ])->save();

            $connection->forceFill(['last_error' => $error->getMessage(), 'status' => 'error'])->save();

            return $this->runPayload($run->fresh());
        }
    }

    public function resolveConflict(IntegrationConflict $conflict, string $resolution, User $user): array
    {
        $conflict->forceFill([
            'resolution' => $resolution,
            'resolved_by' => $user->id,
            'resolved_at' => now(),
        ])->save();

        $this->audit->log('integration.conflict_resolved', $conflict, null, null, [
            'resolution' => $resolution,
            'reference' => $conflict->reference,
        ], $conflict->tenant_id, $user->id);

        return $this->conflictPayload($conflict->fresh());
    }

    public function receiveWebhook(IntegrationConnection $connection, Request $request): array
    {
        $secret = (string) ($connection->webhook_secret ?? '');
        $signature = (string) $request->header('X-Brathamatu-Signature', '');
        $valid = $secret !== '' && $signature !== ''
            && hash_equals(hash_hmac('sha256', $request->getContent(), $secret), $signature);

        if (! $valid) {
            abort(401, 'Invalid webhook signature.');
        }

        $event = IntegrationWebhookEvent::create([
            'tenant_id' => $connection->tenant_id,
            'connection_id' => $connection->id,
            'event' => (string) $request->input('event', 'unknown'),
            'payload' => $request->all(),
            'signature_valid' => true,
            'status' => 'received',
            'attempts' => 1,
        ]);

        try {
            $payload = (array) $request->input('data', []);
            $eventName = strtolower($event->event);

            if (str_contains($eventName, 'movement') || str_contains($eventName, 'stock')) {
                $this->runSync($connection, [
                    'entity' => 'movements',
                    'direction' => 'import',
                    'rows' => [$payload],
                ]);
            } elseif (str_contains($eventName, 'product') || str_contains($eventName, 'item')) {
                $this->runSync($connection, [
                    'entity' => 'products',
                    'direction' => 'import',
                    'rows' => [$payload],
                ]);
            }

            $event->forceFill(['status' => 'processed', 'processed_at' => now()])->save();
        } catch (Throwable $error) {
            $event->forceFill(['status' => 'failed', 'last_error' => $error->getMessage()])->save();
        }

        return $this->eventPayload($event->fresh());
    }

    public function listWebhookEvents(IntegrationConnection $connection): array
    {
        return IntegrationWebhookEvent::withoutGlobalScopes()
            ->where('connection_id', $connection->id)
            ->latest('id')
            ->limit(50)
            ->get()
            ->map(fn (IntegrationWebhookEvent $event) => $this->eventPayload($event))
            ->all();
    }

    private function fetchRemoteRows(IntegrationConnection $connection): array
    {
        $url = (string) ($connection->settings['url'] ?? '');
        $this->assertSafeUrl($url);

        $request = Http::retry(2, 500)->timeout(15)->acceptJson();
        $token = $connection->credentials['token'] ?? null;
        if (is_string($token) && $token !== '') {
            $request = $request->withToken($token);
        }

        $response = $request->get($url);
        if (! $response->successful()) {
            throw ValidationException::withMessages([
                'connection' => ['Remote endpoint returned HTTP '.$response->status().'.'],
            ]);
        }

        $json = $response->json();
        $rows = is_array($json) && isset($json['data']) && is_array($json['data'])
            ? $json['data']
            : $json;

        if (! is_array($rows)) {
            throw ValidationException::withMessages(['connection' => ['Remote response did not contain rows.']]);
        }

        return array_values($rows);
    }

    private function fieldMapFor(IntegrationConnection $connection, string $entity): array
    {
        $map = IntegrationMapping::withoutGlobalScopes()
            ->where('connection_id', $connection->id)
            ->where('entity', $entity)
            ->value('field_map');

        return is_array($map) ? $map : [];
    }

    private function applyMap(array $row, array $fieldMap): array
    {
        if ($fieldMap === []) {
            return $row;
        }

        $mapped = [];
        foreach ($fieldMap as $canonical => $external) {
            if (is_string($external) && $external !== '' && array_key_exists($external, $row)) {
                $mapped[$canonical] = $row[$external];
            }
        }

        foreach ($row as $key => $value) {
            if (! array_key_exists($key, $mapped)) {
                $mapped[$key] = $value;
            }
        }

        return $mapped;
    }

    private function upsertProduct(IntegrationConnection $connection, IntegrationSyncRun $run, array $record): string
    {
        $name = trim((string) ($record['name'] ?? ''));
        $sku = trim((string) ($record['sku'] ?? ''));

        if ($name === '' || $sku === '') {
            $this->recordConflict($connection, $run, 'products', $sku ?: null, 'missing_required_fields', $record);
            return 'conflict';
        }

        $variant = ProductVariant::withoutGlobalScopes()
            ->where('tenant_id', $connection->tenant_id)
            ->where('sku', $sku)
            ->first();

        if ($variant !== null) {
            $product = Product::withoutGlobalScopes()->find($variant->product_id);
            $accepted = $this->hasAcceptedConflict($connection, 'products', $sku);

            if (! $accepted && $product !== null && $product->name !== $name) {
                $this->recordConflict($connection, $run, 'products', $sku, 'name_mismatch', $record);
                return 'conflict';
            }

            $product?->forceFill([
                'name' => $name,
                'brand' => $record['brand'] ?? $product->brand,
                'supplier_ref' => $record['supplier_ref'] ?? $product->supplier_ref,
                'cost_price' => isset($record['cost_price']) ? (float) $record['cost_price'] : $product->cost_price,
                'status' => $this->normaliseProductStatus($record['status'] ?? $product->status),
                'source_system' => $connection->provider,
                'external_ref' => $record['external_ref'] ?? $product->external_ref,
            ])->save();

            $this->upsertBarcode($connection, $variant, $record);

            return 'upserted';
        }

        if (! empty($record['barcode'])) {
            $taken = ProductBarcode::withoutGlobalScopes()
                ->where('tenant_id', $connection->tenant_id)
                ->where('barcode', (string) $record['barcode'])
                ->exists();
            if ($taken) {
                $this->recordConflict($connection, $run, 'products', $sku, 'barcode_conflict', $record);
                return 'conflict';
            }
        }

        DB::transaction(function () use ($connection, $record, $name, $sku): void {
            $category = $this->resolveCategory($connection->tenant_id, $record['category'] ?? null);

            $product = Product::create([
                'tenant_id' => $connection->tenant_id,
                'name' => $name,
                'brand' => $record['brand'] ?? null,
                'category_id' => $category?->id,
                'description' => $record['description'] ?? null,
                'status' => $this->normaliseProductStatus($record['status'] ?? 'active'),
                'tracking_mode' => in_array($record['tracking_mode'] ?? 'quantity', ['quantity', 'batch', 'serial', 'expiry', 'combination'], true)
                    ? ($record['tracking_mode'] ?? 'quantity')
                    : 'quantity',
                'supplier_ref' => $record['supplier_ref'] ?? null,
                'cost_price' => isset($record['cost_price']) ? (float) $record['cost_price'] : null,
                'source_system' => $connection->provider,
                'external_ref' => $record['external_ref'] ?? null,
                'created_by' => $connection->created_by,
            ]);

            $baseUnit = $this->units->ensure($connection->tenant_id, (string) ($record['unit'] ?? 'piece'));
            $packagingUnit = ! empty($record['packaging_unit'])
                ? $this->units->ensure($connection->tenant_id, (string) $record['packaging_unit'])
                : null;

            $variant = ProductVariant::create([
                'tenant_id' => $connection->tenant_id,
                'product_id' => $product->id,
                'name' => $record['variant_name'] ?? null,
                'sku' => $sku,
                'base_unit_id' => $baseUnit->id,
                'packaging_unit_id' => $packagingUnit?->id,
                'packaging_factor' => $packagingUnit !== null && ! empty($record['packaging_factor'])
                    ? (float) $record['packaging_factor']
                    : null,
                'status' => $this->normaliseProductStatus($record['status'] ?? 'active') === 'discontinued'
                    ? 'inactive'
                    : $this->normaliseProductStatus($record['status'] ?? 'active'),
            ]);

            $this->upsertBarcode($connection, $variant, $record);
        });

        return 'upserted';
    }

    private function upsertBarcode(IntegrationConnection $connection, ProductVariant $variant, array $record): void
    {
        $barcode = trim((string) ($record['barcode'] ?? ''));
        if ($barcode === '') {
            return;
        }

        ProductBarcode::withoutGlobalScopes()->firstOrCreate(
            ['tenant_id' => $connection->tenant_id, 'barcode' => $barcode],
            [
                'variant_id' => $variant->id,
                'type' => in_array($record['barcode_type'] ?? 'internal', ['ean13', 'upc', 'code128', 'internal'], true)
                    ? ($record['barcode_type'] ?? 'internal')
                    : 'internal',
                'is_primary' => true,
            ],
        );
    }

    private function applyMovement(IntegrationConnection $connection, IntegrationSyncRun $run, array $record): string
    {
        $sku = trim((string) ($record['sku'] ?? ''));
        $quantity = $record['quantity'] ?? null;

        if ($sku === '' || ! is_numeric($quantity) || (float) $quantity === 0.0) {
            $this->recordConflict($connection, $run, 'movements', $sku ?: null, 'invalid_movement_row', $record);
            return 'conflict';
        }

        $variant = ProductVariant::withoutGlobalScopes()
            ->where('tenant_id', $connection->tenant_id)
            ->where('sku', $sku)
            ->first();

        if ($variant === null) {
            $this->recordConflict($connection, $run, 'movements', $sku, 'unknown_sku', $record);
            return 'conflict';
        }

        $location = StockLocation::withoutGlobalScopes()
            ->where('tenant_id', $connection->tenant_id)
            ->orderByRaw('branch_id IS NULL')
            ->orderBy('id')
            ->first();

        if ($location === null) {
            $location = StockLocation::create([
                'tenant_id' => $connection->tenant_id,
                'name' => 'Default location',
                'type' => 'default',
                'status' => 'active',
            ]);
        }

        $reference = trim((string) ($record['reference'] ?? '')) ?: 'EXT-'.Str::upper(Str::random(8));
        $unitCost = isset($record['unit_cost']) ? (float) $record['unit_cost'] : 0.0;
        $batchKey = '';

        DB::transaction(function () use ($connection, $variant, $location, $quantity, $reference, $unitCost, $batchKey, $record): void {
            $movement = InventoryMovement::create([
                'tenant_id' => $connection->tenant_id,
                'branch_id' => $location->branch_id,
                'stock_location_id' => $location->id,
                'type' => 'external_sync',
                'source' => 'integration:'.$connection->provider,
                'external_id' => $record['external_ref'] ?? $reference,
                'reference' => $reference,
                'occurred_at' => $record['occurred_at'] ?? now(),
                'status' => 'posted',
                'notes' => $record['notes'] ?? 'Imported from '.$connection->name,
                'metadata' => ['connection_id' => $connection->id],
                'line_count' => 1,
            ]);

            InventoryMovementLine::create([
                'tenant_id' => $connection->tenant_id,
                'movement_id' => $movement->id,
                'variant_id' => $variant->id,
                'batch_key' => $batchKey,
                'quantity' => (float) $quantity,
                'unit_cost' => $unitCost,
                'metadata' => ['connection_id' => $connection->id],
            ]);

            $balance = InventoryBalance::withoutGlobalScopes()->firstOrCreate(
                [
                    'tenant_id' => $connection->tenant_id,
                    'stock_location_id' => $location->id,
                    'variant_id' => $variant->id,
                    'batch_key' => $batchKey,
                ],
                [
                    'branch_id' => $location->branch_id,
                    'batch_id' => null,
                    'quantity' => 0,
                    'reserved_quantity' => 0,
                ],
            );

            $balance->quantity = (float) $balance->quantity + (float) $quantity;
            $balance->last_movement_at = now();
            $balance->save();
        });

        return 'upserted';
    }

    private function recordConflict(
        IntegrationConnection $connection,
        IntegrationSyncRun $run,
        string $entity,
        ?string $reference,
        string $reason,
        array $payload,
    ): void {
        IntegrationConflict::create([
            'tenant_id' => $connection->tenant_id,
            'connection_id' => $connection->id,
            'run_id' => $run->id,
            'entity' => $entity,
            'reference' => $reference,
            'reason' => $reason,
            'payload' => $payload,
            'resolution' => 'pending',
        ]);
    }

    private function hasAcceptedConflict(IntegrationConnection $connection, string $entity, string $reference): bool
    {
        return IntegrationConflict::withoutGlobalScopes()
            ->where('connection_id', $connection->id)
            ->where('entity', $entity)
            ->where('reference', $reference)
            ->where('resolution', 'accept_external')
            ->exists();
    }

    private function publishCatalogue(IntegrationConnection $connection, User $user): void
    {
        $catalogue = Catalogue::query()
            ->where('tenant_id', $connection->tenant_id)
            ->orderByDesc('id')
            ->first();

        $catalogue ??= Catalogue::create([
            'tenant_id' => $connection->tenant_id,
            'name' => 'Main catalogue',
            'status' => 'draft',
            'created_by' => $user->id,
        ]);

        $this->versions->publish($catalogue, $user, 'integration', 'Synced from '.$connection->name);
    }

    private function resolveCategory(int $tenantId, mixed $name): ?ProductCategory
    {
        $name = trim((string) $name);
        if ($name === '') {
            return null;
        }

        $existing = ProductCategory::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('name', $name)
            ->first();

        return $existing ?? ProductCategory::create([
            'tenant_id' => $tenantId,
            'name' => $name,
            'code' => Str::upper(Str::slug($name, '_')),
            'status' => 'active',
        ]);
    }

    private function normaliseProductStatus(mixed $status): string
    {
        $status = strtolower(trim((string) $status));

        return in_array($status, ['active', 'inactive', 'discontinued'], true) ? $status : 'active';
    }

    private function assertSafeUrl(string $url): void
    {
        if ($url === '' || ! filter_var($url, FILTER_VALIDATE_URL)) {
            throw ValidationException::withMessages(['settings.url' => ['Provide a valid HTTPS endpoint URL.']]);
        }

        $scheme = parse_url($url, PHP_URL_SCHEME);
        $host = parse_url($url, PHP_URL_HOST);
        if (strtolower((string) $scheme) !== 'https' || ! is_string($host)) {
            throw ValidationException::withMessages(['settings.url' => ['Integrations require an HTTPS endpoint.']]);
        }

        $address = gethostbyname($host);
        if (filter_var($address, FILTER_VALIDATE_IP) && ! filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            throw ValidationException::withMessages(['settings.url' => ['The endpoint must resolve to a public address.']]);
        }
    }

    private function connectionPayload(IntegrationConnection $connection): array
    {
        return [
            'id' => $connection->uuid,
            'name' => $connection->name,
            'provider' => $connection->provider,
            'type' => $connection->type,
            'status' => $connection->status,
            'settings' => (object) ($connection->settings ?? []),
            'has_credentials' => ! empty($connection->credentials),
            'has_webhook_secret' => $connection->webhook_secret !== null && $connection->webhook_secret !== '',
            'last_synced_at' => $connection->last_synced_at?->toIso8601String(),
            'last_error' => $connection->last_error,
            'runs_count' => $connection->runs_count ?? $connection->runs()->count(),
            'conflicts_count' => $connection->conflicts_count
                ?? $connection->conflicts()->where('resolution', 'pending')->count(),
            'created_at' => $connection->created_at?->toIso8601String(),
        ];
    }

    private function runPayload(IntegrationSyncRun $run): array
    {
        return [
            'id' => $run->uuid,
            'entity' => $run->entity,
            'direction' => $run->direction,
            'status' => $run->status,
            'records_in' => $run->records_in,
            'records_upserted' => $run->records_upserted,
            'records_skipped' => $run->records_skipped,
            'conflict_count' => $run->conflict_count,
            'message' => $run->message,
            'started_at' => $run->started_at?->toIso8601String(),
            'finished_at' => $run->finished_at?->toIso8601String(),
        ];
    }

    private function conflictPayload(IntegrationConflict $conflict): array
    {
        return [
            'id' => $conflict->uuid,
            'entity' => $conflict->entity,
            'reference' => $conflict->reference,
            'reason' => $conflict->reason,
            'payload' => (object) ($conflict->payload ?? []),
            'resolution' => $conflict->resolution,
            'resolved_at' => $conflict->resolved_at?->toIso8601String(),
            'created_at' => $conflict->created_at?->toIso8601String(),
        ];
    }

    private function eventPayload(IntegrationWebhookEvent $event): array
    {
        return [
            'id' => $event->uuid,
            'event' => $event->event,
            'signature_valid' => (bool) $event->signature_valid,
            'status' => $event->status,
            'attempts' => $event->attempts,
            'last_error' => $event->last_error,
            'processed_at' => $event->processed_at?->toIso8601String(),
            'created_at' => $event->created_at?->toIso8601String(),
        ];
    }
}
