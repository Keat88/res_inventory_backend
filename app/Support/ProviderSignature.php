<?php

namespace App\Support;

class ProviderSignature
{
    /**
     * Verify a provider callback signature. In local/testing environments a
     * non-empty transaction id is accepted so the flow can be exercised.
     */
    public static function verify(string $transactionId, string $context, ?string $signature): bool
    {
        if ($transactionId === '') {
            return false;
        }

        $secret = config('services.payment.webhook_secret');

        if (! $secret) {
            return app()->environment(['local', 'testing']);
        }

        if (! $signature) {
            return false;
        }

        $expected = hash_hmac('sha256', $transactionId.'|'.$context, $secret);

        return hash_equals($expected, $signature);
    }
}
