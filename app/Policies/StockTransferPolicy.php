<?php

namespace App\Policies;

use App\Models\StockTransfer;
use App\Models\User;

class StockTransferPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('stock_transfers.view');
    }

    public function view(User $user, StockTransfer $transfer): bool
    {
        return $user->can('stock_transfers.view')
            && ($user->canAccessBranch($transfer->from_branch_id) || $user->canAccessBranch($transfer->to_branch_id));
    }

    public function create(User $user): bool
    {
        return $user->can('stock_transfers.manage');
    }

    public function update(User $user, StockTransfer $transfer): bool
    {
        return $user->can('stock_transfers.manage') && $user->canAccessBranch($transfer->from_branch_id);
    }

    public function dispatch(User $user, StockTransfer $transfer): bool
    {
        return $user->can('stock_transfers.dispatch') && $user->canAccessBranch($transfer->from_branch_id);
    }

    public function receive(User $user, StockTransfer $transfer): bool
    {
        return $user->can('stock_transfers.receive') && $user->canAccessBranch($transfer->to_branch_id);
    }
}
