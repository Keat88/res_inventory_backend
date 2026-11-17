<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\Order;
use App\Models\PurchaseOrder;
use App\Models\StockTransfer;
use Illuminate\Support\Facades\DB;

class DocumentNumberService
{
    public function orderNumber(int $branchId): string
    {
        return DB::transaction(function () use ($branchId) {
            $branch = Branch::query()->whereKey($branchId)->lockForUpdate()->firstOrFail();
            $date = now()->format('Ymd');
            $prefix = $branch->code.'-'.$date.'-';
            $count = Order::query()
                ->where('branch_id', $branchId)
                ->where('order_number', 'like', $prefix.'%')
                ->lockForUpdate()
                ->count();

            return $prefix.str_pad((string) ($count + 1), 4, '0', STR_PAD_LEFT);
        });
    }

    public function purchaseOrderNumber(int $branchId): string
    {
        return DB::transaction(function () use ($branchId) {
            $branch = Branch::query()->whereKey($branchId)->lockForUpdate()->firstOrFail();
            $date = now()->format('Ymd');
            $prefix = 'PO-'.$branch->code.'-'.$date.'-';
            $count = PurchaseOrder::query()
                ->where('po_number', 'like', $prefix.'%')
                ->lockForUpdate()
                ->count();

            return $prefix.str_pad((string) ($count + 1), 4, '0', STR_PAD_LEFT);
        });
    }

    public function transferNumber(): string
    {
        $date = now()->format('Ymd');
        $prefix = 'TRF-'.$date.'-';
        $count = StockTransfer::query()
            ->where('transfer_number', 'like', $prefix.'%')
            ->lockForUpdate()
            ->count();

        return $prefix.str_pad((string) ($count + 1), 4, '0', STR_PAD_LEFT);
    }

    public function receiptNumber(Order $order): string
    {
        return 'RCP-'.$order->order_number;
    }
}
