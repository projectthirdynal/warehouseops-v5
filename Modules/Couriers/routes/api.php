<?php

use Modules\Couriers\Http\Controllers\CourierWebhookController;

/*
|--------------------------------------------------------------------------
| Courier Webhook Routes (public, signature-verified)
|--------------------------------------------------------------------------
|
| These routes are loaded by the Couriers module's RouteServiceProvider.
| They are public endpoints verified by the courier.webhook middleware.
|
*/

Route::prefix('webhooks/courier')->group(function () {
    Route::post('/flash', [CourierWebhookController::class, 'handle'])
        ->defaults('courier', 'FLASH')
        ->middleware('courier.webhook:FLASH')
        ->name('webhook.courier.flash');

    Route::post('/jnt', [CourierWebhookController::class, 'handle'])
        ->defaults('courier', 'JNT')
        ->middleware('courier.webhook:JNT')
        ->name('webhook.courier.jnt');
});
