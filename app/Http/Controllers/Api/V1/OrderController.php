<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\OrderType;
use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Order;
use App\Models\OrderItem;
use App\Services\OrderService;
use App\Services\ReceiptService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class OrderController extends Controller
{
    public function __construct(
        protected OrderService $orders,
        protected ReceiptService $receipts
    ) {}

    public function index(Branch $branch, Request $request): JsonResponse
    {
        $query = Order::query()
            ->where('branch_id', $branch->id)
            ->with(['cashier:id,name', 'diningTable:id,table_number,name', 'customer:id,name,phone'])
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->when($request->filled('payment_status'), fn ($q) => $q->where('payment_status', $request->string('payment_status')))
            ->when($request->filled('type'), fn ($q) => $q->where('type', $request->string('type')))
            ->when($request->filled('cashier_id'), fn ($q) => $q->where('cashier_id', $request->integer('cashier_id')))
            ->when($request->filled('dining_table_id'), fn ($q) => $q->where('dining_table_id', $request->integer('dining_table_id')))
            ->when($request->filled('from'), fn ($q) => $q->whereDate('created_at', '>=', $request->date('from')))
            ->when($request->filled('to'), fn ($q) => $q->whereDate('created_at', '<=', $request->date('to')))
            ->when($request->filled('search'), fn ($q) => $q->where('order_number', 'like', '%'.$request->string('search').'%'));

        return ApiResponse::paginated($query->latest()->paginate($request->integer('per_page', 20)));
    }

    public function store(Request $request, Branch $branch): JsonResponse
    {
        $data = $request->validate([
            'type' => ['required', Rule::in(OrderType::values())],
            'customer_id' => ['nullable', 'integer', 'exists:customers,id'],
            'dining_table_id' => ['nullable', 'integer', 'exists:dining_tables,id'],
            'exchange_rate' => ['nullable', 'numeric', 'min:0'],
            'discount_code' => ['nullable', 'string', 'max:50'],
            'manual_discount' => ['nullable', 'array'],
            'manual_discount.type' => ['required_with:manual_discount', Rule::in(['PERCENTAGE', 'FIXED'])],
            'manual_discount.value' => ['required_with:manual_discount', 'numeric', 'min:0.01'],
            'manual_discount.reason' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.menu_item_id' => ['required', 'integer', 'exists:menu_items,id'],
            'items.*.menu_item_variant_id' => ['nullable', 'integer', 'exists:menu_item_variants,id'],
            'items.*.quantity' => ['required', 'numeric', 'min:0.001'],
            'items.*.special_instruction' => ['nullable', 'string', 'max:255'],
            'items.*.modifiers' => ['nullable', 'array'],
            'items.*.modifiers.*.modifier_option_id' => ['required_with:items.*.modifiers', 'integer', 'exists:modifier_options,id'],
            'items.*.modifiers.*.quantity' => ['nullable', 'numeric', 'min:0.001'],
        ]);

        $order = $this->orders->create($request->user(), $branch->id, $data);

        return ApiResponse::created($order, 'Order created successfully');
    }

    public function show(Order $order): JsonResponse
    {
        $this->authorize('view', $order);

        return ApiResponse::success($order->load(
            'branch:id,name,code',
            'cashier:id,name',
            'customer',
            'diningTable',
            'items.modifiers',
            'discounts.discount',
            'payments.transactions',
            'receipt',
            'statusHistories.changedBy:id,name'
        ));
    }

    public function update(Request $request, Order $order): JsonResponse
    {
        $this->authorize('update', $order);

        $data = $request->validate([
            'customer_id' => ['nullable', 'integer', 'exists:customers,id'],
            'notes' => ['nullable', 'string'],
        ]);

        $order->update($data);

        return ApiResponse::success($order->refresh(), 'Order updated successfully');
    }

    public function destroy(Order $order): JsonResponse
    {
        $this->authorize('cancel', $order);

        $this->orders->cancel($order, request()->user(), 'Order deleted via API');

        return ApiResponse::noContent('Order cancelled successfully');
    }

    public function sendToKitchen(Request $request, Order $order): JsonResponse
    {
        $this->authorize('update', $order);

        return ApiResponse::success($this->orders->sendToKitchen($order, $request->user()), 'Order sent to kitchen');
    }

    public function hold(Order $order): JsonResponse
    {
        $this->authorize('update', $order);

        return ApiResponse::success($this->orders->hold($order), 'Order held');
    }

    public function resume(Order $order): JsonResponse
    {
        $this->authorize('update', $order);

        return ApiResponse::success($this->orders->resume($order), 'Order resumed');
    }

    public function complete(Request $request, Order $order): JsonResponse
    {
        $this->authorize('update', $order);

        return ApiResponse::success($this->orders->complete($order, $request->user()), 'Order completed');
    }

    public function cancel(Request $request, Order $order): JsonResponse
    {
        $this->authorize('cancel', $order);

        $data = $request->validate(['reason' => ['nullable', 'string', 'max:255']]);

        return ApiResponse::success($this->orders->cancel($order, $request->user(), $data['reason'] ?? null), 'Order cancelled');
    }

    public function addItems(Request $request, Order $order): JsonResponse
    {
        $this->authorize('update', $order);

        $data = $request->validate([
            'items' => ['required', 'array', 'min:1'],
            'items.*.menu_item_id' => ['required', 'integer', 'exists:menu_items,id'],
            'items.*.menu_item_variant_id' => ['nullable', 'integer', 'exists:menu_item_variants,id'],
            'items.*.quantity' => ['required', 'numeric', 'min:0.001'],
            'items.*.special_instruction' => ['nullable', 'string', 'max:255'],
            'items.*.modifiers' => ['nullable', 'array'],
            'items.*.modifiers.*.modifier_option_id' => ['required_with:items.*.modifiers', 'integer', 'exists:modifier_options,id'],
            'items.*.modifiers.*.quantity' => ['nullable', 'numeric', 'min:0.001'],
        ]);

        return ApiResponse::success($this->orders->addItems($order, $request->user(), $data['items']), 'Items added successfully');
    }

    public function updateItem(Request $request, Order $order, OrderItem $item): JsonResponse
    {
        $this->authorize('update', $order);

        $data = $request->validate(['quantity' => ['required', 'numeric', 'min:0.001']]);

        return ApiResponse::success($this->orders->updateItemQuantity($order, $item->id, (float) $data['quantity']), 'Item updated successfully');
    }

    public function removeItem(Order $order, OrderItem $item): JsonResponse
    {
        $this->authorize('update', $order);

        return ApiResponse::success($this->orders->removeItem($order, $item->id), 'Item removed successfully');
    }

    public function receipt(Request $request, Order $order): JsonResponse
    {
        $this->authorize('view', $order);

        return ApiResponse::success($this->receipts->generate($order, $request->user()), 'Receipt generated successfully');
    }

    public function showReceipt(Order $order): JsonResponse
    {
        $this->authorize('view', $order);

        return ApiResponse::success($order->receipt);
    }

    public function printReceipt(Order $order): JsonResponse
    {
        $this->authorize('view', $order);

        if (! $order->receipt) {
            return ApiResponse::error('Receipt has not been generated yet.', null, 409, 'RECEIPT_MISSING');
        }

        return ApiResponse::success($this->receipts->markPrinted($order->receipt), 'Receipt marked as printed');
    }

    public function statuses(Order $order): JsonResponse
    {
        $this->authorize('view', $order);

        return ApiResponse::success($order->statusHistories()->with('changedBy:id,name')->get());
    }
}
