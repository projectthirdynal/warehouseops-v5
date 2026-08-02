<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Waybill\Enums\ClaimStatus;
use App\Domain\Waybill\Enums\ClaimType;
use App\Domain\Waybill\Enums\WaybillStatus;
use App\Domain\Waybill\Models\Claim;
use App\Models\Waybill;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class AutoCreateClaims extends Command
{
    protected $signature = 'claims:auto-create
                            {--dry-run : Show what would be created without making changes}
                            {--days= : Only process waybills returned within the last N days}';

    protected $description = 'Auto-create draft claims for returned waybills that have no existing claims';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $days = $this->option('days') ? (int) $this->option('days') : null;

        $query = Waybill::where('status', WaybillStatus::RETURNED->value)
            ->whereDoesntHave('claims')
            ->orderBy('returned_at', 'desc');

        if ($days) {
            $query->where('returned_at', '>=', now()->subDays($days));
        }

        $waybills = $query->get();

        if ($waybills->isEmpty()) {
            $this->info('No returned waybills without claims found.');

            return Command::SUCCESS;
        }

        $this->info("Found {$waybills->count()} returned waybills without claims.");

        if ($dryRun) {
            $this->table(
                ['Waybill #', 'Courier', 'Returned At', 'COD Amount'],
                $waybills->map(fn ($w) => [
                    $w->waybill_number,
                    $w->courier_provider ?? '-',
                    $w->returned_at?->format('Y-m-d H:i') ?? '-',
                    number_format((float) ($w->cod_amount ?? $w->amount ?? 0), 2),
                ])->toArray()
            );
            $this->info('Dry run — no claims created.');

            return Command::SUCCESS;
        }

        $created = 0;
        $skipped = 0;

        foreach ($waybills as $waybill) {
            try {
                $claimAmount = (float) ($waybill->cod_amount ?? $waybill->amount ?? 0);

                Claim::create([
                    'claim_number' => Claim::generateClaimNumber(),
                    'waybill_id' => $waybill->id,
                    'type' => ClaimType::BEYOND_SLA->value,
                    'status' => ClaimStatus::DRAFT->value,
                    'auto_created' => true,
                    'source' => 'backfill_command',
                    'description' => "Auto-created via claims:auto-create command. Waybill {$waybill->waybill_number} marked as RETURNED.",
                    'claim_amount' => $claimAmount,
                    'filed_by' => $waybill->uploaded_by ?? 1,
                ]);

                $created++;
            } catch (\Throwable $e) {
                $skipped++;
                Log::error("Failed to auto-create claim for waybill {$waybill->waybill_number}: {$e->getMessage()}");
                $this->error("Failed for {$waybill->waybill_number}: {$e->getMessage()}");
            }
        }

        $this->info("Created {$created} draft claims. Skipped {$skipped} due to errors.");

        return Command::SUCCESS;
    }
}
