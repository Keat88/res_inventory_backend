<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\PaymentMethod;
use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Payment;
use App\Services\PaymentService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PaymentController extends Controller
{
    public function __construct(protected PaymentService $payments) {}

    public function index(Order $order): JsonResponse
    {
        $this->authorize('view', $order);

        return ApiResponse::success($order->payments()->with('transactions')->latest()->get());
    }

    public function store(Request $request, Order $order): JsonResponse
    {
        $this->authorize('view', $order);
        $this->authorize('create', Payment::class);

        $data = $request->validate([
            'method' => ['required', Rule::in(PaymentMethod::values())],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'cash_received' => ['nullable', 'numeric', 'min:0'],
            'provider_reference' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string'],
        ]);

        $payment = $this->payments->initiate($request->user(), $order, $data);

        return ApiResponse::created($payment, 'Payment initiated successfully');
    }

    public function show(Payment $payment): JsonResponse
    {
        $this->authorize('view', $payment);

        return ApiResponse::success($payment->load('order:id,order_number,branch_id,grand_total,paid_total', 'transactions'));
    }

    public function confirm(Request $request, Payment $payment): JsonResponse
    {
        $this->authorize('confirm', $payment);

        $data = $request->validate([
            'provider_transaction_id' => ['nullable', 'string', 'max:255'],
            'provider_reference' => ['nullable', 'string', 'max:255'],
        ]);

        return ApiResponse::success(
            $this->payments->confirm($request->user(), $payment, $data),
            'Payment confirmed successfully'
        );
    }

    public function refund(Request $request, Payment $payment): JsonResponse
    {
        $this->authorize('refund', $payment);

        $data = $request->validate([
            'amount' => ['required', 'numeric', 'min:0.01'],
            'reason' => ['nullable', 'string', 'max:255'],
            'provider_transaction_id' => ['nullable', 'string', 'max:255'],
        ]);

        return ApiResponse::success(
            $this->payments->refund(
                $request->user(),
                $payment,
                (float) $data['amount'],
                $data['reason'] ?? null,
                $data
            ),
            'Payment refunded successfully'
        );
    }
}
