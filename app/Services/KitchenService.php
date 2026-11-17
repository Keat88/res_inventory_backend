<?php

namespace App\Services;

use App\Enums\KitchenStatus;
use App\Enums\OrderStatus;
use App\Events\KitchenStatusChanged;
use App\Exceptions\BusinessException;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderStatusHistory;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class KitchenService
{
    public function accept(Order $order, User $user): Order
    {
        return DB::transaction(function () use ($order, $user) {
            $this->assertKitchenOrder($order);

            if (! in_array($order->status, [OrderStatus::SENT_TO_KITCHEN, OrderStatus::IN_PROGRESS], true)) {
                throw new BusinessException('This order is not in the kitchen queue.', 'ORDER_NOT_IN_KITCHEN', null, 409);
            }

            $order->status = OrderStatus::IN_PROGRESS->value;
            $order->kitchen_status = KitchenStatus::ACCEPTED->value;
            $order->save();

            $order->items()->where('kitchen_status', KitchenStatus::NEW->value)
                ->update(['kitchen_status' => KitchenStatus::ACCEPTED->value]);

            $this->history($order, null, KitchenStatus::NEW, KitchenStatus::ACCEPTED, $user->id);

            event(new KitchenStatusChanged($order, KitchenStatus::ACCEPTED));

            return $order->refresh()->load('items.modifiers');
        });
    }

    public function updateItemStatus(Order $order, OrderItem $item, KitchenStatus $status, User $user): Order
    {
        return DB::transaction(function () use ($order, $item, $status, $user) {
            $this->assertKitchenOrder($order);

            if ((int) $item->order_id !== (int) $order->id) {
                throw new BusinessException('Item does not belong to this order.', 'ITEM_NOT_IN_ORDER', null, 422);
            }

            $previous = $item->kitchen_status;
            $item->kitchen_status = $status->value;
            $item->save();

            $this->history($order, $item, $previous, $status, $user->id);
            $this->syncOrderKitchenStatus($order, $status);

            event(new KitchenStatusChanged($order->refresh(), $status, $item));

            return $order->refresh()->load('items.modifiers');
        });
    }

    protected function syncOrderKitchenStatus(Order $order, KitchenStatus $itemStatus): void
    {
        $order->load('items');
        $statuses = $order->items->pluck('kitchen_status')->map(fn ($status) => $status->value);

        if ($statuses->every(fn ($status) => $status === KitchenStatus::COMPLETED->value)) {
            $order->kitchen_status = KitchenStatus::COMPLETED->value;
            $order->status = OrderStatus::COMPLETED->value;
            $order->completed_at = now();
        } elseif ($statuses->every(fn ($status) => in_array($status, [KitchenStatus::READY->value, KitchenStatus::COMPLETED->value], true))) {
            $order->kitchen_status = KitchenStatus::READY->value;
        } elseif ($statuses->contains(KitchenStatus::PREPARING->value)) {
            $order->kitchen_status = KitchenStatus::PREPARING->value;
        } else {
            $order->kitchen_status = $itemStatus->value;
        }

        $order->save();
    }

    protected function assertKitchenOrder(Order $order): void
    {
        if ($order->status === OrderStatus::CANCELLED) {
            throw new BusinessException('Cancelled orders cannot be processed by the kitchen.', 'ORDER_CANCELLED', null, 409);
        }
    }

    protected function history(Order $order, ?OrderItem $item, KitchenStatus $from, KitchenStatus $to, int $userId): void
    {
        OrderStatusHistory::query()->create([
            'order_id' => $order->id,
            'order_item_id' => $item?->id,
            'from_status' => $from->value,
            'to_status' => $to->value,
            'changed_by' => $userId,
        ]);
    }
}
