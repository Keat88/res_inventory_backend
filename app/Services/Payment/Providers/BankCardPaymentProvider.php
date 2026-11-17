<?php

namespace App\Services\Payment\Providers;

use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Models\Payment;
use App\Support\ProviderSignature;

class BankCardPaymentProvider implements PaymentProviderInterface
{
    public function method(): PaymentMethod
    {
        return PaymentMethod::BANK_CARD;
    }

    public function initiate(Payment $payment, array $payload = []): array
    {
        return [
            'status' => PaymentStatus::PENDING->value,
            'provider_reference' => $payload['provider_reference'] ?? 'CARD-'.strtoupper(bin2hex(random_bytes(8))),
            'provider_transaction_id' => null,
            'payload' => ['requires_terminal_confirmation' => true],
        ];
    }

    public function confirm(Payment $payment, array $payload = []): array
    {
        $transactionId = $payload['provider_transaction_id'] ?? null;

        if (! $transactionId) {
            return ['verified' => false, 'status' => PaymentStatus::PENDING->value];
        }

        $verified = ProviderSignature::verify($transactionId, $payment->uuid, $payload['signature'] ?? null);

        if (! $verified) {
            return ['verified' => false, 'status' => PaymentStatus::PENDING->value];
        }

        return [
            'verified' => true,
            'status' => PaymentStatus::PAID->value,
            'provider_transaction_id' => $transactionId,
            'payload' => $payload,
        ];
    }

    public function refund(Payment $payment, float $amount, array $payload = []): array
    {
        $verified = ProviderSignature::verify(
            $payload['provider_transaction_id'] ?? '',
            $payment->uuid.'|'.$amount,
            $payload['signature'] ?? null
        );

        return [
            'refunded' => $verified,
            'provider_transaction_id' => $payload['provider_transaction_id'] ?? null,
            'payload' => $payload,
        ];
    }
}
