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
use App\Http\Controllers\ClaimController;
use App\Http\Controllers\ReturnReceiptController;
use App\Http\Controllers\WaybillExportController;
use App\Http\Controllers\UnknownWaybillController;
use App\Http\Controllers\FinanceController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\OrderController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\SupplierController;
use App\Http\Controllers\PurchaseRequestController;
use App\Http\Controllers\PurchaseOrderController;
use App\Http\Controllers\ReceivingReportController;
use App\Http\Controllers\WarehouseController;
use App\Http\Controllers\InventoryDashboardController;
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
    Route::get('/register', [AuthController::class, 'showRegisterForm'])->name('register');
    Route::post('/register', [AuthController::class, 'register']);
});

Route::post('/logout', [AuthController::class, 'logout'])->name('logout')->middleware('auth');

// Agent Self-Service Portal (all authenticated users can access their own portal)
Route::middleware(['auth'])->group(function () {
    Route::prefix('agent')->name('agent.')->group(function () {
        Route::get('/leads', [AgentLeadController::class, 'portal'])->name('leads');
        Route::post('/leads/request', [AgentLeadController::class, 'requestLeads'])->name('leads.request');
        Route::get('/tracking', [AgentLeadController::class, 'tracking'])->name('tracking');
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

        // Scanner (sub-tab under Waybills)
        Route::get('/scanner', [ScannerController::class, 'index'])->name('scanner');

        // Scan API
        Route::post('/scan', [ScannerController::class, 'scan'])->name('scan');
        Route::post('/scan/batch', [ScannerController::class, 'batchScan'])->name('scan.batch');

        // Unknown waybills
        Route::get('/unknown', [UnknownWaybillController::class, 'index'])->name('unknown.index');
        Route::get('/unknown/suggest', [UnknownWaybillController::class, 'suggest'])->name('unknown.suggest');
        Route::post('/unknown/{unknown}/match', [UnknownWaybillController::class, 'match'])->name('unknown.match');
        Route::post('/unknown/{unknown}/dismiss', [UnknownWaybillController::class, 'dismiss'])->name('unknown.dismiss');

        // Exports
        Route::get('/claims/export', [WaybillExportController::class, 'claims'])->name('claims.export');
        Route::get('/beyond-sla/export', [WaybillExportController::class, 'beyondSla'])->name('beyond-sla.export');

        Route::get('/import', [WaybillImportController::class, 'index'])->name('import');
        Route::post('/import', [WaybillImportController::class, 'store'])->name('import.store');
        Route::get('/import/template', [WaybillImportController::class, 'template'])->name('import.template');
        Route::get('/import/{upload}', [WaybillImportController::class, 'show'])->name('import.show');
        Route::post('/import/{upload}/retry', [WaybillImportController::class, 'retry'])->name('import.retry');
        Route::post('/import/{upload}/cancel', [WaybillImportController::class, 'cancel'])->name('import.cancel');

        // Claims
        Route::prefix('claims')->name('claims.')->group(function () {
            Route::get('/', [ClaimController::class, 'index'])->name('index');
            Route::get('/approved', [ClaimController::class, 'approved'])->name('approved');
            Route::get('/beyond-sla', [ClaimController::class, 'beyondSla'])->name('beyond-sla');
            Route::get('/create', [ClaimController::class, 'create'])->name('create');
            Route::post('/', [ClaimController::class, 'store'])->name('store');
            Route::get('/{claim}', [ClaimController::class, 'show'])->name('show');
            Route::post('/{claim}/file', [ClaimController::class, 'file'])->name('file');
            Route::post('/{claim}/approve', [ClaimController::class, 'approve'])->name('approve');
            Route::post('/{claim}/reject', [ClaimController::class, 'reject'])->name('reject');
            Route::post('/{claim}/settle', [ClaimController::class, 'settle'])->name('settle');
        });

        // Return receipts (batch scan for Beyond SLA)
        Route::post('/returns/scan', [ReturnReceiptController::class, 'store'])->name('returns.scan');

        // Waybill search API (used by Claims create form)
        Route::get('/search', [WaybillController::class, 'search'])->name('search');

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
        Route::patch('/{user}/profile', [AgentController::class, 'updateProfile'])->name('update-profile')->whereNumber('user');
        Route::patch('/{user}/toggle-active', [AgentController::class, 'toggleActive'])->name('toggle-active')->whereNumber('user');
        Route::patch('/{user}', [AgentController::class, 'update'])->name('update')->whereNumber('user');
        Route::post('/{user}/delete', [AgentController::class, 'destroy'])->name('destroy')->whereNumber('user');
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

    // Inventory dashboard + movements
    Route::prefix('inventory')->name('inventory.')->group(function () {
        Route::get('/',          [InventoryDashboardController::class, 'index'])->name('dashboard');
        Route::get('/movements', [InventoryDashboardController::class, 'movements'])->name('movements');
    });

    // Warehouses + locations
    Route::prefix('warehouses')->name('warehouses.')->group(function () {
        Route::get('/',                                  [WarehouseController::class, 'index'])->name('index');
        Route::post('/',                                 [WarehouseController::class, 'store'])->name('store');
        Route::post('/{warehouse}/locations',            [WarehouseController::class, 'storeLocation'])->name('locations.store');
        Route::delete('/locations/{location}',           [WarehouseController::class, 'destroyLocation'])->name('locations.destroy');
    });

    // Procurement: suppliers, PR, PO, GRN
    Route::prefix('procurement')->name('procurement.')->group(function () {
        Route::resource('suppliers', SupplierController::class)->except(['create', 'edit', 'show']);

        Route::prefix('requests')->name('requests.')->group(function () {
            Route::get('/',                  [PurchaseRequestController::class, 'index'])->name('index');
            Route::get('/create',            [PurchaseRequestController::class, 'create'])->name('create');
            Route::post('/',                 [PurchaseRequestController::class, 'store'])->name('store');
            Route::get('/{request}',         [PurchaseRequestController::class, 'show'])->name('show');
            Route::post('/{request}/submit', [PurchaseRequestController::class, 'submit'])->name('submit');
            Route::post('/{request}/approve',[PurchaseRequestController::class, 'approve'])->name('approve');
            Route::post('/{request}/reject', [PurchaseRequestController::class, 'reject'])->name('reject');
        });

        Route::prefix('orders')->name('orders.')->group(function () {
            Route::get('/',                [PurchaseOrderController::class, 'index'])->name('index');
            Route::get('/create',          [PurchaseOrderController::class, 'create'])->name('create');
            Route::post('/',               [PurchaseOrderController::class, 'store'])->name('store');
            Route::get('/{order}',         [PurchaseOrderController::class, 'show'])->name('show');
            Route::post('/{order}/send',   [PurchaseOrderController::class, 'send'])->name('send');
            Route::post('/{order}/cancel', [PurchaseOrderController::class, 'cancel'])->name('cancel');
        });

        Route::prefix('receiving')->name('receiving.')->group(function () {
            Route::get('/',                    [ReceivingReportController::class, 'index'])->name('index');
            Route::get('/create',              [ReceivingReportController::class, 'create'])->name('create');
            Route::post('/',                   [ReceivingReportController::class, 'store'])->name('store');
            Route::get('/{receiving}',         [ReceivingReportController::class, 'show'])->name('show');
            Route::post('/{receiving}/confirm',[ReceivingReportController::class, 'confirm'])->name('confirm');
        });
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

    // Reports
    Route::prefix('reports')->name('reports.')->group(function () {
        Route::get('/', [ReportController::class, 'index'])->name('index');
        Route::get('/download', [ReportController::class, 'download'])->name('download');
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
