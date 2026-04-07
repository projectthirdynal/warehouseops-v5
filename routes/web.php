<?php

use App\Http\Controllers\DashboardController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\WaybillController;
use App\Http\Controllers\WaybillImportController;
use App\Http\Controllers\LeadController;
use App\Http\Controllers\AgentController;
use App\Http\Controllers\ScannerController;
use App\Http\Controllers\TicketController;
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\SmsController;
use App\Http\Controllers\LeadPoolController;
use App\Http\Controllers\LeadImportController;
use App\Domain\Courier\Http\Controllers\CourierProviderController;
use App\Http\Controllers\AgentLeadController;
use App\Http\Controllers\FinanceController;
use App\Http\Controllers\OrderController;
use App\Http\Controllers\ProductController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
*/

// Authentication routes (public)
Route::middleware('guest')->group(function () {
    Route::get('/login', [AuthController::class, 'showLoginForm'])->name('login');
    Route::post('/login', [AuthController::class, 'login']);
});

Route::post('/logout', [AuthController::class, 'logout'])->name('logout')->middleware('auth');

// Agent Self-Service Portal (all authenticated users can access their own portal)
Route::middleware(['auth'])->group(function () {
    Route::prefix('agent')->name('agent.')->group(function () {
        Route::get('/leads', [AgentLeadController::class, 'portal'])->name('leads');
        Route::post('/leads/request', [AgentLeadController::class, 'requestLeads'])->name('leads.request');
    });

    // Agent API (AJAX calls from portal)
    Route::prefix('api/agent')->name('api.agent.')->group(function () {
        Route::post('/leads/request', [AgentLeadController::class, 'requestLeads'])->name('leads.request');
        Route::post('/leads/{lead}/call', [AgentLeadController::class, 'call'])->name('leads.call');
        Route::post('/leads/{lead}/outcome', [AgentLeadController::class, 'outcome'])->name('leads.outcome');
        Route::get('/leads/{lead}/customer-history', [AgentLeadController::class, 'customerHistory'])->name('leads.customer-history');
    });
});

// Admin / Supervisor routes — agents are redirected to their portal on login
Route::middleware(['auth', 'role:supervisor,admin,superadmin'])->group(function () {
    // Dashboard
    Route::get('/', [DashboardController::class, 'index'])->name('dashboard');

    // Scanner
    Route::prefix('scanner')->name('scanner.')->group(function () {
        Route::get('/', [ScannerController::class, 'index'])->name('index');
    });

    // Waybills
    Route::prefix('waybills')->name('waybills.')->group(function () {
        Route::get('/', [WaybillController::class, 'index'])->name('index');

        Route::get('/import', [WaybillImportController::class, 'index'])->name('import');
        Route::post('/import', [WaybillImportController::class, 'store'])->name('import.store');
        Route::get('/import/template', [WaybillImportController::class, 'template'])->name('import.template');
        Route::get('/import/{upload}', [WaybillImportController::class, 'show'])->name('import.show');
        Route::post('/import/{upload}/retry', [WaybillImportController::class, 'retry'])->name('import.retry');

        Route::get('/{waybill}', [WaybillController::class, 'show'])->name('show');
        Route::patch('/{waybill}/status', [WaybillController::class, 'updateStatus'])->name('update-status');
    });

    // Leads
    Route::prefix('leads')->name('leads.')->group(function () {
        Route::get('/', [LeadController::class, 'index'])->name('index');
        Route::get('/{lead}', [LeadController::class, 'show'])->name('show');
    });

    // Orders
    Route::prefix('orders')->name('orders.')->group(function () {
        Route::get('/', [OrderController::class, 'index'])->name('index');
        Route::get('/{order}', [OrderController::class, 'show'])->name('show');
        Route::post('/{order}/approve', [OrderController::class, 'approve'])->name('approve');
        Route::post('/{order}/reject', [OrderController::class, 'reject'])->name('reject');
        Route::post('/{order}/cancel', [OrderController::class, 'cancel'])->name('cancel');
        Route::post('/{order}/retry-courier', [OrderController::class, 'retryCourier'])->name('retry-courier');
    });

    // QC
    Route::prefix('qc')->name('qc.')->group(function () {
        Route::get('/', [LeadController::class, 'qcIndex'])->name('index');
    });

    // Recycling Pool
    Route::prefix('recycling')->name('recycling.')->group(function () {
        Route::get('/pool', [LeadController::class, 'recyclingPool'])->name('pool');
    });

    // Monitoring
    Route::prefix('monitoring')->name('monitoring.')->group(function () {
        Route::get('/dashboard', [AgentController::class, 'monitoring'])->name('dashboard');
    });

    // Agents
    Route::prefix('agents')->name('agents.')->group(function () {
        Route::get('/governance', [AgentController::class, 'index'])->name('governance');
        Route::post('/', [AgentController::class, 'store'])->name('store');
        Route::patch('/{user}/profile', [AgentController::class, 'updateProfile'])->name('update-profile');
        Route::patch('/{user}/toggle-active', [AgentController::class, 'toggleActive'])->name('toggle-active');
        Route::patch('/{user}', [AgentController::class, 'update'])->name('update');
    });

    // Products & Inventory
    Route::prefix('products')->name('products.')->group(function () {
        Route::get('/', [ProductController::class, 'index'])->name('index');
        Route::get('/create', [ProductController::class, 'create'])->name('create');
        Route::post('/', [ProductController::class, 'store'])->name('store');
        Route::get('/{product}', [ProductController::class, 'show'])->name('show');
        Route::get('/{product}/edit', [ProductController::class, 'edit'])->name('edit');
        Route::put('/{product}', [ProductController::class, 'update'])->name('update');
        Route::delete('/{product}', [ProductController::class, 'destroy'])->name('destroy');
        Route::post('/{product}/stock', [ProductController::class, 'adjustStock'])->name('stock.adjust');
    });

    // Tickets
    Route::prefix('tickets')->name('tickets.')->group(function () {
        Route::get('/', [TicketController::class, 'index'])->name('index');
    });

    // Settings
    Route::prefix('settings')->name('settings.')->group(function () {
        Route::get('/', [SettingsController::class, 'index'])->name('index');
        Route::patch('/profile', [SettingsController::class, 'updateProfile'])->name('profile.update');
        Route::patch('/appearance', [SettingsController::class, 'updateAppearance'])->name('appearance.update');
        Route::patch('/password', [SettingsController::class, 'updatePassword'])->name('password.update');
    });

    // SMS
    Route::prefix('sms')->name('sms.')->group(function () {
        Route::get('/', [SmsController::class, 'index'])->name('index');
        Route::get('/create', [SmsController::class, 'create'])->name('create');
        Route::post('/', [SmsController::class, 'store'])->name('store');
        Route::get('/campaigns/{campaign}', [SmsController::class, 'show'])->name('show');
        Route::post('/campaigns/{campaign}/send', [SmsController::class, 'send'])->name('send');
        Route::post('/preview', [SmsController::class, 'preview'])->name('preview');
        Route::post('/quick-send', [SmsController::class, 'quickSend'])->name('quick-send');

        Route::get('/sequences', [SmsController::class, 'sequences'])->name('sequences');
        Route::get('/sequences/create', [SmsController::class, 'createSequence'])->name('sequences.create');
        Route::post('/sequences', [SmsController::class, 'storeSequence'])->name('sequences.store');
        Route::post('/sequences/{sequence}/toggle', [SmsController::class, 'toggleSequence'])->name('sequences.toggle');

        Route::get('/templates', [SmsController::class, 'templates'])->name('templates');
        Route::post('/templates', [SmsController::class, 'storeTemplate'])->name('templates.store');
        Route::delete('/templates/{template}', [SmsController::class, 'destroyTemplate'])->name('templates.destroy');

        Route::get('/logs', [SmsController::class, 'logs'])->name('logs');
    });

    // Finance
    Route::prefix('finance')->name('finance.')->group(function () {
        Route::get('/', [FinanceController::class, 'dashboard'])->name('dashboard');
        Route::get('/commissions', [FinanceController::class, 'commissions'])->name('commissions');
        Route::post('/commissions/approve', [FinanceController::class, 'approveCommissions'])->name('commissions.approve');
        Route::post('/commissions/pay', [FinanceController::class, 'payCommissions'])->name('commissions.pay');
        Route::post('/commissions/rules', [FinanceController::class, 'storeRule'])->name('commissions.rules.store');
        Route::get('/cod', [FinanceController::class, 'codSettlements'])->name('cod');
        Route::post('/cod', [FinanceController::class, 'storeCodSettlement'])->name('cod.store');
        Route::post('/cod/{settlement}/receive', [FinanceController::class, 'receiveCodSettlement'])->name('cod.receive');
    });

    // Courier Management
    Route::prefix('couriers')->name('couriers.')->group(function () {
        Route::get('/', [CourierProviderController::class, 'index'])->name('index');
        Route::patch('/{provider}', [CourierProviderController::class, 'update'])->name('update');
        Route::post('/{provider}/test', [CourierProviderController::class, 'testConnection'])->name('test');
        Route::post('/{provider}/sync', [CourierProviderController::class, 'syncTracking'])->name('sync');
        Route::get('/{provider}/logs', [CourierProviderController::class, 'logs'])->name('logs');
        Route::post('/create-order', [CourierProviderController::class, 'createOrder'])->name('create-order');
    });

    // Lead Pool
    Route::prefix('lead-pool')->name('lead-pool.')->group(function () {
        Route::get('/', [LeadPoolController::class, 'index'])->name('index');
        Route::post('/distribute', [LeadPoolController::class, 'distribute'])->name('distribute');
        Route::get('/agents', [LeadPoolController::class, 'agentPerformance'])->name('agents');
        Route::get('/import', [LeadImportController::class, 'create'])->name('import');
        Route::post('/import', [LeadImportController::class, 'store'])->name('import.store');
    });
});
