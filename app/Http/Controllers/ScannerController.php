<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Waybill\Models\ReturnReceipt;
use App\Domain\Waybill\Models\UnknownWaybillScan;
use App\Models\Waybill;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class ScannerController extends Controller
{
    public function index(): \Inertia\Response
    {
        return Inertia::render('Scanner/Index');
    }

    /**
     * Validate and record a single scan.
     * Mode: validate | dispatch | receive_return
     */
    public function scan(Request $request): JsonResponse
    {
        $data = $request->validate([
            'waybill_number' => 'required|string|max:60',
            'session_id'     => 'required|string|max:36',
            'mode'           => 'sometimes|in:validate,dispatch,receive_return',
        ]);

        $number    = strtoupper(trim($data['waybill_number']));
        $sessionId = $data['session_id'];
        $mode      = $data['mode'] ?? 'validate';

        // Idempotency — same waybill in same session within 60s = duplicate
        $cacheKey = "scan:{$sessionId}:{$number}";
        if (Cache::has($cacheKey)) {
            return response()->json([
                'status'         => 'duplicate',
                'waybill_number' => $number,
                'message'        => 'Already scanned in this session.',
            ]);
        }
        Cache::put($cacheKey, true, 60);

        $waybill = Waybill::where('waybill_number', $number)
            ->select(['id', 'waybill_number', 'status', 'receiver_name', 'city', 'returned_at', 'amount', 'cod_amount'])
            ->first();

        if (! $waybill) {
            $existsInSession = UnknownWaybillScan::where('waybill_no', $number)
                ->where('scan_session_id', $sessionId)
                ->exists();

            if (! $existsInSession) {
                UnknownWaybillScan::create([
                    'waybill_no'        => $number,
                    'scanned_by'        => $request->user()->id,
                    'scanned_at'        => now(),
                    'scan_session_id'   => $sessionId,
                    'resolution_status' => 'PENDING',
                ]);
            }

            return response()->json([
                'status'         => 'unknown',
                'waybill_number' => $number,
                'message'        => 'Waybill not found in system.',
            ]);
        }

        $currentStatus = is_string($waybill->status) ? $waybill->status : $waybill->status->value;
        $slaCutoff   = now()->setTimezone('Asia/Manila')->startOfDay()->subDay()->utc();

        $isBeyondSla = $currentStatus === 'RETURNED'
            && $waybill->returned_at !== null
            && $waybill->returned_at < $slaCutoff
            && ! ReturnReceipt::where('waybill_id', $waybill->id)->exists();

        $actionTaken = null;

        if ($mode === 'dispatch') {
            if ($currentStatus !== 'PENDING') {
                return response()->json([
                    'status'         => 'wrong_status',
                    'waybill_number' => $number,
                    'waybill'        => $waybill,
                    'message'        => "Cannot dispatch — status is {$currentStatus}.",
                ]);
            }
            DB::table('waybills')->where('id', $waybill->id)->update([
                'status'        => 'DISPATCHED',
                'dispatched_at' => now(),
                'updated_at'    => now(),
            ]);
            $actionTaken = 'dispatched';

        } elseif ($mode === 'receive_return') {
            if ($currentStatus !== 'RETURNED') {
                return response()->json([
                    'status'         => 'wrong_status',
                    'waybill_number' => $number,
                    'waybill'        => $waybill,
                    'message'        => "Cannot receive — status is {$currentStatus}, expected RETURNED.",
                ]);
            }

            if (ReturnReceipt::where('waybill_id', $waybill->id)->exists()) {
                return response()->json([
                    'status'         => 'already_processed',
                    'waybill_number' => $number,
                    'waybill'        => $waybill,
                    'message'        => 'Return already received.',
                ]);
            }

            ReturnReceipt::create([
                'waybill_id' => $waybill->id,
                'scanned_by' => $request->user()->id,
                'scanned_at' => now(),
                'condition'  => 'GOOD',
            ]);
            $actionTaken = 'return_received';
            $isBeyondSla = false;
        }

        return response()->json([
            'status'         => $isBeyondSla ? 'beyond_sla' : 'ok',
            'waybill_number' => $number,
            'waybill'        => $waybill,
            'action_taken'   => $actionTaken,
            'message'        => $isBeyondSla
                ? 'Returned but not received by next day — Beyond SLA.'
                : 'Waybill found.',
        ]);
    }

    public function batchScan(Request $request): JsonResponse
    {
        $data = $request->validate([
            'scans'                  => 'required|array|max:50',
            'scans.*.waybill_number' => 'required|string|max:60',
            'session_id'             => 'required|string|max:36',
            'mode'                   => 'sometimes|in:validate,dispatch,receive_return',
        ]);

        $results = [];
        foreach ($data['scans'] as $scan) {
            $singleRequest = Request::create('/waybills/scan', 'POST', [
                'waybill_number' => $scan['waybill_number'],
                'session_id'     => $data['session_id'],
                'mode'           => $data['mode'] ?? 'validate',
            ]);
            $singleRequest->setUserResolver($request->getUserResolver());

            $response  = $this->scan($singleRequest);
            $results[] = json_decode($response->getContent(), true);
        }

        return response()->json(['results' => $results]);
    }
}
