<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Services\PaymentService;
use App\Support\ApiResponse;
use App\Support\ProviderSignature;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PaymentWebhookController extends Controller
{
    public function __construct(protected PaymentService $payments) {}

    public function handle(Request $request, string $method): JsonResponse
    {
        $data = $request->validate([
            'payment_id' => ['required', 'integer', 'exists:payments,id'],
            'provider_transaction_id' => ['required', 'string', 'max:255'],
            'provider_reference' => ['nullable', 'string', 'max:255'],
            'signature' => ['nullable', 'string', 'max:255'],
        ]);

        $payment = Payment::query()->findOrFail($data['payment_id']);

        $verified = ProviderSignature::verify(
            $data['provider_transaction_id'],
            'payment:'.$payment->uuid,
            $data['signature'] ?? null
        );

        if (! $verified) {
            return ApiResponse::error('Invalid webhook signature.', null, 401, 'INVALID_SIGNATURE');
        }

        if ($payment->method->value !== strtoupper($method)) {
            return ApiResponse::error('Payment method mismatch.', null, 422, 'METHOD_MISMATCH');
        }

        $payment = $this->payments->confirm(null, $payment, $data);

        return ApiResponse::success($payment, 'Payment webhook processed');
    }
}
