<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Inventory\Models\StockAdjustment;
use App\Domain\Procurement\Enums\PrStatus;
use App\Domain\Procurement\Models\PurchaseRequest;
use App\Models\User;
use App\Services\ApprovalService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ApprovalsController extends Controller
{
    public function __construct(private readonly ApprovalService $approval) {}

    public function index(Request $request): Response
    {
        $user = $request->user();
        $settings = $this->approval->getAllSettings();

        $prRoles = array_filter(array_map('trim', explode(',', $settings['pr_approver_roles'] ?? '')));
        $adjRoles = array_filter(array_map('trim', explode(',', $settings['adjustment_approver_roles'] ?? '')));
        $prDesignated = (int) ($settings['pr_approver_user_id'] ?? 0);
        $adjDesignated = (int) ($settings['adjustment_approver_user_id'] ?? 0);

        $canApprovePr = in_array($user->role, $prRoles) || $user->id === $prDesignated;
        $canApproveAdj = in_array($user->role, $adjRoles) || $user->id === $adjDesignated;

        $pendingPrs = $canApprovePr
            ? PurchaseRequest::with(['requester:id,name', 'items'])
                ->where('status', PrStatus::SUBMITTED)
                ->latest()
                ->get()
                ->map(fn ($pr) => [
                    'id' => $pr->id,
                    'pr_number' => $pr->pr_number,
                    'requester' => $pr->requester?->name,
                    'department' => $pr->department,
                    'priority' => $pr->priority,
                    'estimated_total' => $pr->estimated_total,
                    'needed_by_date' => $pr->needed_by_date?->toDateString(),
                    'reason' => $pr->reason,
                    'items_count' => $pr->items->count(),
                    'created_at' => $pr->created_at?->diffForHumans(),
                ])
            : collect();

        $pendingAdj = $canApproveAdj
            ? StockAdjustment::with(['product:id,sku,name', 'supply:id,sku,name', 'warehouse:id,name', 'submittedBy:id,name'])
                ->where('status', 'PENDING')
                ->latest()
                ->get()
                ->map(fn ($adj) => [
                    'id' => $adj->id,
                    'item_name' => $adj->product?->name ?? $adj->supply?->name ?? 'Unknown',
                    'item_sku' => $adj->product?->sku ?? $adj->supply?->sku ?? '',
                    'warehouse' => $adj->warehouse?->name,
                    'quantity_before' => $adj->quantity_before,
                    'quantity_after' => $adj->quantity_after,
                    'variance' => $adj->variance,
                    'reason_code' => $adj->reason_code,
                    'reason_notes' => $adj->reason_notes,
                    'submitted_by' => $adj->submittedBy?->name,
                    'created_at' => $adj->created_at?->diffForHumans(),
                ])
            : collect();

        return Inertia::render('Approvals/Index', [
            'pending_prs' => $pendingPrs,
            'pending_adjustments' => $pendingAdj,
            'can_approve_pr' => $canApprovePr,
            'can_approve_adj' => $canApproveAdj,
            'approval_settings' => $settings,
            'all_users' => User::where('is_active', true)
                ->orderBy('name')
                ->get(['id', 'name', 'role']),
        ]);
    }

    public function updateSettings(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'pr_approver_roles' => 'nullable|string',
            'pr_approver_user_id' => 'nullable|integer|exists:users,id',
            'po_approver_roles' => 'nullable|string',
            'po_approver_user_id' => 'nullable|integer|exists:users,id',
            'adjustment_approver_roles' => 'nullable|string',
            'adjustment_approver_user_id' => 'nullable|integer|exists:users,id',
        ]);

        foreach ($data as $key => $value) {
            $this->approval->setSetting($key, $value ? (string) $value : null);
        }

        return back()->with('success', 'Approval settings updated.');
    }
}
