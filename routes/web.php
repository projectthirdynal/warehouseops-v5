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
use App\Http\Controllers\AgentLeadController;
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

// Protected routes
Route::middleware(['auth'])->group(function () {
    // Dashboard
    Route::get('/', [DashboardController::class, 'index'])->name('dashboard');

    // Scanner
    Route::prefix('scanner')->name('scanner.')->group(function () {
        Route::get('/', [ScannerController::class, 'index'])->name('index');
    });

    // Waybills
    Route::prefix('waybills')->name('waybills.')->group(function () {
        Route::get('/', [WaybillController::class, 'index'])->name('index');

        // Import routes (before {waybill} to avoid conflict)
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
    });

    // Tickets
    Route::prefix('tickets')->name('tickets.')->group(function () {
        Route::get('/', [TicketController::class, 'index'])->name('index');
    });

    // Settings
    Route::prefix('settings')->name('settings.')->group(function () {
        Route::get('/', [SettingsController::class, 'index'])->name('index');
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

        // Sequences
        Route::get('/sequences', [SmsController::class, 'sequences'])->name('sequences');
        Route::get('/sequences/create', [SmsController::class, 'createSequence'])->name('sequences.create');
        Route::post('/sequences', [SmsController::class, 'storeSequence'])->name('sequences.store');
        Route::post('/sequences/{sequence}/toggle', [SmsController::class, 'toggleSequence'])->name('sequences.toggle');

        // Templates
        Route::get('/templates', [SmsController::class, 'templates'])->name('templates');
        Route::post('/templates', [SmsController::class, 'storeTemplate'])->name('templates.store');
        Route::delete('/templates/{template}', [SmsController::class, 'destroyTemplate'])->name('templates.destroy');

        // Logs
        Route::get('/logs', [SmsController::class, 'logs'])->name('logs');
    });

    // Agent Self-Service Portal
    Route::prefix('agent')->name('agent.')->group(function () {
        Route::get('/leads', [AgentLeadController::class, 'portal'])->name('leads');
        Route::post('/leads/request', [AgentLeadController::class, 'requestLeads'])->name('leads.request');
    });

    // Agent API (AJAX calls from portal)
    Route::prefix('api/agent')->name('api.agent.')->group(function () {
        Route::post('/leads/request', [AgentLeadController::class, 'requestLeads'])->name('leads.request');
        Route::post('/leads/{lead}/call', [AgentLeadController::class, 'call'])->name('leads.call');
        Route::post('/leads/{lead}/outcome', [AgentLeadController::class, 'outcome'])->name('leads.outcome');
    });

    // Lead Pool (Supervisor)
    Route::prefix('lead-pool')->name('lead-pool.')->group(function () {
        Route::get('/', [LeadPoolController::class, 'index'])->name('index');
        Route::post('/distribute', [LeadPoolController::class, 'distribute'])->name('distribute');
        Route::get('/agents', [LeadPoolController::class, 'agentPerformance'])->name('agents');
        Route::get('/import', [LeadImportController::class, 'create'])->name('import');
        Route::post('/import', [LeadImportController::class, 'store'])->name('import.store');
    });
});
