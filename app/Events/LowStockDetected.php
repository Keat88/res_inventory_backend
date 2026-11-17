<?php

namespace App\Events;

use App\Models\BranchInventory;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class LowStockDetected implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public BranchInventory $inventory) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel('branch.'.$this->inventory->branch_id.'.inventory')];
    }

    public function broadcastAs(): string
    {
        return 'inventory.low';
    }

    public function broadcastWith(): array
    {
        return [
            'branch_id' => $this->inventory->branch_id,
            'ingredient_id' => $this->inventory->ingredient_id,
            'ingredient' => $this->inventory->ingredient?->name,
            'quantity' => (float) $this->inventory->quantity,
            'min_stock' => (float) $this->inventory->min_stock,
        ];
    }
}
