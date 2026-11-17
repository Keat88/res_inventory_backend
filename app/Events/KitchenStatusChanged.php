<?php

namespace App\Events;

use App\Enums\KitchenStatus;
use App\Models\Order;
use App\Models\OrderItem;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class KitchenStatusChanged implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public Order $order,
        public KitchenStatus $status,
        public ?OrderItem $item = null
    ) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel('branch.'.$this->order->branch_id.'.kitchen')];
    }

    public function broadcastAs(): string
    {
        return 'kitchen.status';
    }

    public function broadcastWith(): array
    {
        return [
            'order_id' => $this->order->id,
            'order_number' => $this->order->order_number,
            'order_status' => $this->order->status->value,
            'kitchen_status' => $this->order->kitchen_status->value,
            'item_id' => $this->item?->id,
            'item_status' => $this->status->value,
            'updated_at' => now()->toIso8601String(),
        ];
    }
}
