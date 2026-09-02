<?php

namespace App\Providers;

use Modules\Couriers\Events\TrackingStatusUpdated;
use Modules\Couriers\Listeners\SyncOrderFromWaybillStatus;
use Modules\Couriers\Listeners\TriggerSmsOnStatusChange;
use App\Domain\Waybill\Listeners\AutoCreateClaimOnReturn;
use App\Events\ConversationStatusChanged;
use App\Events\LeadAssigned;
use App\Events\LeadCreated;
use App\Listeners\AutoDistributeOnLeadCreated;
use App\Listeners\NotifyOnConversationStatusChanged;
use App\Listeners\UpdateAgentWorkloadOnAssignment;
use Illuminate\Auth\Events\Registered;
use Illuminate\Auth\Listeners\SendEmailVerificationNotification;
use Illuminate\Foundation\Support\Providers\EventServiceProvider as ServiceProvider;
use Illuminate\Support\Facades\Event;

class EventServiceProvider extends ServiceProvider
{
    /**
     * The event to listener mappings for the application.
     *
     * @var array<class-string, array<int, class-string>>
     */
    protected $listen = [
        Registered::class => [
            SendEmailVerificationNotification::class,
        ],
        TrackingStatusUpdated::class => [
            TriggerSmsOnStatusChange::class,
            SyncOrderFromWaybillStatus::class,
            AutoCreateClaimOnReturn::class,
        ],
        LeadAssigned::class => [
            UpdateAgentWorkloadOnAssignment::class,
        ],
        LeadCreated::class => [
            AutoDistributeOnLeadCreated::class,
        ],
        ConversationStatusChanged::class => [
            NotifyOnConversationStatusChanged::class,
        ],
    ];

    /**
     * Register any events for your application.
     */
    public function boot(): void
    {
        //
    }

    /**
     * Determine if events and listeners should be automatically discovered.
     */
    public function shouldDiscoverEvents(): bool
    {
        return false;
    }
}
