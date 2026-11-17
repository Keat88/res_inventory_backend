<?php

namespace App\Services\Payment\Providers;

use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Models\Payment;

class CashPaymentProvider implements PaymentProviderInterface
{
    public function method(): PaymentMethod
    {
        return PaymentMethod::CASH;
    }

    public function initiate(Payment $payment, array $payload = []): array
    {
        return [
            'status' => PaymentStatus::PAID->value,
            'provider_reference' => 'CASH-'.$payment->uuid,
            'provider_transaction_id' => 'CASH-'.$payment->uuid,
            'payload' => ['received' => $payload['cash_received'] ?? null],
        ];
    }

    public function confirm(Payment $payment, array $payload = []): array
    {
        return [
            'verified' => true,
            'status' => PaymentStatus::PAID->value,
            'provider_transaction_id' => 'CASH-'.$payment->uuid,
        ];
    }

    public function refund(Payment $payment, float $amount, array $payload = []): array
    {
        return [
            'refunded' => true,
            'provider_transaction_id' => 'CASH-REFUND-'.$payment->uuid.'-'.now()->timestamp,
        ];
    }
}
