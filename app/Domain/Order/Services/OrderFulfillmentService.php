<?php

declare(strict_types=1);

namespace App\Domain\Order\Services;

use App\Domain\Courier\Actions\CreateCourierOrder;
use App\Domain\Finance\Services\CommissionService;
use App\Domain\Finance\Services\RevenueService;
use App\Domain\Lead\Enums\PoolStatus;
use App\Domain\Order\Enums\OrderStatus;
use App\Domain\Order\Models\Order;
use App\Domain\Product\Models\Product;
use App\Domain\Product\Services\InventoryService;
use App\Models\Customer;
use App\Models\Lead;
use App\Models\Waybill;
use App\Services\LeadAuditService;
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
    ) {}

    /**
     * Create an order from a confirmed lead (after agent marks ORDERED).
     */
    public function createFromLead(Lead $lead, ?string $courierCode = null): Order
    {
        return DB::transaction(function () use ($lead, $courierCode) {
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
                'order_number'     => Order::generateOrderNumber(),
                'lead_id'          => $lead->id,
                'customer_id'      => $customer?->id,
                'product_id'       => $product?->id,
                'variant_id'       => $variant?->id,
                'assigned_agent_id' => $lead->assigned_to,
                'status'           => OrderStatus::PENDING,
                'courier_code'     => $courierCode ?? config('services.couriers.default', 'FLASH'),
                'quantity'         => $quantity,
                'unit_price'       => $unitPrice,
                'total_amount'     => $unitPrice * $quantity,
                'cod_amount'       => $unitPrice * $quantity, // COD = total for now
                'receiver_name'    => $lead->name,
                'receiver_phone'   => $lead->phone,
                'receiver_address' => $lead->address ?? '',
                'city'             => $lead->city,
                'state'            => $lead->state,
                'barangay'         => $lead->barangay,
                'postal_code'      => $lead->postal_code ?? null,
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
                        'error'   => $e->getMessage(),
                    ]);
                    // Don't block order — just log the warning
                }
            }

            // Route through QA or skip
            if ($product?->requires_qa ?? true) {
                $order->update(['status' => OrderStatus::QA_PENDING]);
                $lead->update(['sales_status' => 'QA_PENDING']);
            } else {
                $this->approve($order);
            }

            return $order;
        });
    }

    /**
     * QA approves the order → submit to courier.
     */
    public function approve(Order $order, ?int $approvedBy = null): void
    {
        DB::transaction(function () use ($order, $approvedBy) {
            $order->update([
                'status'       => OrderStatus::QA_APPROVED,
                'confirmed_at' => now(),
            ]);

            if ($order->lead) {
                $order->lead->update(['sales_status' => 'QA_APPROVED']);
            }

            // Auto-submit to courier
            $this->submitToCourier($order);
        });
    }

    /**
     * QA rejects the order.
     */
    public function reject(Order $order, string $reason, ?int $rejectedBy = null): void
    {
        DB::transaction(function () use ($order, $reason) {
            $order->update([
                'status'           => OrderStatus::QA_REJECTED,
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
    }

    /**
     * Submit approved order to courier API → create waybill.
     */
    public function submitToCourier(Order $order): void
    {
        $order->update(['status' => OrderStatus::PROCESSING]);

        // Create a waybill record first
        $waybill = Waybill::create([
            'waybill_number'  => 'PENDING-' . $order->order_number,
            'status'          => 'PENDING',
            'receiver_name'   => $order->receiver_name,
            'receiver_phone'  => $order->receiver_phone,
            'receiver_address' => $order->receiver_address,
            'city'            => $order->city,
            'state'           => $order->state,
            'barangay'        => $order->barangay,
            'postal_code'     => $order->postal_code,
            'item_name'       => $order->product?->name ?? 'Package',
            'item_qty'        => $order->quantity,
            'amount'          => $order->total_amount,
            'cod_amount'      => $order->cod_amount,
            'courier_provider' => $order->courier_code ?? 'MANUAL',
            'lead_id'         => $order->lead_id,
        ]);

        $order->update(['waybill_id' => $waybill->id]);

        // Call courier API
        $courierCode = $order->courier_code;
        if ($courierCode && $courierCode !== 'MANUAL') {
            try {
                $result = $this->createCourierOrder->execute($waybill, $courierCode);

                if ($result->success) {
                    $order->update([
                        'status'        => OrderStatus::DISPATCHED,
                        'dispatched_at' => now(),
                    ]);

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
                'status'       => OrderStatus::DELIVERED,
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
        });
    }

    /**
     * Handle return — release stock, cancel commission, update customer.
     */
    public function handleReturn(Order $order): void
    {
        DB::transaction(function () use ($order) {
            $order->update([
                'status'      => OrderStatus::RETURNED,
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
        });
    }

    /**
     * Cancel an order (before dispatch).
     */
    public function cancel(Order $order, ?string $reason = null): void
    {
        DB::transaction(function () use ($order, $reason) {
            $order->update([
                'status'           => OrderStatus::CANCELLED,
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

            // Return lead to pool
            if ($order->lead) {
                $order->lead->update([
                    'sales_status' => 'NEW',
                    'pool_status'  => PoolStatus::AVAILABLE->value,
                    'assigned_to'  => null,
                ]);
            }
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
                $q->where('name', 'ILIKE', "%{$lead->product_name}%")
                  ->orWhere('brand', 'ILIKE', "%{$lead->product_name}%");
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
}
