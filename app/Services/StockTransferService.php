<?php

namespace App\Services;

use App\Enums\TransferStatus;
use App\Exceptions\BusinessException;
use App\Models\StockTransfer;
use App\Models\StockTransferItem;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class StockTransferService
{
    public function __construct(
        protected DocumentNumberService $numbers,
        protected InventoryService $inventory,
        protected AuditService $audit
    ) {}

    public function create(User $user, array $data): StockTransfer
    {
        return DB::transaction(function () use ($user, $data) {
            if ((int) $data['from_branch_id'] === (int) $data['to_branch_id']) {
                throw new BusinessException('Source and destination branches must be different.', 'SAME_BRANCH', null, 422);
            }

            $items = $data['items'] ?? [];

            if (empty($items)) {
                throw new BusinessException('A stock transfer needs at least one item.', 'TRANSFER_EMPTY', null, 422);
            }

            $transfer = StockTransfer::query()->create([
                'transfer_number' => $this->numbers->transferNumber(),
                'from_branch_id' => $data['from_branch_id'],
                'to_branch_id' => $data['to_branch_id'],
                'status' => TransferStatus::DRAFT->value,
                'requested_by' => $user->id,
                'notes' => $data['notes'] ?? null,
            ]);

            foreach ($items as $item) {
                $quantity = round((float) $item['quantity_requested'], 3);

                if ($quantity <= 0) {
                    throw new BusinessException('Transfer quantity must be greater than zero.', 'INVALID_QUANTITY', null, 422);
                }

                StockTransferItem::query()->create([
                    'stock_transfer_id' => $transfer->id,
                    'ingredient_id' => $item['ingredient_id'],
                    'quantity_requested' => $quantity,
                ]);
            }

            $this->audit->log('stock_transfer.created', 'stock_transfer', $transfer->id, null, [
                'from_branch_id' => $transfer->from_branch_id,
                'to_branch_id' => $transfer->to_branch_id,
            ], $transfer->from_branch_id);

            return $transfer->refresh()->load(['items.ingredient', 'fromBranch', 'toBranch']);
        });
    }

    public function approve(StockTransfer $transfer, User $user): StockTransfer
    {
        return DB::transaction(function () use ($transfer, $user) {
            if ($transfer->status !== TransferStatus::DRAFT) {
                throw new BusinessException('Only draft transfers can be approved.', 'INVALID_TRANSFER_STATE', null, 409);
            }

            $transfer->approved_by = $user->id;
            $transfer->save();

            $this->audit->log('stock_transfer.approved', 'stock_transfer', $transfer->id, null, null, $transfer->from_branch_id, $user->id);

            return $transfer->refresh()->load('items.ingredient');
        });
    }

    public function dispatch(StockTransfer $transfer, User $user): StockTransfer
    {
        return DB::transaction(function () use ($transfer, $user) {
            $transfer = StockTransfer::query()->whereKey($transfer->id)->lockForUpdate()->firstOrFail();

            if ($transfer->status !== TransferStatus::DRAFT) {
                throw new BusinessException('Only draft transfers can be dispatched.', 'INVALID_TRANSFER_STATE', null, 409);
            }

            if (! $transfer->approved_by) {
                throw new BusinessException('Transfer must be approved before dispatch.', 'TRANSFER_NOT_APPROVED', null, 409);
            }

            $transfer->load('items');

            foreach ($transfer->items as $item) {
                $this->inventory->transferOut(
                    $transfer->from_branch_id,
                    $item->ingredient_id,
                    (float) $item->quantity_requested,
                    $transfer->id,
                    $user->id
                );

                $item->quantity_dispatched = $item->quantity_requested;
                $item->save();
            }

            $transfer->status = TransferStatus::IN_TRANSIT->value;
            $transfer->dispatched_by = $user->id;
            $transfer->dispatched_at = now();
            $transfer->save();

            $this->audit->log('stock_transfer.dispatched', 'stock_transfer', $transfer->id, null, null, $transfer->from_branch_id, $user->id);

            return $transfer->refresh()->load('items.ingredient');
        });
    }

    public function receive(StockTransfer $transfer, User $user): StockTransfer
    {
        return DB::transaction(function () use ($transfer, $user) {
            $transfer = StockTransfer::query()->whereKey($transfer->id)->lockForUpdate()->firstOrFail();

            if ($transfer->status !== TransferStatus::IN_TRANSIT) {
                throw new BusinessException('Only in-transit transfers can be received.', 'INVALID_TRANSFER_STATE', null, 409);
            }

            $transfer->load('items');

            foreach ($transfer->items as $item) {
                $quantity = (float) ($item->quantity_dispatched ?: $item->quantity_requested);

                $this->inventory->transferIn(
                    $transfer->to_branch_id,
                    $item->ingredient_id,
                    $quantity,
                    $transfer->id,
                    $user->id
                );

                $item->quantity_received = $quantity;
                $item->save();
            }

            $transfer->status = TransferStatus::RECEIVED->value;
            $transfer->received_by = $user->id;
            $transfer->received_at = now();
            $transfer->save();

            $this->audit->log('stock_transfer.received', 'stock_transfer', $transfer->id, null, null, $transfer->to_branch_id, $user->id);

            return $transfer->refresh()->load('items.ingredient');
        });
    }

    public function cancel(StockTransfer $transfer): StockTransfer
    {
        return DB::transaction(function () use ($transfer) {
            if ($transfer->status !== TransferStatus::DRAFT) {
                throw new BusinessException('Only draft transfers can be cancelled.', 'INVALID_TRANSFER_STATE', null, 409);
            }

            $transfer->status = TransferStatus::CANCELLED->value;
            $transfer->save();

            $this->audit->log('stock_transfer.cancelled', 'stock_transfer', $transfer->id, null, null, $transfer->from_branch_id);

            return $transfer->refresh()->load('items.ingredient');
        });
    }
}
