<?php

namespace App\Providers;

use App\Models\Branch;
use App\Models\CashierShift;
use App\Models\Discount;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PurchaseOrder;
use App\Models\StockTransfer;
use App\Models\User;
use App\Policies\BranchPolicy;
use App\Policies\DiscountPolicy;
use App\Policies\OrderPolicy;
use App\Policies\PaymentPolicy;
use App\Policies\PurchaseOrderPolicy;
use App\Policies\ShiftPolicy;
use App\Policies\StockTransferPolicy;
use Illuminate\Foundation\Support\Providers\AuthServiceProvider as ServiceProvider;
use Illuminate\Support\Facades\Gate;

class AuthServiceProvider extends ServiceProvider
{
    protected $policies = [
        Branch::class => BranchPolicy::class,
        Order::class => OrderPolicy::class,
        Payment::class => PaymentPolicy::class,
        PurchaseOrder::class => PurchaseOrderPolicy::class,
        StockTransfer::class => StockTransferPolicy::class,
        CashierShift::class => ShiftPolicy::class,
        Discount::class => DiscountPolicy::class,
    ];

    public function boot(): void
    {
        $this->registerPolicies();

        Gate::before(function (User $user) {
            return $user->hasRole('ADMIN') ? true : null;
        });
    }
}
