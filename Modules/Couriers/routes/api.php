<?php

use Illuminate\Support\Facades\Route;
use Modules\Couriers\Http\Controllers\CouriersController;

Route::middleware(['auth:sanctum'])->prefix('v1')->group(function () {
    Route::apiResource('couriers', CouriersController::class)->names('couriers');
});
