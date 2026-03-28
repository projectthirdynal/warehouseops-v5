<?php

use App\Http\Controllers\AgentLeadController;
use App\Http\Controllers\DesktopApiController;
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

/*
|--------------------------------------------------------------------------
| Desktop App API Routes
|--------------------------------------------------------------------------
|
| API endpoints for the WarehouseOps Desktop application.
| Uses Sanctum token-based authentication (Bearer tokens).
|
*/

// Public (no auth)
Route::prefix('desktop')->group(function () {
    Route::get('ping', [DesktopApiController::class, 'ping']);
    Route::post('login', [DesktopApiController::class, 'login']);
});

// Protected (requires Sanctum token)
Route::prefix('desktop')->middleware('auth:sanctum')->group(function () {
    Route::post('logout', [DesktopApiController::class, 'logout']);
    Route::get('user', [DesktopApiController::class, 'user']);
    Route::get('dashboard', [DesktopApiController::class, 'dashboard']);

    // Scanner
    Route::post('scanner/validate', [DesktopApiController::class, 'scannerValidate']);
    Route::post('scanner/dispatch', [DesktopApiController::class, 'scannerDispatch']);

    // Imports
    Route::get('imports', [DesktopApiController::class, 'imports']);
    Route::post('imports', [DesktopApiController::class, 'importStore']);
    Route::get('imports/{upload}', [DesktopApiController::class, 'importShow']);
    Route::post('imports/{upload}/retry', [DesktopApiController::class, 'importRetry']);

    // Monitoring
    Route::get('monitoring', [DesktopApiController::class, 'monitoring']);
});
