<?php

use App\Http\Controllers\ApprovalsController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\Crm\ThirdPartyController;
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
use App\Http\Controllers\DistributionController;
use App\Http\Controllers\DistributionAnalyticsController;
use App\Http\Controllers\TelesalesLeadImportController;
use App\Domain\Courier\Http\Controllers\CourierProviderController;
use App\Http\Controllers\AgentLeadController;
use App\Http\Controllers\ClaimController;
use App\Http\Controllers\ReturnReceiptController;
use App\Http\Controllers\WaybillExportController;
use App\Http\Controllers\UnknownWaybillController;
use App\Http\Controllers\FinanceController;
use App\Http\Controllers\Finance\InvoiceController;
use App\Http\Controllers\Finance\SupplierInvoiceController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\OrderController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\SupplierController;
use App\Http\Controllers\PurchaseRequestController;
use App\Http\Controllers\PurchaseOrderController;
use App\Http\Controllers\ReceivingReportController;
use App\Http\Controllers\WarehouseController;
use App\Http\Controllers\InventoryDashboardController;
use App\Http\Controllers\SupplyController;
use App\Http\Controllers\SalesTrackingController;
use App\Http\Controllers\QuickBooksController;
use App\Http\Controllers\CostOfGoodsController;
use App\Http\Controllers\MetaComplianceController;
use App\Http\Controllers\ShopController;
use App\Http\Controllers\StockAdjustmentController;
use App\Http\Controllers\CapexAssetController;
use App\Http\Controllers\DeadStockController;
use App\Http\Controllers\EmailVerificationController;
use App\Http\Controllers\ForgotPasswordController;
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

Route::get('/meta/privacy', [MetaComplianceController::class, 'privacy'])->name('meta.privacy');
Route::get('/meta/terms', [MetaComplianceController::class, 'terms'])->name('meta.terms');
Route::get('/meta/data-deletion', [MetaComplianceController::class, 'dataDeletionInfo'])->name('meta.data-deletion.info');
Route::post('/meta/data-deletion', [MetaComplianceController::class, 'handleDataDeletion'])->name('meta.data-deletion.handle');
Route::get('/meta/data-deletion/status/{confirmationCode}', [MetaComplianceController::class, 'dataDeletionStatus'])->name('meta.data-deletion.status');

Route::post('/logout', [AuthController::class, 'logout'])->name('logout')->middleware('auth');

// Password Reset Routes
Route::get('/forgot-password', [ForgotPasswordController::class, 'showLinkRequestForm'])->name('password.request');
Route::post('/forgot-password', [ForgotPasswordController::class, 'sendResetLinkEmail'])->name('password.email');
Route::get('/reset-password/{token}', [ForgotPasswordController::class, 'showResetForm'])->name('password.reset');
Route::post('/reset-password', [ForgotPasswordController::class, 'reset'])->name('password.update');

// Email Verification Routes
Route::middleware('auth')->group(function () {
    Route::get('/email/verify', [EmailVerificationController::class, 'notice'])->name('verification.notice');
    Route::get('/email/verify/{id}/{hash}', [EmailVerificationController::class, 'verify'])->middleware(['signed', 'throttle:6,1'])->name('verification.verify');
    Route::post('/email/verification-notification', [EmailVerificationController::class, 'resend'])->middleware('throttle:6,1')->name('verification.send');
});

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
        Route::get('/leads/unread-count', [AgentLeadController::class, 'unreadCount'])->name('leads.unread-count');
    });
});

// ─────────────────────────────────────────────────────────────────────────────
// ROLE CONSTANTS (for reference)
//   superadmin  – IT Administrator  – full system access
//   admin       – Manager/Admin     – full access except system config
//   supervisor  – Operations Supervisor – ops + inventory + procurement
//   finance     – Finance Officer   – finance, reports, inventory (full: dashboard, movements, supplies, adjustments)
//   accounting  – Accountant        – finance, QuickBooks, claims, reports
//   warehouse   – Warehouse Staff   – inventory + procurement + products
//   agent       – Sales Agent       – agent portal only (separate group below)
// ─────────────────────────────────────────────────────────────────────────────

// ── SHARED: all staff roles can access dashboard, settings, tickets ──────────
Route::middleware(['auth', 'role:superadmin,admin,supervisor,finance,accounting,warehouse'])->group(function () {
    Route::get('/', [DashboardController::class, 'index'])->name('dashboard');

    Route::prefix('tickets')->name('tickets.')->group(function () {
        Route::get('/', [TicketController::class, 'index'])->name('index');
    });

    Route::prefix('settings')->name('settings.')->group(function () {
        Route::get('/', [SettingsController::class, 'index'])->name('index');
        Route::patch('/profile', [SettingsController::class, 'updateProfile'])->name('profile.update');
        Route::patch('/appearance', [SettingsController::class, 'updateAppearance'])->name('appearance.update');
        Route::patch('/password', [SettingsController::class, 'updatePassword'])->name('password.update');
        Route::patch('/system', [SettingsController::class, 'updateSystemSettings'])->name('system.update');
        Route::patch('/email', [SettingsController::class, 'updateEmailSettings'])->name('email.update');
        Route::post('/email/test', [SettingsController::class, 'testEmail'])->name('email.test');
        Route::patch('/printer', [SettingsController::class, 'updateLabelPrinter'])->name('printer.update');
        Route::patch('/scanner', [SettingsController::class, 'updateScannerSettings'])->name('scanner.update');
    });
});

// ── INVENTORY READ-ONLY: finance/accounting can audit stock without managing it
Route::middleware(['auth', 'role:superadmin,admin,supervisor,warehouse,finance,accounting'])->group(function () {
    Route::get('/inventory', [InventoryDashboardController::class, 'index'])->name('inventory.dashboard');
    Route::get('/inventory/movements', [InventoryDashboardController::class, 'movements'])->name('inventory.movements');
    Route::get('/inventory/non-moving', [InventoryDashboardController::class, 'nonMoving'])->name('inventory.non-moving');
});

// ── INVENTORY MATERIALS + ADJUSTMENTS: accounting + finance can participate in controls
Route::middleware(['auth', 'role:superadmin,admin,supervisor,warehouse,accounting,finance'])->group(function () {
    Route::prefix('inventory')->name('inventory.')->group(function () {
        Route::get('/supplies',                  [SupplyController::class, 'index'])->name('supplies.index');
        Route::post('/supplies',                 [SupplyController::class, 'store'])->name('supplies.store');
        Route::get('/supplies/search',           [SupplyController::class, 'search'])->name('supplies.search');
        Route::get('/supplies/export',           [SupplyController::class, 'export'])->name('supplies.export');
        Route::get('/supplies/trash',            [SupplyController::class, 'trash'])->name('supplies.trash');
        Route::put('/supplies/{supply}',         [SupplyController::class, 'update'])->name('supplies.update');
        Route::delete('/supplies/{supply}',      [SupplyController::class, 'destroy'])->name('supplies.destroy');
        Route::post('/supplies/{supply}/stock',  [SupplyController::class, 'adjustStock'])->name('supplies.stock.adjust');
        Route::patch('/supplies/{supply}/status',[SupplyController::class, 'updateStatus'])->name('supplies.status.update');
        Route::get('/supplies/{supply}/summary', [SupplyController::class, 'summary'])->name('supplies.summary');
        Route::post('/supplies/{id}/restore',    [SupplyController::class, 'restore'])->name('supplies.restore');

        Route::prefix('assets')->name('assets.')->group(function () {
            Route::get('/',                             [CapexAssetController::class, 'index'])->name('index');
            Route::get('/create',                       [CapexAssetController::class, 'create'])->name('create');
            Route::post('/',                            [CapexAssetController::class, 'store'])->name('store');
            Route::get('/{asset}',                      [CapexAssetController::class, 'show'])->name('show');
            Route::get('/{asset}/edit',                 [CapexAssetController::class, 'edit'])->name('edit');
            Route::put('/{asset}',                      [CapexAssetController::class, 'update'])->name('update');
            Route::post('/{asset}/assign',              [CapexAssetController::class, 'assign'])->name('assign');
            Route::post('/depreciation/{schedule}/post',[CapexAssetController::class, 'postDepreciation'])->name('depreciation.post');
            Route::post('/{asset}/dispose',             [CapexAssetController::class, 'dispose'])->name('dispose');
        });

        Route::prefix('adjustments')->name('adjustments.')->group(function () {
            Route::get('/',              [StockAdjustmentController::class, 'index'])->name('index');
            Route::get('/report',        [StockAdjustmentController::class, 'report'])->name('report');
            Route::get('/report/download', [StockAdjustmentController::class, 'downloadReport'])->name('report.download');
            Route::post('/',             [StockAdjustmentController::class, 'store'])->name('store');
            Route::post('/{id}/approve', [StockAdjustmentController::class, 'approve'])->name('approve');
            Route::post('/{id}/reject',  [StockAdjustmentController::class, 'reject'])->name('reject');
        });

        Route::get('/dead-stock',  [DeadStockController::class, 'index'])->name('dead-stock.index');
        Route::post('/dead-stock', [DeadStockController::class, 'store'])->name('dead-stock.store');
    });
});

// ── INVENTORY + PROCUREMENT: warehouse staff, supervisors, admins ─────────────
Route::middleware(['auth', 'role:superadmin,admin,supervisor,warehouse'])->group(function () {
    // Products
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

    // Warehouses + locations
    Route::prefix('warehouses')->name('warehouses.')->group(function () {
        Route::get('/',                       [WarehouseController::class, 'index'])->name('index');
        Route::post('/',                      [WarehouseController::class, 'store'])->name('store');
        Route::put('/{warehouse}',            [WarehouseController::class, 'update'])->name('update');
        Route::post('/{warehouse}/toggle',    [WarehouseController::class, 'toggleActive'])->name('toggle');
        Route::post('/{warehouse}/locations', [WarehouseController::class, 'storeLocation'])->name('locations.store');
        Route::put('/locations/{location}',   [WarehouseController::class, 'updateLocation'])->name('locations.update');
        Route::delete('/locations/{location}',[WarehouseController::class, 'destroyLocation'])->name('locations.destroy');
    });

    // Procurement: suppliers, PR, PO, GRN
    Route::prefix('procurement')->name('procurement.')->group(function () {
        Route::resource('suppliers', SupplierController::class)->except(['create', 'edit', 'show']);

        Route::prefix('requests')->name('requests.')->group(function () {
            Route::get('/',                   [PurchaseRequestController::class, 'index'])->name('index');
            Route::get('/create',             [PurchaseRequestController::class, 'create'])->name('create');
            Route::post('/',                  [PurchaseRequestController::class, 'store'])->name('store');
            Route::get('/{request}',          [PurchaseRequestController::class, 'show'])->name('show');
            Route::post('/{request}/submit',  [PurchaseRequestController::class, 'submit'])->name('submit');
            Route::post('/{request}/approve', [PurchaseRequestController::class, 'approve'])->name('approve');
            Route::post('/{request}/reject',  [PurchaseRequestController::class, 'reject'])->name('reject');
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
});

// ── FINANCE + ACCOUNTING: finance officers, accountants, admins ───────────────
Route::middleware(['auth', 'role:superadmin,admin,supervisor,finance,accounting'])->group(function () {
    // Finance dashboard + commissions + COD
    Route::prefix('finance')->name('finance.')->group(function () {
        Route::get('/', [FinanceController::class, 'dashboard'])->name('dashboard');
        Route::get('/commissions', [FinanceController::class, 'commissions'])->name('commissions');
        Route::post('/commissions/approve', [FinanceController::class, 'approveCommissions'])->name('commissions.approve');
        Route::post('/commissions/pay', [FinanceController::class, 'payCommissions'])->name('commissions.pay');
        Route::post('/commissions/rules', [FinanceController::class, 'storeRule'])->name('commissions.rules.store');
        Route::get('/cod', [FinanceController::class, 'codSettlements'])->name('cod');
        Route::post('/cod', [FinanceController::class, 'storeCodSettlement'])->name('cod.store');
        Route::post('/cod/{settlement}/receive', [FinanceController::class, 'receiveCodSettlement'])->name('cod.receive');

        // QuickBooks (accounting + admins only — finance officers view only)
        Route::prefix('quickbooks')->name('quickbooks.')->group(function () {
            Route::get('/',                    [QuickBooksController::class, 'dashboard'])->name('dashboard');
            Route::get('/connect',             [QuickBooksController::class, 'connect'])->name('connect');
            Route::get('/callback',            [QuickBooksController::class, 'callback'])->name('callback');
            Route::post('/disconnect',         [QuickBooksController::class, 'disconnect'])->name('disconnect');
            Route::post('/sync/{queue}/retry', [QuickBooksController::class, 'retry'])->name('sync.retry');
            Route::get('/accounts',            [QuickBooksController::class, 'accounts'])->name('accounts');
            Route::get('/mappings',            [QuickBooksController::class, 'mappings'])->name('mappings.index');
            Route::post('/mappings',           [QuickBooksController::class, 'saveMapping'])->name('mappings.save');
        });

        // Invoices
        Route::prefix('invoices')->name('invoices.')->group(function () {
            Route::get('/',                          [InvoiceController::class, 'index'])->name('index');
            Route::get('/create',                    [InvoiceController::class, 'create'])->name('create');
            Route::post('/',                         [InvoiceController::class, 'store'])->name('store');
            Route::get('/{invoice}',                 [InvoiceController::class, 'show'])->name('show');
            Route::patch('/{invoice}',               [InvoiceController::class, 'update'])->name('update');
            Route::post('/{invoice}/validate',       [InvoiceController::class, 'validateInvoice'])->name('validate');
            Route::post('/{invoice}/send',          [InvoiceController::class, 'send'])->name('send');
            Route::post('/{invoice}/cancel',        [InvoiceController::class, 'cancel'])->name('cancel');

            // Lines
            Route::post('/{invoice}/lines',          [InvoiceController::class, 'storeLine'])->name('lines.store');
            Route::delete('/{invoice}/lines/{line}', [InvoiceController::class, 'destroyLine'])->name('lines.destroy');

            // Payments
            Route::post('/{invoice}/payments',       [InvoiceController::class, 'storePayment'])->name('payments.store');
        });

        // Supplier Invoices
        Route::prefix('supplier-invoices')->name('supplier-invoices.')->group(function () {
            Route::get('/',                                  [SupplierInvoiceController::class, 'index'])->name('index');
            Route::get('/create',                            [SupplierInvoiceController::class, 'create'])->name('create');
            Route::post('/',                                 [SupplierInvoiceController::class, 'store'])->name('store');
            Route::get('/{invoice}',                         [SupplierInvoiceController::class, 'show'])->name('show');
            Route::post('/{invoice}/validate',              [SupplierInvoiceController::class, 'validateInvoice'])->name('validate');
            Route::post('/{invoice}/cancel',                [SupplierInvoiceController::class, 'cancel'])->name('cancel');
        });

        Route::get('/cost-of-goods', [CostOfGoodsController::class, 'index'])->name('cogs');
    });

    // Reports
    Route::prefix('reports')->name('reports.')->group(function () {
        Route::get('/', [ReportController::class, 'index'])->name('index');
        Route::get('/download', [ReportController::class, 'download'])->name('download');
    });
});

// ── OPS / ADMIN: supervisors, admins — sales, leads, shop, waybills ──────────
Route::middleware(['auth', 'role:superadmin,admin,supervisor'])->group(function () {
    // Shop / Facebook POS
    Route::get('/shop', [ShopController::class, 'index'])->name('shop.index');
    Route::get('/shop/metrics', [ShopController::class, 'metrics'])->name('shop.metrics');
    Route::get('/shop/pos', [ShopController::class, 'pos'])->name('shop.pos');
    Route::get('/shop/pos/search', [ShopController::class, 'posSearch'])->name('shop.pos.search');
    Route::post('/shop/pos/checkout', [ShopController::class, 'posCheckout'])->name('shop.pos.checkout');
    Route::get('/shop/inbox', [ShopController::class, 'inbox'])->name('shop.inbox');
    Route::get('/shop/inbox/export-statuses', [ShopController::class, 'exportConversationStatuses'])->name('shop.inbox.export-statuses');
    Route::post('/shop/inbox/page-favorite', [ShopController::class, 'togglePageFavorite'])->name('shop.page-favorite.toggle');
    Route::post('/shop/inbox/status-labels', [ShopController::class, 'storeStatusLabel'])->name('shop.status-labels.store');
    Route::delete('/shop/inbox/status-labels', [ShopController::class, 'destroyStatusLabel'])->name('shop.status-labels.destroy');
    Route::post('/shop/inbox/assignment-rules', [ShopController::class, 'storeAssignmentRule'])->name('shop.assignment-rules.store');
    Route::delete('/shop/inbox/assignment-rules', [ShopController::class, 'destroyAssignmentRule'])->name('shop.assignment-rules.destroy');
    Route::post('/shop/inbox/status-rules', [ShopController::class, 'storeStatusRule'])->name('shop.status-rules.store');
    Route::delete('/shop/inbox/status-rules', [ShopController::class, 'destroyStatusRule'])->name('shop.status-rules.destroy');
    Route::post('/shop/inbox/moderate-comment', [ShopController::class, 'moderateComment'])->name('shop.comment.moderate');
    Route::post('/shop/inbox/page-canned-responses', [ShopController::class, 'storePageCannedResponse'])->name('shop.page-canned-responses.store');
    Route::delete('/shop/inbox/page-canned-responses', [ShopController::class, 'destroyPageCannedResponse'])->name('shop.page-canned-responses.destroy');
    Route::get('/shop/inbox/{conversation}', [ShopController::class, 'conversation'])->name('shop.conversation');
    Route::post('/shop/inbox/{conversation}/read', [ShopController::class, 'markMessagesRead'])->name('shop.conversation.read');
    Route::get('/shop/inbox/{conversation}/poll', [ShopController::class, 'pollMessages'])->name('shop.conversation.poll');
    Route::post('/shop/inbox/{conversation}/reply', [ShopController::class, 'sendReply'])->name('shop.conversation.reply');
    Route::patch('/shop/inbox/{conversation}/assignment', [ShopController::class, 'updateConversationAssignment'])->name('shop.conversation.assignment');
    Route::patch('/shop/inbox/{conversation}/status', [ShopController::class, 'updateConversationStatus'])->name('shop.conversation.status');
    Route::post('/shop/inbox/bulk-status', [ShopController::class, 'bulkUpdateConversationStatus'])->name('shop.conversation.bulk-status');
    Route::post('/shop/inbox/bulk-assign', [ShopController::class, 'bulkAssignConversations'])->name('shop.conversation.bulk-assign');
    Route::post('/shop/inbox/bulk-priority', [ShopController::class, 'bulkUpdateConversationPriority'])->name('shop.conversation.bulk-priority');
    Route::post('/shop/inbox/bulk-tag', [ShopController::class, 'bulkTagConversations'])->name('shop.conversation.bulk-tag');
    Route::patch('/shop/inbox/{conversation}/priority', [ShopController::class, 'updateConversationPriority'])->name('shop.conversation.priority');
    Route::patch('/shop/inbox/{conversation}/tags', [ShopController::class, 'updateConversationTags'])->name('shop.conversation.tags');
    Route::post('/shop/inbox/{conversation}/snooze', [ShopController::class, 'snoozeConversation'])->name('shop.conversation.snooze');
    Route::delete('/shop/inbox/{conversation}/snooze', [ShopController::class, 'unsnoozeConversation'])->name('shop.conversation.unsnooze');
    Route::post('/shop/inbox/{conversation}/reminder', [ShopController::class, 'setConversationReminder'])->name('shop.conversation.reminder');
    Route::delete('/shop/inbox/{conversation}/reminder', [ShopController::class, 'clearConversationReminder'])->name('shop.conversation.reminder.clear');
    Route::post('/shop/inbox/{conversation}/merge', [ShopController::class, 'mergeConversations'])->name('shop.conversation.merge');
    Route::post('/shop/inbox/{conversation}/block', [ShopController::class, 'toggleBlock'])->name('shop.conversation.block');
    Route::post('/shop/inbox/{conversation}/remarks', [ShopController::class, 'storeConversationRemark'])->name('shop.conversation.remarks.store');
    Route::delete('/shop/remarks/{remark}', [ShopController::class, 'deleteConversationRemark'])->name('shop.conversation.remarks.destroy');
    Route::post('/shop/inbox/{conversation}/typing', [ShopController::class, 'sendTypingIndicator'])->name('shop.conversation.typing');
    Route::get('/shop/inbox/{conversation}/messages', [ShopController::class, 'fetchOlderMessages'])->name('shop.conversation.messages');
    Route::post('/shop/messages/{message}/reaction', [ShopController::class, 'toggleReaction'])->name('shop.messages.reaction');
    Route::post('/shop/messages/{message}/flag', [ShopController::class, 'toggleMessageFlag'])->name('shop.messages.flag');
    Route::post('/shop/messages/{message}/translate', [ShopController::class, 'translateMessage'])->name('shop.messages.translate');
    Route::get('/shop/inbox/{conversation}/search', [ShopController::class, 'searchMessages'])->name('shop.conversation.search');
    Route::post('/shop/inbox/{conversation}/draft', [ShopController::class, 'saveDraft'])->name('shop.conversation.draft');
    Route::post('/shop/inbox/{conversation}/schedule', [ShopController::class, 'scheduleMessage'])->name('shop.conversation.schedule');
    Route::delete('/shop/scheduled-messages/{scheduled_message}', [ShopController::class, 'cancelScheduledMessage'])->name('shop.scheduled-messages.cancel');
    Route::post('/shop/broadcast', [ShopController::class, 'broadcastMessage'])->name('shop.broadcast');
    Route::get('/shop/analytics', [ShopController::class, 'conversationAnalytics'])->name('shop.analytics');
    Route::post('/shop/conversations/export', [ShopController::class, 'exportConversations'])->name('shop.conversations.export');
    Route::get('/shop/conversations/export/{export}/download', [ShopController::class, 'downloadConversationExport'])->name('shop.conversations.export.download');
    Route::post('/shop/conversation-tags', [ShopController::class, 'storeTag'])->name('shop.conversation-tags.store');
    Route::get('/shop/customers', [ShopController::class, 'customers'])->name('shop.customers.index');
    Route::get('/shop/customers/export', [ShopController::class, 'exportCustomers'])->name('shop.customers.export');
    Route::get('/shop/customers/search', [ShopController::class, 'searchCustomers'])->name('shop.customers.search');
    Route::get('/shop/customers/{customer}', [ShopController::class, 'showCustomer'])->name('shop.customers.show');
    Route::patch('/shop/customers/{customer}', [ShopController::class, 'updateCustomer'])->name('shop.customers.update');
    Route::get('/shop/customers/{customer}/addresses', [ShopController::class, 'customerAddresses'])->name('shop.customers.addresses.index');
    Route::post('/shop/customers/{customer}/addresses', [ShopController::class, 'storeCustomerAddress'])->name('shop.customers.addresses.store');
    Route::patch('/shop/customers/{customer}/addresses/{address}/default', [ShopController::class, 'setDefaultCustomerAddress'])->name('shop.customers.addresses.default');
    Route::get('/shop/customers/{customer}/notes', [ShopController::class, 'customerNotes'])->name('shop.customers.notes.index');
    Route::post('/shop/customers/{customer}/notes', [ShopController::class, 'storeCustomerNote'])->name('shop.customers.notes.store');
    Route::patch('/shop/customers/{customer}/tags', [ShopController::class, 'updateCustomerTags'])->name('shop.customers.tags.update');
    Route::get('/shop/customers/{customer}/timeline', [ShopController::class, 'customerTimeline'])->name('shop.customers.timeline');
    Route::get('/shop/orders', [ShopController::class, 'orders'])->name('shop.orders.index');
    Route::get('/shop/templates', [ShopController::class, 'templates'])->name('shop.templates');
    Route::post('/shop/templates', [ShopController::class, 'storeTemplate'])->name('shop.templates.store');
    Route::delete('/shop/templates/{template}', [ShopController::class, 'destroyTemplate'])->name('shop.templates.destroy');
    Route::get('/shop/reports', [ShopController::class, 'reports'])->name('shop.reports');
    Route::get('/shop/webhooks', [ShopController::class, 'webhooks'])->name('shop.webhooks');
    Route::get('/shop/meta-readiness', [ShopController::class, 'metaReadiness'])->name('shop.meta-readiness');
    Route::post('/shop/webhooks/simulate', [ShopController::class, 'simulateWebhook'])->name('shop.webhooks.simulate');
    Route::get('/shop/encoder', [ShopController::class, 'encoder'])->name('shop.encoder');
    Route::patch('/shop/encoder/orders/{order}/address', [ShopController::class, 'updateOrderAddress'])->name('shop.encoder.address');
    Route::post('/shop/encoder/orders/{order}/encoded', [ShopController::class, 'markEncoded'])->name('shop.encoder.encoded');
    Route::post('/shop/exports', [ShopController::class, 'exportCourier'])->name('shop.exports.store');
    Route::post('/shop/exports/multi', [ShopController::class, 'exportMultipleCouriers'])->name('shop.exports.multi');
    Route::get('/shop/exports/{batch}/download', [ShopController::class, 'downloadExport'])->name('shop.exports.download');
    Route::post('/shop/exports/{batch}/archive', [ShopController::class, 'archiveCourierBatch'])->name('shop.exports.archive');
    Route::delete('/shop/exports/{batch}', [ShopController::class, 'deleteCourierBatch'])->name('shop.exports.destroy');
    Route::post('/shop/exports/{batch}/retry', [ShopController::class, 'retryCourierBatch'])->name('shop.exports.retry');
    Route::patch('/shop/exports/{batch}/notes', [ShopController::class, 'updateBatchNotes'])->name('shop.exports.notes');
    Route::get('/shop/exports/{batch}/preview', [ShopController::class, 'previewBatch'])->name('shop.exports.preview');
    Route::get('/shop/exports/analytics', [ShopController::class, 'batchAnalytics'])->name('shop.exports.analytics');
    Route::get('/shop/orders/create', [ShopController::class, 'createOrder'])->name('shop.orders.create');
    Route::get('/shop/orders/{order}/edit', [ShopController::class, 'editOrder'])->name('shop.orders.edit')->whereNumber('order');
    Route::post('/shop/orders', [ShopController::class, 'storeOrder'])->name('shop.orders.store');
    Route::post('/shop/orders/check-duplicates', [ShopController::class, 'checkDuplicates'])->name('shop.orders.check-duplicates');
    Route::post('/shop/orders/recommendations', [ShopController::class, 'recommendProducts'])->name('shop.orders.recommendations');
    Route::get('/shop/templates', [ShopController::class, 'listCartTemplates'])->name('shop.templates.index');
    Route::post('/shop/templates', [ShopController::class, 'storeCartTemplate'])->name('shop.templates.store');
    Route::delete('/shop/templates/{template}', [ShopController::class, 'deleteCartTemplate'])->name('shop.templates.destroy')->whereNumber('template');
    Route::post('/shop/orders/calculate-shipping', [ShopController::class, 'calculateShipping'])->name('shop.orders.shipping');
    Route::post('/shop/orders/draft', [ShopController::class, 'storeDraft'])->name('shop.orders.draft.store');
    Route::get('/shop/orders/{order}/draft', [ShopController::class, 'loadDraft'])->name('shop.orders.draft.load')->whereNumber('order');
    Route::delete('/shop/orders/{order}/draft', [ShopController::class, 'deleteDraft'])->name('shop.orders.draft.delete')->whereNumber('order');
    Route::get('/shop/orders/{order}', [ShopController::class, 'order'])->name('shop.orders.show')->whereNumber('order');
    Route::patch('/shop/orders/{order}', [ShopController::class, 'updateOrder'])->name('shop.orders.update')->whereNumber('order');
    Route::post('/shop/orders/{order}/follow-up', [ShopController::class, 'manualFollowUp'])->name('shop.orders.follow-up')->whereNumber('order');
    Route::post('/shop/orders/{order}/split', [ShopController::class, 'splitOrder'])->name('shop.orders.split')->whereNumber('order');
    Route::get('/shop/facebook/connect', [ShopController::class, 'connectFacebook'])->name('shop.facebook.connect');
    Route::get('/shop/facebook/callback', [ShopController::class, 'facebookCallback'])->name('shop.facebook.callback');
    Route::post('/shop/facebook/pages/manual', [ShopController::class, 'storeManualFacebookPage'])->name('shop.facebook.pages.manual');
    Route::post('/shop/facebook/pages/{page}/subscribe', [ShopController::class, 'subscribeFacebookPage'])->name('shop.facebook.pages.subscribe');
    Route::post('/shop/facebook/pages/{page}/check', [ShopController::class, 'checkFacebookPageSubscription'])->name('shop.facebook.pages.check');
    Route::delete('/shop/facebook/pages/{page}', [ShopController::class, 'disconnectFacebookPage'])->name('shop.facebook.pages.disconnect');
    Route::post('/shop/inbox/agent-status', [ShopController::class, 'updateAgentStatus'])->name('shop.agent.status');
    Route::post('/shop/inbox/agent-auto-assign', [ShopController::class, 'toggleAgentAutoAssign'])->name('shop.agent.auto-assign');
    Route::post('/shop/inbox/agent-skills', [ShopController::class, 'updateAgentSkills'])->name('shop.agent.skills');
    Route::post('/shop/inbox/agent-queue-limit', [ShopController::class, 'updateAgentQueueLimit'])->name('shop.agent.queue-limit');
    Route::post('/shop/inbox/agent-shift', [ShopController::class, 'updateAgentShiftSchedule'])->name('shop.agent.shift');
    Route::post('/shop/inbox/agent-idle-threshold', [ShopController::class, 'updateAgentIdleThreshold'])->name('shop.agent.idle-threshold');

    // Scanner
    Route::prefix('scanner')->name('scanner.')->group(function () {
        Route::get('/', [ScannerController::class, 'index'])->name('index');
    });

    // Waybills
    Route::prefix('waybills')->name('waybills.')->group(function () {
        Route::get('/', [WaybillController::class, 'index'])->name('index');
        Route::get('/scanner', [ScannerController::class, 'index'])->name('scanner');
        Route::post('/scan', [ScannerController::class, 'scan'])->name('scan');
        Route::post('/scan/batch', [ScannerController::class, 'batchScan'])->name('scan.batch');
        Route::get('/unknown', [UnknownWaybillController::class, 'index'])->name('unknown.index');
        Route::get('/unknown/suggest', [UnknownWaybillController::class, 'suggest'])->name('unknown.suggest');
        Route::post('/unknown/{unknown}/match', [UnknownWaybillController::class, 'match'])->name('unknown.match');
        Route::post('/unknown/{unknown}/dismiss', [UnknownWaybillController::class, 'dismiss'])->name('unknown.dismiss');
        Route::get('/claims/export', [WaybillExportController::class, 'claims'])->name('claims.export');
        Route::get('/beyond-sla/export', [WaybillExportController::class, 'beyondSla'])->name('beyond-sla.export');
        Route::get('/import', [WaybillImportController::class, 'index'])->name('import');
        Route::post('/import', [WaybillImportController::class, 'store'])->name('import.store');
        Route::get('/import/template', [WaybillImportController::class, 'template'])->name('import.template');
        Route::get('/import/{upload}', [WaybillImportController::class, 'show'])->name('import.show');
        Route::post('/import/{upload}/validate', [WaybillImportController::class, 'validateUpload'])->name('import.validate');
        Route::post('/import/{upload}/start', [WaybillImportController::class, 'start'])->name('import.start');
        Route::get('/import/{upload}/preview', [WaybillImportController::class, 'preview'])->name('import.preview');
        Route::get('/import/{upload}/errors/download', [WaybillImportController::class, 'errorsDownload'])->name('import.errors.download');
        Route::post('/import/{upload}/retry', [WaybillImportController::class, 'retry'])->name('import.retry');
        Route::post('/import/{upload}/cancel', [WaybillImportController::class, 'cancel'])->name('import.cancel');
        Route::get('/import/{upload}/status', [WaybillImportController::class, 'status'])->name('import.status');
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
        Route::post('/returns/scan', [ReturnReceiptController::class, 'store'])->name('returns.scan');
        Route::get('/search', [WaybillController::class, 'search'])->name('search');
        Route::get('/{waybill}', [WaybillController::class, 'show'])->name('show');
        Route::patch('/{waybill}/status', [WaybillController::class, 'updateStatus'])->name('update-status');
    });

    // Leads — index now redirects to unified Lead Pool view
    Route::prefix('leads')->name('leads.')->group(function () {
        Route::get('/', function () {
            return redirect('/lead-pool');
        })->name('index');
        Route::get('/{lead}', [LeadController::class, 'show'])->name('show');
    });

    // Orders
    Route::prefix('orders')->name('orders.')->group(function () {
        Route::get('/', [OrderController::class, 'index'])->name('index');
        Route::get('/{order}', [OrderController::class, 'show'])->name('show');
        Route::post('/{order}/approve', [OrderController::class, 'approve'])->name('approve');
        Route::post('/{order}/reject', [OrderController::class, 'reject'])->name('reject');
        Route::post('/{order}/cancel', [OrderController::class, 'cancel'])->name('cancel');
        Route::post('/{order}/duplicate-warnings/{remark}/resolve', [OrderController::class, 'resolveDuplicateWarning'])->name('resolve-duplicate-warning');
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

    // Agents & User Management
    Route::prefix('agents')->name('agents.')->group(function () {
        Route::get('/governance', [AgentController::class, 'index'])->name('governance');
        Route::post('/', [AgentController::class, 'store'])->name('store');
        Route::patch('/{user}/profile', [AgentController::class, 'updateProfile'])->name('update-profile')->whereNumber('user');
        Route::patch('/{user}/toggle-active', [AgentController::class, 'toggleActive'])->name('toggle-active')->whereNumber('user');
        Route::patch('/{user}', [AgentController::class, 'update'])->name('update')->whereNumber('user');
        Route::post('/{user}/delete', [AgentController::class, 'destroy'])->name('destroy')->whereNumber('user');
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

    // Courier Management
    Route::prefix('couriers')->name('couriers.')->group(function () {
        Route::get('/', [CourierProviderController::class, 'index'])->name('index');
        Route::patch('/{provider}', [CourierProviderController::class, 'update'])->name('update');
        Route::post('/{provider}/test', [CourierProviderController::class, 'testConnection'])->name('test');
        Route::post('/{provider}/sync', [CourierProviderController::class, 'syncTracking'])->name('sync');
        Route::get('/{provider}/logs', [CourierProviderController::class, 'logs'])->name('logs');
        Route::post('/create-order', [CourierProviderController::class, 'createOrder'])->name('create-order');
    });

    // Sales Tracking
    Route::get('/sales', [SalesTrackingController::class, 'index'])->name('sales.index');

    // Lead Pool
    Route::prefix('lead-pool')->name('lead-pool.')->group(function () {
        Route::get('/', [LeadPoolController::class, 'index'])->name('index');
        Route::post('/distribute', [LeadPoolController::class, 'distribute'])->name('distribute');
        Route::get('/agents', [LeadPoolController::class, 'agentPerformance'])->name('agents');
        Route::get('/import', [LeadImportController::class, 'create'])->name('import');
        Route::post('/import', [LeadImportController::class, 'store'])->name('import.store');
    });

    // Telesales Import
    Route::prefix('telesales')->name('telesales.')->group(function () {
        Route::get('/import', [TelesalesLeadImportController::class, 'create'])->name('import.create');
        Route::post('/import', [TelesalesLeadImportController::class, 'store'])->name('import.store');
    });

    // Distribution Engine
    Route::prefix('distribution')->name('distribution.')->group(function () {
        Route::get('/', [DistributionController::class, 'index'])->name('index');
        Route::post('/rules', [DistributionController::class, 'storeRule'])->name('rules.store');
        Route::patch('/rules/{rule}', [DistributionController::class, 'updateRule'])->name('rules.update');
        Route::delete('/rules/{rule}', [DistributionController::class, 'destroyRule'])->name('rules.destroy');
        Route::post('/assign', [DistributionController::class, 'assign'])->name('assign');
        Route::post('/reassign', [DistributionController::class, 'reassign'])->name('reassign');
        Route::post('/auto-distribute', [DistributionController::class, 'autoDistribute'])->name('auto-distribute');
        Route::get('/queue', [DistributionController::class, 'queue'])->name('queue');
        Route::get('/agents/{agent}/workload', [DistributionController::class, 'agentWorkload'])->name('agents.workload');

        // Analytics
        Route::get('/analytics', [DistributionAnalyticsController::class, 'index'])->name('analytics');
        Route::get('/analytics/alerts', [DistributionAnalyticsController::class, 'alerts'])->name('analytics.alerts');
        Route::get('/analytics/rebalancing', [DistributionAnalyticsController::class, 'rebalancing'])->name('analytics.rebalancing');
    });
});

// ── CRM ─────────────────────────────────────────────────────────────────────
Route::middleware(['auth', 'role:superadmin,admin,supervisor,finance,accounting'])->prefix('crm')->name('crm.')->group(function () {
    Route::prefix('contacts')->name('contacts.')->group(function () {
        Route::get('/',                                    [ThirdPartyController::class, 'index'])->name('index');
        Route::get('/create',                              [ThirdPartyController::class, 'create'])->name('create');
        Route::post('/',                                   [ThirdPartyController::class, 'store'])->name('store');
        Route::get('/{thirdParty}',                        [ThirdPartyController::class, 'show'])->name('show');
        Route::get('/{thirdParty}/edit',                   [ThirdPartyController::class, 'edit'])->name('edit');
        Route::patch('/{thirdParty}',                      [ThirdPartyController::class, 'update'])->name('update');
        Route::delete('/{thirdParty}',                     [ThirdPartyController::class, 'destroy'])->name('destroy');
        Route::post('/{thirdParty}/contacts',              [ThirdPartyController::class, 'storeContact'])->name('contacts.store');
        Route::post('/{thirdParty}/addresses',             [ThirdPartyController::class, 'storeAddress'])->name('addresses.store');
    });
});

// Approvals Inbox
Route::middleware(['auth', 'role:superadmin,admin,supervisor,finance,warehouse'])->group(function () {
    Route::get('/approvals', [ApprovalsController::class, 'index'])->name('approvals.index');
    Route::post('/approvals/settings', [ApprovalsController::class, 'updateSettings'])->name('approvals.settings');
});

// Notifications API
Route::middleware('auth')->prefix('api/notifications')->name('api.notifications.')->group(function () {
    Route::get('/', [NotificationController::class, 'index'])->name('index');
    Route::post('/{id}/read', [NotificationController::class, 'markRead'])->name('read');
    Route::post('/read-all', [NotificationController::class, 'markAllRead'])->name('read-all');
});

// Admin Dashboard
Route::middleware(['auth', 'role:superadmin,admin'])->prefix('admin')->name('admin.')->group(function () {
    Route::get('/', [\App\Http\Controllers\AdminController::class, 'index'])->name('dashboard');
    Route::post('/roles/permissions', [\App\Http\Controllers\AdminController::class, 'updateRolePermissions'])->name('roles.permissions');
    Route::post('/users', [\App\Http\Controllers\AdminController::class, 'storeUser'])->name('users.store');
    Route::post('/users/{user}/toggle', [\App\Http\Controllers\AdminController::class, 'toggleUser'])->name('users.toggle');
    Route::patch('/users/{user}/role', [\App\Http\Controllers\AdminController::class, 'updateUserRole'])->name('users.role');
    Route::patch('/users/{user}/modules', [\App\Http\Controllers\AdminController::class, 'updateUserModules'])->name('users.modules');
    Route::delete('/users/{user}', [\App\Http\Controllers\AdminController::class, 'deleteUser'])->name('users.delete');
});
