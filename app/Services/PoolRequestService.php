<?php

declare(strict_types=1);

namespace App\Services;

use App\Domain\Lead\Enums\PoolRequestStatus;
use App\Domain\Lead\Models\LeadPool;
use App\Domain\Lead\Models\LeadPoolRequest;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Manages the lead pool request lifecycle: creation, approval, rejection, cancellation.
 *
 * Does NOT reserve leads at request time — that happens in PoolReservationService
 * only after admin approval.
 */
class PoolRequestService
{
    public function __construct(
        private LeadEligibilityService $eligibilityService
    ) {}

    /**
     * Create a new pool request with a live availability snapshot.
     *
     * @param  array{brand_name: string, product_name?: ?string, business_region?: ?string, province?: ?string, city?: ?string, lead_age_from?: ?int, lead_age_to?: ?int, requested_quantity: int, distribution_method?: string, team_id?: ?int, notes?: ?string}  $data
     */
    public function createRequest(array $data, User $requestedBy): LeadPoolRequest
    {
        $filters = $this->buildFilters($data);
        $availableNow = $this->eligibilityService->countEligible($filters);

        return LeadPoolRequest::create([
            'requested_by' => $requestedBy->id,
            'team_id' => $data['team_id'] ?? null,
            'brand_name' => $data['brand_name'],
            'product_name' => $data['product_name'] ?? null,
            'business_region' => $data['business_region'] ?? null,
            'province' => $data['province'] ?? null,
            'city' => $data['city'] ?? null,
            'lead_age_from' => $data['lead_age_from'] ?? 0,
            'lead_age_to' => $data['lead_age_to'] ?? null,
            'requested_quantity' => $data['requested_quantity'],
            'available_quantity_at_request' => $availableNow,
            'distribution_method' => $data['distribution_method'] ?? 'equal',
            'status' => PoolRequestStatus::PENDING_APPROVAL,
            'notes' => $data['notes'] ?? null,
        ]);
    }

    /**
     * Approve a pool request, optionally with a modified approved quantity.
     *
     * Triggers PoolReservationService to lock and reserve leads transactionally.
     *
     * @return array{request: LeadPoolRequest, pool: LeadPool, reserved: int}
     *
     * @throws \RuntimeException if the request is not pending or available inventory is insufficient.
     */
    public function approveRequest(
        LeadPoolRequest $request,
        User $approver,
        ?int $approvedQuantity = null,
        ?string $notes = null
    ): array {
        if ($request->status !== PoolRequestStatus::PENDING_APPROVAL) {
            throw new \RuntimeException("Request {$request->request_number} is not pending approval (current: {$request->status->value}).");
        }

        $qty = $approvedQuantity ?? $request->requested_quantity;

        if ($qty < 1) {
            throw new \RuntimeException('Approved quantity must be at least 1.');
        }

        // Recalculate live availability
        $currentAvailable = $this->eligibilityService->countEligible($request->toEligibilityFilters());

        if ($currentAvailable < $qty) {
            throw new \RuntimeException(
                "Insufficient eligible leads. Requested: {$qty}, Currently available: {$currentAvailable}."
            );
        }

        return DB::transaction(function () use ($request, $approver, $qty, $notes) {
            // Update the request
            $request->update([
                'status' => PoolRequestStatus::APPROVED,
                'approved_quantity' => $qty,
                'approved_by' => $approver->id,
                'approved_at' => now(),
                'notes' => $notes ? ($request->notes."\n".$notes) : $request->notes,
            ]);

            // Reserve leads and create the pool
            $reservationService = app(PoolReservationService::class);
            $result = $reservationService->reserveAndCreatePool($request, $approver);

            return [
                'request' => $request->fresh(),
                'pool' => $result['pool'],
                'reserved' => $result['reserved'],
            ];
        });
    }

    /**
     * Reject a pool request with a reason.
     */
    public function rejectRequest(LeadPoolRequest $request, User $rejecter, string $reason): LeadPoolRequest
    {
        if ($request->status !== PoolRequestStatus::PENDING_APPROVAL) {
            throw new \RuntimeException("Request {$request->request_number} is not pending approval.");
        }

        $request->update([
            'status' => PoolRequestStatus::REJECTED,
            'rejected_by' => $rejecter->id,
            'rejected_at' => now(),
            'rejection_reason' => $reason,
        ]);

        return $request->fresh();
    }

    /**
     * Cancel a pool request (by the original requester or an admin).
     */
    public function cancelRequest(LeadPoolRequest $request, User $actor, ?string $reason = null): LeadPoolRequest
    {
        if (! in_array($request->status, [PoolRequestStatus::DRAFT, PoolRequestStatus::PENDING_APPROVAL])) {
            throw new \RuntimeException("Request {$request->request_number} cannot be cancelled in its current state ({$request->status->value}).");
        }

        $request->update([
            'status' => PoolRequestStatus::CANCELLED,
            'rejection_reason' => $reason ? "Cancelled: {$reason}" : 'Cancelled by '.$actor->name,
        ]);

        return $request->fresh();
    }

    /**
     * Get the current available count for a request (recalculated live).
     */
    public function recalculateAvailability(LeadPoolRequest $request): int
    {
        return $this->eligibilityService->countEligible($request->toEligibilityFilters());
    }

    /**
     * Get pending requests for the approval queue.
     *
     * @return Collection<int, LeadPoolRequest>
     */
    public function getPendingRequests(): Collection
    {
        return LeadPoolRequest::with(['requestedBy', 'pool'])
            ->pending()
            ->orderBy('created_at')
            ->get();
    }

    /**
     * Get requests created by a specific user.
     *
     * @return Collection<int, LeadPoolRequest>
     */
    public function getRequestsByUser(User $user): Collection
    {
        return LeadPoolRequest::with(['requestedBy', 'approvedBy', 'pool'])
            ->where('requested_by', $user->id)
            ->orderByDesc('created_at')
            ->get();
    }

    /**
     * @param  array{brand_name: string, product_name?: ?string, business_region?: ?string, province?: ?string, city?: ?string, lead_age_from?: ?int, lead_age_to?: ?int}  $data
     * @return array{brand?: string, product?: string, business_region?: string, province?: string, city?: string, age_from?: int, age_to?: int}
     */
    private function buildFilters(array $data): array
    {
        $filters = [];

        if (! empty($data['brand_name'])) {
            $filters['brand'] = $data['brand_name'];
        }
        if (! empty($data['product_name'])) {
            $filters['product'] = $data['product_name'];
        }
        if (! empty($data['business_region'])) {
            $filters['business_region'] = $data['business_region'];
        }
        if (! empty($data['province'])) {
            $filters['province'] = $data['province'];
        }
        if (! empty($data['city'])) {
            $filters['city'] = $data['city'];
        }
        if (isset($data['lead_age_from'])) {
            $filters['age_from'] = (int) $data['lead_age_from'];
        }
        if (isset($data['lead_age_to'])) {
            $filters['age_to'] = (int) $data['lead_age_to'];
        }

        return $filters;
    }
}
