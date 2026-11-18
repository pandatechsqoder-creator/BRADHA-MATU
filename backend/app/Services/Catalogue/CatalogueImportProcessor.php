<?php

namespace App\Services\Catalogue;

use App\Models\Catalogue;
use App\Models\CatalogueImport;
use App\Models\CatalogueImportRow;
use App\Models\CatalogueVersion;
use App\Models\Product;
use App\Models\ProductBarcode;
use App\Models\ProductCategory;
use App\Models\ProductVariant;
use App\Models\UnitConversion;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Throwable;

final class CatalogueImportProcessor
{
    public const CANONICAL_FIELDS = [
        'name' => 'Product name',
        'variant_name' => 'Variant / pack description',
        'sku' => 'SKU / item code',
        'barcode' => 'Barcode',
        'barcode_type' => 'Barcode type',
        'brand' => 'Brand',
        'category' => 'Category',
        'subcategory' => 'Subcategory',
        'description' => 'Description',
        'unit' => 'Base unit',
        'packaging_unit' => 'Packaging unit',
        'packaging_factor' => 'Units per package',
        'tracking_mode' => 'Tracking mode',
        'status' => 'Status',
        'supplier_ref' => 'Supplier reference',
        'cost_price' => 'Cost price',
    ];

    private const ALIASES = [
        'name' => ['product name', 'product', 'item name', 'item', 'description name', 'name'],
        'variant_name' => ['variant', 'variant name', 'pack description', 'option'],
        'sku' => ['sku', 'item code', 'product code', 'code', 'sku code', 'item number'],
        'barcode' => ['barcode', 'bar code', 'ean', 'upc', 'barcode number'],
        'barcode_type' => ['barcode type', 'bar code type', 'ean type'],
        'brand' => ['brand', 'manufacturer'],
        'category' => ['category', 'category name', 'product category'],
        'subcategory' => ['subcategory', 'sub category', 'sub-category'],
        'description' => ['description', 'product description', 'long description'],
        'unit' => ['unit', 'uom', 'base unit', 'unit of measure'],
        'packaging_unit' => ['packaging unit', 'package unit', 'pack unit', 'outer unit'],
        'packaging_factor' => ['packaging factor', 'units per package', 'units per pack', 'pack size', 'conversion factor', 'carton size', 'case size', 'box size', 'units per carton', 'pieces per carton'],
        'tracking_mode' => ['tracking mode', 'tracking', 'inventory tracking'],
        'status' => ['status', 'product status'],
        'supplier_ref' => ['supplier reference', 'supplier ref', 'supplier'],
        'cost_price' => ['cost price', 'cost', 'buying price', 'unit cost'],
    ];

    public function __construct(
        private readonly TabularFileReader $reader,
        private readonly UnitService $units,
    ) {}

    public function autoMap(array $headers): array
    {
        $mapping = [];
        $used = [];

        foreach (self::CANONICAL_FIELDS as $field => $label) {
            $normalisedHeaders = array_map(fn ($header) => $this->normalise((string) $header), $headers);
            $match = null;

            foreach (self::ALIASES[$field] ?? [] as $alias) {
                foreach ($normalisedHeaders as $index => $header) {
                    if ($header === $alias && ! isset($used[$index])) {
                        $match = $index;
                        break 2;
                    }
                }
            }

            if ($match === null) {
                foreach (self::ALIASES[$field] ?? [] as $alias) {
                    foreach ($normalisedHeaders as $index => $header) {
                        if ($header !== '' && str_contains($header, $alias) && ! isset($used[$index])) {
                            $match = $index;
                            break 2;
                        }
                    }
                }
            }

            if ($match !== null) {
                $mapping[$field] = $headers[$match];
                $used[$match] = true;
            }
        }

        return $mapping;
    }

    public function process(CatalogueImport $import): CatalogueImport
    {
        $import->update(['status' => 'processing', 'error_message' => null]);

        try {
            $absolutePath = Storage::disk($import->disk)->path($import->path);
            $parsed = $this->reader->read($absolutePath, $import->extension);

            $mapping = $this->sanitiseMapping($import->mapping ?: $this->autoMap($parsed['headers']), $parsed['headers']);

            $import->forceFill([
                'headers' => $parsed['headers'],
                'mapping' => $mapping,
            ])->save();

            $this->storeRows($import, $parsed['rows']);

            return $this->revalidate($import, $mapping);
        } catch (Throwable $exception) {
            $import->update([
                'status' => 'failed',
                'error_message' => $exception->getMessage(),
            ]);

            throw $exception;
        }
    }

    /**
     * @param  array<string, string>  $mapping
     */
    public function revalidate(CatalogueImport $import, array $mapping): CatalogueImport
    {
        $mapping = $this->sanitiseMapping($mapping, $import->headers ?? []);
        $rows = $import->rows()->get();

        $skuCounts = [];
        $barcodeCounts = [];
        $skuFirstRow = [];
        $barcodeFirstRow = [];
        $rawSkus = [];
        $rawBarcodes = [];
        foreach ($rows as $row) {
            $rawSku = trim($this->value($row->data, $mapping, 'sku'));
            $rawBarcode = trim($this->value($row->data, $mapping, 'barcode'));
            $sku = $this->normaliseValue($rawSku);
            $barcode = $this->normaliseValue($rawBarcode);
            if ($sku !== '') {
                $skuCounts[$sku] = ($skuCounts[$sku] ?? 0) + 1;
                $skuFirstRow[$sku] ??= $row->row_number;
                $rawSkus[$sku] = $rawSku;
            }
            if ($barcode !== '') {
                $barcodeCounts[$barcode] = ($barcodeCounts[$barcode] ?? 0) + 1;
                $barcodeFirstRow[$barcode] ??= $row->row_number;
                $rawBarcodes[$barcode] = $rawBarcode;
            }
        }

        $tenantId = $import->tenant_id;
        $existingSkus = ProductVariant::query()
            ->where('tenant_id', $tenantId)
            ->whereIn('sku', array_values($rawSkus))
            ->pluck('sku')
            ->map(fn ($sku) => $this->normaliseValue((string) $sku))
            ->all();
        $existingBarcodes = ProductBarcode::query()
            ->where('tenant_id', $tenantId)
            ->whereIn('barcode', array_values($rawBarcodes))
            ->pluck('barcode')
            ->map(fn ($barcode) => $this->normaliseValue((string) $barcode))
            ->all();

        $counts = ['valid' => 0, 'error' => 0, 'duplicate' => 0];

        foreach ($rows as $row) {
            [$errors, $status] = $this->validateRow(
                $row->data,
                $mapping,
                $row->row_number,
                $skuCounts,
                $skuFirstRow,
                $barcodeCounts,
                $barcodeFirstRow,
                $existingSkus,
                $existingBarcodes,
            );

            $row->update(['status' => $status, 'errors' => $errors ?: null]);
            $counts[$status]++;
        }

        $import->forceFill([
            'mapping' => $mapping,
            'status' => 'ready',
            'total_rows' => $rows->count(),
            'valid_rows' => $counts['valid'],
            'error_rows' => $counts['error'],
            'duplicate_rows' => $counts['duplicate'],
        ])->save();

        return $import->fresh();
    }

    /**
     * @return array{import: CatalogueImport, mapping: array<string, string>, rows: list<array<string, mixed>>}
     */
    public function preview(CatalogueImport $import, int $limit = 20): array
    {
        $mapping = $import->mapping ?? [];
        $rows = $import->rows()->orderBy('row_number')->limit($limit)->get()->map(fn (CatalogueImportRow $row) => [
            'row_number' => $row->row_number,
            'status' => $row->status,
            'errors' => $row->errors ?? [],
            'values' => collect(self::CANONICAL_FIELDS)
                ->map(fn ($label, $field) => $this->value($row->data, $mapping, $field))
                ->all(),
            'raw' => $row->data,
        ])->values()->all();

        return ['import' => $import, 'mapping' => $mapping, 'rows' => $rows];
    }

    public function commit(CatalogueImport $import, string $strategy, ?User $actor = null): CatalogueVersion
    {
        if (! in_array($import->status, ['ready', 'failed'], true)) {
            throw ValidationException::withMessages(['import' => ['This import is not ready to commit.']]);
        }

        $import = $this->revalidate($import, $import->mapping ?? []);

        if ($import->partial_policy === 'all_or_nothing' && $import->error_rows > 0) {
            throw ValidationException::withMessages([
                'import' => ['This import is set to all-or-nothing and still has rows with errors.'],
            ]);
        }

        $strategy = in_array($strategy, ['skip', 'update'], true) ? $strategy : 'skip';

        $rows = $import->rows()
            ->where(function ($query) use ($strategy): void {
                $query->where('status', 'valid');
                if ($strategy === 'update') {
                    $query->orWhere('status', 'duplicate');
                }
            })
            ->orderBy('row_number')
            ->get();

        if ($rows->isEmpty()) {
            throw ValidationException::withMessages(['import' => ['There are no rows available to import.']]);
        }

        return DB::transaction(function () use ($import, $rows, $strategy, $actor): CatalogueVersion {
            $tenantId = $import->tenant_id;
            $mapping = $import->mapping ?? [];

            $catalogue = Catalogue::query()
                ->where('tenant_id', $tenantId)
                ->orderByDesc('id')
                ->first();

            if ($catalogue === null) {
                $catalogue = Catalogue::create([
                    'tenant_id' => $tenantId,
                    'name' => 'Main catalogue',
                    'status' => 'draft',
                    'created_by' => $actor?->id,
                ]);
            }

            $categoryCache = [];
            $unitCache = [];

            foreach ($rows as $row) {
                $data = $row->data;
                $name = trim($this->value($data, $mapping, 'name'));
                $sku = trim($this->value($data, $mapping, 'sku'));

                if ($name === '' || $sku === '') {
                    continue;
                }

                $brand = trim($this->value($data, $mapping, 'brand')) ?: null;
                $categoryName = trim($this->value($data, $mapping, 'category'));
                $subcategoryName = trim($this->value($data, $mapping, 'subcategory'));

                $category = null;
                if ($categoryName !== '') {
                    $category = $categoryCache[$categoryName] ??= ProductCategory::firstOrCreate(
                        ['tenant_id' => $tenantId, 'name' => $categoryName],
                        ['status' => 'active'],
                    );
                }

                $unitCode = $this->unitCode($this->value($data, $mapping, 'unit')) ?: 'piece';
                $unit = $unitCache[$unitCode] ??= $this->units->ensure($tenantId, $unitCode);

                $tracking = strtolower(trim($this->value($data, $mapping, 'tracking_mode'))) ?: 'quantity';
                $status = strtolower(trim($this->value($data, $mapping, 'status'))) ?: 'active';
                $cost = $this->value($data, $mapping, 'cost_price');
                $variantName = trim($this->value($data, $mapping, 'variant_name')) ?: null;
                $description = trim($this->value($data, $mapping, 'description')) ?: null;

                $product = Product::firstOrCreate(
                    ['tenant_id' => $tenantId, 'name' => $name, 'brand' => $brand],
                    [
                        'category_id' => $category?->id,
                        'description' => $description,
                        'status' => $status,
                        'tracking_mode' => $tracking,
                        'supplier_ref' => trim($this->value($data, $mapping, 'supplier_ref')) ?: null,
                        'cost_price' => is_numeric($cost) ? $cost : null,
                        'source_system' => 'import',
                        'created_by' => $actor?->id,
                    ],
                );

                if ($strategy === 'update' && ! $product->wasRecentlyCreated) {
                    $product->fill([
                        'category_id' => $category?->id ?? $product->category_id,
                        'description' => $description ?? $product->description,
                        'status' => $status,
                        'tracking_mode' => $tracking,
                    ]);
                    if ($product->isDirty()) {
                        $product->save();
                    }
                }

                $packagingCode = $this->unitCode($this->value($data, $mapping, 'packaging_unit'));
                $packagingUnit = null;
                $packagingFactor = null;

                if ($packagingCode !== '' && $packagingCode !== $unitCode) {
                    $packagingUnit = $unitCache[$packagingCode] ??= $this->units->ensure($tenantId, $packagingCode);
                    $factor = $this->value($data, $mapping, 'packaging_factor');
                    $packagingFactor = is_numeric($factor) && (float) $factor > 0 ? (float) $factor : null;

                    if ($packagingFactor !== null) {
                        UnitConversion::firstOrCreate(
                            [
                                'tenant_id' => $tenantId,
                                'from_unit_id' => $packagingUnit->id,
                                'to_unit_id' => $unit->id,
                            ],
                            ['factor' => $packagingFactor],
                        );
                    }
                }

                $variant = ProductVariant::query()
                    ->where('tenant_id', $tenantId)
                    ->whereRaw('lower(sku) = ?', [mb_strtolower($sku)])
                    ->first();

                if ($variant === null) {
                    $variant = new ProductVariant(['tenant_id' => $tenantId, 'sku' => $sku]);
                    $variant->fill([
                        'product_id' => $product->id,
                        'name' => $variantName,
                        'base_unit_id' => $unit->id,
                        'packaging_unit_id' => $packagingUnit?->id,
                        'packaging_factor' => $packagingFactor,
                        'attributes' => $subcategoryName !== '' ? ['subcategory' => $subcategoryName] : null,
                        'status' => $status === 'discontinued' ? 'inactive' : $status,
                    ]);
                    $variant->save();
                } elseif ($strategy === 'update') {
                    $variant->fill([
                        'product_id' => $product->id,
                        'name' => $variantName ?? $variant->name,
                        'base_unit_id' => $unit->id,
                        'packaging_unit_id' => $packagingUnit?->id ?? $variant->packaging_unit_id,
                        'packaging_factor' => $packagingFactor ?? $variant->packaging_factor,
                        'status' => $status === 'discontinued' ? 'inactive' : $status,
                    ]);
                    if ($variant->isDirty()) {
                        $variant->save();
                    }
                }

                $barcode = trim($this->value($data, $mapping, 'barcode'));
                if ($barcode !== '') {
                    $type = strtolower(trim($this->value($data, $mapping, 'barcode_type'))) ?: 'internal';
                    $existingBarcode = ProductBarcode::query()
                        ->where('tenant_id', $tenantId)
                        ->whereRaw('lower(barcode) = ?', [mb_strtolower($barcode)])
                        ->first();

                    if ($existingBarcode === null) {
                        ProductBarcode::create([
                            'tenant_id' => $tenantId,
                            'variant_id' => $variant->id,
                            'barcode' => $barcode,
                            'type' => in_array($type, ['ean13', 'upc', 'code128', 'internal'], true) ? $type : 'internal',
                            'is_primary' => true,
                        ]);
                    }
                }
            }

            $productCount = ProductVariant::query()
                ->where('tenant_id', $tenantId)
                ->where('status', 'active')
                ->count();

            $lastVersion = (int) CatalogueVersion::query()
                ->where('catalogue_id', $catalogue->id)
                ->max('version_number');

            CatalogueVersion::query()
                ->where('catalogue_id', $catalogue->id)
                ->where('status', 'active')
                ->update(['status' => 'superseded']);

            $version = CatalogueVersion::create([
                'tenant_id' => $tenantId,
                'catalogue_id' => $catalogue->id,
                'version_number' => $lastVersion + 1,
                'status' => 'active',
                'product_count' => $productCount,
                'source' => 'import',
                'import_id' => $import->id,
                'created_by' => $actor?->id,
                'notes' => "Imported from {$import->original_name}",
            ]);

            $catalogue->update([
                'current_version_id' => $version->id,
                'status' => 'active',
                'confirmed_at' => null,
            ]);

            $import->update([
                'catalogue_id' => $catalogue->id,
                'status' => 'committed',
                'committed_version_id' => $version->id,
            ]);

            return $version;
        });
    }

    public function errorReport(CatalogueImport $import): string
    {
        $mapping = $import->mapping ?? [];
        $handle = fopen('php://temp', 'r+');
        $headers = ['row_number', 'status', 'errors', ...array_values(self::CANONICAL_FIELDS)];
        fputcsv($handle, $headers, ',', '"', '\\');

        foreach ($import->rows()->whereIn('status', ['error', 'duplicate'])->orderBy('row_number')->get() as $row) {
            $line = [$row->row_number, $row->status, implode(' | ', $row->errors ?? [])];
            foreach (array_keys(self::CANONICAL_FIELDS) as $field) {
                $line[] = $this->value($row->data, $mapping, $field);
            }
            fputcsv($handle, $line, ',', '"', '\\');
        }

        rewind($handle);
        $csv = stream_get_contents($handle) ?: '';
        fclose($handle);

        return $csv;
    }

    /**
     * @param  list<string>  $headers
     * @return array<string, string>
     */
    private function sanitiseMapping(array $mapping, array $headers): array
    {
        $clean = [];
        foreach ($mapping as $field => $header) {
            if (! array_key_exists($field, self::CANONICAL_FIELDS)) {
                continue;
            }
            if (! in_array($header, $headers, true)) {
                continue;
            }
            $clean[$field] = $header;
        }

        return $clean;
    }

    /**
     * @param  list<array<string, string>>  $rows
     */
    private function storeRows(CatalogueImport $import, array $rows): void
    {
        $import->rows()->delete();

        $records = [];
        foreach ($rows as $row) {
            $records[] = [
                'tenant_id' => $import->tenant_id,
                'import_id' => $import->id,
                'row_number' => (int) ($row['__row_number'] ?? count($records) + 2),
                'data' => json_encode($row),
                'status' => 'valid',
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        foreach (array_chunk($records, 200) as $chunk) {
            DB::table('catalogue_import_rows')->insert($chunk);
        }
    }

    /**
     * @return array{0: list<string>, 1: string}
     */
    private function validateRow(
        array $data,
        array $mapping,
        int $rowNumber,
        array $skuCounts,
        array $skuFirstRow,
        array $barcodeCounts,
        array $barcodeFirstRow,
        array $existingSkus,
        array $existingBarcodes,
    ): array {
        $errors = [];

        $name = trim($this->value($data, $mapping, 'name'));
        $sku = $this->normaliseValue($this->value($data, $mapping, 'sku'));
        $barcode = $this->normaliseValue($this->value($data, $mapping, 'barcode'));

        if ($name === '') {
            $errors[] = 'Product name is required.';
        }

        if ($sku === '') {
            $errors[] = 'SKU / item code is required.';
        } elseif (($skuCounts[$sku] ?? 0) > 1 && ($skuFirstRow[$sku] ?? $rowNumber) !== $rowNumber) {
            $errors[] = 'This SKU appears more than once in the file.';
        }

        if ($barcode !== '' && ($barcodeCounts[$barcode] ?? 0) > 1 && ($barcodeFirstRow[$barcode] ?? $rowNumber) !== $rowNumber) {
            $errors[] = 'This barcode appears more than once in the file.';
        }

        if ($barcode !== '' && in_array($barcode, $existingBarcodes, true)) {
            $errors[] = 'This barcode is already registered to another product.';
        }

        $status = strtolower(trim($this->value($data, $mapping, 'status')));
        if ($status !== '' && ! in_array($status, ['active', 'inactive', 'discontinued'], true)) {
            $errors[] = 'Status must be active, inactive or discontinued.';
        }

        $tracking = strtolower(trim($this->value($data, $mapping, 'tracking_mode')));
        if ($tracking !== '' && ! in_array($tracking, ['quantity', 'batch', 'serial', 'expiry', 'combination'], true)) {
            $errors[] = 'Tracking mode is not recognised.';
        }

        $packagingUnit = $this->unitCode($this->value($data, $mapping, 'packaging_unit'));
        $factor = $this->value($data, $mapping, 'packaging_factor');
        if ($packagingUnit !== '' && (! is_numeric($factor) || (float) $factor <= 0)) {
            $errors[] = 'Packaging unit requires a conversion factor greater than zero.';
        }

        $cost = $this->value($data, $mapping, 'cost_price');
        if ($cost !== '' && (! is_numeric($cost) || (float) $cost < 0)) {
            $errors[] = 'Cost price must be a non-negative number.';
        }

        if ($errors !== []) {
            return [$errors, 'error'];
        }

        if ($sku !== '' && in_array($sku, $existingSkus, true)) {
            return [[], 'duplicate'];
        }

        return [[], 'valid'];
    }

    private function value(array $data, array $mapping, string $field): string
    {
        $header = $mapping[$field] ?? null;
        if ($header === null) {
            return '';
        }

        return trim((string) ($data[$header] ?? ''));
    }

    private function normaliseValue(string $value): string
    {
        return strtolower(trim($value));
    }

    private function normalise(string $value): string
    {
        $value = strtolower(trim($value));

        return trim((string) preg_replace('/[^a-z0-9]+/', ' ', $value));
    }

    private function unitCode(string $value): string
    {
        return $this->units->normaliseCode($value);
    }
}
