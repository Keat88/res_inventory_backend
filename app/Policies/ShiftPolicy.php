<?php

namespace App\Policies;

use App\Models\CashierShift;
use App\Models\User;

class ShiftPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('shifts.view');
    }

    public function view(User $user, CashierShift $shift): bool
    {
        return $user->can('shifts.view')
            && $user->canAccessBranch($shift->branch_id)
            && ($user->can('shifts.manage') || $shift->cashier_id === $user->id);
    }

    public function open(User $user): bool
    {
        return $user->can('shifts.open');
    }

    public function close(User $user, CashierShift $shift): bool
    {
        return $user->canAccessBranch($shift->branch_id)
            && ($user->can('shifts.manage') || $shift->cashier_id === $user->id);
    }
}
