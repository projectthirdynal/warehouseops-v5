<?php

declare(strict_types=1);

namespace Modules\Inventory\Services;

use App\Models\SiteSetting;
use App\Models\User;
use App\Notifications\DepreciationPostedNotification;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Modules\Inventory\Models\CapexAsset;
use Modules\Inventory\Models\CapexDepreciationJournal;

class DepreciationAutomationService
{
    public const DEFAULT_DEBIT_ACCOUNT = 'Depreciation Expense';

    public const DEFAULT_CREDIT_ACCOUNT = 'Accumulated Depreciation';

    /**
     * Get settings.
     *
     * @return array<string, mixed>
     */
    public function getSettings(): array
    {
        return [
            'auto_post' => (bool) SiteSetting::get('dep_auto_post', true),
            'posting_day' => (int) SiteSetting::get('dep_posting_day', 1),
            'debit_account' => (string) SiteSetting::get('dep_debit_account', self::DEFAULT_DEBIT_ACCOUNT),
            'credit_account' => (string) SiteSetting::get('dep_credit_account', self::DEFAULT_CREDIT_ACCOUNT),
            'notify_emails' => (string) SiteSetting::get('dep_notify_emails', ''),
            'notify_email_enabled' => (bool) SiteSetting::get('dep_notify_email_enabled', true),
            'notify_in_app_enabled' => (bool) SiteSetting::get('dep_notify_in_app_enabled', true),
        ];
    }

    /**
     * Update settings.
     *
     * @param  array<string, mixed>  $data
     */
    public function updateSettings(array $data): void
    {
        $keys = [
            'auto_post' => 'dep_auto_post',
            'posting_day' => 'dep_posting_day',
            'debit_account' => 'dep_debit_account',
            'credit_account' => 'dep_credit_account',
            'notify_emails' => 'dep_notify_emails',
            'notify_email_enabled' => 'dep_notify_email_enabled',
            'notify_in_app_enabled' => 'dep_notify_in_app_enabled',
        ];

        foreach ($keys as $field => $settingKey) {
            if (array_key_exists($field, $data)) {
                SiteSetting::set($settingKey, $data[$field]);
            }
        }
    }

    /**
     * Generate monthly journal entries for all active assets.
     *
     * @return array<string, mixed>
     */
    public function generateMonthlySchedules(): array
    {
        $settings = $this->getSettings();
        $assets = CapexAsset::where('status', CapexAsset::STATUS_ACTIVE)->get();
        $generated = 0;

        foreach ($assets as $asset) {
            $generated += $this->generateForAsset($asset, $settings);
        }

        return [
            'assets_processed' => $assets->count(),
            'entries_generated' => $generated,
        ];
    }

    /**
     * Generate monthly journal entries for a single asset.
     *
     * @param  array<string, mixed>  $settings
     */
    public function generateForAsset(CapexAsset $asset, ?array $settings = null): int
    {
        $settings ??= $this->getSettings();
        $annualSchedules = $asset->depreciationSchedule()->orderBy('year')->get();

        if ($annualSchedules->isEmpty()) {
            return 0;
        }

        $generated = 0;
        $accumulatedDep = (float) $asset->acquisition_cost - (float) $asset->current_book_value;
        $purchaseDate = $asset->purchase_date;

        foreach ($annualSchedules as $schedule) {
            $annualAmount = (float) $schedule->depreciation_amount;
            $monthlyAmount = round($annualAmount / 12, 4);

            for ($month = 1; $month <= 12; $month++) {
                $postingDate = $this->computePostingDate($schedule->fiscal_year, $month, $settings['posting_day']);

                // Skip future months beyond current date
                if ($postingDate->isFuture()) {
                    break;
                }

                // Skip months before asset purchase
                if ($postingDate->lt($purchaseDate)) {
                    continue;
                }

                $exists = CapexDepreciationJournal::where('capex_asset_id', $asset->id)
                    ->where('year', $schedule->year)
                    ->where('month', $month)
                    ->exists();

                if ($exists) {
                    $accumulatedDep += $monthlyAmount;

                    continue;
                }

                // Last month of the last year: adjust for rounding
                $isLastEntry = $schedule->year === $annualSchedules->last()->year && $month === 12;
                $amount = $isLastEntry
                    ? round((float) $asset->salvage_value - ((float) $asset->acquisition_cost - $accumulatedDep - $annualAmount), 4)
                    : $monthlyAmount;

                // For the last month, just use the remaining amount
                if ($isLastEntry) {
                    $remaining = ((float) $schedule->closing_book_value) - ($accumulatedDep + $monthlyAmount * 11 - $annualAmount);
                    $amount = max(0, round(((float) $schedule->depreciation_amount) - $monthlyAmount * 11, 4));
                }

                $bookValueAfter = max(0, (float) $asset->acquisition_cost - $accumulatedDep - $amount);

                CapexDepreciationJournal::create([
                    'capex_asset_id' => $asset->id,
                    'depreciation_schedule_id' => $schedule->id,
                    'year' => $schedule->year,
                    'month' => $month,
                    'posting_date' => $postingDate->toDateString(),
                    'depreciation_amount' => $amount,
                    'accumulated_depreciation' => round($accumulatedDep + $amount, 4),
                    'book_value_after' => round($bookValueAfter, 4),
                    'debit_account' => $settings['debit_account'],
                    'credit_account' => $settings['credit_account'],
                    'reference' => "DEP-{$asset->asset_code}-Y{$schedule->year}-M{$month}",
                    'notes' => "Monthly depreciation for {$asset->name} ({$asset->asset_code})",
                    'is_posted' => false,
                ]);

                $accumulatedDep += $amount;
                $generated++;
            }
        }

        return $generated;
    }

    /**
     * Post all due depreciation journal entries.
     *
     * @return array<string, mixed>
     */
    public function postDueEntries(): array
    {
        $settings = $this->getSettings();

        // First generate any missing monthly entries
        $genResult = $this->generateMonthlySchedules();

        // Get all unposted entries with posting_date <= today
        $dueEntries = CapexDepreciationJournal::with('asset')
            ->where('is_posted', false)
            ->where('posting_date', '<=', today())
            ->orderBy('posting_date')
            ->get();

        $posted = 0;
        $totalAmount = 0.0;
        $postedEntries = [];

        foreach ($dueEntries as $entry) {
            if ($entry->asset && $entry->asset->status !== CapexAsset::STATUS_ACTIVE) {
                continue;
            }

            $this->postEntry($entry, $settings);
            $posted++;
            $totalAmount += (float) $entry->depreciation_amount;
            $postedEntries[] = $entry;
        }

        // Send notifications
        if ($posted > 0) {
            $this->sendNotifications($posted, $totalAmount, $postedEntries, $settings);
        }

        return [
            'generated' => $genResult['entries_generated'],
            'posted' => $posted,
            'total_amount' => round($totalAmount, 2),
            'posted_at' => now()->toDateTimeString(),
        ];
    }

    /**
     * Post a single journal entry.
     *
     * @param  array<string, mixed>  $settings
     */
    public function postEntry(CapexDepreciationJournal $entry, ?array $settings = null): void
    {
        $settings ??= $this->getSettings();

        DB::transaction(function () use ($entry): void {
            $entry->update([
                'is_posted' => true,
                'posted_at' => now(),
                'posted_by' => null,
            ]);

            // Update asset book value
            $entry->asset->update([
                'current_book_value' => $entry->book_value_after,
            ]);

            // Mark the annual schedule as posted if all 12 months are posted
            $allMonthsPosted = CapexDepreciationJournal::where('capex_asset_id', $entry->capex_asset_id)
                ->where('year', $entry->year)
                ->where('is_posted', false)
                ->doesntExist();

            if ($allMonthsPosted && $entry->schedule) {
                $entry->schedule->update([
                    'is_posted' => true,
                    'posted_at' => now(),
                ]);
            }
        });
    }

    /**
     * Get the depreciation dashboard.
     *
     * @return array<string, mixed>
     */
    public function getDashboard(): array
    {
        $settings = $this->getSettings();

        // Generate entries first to ensure data is current
        $this->generateMonthlySchedules();

        $totalAssets = CapexAsset::where('status', CapexAsset::STATUS_ACTIVE)->count();
        $totalCost = (float) CapexAsset::where('status', CapexAsset::STATUS_ACTIVE)->sum('acquisition_cost');
        $totalBookValue = (float) CapexAsset::where('status', CapexAsset::STATUS_ACTIVE)->sum('current_book_value');
        $totalAccumulatedDep = $totalCost - $totalBookValue;

        $dueCount = CapexDepreciationJournal::where('is_posted', false)
            ->where('posting_date', '<=', today())
            ->count();
        $dueAmount = (float) CapexDepreciationJournal::where('is_posted', false)
            ->where('posting_date', '<=', today())
            ->sum('depreciation_amount');

        $postedCount = CapexDepreciationJournal::where('is_posted', true)->count();
        $postedAmount = (float) CapexDepreciationJournal::where('is_posted', true)->sum('depreciation_amount');

        // Upcoming entries (next 30 days)
        $upcoming = CapexDepreciationJournal::with('asset:id,asset_code,name,category')
            ->where('is_posted', false)
            ->where('posting_date', '>', today())
            ->where('posting_date', '<=', today()->addDays(30))
            ->orderBy('posting_date')
            ->limit(20)
            ->get()
            ->map(fn ($j) => [
                'id' => $j->id,
                'asset_code' => $j->asset?->asset_code,
                'asset_name' => $j->asset?->name,
                'category' => $j->asset?->category,
                'year' => $j->year,
                'month' => $j->month,
                'posting_date' => $j->posting_date->toDateString(),
                'depreciation_amount' => (float) $j->depreciation_amount,
                'debit_account' => $j->debit_account,
                'credit_account' => $j->credit_account,
                'reference' => $j->reference,
            ])
            ->all();

        // Due entries
        $dueEntries = CapexDepreciationJournal::with('asset:id,asset_code,name,category')
            ->where('is_posted', false)
            ->where('posting_date', '<=', today())
            ->orderBy('posting_date')
            ->limit(50)
            ->get()
            ->map(fn ($j) => [
                'id' => $j->id,
                'asset_code' => $j->asset?->asset_code,
                'asset_name' => $j->asset?->name,
                'category' => $j->asset?->category,
                'year' => $j->year,
                'month' => $j->month,
                'posting_date' => $j->posting_date->toDateString(),
                'depreciation_amount' => (float) $j->depreciation_amount,
                'book_value_after' => (float) $j->book_value_after,
                'debit_account' => $j->debit_account,
                'credit_account' => $j->credit_account,
                'reference' => $j->reference,
            ])
            ->all();

        // By asset summary
        $byAsset = CapexAsset::where('status', CapexAsset::STATUS_ACTIVE)
            ->withCount(['depreciationJournals as posted_count' => fn ($q) => $q->where('is_posted', true)])
            ->withCount(['depreciationJournals as due_count' => fn ($q) => $q->where('is_posted', false)->where('posting_date', '<=', today())])
            ->get()
            ->map(fn ($a) => [
                'id' => $a->id,
                'asset_code' => $a->asset_code,
                'name' => $a->name,
                'category' => $a->category,
                'acquisition_cost' => (float) $a->acquisition_cost,
                'current_book_value' => (float) $a->current_book_value,
                'accumulated_depreciation' => (float) $a->acquisition_cost - (float) $a->current_book_value,
                'annual_depreciation' => $a->annualDepreciation(),
                'posted_count' => $a->posted_count,
                'due_count' => $a->due_count,
            ])
            ->sortByDesc('accumulated_depreciation')
            ->values()
            ->all();

        // Monthly trend (last 12 months)
        $monthlyTrend = CapexDepreciationJournal::selectRaw('
                EXTRACT(YEAR FROM posting_date)::int as yr,
                EXTRACT(MONTH FROM posting_date)::int as mo,
                SUM(depreciation_amount) as total_amount,
                COUNT(*) as entry_count
            ')
            ->where('is_posted', true)
            ->where('posted_at', '>=', now()->subMonths(12))
            ->groupByRaw('EXTRACT(YEAR FROM posting_date), EXTRACT(MONTH FROM posting_date)')
            ->orderByRaw('EXTRACT(YEAR FROM posting_date), EXTRACT(MONTH FROM posting_date)')
            ->get()
            ->map(fn ($r) => [
                'period' => sprintf('%04d-%02d', $r->yr, $r->mo),
                'total_amount' => (float) $r->total_amount,
                'entry_count' => (int) $r->entry_count,
            ])
            ->all();

        return [
            'summary' => [
                'total_assets' => $totalAssets,
                'total_acquisition_cost' => round($totalCost, 2),
                'total_book_value' => round($totalBookValue, 2),
                'total_accumulated_depreciation' => round($totalAccumulatedDep, 2),
                'due_count' => $dueCount,
                'due_amount' => round($dueAmount, 2),
                'posted_count' => $postedCount,
                'posted_amount' => round($postedAmount, 2),
            ],
            'upcoming' => $upcoming,
            'due_entries' => $dueEntries,
            'by_asset' => $byAsset,
            'monthly_trend' => $monthlyTrend,
            'settings' => $settings,
        ];
    }

    /**
     * Export journal entries as CSV.
     */
    public function exportCsv(): string
    {
        $this->generateMonthlySchedules();

        $entries = CapexDepreciationJournal::with('asset:id,asset_code,name,category')
            ->orderBy('posting_date')
            ->get();

        $lines = [];
        $lines[] = 'ASSET DEPRECIATION JOURNAL REPORT';
        $lines[] = 'Generated,'.now()->toDateTimeString();
        $lines[] = '';
        $lines[] = 'SUMMARY';
        $lines[] = 'Total Entries,'.$entries->count();
        $lines[] = 'Posted,'.$entries->where('is_posted', true)->count();
        $lines[] = 'Unposted,'.$entries->where('is_posted', false)->count();
        $lines[] = 'Total Depreciation,'.number_format((float) $entries->sum('depreciation_amount'), 2, '.', '');
        $lines[] = '';
        $lines[] = 'JOURNAL ENTRIES';
        $lines[] = 'Reference,Asset Code,Asset Name,Category,Year,Month,Posting Date,Depreciation Amount,Accumulated Dep,Book Value After,Debit Account,Credit Account,Posted,Posted At';

        foreach ($entries as $j) {
            $lines[] = implode(',', [
                $j->reference ?? '',
                $j->asset?->asset_code ?? '',
                $this->csvEscape($j->asset?->name ?? ''),
                $j->asset?->category ?? '',
                $j->year,
                $j->month,
                $j->posting_date->toDateString(),
                number_format((float) $j->depreciation_amount, 4, '.', ''),
                number_format((float) $j->accumulated_depreciation, 4, '.', ''),
                number_format((float) $j->book_value_after, 4, '.', ''),
                $this->csvEscape($j->debit_account),
                $this->csvEscape($j->credit_account),
                $j->is_posted ? 'YES' : 'NO',
                $j->posted_at?->toDateTimeString() ?? '',
            ]);
        }

        return implode("\n", $lines);
    }

    /**
     * Compute the posting date for a given year/month.
     *
     * @param  array<string, mixed>  $settings
     */
    private function computePostingDate(int $fiscalYear, int $month, int $postingDay): Carbon
    {
        $day = min($postingDay, 28);

        return Carbon::createFromDate($fiscalYear, $month, $day);
    }

    /**
     * Send notifications for posted depreciation.
     *
     * @param  array<string, mixed>  $settings
     * @param  Collection<int, CapexDepreciationJournal>  $entries
     */
    private function sendNotifications(int $count, float $totalAmount, array $entries, array $settings): void
    {
        $notifyEmails = $settings['notify_email_enabled']
            ? array_filter(array_map('trim', explode(',', (string) $settings['notify_emails'])))
            : [];

        $notifyUsers = collect();

        if ($settings['notify_in_app_enabled']) {
            $roles = ['superadmin', 'admin', 'finance', 'supervisor'];
            $notifyUsers = User::whereIn('role', $roles)->where('is_active', true)->get();
        }

        if (! empty($notifyEmails)) {
            $emailUsers = User::whereIn('email', $notifyEmails)->where('is_active', true)->get();
            $notifyUsers = $notifyUsers->merge($emailUsers)->unique('id');
        }

        if ($notifyUsers->isEmpty()) {
            return;
        }

        $summary = [
            'posted_count' => $count,
            'total_amount' => round($totalAmount, 2),
            'posted_at' => now()->toDateTimeString(),
            'top_entries' => collect($entries)
                ->sortByDesc('depreciation_amount')
                ->take(10)
                ->map(fn ($e) => [
                    'asset_code' => $e->asset?->asset_code,
                    'asset_name' => $e->asset?->name,
                    'amount' => (float) $e->depreciation_amount,
                    'reference' => $e->reference,
                ])
                ->all(),
        ];

        Notification::send($notifyUsers, new DepreciationPostedNotification($summary, $settings['notify_email_enabled']));
    }

    private function csvEscape(string $value): string
    {
        if (str_contains($value, ',') || str_contains($value, '"') || str_contains($value, "\n")) {
            return '"'.str_replace('"', '""', $value).'"';
        }

        return $value;
    }
}
