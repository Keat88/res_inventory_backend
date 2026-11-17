<?php

namespace App\Policies;

use App\Models\PurchaseOrder;
use App\Models\User;

class PurchaseOrderPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('purchase_orders.view');
    }

    public function view(User $user, PurchaseOrder $order): bool
    {
        return $user->can('purchase_orders.view') && $user->canAccessBranch($order->branch_id);
    }

    public function create(User $user): bool
    {
        return $user->can('purchase_orders.manage');
    }

    public function update(User $user, PurchaseOrder $order): bool
    {
        return $user->can('purchase_orders.manage') && $user->canAccessBranch($order->branch_id);
    }

    public function approve(User $user, PurchaseOrder $order): bool
    {
        return $user->can('purchase_orders.approve') && $user->canAccessBranch($order->branch_id);
    }

    public function receive(User $user, PurchaseOrder $order): bool
    {
        return $user->can('purchase_orders.receive') && $user->canAccessBranch($order->branch_id);
    }
}
