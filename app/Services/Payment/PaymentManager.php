<?php

namespace App\Services\Payment;

use App\Enums\PaymentMethod;
use App\Exceptions\BusinessException;
use App\Services\Payment\Providers\BankCardPaymentProvider;
use App\Services\Payment\Providers\CashPaymentProvider;
use App\Services\Payment\Providers\KhqrPaymentProvider;
use App\Services\Payment\Providers\PaymentProviderInterface;

class PaymentManager
{
    /** @var array<string, PaymentProviderInterface> */
    protected array $providers = [];

    public function __construct(
        CashPaymentProvider $cash,
        KhqrPaymentProvider $khqr,
        BankCardPaymentProvider $card
    ) {
        foreach ([$cash, $khqr, $card] as $provider) {
            $this->providers[$provider->method()->value] = $provider;
        }
    }

    public function driver(PaymentMethod|string $method): PaymentProviderInterface
    {
        $key = $method instanceof PaymentMethod ? $method->value : $method;

        if (! isset($this->providers[$key])) {
            throw new BusinessException("Unsupported payment method: {$key}", 'UNSUPPORTED_PAYMENT_METHOD', null, 422);
        }

        return $this->providers[$key];
    }
}
