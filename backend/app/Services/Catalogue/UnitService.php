<?php

namespace App\Services\Catalogue;

use App\Models\UnitOfMeasure;

final class UnitService
{
    public const DEFAULTS = [
        'piece' => ['Piece', 'count', 1, true],
        'pack' => ['Pack', 'count', 1, false],
        'box' => ['Box', 'count', 1, false],
        'carton' => ['Carton', 'count', 1, false],
        'kg' => ['Kilogram', 'weight', 1, true],
        'g' => ['Gram', 'weight', 0.001, false],
        'litre' => ['Litre', 'volume', 1, true],
        'ml' => ['Millilitre', 'volume', 0.001, false],
        'metre' => ['Metre', 'length', 1, true],
        'roll' => ['Roll', 'length', 1, false],
    ];

    public function ensure(int $tenantId, string $code): UnitOfMeasure
    {
        $code = $this->normaliseCode($code) ?: 'piece';

        $existing = UnitOfMeasure::query()
            ->where('tenant_id', $tenantId)
            ->where('code', $code)
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        [$name, $dimension, $factor, $isBase] = self::DEFAULTS[$code]
            ?? [ucfirst(str_replace('_', ' ', $code)), 'count', 1, false];

        return UnitOfMeasure::create([
            'tenant_id' => $tenantId,
            'code' => $code,
            'name' => $name,
            'dimension' => $dimension,
            'factor_to_base' => $factor,
            'is_base' => $isBase,
            'status' => 'active',
        ]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function directory(int $tenantId): array
    {
        $custom = UnitOfMeasure::query()
            ->where('tenant_id', $tenantId)
            ->orderBy('name')
            ->get();

        $units = [];

        foreach (self::DEFAULTS as $code => [$name, $dimension, $factor, $isBase]) {
            $record = $custom->firstWhere('code', $code);
            $units[] = [
                'id' => $record?->uuid,
                'code' => $code,
                'name' => $record?->name ?? $name,
                'dimension' => $record?->dimension ?? $dimension,
                'factor_to_base' => (float) ($record?->factor_to_base ?? $factor),
                'is_base' => (bool) ($record?->is_base ?? $isBase),
                'custom' => $record !== null,
            ];
        }

        foreach ($custom as $record) {
            if (array_key_exists($record->code, self::DEFAULTS)) {
                continue;
            }
            $units[] = [
                'id' => $record->uuid,
                'code' => $record->code,
                'name' => $record->name,
                'dimension' => $record->dimension,
                'factor_to_base' => (float) $record->factor_to_base,
                'is_base' => (bool) $record->is_base,
                'custom' => true,
            ];
        }

        return $units;
    }

    public function normaliseCode(string $code): string
    {
        $code = strtolower(trim($code));

        return trim((string) preg_replace('/[^a-z0-9]+/', '_', $code), '_');
    }
}
