<?php

namespace App\Services;

use App\Enums\DiscountType;
use App\Enums\KitchenStatus;
use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\PaymentStatus;
use App\Enums\TableStatus;
use App\Events\OrderCompleted;
use App\Events\OrderSentToKitchen;
use App\Exceptions\BusinessException;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\DiningTable;
use App\Models\Order;
use App\Models\OrderDiscount;
use App\Models\OrderItem;
use App\Models\OrderStatusHistory;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class OrderService
{
    public function __construct(
        protected OrderPricingService $pricing,
        protected InventoryService $inventory,
        protected DocumentNumberService $numbers,
        protected AuditService $audit
    ) {}

    public function create(User $user, int $branchId, array $data): Order
    {
        return DB::transaction(function () use ($user, $branchId, $data) {
            $branch = Branch::query()->findOrFail($branchId);
            $items = $data['items'] ?? [];

            if (empty($items)) {
                throw new BusinessException('An order must contain at least one item.', 'ORDER_EMPTY', null, 422);
            }

            $table = null;
            $type = OrderType::from($data['type'] ?? OrderType::DINE_IN->value);

            if ($type === OrderType::DINE_IN && ! empty($data['dining_table_id'])) {
                $table = DiningTable::query()
                    ->where('branch_id', $branchId)
                    ->lockForUpdate()
                    ->find($data['dining_table_id']);

                if (! $table) {
                    throw new BusinessException('Dining table not found for this branch.', 'TABLE_NOT_FOUND', null, 404);
                }

                if ($table->status !== TableStatus::AVAILABLE) {
                    throw new BusinessException('Dining table is not available.', 'TABLE_NOT_AVAILABLE', ['table_id' => $table->id], 409);
                }
            }

            $pricedLines = array_map(
                fn (array $line) => $this->pricing->priceLine($branchId, $line),
                $items
            );

            $discount = $this->pricing->resolveDiscount($data['discount_code'] ?? null, $branchId);
            $manualDiscount = $this->resolveManualDiscount($user, $data['manual_discount'] ?? null);
            $totals = $this->pricing->computeTotals($branch, $pricedLines, $discount, $manualDiscount);

            $shift = $user->shifts()
                ->where('branch_id', $branchId)
                ->where('status', 'OPEN')
                ->first();

            $order = Order::query()->create([
                'branch_id' => $branchId,
                'order_number' => $this->numbers->orderNumber($branchId),
                'type' => $type->value,
                'status' => OrderStatus::DRAFT->value,
                'payment_status' => PaymentStatus::UNPAID->value,
                'kitchen_status' => KitchenStatus::NEW->value,
                'customer_id' => $data['customer_id'] ?? null,
                'cashier_id' => $user->id,
                'dining_table_id' => $table?->id,
                'cashier_shift_id' => $shift?->id,
                'currency' => $branch->currency,
                'exchange_rate' => $data['exchange_rate'] ?? 1,
                'subtotal' => $totals['subtotal'],
                'discount_total' => $totals['discount_total'],
                'tax_rate_snapshot' => $totals['tax_rate'],
                'tax_total' => $totals['tax_total'],
                'service_charge_rate_snapshot' => $totals['service_charge_rate'],
                'service_charge_total' => $totals['service_charge_total'],
                'grand_total' => $totals['grand_total'],
                'paid_total' => 0,
                'change_total' => 0,
                'notes' => $data['notes'] ?? null,
                'placed_at' => now(),
            ]);

            $this->persistLines($order, $pricedLines);
            $this->persistDiscount($order, $discount, $manualDiscount, $totals, $user);

            if ($discount) {
                $discount->increment('used_count');
            }

            if ($table) {
                $table->status = TableStatus::OCCUPIED;
                $table->current_order_id = $order->id;
                $table->save();
            }

            $this->audit->log('order.created', 'order', $order->id, null, [
                'order_number' => $order->order_number,
                'grand_total' => $order->grand_total,
            ], $branchId);

            return $order->refresh()->load($this->defaultRelations());
        });
    }

    public function addItems(Order $order, User $user, array $items): Order
    {
        return DB::transaction(function () use ($order, $items) {
            $this->assertEditable($order);

            $pricedLines = array_map(
                fn (array $line) => $this->pricing->priceLine($order->branch_id, $line),
                $items
            );

            $this->persistLines($order, $pricedLines);
            $this->recalculate($order);

            return $order->refresh()->load($this->defaultRelations());
        });
    }

    public function updateItemQuantity(Order $order, int $itemId, float $quantity): Order
    {
        return DB::transaction(function () use ($order, $itemId, $quantity) {
            $this->assertEditable($order);

            if ($quantity <= 0) {
                throw new BusinessException('Item quantity must be greater than zero.', 'INVALID_QUANTITY', null, 422);
            }

            $item = $order->items()->find($itemId);
            if (! $item) {
                throw new BusinessException('Order item not found.', 'ITEM_NOT_FOUND', null, 404);
            }

            $item->quantity = $quantity;
            $item->line_subtotal = round((float) $item->unit_price * $quantity, 2);
            $item->line_total = $item->line_subtotal;
            $item->save();

            $this->recalculate($order);

            return $order->refresh()->load($this->defaultRelations());
        });
    }

    public function removeItem(Order $order, int $itemId): Order
    {
        return DB::transaction(function () use ($order, $itemId) {
            $this->assertEditable($order);

            $item = $order->items()->find($itemId);
            if (! $item) {
                throw new BusinessException('Order item not found.', 'ITEM_NOT_FOUND', null, 404);
            }

            $item->delete();

            if ($order->items()->count() === 0) {
                throw new BusinessException('An order must contain at least one item.', 'ORDER_EMPTY', null, 422);
            }

            $this->recalculate($order);

            return $order->refresh()->load($this->defaultRelations());
        });
    }

    public function recalculate(Order $order): void
    {
        $order->loadMissing(['items', 'discounts']);

        $subtotal = round((float) $order->items->sum('line_subtotal'), 2);
        $discountTotal = round((float) $order->discounts->sum('amount'), 2);
        $discountTotal = min($discountTotal, $subtotal);
        $taxable = round($subtotal - $discountTotal, 2);

        $taxTotal = round($taxable * (float) $order->tax_rate_snapshot / 100, 2);
        $serviceTotal = round($taxable * (float) $order->service_charge_rate_snapshot / 100, 2);

        $order->subtotal = $subtotal;
        $order->discount_total = $discountTotal;
        $order->tax_total = $taxTotal;
        $order->service_charge_total = $serviceTotal;
        $order->grand_total = round($taxable + $taxTotal + $serviceTotal, 2);
        $order->save();
    }

    public function hold(Order $order): Order
    {
        return $this->transition($order, OrderStatus::HELD, 'order.held');
    }

    public function resume(Order $order): Order
    {
        return $this->transition($order, OrderStatus::DRAFT, 'order.resumed');
    }

    public function sendToKitchen(Order $order, User $user): Order
    {
        return DB::transaction(function () use ($order, $user) {
            $this->assertTransition($order, OrderStatus::SENT_TO_KITCHEN);
            $previous = $order->status;

            $this->inventory->deductForOrder($order);

            $order->status = OrderStatus::SENT_TO_KITCHEN->value;
            $order->kitchen_status = KitchenStatus::NEW->value;
            $order->sent_to_kitchen_at = now();
            $order->placed_at = $order->placed_at ?? now();
            $order->save();

            $order->items()->update(['kitchen_status' => KitchenStatus::NEW->value]);

            $this->recordStatus($order, $previous, OrderStatus::SENT_TO_KITCHEN, $user->id, 'Sent to kitchen');

            event(new OrderSentToKitchen($order));

            return $order->refresh()->load($this->defaultRelations());
        });
    }

    public function cancel(Order $order, User $user, ?string $reason = null): Order
    {
        return DB::transaction(function () use ($order, $user, $reason) {
            if (in_array($order->status, [OrderStatus::COMPLETED, OrderStatus::CANCELLED], true)) {
                throw new BusinessException('This order can no longer be cancelled.', 'ORDER_NOT_CANCELLABLE', null, 409);
            }

            if ((float) $order->paid_total > 0) {
                throw new BusinessException('Refund payments before cancelling this order.', 'ORDER_HAS_PAYMENTS', null, 409);
            }

            if ($order->status !== OrderStatus::DRAFT) {
                $this->inventory->reverseForOrder($order, $reason ?? 'Order cancelled');
            }

            $previous = $order->status;

            $order->status = OrderStatus::CANCELLED->value;
            $order->cancelled_at = now();
            $order->cancel_reason = $reason;
            $order->save();

            foreach ($order->discounts as $orderDiscount) {
                $orderDiscount->discount?->decrement('used_count');
            }

            $this->releaseTable($order);
            $this->recordStatus($order, $previous, OrderStatus::CANCELLED, $user->id, $reason);

            $this->audit->log('order.cancelled', 'order', $order->id, null, [
                'reason' => $reason,
            ], $order->branch_id);

            return $order->refresh()->load($this->defaultRelations());
        });
    }

    public function complete(Order $order, User $user): Order
    {
        return DB::transaction(function () use ($order, $user) {
            if (! in_array($order->status, [OrderStatus::SENT_TO_KITCHEN, OrderStatus::IN_PROGRESS], true)) {
                throw new BusinessException('Only orders in progress can be completed.', 'INVALID_ORDER_STATE', null, 409);
            }

            $previous = $order->status;
            $order->status = OrderStatus::COMPLETED->value;
            $order->kitchen_status = KitchenStatus::COMPLETED->value;
            $order->completed_at = now();
            $order->save();

            $order->items()->update(['kitchen_status' => KitchenStatus::COMPLETED->value]);
            $this->releaseTable($order);
            $this->recordStatus($order, $previous, OrderStatus::COMPLETED, $user->id);
            $this->refreshCustomerStats($order);

            event(new OrderCompleted($order));

            return $order->refresh()->load($this->defaultRelations());
        });
    }

    protected function persistLines(Order $order, array $pricedLines): void
    {
        foreach ($pricedLines as $line) {
            $item = OrderItem::query()->create([
                'order_id' => $order->id,
                'menu_item_id' => $line['menu_item_id'],
                'menu_item_variant_id' => $line['menu_item_variant_id'],
                'name_snapshot' => $line['name_snapshot'],
                'variant_name_snapshot' => $line['variant_name_snapshot'],
                'unit_price' => $line['unit_price'],
                'quantity' => $line['quantity'],
                'line_subtotal' => $line['line_subtotal'],
                'discount_amount' => 0,
                'tax_amount' => 0,
                'line_total' => $line['line_subtotal'],
                'kitchen_status' => KitchenStatus::NEW->value,
                'special_instruction' => $line['special_instruction'],
            ]);

            foreach ($line['modifiers'] as $modifier) {
                $item->modifiers()->create($modifier);
            }
        }
    }

    protected function persistDiscount(
        Order $order,
        $discount,
        ?array $manualDiscount,
        array $totals,
        User $user
    ): void {
        if ($totals['discount_type'] === null) {
            return;
        }

        OrderDiscount::query()->create([
            'order_id' => $order->id,
            'discount_id' => $discount?->id,
            'type' => $totals['discount_type'] instanceof DiscountType
                ? $totals['discount_type']->value
                : $totals['discount_type'],
            'value' => $totals['discount_value'],
            'amount' => $totals['discount_total'],
            'reason' => $manualDiscount['reason'] ?? null,
            'approved_by' => $manualDiscount ? $user->id : null,
        ]);
    }

    protected function resolveManualDiscount(User $user, ?array $manualDiscount): ?array
    {
        if (! $manualDiscount) {
            return null;
        }

        if (! $user->can('discounts.authorize')) {
            throw new BusinessException('You are not allowed to apply a manual discount.', 'DISCOUNT_UNAUTHORIZED', null, 403);
        }

        if (! in_array($manualDiscount['type'] ?? null, DiscountType::values(), true)) {
            throw new BusinessException('Invalid manual discount type.', 'INVALID_DISCOUNT', null, 422);
        }

        if ((float) ($manualDiscount['value'] ?? 0) <= 0) {
            throw new BusinessException('Manual discount value must be greater than zero.', 'INVALID_DISCOUNT', null, 422);
        }

        return $manualDiscount;
    }

    protected function assertEditable(Order $order): void
    {
        if (! in_array($order->status, [OrderStatus::DRAFT, OrderStatus::HELD], true)) {
            throw new BusinessException('Only draft or held orders can be modified.', 'ORDER_NOT_EDITABLE', null, 409);
        }

        if ((float) $order->paid_total > 0) {
            throw new BusinessException('This order already has payments.', 'ORDER_HAS_PAYMENTS', null, 409);
        }
    }

    protected function assertTransition(Order $order, OrderStatus $to): void
    {
        if (! $order->status->canTransitionTo($to)) {
            throw new BusinessException("Order cannot transition from {$order->status->value} to {$to->value}.", 'INVALID_ORDER_STATE', null, 409);
        }
    }

    protected function transition(Order $order, OrderStatus $to, string $auditAction): Order
    {
        return DB::transaction(function () use ($order, $to, $auditAction) {
            $this->assertTransition($order, $to);
            $previous = $order->status;
            $order->status = $to->value;
            $order->save();
            $this->recordStatus($order, $previous, $to, auth()->id());
            $this->audit->log($auditAction, 'order', $order->id, null, ['status' => $to->value], $order->branch_id);

            return $order->refresh()->load($this->defaultRelations());
        });
    }

    protected function recordStatus(Order $order, OrderStatus $from, OrderStatus $to, ?int $userId, ?string $reason = null): void
    {
        OrderStatusHistory::query()->create([
            'order_id' => $order->id,
            'from_status' => $from->value,
            'to_status' => $to->value,
            'changed_by' => $userId,
            'reason' => $reason,
        ]);
    }

    protected function releaseTable(Order $order): void
    {
        if (! $order->dining_table_id) {
            return;
        }

        $table = DiningTable::query()->find($order->dining_table_id);

        if ($table && (int) $table->current_order_id === (int) $order->id) {
            $table->status = TableStatus::AVAILABLE;
            $table->current_order_id = null;
            $table->save();
        }
    }

    public function refreshCustomerStats(Order $order): void
    {
        if (! $order->customer_id) {
            return;
        }

        $customer = Customer::query()->find($order->customer_id);
        if (! $customer) {
            return;
        }

        $completed = Order::query()
            ->where('customer_id', $customer->id)
            ->where('status', OrderStatus::COMPLETED->value)
            ->selectRaw('COUNT(*) as orders_count, COALESCE(SUM(grand_total), 0) as spending')
            ->first();

        $customer->total_orders = (int) ($completed->orders_count ?? 0);
        $customer->total_spending = round((float) ($completed->spending ?? 0), 2);
        $customer->save();
    }

    protected function defaultRelations(): array
    {
        return [
            'items.modifiers',
            'discounts',
            'payments',
            'cashier',
            'customer',
            'diningTable',
            'receipt',
        ];
    }
}
