<?php

namespace App\Events;

use App\Models\Order;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class OrderCompleted implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public Order $order) {}

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('branch.'.$this->order->branch_id.'.pos'),
            new PrivateChannel('branch.'.$this->order->branch_id.'.kitchen'),
        ];
    }

    public function broadcastAs(): string
    {
        return 'order.completed';
    }

    public function broadcastWith(): array
    {
        return [
            'order_id' => $this->order->id,
            'order_number' => $this->order->order_number,
            'grand_total' => (float) $this->order->grand_total,
            'completed_at' => now()->toIso8601String(),
        ];
    }
}
