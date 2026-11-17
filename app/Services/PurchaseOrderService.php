<?php

namespace App\Services;

use App\Enums\PurchaseOrderStatus;
use App\Exceptions\BusinessException;
use App\Models\GoodsReceipt;
use App\Models\Ingredient;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class PurchaseOrderService
{
    public function __construct(
        protected DocumentNumberService $numbers,
        protected InventoryService $inventory,
        protected AuditService $audit
    ) {}

    public function create(User $user, int $branchId, array $data): PurchaseOrder
    {
        return DB::transaction(function () use ($user, $branchId, $data) {
            $items = $data['items'] ?? [];

            if (empty($items)) {
                throw new BusinessException('A purchase order needs at least one item.', 'PO_EMPTY', null, 422);
            }

            $po = PurchaseOrder::query()->create([
                'po_number' => $this->numbers->purchaseOrderNumber($branchId),
                'branch_id' => $branchId,
                'supplier_id' => $data['supplier_id'],
                'status' => PurchaseOrderStatus::DRAFT->value,
                'currency' => $data['currency'] ?? 'USD',
                'exchange_rate' => $data['exchange_rate'] ?? 1,
                'tax_total' => round((float) ($data['tax_total'] ?? 0), 2),
                'discount_total' => round((float) ($data['discount_total'] ?? 0), 2),
                'expected_at' => $data['expected_at'] ?? null,
                'notes' => $data['notes'] ?? null,
                'created_by' => $user->id,
            ]);

            $subtotal = 0.0;

            foreach ($items as $item) {
                $ingredient = Ingredient::query()->findOrFail($item['ingredient_id']);
                $quantity = round((float) $item['quantity_ordered'], 3);
                $unitCost = round((float) $item['unit_cost'], 4);

                if ($quantity <= 0) {
                    throw new BusinessException('Ordered quantity must be greater than zero.', 'INVALID_QUANTITY', null, 422);
                }

                $lineTotal = round($quantity * $unitCost, 2);
                $subtotal += $lineTotal;

                PurchaseOrderItem::query()->create([
                    'purchase_order_id' => $po->id,
                    'ingredient_id' => $ingredient->id,
                    'quantity_ordered' => $quantity,
                    'quantity_received' => 0,
                    'unit_cost' => $unitCost,
                    'line_total' => $lineTotal,
                ]);
            }

            $po->subtotal = round($subtotal, 2);
            $po->grand_total = round($po->subtotal + (float) $po->tax_total - (float) $po->discount_total, 2);
            $po->save();

            $this->audit->log('purchase_order.created', 'purchase_order', $po->id, null, [
                'po_number' => $po->po_number,
                'grand_total' => $po->grand_total,
            ], $branchId);

            return $po->refresh()->load(['items.ingredient', 'supplier', 'branch']);
        });
    }

    public function submit(PurchaseOrder $po): PurchaseOrder
    {
        return DB::transaction(function () use ($po) {
            if ($po->status !== PurchaseOrderStatus::DRAFT) {
                throw new BusinessException('Only draft purchase orders can be submitted.', 'INVALID_PO_STATE', null, 409);
            }

            $po->status = PurchaseOrderStatus::ORDERED->value;
            $po->ordered_at = now();
            $po->save();

            $this->audit->log('purchase_order.submitted', 'purchase_order', $po->id, null, null, $po->branch_id);

            return $po->refresh()->load(['items.ingredient', 'supplier']);
        });
    }

    public function approve(PurchaseOrder $po, User $user): PurchaseOrder
    {
        return DB::transaction(function () use ($po, $user) {
            if (! in_array($po->status, [PurchaseOrderStatus::DRAFT, PurchaseOrderStatus::ORDERED], true)) {
                throw new BusinessException('This purchase order cannot be approved.', 'INVALID_PO_STATE', null, 409);
            }

            $po->status = PurchaseOrderStatus::ORDERED->value;
            $po->approved_by = $user->id;
            $po->approved_at = now();
            $po->ordered_at = $po->ordered_at ?? now();
            $po->save();

            $this->audit->log('purchase_order.approved', 'purchase_order', $po->id, null, null, $po->branch_id, $user->id);

            return $po->refresh()->load(['items.ingredient', 'supplier']);
        });
    }

    /**
     * Receive goods (partial or full). Idempotent by purchase order state and
     * the stock movement unique reference per goods receipt.
     */
    public function receive(PurchaseOrder $po, User $user, array $items, ?string $notes = null): PurchaseOrder
    {
        return DB::transaction(function () use ($po, $user, $items, $notes) {
            $po = PurchaseOrder::query()->whereKey($po->id)->lockForUpdate()->firstOrFail();

            if (! in_array($po->status, [PurchaseOrderStatus::ORDERED, PurchaseOrderStatus::PARTIALLY_RECEIVED], true)) {
                throw new BusinessException('This purchase order cannot receive goods in its current state.', 'INVALID_PO_STATE', null, 409);
            }

            if (empty($items)) {
                throw new BusinessException('No received items were provided.', 'EMPTY_RECEIPT', null, 422);
            }

            $po->load('items');
            $receiptNumber = 'GRN-'.$po->po_number.'-'.str_pad((string) ($po->receipts()->count() + 1), 2, '0', STR_PAD_LEFT);

            $receipt = GoodsReceipt::query()->create([
                'purchase_order_id' => $po->id,
                'receipt_number' => $receiptNumber,
                'received_by' => $user->id,
                'received_at' => now(),
                'notes' => $notes,
            ]);

            foreach ($items as $row) {
                $poItem = $po->items->firstWhere('id', (int) ($row['purchase_order_item_id'] ?? 0));

                if (! $poItem) {
                    throw new BusinessException('Purchase order item not found.', 'PO_ITEM_NOT_FOUND', null, 404);
                }

                $quantity = round((float) ($row['quantity_received'] ?? 0), 3);
                $remaining = round((float) $poItem->quantity_ordered - (float) $poItem->quantity_received, 3);

                if ($quantity <= 0) {
                    continue;
                }

                if ($quantity > $remaining) {
                    throw new BusinessException('Received quantity exceeds the remaining quantity.', 'OVER_RECEIPT', [
                        'purchase_order_item_id' => $poItem->id,
                        'remaining' => $remaining,
                    ], 409);
                }

                $receipt->items()->create([
                    'purchase_order_item_id' => $poItem->id,
                    'quantity_received' => $quantity,
                    'unit_cost' => $poItem->unit_cost,
                    'line_total' => round($quantity * (float) $poItem->unit_cost, 2),
                ]);

                $poItem->quantity_received = round((float) $poItem->quantity_received + $quantity, 3);
                $poItem->save();

                $this->inventory->receiveStock(
                    $po->branch_id,
                    $poItem->ingredient_id,
                    $quantity,
                    (float) $poItem->unit_cost,
                    'goods_receipt',
                    $receipt->id,
                    $user->id,
                    'grn:'.$receipt->id.':'.$poItem->ingredient_id
                );
            }

            $po->load('items');
            $allReceived = $po->items->every(
                fn (PurchaseOrderItem $item) => (float) $item->quantity_received + 0.0001 >= (float) $item->quantity_ordered
            );

            $po->status = $allReceived
                ? PurchaseOrderStatus::RECEIVED->value
                : PurchaseOrderStatus::PARTIALLY_RECEIVED->value;
            $po->received_at = $allReceived ? now() : $po->received_at;
            $po->save();

            $this->audit->log('purchase_order.received', 'purchase_order', $po->id, null, [
                'receipt_number' => $receipt->receipt_number,
                'status' => $po->status->value,
            ], $po->branch_id, $user->id);

            return $po->refresh()->load(['items.ingredient', 'supplier', 'receipts.items']);
        });
    }

    public function cancel(PurchaseOrder $po): PurchaseOrder
    {
        return DB::transaction(function () use ($po) {
            if ($po->status === PurchaseOrderStatus::RECEIVED) {
                throw new BusinessException('Received purchase orders cannot be cancelled.', 'INVALID_PO_STATE', null, 409);
            }

            if ($po->items()->where('quantity_received', '>', 0)->exists()) {
                throw new BusinessException('Purchase orders with received goods cannot be cancelled.', 'PO_HAS_RECEIPTS', null, 409);
            }

            $po->status = PurchaseOrderStatus::CANCELLED->value;
            $po->save();

            $this->audit->log('purchase_order.cancelled', 'purchase_order', $po->id, null, null, $po->branch_id);

            return $po->refresh()->load('items.ingredient');
        });
    }
}
