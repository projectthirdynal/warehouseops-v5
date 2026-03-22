<?php

namespace App\Providers;

use App\Domain\Waybill\Models\Waybill;
use App\Observers\WaybillObserver;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Waybill::observe(WaybillObserver::class);
    }
}
