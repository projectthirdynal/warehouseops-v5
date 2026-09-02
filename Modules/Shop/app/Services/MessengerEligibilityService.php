<?php

declare(strict_types=1);

namespace Modules\Shop\Services;

use Carbon\Carbon;
use Modules\Shop\Models\Conversation;

class MessengerEligibilityService
{
    private const RESPONSE_WINDOW_HOURS = 24;

    public function canSendResponse(Conversation $conversation): bool
    {
        $expiresAt = $this->standardWindowExpiresAt($conversation);

        if ($expiresAt === null) {
            return false;
        }

        return now()->lessThan($expiresAt);
    }

    public function standardWindowExpiresAt(Conversation $conversation): ?Carbon
    {
        $lastCustomerMessage = $conversation->last_customer_message_at ?? $conversation->last_message_at;

        if ($lastCustomerMessage === null) {
            return null;
        }

        return Carbon::parse($lastCustomerMessage)->addHours(self::RESPONSE_WINDOW_HOURS);
    }

    public function allowedMessageMethods(Conversation $conversation): array
    {
        if ($this->canSendResponse($conversation)) {
            return ['RESPONSE'];
        }

        return [];
    }

    public function reason(Conversation $conversation): string
    {
        if ($this->canSendResponse($conversation)) {
            $expiresAt = $this->standardWindowExpiresAt($conversation);
            $remaining = now()->diff($expiresAt);
            $h = $remaining->h;
            $m = $remaining->i;

            return "Response window ends in {$h}h {$m}m";
        }

        $expiresAt = $this->standardWindowExpiresAt($conversation);

        if ($expiresAt === null) {
            return 'No customer message received — response window not available.';
        }

        return 'The standard response window has expired.';
    }

    public function updateConversationWindow(Conversation $conversation): void
    {
        $conversation->forceFill([
            'response_window_expires_at' => $this->standardWindowExpiresAt($conversation),
        ])->save();
    }
}
