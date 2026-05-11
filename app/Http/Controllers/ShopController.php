<?php

namespace App\Http\Controllers;

use App\Domain\Order\Enums\OrderStatus;
use App\Domain\Order\Models\Order;
use App\Domain\Product\Models\Product;
use App\Domain\Shop\Models\OrderRemark;
use App\Domain\Shop\Models\ShopOrderItem;
use App\Models\Customer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Inertia\Inertia;
use Inertia\Response;

class ShopController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Shop/Index', [
            'stats' => $this->stats(),
            'modules' => [
                [
                    'name' => 'POS Core Schema',
                    'status' => 'Foundation',
                    'description' => 'Database foundation for Facebook identities, conversations, messages, order items, remarks, address mapping, and courier exports.',
                    'items' => ['Customer identities', 'Conversations', 'Messages', 'Export batches'],
                ],
                [
                    'name' => 'Facebook Connector',
                    'status' => 'Planned',
                    'description' => 'Connect Meta accounts, list Pages, store encrypted Page tokens, and subscribe webhooks.',
                    'items' => ['Login with Facebook', 'Fetch Pages', 'Page token vault', 'Webhook subscription'],
                ],
                [
                    'name' => 'Multi-page Inbox',
                    'status' => 'Planned',
                    'description' => 'Central inbox for Messenger messages and Page comments across connected selling Pages.',
                    'items' => ['Page filters', 'Unread queue', 'Agent assignment', 'Conversation history'],
                ],
                [
                    'name' => 'Order Desk',
                    'status' => 'MVP Entry',
                    'description' => 'Create structured orders from conversations with products, COD amount, remarks, and customer details.',
                    'items' => ['Manual order form', 'Customer matching', 'Order items', 'Agent remarks'],
                ],
                [
                    'name' => 'Encoder & Export',
                    'status' => 'Planned',
                    'description' => 'Validate addresses, map regions, and export courier-ready sheets for J&T, Flash, and other COD couriers.',
                    'items' => ['Address confidence', 'Encoder queue', 'Courier batches', 'CSV/XLSX export'],
                ],
            ],
            'workflow' => [
                'Connect Pages',
                'Receive Messages',
                'Detect Phone',
                'Match Customer',
                'Create Order',
                'Validate Address',
                'Export Courier File',
            ],
            'next_actions' => [
                'Add phone normalization and customer identity matching services.',
                'Seed Philippine address mapping references for province, city, barangay, and courier zone.',
                'Add Meta app configuration and encrypted Page token storage.',
                'Implement webhook verification and raw event capture.',
            ],
        ]);
    }

    public function createOrder(): Response
    {
        return Inertia::render('Shop/CreateOrder', [
            'products' => Product::query()
                ->with(['activeVariants:id,product_id,sku,variant_name,selling_price'])
                ->where('is_active', true)
                ->orderBy('name')
                ->get(['id', 'sku', 'name', 'selling_price']),
            'couriers' => [
                ['value' => 'MANUAL', 'label' => 'Manual'],
                ['value' => 'JNT', 'label' => 'J&T Express'],
                ['value' => 'FLASH', 'label' => 'Flash Express'],
            ],
        ]);
    }

    public function storeOrder(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'customer_name' => ['required', 'string', 'max:255'],
            'phone' => ['required', 'string', 'max:30'],
            'complete_address' => ['required', 'string', 'max:2000'],
            'landmark' => ['nullable', 'string', 'max:255'],
            'barangay' => ['nullable', 'string', 'max:255'],
            'city_municipality' => ['nullable', 'string', 'max:255'],
            'province' => ['nullable', 'string', 'max:255'],
            'product_id' => ['required', 'exists:products,id'],
            'variant_id' => ['nullable', 'exists:product_variants,id'],
            'quantity' => ['required', 'integer', 'min:1', 'max:999'],
            'unit_price' => ['required', 'numeric', 'min:0', 'max:999999.99'],
            'shipping_fee' => ['nullable', 'numeric', 'min:0', 'max:999999.99'],
            'courier_code' => ['nullable', 'string', 'max:30'],
            'remarks' => ['nullable', 'string', 'max:2000'],
        ]);

        $product = Product::query()->findOrFail($validated['product_id']);
        $variant = null;

        if (! empty($validated['variant_id'])) {
            $variant = $product->variants()->whereKey($validated['variant_id'])->firstOrFail();
        }

        $quantity = (int) $validated['quantity'];
        $unitPrice = (float) $validated['unit_price'];
        $shippingFee = (float) ($validated['shipping_fee'] ?? 0);
        $lineTotal = $quantity * $unitPrice;
        $totalAmount = $lineTotal + $shippingFee;
        $normalizedPhone = $this->normalizePhilippinePhone($validated['phone']);

        $order = DB::transaction(function () use (
            $validated,
            $product,
            $variant,
            $quantity,
            $unitPrice,
            $shippingFee,
            $lineTotal,
            $totalAmount,
            $normalizedPhone
        ) {
            $customer = Customer::query()
                ->where('normalized_phone', $normalizedPhone)
                ->orWhere('phone', $validated['phone'])
                ->first();

            if (! $customer) {
                $customer = new Customer([
                    'phone' => $validated['phone'],
                    'normalized_phone' => $normalizedPhone,
                    'name' => $validated['customer_name'],
                    'risk_level' => 'LOW',
                ]);
            }

            $customer->fill([
                'name' => $validated['customer_name'],
                'normalized_phone' => $normalizedPhone,
                'canonical_address' => $validated['complete_address'],
                'landmark' => $validated['landmark'] ?? null,
                'barangay' => $validated['barangay'] ?? null,
                'city_municipality' => $validated['city_municipality'] ?? null,
                'province' => $validated['province'] ?? null,
                'last_order_date' => now(),
            ])->save();

            $order = Order::query()->create([
                'order_number' => Order::generateOrderNumber(),
                'customer_id' => $customer->id,
                'product_id' => $product->id,
                'variant_id' => $variant?->id,
                'assigned_agent_id' => auth()->id(),
                'status' => OrderStatus::CONFIRMED,
                'courier_code' => $validated['courier_code'] ?? 'MANUAL',
                'quantity' => $quantity,
                'unit_price' => $unitPrice,
                'total_amount' => $totalAmount,
                'cod_amount' => $totalAmount,
                'shipping_cost' => $shippingFee,
                'receiver_name' => $validated['customer_name'],
                'receiver_phone' => $normalizedPhone ?: $validated['phone'],
                'receiver_address' => $validated['complete_address'],
                'city' => $validated['city_municipality'] ?? null,
                'state' => $validated['province'] ?? null,
                'barangay' => $validated['barangay'] ?? null,
                'source_channel' => 'manual_shop',
                'export_status' => 'pending',
                'confirmed_at' => now(),
                'notes' => $validated['remarks'] ?? null,
            ]);

            ShopOrderItem::query()->create([
                'order_id' => $order->id,
                'product_id' => $product->id,
                'variant_id' => $variant?->id,
                'sku' => $variant?->sku ?? $product->sku,
                'product_name' => $variant ? "{$product->name} - {$variant->variant_name}" : $product->name,
                'quantity' => $quantity,
                'unit_price' => $unitPrice,
                'line_total' => $lineTotal,
            ]);

            if (! empty($validated['remarks'])) {
                OrderRemark::query()->create([
                    'order_id' => $order->id,
                    'user_id' => auth()->id(),
                    'type' => 'agent_note',
                    'body' => $validated['remarks'],
                ]);
            }

            return $order;
        });

        return redirect()
            ->route('orders.show', $order)
            ->with('success', "Shop order {$order->order_number} created.");
    }

    private function stats(): array
    {
        return [
            'connected_pages' => $this->countWhenReady('facebook_pages', fn () => DB::table('facebook_pages')
                ->where('connected_status', 'connected')
                ->count()),
            'open_conversations' => $this->countWhenReady('conversations', fn () => DB::table('conversations')
                ->where('status', 'open')
                ->count()),
            'orders_today' => $this->countWhenReady('orders', fn () => DB::table('orders')
                ->whereDate('created_at', today())
                ->count()),
            'for_encoding' => $this->forEncodingCount(),
        ];
    }

    private function forEncodingCount(): int
    {
        if (! Schema::hasTable('orders') || ! Schema::hasColumn('orders', 'encoded_at')) {
            return 0;
        }

        return (int) DB::table('orders')
            ->whereIn('status', ['CONFIRMED', 'For Encoding', 'for_encoding'])
            ->whereNull('encoded_at')
            ->count();
    }

    private function countWhenReady(string $table, callable $callback): int
    {
        if (! Schema::hasTable($table)) {
            return 0;
        }

        return (int) $callback();
    }

    private function normalizePhilippinePhone(string $phone): string
    {
        $digits = preg_replace('/\D+/', '', $phone) ?? '';

        if (str_starts_with($digits, '63') && strlen($digits) === 12) {
            return '0' . substr($digits, 2);
        }

        if (str_starts_with($digits, '9') && strlen($digits) === 10) {
            return '0' . $digits;
        }

        return $digits;
    }
}
