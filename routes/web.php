<?php

use App\Http\Controllers\DashboardController;
use App\Http\Controllers\AuthController;
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
        Route::get('/', fn() => inertia('Scanner/Index'))->name('index');
    });

    // Waybills
    Route::prefix('waybills')->name('waybills.')->group(function () {
        Route::get('/', fn() => inertia('Waybills/Index'))->name('index');
        Route::get('/{waybill}', fn($waybill) => inertia('Waybills/Show', ['waybillId' => $waybill]))->name('show');
    });

    // Leads
    Route::prefix('leads')->name('leads.')->group(function () {
        Route::get('/', fn() => inertia('Leads/Index'))->name('index');
        Route::get('/{lead}', fn($lead) => inertia('Leads/Show', ['leadId' => $lead]))->name('show');
    });

    // QC
    Route::prefix('qc')->name('qc.')->group(function () {
        Route::get('/', fn() => inertia('QC/Index'))->name('index');
    });

    // Recycling Pool
    Route::prefix('recycling')->name('recycling.')->group(function () {
        Route::get('/pool', fn() => inertia('Recycling/Index'))->name('pool');
    });

    // Monitoring
    Route::prefix('monitoring')->name('monitoring.')->group(function () {
        Route::get('/dashboard', fn() => inertia('Monitoring/Index'))->name('dashboard');
    });

    // Agents
    Route::prefix('agents')->name('agents.')->group(function () {
        Route::get('/governance', fn() => inertia('Agents/Index'))->name('governance');
    });

    // Tickets
    Route::prefix('tickets')->name('tickets.')->group(function () {
        Route::get('/', fn() => inertia('Tickets/Index'))->name('index');
    });

    // Settings
    Route::prefix('settings')->name('settings.')->group(function () {
        Route::get('/', fn() => inertia('Settings/Index'))->name('index');
    });
});
