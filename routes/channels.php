<?php

use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('branch.{branchId}.kitchen', function (User $user, int $branchId) {
    return $user->canAccessBranch($branchId);
});

Broadcast::channel('branch.{branchId}.pos', function (User $user, int $branchId) {
    return $user->canAccessBranch($branchId);
});

Broadcast::channel('branch.{branchId}.inventory', function (User $user, int $branchId) {
    return $user->canAccessBranch($branchId);
});

Broadcast::channel('user.{userId}', function (User $user, int $userId) {
    return (int) $user->id === $userId;
});
