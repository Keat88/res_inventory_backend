<?php

namespace App\Providers;

use App\Support\BranchContext;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(BranchContext::class);
    }

    public function boot(): void
    {
        //
    }
}
