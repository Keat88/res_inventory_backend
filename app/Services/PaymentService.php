<?php

namespace App\Services;

use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\PaymentTransactionStatus;
use App\Enums\PaymentTransactionType;
use App\Events\PaymentConfirmed;
use App\Exceptions\BusinessException;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentTransaction;
use App\Models\User;
use App\Services\Payment\PaymentManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class PaymentService
{
    public function __construct(
        protected PaymentManager $manager,
        protected AuditService $audit
    ) {}

    public function initiate(User $user, Order $order, array $data): Payment
    {
        return DB::transaction(function () use ($user, $order, $data) {
            $order = Order::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();

            if ($order->status === OrderStatus::CANCELLED) {
                throw new BusinessException('Cannot accept payment for a cancelled order.', 'ORDER_CANCELLED', null, 409);
            }

            $method = PaymentMethod::from($data['method']);
            $amount = round((float) $data['amount'], 2);

            if ($amount <= 0) {
                throw new BusinessException('Payment amount must be greater than zero.', 'INVALID_PAYMENT_AMOUNT', null, 422);
            }

            if ($amount > $order->balanceDue() + 0.001) {
                throw new BusinessException('Payment exceeds the remaining balance.', 'PAYMENT_EXCEEDS_BALANCE', [
                    'balance_due' => $order->balanceDue(),
                ], 409);
            }

            $cashReceived = isset($data['cash_received']) ? round((float) $data['cash_received'], 2) : null;

            if ($method === PaymentMethod::CASH && ($cashReceived === null || $cashReceived < $amount)) {
                throw new BusinessException('Cash received must be equal to or greater than the payment amount.', 'INSUFFICIENT_CASH', null, 422);
            }

            $payment = Payment::query()->create([
                'uuid' => (string) Str::uuid(),
                'order_id' => $order->id,
                'cashier_shift_id' => $order->cashier_shift_id,
                'method' => $method->value,
                'status' => PaymentStatus::PENDING->value,
                'amount' => $amount,
                'currency' => $order->currency,
                'exchange_rate' => $order->exchange_rate,
                'cash_received' => $cashReceived,
                'change_given' => $method === PaymentMethod::CASH ? round($cashReceived - $amount, 2) : null,
                'provider' => $method->value,
                'provider_reference' => $data['provider_reference'] ?? null,
                'notes' => $data['notes'] ?? null,
            ]);

            $provider = $this->manager->driver($method);
            $result = $provider->initiate($payment, $data);

            $status = PaymentStatus::from($result['status']);
            $payment->status = $status;
            $payment->provider_reference = $result['provider_reference'] ?? $payment->provider_reference;
            $payment->paid_at = $status === PaymentStatus::PAID ? now() : null;
            $payment->confirmed_by = $status === PaymentStatus::PAID ? $user->id : null;
            $payment->save();

            PaymentTransaction::query()->create([
                'payment_id' => $payment->id,
                'provider' => $method->value,
                'provider_transaction_id' => $result['provider_transaction_id'] ?? null,
                'type' => PaymentTransactionType::CHARGE->value,
                'status' => $status === PaymentStatus::PAID
                    ? PaymentTransactionStatus::SUCCEEDED->value
                    : PaymentTransactionStatus::PENDING->value,
                'amount' => $amount,
                'currency' => $order->currency,
                'idempotency_key' => $data['idempotency_key'] ?? request()?->header('Idempotency-Key'),
                'request_payload' => $data,
                'response_payload' => $result['payload'] ?? $result,
                'processed_at' => $status === PaymentStatus::PAID ? now() : null,
            ]);

            $this->syncOrderPaymentStatus($order->refresh());

            $this->audit->log('payment.initiated', 'payment', $payment->id, null, [
                'order_id' => $order->id,
                'method' => $method->value,
                'amount' => $amount,
                'status' => $status->value,
            ], $order->branch_id);

            if ($status === PaymentStatus::PAID) {
                event(new PaymentConfirmed($payment));
            }

            return $payment->refresh()->load('transactions');
        });
    }

    public function confirm(?User $user, Payment $payment, array $data = []): Payment
    {
        return DB::transaction(function () use ($user, $payment, $data) {
            $payment = Payment::query()->whereKey($payment->id)->lockForUpdate()->firstOrFail();

            if ($payment->status === PaymentStatus::PAID) {
                return $payment->load('transactions');
            }

            if ($payment->status !== PaymentStatus::PENDING) {
                throw new BusinessException('This payment cannot be confirmed.', 'INVALID_PAYMENT_STATE', null, 409);
            }

            $provider = $this->manager->driver($payment->method);
            $result = $provider->confirm($payment, $data);

            if (! ($result['verified'] ?? false)) {
                throw new BusinessException('Payment was not verified by the provider.', 'PAYMENT_NOT_VERIFIED', null, 409);
            }

            $payment->status = PaymentStatus::PAID->value;
            $payment->paid_at = now();
            $payment->confirmed_by = $user?->id;
            $payment->provider_reference = $result['provider_reference'] ?? $payment->provider_reference;
            $payment->save();

            PaymentTransaction::query()->create([
                'payment_id' => $payment->id,
                'provider' => $payment->method->value,
                'provider_transaction_id' => $result['provider_transaction_id'] ?? null,
                'type' => PaymentTransactionType::CONFIRM->value,
                'status' => PaymentTransactionStatus::SUCCEEDED->value,
                'amount' => $payment->amount,
                'currency' => $payment->currency,
                'request_payload' => $data,
                'response_payload' => $result['payload'] ?? $result,
                'processed_at' => now(),
            ]);

            $this->syncOrderPaymentStatus($payment->order->refresh());

            $this->audit->log('payment.confirmed', 'payment', $payment->id, null, [
                'order_id' => $payment->order_id,
                'amount' => $payment->amount,
            ], $payment->order->branch_id, $user?->id);

            event(new PaymentConfirmed($payment));

            return $payment->refresh()->load('transactions');
        });
    }

    public function refund(User $user, Payment $payment, float $amount, ?string $reason = null, array $data = []): Payment
    {
        return DB::transaction(function () use ($payment, $amount, $reason, $data) {
            $payment = Payment::query()->whereKey($payment->id)->lockForUpdate()->firstOrFail();

            if (! in_array($payment->status, [PaymentStatus::PAID, PaymentStatus::PARTIALLY_REFUNDED], true)) {
                throw new BusinessException('Only paid payments can be refunded.', 'INVALID_PAYMENT_STATE', null, 409);
            }

            $amount = round($amount, 2);

            if ($amount <= 0) {
                throw new BusinessException('Refund amount must be greater than zero.', 'INVALID_REFUND_AMOUNT', null, 422);
            }

            $alreadyRefunded = (float) PaymentTransaction::query()
                ->where('payment_id', $payment->id)
                ->where('type', PaymentTransactionType::REFUND->value)
                ->where('status', PaymentTransactionStatus::SUCCEEDED->value)
                ->sum('amount');

            $refundable = round((float) $payment->amount - $alreadyRefunded, 2);

            if ($amount > $refundable) {
                throw new BusinessException('Refund amount exceeds refundable balance.', 'REFUND_EXCEEDS_BALANCE', [
                    'refundable' => $refundable,
                ], 409);
            }

            $provider = $this->manager->driver($payment->method);
            $result = $provider->refund($payment, $amount, $data);

            if (! ($result['refunded'] ?? false)) {
                throw new BusinessException('Refund was not verified by the provider.', 'REFUND_NOT_VERIFIED', null, 409);
            }

            PaymentTransaction::query()->create([
                'payment_id' => $payment->id,
                'provider' => $payment->method->value,
                'provider_transaction_id' => $result['provider_transaction_id'] ?? null,
                'type' => PaymentTransactionType::REFUND->value,
                'status' => PaymentTransactionStatus::SUCCEEDED->value,
                'amount' => $amount,
                'currency' => $payment->currency,
                'request_payload' => $data,
                'response_payload' => $result['payload'] ?? $result,
                'processed_at' => now(),
            ]);

            $payment->status = ($alreadyRefunded + $amount) >= (float) $payment->amount
                ? PaymentStatus::REFUNDED->value
                : PaymentStatus::PARTIALLY_REFUNDED->value;
            $payment->save();

            $this->syncOrderPaymentStatus($payment->order->refresh());

            $this->audit->log('payment.refunded', 'payment', $payment->id, null, [
                'amount' => $amount,
                'reason' => $reason,
            ], $payment->order->branch_id);

            return $payment->refresh()->load('transactions');
        });
    }

    public function syncOrderPaymentStatus(Order $order): void
    {
        $payments = $order->payments()->get();

        $grossPaid = (float) $payments
            ->filter(fn (Payment $payment) => in_array($payment->status, [
                PaymentStatus::PAID,
                PaymentStatus::PARTIALLY_REFUNDED,
                PaymentStatus::REFUNDED,
            ], true))
            ->sum('amount');

        $refunded = (float) PaymentTransaction::query()
            ->whereIn('payment_id', $payments->pluck('id'))
            ->where('type', PaymentTransactionType::REFUND->value)
            ->where('status', PaymentTransactionStatus::SUCCEEDED->value)
            ->sum('amount');

        $paidTotal = round(max(0, $grossPaid - $refunded), 2);
        $changeTotal = round((float) $payments->sum('change_given'), 2);

        $order->paid_total = $paidTotal;
        $order->change_total = $changeTotal;

        if ($refunded > 0 && $paidTotal <= 0) {
            $order->payment_status = PaymentStatus::REFUNDED->value;
        } elseif ($paidTotal <= 0) {
            $order->payment_status = PaymentStatus::UNPAID->value;
        } elseif ($paidTotal + 0.001 >= (float) $order->grand_total) {
            $order->payment_status = PaymentStatus::PAID->value;
        } else {
            $order->payment_status = PaymentStatus::PARTIAL->value;
        }

        $order->save();
    }
}
