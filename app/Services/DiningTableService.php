<?php

namespace App\Services;

use App\Enums\OrderStatus;
use App\Enums\TableStatus;
use App\Exceptions\BusinessException;
use App\Models\DiningTable;
use App\Models\Order;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class DiningTableService
{
    public function __construct(protected AuditService $audit) {}

    public function assign(DiningTable $table, Order $order, User $user): DiningTable
    {
        return DB::transaction(function () use ($table, $order, $user) {
            if ($table->branch_id !== $order->branch_id) {
                throw new BusinessException('Table and order belong to different branches.', 'BRANCH_MISMATCH', null, 422);
            }

            if ($table->status === TableStatus::OCCUPIED && (int) $table->current_order_id !== (int) $order->id) {
                throw new BusinessException('Table is already occupied.', 'TABLE_OCCUPIED', null, 409);
            }

            $table->status = TableStatus::OCCUPIED;
            $table->current_order_id = $order->id;
            $table->save();

            $order->dining_table_id = $table->id;
            $order->save();

            $this->audit->log('table.assigned', 'dining_table', $table->id, null, [
                'order_id' => $order->id,
            ], $table->branch_id, $user->id);

            return $table->refresh();
        });
    }

    public function transfer(DiningTable $from, DiningTable $to, User $user): DiningTable
    {
        return DB::transaction(function () use ($from, $to, $user) {
            if ($from->branch_id !== $to->branch_id) {
                throw new BusinessException('Tables belong to different branches.', 'BRANCH_MISMATCH', null, 422);
            }

            if (! $from->current_order_id) {
                throw new BusinessException('Source table has no active order.', 'TABLE_EMPTY', null, 409);
            }

            if ($to->status !== TableStatus::AVAILABLE) {
                throw new BusinessException('Destination table is not available.', 'TABLE_NOT_AVAILABLE', null, 409);
            }

            $order = Order::query()->lockForUpdate()->findOrFail($from->current_order_id);

            $from->status = TableStatus::AVAILABLE;
            $from->current_order_id = null;
            $from->save();

            $to->status = TableStatus::OCCUPIED;
            $to->current_order_id = $order->id;
            $to->save();

            $order->dining_table_id = $to->id;
            $order->save();

            $this->audit->log('table.transferred', 'dining_table', $from->id, [
                'to_table_id' => $from->id,
            ], [
                'to_table_id' => $to->id,
                'order_id' => $order->id,
            ], $from->branch_id, $user->id);

            return $to->refresh();
        });
    }

    public function merge(DiningTable $primary, DiningTable $secondary, User $user): DiningTable
    {
        return DB::transaction(function () use ($primary, $secondary, $user) {
            if ($primary->branch_id !== $secondary->branch_id) {
                throw new BusinessException('Tables belong to different branches.', 'BRANCH_MISMATCH', null, 422);
            }

            if (! $primary->current_order_id || ! $secondary->current_order_id) {
                throw new BusinessException('Both tables must have active orders to merge.', 'TABLE_EMPTY', null, 409);
            }

            if ((int) $primary->current_order_id === (int) $secondary->current_order_id) {
                throw new BusinessException('Both tables already share the same order.', 'SAME_ORDER', null, 409);
            }

            $primaryOrder = Order::query()->lockForUpdate()->findOrFail($primary->current_order_id);
            $secondaryOrder = Order::query()->lockForUpdate()->findOrFail($secondary->current_order_id);

            if (in_array($secondaryOrder->status, [OrderStatus::COMPLETED, OrderStatus::CANCELLED], true)) {
                throw new BusinessException('Secondary order is already closed.', 'ORDER_CLOSED', null, 409);
            }

            $secondaryOrder->items()->update(['order_id' => $primaryOrder->id]);
            $secondaryOrder->payments()->update(['order_id' => $primaryOrder->id]);
            $secondaryOrder->discounts()->update(['order_id' => $primaryOrder->id]);

            $secondaryOrder->status = OrderStatus::CANCELLED->value;
            $secondaryOrder->cancelled_at = now();
            $secondaryOrder->cancel_reason = 'Merged into order '.$primaryOrder->order_number;
            $secondaryOrder->save();

            $secondary->status = TableStatus::AVAILABLE;
            $secondary->current_order_id = null;
            $secondary->save();

            app(OrderService::class)->recalculate($primaryOrder);
            app(PaymentService::class)->syncOrderPaymentStatus($primaryOrder->refresh());

            $this->audit->log('table.merged', 'dining_table', $primary->id, [
                'secondary_table_id' => $secondary->id,
                'secondary_order_id' => $secondaryOrder->id,
            ], [
                'order_id' => $primaryOrder->id,
            ], $primary->branch_id, $user->id);

            return $primary->refresh();
        });
    }
}
