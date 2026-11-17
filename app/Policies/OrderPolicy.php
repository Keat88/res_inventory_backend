<?php

namespace App\Policies;

use App\Models\Order;
use App\Models\User;

class OrderPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('orders.view');
    }

    public function view(User $user, Order $order): bool
    {
        return $user->can('orders.view') && $user->canAccessBranch($order->branch_id);
    }

    public function create(User $user): bool
    {
        return $user->can('orders.create');
    }

    public function update(User $user, Order $order): bool
    {
        return $user->can('orders.update')
            && $user->canAccessBranch($order->branch_id)
            && $order->status->value !== 'COMPLETED'
            && $order->status->value !== 'CANCELLED';
    }

    public function cancel(User $user, Order $order): bool
    {
        return $user->can('orders.cancel') && $user->canAccessBranch($order->branch_id);
    }

    public function refund(User $user, Order $order): bool
    {
        return $user->can('payments.refund') && $user->canAccessBranch($order->branch_id);
    }
}
