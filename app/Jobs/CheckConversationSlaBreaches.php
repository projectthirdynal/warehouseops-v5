<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Domain\Shop\Services\ConversationSlaService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class CheckConversationSlaBreaches implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public int $limit = 200)
    {
        //
    }

    public function handle(ConversationSlaService $slaService): int
    {
        return $slaService->checkBreachAlerts($this->limit);
    }
}
