<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Waybill\Enums\ClaimStatus;
use App\Domain\Waybill\Enums\ClaimType;
use App\Domain\Waybill\Models\Claim;
use App\Domain\Waybill\Models\Waybill;
use Illuminate\Http\Request;
use Inertia\Inertia;

class ClaimController extends Controller
{
    public function index(Request $request)
    {
        $claims = Claim::with(['waybill', 'filedBy'])
            ->when($request->status, fn ($q, $v) => $q->where('status', $v))
            ->when($request->type, fn ($q, $v) => $q->where('type', $v))
            ->when($request->from, fn ($q, $v) => $q->where('filed_at', '>=', $v))
            ->when($request->to, fn ($q, $v) => $q->where('filed_at', '<=', $v . ' 23:59:59'))
            ->when($request->search, function ($q, $v) {
                $q->where('claim_number', 'ILIKE', "%{$v}%")
                    ->orWhereHas('waybill', fn ($wq) => $wq->where('waybill_number', 'ILIKE', "%{$v}%"));
            })
            ->latest()
            ->paginate(20)
            ->withQueryString();

        $stats = [
            'total'          => Claim::count(),
            'draft'          => Claim::where('status', ClaimStatus::DRAFT->value)->count(),
            'pending_review' => Claim::whereIn('status', [ClaimStatus::FILED->value, ClaimStatus::UNDER_REVIEW->value])->count(),
            'approved'       => Claim::whereIn('status', [ClaimStatus::APPROVED->value, ClaimStatus::SETTLED->value])->count(),
            'rejected'       => Claim::where('status', ClaimStatus::REJECTED->value)->count(),
        ];

        return Inertia::render('Waybills/Claims/Index', [
            'claims'  => $claims,
            'stats'   => $stats,
            'filters' => $request->only(['status', 'type', 'search', 'from', 'to']),
        ]);
    }

    public function create(Request $request)
    {
        $waybill = null;
        if ($request->waybill_id) {
            $waybill = Waybill::find($request->waybill_id, ['id', 'waybill_number', 'receiver_name', 'amount', 'status']);
        }

        return Inertia::render('Waybills/Claims/Create', [
            'prefill_waybill' => $waybill,
            'prefill_type'    => $request->type,
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'waybill_id'   => 'required|exists:waybills,id',
            'type'         => 'required|in:LOST,DAMAGED,BEYOND_SLA',
            'description'  => 'nullable|string|max:2000',
            'claim_amount' => 'required|numeric|min:0',
        ]);

        $claim = Claim::create([
            'claim_number' => Claim::generateClaimNumber(),
            'status'       => ClaimStatus::DRAFT->value,
            'filed_by'     => $request->user()->id,
            ...$data,
        ]);

        return redirect()
            ->route('waybills.claims.show', $claim)
            ->with('success', "Claim {$claim->claim_number} created.");
    }

    public function show(Claim $claim)
    {
        $claim->load(['waybill', 'filedBy', 'reviewedBy']);

        return Inertia::render('Waybills/Claims/Show', [
            'claim' => $claim,
        ]);
    }

    public function file(Claim $claim)
    {
        if ($claim->status !== ClaimStatus::DRAFT) {
            return back()->with('error', 'Only draft claims can be filed.');
        }

        $claim->update([
            'status'   => ClaimStatus::FILED->value,
            'filed_at' => now(),
        ]);

        return back()->with('success', 'Claim filed and submitted for review.');
    }

    public function approve(Request $request, Claim $claim)
    {
        if (! in_array($claim->status, [ClaimStatus::FILED, ClaimStatus::UNDER_REVIEW])) {
            return back()->with('error', 'Only filed or under-review claims can be approved.');
        }

        $data = $request->validate([
            'approved_amount'      => 'required|numeric|min:0',
            'jnt_reference_number' => 'nullable|string|max:100',
            'resolution_notes'     => 'nullable|string|max:2000',
        ]);

        $claim->update([
            'status'               => ClaimStatus::APPROVED->value,
            'approved_amount'      => $data['approved_amount'],
            'jnt_reference_number' => $data['jnt_reference_number'] ?? null,
            'resolution_notes'     => $data['resolution_notes'] ?? null,
            'reviewed_by'          => $request->user()->id,
            'reviewed_at'          => now(),
            'resolved_at'          => now(),
        ]);

        return back()->with('success', 'Claim approved.');
    }

    public function reject(Request $request, Claim $claim)
    {
        if (! in_array($claim->status, [ClaimStatus::FILED, ClaimStatus::UNDER_REVIEW])) {
            return back()->with('error', 'Only filed or under-review claims can be rejected.');
        }

        $data = $request->validate([
            'resolution_notes' => 'required|string|max:2000',
        ]);

        $claim->update([
            'status'           => ClaimStatus::REJECTED->value,
            'resolution_notes' => $data['resolution_notes'],
            'reviewed_by'      => $request->user()->id,
            'reviewed_at'      => now(),
            'resolved_at'      => now(),
        ]);

        return back()->with('success', 'Claim rejected.');
    }

    public function settle(Claim $claim)
    {
        if ($claim->status !== ClaimStatus::APPROVED) {
            return back()->with('error', 'Only approved claims can be settled.');
        }

        $claim->update([
            'status'      => ClaimStatus::SETTLED->value,
            'resolved_at' => now(),
        ]);

        return back()->with('success', 'Claim marked as settled.');
    }

    public function approved(Request $request)
    {
        $claims = Claim::with(['waybill', 'filedBy', 'reviewedBy'])
            ->whereIn('status', [ClaimStatus::APPROVED->value, ClaimStatus::SETTLED->value])
            ->when($request->from, fn ($q, $v) => $q->where('resolved_at', '>=', $v))
            ->when($request->to, fn ($q, $v) => $q->where('resolved_at', '<=', $v . ' 23:59:59'))
            ->when($request->search, function ($q, $v) {
                $q->where('claim_number', 'ILIKE', "%{$v}%")
                    ->orWhereHas('waybill', fn ($wq) => $wq->where('waybill_number', 'ILIKE', "%{$v}%"));
            })
            ->latest('resolved_at')
            ->paginate(20)
            ->withQueryString();

        $totals = [
            'total_claimed'  => Claim::whereIn('status', [ClaimStatus::APPROVED->value, ClaimStatus::SETTLED->value])->sum('claim_amount'),
            'total_approved' => Claim::whereIn('status', [ClaimStatus::APPROVED->value, ClaimStatus::SETTLED->value])->sum('approved_amount'),
            'approved_count' => Claim::where('status', ClaimStatus::APPROVED->value)->count(),
            'settled_count'  => Claim::where('status', ClaimStatus::SETTLED->value)->count(),
        ];

        return Inertia::render('Waybills/Claims/Approved', [
            'claims'  => $claims,
            'totals'  => $totals,
            'filters' => $request->only(['search', 'from', 'to']),
        ]);
    }

    public function beyondSla(Request $request)
    {
        // SLA rule: returned on day D → must be received by end of day D+1 (midnight).
        // Cutoff is start of yesterday Manila time, so returned-yesterday items still have today.
        $slaCutoff = now()->setTimezone('Asia/Manila')->startOfDay()->subDay()->utc();

        $query = Waybill::where('status', 'RETURNED')
            ->where('returned_at', '<', $slaCutoff)
            ->whereDoesntHave('returnReceipt')
            ->when($request->from, fn ($q, $v) => $q->where('returned_at', '>=', $v))
            ->when($request->to, fn ($q, $v) => $q->where('returned_at', '<=', $v . ' 23:59:59'))
            ->when($request->search, function ($q, $v) {
                $q->where('waybill_number', 'ILIKE', "%{$v}%")
                    ->orWhere('receiver_name', 'ILIKE', "%{$v}%");
            })
            ->with(['claims'])
            ->latest('returned_at');

        $waybills       = (clone $query)->paginate(30)->withQueryString();
        $beyondSlaCount = (clone $query)->count();

        return Inertia::render('Waybills/Claims/BeyondSla', [
            'waybills'         => $waybills,
            'beyond_sla_count' => $beyondSlaCount,
            'filters'          => $request->only(['search', 'from', 'to']),
        ]);
    }
}
