<?php

namespace App\Providers;

use App\Domain\Courier\Services\CourierServiceManager;
use App\Domain\Courier\Services\StatusMapper;
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
        $this->app->singleton(CourierServiceManager::class);
        $this->app->singleton(StatusMapper::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Waybill::observe(WaybillObserver::class);
    }
}
