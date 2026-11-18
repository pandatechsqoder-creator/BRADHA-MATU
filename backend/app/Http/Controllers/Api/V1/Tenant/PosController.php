<?php

namespace App\Http\Controllers\Api\V1\Tenant;

use App\Http\Controllers\Api\V1\Tenant\Concerns\ChecksTenantAccess;
use App\Http\Controllers\Controller;
use App\Models\PosCustomer;
use App\Models\PosSale;
use App\Models\PosShift;
use App\Models\PosTerminal;
use App\Models\PosTransfer;
use App\Models\Tenant;
use App\Services\Pos\PosService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class PosController extends Controller
{
    use ChecksTenantAccess;

    public function terminals(Request $request, Tenant $tenant, PosService $pos): JsonResponse
    {
        $this->assertAnyCan($request, $tenant, ['pos.manage', 'pos.sales.create', 'pos.shift.open', 'pos.shift.close']);
        return ApiResponse::success($pos->listTerminals($tenant));
    }

    public function storeTerminal(Request $request, Tenant $tenant, PosService $pos): JsonResponse
    {
        $this->assertAnyCan($request, $tenant, ['pos.manage']);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:200'],
            'branch_id' => ['nullable', 'string', 'max:64'],
            'device_id' => ['nullable', 'string', 'max:100'],
            'status' => ['sometimes', 'string', 'in:active,inactive,maintenance'],
        ]);
        return ApiResponse::success($pos->createTerminal($tenant, $data, $request->user()), 201);
    }

    public function updateTerminal(Request $request, Tenant $tenant, PosTerminal $terminal, PosService $pos): JsonResponse
    {
        $this->assertAnyCan($request, $tenant, ['pos.manage']);
        abort_unless($terminal->tenant_id === $tenant->id, 404);
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:200'],
            'branch_id' => ['nullable', 'string', 'max:64'],
            'device_id' => ['nullable', 'string', 'max:100'],
            'status' => ['sometimes', 'string', 'in:active,inactive,maintenance'],
        ]);
        return ApiResponse::success($pos->updateTerminal($terminal, $data));
    }

    public function shifts(Request $request, Tenant $tenant, PosService $pos): JsonResponse
    {
        $this->assertAnyCan($request, $tenant, ['pos.shift.open', 'pos.shift.close', 'pos.manage']);
        return ApiResponse::success($pos->listShifts($tenant, $request->only(['status'])));
    }

    public function products(Request $request, Tenant $tenant, PosService $pos): JsonResponse
    {
        $this->assertAnyCan($request, $tenant, ['pos.sales.create']);
        $filters = $request->validate([
            'terminal_id' => ['required', 'string', 'max:64'],
            'search' => ['nullable', 'string', 'max:120'],
            'brand' => ['nullable', 'string', 'max:120'],
            'category' => ['nullable', 'string', 'max:120'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);
        $result = $pos->searchProducts($tenant, $filters);

        return ApiResponse::success($result);
    }

    public function openShift(Request $request, Tenant $tenant, PosTerminal $terminal, PosService $pos): JsonResponse
    {
        $this->assertAnyCan($request, $tenant, ['pos.shift.open']);
        abort_unless($terminal->tenant_id === $tenant->id, 404);
        $data = $request->validate([
            'opening_float' => ['nullable', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);
        return ApiResponse::success($pos->openShift($terminal, $data, $request->user()), 201);
    }

    public function closeShift(Request $request, Tenant $tenant, PosShift $shift, PosService $pos): JsonResponse
    {
        $this->assertAnyCan($request, $tenant, ['pos.shift.close']);
        abort_unless($shift->tenant_id === $tenant->id, 404);
        $data = $request->validate([
            'actual_cash' => ['required', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);
        return ApiResponse::success($pos->closeShift($shift, $data, $request->user()));
    }

    public function reconcileShift(Request $request, Tenant $tenant, PosShift $shift, PosService $pos): JsonResponse
    {
        $this->assertAnyCan($request, $tenant, ['pos.shift.close']);
        abort_unless($shift->tenant_id === $tenant->id, 404);
        return ApiResponse::success($pos->reconcileShift($shift, $request->user()));
    }

    public function shiftReconciliation(Request $request, Tenant $tenant, string $shiftId, PosService $pos): JsonResponse
    {
        $this->assertAnyCan($request, $tenant, ['pos.shift.close', 'pos.manage']);
        return ApiResponse::success($pos->cashReconciliation($tenant, $shiftId));
    }

    public function sales(Request $request, Tenant $tenant, PosService $pos): JsonResponse
    {
        $this->assertAnyCan($request, $tenant, ['pos.sales.create']);
        return ApiResponse::success($pos->listSales($tenant, $request->only(['status', 'terminal_id', 'from', 'to'])));
    }

    public function storeSale(Request $request, Tenant $tenant, PosService $pos): JsonResponse
    {
        $this->assertAnyCan($request, $tenant, ['pos.sales.create']);
        $data = $request->validate([
            'terminal_id' => ['required', 'string', 'max:64'],
            'customer_id' => ['nullable', 'string', 'max:64'],
            'payment_method' => ['sometimes', 'string', 'in:cash,mpesa,card,mixed'],
            'cash_tendered' => ['nullable', 'numeric', 'min:0'],
            'currency' => ['sometimes', 'string', 'size:3'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.variant_id' => ['required', 'string', 'max:64'],
            'items.*.batch_id' => ['nullable', 'string', 'max:64'],
            'items.*.barcode' => ['nullable', 'string', 'max:100'],
            'items.*.quantity' => ['required', 'numeric', 'gt:0'],
            'items.*.product_name' => ['prohibited'],
            'items.*.sku' => ['prohibited'],
            'items.*.unit' => ['prohibited'],
            'items.*.unit_price' => ['prohibited'],
            'items.*.tax_amount' => ['nullable', 'numeric', 'min:0'],
            'items.*.discount_amount' => ['nullable', 'numeric', 'min:0'],
        ]);
        return ApiResponse::success($pos->createSale($tenant, $data, $request->user()), 201);
    }

    public function showSale(Request $request, Tenant $tenant, PosSale $sale, PosService $pos): JsonResponse
    {
        $this->assertAnyCan($request, $tenant, ['pos.sales.create']);
        return ApiResponse::success($pos->getSale($tenant, $sale));
    }

    public function suppliers(Request $request, Tenant $tenant, PosService $pos): JsonResponse
    {
        $this->assertAnyCan($request, $tenant, ['pos.manage']);
        return ApiResponse::success($pos->listSuppliers($tenant));
    }

    public function storeSupplier(Request $request, Tenant $tenant, PosService $pos): JsonResponse
    {
        $this->assertAnyCan($request, $tenant, ['pos.manage']);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:200'],
            'contact_person' => ['nullable', 'string', 'max:200'],
            'phone' => ['nullable', 'string', 'max:50'],
            'email' => ['nullable', 'email'],
            'address' => ['nullable', 'string', 'max:500'],
            'tax_pin' => ['nullable', 'string', 'max:50'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);
        return ApiResponse::success($pos->createSupplier($tenant, $data), 201);
    }

    public function purchases(Request $request, Tenant $tenant, PosService $pos): JsonResponse
    {
        $this->assertAnyCan($request, $tenant, ['pos.manage']);
        return ApiResponse::success($pos->listPurchases($tenant));
    }

    public function storePurchase(Request $request, Tenant $tenant, PosService $pos): JsonResponse
    {
        $this->assertAnyCan($request, $tenant, ['pos.manage']);
        $data = $request->validate([
            'supplier_id' => ['required', 'string', 'max:64'],
            'branch_id' => ['nullable', 'string', 'max:64'],
            'status' => ['sometimes', 'string', 'in:ordered,received,cancelled'],
            'subtotal' => ['nullable', 'numeric', 'min:0'],
            'tax_amount' => ['nullable', 'numeric', 'min:0'],
            'total_amount' => ['nullable', 'numeric', 'min:0'],
            'currency' => ['sometimes', 'string', 'size:3'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.variant_id' => ['required', 'string', 'max:64'],
            'items.*.batch_id' => ['nullable', 'string', 'max:64'],
            'items.*.product_name' => ['required', 'string', 'max:200'],
            'items.*.sku' => ['nullable', 'string', 'max:100'],
            'items.*.quantity' => ['required', 'numeric', 'gt:0'],
            'items.*.unit' => ['sometimes', 'string', 'max:20'],
            'items.*.unit_cost' => ['required', 'numeric', 'min:0'],
        ]);
        return ApiResponse::success($pos->createPurchase($tenant, $data, $request->user()), 201);
    }

    public function customers(Request $request, Tenant $tenant, PosService $pos): JsonResponse
    {
        $this->assertAnyCan($request, $tenant, ['pos.sales.create', 'pos.manage']);
        return ApiResponse::success($pos->listCustomers($tenant, $request->only(['search'])));
    }

    public function storeCustomer(Request $request, Tenant $tenant, PosService $pos): JsonResponse
    {
        $this->assertAnyCan($request, $tenant, ['pos.sales.create', 'pos.manage']);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:200'],
            'phone' => ['nullable', 'string', 'max:50'],
            'email' => ['nullable', 'email', 'max:200'],
            'loyalty_id' => ['nullable', 'string', 'max:100'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);
        return ApiResponse::success($pos->createCustomer($tenant, $data), 201);
    }

    public function updateCustomer(Request $request, Tenant $tenant, PosCustomer $customer, PosService $pos): JsonResponse
    {
        $this->assertAnyCan($request, $tenant, ['pos.manage']);
        abort_unless($customer->tenant_id === $tenant->id, 404);
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:200'],
            'phone' => ['nullable', 'string', 'max:50'],
            'email' => ['nullable', 'email', 'max:200'],
            'loyalty_id' => ['nullable', 'string', 'max:100'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'active' => ['sometimes', 'boolean'],
        ]);
        return ApiResponse::success($pos->updateCustomer($customer, $data));
    }

    public function returns(Request $request, Tenant $tenant, PosService $pos): JsonResponse
    {
        $this->assertAnyCan($request, $tenant, ['pos.refund.create', 'pos.manage']);
        return ApiResponse::success($pos->listReturns($tenant));
    }

    public function storeReturn(Request $request, Tenant $tenant, PosService $pos): JsonResponse
    {
        $this->assertAnyCan($request, $tenant, ['pos.refund.create', 'pos.manage']);
        $data = $request->validate([
            'sale_id' => ['required', 'string', 'max:64'],
            'refund_method' => ['sometimes', 'string', 'in:cash,mpesa,card,none'],
            'reason' => ['nullable', 'string', 'max:500'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.sale_item_id' => ['required', 'string', 'max:64'],
            'items.*.quantity' => ['required', 'numeric', 'gt:0'],
        ]);
        return ApiResponse::success($pos->createReturn($tenant, $data, $request->user()), 201);
    }

    public function transfers(Request $request, Tenant $tenant, PosService $pos): JsonResponse
    {
        $this->assertAnyCan($request, $tenant, ['pos.manage', 'inventory.movement.create']);
        return ApiResponse::success($pos->listTransfers($tenant));
    }

    public function storeTransfer(Request $request, Tenant $tenant, PosService $pos): JsonResponse
    {
        $this->assertAnyCan($request, $tenant, ['pos.manage', 'inventory.movement.create']);
        $data = $request->validate([
            'from_branch_id' => ['required', 'string', 'max:64'],
            'to_branch_id' => ['required', 'string', 'max:64'],
            'from_location_id' => ['nullable', 'string', 'max:64'],
            'to_location_id' => ['nullable', 'string', 'max:64'],
            'status' => ['sometimes', 'string', 'in:in_transit,received'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.variant_id' => ['required', 'string', 'max:64'],
            'items.*.quantity' => ['required', 'numeric', 'gt:0'],
            'items.*.unit' => ['sometimes', 'string', 'max:20'],
        ]);
        return ApiResponse::success($pos->createTransfer($tenant, $data, $request->user()), 201);
    }

    public function receiveTransfer(Request $request, Tenant $tenant, PosTransfer $transfer, PosService $pos): JsonResponse
    {
        $this->assertAnyCan($request, $tenant, ['pos.manage', 'inventory.movement.create']);
        abort_unless($transfer->tenant_id === $tenant->id, 404);
        return ApiResponse::success($pos->receiveTransfer($tenant, $transfer, $request->user()));
    }
}