<?php

namespace App\Events;

use App\Models\Order;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class OrderSentToKitchen implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public Order $order) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel('branch.'.$this->order->branch_id.'.kitchen')];
    }

    public function broadcastAs(): string
    {
        return 'order.sent';
    }

    public function broadcastWith(): array
    {
        return [
            'order_id' => $this->order->id,
            'order_number' => $this->order->order_number,
            'branch_id' => $this->order->branch_id,
            'status' => $this->order->status->value,
            'kitchen_status' => $this->order->kitchen_status->value,
            'items_count' => $this->order->items()->count(),
            'sent_at' => now()->toIso8601String(),
        ];
    }
}
