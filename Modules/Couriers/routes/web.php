<?php

use Modules\Couriers\Http\Controllers\CourierProviderController;

/*
|--------------------------------------------------------------------------
| Courier Management Routes
|--------------------------------------------------------------------------
|
| These routes are loaded by the Couriers module's RouteServiceProvider.
| All routes require authentication + supervisor/admin/superadmin role.
|
*/

Route::middleware(['auth', 'role:supervisor,admin,superadmin'])->prefix('couriers')->name('couriers.')->group(function () {
    Route::get('/', [CourierProviderController::class, 'index'])->name('index');
    Route::patch('/{provider}', [CourierProviderController::class, 'update'])->name('update');
    Route::post('/{provider}/test', [CourierProviderController::class, 'testConnection'])->name('test');
    Route::post('/{provider}/sync', [CourierProviderController::class, 'syncTracking'])->name('sync');
    Route::get('/{provider}/logs', [CourierProviderController::class, 'logs'])->name('logs');
    Route::post('/create-order', [CourierProviderController::class, 'createOrder'])->name('create-order');
    Route::get('/compare-rates', [CourierProviderController::class, 'compareRates'])->name('compare-rates');
    Route::post('/compare-rates', [CourierProviderController::class, 'apiCompareRates'])->name('compare-rates.api');
    Route::get('/rate-management', [CourierProviderController::class, 'rateManagement'])->name('rate-management');
    Route::post('/rates', [CourierProviderController::class, 'storeRate'])->name('rates.store');
    Route::patch('/rates/{rate}', [CourierProviderController::class, 'updateRate'])->name('rates.update');
    Route::delete('/rates/{rate}', [CourierProviderController::class, 'destroyRate'])->name('rates.destroy');
});
