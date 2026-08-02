<?php

namespace App\Console\Commands;

use App\Domain\Analytics\Services\SalesDashboardService;
use App\Models\ScheduledSalesReport;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;

class GenerateScheduledSalesReports extends Command
{
    protected $signature = 'sales-dashboard:generate-scheduled-reports';

    protected $description = 'Generate and send scheduled sales reports that are due.';

    public function handle(SalesDashboardService $service): int
    {
        $now = Carbon::now();
        $due = ScheduledSalesReport::where('is_active', true)
            ->where('next_run_at', '<=', $now)
            ->get();

        if ($due->isEmpty()) {
            $this->info('No scheduled sales reports due.');

            return self::SUCCESS;
        }

        $sent = 0;
        foreach ($due as $report) {
            try {
                $from = $now->copy()->subDays($report->lookback_days)->toDateString();
                $to = $now->toDateString();

                if ($report->format === 'json') {
                    $content = json_encode($service->exportSalesReport($from, $to), JSON_PRETTY_PRINT);
                    $ext = 'json';
                    $mime = 'application/json';
                } else {
                    $content = $service->exportSalesReportCsv($from, $to);
                    $ext = 'csv';
                    $mime = 'text/csv';
                }

                $filename = "sales_report_{$from}_{$to}.{$ext}";
                $path = "sales-reports/{$filename}";
                Storage::disk('local')->put($path, $content);

                $recipients = $report->recipients ?? [];
                if (! empty($recipients)) {
                    Mail::raw(
                        "Scheduled sales report '{$report->name}' for period {$from} to {$to}.\n\nReport attached.",
                        function ($mail) use ($recipients, $filename, $path, $mime) {
                            $mail->to($recipients)
                                ->subject("Scheduled Sales Report: {$filename}")
                                ->attach(Storage::disk('local')->path($path), [
                                    'as' => $filename,
                                    'mime' => $mime,
                                ]);
                        }
                    );
                }

                $report->update([
                    'last_run_at' => $now,
                    'next_run_at' => $this->calculateNextRun($report, $now),
                ]);

                $sent++;
            } catch (\Throwable $e) {
                Log::error("Failed to generate scheduled sales report '{$report->name}': {$e->getMessage()}");
                $this->error("Failed for report ID {$report->id}: {$e->getMessage()}");
            }
        }

        $this->info("Processed {$sent} scheduled sales report(s).");

        return self::SUCCESS;
    }

    private function calculateNextRun(ScheduledSalesReport $report, Carbon $now): Carbon
    {
        $sendAt = Carbon::parse($report->send_at);

        return match ($report->frequency) {
            'daily' => $now->copy()->addDay()->setTimeFromTimeString($report->send_at),
            'weekly' => $this->nextWeeklyRun($report, $now),
            'monthly' => $this->nextMonthlyRun($report, $now),
            default => $now->copy()->addWeek()->setTimeFromTimeString($report->send_at),
        };
    }

    private function nextWeeklyRun(ScheduledSalesReport $report, Carbon $now): Carbon
    {
        $dayMap = ['sun' => 0, 'mon' => 1, 'tue' => 2, 'wed' => 3, 'thu' => 4, 'fri' => 5, 'sat' => 6];
        $targetDow = $dayMap[strtolower($report->day_of_week ?? 'mon')] ?? 1;
        $currentDow = $now->dayOfWeek;
        $daysUntil = ($targetDow - $currentDow + 7) % 7;
        if ($daysUntil === 0) {
            $daysUntil = 7;
        }

        return $now->copy()->addDays($daysUntil)->setTimeFromTimeString($report->send_at);
    }

    private function nextMonthlyRun(ScheduledSalesReport $report, Carbon $now): Carbon
    {
        $dom = $report->day_of_month ?? 1;
        $next = $now->copy()->addMonth();
        $lastDay = (int) $next->format('t');
        $dom = min($dom, $lastDay);

        return $next->setDay($dom)->setTimeFromTimeString($report->send_at);
    }
}
