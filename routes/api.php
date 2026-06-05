<?php

use App\Domain\Courier\Http\Controllers\CourierWebhookController;
use App\Domain\Shop\Http\Controllers\MetaWebhookController;
use App\Http\Controllers\AgentLeadController;
use App\Http\Controllers\DesktopApiController;
use App\Http\Controllers\WaybillStreamingImportController;
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

    // Streaming waybill import routes
    Route::prefix('waybills/streaming')->group(function () {
        Route::post('initiate', [WaybillStreamingImportController::class, 'initiate']);
        Route::post('{upload}/chunk', [WaybillStreamingImportController::class, 'uploadChunk']);
        Route::get('{upload}/progress', [WaybillStreamingImportController::class, 'progress']);
        Route::post('{upload}/cancel', [WaybillStreamingImportController::class, 'cancel']);
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

    // SMS
    Route::get('sms', [DesktopApiController::class, 'smsIndex']);
    Route::post('sms/preview', [DesktopApiController::class, 'smsPreview']);
    Route::post('sms/campaigns', [DesktopApiController::class, 'smsSendCampaign']);
    Route::post('sms/quick-send', [DesktopApiController::class, 'smsQuickSend']);
    Route::post('sms/templates', [DesktopApiController::class, 'smsCreateTemplate']);
    Route::delete('sms/templates/{template}', [DesktopApiController::class, 'smsDeleteTemplate']);

    // Settings
    Route::patch('settings/profile', [DesktopApiController::class, 'updateProfile']);
    Route::patch('settings/password', [DesktopApiController::class, 'updatePassword']);
    Route::patch('settings/appearance', [DesktopApiController::class, 'updateAppearance']);

    // User Management
    Route::get('users', [DesktopApiController::class, 'usersList']);
    Route::post('users', [DesktopApiController::class, 'usersStore']);
    Route::patch('users/{targetUser}', [DesktopApiController::class, 'usersUpdate']);
    Route::patch('users/{targetUser}/toggle-active', [DesktopApiController::class, 'usersToggleActive']);
});

/*
|--------------------------------------------------------------------------
| Courier Webhook Routes (public, signature-verified)
|--------------------------------------------------------------------------
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

/*
|--------------------------------------------------------------------------
| Meta Webhook Routes (public, signature-recorded)
|--------------------------------------------------------------------------
*/
Route::prefix('webhooks/meta')->name('webhook.meta.')->group(function () {
    Route::get('/', [MetaWebhookController::class, 'verify'])->name('verify');
    Route::post('/', [MetaWebhookController::class, 'receive'])->name('receive');
});
