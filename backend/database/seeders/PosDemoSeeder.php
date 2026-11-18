<?php

namespace Database\Seeders;

use App\Models\Branch;
use App\Models\Catalogue;
use App\Models\InventoryBalance;
use App\Models\PosTerminal;
use App\Models\PrepaidAgreement;
use App\Models\Product;
use App\Models\ProductBarcode;
use App\Models\ProductVariant;
use App\Models\StockLocation;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Catalogue\CatalogueVersionService;
use App\Services\Catalogue\UnitService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Local/staging demo data ONLY. Seeds POS catalogue, terminals and a prepaid agreement.
 */
class PosDemoSeeder extends Seeder
{
    public function run(): void
    {
        $units = app(UnitService::class);

        $catalogues = [
            'Demo Supermarket' => [
                'DSM',
                [['Till 1', 'DSM-POS-01'], ['Till 2', 'DSM-POS-02']],
                [
                    ['Sugar 1kg', 'SUG-1KG', '6001234500011', 'piece', 118, 140],
                    ['Maize Flour 2kg', 'MAI-2KG', '6001234500028', 'piece', 145, 175],
                    ['Cooking Oil 1L', 'OIL-1L', '6001234500035', 'litre', 265, 320],
                    ['Rice 5kg', 'RIC-5KG', '6001234500042', 'piece', 640, 780],
                    ['Milk 500ml', 'MIL-500', '6001234500059', 'piece', 52, 65],
                    ['Bread 400g', 'BRE-400', '6001234500066', 'piece', 55, 70],
                    ['Soap Bar 800g', 'SOA-800', '6001234500073', 'piece', 180, 220],
                    ['Tea Leaves 250g', 'TEA-250', '6001234500080', 'piece', 95, 120],
                ],
                true,
            ],
            'Demo Restaurant' => [
                'DRS',
                [['Counter 1', 'DRS-POS-01']],
                [
                    ['Beef Stew Plate', 'RST-BEEF', '6002234500012', 'piece', 220, 350],
                    ['Chicken Plate', 'RST-CHKN', '6002234500029', 'piece', 240, 380],
                    ['Ugali Portion', 'RST-UGAL', '6002234500036', 'piece', 25, 40],
                    ['Soda 300ml', 'RST-SODA', '6002234500043', 'piece', 45, 70],
                    ['Mineral Water 500ml', 'RST-WATR', '6002234500050', 'piece', 30, 50],
                ],
                false,
            ],
        ];

        foreach ($catalogues as $tenantName => [$prefix, $terminals, $products, $withPrepaid]) {
            $tenant = Tenant::withoutGlobalScope('tenant')->where('name', $tenantName)->first();
            if ($tenant === null) {
                continue;
            }

            $branch = Branch::where('tenant_id', $tenant->id)->orderBy('id')->first();
            $owner = User::where('email', $prefix === 'DSM'
                ? 'owner@demosupermarket.test'
                : 'ops@' . Str::lower(str_replace('Demo ', 'demo', $tenantName)) . '.test')->first();

            $location = StockLocation::withoutGlobalScope('tenant')->firstOrCreate(
                ['tenant_id' => $tenant->id, 'name' => 'Shop floor'],
                ['branch_id' => $branch?->id, 'type' => 'floor', 'status' => 'active'],
            );

            foreach ($products as [$name, $sku, $barcode, $unitCode, $cost, $price]) {
                $product = Product::withoutGlobalScope('tenant')->firstOrCreate(
                    ['tenant_id' => $tenant->id, 'name' => $name],
                    [
                        'status' => 'active',
                        'tracking_mode' => 'quantity',
                        'cost_price' => $cost,
                        'source_system' => 'seed',
                        'created_by' => $owner?->id,
                    ],
                );

                $unit = $units->ensure($tenant->id, $unitCode);

                $variant = ProductVariant::withoutGlobalScope('tenant')->firstOrCreate(
                    ['tenant_id' => $tenant->id, 'sku' => $sku],
                    [
                        'product_id' => $product->id,
                        'name' => null,
                        'base_unit_id' => $unit->id,
                        'attributes' => ['retail_price' => $price],
                        'status' => 'active',
                    ],
                );

                ProductBarcode::withoutGlobalScope('tenant')->firstOrCreate(
                    ['tenant_id' => $tenant->id, 'barcode' => $barcode],
                    [
                        'variant_id' => $variant->id,
                        'type' => 'ean13',
                        'is_primary' => true,
                    ],
                );

                InventoryBalance::withoutGlobalScope('tenant')->firstOrCreate(
                    [
                        'tenant_id' => $tenant->id,
                        'stock_location_id' => $location->id,
                        'variant_id' => $variant->id,
                        'batch_key' => '',
                    ],
                    [
                        'branch_id' => $location->branch_id,
                        'quantity' => 60,
                        'reserved_quantity' => 0,
                        'last_movement_at' => now(),
                    ],
                );
            }

            foreach ($terminals as [$name, $deviceId]) {
                PosTerminal::withoutGlobalScope('tenant')->firstOrCreate(
                    ['tenant_id' => $tenant->id, 'name' => $name],
                    [
                        'branch_id' => $branch?->id,
                        'device_id' => $deviceId,
                        'status' => 'active',
                    ],
                );
            }

            $catalogue = Catalogue::withoutGlobalScope('tenant')->firstOrCreate(
                ['tenant_id' => $tenant->id],
                ['name' => 'Main catalogue', 'status' => 'active', 'created_by' => $owner?->id],
            );

            if ($catalogue->current_version_id === null) {
                app(CatalogueVersionService::class)->publish(
                    $catalogue,
                    $owner,
                    'seed',
                    'Seeded demo catalogue.',
                );
                $catalogue->forceFill(['confirmed_at' => now()])->save();
            }

            if ($withPrepaid) {
                PrepaidAgreement::withoutGlobalScope('tenant')->firstOrCreate(
                    ['tenant_id' => $tenant->id, 'reference' => 'PPA-DSM-2026'],
                    [
                        'amount' => 25000,
                        'currency' => 'KES',
                        'status' => 'active',
                        'valid_until' => now()->addYear(),
                        'terms_conditions' => 'Prepaid stock-taking credit for local demo environment.',
                    ],
                );
            }
        }
    }
}
