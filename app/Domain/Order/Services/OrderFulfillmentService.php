<?php

declare(strict_types=1);

namespace App\Domain\Order\Services;

use Modules\Couriers\Actions\CreateCourierOrder;
use App\Domain\Finance\Services\CogsService;
use App\Domain\Finance\Services\CommissionService;
use App\Domain\Finance\Services\QboSyncService;
use App\Domain\Finance\Services\RevenueService;
use App\Domain\Order\Enums\OrderStatus;
use App\Domain\Order\Models\Order;
use App\Domain\Product\Models\Product;
use App\Domain\Product\Services\InventoryService;
use App\Domain\Shop\Models\Conversation;
use App\Domain\Shop\Models\Message;
use App\Domain\Shop\Services\FacebookConnectorService;
use App\Models\Customer;
use App\Models\Lead;
use App\Models\SiteSetting;
use App\Models\Waybill;
use App\Services\LeadAuditService;
use App\Services\LeadPoolService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class OrderFulfillmentService
{
    public function __construct(
        private InventoryService $inventory,
        private CreateCourierOrder $createCourierOrder,
        private LeadAuditService $auditService,
        private CommissionService $commissionService,
        private RevenueService $revenueService,
        private CogsService $cogsService,
        private QboSyncService $qboSyncService,
        private LeadPoolService $leadPoolService,
        private FacebookConnectorService $facebookConnector,
    ) {}

    /**
     * Create an order from a confirmed lead (after agent marks ORDERED).
     */
    public function createFromLead(Lead $lead, ?string $courierCode = null): Order
    {
        $order = DB::transaction(function () use ($lead, $courierCode) {
            // Find or match product
            $product = $this->matchProduct($lead);
            $variant = null;

            // Determine pricing
            $unitPrice = $product ? (float) $product->selling_price : (float) ($lead->amount ?? 0);
            $quantity = 1;

            // Find or create customer
            $customer = Customer::where('phone', $lead->phone)->first();

            // Create order
            $order = Order::create([
                'order_number' => Order::generateOrderNumber(),
                'lead_id' => $lead->id,
                'customer_id' => $customer?->id,
                'product_id' => $product?->id,
                'variant_id' => $variant?->id,
                'assigned_agent_id' => $lead->assigned_to,
                'status' => OrderStatus::PENDING,
                'courier_code' => $courierCode ?? config('services.couriers.default', 'FLASH'),
                'quantity' => $quantity,
                'unit_price' => $unitPrice,
                'total_amount' => $unitPrice * $quantity,
                'cod_amount' => $unitPrice * $quantity, // COD = total for now
                'receiver_name' => $lead->name,
                'receiver_phone' => $lead->phone,
                'receiver_address' => $lead->address ?? '',
                'city' => $lead->city,
                'state' => $lead->state,
                'barangay' => $lead->barangay,
                'postal_code' => $lead->postal_code ?? null,
            ]);

            // Update lead sales status
            $lead->update(['sales_status' => 'AGENT_CONFIRMED']);

            // Reserve inventory if product exists
            if ($product) {
                try {
                    $this->inventory->reserve(
                        $product->id,
                        $quantity,
                        $variant?->id,
                        Order::class,
                        $order->id,
                    );
                } catch (\RuntimeException $e) {
                    Log::warning("Insufficient stock for order {$order->order_number}", [
                        'product' => $product->id,
                        'error' => $e->getMessage(),
                    ]);
                    // Don't block order — just log the warning
                }
            }

            // Route through QA or skip
            $skipQa = ! ($product?->requires_qa ?? true);
            if ($skipQa) {
                // Approve DB state inside the transaction; defer courier call until after commit.
                $this->approve($order, submitCourier: false);
            } else {
                $order->update(['status' => OrderStatus::QA_PENDING]);
                $lead->update(['sales_status' => 'QA_PENDING']);
            }

            return $order;
        });

        // Submit to courier AFTER the transaction is committed (ISSUE-B / BUG-09).
        // If the order was auto-approved (status = QA_APPROVED), send it now.
        if ($order->status === OrderStatus::QA_APPROVED) {
            $this->submitToCourier($order);
        }

        return $order;
    }

    /**
     * QA approves the order → submit to courier.
     *
     * @param  bool  $submitCourier  Set to false when called from within an existing transaction
     *                               (e.g. createFromLead auto-approve path) to defer courier
     *                               submission until the outer transaction has committed.
     */
    public function approve(Order $order, ?int $approvedBy = null, bool $submitCourier = true): void
    {
        DB::transaction(function () use ($order) {
            $order->update([
                'status' => OrderStatus::QA_APPROVED,
                'confirmed_at' => now(),
            ]);

            if ($order->lead) {
                $order->lead->update(['sales_status' => 'QA_APPROVED']);
            }
        });

        $this->syncOrderStatusToConversation($order, OrderStatus::QA_APPROVED);

        // Courier submission runs OUTSIDE the transaction — prevents the external
        // API call from holding a DB lock and rolling back committed data on timeout.
        if ($submitCourier) {
            $this->submitToCourier($order);
        }
    }

    /**
     * QA rejects the order.
     */
    public function reject(Order $order, string $reason, ?int $rejectedBy = null): void
    {
        DB::transaction(function () use ($order, $reason) {
            $order->update([
                'status' => OrderStatus::QA_REJECTED,
                'rejection_reason' => $reason,
            ]);

            if ($order->lead) {
                $order->lead->update(['sales_status' => 'QA_REJECTED']);
            }

            // Release inventory reservation
            if ($order->product_id) {
                $this->inventory->release(
                    $order->product_id,
                    $order->quantity,
                    $order->variant_id,
                    Order::class,
                    $order->id,
                );
            }
        });

        $this->syncOrderStatusToConversation($order, OrderStatus::QA_REJECTED, $reason);

        Cache::forget('inv_dashboard_stats');
        Cache::forget('inv_dashboard_charts');
    }

    /**
     * Submit approved order to courier API → create waybill.
     */
    public function submitToCourier(Order $order): void
    {
        $order->update(['status' => OrderStatus::PROCESSING]);

        $this->syncOrderStatusToConversation($order, OrderStatus::PROCESSING);

        // Create a waybill record first
        $waybill = Waybill::create([
            'waybill_number' => 'PENDING-'.$order->order_number,
            'status' => 'PENDING',
            'receiver_name' => $order->receiver_name,
            'receiver_phone' => $order->receiver_phone,
            'receiver_address' => $order->receiver_address,
            'city' => $order->city,
            'state' => $order->state,
            'barangay' => $order->barangay,
            'postal_code' => $order->postal_code,
            'item_name' => $order->product?->name ?? 'Package',
            'item_qty' => $order->quantity,
            'amount' => $order->total_amount,
            'cod_amount' => $order->cod_amount,
            'courier_provider' => $order->courier_code ?? 'MANUAL',
            'lead_id' => $order->lead_id,
        ]);

        $order->update(['waybill_id' => $waybill->id]);

        // Call courier API
        $courierCode = $order->courier_code;
        if ($courierCode && $courierCode !== 'MANUAL') {
            try {
                $result = $this->createCourierOrder->execute($waybill, $courierCode);

                if ($result->success) {
                    $order->update([
                        'status' => OrderStatus::DISPATCHED,
                        'dispatched_at' => now(),
                    ]);

                    $this->syncOrderStatusToConversation($order, OrderStatus::DISPATCHED);

                    if ($order->lead) {
                        $order->lead->update(['sales_status' => 'WAYBILL_CREATED']);
                    }
                } else {
                    Log::error("Courier order failed for order {$order->order_number}", [
                        'error' => $result->errorMessage,
                    ]);
                    // Keep as PROCESSING — can retry manually
                }
            } catch (\Exception $e) {
                Log::error("Courier API exception for order {$order->order_number}", [
                    'error' => $e->getMessage(),
                ]);
            }
        } else {
            // Manual dispatch — update lead status
            if ($order->lead) {
                $order->lead->update(['sales_status' => 'WAYBILL_CREATED']);
            }
        }
    }

    /**
     * Handle successful delivery — finalize stock, update customer.
     */
    public function handleDelivery(Order $order): void
    {
        DB::transaction(function () use ($order) {
            $order->update([
                'status' => OrderStatus::DELIVERED,
                'delivered_at' => now(),
            ]);

            // Confirm inventory reservation → actual stock out
            if ($order->product_id) {
                $this->inventory->confirmReservation(
                    $order->product_id,
                    $order->quantity,
                    $order->variant_id,
                    Order::class,
                    $order->id,
                );
            }

            // Update customer stats
            if ($order->customer_id) {
                $customer = $order->customer;
                $customer->increment('total_orders');
                $customer->increment('successful_orders');
                $customer->increment('total_revenue', $order->total_amount);
                $this->recalculateCustomerStats($customer);
            }

            // Record revenue + create agent commission
            $this->revenueService->recordSale($order);
            $this->commissionService->createForOrder($order);

            // Record COGS (FIFO lot consumption) + push journal entry to QBO
            // warehouseId not passed — Order/Waybill models have no warehouse_id column yet.
            // Forward-compatible: when warehouse assignment is added, pass it here to isolate FIFO lots per warehouse.
            if ($order->product_id) {
                try {
                    $cogs = $this->cogsService->record(
                        productId: (int) $order->product_id,
                        variantId: $order->variant_id ? (int) $order->variant_id : null,
                        quantity: (float) $order->quantity,
                        waybillId: $order->waybill_id ? (int) $order->waybill_id : null,
                        orderId: (int) $order->id,
                    );
                    if ($cogs->isNotEmpty()) {
                        $this->qboSyncService->enqueueCogsJournal($cogs, $order->waybill_id ? (int) $order->waybill_id : null);
                    }
                } catch (\Throwable $e) {
                    Log::warning("COGS recording failed for order {$order->order_number}: {$e->getMessage()}");
                }
            }
        });

        $this->syncOrderStatusToConversation($order, OrderStatus::DELIVERED);

        Cache::forget('inv_dashboard_stats');
        Cache::forget('inv_dashboard_charts');
    }

    /**
     * Handle return — release stock, cancel commission, update customer.
     */
    public function handleReturn(Order $order): void
    {
        DB::transaction(function () use ($order) {
            $order->update([
                'status' => OrderStatus::RETURNED,
                'returned_at' => now(),
            ]);

            // Release reservation + return stock
            if ($order->product_id) {
                $this->inventory->release(
                    $order->product_id,
                    $order->quantity,
                    $order->variant_id,
                    Order::class,
                    $order->id,
                );
            }

            // Update customer stats
            if ($order->customer_id) {
                $customer = $order->customer;
                $customer->increment('total_orders');
                $customer->increment('returned_orders');
                $this->recalculateCustomerStats($customer);
            }

            // Record return + cancel commission
            $this->revenueService->recordReturn($order);
            $this->commissionService->cancelForOrder($order);

            // Reverse COGS — return inventory units to their lots
            if ($order->waybill_id) {
                try {
                    $this->cogsService->reverse((int) $order->waybill_id);
                } catch (\Throwable $e) {
                    Log::warning("COGS reversal failed for order {$order->order_number}: {$e->getMessage()}");
                }
            }
        });

        $this->syncOrderStatusToConversation($order, OrderStatus::RETURNED);

        Cache::forget('inv_dashboard_stats');
        Cache::forget('inv_dashboard_charts');
    }

    /**
     * Cancel an order (before dispatch).
     */
    public function cancel(Order $order, ?string $reason = null): void
    {
        DB::transaction(function () use ($order, $reason) {
            $order->update([
                'status' => OrderStatus::CANCELLED,
                'rejection_reason' => $reason,
            ]);

            // Release inventory
            if ($order->product_id) {
                $this->inventory->release(
                    $order->product_id,
                    $order->quantity,
                    $order->variant_id,
                    Order::class,
                    $order->id,
                );
            }

            // Return lead to pool — routes through LeadPoolService so that
            // cache is invalidated and agent workload is decremented (BUG-03).
            if ($order->lead) {
                $order->lead->update(['sales_status' => 'NEW']);
                $this->leadPoolService->markAsAvailable($order->lead);
            }
        });

        $this->syncOrderStatusToConversation($order, OrderStatus::CANCELLED, $reason);

        Cache::forget('inv_dashboard_stats');
        Cache::forget('inv_dashboard_charts');
    }

    /**
     * Split an order: move selected items into a new child order.
     * The parent keeps the remaining items; both orders get recalculated totals.
     */
    public function splitOrder(Order $parentOrder, array $splitItemIds): Order
    {
        $parentOrder->load('shopItems');

        $splitItems = $parentOrder->shopItems->whereIn('id', $splitItemIds);
        $keepItems = $parentOrder->shopItems->whereNotIn('id', $splitItemIds);

        if ($splitItems->isEmpty() || $keepItems->isEmpty()) {
            throw new \InvalidArgumentException('Cannot split: must have at least one item on each side.');
        }

        return DB::transaction(function () use ($parentOrder, $splitItems, $keepItems) {
            $splitTotal = $splitItems->sum(fn ($i) => (float) $i->line_total);
            $splitDiscount = $splitItems->sum(fn ($i) => (float) $i->discount_amount);
            $keepTotal = $keepItems->sum(fn ($i) => (float) $i->line_total);
            $keepDiscount = $keepItems->sum(fn ($i) => (float) $i->discount_amount);

            $splitRatio = $parentOrder->total_amount > 0
                ? $splitTotal / (float) $parentOrder->total_amount
                : 0;

            $childOrder = Order::create([
                'order_number' => Order::generateOrderNumber(),
                'parent_order_id' => $parentOrder->id,
                'lead_id' => $parentOrder->lead_id,
                'conversation_id' => $parentOrder->conversation_id,
                'facebook_page_id' => $parentOrder->facebook_page_id,
                'customer_id' => $parentOrder->customer_id,
                'assigned_agent_id' => $parentOrder->assigned_agent_id,
                'encoder_id' => $parentOrder->encoder_id,
                'status' => OrderStatus::PENDING,
                'courier_code' => $parentOrder->courier_code,
                'quantity' => $splitItems->sum('quantity'),
                'unit_price' => 0,
                'total_amount' => $splitTotal,
                'cod_amount' => round((float) $parentOrder->cod_amount * $splitRatio, 2),
                'shipping_cost' => round((float) $parentOrder->shipping_cost * $splitRatio, 2),
                'discount_amount' => $splitDiscount,
                'tax_rate' => $parentOrder->tax_rate,
                'tax_amount' => round((float) $parentOrder->tax_amount * $splitRatio, 2),
                'receiver_name' => $parentOrder->receiver_name,
                'receiver_phone' => $parentOrder->receiver_phone,
                'receiver_address' => $parentOrder->receiver_address,
                'city' => $parentOrder->city,
                'state' => $parentOrder->state,
                'barangay' => $parentOrder->barangay,
                'postal_code' => $parentOrder->postal_code,
                'address_mapping_id' => $parentOrder->address_mapping_id,
                'source_channel' => $parentOrder->source_channel,
                'notes' => "Split from {$parentOrder->order_number}",
            ]);

            foreach ($splitItems as $item) {
                $item->update(['order_id' => $childOrder->id]);
            }

            $parentOrder->update([
                'total_amount' => $keepTotal,
                'cod_amount' => round((float) $parentOrder->cod_amount - ((float) $parentOrder->cod_amount * $splitRatio), 2),
                'shipping_cost' => round((float) $parentOrder->shipping_cost - ((float) $parentOrder->shipping_cost * $splitRatio), 2),
                'discount_amount' => $keepDiscount,
                'tax_amount' => round((float) $parentOrder->tax_amount - ((float) $parentOrder->tax_amount * $splitRatio), 2),
                'quantity' => $keepItems->sum('quantity'),
            ]);

            $this->syncOrderStatusToConversation($childOrder, OrderStatus::PENDING, "Split from {$parentOrder->order_number}");

            return $childOrder;
        });
    }

    /**
     * Try to match a product from lead's product_name field.
     */
    private function matchProduct(Lead $lead): ?Product
    {
        if (empty($lead->product_name)) {
            return null;
        }

        return Product::where('is_active', true)
            ->where(function ($q) use ($lead) {
                $q->whereRaw('LOWER(name) LIKE ?', ['%'.mb_strtolower($lead->product_name).'%'])
                    ->orWhereRaw('LOWER(brand) LIKE ?', ['%'.mb_strtolower($lead->product_name).'%']);
            })
            ->first();
    }

    private function recalculateCustomerStats(Customer $customer): void
    {
        $total = $customer->total_orders;
        if ($total > 0) {
            $customer->update([
                'success_rate' => round(($customer->successful_orders / $total) * 100, 2),
            ]);
        }
    }

    private function syncOrderStatusToConversation(Order $order, OrderStatus $newStatus, ?string $reason = null): void
    {
        if (! $order->conversation_id) {
            return;
        }

        $conversation = Conversation::find($order->conversation_id);
        if (! $conversation) {
            return;
        }

        $statusLabel = $newStatus->label();
        $body = "📦 Order {$order->order_number} status updated: {$statusLabel}";
        if ($reason) {
            $body .= " — {$reason}";
        }

        Message::query()->create([
            'conversation_id' => $order->conversation_id,
            'facebook_page_id' => $conversation->facebook_page_id,
            'sent_by' => auth()->id(),
            'external_message_id' => 'system-'.str()->uuid(),
            'direction' => 'system',
            'message_type' => 'order_status',
            'body' => $body,
            'metadata' => [
                'order_id' => $order->id,
                'order_number' => $order->order_number,
                'order_status' => $newStatus->value,
                'reason' => $reason,
            ],
            'sent_at' => now(),
            'send_status' => 'logged',
            'retry_count' => 0,
        ]);

        $conversation->forceFill([
            'last_message_preview' => $body,
            'last_message_at' => now(),
        ])->save();

        $this->sendMessengerStatusUpdate($order, $conversation, $newStatus);
    }

    private function sendMessengerStatusUpdate(Order $order, Conversation $conversation, OrderStatus $newStatus): void
    {
        $autoNotify = SiteSetting::get('shop_auto_messenger_updates', '1') === '1';
        if (! $autoNotify) {
            return;
        }

        $customerMessage = match ($newStatus) {
            OrderStatus::DISPATCHED => "📦 Your order {$order->order_number} has been dispatched and is on the way! Courier: ".($order->courier_code ?? 'Manual').'. Track your shipment soon.',
            OrderStatus::DELIVERED => "✅ Your order {$order->order_number} has been delivered! Thank you for your purchase. We'd love to hear your feedback.",
            OrderStatus::RETURNED => "↩️ Your order {$order->order_number} has been returned. Please contact us if you have any questions.",
            OrderStatus::CANCELLED => "❌ Your order {$order->order_number} has been cancelled. If this was unexpected, please reach out to us.",
            default => null,
        };

        if (! $customerMessage) {
            return;
        }

        $conversation->load(['facebookPage', 'identity']);

        if (! $conversation->facebookPage?->page_access_token || ! $conversation->identity?->provider_user_id) {
            return;
        }

        $delivery = ['status' => 'logged'];

        try {
            $delivery = $this->facebookConnector->sendMessage(
                $conversation->facebookPage,
                $conversation->identity->provider_user_id,
                $customerMessage,
            );
            $delivery['status'] = 'sent';
        } catch (\Throwable $e) {
            $delivery = ['status' => 'failed', 'error' => $e->getMessage()];
            Log::warning("Messenger status update failed for order {$order->order_number}: {$e->getMessage()}");
        }

        Message::query()->create([
            'conversation_id' => $conversation->id,
            'facebook_page_id' => $conversation->facebook_page_id,
            'customer_identity_id' => $conversation->customer_identity_id,
            'sent_by' => auth()->id(),
            'external_message_id' => 'local-'.str()->uuid(),
            'direction' => 'outbound',
            'message_type' => 'order_status_update',
            'body' => $customerMessage,
            'metadata' => [
                'order_id' => $order->id,
                'order_number' => $order->order_number,
                'order_status' => $newStatus->value,
                'auto_messenger' => true,
            ],
            'raw_payload' => $delivery,
            'sent_at' => now(),
            'send_status' => $delivery['status'],
            'send_error' => $delivery['error'] ?? null,
            'retry_count' => 0,
        ]);
    }
}
