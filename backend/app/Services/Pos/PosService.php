<?php

namespace App\Services\Pos;

use App\Models\Branch;
use App\Models\InventoryBalance;
use App\Models\InventoryBatch;
use App\Models\InventoryMovement;
use App\Models\InventoryMovementLine;
use App\Models\PosCustomer;
use App\Models\PosPurchase;
use App\Models\PosPurchaseItem;
use App\Models\PosReceipt;
use App\Models\PosReturn;
use App\Models\PosReturnItem;
use App\Models\PosSale;
use App\Models\PosSaleItem;
use App\Models\PosShift;
use App\Models\PosSupplier;
use App\Models\PosTerminal;
use App\Models\PosTransfer;
use App\Models\PosTransferItem;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\StockLocation;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class PosService
{
    public function listTerminals(Tenant $tenant): array
    {
        return PosTerminal::where('tenant_id', $tenant->id)
            ->orderBy('name')
            ->get()
            ->map(fn (PosTerminal $terminal) => $this->terminalPayload($terminal))
            ->all();
    }

    public function searchProducts(Tenant $tenant, array $filters): array
    {
        $terminal = PosTerminal::query()
            ->where('tenant_id', $tenant->id)
            ->where('uuid', $filters['terminal_id'])
            ->first();

        if (! $terminal) {
            throw ValidationException::withMessages([
                'terminal_id' => ['Select a POS terminal belonging to this business.'],
            ]);
        }

        $query = Product::query()
            ->where('tenant_id', $tenant->id)
            ->where('status', 'active')
            ->with([
                'category',
                'variants' => fn ($variants) => $variants
                    ->where('tenant_id', $tenant->id)
                    ->where('status', 'active')
                    ->with(['baseUnit', 'barcodes']),
            ])
            ->when($filters['search'] ?? null, function ($products, $search): void {
                $products->where(function ($matches) use ($search): void {
                    $matches->where('name', 'like', "%{$search}%")
                        ->orWhere('brand', 'like', "%{$search}%")
                        ->orWhereHas('variants', fn ($variants) => $variants->where('sku', 'like', "%{$search}%"))
                        ->orWhereHas('barcodes', fn ($barcodes) => $barcodes->where('barcode', 'like', "%{$search}%"));
                });
            })
            ->when($filters['brand'] ?? null, fn ($products, $brand) => $products->where('brand', 'like', "%{$brand}%"))
            ->when($filters['category'] ?? null, function ($products, $category): void {
                $products->whereHas('category', fn ($query) => $query
                    ->where('uuid', $category)
                    ->orWhere('name', $category));
            })
            ->whereHas('variants', fn ($variants) => $variants
                ->where('tenant_id', $tenant->id)
                ->where('status', 'active'))
            ->orderBy('name');

        $page = $query->paginate($filters['per_page'] ?? 12);
        $products = collect($page->items())->map(function (Product $product): array {
            $variants = $product->variants->map(function (ProductVariant $variant): array {
                $primaryBarcode = $variant->barcodes->firstWhere('is_primary', true)
                    ?? $variant->barcodes->first();

                return [
                    'id' => $variant->uuid,
                    'name' => $variant->name,
                    'sku' => $variant->sku,
                    'status' => $variant->status,
                    'base_unit' => $variant->baseUnit?->code,
                    'attributes' => (object) ($variant->attributes ?? []),
                    'barcodes' => $variant->barcodes->map(fn ($barcode) => [
                        'id' => $barcode->uuid,
                        'barcode' => $barcode->barcode,
                        'type' => $barcode->type,
                        'is_primary' => (bool) $barcode->is_primary,
                    ])->values(),
                    'barcode' => $primaryBarcode?->barcode,
                    'selling_price' => $this->configuredSellingPrice($variant),
                ];
            })->values();
            $primary = $variants->first();

            return [
                'id' => $product->uuid,
                'name' => $product->name,
                'brand' => $product->brand,
                'category' => $product->category ? [
                    'id' => $product->category->uuid,
                    'name' => $product->category->name,
                ] : null,
                'status' => $product->status,
                'sku' => $primary['sku'] ?? null,
                'barcode' => $primary['barcode'] ?? null,
                'base_unit' => $primary['base_unit'] ?? null,
                'variants_count' => $variants->count(),
                'variants' => $variants,
            ];
        })->values()->all();

        return [
            'products' => $products,
            'pagination' => [
                'current_page' => $page->currentPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
                'last_page' => $page->lastPage(),
            ],
            'terminal_id' => $terminal->uuid,
            'branch_id' => $terminal->branch?->uuid,
        ];
    }

    public function createTerminal(Tenant $tenant, array $data, User $user): array
    {
        $terminal = PosTerminal::create([
            'tenant_id' => $tenant->id,
            'branch_id' => $this->resolveBranchId($data['branch_id'] ?? null),
            'name' => $data['name'],
            'device_id' => $data['device_id'] ?? null,
            'status' => $data['status'] ?? 'active',
            'notes' => $data['notes'] ?? null,
        ]);

        return $this->terminalPayload($terminal);
    }

    public function updateTerminal(PosTerminal $terminal, array $data): array
    {
        if (array_key_exists('branch_id', $data)) {
            $data['branch_id'] = $this->resolveBranchId($data['branch_id']);
        }

        $terminal->update($data);

        return $this->terminalPayload($terminal->fresh());
    }

    public function openShift(PosTerminal $terminal, array $data, User $user): array
    {
        if ($terminal->currentShift()) {
            throw ValidationException::withMessages(['terminal' => ['Terminal already has an open shift.']]);
        }

        $openingFloat = (float) ($data['opening_float'] ?? 0);

        $shift = PosShift::create([
            'tenant_id' => $terminal->tenant_id,
            'terminal_id' => $terminal->id,
            'opened_by' => $user->id,
            'opening_float' => $openingFloat,
            'expected_cash' => $openingFloat,
            'status' => 'open',
            'opened_at' => now(),
            'notes' => $data['notes'] ?? null,
        ]);

        return $this->shiftPayload($shift->fresh('sales'));
    }

    public function closeShift(PosShift $shift, array $data, User $user): array
    {
        if ($shift->status !== 'open') {
            throw ValidationException::withMessages(['shift' => ['Shift is not open.']]);
        }

        $actualCash = (float) ($data['actual_cash'] ?? 0);
        $expectedCash = (float) $shift->opening_float + $shift->totalCashSales() - $shift->totalCashRefunds();

        $shift->update([
            'closed_by' => $user->id,
            'actual_cash' => $actualCash,
            'expected_cash' => $expectedCash,
            'cash_variance' => $actualCash - $expectedCash,
            'status' => 'closed',
            'closed_at' => now(),
            'notes' => $data['notes'] ?? $shift->notes,
        ]);

        return $this->shiftPayload($shift->fresh('sales'));
    }

    public function createSale(Tenant $tenant, array $data, User $user): array
    {
        $terminal = PosTerminal::where('uuid', $data['terminal_id'])->first();
        if (! $terminal || $terminal->tenant_id !== $tenant->id) {
            throw ValidationException::withMessages(['terminal_id' => ['Terminal not found.']]);
        }

        $shift = PosShift::where('terminal_id', $terminal->id)->where('status', 'open')->first();
        if (! $shift) {
            throw ValidationException::withMessages(['shift' => ['No open shift found. Open a shift before making sales.']]);
        }

        $items = $data['items'] ?? [];
        if (empty($items)) {
            throw ValidationException::withMessages(['items' => ['At least one item is required.']]);
        }

        $subtotal = 0.0;
        $taxAmount = 0.0;
        $discountAmount = 0.0;
        $lines = [];

        foreach ($items as $item) {
            $variant = ProductVariant::with(['product', 'baseUnit', 'barcodes'])
                ->where('uuid', $item['variant_id'])
                ->first();
            if (! $variant || $variant->tenant_id !== $tenant->id) {
                throw ValidationException::withMessages(['items' => ['One of the selected products is not in this catalogue.']]);
            }
            if ($variant->status !== 'active' || $variant->product?->status !== 'active') {
                throw ValidationException::withMessages(['items' => ['Only active catalogue products can be sold.']]);
            }

            $quantity = (float) $item['quantity'];
            if ($quantity <= 0) {
                throw ValidationException::withMessages(['items' => ['Item quantities must be greater than zero.']]);
            }

            $unitPrice = $this->configuredSellingPrice($variant);
            if ($unitPrice === null) {
                throw ValidationException::withMessages([
                    'items' => ["No selling price is configured for {$variant->product->name}. Add a retail_price, selling_price, unit_price or price to the catalogue variant before selling."],
                ]);
            }

            $lineTotal = round($quantity * $unitPrice, 2);
            $tax = (float) ($item['tax_amount'] ?? 0);
            $discount = (float) ($item['discount_amount'] ?? 0);
            if ($discount > $lineTotal) {
                throw ValidationException::withMessages(['items' => ['A line discount cannot exceed its selling amount.']]);
            }

            $subtotal += $lineTotal;
            $taxAmount += $tax;
            $discountAmount += $discount;

            $lines[] = [
                'variant' => $variant,
                'batch_id' => $this->resolveBatchId($item['batch_id'] ?? null),
                'product_name' => $variant->product->name.($variant->name ? ' — '.$variant->name : ''),
                'sku' => $variant->sku,
                'barcode' => $this->saleBarcode($variant, $item['barcode'] ?? null),
                'quantity' => $quantity,
                'unit' => $variant->baseUnit?->code ?? 'pcs',
                'unit_price' => $unitPrice,
                'line_total' => $lineTotal,
                'tax_amount' => $tax,
                'discount_amount' => $discount,
            ];
        }

        $subtotal = round($subtotal, 2);
        $taxAmount = round($taxAmount, 2);
        $discountAmount = round($discountAmount, 2);
        $totalAmount = round($subtotal + $taxAmount - $discountAmount, 2);

        $paymentMethod = $data['payment_method'] ?? 'cash';
        $cashTendered = (float) ($data['cash_tendered'] ?? 0);
        $cashChange = $paymentMethod === 'cash' ? max(0, round($cashTendered - $totalAmount, 2)) : 0;

        $sale = PosSale::create([
            'tenant_id' => $tenant->id,
            'branch_id' => $terminal->branch_id,
            'terminal_id' => $terminal->id,
            'shift_id' => $shift->id,
            'user_id' => $user->id,
            'customer_id' => $this->resolveCustomerId($data['customer_id'] ?? null),
            'reference' => 'POS-' . Str::upper(Str::random(8)),
            'status' => 'completed',
            'subtotal' => $subtotal,
            'tax_amount' => $taxAmount,
            'discount_amount' => $discountAmount,
            'total_amount' => $totalAmount,
            'currency' => $data['currency'] ?? 'KES',
            'payment_method' => $paymentMethod,
            'cash_tendered' => $cashTendered,
            'cash_change' => $cashChange,
            'notes' => $data['notes'] ?? null,
        ]);

        foreach ($lines as $line) {
            PosSaleItem::create([
                'sale_id' => $sale->id,
                'variant_id' => $line['variant']->id,
                'batch_id' => $line['batch_id'],
                'product_name' => $line['product_name'],
                'sku' => $line['sku'],
                'barcode' => $line['barcode'],
                'quantity' => $line['quantity'],
                'unit' => $line['unit'],
                'unit_price' => $line['unit_price'],
                'line_total' => $line['line_total'],
                'tax_amount' => $line['tax_amount'],
                'discount_amount' => $line['discount_amount'],
            ]);

            $this->recordMovement(
                $tenant,
                $sale->branch_id,
                $line['variant'],
                -$line['quantity'],
                $sale->reference,
                'sale',
                'brathamatu_pos',
                $sale->uuid,
                $user,
                0,
                $line['batch_id'],
                ['sale_id' => $sale->id, 'terminal_id' => $sale->terminal_id],
            );
        }

        $receiptNumber = 'RCPT-' . Str::upper(Str::random(8));
        $sale->load('items');

        PosReceipt::create([
            'tenant_id' => $tenant->id,
            'sale_id' => $sale->id,
            'shift_id' => $shift->id,
            'receipt_number' => $receiptNumber,
            'type' => 'sale',
            'amount' => $totalAmount,
            'currency' => $sale->currency,
            'content' => json_encode([
                'receipt_number' => $receiptNumber,
                'sale_reference' => $sale->reference,
                'terminal' => $terminal->name,
                'shift' => $shift->uuid,
                'cashier' => $user->name,
                'items' => $sale->items->map(fn (PosSaleItem $item) => [
                    'product_name' => $item->product_name,
                    'quantity' => (float) $item->quantity,
                    'unit_price' => (float) $item->unit_price,
                    'line_total' => (float) $item->line_total,
                ])->all(),
                'subtotal' => $subtotal,
                'tax_amount' => $taxAmount,
                'discount_amount' => $discountAmount,
                'total' => $totalAmount,
                'payment_method' => $paymentMethod,
                'cash_tendered' => $cashTendered,
                'cash_change' => $cashChange,
            ]),
            'printed' => true,
            'printed_at' => now(),
        ]);

        $sale->update(['receipt_number' => $receiptNumber]);

        return $this->salePayload($sale->fresh(['items', 'receipt', 'terminal', 'shift', 'user', 'customer']));
    }

    public function listSales(Tenant $tenant, array $filters = []): array
    {
        $query = PosSale::where('tenant_id', $tenant->id)
            ->with(['items', 'terminal', 'shift', 'user', 'customer'])
            ->orderByDesc('created_at');

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }
        if (! empty($filters['terminal_id'])) {
            $query->whereHas('terminal', fn ($terminal) => $terminal->where('uuid', $filters['terminal_id']));
        }
        if (! empty($filters['from'])) {
            $query->whereDate('created_at', '>=', $filters['from']);
        }
        if (! empty($filters['to'])) {
            $query->whereDate('created_at', '<=', $filters['to']);
        }

        return $query->limit(100)->get()->map(fn (PosSale $sale) => $this->salePayload($sale))->all();
    }

    public function getSale(Tenant $tenant, PosSale $sale): array
    {
        abort_unless($sale->tenant_id === $tenant->id, 404);

        return $this->salePayload($sale->fresh(['items', 'receipt', 'terminal', 'shift', 'user', 'customer']));
    }

    public function reconcileShift(PosShift $shift, User $user): array
    {
        if ($shift->status !== 'closed') {
            throw ValidationException::withMessages(['shift' => ['Shift must be closed before reconciliation.']]);
        }

        $shift->update([
            'status' => 'reconciled',
            'reconciled_at' => now(),
        ]);

        return $this->shiftPayload($shift->fresh('sales'));
    }

    public function listShifts(Tenant $tenant, array $filters = []): array
    {
        $query = PosShift::where('tenant_id', $tenant->id)
            ->with(['terminal', 'openedBy', 'closedBy', 'sales'])
            ->orderByDesc('opened_at');

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        return $query->limit(50)->get()->map(fn (PosShift $shift) => $this->shiftPayload($shift))->all();
    }

    public function createSupplier(Tenant $tenant, array $data): array
    {
        $supplier = PosSupplier::create([
            'tenant_id' => $tenant->id,
            'name' => $data['name'],
            'contact_person' => $data['contact_person'] ?? null,
            'phone' => $data['phone'] ?? null,
            'email' => $data['email'] ?? null,
            'address' => $data['address'] ?? null,
            'tax_pin' => $data['tax_pin'] ?? null,
            'notes' => $data['notes'] ?? null,
            'active' => true,
        ]);

        return $this->supplierPayload($supplier);
    }

    public function listSuppliers(Tenant $tenant): array
    {
        return PosSupplier::where('tenant_id', $tenant->id)
            ->where('active', true)
            ->orderBy('name')
            ->get()
            ->map(fn (PosSupplier $supplier) => $this->supplierPayload($supplier))
            ->all();
    }

    public function createPurchase(Tenant $tenant, array $data, User $user): array
    {
        $supplier = PosSupplier::where('uuid', $data['supplier_id'])->first();
        if (! $supplier || $supplier->tenant_id !== $tenant->id) {
            throw ValidationException::withMessages(['supplier_id' => ['Supplier not found.']]);
        }

        $items = $data['items'] ?? [];
        if (empty($items)) {
            throw ValidationException::withMessages(['items' => ['At least one item is required.']]);
        }

        $lines = [];
        $subtotal = 0.0;

        foreach ($items as $item) {
            $variant = ProductVariant::where('uuid', $item['variant_id'])->first();
            if (! $variant || $variant->tenant_id !== $tenant->id) {
                throw ValidationException::withMessages(['items' => ['One of the selected products is not in this catalogue.']]);
            }

            $quantity = (float) $item['quantity'];
            $unitCost = (float) $item['unit_cost'];
            $lineTotal = round($quantity * $unitCost, 2);
            $subtotal += $lineTotal;

            $lines[] = [
                'variant' => $variant,
                'batch_id' => $this->resolveBatchId($item['batch_id'] ?? null),
                'product_name' => $item['product_name'],
                'sku' => $item['sku'] ?? $variant->sku,
                'quantity' => $quantity,
                'unit' => $item['unit'] ?? 'pcs',
                'unit_cost' => $unitCost,
                'line_total' => $lineTotal,
            ];
        }

        $subtotal = round($subtotal, 2);
        $taxAmount = round((float) ($data['tax_amount'] ?? 0), 2);
        $totalAmount = round((float) ($data['total_amount'] ?? ($subtotal + $taxAmount)), 2);

        $purchase = PosPurchase::create([
            'tenant_id' => $tenant->id,
            'reference' => 'PUR-' . Str::upper(Str::random(8)),
            'branch_id' => $this->resolveBranchId($data['branch_id'] ?? null),
            'supplier_id' => $supplier->id,
            'user_id' => $user->id,
            'status' => $data['status'] ?? 'received',
            'subtotal' => $subtotal,
            'tax_amount' => $taxAmount,
            'total_amount' => $totalAmount,
            'currency' => $data['currency'] ?? 'KES',
            'notes' => $data['notes'] ?? null,
        ]);

        foreach ($lines as $line) {
            PosPurchaseItem::create([
                'purchase_id' => $purchase->id,
                'variant_id' => $line['variant']->id,
                'batch_id' => $line['batch_id'],
                'product_name' => $line['product_name'],
                'sku' => $line['sku'],
                'quantity' => $line['quantity'],
                'unit' => $line['unit'],
                'unit_cost' => $line['unit_cost'],
                'line_total' => $line['line_total'],
            ]);

            if ($purchase->status === 'received') {
                $this->recordMovement(
                    $tenant,
                    $purchase->branch_id,
                    $line['variant'],
                    $line['quantity'],
                    $purchase->reference,
                    'purchase',
                    'brathamatu_pos',
                    $purchase->uuid,
                    $user,
                    $line['unit_cost'],
                    $line['batch_id'],
                    ['purchase_id' => $purchase->id],
                );
            }
        }

        return $this->purchasePayload($purchase->fresh(['supplier', 'user', 'items']));
    }

    public function listPurchases(Tenant $tenant): array
    {
        return PosPurchase::where('tenant_id', $tenant->id)
            ->with(['supplier', 'user', 'items'])
            ->orderByDesc('created_at')
            ->limit(100)
            ->get()
            ->map(fn (PosPurchase $purchase) => $this->purchasePayload($purchase))
            ->all();
    }

    public function cashReconciliation(Tenant $tenant, string $shiftId): array
    {
        $shift = PosShift::where('tenant_id', $tenant->id)->where('uuid', $shiftId)->firstOrFail();
        $sales = $shift->sales()->whereIn('status', ['completed', 'partially_returned', 'returned'])->get();
        $returns = $shift->returns()->where('status', 'completed')->get();

        return [
            'shift_id' => $shift->uuid,
            'opening_float' => (float) $shift->opening_float,
            'expected_cash' => (float) $shift->expected_cash,
            'actual_cash' => $shift->actual_cash !== null ? (float) $shift->actual_cash : null,
            'cash_variance' => $shift->cash_variance !== null ? (float) $shift->cash_variance : null,
            'cash_sales' => (float) $sales->where('payment_method', 'cash')->sum('total_amount'),
            'card_sales' => (float) $sales->where('payment_method', 'card')->sum('total_amount'),
            'mpesa_sales' => (float) $sales->where('payment_method', 'mpesa')->sum('total_amount'),
            'cash_refunds' => (float) $returns->where('refund_method', 'cash')->sum('total_amount'),
            'total_sales' => $shift->totalSales(),
            'transaction_count' => $sales->count(),
            'return_count' => $returns->count(),
            'status' => $shift->status,
            'reconciled_at' => $shift->reconciled_at?->toIso8601String(),
        ];
    }

    public function listCustomers(Tenant $tenant, array $filters = []): array
    {
        $query = PosCustomer::where('tenant_id', $tenant->id)->orderBy('name');

        if (! empty($filters['search'])) {
            $search = '%'.$filters['search'].'%';
            $query->where(fn ($inner) => $inner
                ->where('name', 'like', $search)
                ->orWhere('phone', 'like', $search)
                ->orWhere('email', 'like', $search));
        }

        return $query->limit(200)->get()->map(fn (PosCustomer $customer) => $this->customerPayload($customer))->all();
    }

    public function createCustomer(Tenant $tenant, array $data): array
    {
        $customer = PosCustomer::create([
            'tenant_id' => $tenant->id,
            'name' => $data['name'],
            'phone' => $data['phone'] ?? null,
            'email' => $data['email'] ?? null,
            'loyalty_id' => $data['loyalty_id'] ?? null,
            'notes' => $data['notes'] ?? null,
            'active' => true,
        ]);

        return $this->customerPayload($customer);
    }

    public function updateCustomer(PosCustomer $customer, array $data): array
    {
        $customer->update($data);

        return $this->customerPayload($customer->fresh());
    }

    public function listReturns(Tenant $tenant): array
    {
        return PosReturn::where('tenant_id', $tenant->id)
            ->with(['sale', 'user', 'items'])
            ->orderByDesc('created_at')
            ->limit(100)
            ->get()
            ->map(fn (PosReturn $return) => $this->returnPayload($return))
            ->all();
    }

    public function createReturn(Tenant $tenant, array $data, User $user): array
    {
        $sale = PosSale::where('uuid', $data['sale_id'])->where('tenant_id', $tenant->id)->first();
        if (! $sale) {
            throw ValidationException::withMessages(['sale_id' => ['Sale not found.']]);
        }

        if (! in_array($sale->status, ['completed', 'partially_returned'], true)) {
            throw ValidationException::withMessages(['sale_id' => ['Only completed sales can be returned.']]);
        }

        $sale->load('items');
        $alreadyReturned = PosReturnItem::query()
            ->whereHas('return', fn ($query) => $query->where('sale_id', $sale->id)->where('status', 'completed'))
            ->get()
            ->groupBy('sale_item_id')
            ->map(fn ($items) => (float) $items->sum('quantity'));

        $lines = [];
        $total = 0.0;
        $taxTotal = 0.0;

        foreach ($data['items'] as $input) {
            $saleItem = $sale->items->firstWhere('uuid', $input['sale_item_id']);
            if (! $saleItem) {
                throw ValidationException::withMessages(['items' => ['Return line does not belong to this sale.']]);
            }

            $quantity = (float) $input['quantity'];
            $remaining = (float) $saleItem->quantity - (float) ($alreadyReturned[$saleItem->id] ?? 0);
            if ($quantity <= 0 || $quantity > $remaining) {
                throw ValidationException::withMessages(['items' => ["Return quantity for {$saleItem->product_name} exceeds the unreturned quantity."]]);
            }

            $lineTotal = round($quantity * (float) $saleItem->unit_price, 2);
            $tax = $saleItem->quantity > 0 ? round((float) $saleItem->tax_amount * ($quantity / (float) $saleItem->quantity), 2) : 0;
            $total += $lineTotal;
            $taxTotal += $tax;

            $lines[] = [
                'sale_item' => $saleItem,
                'quantity' => $quantity,
                'line_total' => $lineTotal,
                'tax_amount' => $tax,
            ];
        }

        return DB::transaction(function () use ($tenant, $sale, $data, $user, $lines, $total, $taxTotal): array {
            $reference = 'RET-'.Str::upper(Str::random(8));
            $return = PosReturn::create([
                'tenant_id' => $tenant->id,
                'reference' => $reference,
                'branch_id' => $sale->branch_id,
                'sale_id' => $sale->id,
                'shift_id' => $sale->shift_id,
                'user_id' => $user->id,
                'status' => 'completed',
                'subtotal' => round($total - $taxTotal, 2),
                'tax_amount' => $taxTotal,
                'total_amount' => round($total, 2),
                'currency' => $sale->currency,
                'refund_method' => $data['refund_method'] ?? $sale->payment_method ?? 'cash',
                'reason' => $data['reason'] ?? null,
                'notes' => $data['notes'] ?? null,
                'returned_at' => now(),
            ]);

            foreach ($lines as $line) {
                /** @var PosSaleItem $saleItem */
                $saleItem = $line['sale_item'];
                PosReturnItem::create([
                    'return_id' => $return->id,
                    'sale_item_id' => $saleItem->id,
                    'variant_id' => $saleItem->variant_id,
                    'batch_id' => $saleItem->batch_id,
                    'product_name' => $saleItem->product_name,
                    'sku' => $saleItem->sku,
                    'quantity' => $line['quantity'],
                    'unit' => $saleItem->unit,
                    'unit_price' => (float) $saleItem->unit_price,
                    'line_total' => $line['line_total'],
                ]);

                $this->recordMovement(
                    $tenant,
                    $sale->branch_id,
                    $saleItem->variant,
                    $line['quantity'],
                    $reference,
                    'return',
                    'brathamatu_pos',
                    $return->uuid,
                    $user,
                    0,
                    $saleItem->batch_id,
                    ['return_id' => $return->id, 'sale_id' => $sale->id],
                );
            }

            $fullyReturned = PosReturnItem::query()
                ->whereHas('return', fn ($query) => $query->where('sale_id', $sale->id)->where('status', 'completed'))
                ->get()
                ->groupBy('sale_item_id')
                ->every(fn ($items, $saleItemId) => (float) $items->sum('quantity') >= (float) $sale->items->firstWhere('id', $saleItemId)?->quantity);

            $sale->update(['status' => $fullyReturned ? 'returned' : 'partially_returned']);

            PosReceipt::create([
                'tenant_id' => $tenant->id,
                'sale_id' => $sale->id,
                'shift_id' => $sale->shift_id,
                'receipt_number' => 'RCPT-'.Str::upper(Str::random(8)),
                'type' => 'refund',
                'amount' => $return->total_amount,
                'currency' => $return->currency,
                'content' => json_encode([
                    'return_reference' => $reference,
                    'sale_reference' => $sale->reference,
                    'refund_method' => $return->refund_method,
                    'total' => (float) $return->total_amount,
                ]),
                'printed' => true,
                'printed_at' => now(),
            ]);

            return $this->returnPayload($return->fresh(['sale', 'user', 'items']));
        });
    }

    public function listTransfers(Tenant $tenant): array
    {
        return PosTransfer::where('tenant_id', $tenant->id)
            ->with(['fromBranch', 'toBranch', 'user', 'items'])
            ->orderByDesc('created_at')
            ->limit(100)
            ->get()
            ->map(fn (PosTransfer $transfer) => $this->transferPayload($transfer))
            ->all();
    }

    public function createTransfer(Tenant $tenant, array $data, User $user): array
    {
        $fromBranchId = $this->resolveBranchId($data['from_branch_id']);
        $toBranchId = $this->resolveBranchId($data['to_branch_id']);

        if ($fromBranchId === null || $toBranchId === null) {
            throw ValidationException::withMessages(['branch' => ['Both source and destination branches are required.']]);
        }
        if ($fromBranchId === $toBranchId) {
            throw ValidationException::withMessages(['to_branch_id' => ['Destination branch must differ from the source branch.']]);
        }

        $lines = [];
        foreach ($data['items'] as $item) {
            $variant = ProductVariant::where('uuid', $item['variant_id'])->where('tenant_id', $tenant->id)->first();
            if (! $variant) {
                throw ValidationException::withMessages(['items' => ['One of the selected products is not in this catalogue.']]);
            }
            $quantity = (float) $item['quantity'];
            if ($quantity <= 0) {
                throw ValidationException::withMessages(['items' => ['Transfer quantities must be greater than zero.']]);
            }
            $lines[] = ['variant' => $variant, 'quantity' => $quantity, 'unit' => $item['unit'] ?? 'pcs'];
        }

        $status = $data['status'] ?? 'received';

        return DB::transaction(function () use ($tenant, $data, $user, $fromBranchId, $toBranchId, $lines, $status): array {
            $transfer = PosTransfer::create([
                'tenant_id' => $tenant->id,
                'reference' => 'TRF-'.Str::upper(Str::random(8)),
                'from_branch_id' => $fromBranchId,
                'to_branch_id' => $toBranchId,
                'from_location_id' => $this->resolveLocationId($tenant, $data['from_location_id'] ?? null),
                'to_location_id' => $this->resolveLocationId($tenant, $data['to_location_id'] ?? null),
                'user_id' => $user->id,
                'status' => $status,
                'notes' => $data['notes'] ?? null,
                'shipped_at' => now(),
                'received_at' => $status === 'received' ? now() : null,
            ]);

            foreach ($lines as $line) {
                PosTransferItem::create([
                    'transfer_id' => $transfer->id,
                    'variant_id' => $line['variant']->id,
                    'product_name' => $line['variant']->product?->name ?? $line['variant']->sku,
                    'sku' => $line['variant']->sku,
                    'quantity' => $line['quantity'],
                    'unit' => $line['unit'],
                ]);
            }

            if ($status === 'received') {
                $this->applyTransferMovements($tenant, $transfer, $user);
            }

            return $this->transferPayload($transfer->fresh(['fromBranch', 'toBranch', 'user', 'items']));
        });
    }

    public function receiveTransfer(Tenant $tenant, PosTransfer $transfer, User $user): array
    {
        if ($transfer->status !== 'in_transit') {
            throw ValidationException::withMessages(['transfer' => ['Only in-transit transfers can be received.']]);
        }

        return DB::transaction(function () use ($tenant, $transfer, $user): array {
            $transfer->update(['status' => 'received', 'received_at' => now()]);
            $this->applyTransferMovements($tenant, $transfer, $user);

            return $this->transferPayload($transfer->fresh(['fromBranch', 'toBranch', 'user', 'items']));
        });
    }

    private function applyTransferMovements(Tenant $tenant, PosTransfer $transfer, User $user): void
    {
        foreach ($transfer->items as $item) {
            $this->recordMovement(
                $tenant,
                $transfer->from_branch_id,
                $item->variant,
                -(float) $item->quantity,
                $transfer->reference,
                'transfer_out',
                'brathamatu_pos',
                $transfer->uuid,
                $user,
                0,
                null,
                ['transfer_id' => $transfer->id, 'direction' => 'out'],
            );

            $this->recordMovement(
                $tenant,
                $transfer->to_branch_id,
                $item->variant,
                (float) $item->quantity,
                $transfer->reference,
                'transfer_in',
                'brathamatu_pos',
                $transfer->uuid,
                $user,
                0,
                null,
                ['transfer_id' => $transfer->id, 'direction' => 'in'],
            );
        }
    }


    private function recordMovement(
        Tenant $tenant,
        ?int $branchId,
        ProductVariant $variant,
        float $quantity,
        string $reference,
        string $type,
        string $source,
        ?string $externalId,
        User $user,
        float $unitCost = 0,
        ?int $batchId = null,
        array $metadata = [],
    ): void {
        $location = StockLocation::query()
            ->where('tenant_id', $tenant->id)
            ->when($branchId, fn ($query, $id) => $query->where(fn ($inner) => $inner
                ->where('branch_id', $id)
                ->orWhereNull('branch_id')))
            ->orderByRaw('branch_id IS NULL')
            ->orderBy('id')
            ->first();

        // Branch-specific stock must stay attributable even when the branch has no location yet.
        if ($location === null && $branchId !== null) {
            $branchName = Branch::whereKey($branchId)->value('name') ?? 'Branch';
            $location = StockLocation::firstOrCreate(
                ['tenant_id' => $tenant->id, 'branch_id' => $branchId, 'name' => $branchName.' stock'],
                ['type' => 'branch', 'status' => 'active'],
            );
        }

        $batchKey = $batchId !== null ? (string) $batchId : '';

        $balance = $location === null ? null : InventoryBalance::firstOrCreate(
            [
                'tenant_id' => $tenant->id,
                'stock_location_id' => $location->id,
                'variant_id' => $variant->id,
                'batch_key' => $batchKey,
            ],
            [
                'branch_id' => $location->branch_id,
                'batch_id' => $batchId,
                'quantity' => 0,
                'reserved_quantity' => 0,
            ],
        );

        $movement = InventoryMovement::create([
            'tenant_id' => $tenant->id,
            'branch_id' => $branchId,
            'stock_location_id' => $location?->id,
            'type' => $type,
            'source' => $source,
            'external_id' => $externalId,
            'reference' => $reference,
            'occurred_at' => now(),
            'recorded_by' => $user->id,
            'status' => 'posted',
            'notes' => "POS {$type} {$reference}",
            'metadata' => $metadata,
            'line_count' => 1,
        ]);

        if ($balance) {
            $balance->quantity = (float) $balance->quantity + $quantity;
            $balance->last_movement_at = now();
            $balance->save();
        }

        InventoryMovementLine::create([
            'tenant_id' => $tenant->id,
            'movement_id' => $movement->id,
            'variant_id' => $variant->id,
            'batch_id' => $batchId,
            'batch_key' => $batchKey,
            'quantity' => $quantity,
            'unit_cost' => $unitCost,
            'metadata' => $metadata,
        ]);
    }

    private function resolveBranchId(?string $uuid): ?int
    {
        if ($uuid === null || $uuid === '') {
            return null;
        }

        $id = Branch::where('uuid', $uuid)->value('id');
        if ($id === null) {
            throw ValidationException::withMessages(['branch_id' => ['Branch not found.']]);
        }

        return (int) $id;
    }

    private function configuredSellingPrice(ProductVariant $variant): ?float
    {
        foreach (['retail_price', 'selling_price', 'unit_price', 'price'] as $key) {
            $raw = $variant->attributes[$key] ?? null;
            if (is_numeric($raw) && is_finite((float) $raw) && (float) $raw > 0) {
                return round((float) $raw, 2);
            }
        }

        return null;
    }

    private function saleBarcode(ProductVariant $variant, ?string $requestedBarcode): ?string
    {
        if ($requestedBarcode !== null && $requestedBarcode !== '') {
            $barcode = $variant->barcodes->firstWhere('barcode', $requestedBarcode);
            if (! $barcode) {
                throw ValidationException::withMessages([
                    'items' => ['The selected barcode does not belong to this catalogue variant.'],
                ]);
            }

            return $barcode->barcode;
        }

        return $variant->barcodes->firstWhere('is_primary', true)?->barcode
            ?? $variant->barcodes->first()?->barcode;
    }

    private function resolveBatchId(?string $uuid): ?int
    {
        if ($uuid === null || $uuid === '') {
            return null;
        }

        return InventoryBatch::where('uuid', $uuid)->value('id');
    }

    private function resolveCustomerId(?string $uuid): ?int
    {
        if ($uuid === null || $uuid === '') {
            return null;
        }

        return PosCustomer::where('uuid', $uuid)->value('id');
    }

    private function resolveLocationId(Tenant $tenant, ?string $uuid): ?int
    {
        if ($uuid === null || $uuid === '') {
            return null;
        }

        $id = StockLocation::where('tenant_id', $tenant->id)->where('uuid', $uuid)->value('id');
        if ($id === null) {
            throw ValidationException::withMessages(['stock_location_id' => ['Stock location not found.']]);
        }

        return (int) $id;
    }

    private function customerPayload(PosCustomer $customer): array
    {
        return [
            'id' => $customer->uuid,
            'name' => $customer->name,
            'phone' => $customer->phone,
            'email' => $customer->email,
            'loyalty_id' => $customer->loyalty_id,
            'active' => (bool) $customer->active,
            'notes' => $customer->notes,
            'created_at' => $customer->created_at?->toIso8601String(),
        ];
    }

    private function returnPayload(PosReturn $return): array
    {
        return [
            'id' => $return->uuid,
            'reference' => $return->reference,
            'sale_id' => $return->sale?->uuid,
            'sale_reference' => $return->sale?->reference,
            'user' => $return->user?->name,
            'status' => $return->status,
            'refund_method' => $return->refund_method,
            'subtotal' => (float) $return->subtotal,
            'tax_amount' => (float) $return->tax_amount,
            'total_amount' => (float) $return->total_amount,
            'currency' => $return->currency,
            'reason' => $return->reason,
            'returned_at' => $return->returned_at?->toIso8601String(),
            'items' => $return->items->map(fn (PosReturnItem $item) => [
                'product_name' => $item->product_name,
                'sku' => $item->sku,
                'quantity' => (float) $item->quantity,
                'unit' => $item->unit,
                'unit_price' => (float) $item->unit_price,
                'line_total' => (float) $item->line_total,
            ])->all(),
        ];
    }

    private function transferPayload(PosTransfer $transfer): array
    {
        return [
            'id' => $transfer->uuid,
            'reference' => $transfer->reference,
            'from_branch' => $transfer->fromBranch?->name,
            'to_branch' => $transfer->toBranch?->name,
            'user' => $transfer->user?->name,
            'status' => $transfer->status,
            'notes' => $transfer->notes,
            'shipped_at' => $transfer->shipped_at?->toIso8601String(),
            'received_at' => $transfer->received_at?->toIso8601String(),
            'created_at' => $transfer->created_at?->toIso8601String(),
            'items' => $transfer->items->map(fn (PosTransferItem $item) => [
                'product_name' => $item->product_name,
                'sku' => $item->sku,
                'quantity' => (float) $item->quantity,
                'unit' => $item->unit,
            ])->all(),
        ];
    }

    private function supplierPayload(PosSupplier $supplier): array
    {
        return [
            'id' => $supplier->uuid,
            'name' => $supplier->name,
            'contact_person' => $supplier->contact_person,
            'phone' => $supplier->phone,
            'email' => $supplier->email,
            'address' => $supplier->address,
            'tax_pin' => $supplier->tax_pin,
            'active' => (bool) $supplier->active,
            'created_at' => $supplier->created_at?->toIso8601String(),
        ];
    }

    private function terminalPayload(PosTerminal $terminal): array
    {
        $activeShift = $terminal->currentShift();

        return [
            'id' => $terminal->uuid,
            'name' => $terminal->name,
            'branch_id' => $terminal->branch?->uuid,
            'device_id' => $terminal->device_id,
            'status' => $terminal->status,
            'current_shift' => $activeShift ? [
                'id' => $activeShift->uuid,
                'status' => $activeShift->status,
                'opened_at' => $activeShift->opened_at?->toIso8601String(),
            ] : null,
            'created_at' => $terminal->created_at?->toIso8601String(),
        ];
    }

    private function shiftPayload(PosShift $shift): array
    {
        return [
            'id' => $shift->uuid,
            'terminal_id' => $shift->terminal?->uuid,
            'terminal_name' => $shift->terminal?->name,
            'opened_by' => $shift->openedBy?->name,
            'closed_by' => $shift->closedBy?->name,
            'opening_float' => (float) $shift->opening_float,
            'expected_cash' => (float) $shift->expected_cash,
            'actual_cash' => $shift->actual_cash !== null ? (float) $shift->actual_cash : null,
            'cash_variance' => $shift->cash_variance !== null ? (float) $shift->cash_variance : null,
            'status' => $shift->status,
            'opened_at' => $shift->opened_at?->toIso8601String(),
            'closed_at' => $shift->closed_at?->toIso8601String(),
            'reconciled_at' => $shift->reconciled_at?->toIso8601String(),
            'total_sales' => $shift->totalSales(),
            'total_cash_sales' => $shift->totalCashSales(),
            'created_at' => $shift->created_at?->toIso8601String(),
        ];
    }

    private function salePayload(PosSale $sale): array
    {
        return [
            'id' => $sale->uuid,
            'reference' => $sale->reference,
            'receipt_number' => $sale->receipt_number,
            'terminal_id' => $sale->terminal?->uuid,
            'terminal_name' => $sale->terminal?->name,
            'shift_id' => $sale->shift?->uuid,
            'user' => $sale->user?->name,
            'customer' => $sale->customer?->name,
            'status' => $sale->status,
            'subtotal' => (float) $sale->subtotal,
            'tax_amount' => (float) $sale->tax_amount,
            'discount_amount' => (float) $sale->discount_amount,
            'total_amount' => (float) $sale->total_amount,
            'currency' => $sale->currency,
            'payment_method' => $sale->payment_method,
            'cash_tendered' => $sale->cash_tendered !== null ? (float) $sale->cash_tendered : null,
            'cash_change' => $sale->cash_change !== null ? (float) $sale->cash_change : null,
            'notes' => $sale->notes,
            'created_at' => $sale->created_at?->toIso8601String(),
            'items' => $sale->items->map(fn (PosSaleItem $item) => [
                'id' => $item->uuid,
                'product_name' => $item->product_name,
                'sku' => $item->sku,
                'barcode' => $item->barcode,
                'quantity' => (float) $item->quantity,
                'unit' => $item->unit,
                'unit_price' => (float) $item->unit_price,
                'line_total' => (float) $item->line_total,
                'tax_amount' => (float) $item->tax_amount,
                'discount_amount' => (float) $item->discount_amount,
            ])->all(),
            'receipt' => $sale->receipt ? [
                'receipt_number' => $sale->receipt->receipt_number,
                'type' => $sale->receipt->type,
                'amount' => (float) $sale->receipt->amount,
                'printed' => (bool) $sale->receipt->printed,
            ] : null,
        ];
    }

    private function purchasePayload(PosPurchase $purchase): array
    {
        return [
            'id' => $purchase->uuid,
            'reference' => $purchase->reference,
            'supplier' => $purchase->supplier?->name,
            'user' => $purchase->user?->name,
            'status' => $purchase->status,
            'subtotal' => (float) $purchase->subtotal,
            'tax_amount' => (float) $purchase->tax_amount,
            'total_amount' => (float) $purchase->total_amount,
            'currency' => $purchase->currency,
            'created_at' => $purchase->created_at?->toIso8601String(),
            'items' => $purchase->items->map(fn (PosPurchaseItem $item) => [
                'product_name' => $item->product_name,
                'sku' => $item->sku,
                'quantity' => (float) $item->quantity,
                'unit_cost' => (float) $item->unit_cost,
                'line_total' => (float) $item->line_total,
            ])->all(),
        ];
    }
}
