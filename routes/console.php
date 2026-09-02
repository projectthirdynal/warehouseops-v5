<?php

use App\Domain\Courier\Jobs\SyncTrackingStatusJob;
use App\Jobs\AutoDistributeLeads;
use App\Jobs\DetectFraudPatterns;
use App\Jobs\ProcessCooldownLeads;
use App\Models\Upload;
use Illuminate\Support\Facades\Schedule;

/*
|--------------------------------------------------------------------------
| Console Routes
|--------------------------------------------------------------------------
|
| This file is where you may define all of your Closure based console
| commands and scheduled tasks. Each Closure is bound to a command
| instance allowing a simple approach to interacting with each
| command's IO methods.
|
*/

// ── Lead / Distribution ──────────────────────────────────────────────
Schedule::job(new ProcessCooldownLeads)->everyFifteenMinutes()
    ->withoutOverlapping()
    ->onOneServer();

Schedule::job(new DetectFraudPatterns)->everyThirtyMinutes();

Schedule::job(new AutoDistributeLeads(batchSize: 20))
    ->everyMinute()
    ->withoutOverlapping()
    ->onOneServer();

Schedule::command('leads:rescore --limit=500')->hourly()->withoutOverlapping()->onOneServer();
Schedule::command('leads:check-pool-capacity')->everyThirtyMinutes()->withoutOverlapping()->onOneServer();
Schedule::command('predictive:retrain')->dailyAt('04:30')->withoutOverlapping()->onOneServer();

// ── Courier ──────────────────────────────────────────────────────────
Schedule::job(new SyncTrackingStatusJob)->everyFifteenMinutes()
    ->withoutOverlapping()
    ->onOneServer();

// ── Inventory ────────────────────────────────────────────────────────
Schedule::command('inventory:recompute-stock-status')->daily()->withoutOverlapping();
Schedule::command('inventory:release-expired-reservations')->hourly()->withoutOverlapping();
Schedule::command('inventory:check-reorder-points')->dailyAt('06:00')->withoutOverlapping()->onOneServer();
Schedule::command('inventory:scan-dead-stock')->dailyAt('06:30')->withoutOverlapping()->onOneServer();
Schedule::command('inventory:generate-cycle-counts')->dailyAt('07:00')->withoutOverlapping()->onOneServer();
Schedule::command('inventory:post-depreciation')->monthlyOn(1, '07:00')->withoutOverlapping()->onOneServer();

// ── Finance ──────────────────────────────────────────────────────────
Schedule::command('finance:generate-commission-runs --backfill')->dailyAt('07:30')->withoutOverlapping()->onOneServer();
Schedule::command('invoices:mark-overdue')->dailyAt('06:00')->withoutOverlapping();
Schedule::command('cogs:generate-daily-summary')->dailyAt('01:00')->withoutOverlapping()->onOneServer();

// ── Shop ─────────────────────────────────────────────────────────────
Schedule::command('shop:process-scheduled-messages')->everyMinute()->withoutOverlapping();
Schedule::command('shop:archive-stale-batches')->dailyAt('02:45')->withoutOverlapping();
Schedule::command('shop:cleanup-old-batches')->dailyAt('03:00')->withoutOverlapping();
Schedule::command('shop:check-idle-agents')->everyFiveMinutes()->withoutOverlapping();
Schedule::command('shop:enforce-shift-hours')->everyMinute()->withoutOverlapping()->onOneServer();
Schedule::command('shop:balance-workload')->everyFiveMinutes()->withoutOverlapping()->onOneServer();
Schedule::command('shop:auto-resolve-inactive')->hourly()->withoutOverlapping()->onOneServer();
Schedule::command('shop:escalate-sla-breached')->everyFifteenMinutes()->withoutOverlapping()->onOneServer();
Schedule::command('shop:apply-status-rules')->everyFifteenMinutes()->withoutOverlapping()->onOneServer();
Schedule::command('shop:send-order-followups')->dailyAt('09:00')->withoutOverlapping()->onOneServer();
Schedule::command('shop:archive-stale-conversations')->dailyAt('03:30')->withoutOverlapping()->onOneServer();
Schedule::command('shop:check-gamification')->dailyAt('04:00')->withoutOverlapping()->onOneServer();

// ── Sales / Quality / Meta ───────────────────────────────────────────
Schedule::command('sales-dashboard:generate-scheduled-reports')->everyFiveMinutes()->withoutOverlapping()->onOneServer();
Schedule::command('quality:train')->dailyAt('04:15')->withoutOverlapping()->onOneServer();
Schedule::command('meta:validate-tokens')->dailyAt('05:00')->withoutOverlapping()->onOneServer();

// ── Waybill import auto-fail ─────────────────────────────────────────
// Auto-fail orphaned imports: stuck in 'processing' with 0 rows for >15 min
Schedule::call(function () {
    Upload::where('type', 'waybill')
        ->where('status', 'processing')
        ->where('processed_rows', 0)
        ->where('created_at', '<', now()->subMinutes(15))
        ->each(fn ($u) => $u->markAsFailed(['message' => 'Job did not start — retried automatically. Please retry.']));
})->everyFifteenMinutes();
