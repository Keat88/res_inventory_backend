<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\PurchaseOrder;
use App\Services\PurchaseOrderService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PurchaseOrderController extends Controller
{
    public function __construct(protected PurchaseOrderService $orders) {}

    public function index(Branch $branch, Request $request): JsonResponse
    {
        $query = PurchaseOrder::query()
            ->where('branch_id', $branch->id)
            ->with('supplier:id,name', 'createdBy:id,name')
            ->withCount('items')
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->when($request->filled('supplier_id'), fn ($q) => $q->where('supplier_id', $request->integer('supplier_id')))
            ->when($request->filled('search'), fn ($q) => $q->where('po_number', 'like', '%'.$request->string('search').'%'));

        return ApiResponse::paginated($query->latest()->paginate($request->integer('per_page', 20)));
    }

    public function store(Request $request, Branch $branch): JsonResponse
    {
        $data = $request->validate([
            'supplier_id' => ['required', 'integer', 'exists:suppliers,id'],
            'currency' => ['nullable', 'string', 'size:3'],
            'exchange_rate' => ['nullable', 'numeric', 'min:0'],
            'tax_total' => ['nullable', 'numeric', 'min:0'],
            'discount_total' => ['nullable', 'numeric', 'min:0'],
            'expected_at' => ['nullable', 'date'],
            'notes' => ['nullable', 'string'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.ingredient_id' => ['required', 'integer', 'exists:ingredients,id'],
            'items.*.quantity_ordered' => ['required', 'numeric', 'min:0.001'],
            'items.*.unit_cost' => ['required', 'numeric', 'min:0'],
        ]);

        $order = $this->orders->create($request->user(), $branch->id, $data);

        return ApiResponse::created($order, 'Purchase order created successfully');
    }

    public function show(PurchaseOrder $purchaseOrder): JsonResponse
    {
        return ApiResponse::success($purchaseOrder->load('branch', 'supplier', 'items.ingredient', 'receipts.items', 'createdBy:id,name', 'approvedBy:id,name'));
    }

    public function update(Request $request, PurchaseOrder $purchaseOrder): JsonResponse
    {
        if ($purchaseOrder->status->value !== 'DRAFT') {
            return ApiResponse::error('Only draft purchase orders can be updated.', null, 409, 'INVALID_PO_STATE');
        }

        $data = $request->validate([
            'supplier_id' => ['sometimes', 'integer', 'exists:suppliers,id'],
            'currency' => ['nullable', 'string', 'size:3'],
            'exchange_rate' => ['nullable', 'numeric', 'min:0'],
            'tax_total' => ['nullable', 'numeric', 'min:0'],
            'discount_total' => ['nullable', 'numeric', 'min:0'],
            'expected_at' => ['nullable', 'date'],
            'notes' => ['nullable', 'string'],
        ]);

        $purchaseOrder->update($data);
        $purchaseOrder->grand_total = round(
            (float) $purchaseOrder->subtotal + (float) $purchaseOrder->tax_total - (float) $purchaseOrder->discount_total,
            2
        );
        $purchaseOrder->save();

        return ApiResponse::success($purchaseOrder->refresh()->load('supplier', 'items.ingredient'), 'Purchase order updated successfully');
    }

    public function destroy(PurchaseOrder $purchaseOrder): JsonResponse
    {
        $this->orders->cancel($purchaseOrder);

        return ApiResponse::noContent('Purchase order cancelled successfully');
    }

    public function submit(PurchaseOrder $purchaseOrder): JsonResponse
    {
        return ApiResponse::success($this->orders->submit($purchaseOrder), 'Purchase order submitted');
    }

    public function approve(Request $request, PurchaseOrder $purchaseOrder): JsonResponse
    {
        return ApiResponse::success($this->orders->approve($purchaseOrder, $request->user()), 'Purchase order approved');
    }

    public function receive(Request $request, PurchaseOrder $purchaseOrder): JsonResponse
    {
        $data = $request->validate([
            'notes' => ['nullable', 'string'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.purchase_order_item_id' => ['required', 'integer', 'exists:purchase_order_items,id'],
            'items.*.quantity_received' => ['required', 'numeric', 'min:0.001'],
        ]);

        return ApiResponse::success(
            $this->orders->receive($purchaseOrder, $request->user(), $data['items'], $data['notes'] ?? null),
            'Goods received successfully'
        );
    }

    public function cancel(PurchaseOrder $purchaseOrder): JsonResponse
    {
        return ApiResponse::success($this->orders->cancel($purchaseOrder), 'Purchase order cancelled');
    }
}
