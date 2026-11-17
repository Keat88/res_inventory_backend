<?php

namespace App\Events;

use App\Models\Payment;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class PaymentConfirmed implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public Payment $payment) {}

    public function broadcastOn(): array
    {
        $branchId = $this->payment->order->branch_id;

        return [
            new PrivateChannel('branch.'.$branchId.'.pos'),
            new PrivateChannel('branch.'.$branchId.'.kitchen'),
        ];
    }

    public function broadcastAs(): string
    {
        return 'payment.confirmed';
    }

    public function broadcastWith(): array
    {
        return [
            'payment_id' => $this->payment->id,
            'order_id' => $this->payment->order_id,
            'method' => $this->payment->method->value,
            'amount' => (float) $this->payment->amount,
            'status' => $this->payment->status->value,
            'paid_at' => $this->payment->paid_at?->toIso8601String(),
        ];
    }
}
