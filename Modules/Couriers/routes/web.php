<?php

use Illuminate\Support\Facades\Route;
use Modules\Couriers\Http\Controllers\CouriersController;

Route::middleware(['auth', 'verified'])->group(function () {
    Route::resource('couriers', CouriersController::class)->names('couriers');
});
