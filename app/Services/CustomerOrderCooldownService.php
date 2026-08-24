<?php

declare(strict_types=1);

namespace App\Services;

use App\Domain\Courier\Services\DeliveryEtaService;
use App\Domain\Lead\Enums\PoolStatus;
use App\Domain\Lead\Models\Lead;
use App\Domain\Order\Enums\OrderStatus;
use App\Domain\Order\Models\Order;
use App\Models\Customer;
use Carbon\Carbon;

/**
 * Prevents telesales calls to customers who are still waiting for an active
 * order to arrive. A customer becomes callable again only after the order is
 * delivered (or its estimated delivery date passes) plus a 2-day buffer.
 *
 * The 2-day buffer is fixed; the ETA is derived from the courier shipping_days
 * table by province/city/barangay, or falls back to island-group defaults.
 */
class CustomerOrderCooldownService
{
    private const POST_DELIVERY_BUFFER_DAYS = 2;

    public function __construct(
        private DeliveryEtaService $etaService,
        private LeadPoolService $poolService,
    ) {}

    /**
     * Compute the earliest date on which a customer with this order can be
     * called again. Returns null when the order should not block calling.
     */
    public function qualifyAt(Order $order): ?Carbon
    {
        // Terminal orders that are not delivered should not block.
        if (in_array($order->status, [OrderStatus::CANCELLED, OrderStatus::RETURNED, OrderStatus::QA_REJECTED], true)) {
            return null;
        }

        // Delivered orders: 2 days after actual delivery.
        if ($order->status === OrderStatus::DELIVERED && $order->delivered_at !== null) {
            return $order->delivered_at->copy()->addDays(self::POST_DELIVERY_BUFFER_DAYS);
        }

        // If scheduled delivery is already known, use that plus buffer.
        if ($order->scheduled_delivery_at !== null) {
            return $order->scheduled_delivery_at->copy()->addDays(self::POST_DELIVERY_BUFFER_DAYS);
        }

        // Otherwise estimate from confirmed/created date + regional ETA + buffer.
        $startAt = $order->confirmed_at ?? $order->created_at;
        if ($startAt === null) {
            return null;
        }

        $eta = $this->etaService->estimateEta(
            $order->state,
            $order->city,
            $order->barangay,
        );

        $estimatedDelivery = $startAt->copy()->addWeekdays($eta['eta_days'] ?? 5);

        return $estimatedDelivery->addDays(self::POST_DELIVERY_BUFFER_DAYS);
    }

    /**
     * Check whether a customer is currently blocked by any active order and
     * return the latest re-qualify date plus the most restrictive order.
     *
     * @return array{blocked: bool, until: ?Carbon, reason: ?string, order_id: ?int}
     */
    public function forCustomer(Customer $customer): array
    {
        $activeStatuses = [
            OrderStatus::PENDING,
            OrderStatus::CONFIRMED,
            OrderStatus::QA_PENDING,
            OrderStatus::QA_APPROVED,
            OrderStatus::PROCESSING,
            OrderStatus::DISPATCHED,
            OrderStatus::ON_HOLD,
            OrderStatus::DELIVERED,
        ];

        $orders = Order::where('customer_id', $customer->id)
            ->whereIn('status', $activeStatuses)
            ->get();

        $latest = null;
        $latestOrder = null;

        foreach ($orders as $order) {
            $qualifyAt = $this->qualifyAt($order);
            if ($qualifyAt === null) {
                continue;
            }

            if ($latest === null || $qualifyAt->isAfter($latest)) {
                $latest = $qualifyAt;
                $latestOrder = $order;
            }
        }

        if ($latest === null || $latest->isPast()) {
            return ['blocked' => false, 'until' => null, 'reason' => null, 'order_id' => null];
        }

        return [
            'blocked' => true,
            'until' => $latest,
            'reason' => $this->cooldownReason($latestOrder, $latest),
            'order_id' => $latestOrder?->id,
        ];
    }

    /**
     * Recompute a lead's order-based cooldown and update its pool status.
     *
     * Returns the re-qualify date (if the customer is still blocked), or null
     * if no active order is blocking the lead.
     */
    public function refreshLead(Lead $lead): ?Carbon
    {
        $customer = $lead->customer;
        if ($customer === null) {
            return null;
        }

        $cooldown = $this->forCustomer($customer);

        if ($cooldown['blocked']) {
            $this->poolService->markAsCooldownUntil($lead, $cooldown['until'], [
                'order_id' => $cooldown['order_id'],
                'reason' => $cooldown['reason'],
            ]);

            return $cooldown['until'];
        }

        if ($lead->pool_status === PoolStatus::COOLDOWN) {
            $this->poolService->markAsAvailable($lead);
        }

        return null;
    }

    /**
     * Apply a cooldown to a lead using a specific order's re-qualify date.
     */
    public function applyOrderCooldown(Lead $lead, Order $order): ?Carbon
    {
        $qualifyAt = $this->qualifyAt($order);

        if ($qualifyAt !== null && $qualifyAt->isFuture()) {
            $this->poolService->markAsCooldownUntil($lead, $qualifyAt, [
                'order_id' => $order->id,
                'reason' => $this->cooldownReason($order, $qualifyAt),
            ]);

            return $qualifyAt;
        }

        return null;
    }

    /**
     * Find all non-exhausted leads for a customer and sync their order-based
     * cooldown. This is useful when an order is created or updated outside of
     * the telesales lead flow (e.g. shop/pos orders).
     */
    public function syncCustomerLeads(Customer $customer): void
    {
        $cooldown = $this->forCustomer($customer);

        $leads = Lead::where('customer_id', $customer->id)
            ->where('pool_status', '!=', PoolStatus::EXHAUSTED)
            ->get();

        foreach ($leads as $lead) {
            if ($cooldown['blocked']) {
                $this->poolService->markAsCooldownUntil($lead, $cooldown['until'], [
                    'order_id' => $cooldown['order_id'],
                    'reason' => $cooldown['reason'],
                ]);
            } elseif ($lead->pool_status === PoolStatus::COOLDOWN) {
                $this->poolService->markAsAvailable($lead);
            }
        }
    }

    private function cooldownReason(?Order $order, Carbon $until): ?string
    {
        if ($order === null) {
            return null;
        }

        if ($order->status === OrderStatus::DELIVERED) {
            return "Delivered order {$order->order_number}; re-qualifies on {$until->format('M d, Y')}";
        }

        return "Active order {$order->order_number} for {$order->state} / {$order->city}; re-qualifies on {$until->format('M d, Y')}";
    }
}
