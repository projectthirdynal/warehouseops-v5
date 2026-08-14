<?php

declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

class PoolCapacityAlertNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly string $level,
        public readonly int $count,
        public readonly int $threshold,
        public readonly ?string $source = null,
    ) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toDatabase(object $notifiable): array
    {
        $scope = $this->source ? "source \"{$this->source}\"" : 'the lead pool';

        $message = $this->level === 'low'
            ? "Available leads for {$scope} dropped to {$this->count} (below threshold of {$this->threshold})."
            : "Unassigned leads for {$scope} reached {$this->count} (above threshold of {$this->threshold}). Consider distributing.";

        return [
            'type' => 'pool_capacity_'.$this->level,
            'title' => $this->level === 'low' ? 'Lead Pool Running Low' : 'Lead Pool Overstocked',
            'message' => $message,
            'url' => '/lead-pool',
            'meta' => [
                'level' => $this->level,
                'count' => $this->count,
                'threshold' => $this->threshold,
                'source' => $this->source,
            ],
        ];
    }
}
