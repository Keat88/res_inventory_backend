<?php

namespace App\Services;

use App\Enums\PaymentStatus;
use App\Exceptions\BusinessException;
use App\Models\Order;
use App\Models\Receipt;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class ReceiptService
{
    public function __construct(
        protected DocumentNumberService $numbers,
        protected AuditService $audit
    ) {}

    public function generate(Order $order, User $user): Receipt
    {
        return DB::transaction(function () use ($order, $user) {
            $order->loadMissing(['branch', 'cashier', 'customer', 'items.modifiers', 'payments']);

            if ($order->status->value === 'CANCELLED') {
                throw new BusinessException('Cannot generate a receipt for a cancelled order.', 'ORDER_CANCELLED', null, 409);
            }

            $paymentSummary = $order->payments
                ->whereIn('status', [PaymentStatus::PAID, PaymentStatus::PARTIALLY_REFUNDED, PaymentStatus::REFUNDED])
                ->map(fn ($payment) => [
                    'method' => $payment->method->value,
                    'amount' => (float) $payment->amount,
                    'currency' => $payment->currency,
                    'cash_received' => $payment->cash_received !== null ? (float) $payment->cash_received : null,
                    'change' => $payment->change_given !== null ? (float) $payment->change_given : null,
                    'reference' => $payment->provider_reference,
                ])->values()->all();

            $itemsSnapshot = $order->items->map(fn ($item) => [
                'name' => $item->name_snapshot,
                'variant' => $item->variant_name_snapshot,
                'quantity' => (float) $item->quantity,
                'unit_price' => (float) $item->unit_price,
                'line_total' => (float) $item->line_total,
                'modifiers' => $item->modifiers->map(fn ($modifier) => [
                    'name' => $modifier->option_name_snapshot,
                    'quantity' => (float) $modifier->quantity,
                    'price' => (float) $modifier->total,
                ])->values()->all(),
            ])->values()->all();

            $receipt = Receipt::query()->updateOrCreate(
                ['order_id' => $order->id],
                [
                    'receipt_number' => $this->numbers->receiptNumber($order),
                    'branch_name' => $order->branch->name,
                    'branch_address' => $order->branch->address,
                    'cashier_name' => $order->cashier?->name,
                    'customer_name' => $order->customer?->name,
                    'subtotal' => $order->subtotal,
                    'discount' => $order->discount_total,
                    'tax' => $order->tax_total,
                    'service_charge' => $order->service_charge_total,
                    'total_paid' => $order->paid_total,
                    'change' => $order->change_total,
                    'payment_summary' => $paymentSummary,
                    'items_snapshot' => $itemsSnapshot,
                    'issued_at' => now(),
                    'created_by' => $user->id,
                ]
            );

            $this->audit->log('receipt.generated', 'receipt', $receipt->id, null, [
                'order_id' => $order->id,
                'receipt_number' => $receipt->receipt_number,
            ], $order->branch_id);

            return $receipt->refresh();
        });
    }

    public function markPrinted(Receipt $receipt): Receipt
    {
        $receipt->increment('printed_count');

        return $receipt->refresh();
    }
}
