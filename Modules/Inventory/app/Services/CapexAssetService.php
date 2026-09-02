<?php

declare(strict_types=1);

namespace Modules\Inventory\Services;

use Illuminate\Support\Facades\DB;
use Modules\Inventory\Models\CapexAsset;
use Modules\Inventory\Models\CapexAssetAssignment;
use Modules\Inventory\Models\CapexDepreciationSchedule;

class CapexAssetService
{
    public function create(array $data, int $createdBy): CapexAsset
    {
        return DB::transaction(function () use ($data, $createdBy): CapexAsset {
            $asset = CapexAsset::create(array_merge($data, [
                'created_by' => $createdBy,
                'status' => CapexAsset::STATUS_ACTIVE,
                'current_book_value' => $data['acquisition_cost'],
            ]));

            $this->generateDepreciationSchedule($asset);

            return $asset;
        });
    }

    public function update(CapexAsset $asset, array $data): CapexAsset
    {
        return DB::transaction(function () use ($asset, $data): CapexAsset {
            $asset->update($data);

            if (isset($data['acquisition_cost']) || isset($data['salvage_value']) || isset($data['depreciation_years']) || isset($data['purchase_date'])) {
                $asset->depreciationSchedule()->delete();
                $this->generateDepreciationSchedule($asset->fresh());
            }

            return $asset->fresh();
        });
    }

    public function assign(CapexAsset $asset, int $assignedTo, int $assignedBy, array $data): CapexAssetAssignment
    {
        return DB::transaction(function () use ($asset, $assignedTo, $assignedBy, $data): CapexAssetAssignment {
            $asset->currentAssignment()->update(['returned_at' => now()]);

            $assignment = CapexAssetAssignment::create([
                'capex_asset_id' => $asset->id,
                'assigned_to' => $assignedTo,
                'assigned_by' => $assignedBy,
                'department' => $data['department'] ?? null,
                'location' => $data['location'] ?? null,
                'notes' => $data['notes'] ?? null,
                'assigned_at' => now(),
            ]);

            $asset->update([
                'assigned_to' => $assignedTo,
                'department' => $data['department'] ?? $asset->department,
            ]);

            return $assignment;
        });
    }

    public function postDepreciation(CapexDepreciationSchedule $schedule, int $postedBy): CapexDepreciationSchedule
    {
        return DB::transaction(function () use ($schedule, $postedBy): CapexDepreciationSchedule {
            $schedule->update([
                'is_posted' => true,
                'posted_at' => now(),
                'posted_by' => $postedBy,
            ]);

            $schedule->asset()->update([
                'current_book_value' => $schedule->closing_book_value,
            ]);

            return $schedule;
        });
    }

    public function dispose(CapexAsset $asset, array $data): CapexAsset
    {
        return DB::transaction(function () use ($asset, $data): CapexAsset {
            $asset->currentAssignment()->update(['returned_at' => now()]);

            $asset->update([
                'status' => CapexAsset::STATUS_DISPOSED,
                'disposed_at' => now(),
                'disposal_reason' => $data['disposal_reason'] ?? null,
                'disposal_value' => $data['disposal_value'] ?? null,
                'assigned_to' => null,
            ]);

            return $asset;
        });
    }

    private function generateDepreciationSchedule(CapexAsset $asset): void
    {
        $depreciableAmount = (float) $asset->acquisition_cost - (float) $asset->salvage_value;
        $annualAmount = $asset->depreciation_years > 0
            ? $depreciableAmount / $asset->depreciation_years
            : 0;

        $bookValue = (float) $asset->acquisition_cost;
        $purchaseYear = (int) $asset->purchase_date->format('Y');

        $rows = [];
        for ($year = 1; $year <= $asset->depreciation_years; $year++) {
            $depreciation = ($year === $asset->depreciation_years)
                ? $bookValue - (float) $asset->salvage_value
                : $annualAmount;

            $closingValue = $bookValue - $depreciation;

            $rows[] = [
                'capex_asset_id' => $asset->id,
                'year' => $year,
                'fiscal_year' => $purchaseYear + $year - 1,
                'opening_book_value' => round($bookValue, 4),
                'depreciation_amount' => round($depreciation, 4),
                'closing_book_value' => round($closingValue, 4),
                'depreciation_date' => $asset->purchase_date->copy()->addYears($year - 1)->endOfYear()->toDateString(),
                'is_posted' => false,
                'posted_at' => null,
                'posted_by' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ];

            $bookValue = $closingValue;
        }

        CapexDepreciationSchedule::insert($rows);
    }
}
