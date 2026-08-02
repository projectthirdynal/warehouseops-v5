<?php

declare(strict_types=1);

namespace App\Domain\Courier\Jobs;

use App\Domain\Courier\Services\BatchDispatchService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class BatchDispatchJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 300;

    public int $tries = 1;

    /**
     * @param  array<int>  $waybillIds
     */
    public function __construct(
        public array $waybillIds,
        public string $courierCode,
        public array $senderDefaults = [],
    ) {}

    public function handle(BatchDispatchService $service): void
    {
        Log::info('BatchDispatchJob started', [
            'courier' => $this->courierCode,
            'count' => count($this->waybillIds),
        ]);

        $result = $service->dispatch($this->waybillIds, $this->courierCode, $this->senderDefaults);

        // Store result in cache for frontend polling
        $cacheKey = "batch_dispatch_result:{$this->courierCode}:".md5(implode(',', $this->waybillIds));
        cache()->put($cacheKey, $result, now()->addMinutes(30));

        Log::info('BatchDispatchJob completed', [
            'courier' => $this->courierCode,
            'success' => $result['success'],
            'failed' => $result['failed'],
            'total' => $result['total'],
        ]);
    }
}
