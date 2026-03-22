<?php

use App\Http\Controllers\AgentLeadController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "api" middleware group. Make something great!
|
*/

Route::middleware('auth:sanctum')->get('/user', function (Request $request) {
    return $request->user();
});

Route::middleware(['auth:sanctum'])->group(function () {
    // Agent routes
    Route::prefix('agent')->group(function () {
        Route::get('leads', [AgentLeadController::class, 'index']);
        Route::get('leads/callbacks', [AgentLeadController::class, 'callbacks']);
        Route::get('leads/{lead}', [AgentLeadController::class, 'show']);
        Route::post('leads/{lead}/call', [AgentLeadController::class, 'call']);
        Route::post('leads/{lead}/outcome', [AgentLeadController::class, 'outcome']);
    });
});
