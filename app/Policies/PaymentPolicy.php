<?php

namespace App\Policies;

use App\Models\Payment;
use App\Models\User;

class PaymentPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('payments.view');
    }

    public function view(User $user, Payment $payment): bool
    {
        return $user->can('payments.view')
            && $user->canAccessBranch($payment->order->branch_id);
    }

    public function create(User $user): bool
    {
        return $user->can('payments.create');
    }

    public function confirm(User $user, Payment $payment): bool
    {
        return $user->can('payments.confirm')
            && $user->canAccessBranch($payment->order->branch_id);
    }

    public function refund(User $user, Payment $payment): bool
    {
        return $user->can('payments.refund')
            && $user->canAccessBranch($payment->order->branch_id);
    }
}
