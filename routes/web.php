<?php

use App\Http\Controllers\DashboardController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\WaybillController;
use App\Http\Controllers\LeadController;
use App\Http\Controllers\AgentController;
use App\Http\Controllers\ScannerController;
use App\Http\Controllers\TicketController;
use App\Http\Controllers\SettingsController;
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
});
