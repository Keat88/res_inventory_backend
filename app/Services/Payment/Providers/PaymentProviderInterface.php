<?php

namespace App\Services\Payment\Providers;

use App\Enums\PaymentMethod;
use App\Models\Payment;

interface PaymentProviderInterface
{
    public function method(): PaymentMethod;

    /**
     * @return array{status: string, provider_reference?: string|null, provider_transaction_id?: string|null, payload?: array}
     */
    public function initiate(Payment $payment, array $payload = []): array;

    /**
     * @return array{verified: bool, status: string, provider_transaction_id?: string|null, payload?: array}
     */
    public function confirm(Payment $payment, array $payload = []): array;

    /**
     * @return array{refunded: bool, provider_transaction_id?: string|null, payload?: array}
     */
    public function refund(Payment $payment, float $amount, array $payload = []): array;
}
