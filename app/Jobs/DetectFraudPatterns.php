<?php

namespace App\Jobs;

use App\Services\FraudDetectionService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class DetectFraudPatterns implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(FraudDetectionService $fraudService): void
    {
        $results = $fraudService->runAllDetections();

        $totalFlags = collect($results)->flatten()->count();

        Log::info("DetectFraudPatterns: Created {$totalFlags} new flags", [
            'suspicious_velocity' => $results['suspicious_velocity']->count(),
            'no_call_outcomes' => $results['no_call_outcomes']->count(),
            'lead_hoarding' => $results['lead_hoarding']->count(),
        ]);
    }
}
