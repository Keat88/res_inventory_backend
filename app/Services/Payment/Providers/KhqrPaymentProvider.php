<?php

namespace App\Services\Payment\Providers;

use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Models\Payment;
use App\Support\ProviderSignature;

class KhqrPaymentProvider implements PaymentProviderInterface
{
    public function method(): PaymentMethod
    {
        return PaymentMethod::KHQR;
    }

    public function initiate(Payment $payment, array $payload = []): array
    {
        $reference = $payload['provider_reference'] ?? 'KHQR-'.strtoupper(bin2hex(random_bytes(8)));

        return [
            'status' => PaymentStatus::PENDING->value,
            'provider_reference' => $reference,
            'provider_transaction_id' => null,
            'payload' => [
                'qr_reference' => $reference,
                'expires_at' => now()->addMinutes(15)->toIso8601String(),
            ],
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
