<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\KitchenStatus;
use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Order;
use App\Models\OrderItem;
use App\Services\KitchenService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class KitchenController extends Controller
{
    public function __construct(protected KitchenService $kitchen) {}

    public function queue(Branch $branch, Request $request): JsonResponse
    {
        $orders = Order::query()
            ->where('branch_id', $branch->id)
            ->whereIn('status', ['SENT_TO_KITCHEN', 'IN_PROGRESS'])
            ->with('items.modifiers', 'diningTable:id,table_number,name', 'cashier:id,name')
            ->when($request->filled('kitchen_status'), fn ($q) => $q->where('kitchen_status', $request->string('kitchen_status')))
            ->orderBy('sent_to_kitchen_at')
            ->get();

        return ApiResponse::success($orders);
    }

    public function accept(Request $request, Order $order): JsonResponse
    {
        $this->authorize('update', $order);

        return ApiResponse::success($this->kitchen->accept($order, $request->user()), 'Order accepted by kitchen');
    }

    public function updateItem(Request $request, Order $order, OrderItem $item): JsonResponse
    {
        $this->authorize('update', $order);

        $data = $request->validate([
            'status' => ['required', Rule::in(KitchenStatus::values())],
        ]);

        return ApiResponse::success(
            $this->kitchen->updateItemStatus($order, $item, KitchenStatus::from($data['status']), $request->user()),
            'Kitchen item status updated'
        );
    }
}
