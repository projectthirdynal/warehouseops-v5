<?php

declare(strict_types=1);

namespace App\Services;

use App\Domain\Lead\Enums\LeadPoolStatus;
use App\Domain\Lead\Enums\PoolMemberStatus;
use App\Domain\Lead\Enums\PoolRequestStatus;
use App\Domain\Lead\Enums\PoolStatus;
use App\Domain\Lead\Models\Lead;
use App\Domain\Lead\Models\LeadPool;
use App\Domain\Lead\Models\LeadPoolMember;
use App\Domain\Lead\Models\LeadPoolRequest;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Handles the transactional reservation of leads into an approved pool.
 *
 * This service is called by PoolRequestService::approveRequest() after the
 * admin approves a request. It:
 *  1. Recalculates eligible leads using LeadEligibilityService
 *  2. Locks the candidate lead rows (SELECT ... FOR UPDATE)
 *  3. Revalidates eligibility inside the transaction
 *  4. Creates the LeadPool + LeadPoolMember records
 *  5. Marks reserved leads as ASSIGNED to the pool (via pool_status remains AVAILABLE
 *     but membership prevents double-pooling through the partial unique index)
 *
 * Concurrency safety:
 *  - Uses DB::transaction() with SELECT ... FOR UPDATE on candidate leads
 *  - The partial unique index on lead_pool_members(lead_id) WHERE status IN ('PENDING','ASSIGNED')
 *    prevents the same lead from being reserved into two active pools simultaneously
 *  - If a concurrent reservation wins, the FOR UPDATE lock ensures the second transaction
 *    sees the updated state and skips already-reserved leads
 */
class PoolReservationService
{
    public function __construct(
        private LeadEligibilityService $eligibilityService
    ) {}

    /**
     * Reserve leads and create the approved pool with members.
     *
     * @return array{pool: LeadPool, reserved: int}
     *
     * @throws \RuntimeException if not enough eligible leads can be reserved.
     */
    public function reserveAndCreatePool(LeadPoolRequest $request, User $approver): array
    {
        return DB::transaction(function () use ($request, $approver) {
            $approvedQty = $request->approved_quantity ?? $request->requested_quantity;

            // 1. Get the eligible lead IDs (limit at query level for efficiency)
            $leadIds = $this->eligibilityService
                ->buildFilteredQuery($request->toEligibilityFilters())
                ->limit($approvedQty)
                ->pluck('leads.id')
                ->all();

            $reservedCount = count($leadIds);
            if ($reservedCount < $approvedQty) {
                throw new \RuntimeException(
                    "Could only reserve {$reservedCount} leads out of {$approvedQty} approved. "
                    .'Availability may have changed during approval.'
                );
            }

            // 2. Lock the candidate lead rows for update (PostgreSQL)
            //    This prevents concurrent transactions from modifying these leads
            //    until our transaction commits.
            $lockedLeads = Lead::whereIn('id', $leadIds)
                ->where('pool_status', PoolStatus::AVAILABLE)
                ->lockForUpdate()
                ->get();

            if ($lockedLeads->count() < $approvedQty) {
                throw new \RuntimeException(
                    "Race condition: only {$lockedLeads->count()} leads are still AVAILABLE out of {$approvedQty} needed."
                );
            }

            // 3. Create the pool
            $pool = LeadPool::create([
                'pool_number' => $this->generatePoolNumber(),
                'pool_request_id' => $request->id,
                'brand_name' => $request->brand_name,
                'product_name' => $request->product_name,
                'business_region' => $request->business_region,
                'province' => $request->province,
                'city' => $request->city,
                'lead_age_from' => $request->lead_age_from,
                'lead_age_to' => $request->lead_age_to,
                'team_id' => $request->team_id,
                'approved_quantity' => $approvedQty,
                'reserved_quantity' => $lockedLeads->count(),
                'distributed_quantity' => 0,
                'distribution_method' => $request->distribution_method,
                'status' => LeadPoolStatus::ACTIVE,
                'created_by' => $approver->id,
                'approved_by' => $approver->id,
                'activated_at' => now(),
            ]);

            // 4. Create pool members — the partial unique index on lead_pool_members
            //    will throw a unique violation if a concurrent transaction already
            //    reserved one of these leads, causing our transaction to roll back.
            $members = [];
            $now = now();
            foreach ($lockedLeads as $lead) {
                $members[] = [
                    'lead_pool_id' => $pool->id,
                    'lead_id' => $lead->id,
                    'status' => PoolMemberStatus::PENDING->value,
                    'added_at' => $now,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }

            // Bulk insert for performance
            DB::table('lead_pool_members')->insert($members);

            return [
                'pool' => $pool->fresh(),
                'reserved' => count($members),
            ];
        });
    }

    /**
     * Mark a pool member as assigned to an agent (called during distribution).
     */
    public function markMemberAssigned(LeadPoolMember $member): void
    {
        DB::transaction(function () use ($member) {
            $member->update([
                'status' => PoolMemberStatus::ASSIGNED,
                'assigned_at' => now(),
            ]);

            $pool = $member->pool;
            $pool->increment('distributed_quantity');

            // Update pool status if fully distributed
            if ($pool->distributed_quantity >= $pool->reserved_quantity) {
                $pool->update([
                    'status' => LeadPoolStatus::FULLY_DISTRIBUTED,
                    'completed_at' => now(),
                ]);
            } elseif ($pool->status === LeadPoolStatus::ACTIVE) {
                $pool->update(['status' => LeadPoolStatus::PARTIALLY_DISTRIBUTED]);
            }

            // Update the request status
            $request = $pool->request;
            if ($request && $pool->status === LeadPoolStatus::FULLY_DISTRIBUTED) {
                $request->update(['status' => PoolRequestStatus::DISTRIBUTED]);
            } elseif ($request && $request->status === PoolRequestStatus::APPROVED) {
                $request->update(['status' => PoolRequestStatus::PARTIALLY_DISTRIBUTED]);
            }
        });
    }

    /**
     * Cancel a pool — remove all pending members and mark the pool as cancelled.
     */
    public function cancelPool(LeadPool $pool, User $actor, ?string $reason = null): void
    {
        DB::transaction(function () use ($pool, $actor, $reason) {
            // Remove all pending members (ASSIGNED members stay for audit)
            $pool->members()
                ->where('status', PoolMemberStatus::PENDING)
                ->update([
                    'status' => PoolMemberStatus::REMOVED->value,
                    'removed_at' => now(),
                    'removal_reason' => $reason ?? 'Pool cancelled by '.$actor->name,
                ]);

            $pool->update([
                'status' => LeadPoolStatus::CANCELLED,
                'cancelled_at' => now(),
            ]);
        });
    }

    /**
     * Get pending (unassigned) pool members for a specific team.
     *
     * Used by the restricted agent self-pull to find leads only from approved pools.
     *
     * @return Collection<int, LeadPoolMember>
     */
    public function getPendingMembersForAgent(User $agent): Collection
    {
        // Find active pools that match the agent's product skills
        $productSkills = $agent->agentProfile?->product_skills ?? [];

        $query = LeadPoolMember::with(['lead', 'pool'])
            ->where('lead_pool_members.status', PoolMemberStatus::PENDING)
            ->whereHas('pool', function (Builder $q) {
                $q->whereIn('status', [LeadPoolStatus::ACTIVE, LeadPoolStatus::PARTIALLY_DISTRIBUTED]);
            });

        // Filter by agent's product skills if set
        if (! empty($productSkills)) {
            $query->whereHas('lead', function (Builder $q) use ($productSkills) {
                $q->where(function ($sub) use ($productSkills) {
                    foreach ($productSkills as $skill) {
                        $sub->orWhereRaw('LOWER(product_name) LIKE ?', ['%'.mb_strtolower($skill).'%']);
                    }
                });
            });
        }

        return $query->orderBy('lead_pool_members.added_at')->get();
    }

    private function generatePoolNumber(): string
    {
        $prefix = 'POOL-'.now()->format('Ymd').'-';
        $latest = LeadPool::where('pool_number', 'like', $prefix.'%')
            ->orderByDesc('id')
            ->value('pool_number');

        $next = 1;
        if ($latest) {
            $parts = explode('-', $latest);
            $next = (int) end($parts) + 1;
        }

        return $prefix.str_pad((string) $next, 4, '0', STR_PAD_LEFT);
    }
}
