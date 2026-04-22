<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Waybill\Models\ReturnReceipt;
use App\Domain\Waybill\Models\Waybill;
use Illuminate\Http\Request;

class ReturnReceiptController extends Controller
{
    public function store(Request $request)
    {
        $request->validate([
            'waybill_numbers' => 'required|string',
            'condition'       => 'required|in:GOOD,DAMAGED',
            'notes'           => 'nullable|string|max:1000',
        ]);

        // Split by newlines, commas, or whitespace — supports paste from spreadsheet or scanner
        $numbers = array_values(array_filter(
            array_map('trim', preg_split('/[\r\n,\s]+/', $request->waybill_numbers))
        ));

        if (empty($numbers)) {
            return back()->with('error', 'No waybill numbers provided.');
        }

        $allFound     = Waybill::whereIn('waybill_number', $numbers)->get()->keyBy('waybill_number');
        $alreadyDone  = ReturnReceipt::whereHas('waybill', fn ($q) => $q->whereIn('waybill_number', $numbers))
            ->with('waybill:id,waybill_number')
            ->get()
            ->keyBy(fn ($r) => $r->waybill->waybill_number);

        $results = [
            'scanned'          => [],
            'already_received' => [],
            'not_found'        => [],
            'wrong_status'     => [],
        ];

        foreach ($numbers as $number) {
            if (! isset($allFound[$number])) {
                $results['not_found'][] = $number;
                continue;
            }

            $waybill = $allFound[$number];

            if ($waybill->status->value !== 'RETURNED') {
                $results['wrong_status'][] = $number;
                continue;
            }

            if (isset($alreadyDone[$number])) {
                $results['already_received'][] = $number;
                continue;
            }

            ReturnReceipt::create([
                'waybill_id' => $waybill->id,
                'scanned_by' => $request->user()->id,
                'scanned_at' => now(),
                'condition'  => $request->condition,
                'notes'      => $request->notes,
            ]);

            $results['scanned'][] = $number;
        }

        return back()->with('scan_results', $results);
    }
}
