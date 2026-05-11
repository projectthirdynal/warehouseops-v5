<?php

namespace App\Http\Controllers;

use App\Domain\Order\Enums\OrderStatus;
use App\Domain\Order\Models\Order;
use App\Domain\Product\Models\Product;
use App\Domain\Shop\Models\Conversation;
use App\Domain\Shop\Models\CourierExportBatch;
use App\Domain\Shop\Models\FacebookPage;
use App\Domain\Shop\Models\Message;
use App\Domain\Shop\Services\AddressMappingService;
use App\Domain\Shop\Services\CourierExportService;
use App\Domain\Shop\Services\CustomerIdentityService;
use App\Domain\Shop\Services\FacebookConnectorService;
use App\Domain\Shop\Services\PhoneDetectionService;
use App\Domain\Shop\Models\OrderRemark;
use App\Domain\Shop\Models\ShopOrderItem;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class ShopController extends Controller
{
    public function __construct(
        private readonly PhoneDetectionService $phones,
        private readonly CustomerIdentityService $customerIdentities,
        private readonly AddressMappingService $addressMappings,
        private readonly FacebookConnectorService $facebookConnector,
        private readonly CourierExportService $courierExports,
    ) {}

    public function index(): Response
    {
        return Inertia::render('Shop/Index', [
            'stats' => $this->stats(),
            'facebook_pages' => FacebookPage::query()
                ->latest('last_sync_at')
                ->limit(8)
                ->get(['id', 'page_id', 'page_name', 'connected_status', 'webhook_status', 'last_sync_at']),
            'modules' => [
                [
                    'name' => 'POS Core Schema',
                    'status' => 'Foundation',
                    'description' => 'Database foundation for Facebook identities, conversations, messages, order items, remarks, address mapping, and courier exports.',
                    'items' => ['Customer identities', 'Conversations', 'Messages', 'Export batches'],
                ],
                [
                    'name' => 'Facebook Connector',
                    'status' => 'Subscribe Ready',
                    'description' => 'Meta OAuth redirect, Page token storage, webhook verification, Page subscription, and raw event capture foundation.',
                    'items' => ['OAuth connect', 'Page list sync', 'Webhook subscribe', 'Raw event capture'],
                ],
                [
                    'name' => 'Multi-page Inbox',
                    'status' => 'Detail Ready',
                    'description' => 'Central inbox for Messenger messages and Page comments across connected selling Pages.',
                    'items' => ['Page filters', 'Conversation detail', 'Reply logging', 'Message preview'],
                ],
                [
                    'name' => 'Order Desk',
                    'status' => 'MVP Entry',
                    'description' => 'Create structured orders from conversations with products, COD amount, remarks, and customer details.',
                    'items' => ['Manual order form', 'Phone matching', 'Address matching', 'Agent remarks'],
                ],
                [
                    'name' => 'Encoder & Export',
                    'status' => 'Correction Ready',
                    'description' => 'Validate addresses, map regions, and export courier-ready sheets for J&T, Flash, and other COD couriers.',
                    'items' => ['Address correction', 'PH reference seed', 'Courier batches', 'Courier CSV adapters'],
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
                'Add live Send API error/status indicators in conversation detail.',
                'Add Page subscription health checks and resubscribe retries.',
                'Add bulk encoder selection before export.',
                'Add courier-specific required field validation.',
            ],
        ]);
    }

    public function inbox(Request $request): Response
    {
        $query = Conversation::query()
            ->with(['facebookPage:id,page_name,page_id', 'customer:id,name,phone,normalized_phone', 'identity:id,display_name,phone_detected'])
            ->withCount('messages')
            ->latest('last_message_at');

        if ($request->filled('page_id')) {
            $query->where('facebook_page_id', $request->integer('page_id'));
        }

        if ($request->filled('status')) {
            $query->where('status', $request->string('status'));
        }

        return Inertia::render('Shop/Inbox', [
            'conversations' => $query->paginate(20)->withQueryString(),
            'pages' => FacebookPage::query()->orderBy('page_name')->get(['id', 'page_id', 'page_name']),
            'filters' => $request->only(['page_id', 'status']),
        ]);
    }

    public function conversation(Conversation $conversation): Response
    {
        $conversation->load([
            'facebookPage:id,page_id,page_name,webhook_status',
            'customer:id,name,phone,normalized_phone,canonical_address',
            'identity:id,display_name,provider_user_id,phone_detected',
            'messages' => fn ($query) => $query->orderBy('sent_at')->orderBy('id'),
        ]);

        $conversation->forceFill(['unread_count' => 0])->save();

        return Inertia::render('Shop/Conversation', [
            'conversation' => $conversation,
        ]);
    }

    public function sendReply(Request $request, Conversation $conversation): RedirectResponse
    {
        $validated = $request->validate([
            'body' => ['required', 'string', 'max:2000'],
        ]);

        $conversation->load(['facebookPage', 'identity']);
        $delivery = ['status' => 'logged'];

        if ($conversation->facebookPage?->page_access_token && $conversation->identity?->provider_user_id) {
            try {
                $delivery = $this->facebookConnector->sendMessage(
                    $conversation->facebookPage,
                    $conversation->identity->provider_user_id,
                    $validated['body']
                );
                $delivery['status'] = 'sent';
            } catch (\Throwable $exception) {
                $delivery = [
                    'status' => 'failed',
                    'error' => $exception->getMessage(),
                ];
            }
        }

        Message::query()->create([
            'conversation_id' => $conversation->id,
            'facebook_page_id' => $conversation->facebook_page_id,
            'customer_identity_id' => $conversation->customer_identity_id,
            'external_message_id' => 'local-' . str()->uuid(),
            'direction' => 'outbound',
            'message_type' => 'text',
            'body' => $validated['body'],
            'raw_payload' => $delivery,
            'sent_at' => now(),
        ]);

        $conversation->forceFill([
            'last_message_preview' => $validated['body'],
            'last_message_at' => now(),
        ])->save();

        return back()->with($delivery['status'] === 'failed' ? 'error' : 'success', $delivery['status'] === 'failed'
            ? 'Reply saved locally, but Meta send failed.'
            : 'Reply saved.');
    }

    public function encoder(): Response
    {
        return Inertia::render('Shop/Encoder', [
            'orders' => Order::query()
                ->with(['customer:id,name,phone,normalized_phone', 'product:id,name,sku'])
                ->whereIn('status', [OrderStatus::CONFIRMED, OrderStatus::QA_APPROVED])
                ->whereNull('encoded_at')
                ->latest()
                ->paginate(25),
            'recent_batches' => CourierExportBatch::query()
                ->latest()
                ->limit(10)
                ->get(['id', 'batch_number', 'courier_code', 'row_count', 'file_path', 'created_at']),
            'couriers' => [
                ['value' => 'JNT', 'label' => 'J&T Express'],
                ['value' => 'FLASH', 'label' => 'Flash Express'],
                ['value' => 'GENERIC', 'label' => 'Generic CSV'],
            ],
        ]);
    }

    public function exportCourier(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'courier_code' => ['required', 'string', 'max:30'],
            'order_ids' => ['nullable', 'array'],
            'order_ids.*' => ['integer', 'exists:orders,id'],
        ]);

        $orders = Order::query()
            ->with('product:id,name,sku')
            ->whereIn('status', [OrderStatus::CONFIRMED, OrderStatus::QA_APPROVED])
            ->whereNull('encoded_at')
            ->when(! empty($validated['order_ids']), fn ($query) => $query->whereIn('id', $validated['order_ids']))
            ->limit(500)
            ->get();

        if ($orders->isEmpty()) {
            return back()->with('error', 'No encoder-ready orders found for export.');
        }

        $batch = $this->courierExports->createBatch($orders, $validated['courier_code'], auth()->id());

        return redirect()
            ->route('shop.encoder')
            ->with('success', "Export batch {$batch->batch_number} created.");
    }

    public function updateOrderAddress(Request $request, Order $order): RedirectResponse
    {
        $validated = $request->validate([
            'receiver_address' => ['required', 'string', 'max:2000'],
            'barangay' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:255'],
            'state' => ['nullable', 'string', 'max:255'],
            'postal_code' => ['nullable', 'string', 'max:30'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $addressMatch = $this->addressMappings->match([
            'province' => $validated['state'] ?? null,
            'city_municipality' => $validated['city'] ?? null,
            'barangay' => $validated['barangay'] ?? null,
            'address' => $validated['receiver_address'],
        ]);

        $order->forceFill([
            'receiver_address' => $validated['receiver_address'],
            'barangay' => $validated['barangay'] ?? null,
            'city' => $validated['city'] ?? null,
            'state' => $validated['state'] ?? null,
            'postal_code' => $validated['postal_code'] ?? null,
            'notes' => $validated['notes'] ?? $order->notes,
            'address_mapping_id' => $addressMatch['mapping']?->id,
            'address_confidence' => $addressMatch['confidence'],
        ])->save();

        return back()->with('success', "Address updated for {$order->order_number}.");
    }

    public function markEncoded(Order $order): RedirectResponse
    {
        $order->forceFill([
            'encoded_at' => now(),
            'export_status' => 'ready',
        ])->save();

        return back()->with('success', "{$order->order_number} marked encoded.");
    }

    public function downloadExport(CourierExportBatch $batch): BinaryFileResponse
    {
        abort_unless($batch->file_path && file_exists(storage_path("app/{$batch->file_path}")), 404);

        return response()->download(storage_path("app/{$batch->file_path}"));
    }

    public function connectFacebook(): RedirectResponse
    {
        if (! $this->facebookConnector->isConfigured()) {
            return back()->with('error', 'Meta app credentials are not configured yet.');
        }

        return redirect()->away($this->facebookConnector->authorizationUrl());
    }

    public function facebookCallback(Request $request): RedirectResponse
    {
        if ($request->filled('error')) {
            return redirect()->route('shop.index')->with('error', (string) $request->query('error_description', 'Facebook connection cancelled.'));
        }

        $request->validate(['code' => ['required', 'string']]);

        if ($request->filled('state') && ! hash_equals(csrf_token(), (string) $request->query('state'))) {
            return redirect()->route('shop.index')->with('error', 'Facebook connection state check failed.');
        }

        $pageCount = $this->facebookConnector->connectFromCallback($request->user(), (string) $request->query('code'));

        return redirect()
            ->route('shop.index')
            ->with('success', "Facebook connected. {$pageCount} Pages synced.");
    }

    public function subscribeFacebookPage(FacebookPage $page): RedirectResponse
    {
        try {
            $this->facebookConnector->subscribePage($page);
        } catch (\Throwable $exception) {
            return back()->with('error', "Page subscription failed: {$exception->getMessage()}");
        }

        return back()->with('success', "{$page->page_name} subscribed to Meta webhook fields.");
    }

    public function checkFacebookPageSubscription(FacebookPage $page): RedirectResponse
    {
        try {
            $result = $this->facebookConnector->checkPageSubscription($page);
        } catch (\Throwable $exception) {
            return back()->with('error', "Page subscription health check failed: {$exception->getMessage()}");
        }

        if ($result['status'] !== 'subscribed') {
            return back()->with('error', "{$page->page_name} needs resubscribe. Missing: " . implode(', ', $result['missing_fields']));
        }

        return back()->with('success', "{$page->page_name} subscription is healthy.");
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
        $normalizedPhone = $this->phones->normalize($validated['phone']);
        $addressMatch = $this->addressMappings->match([
            'province' => $validated['province'] ?? null,
            'city_municipality' => $validated['city_municipality'] ?? null,
            'barangay' => $validated['barangay'] ?? null,
            'address' => $validated['complete_address'],
        ]);

        $order = DB::transaction(function () use (
            $validated,
            $product,
            $variant,
            $quantity,
            $unitPrice,
            $shippingFee,
            $lineTotal,
            $totalAmount,
            $normalizedPhone,
            $addressMatch
        ) {
            $customer = $this->customerIdentities->firstOrCreateFromPhone([
                'name' => $validated['customer_name'],
                'phone' => $validated['phone'],
                'address' => $validated['complete_address'],
                'landmark' => $validated['landmark'] ?? null,
                'barangay' => $validated['barangay'] ?? null,
                'city_municipality' => $validated['city_municipality'] ?? null,
                'province' => $validated['province'] ?? null,
                'region' => $addressMatch['mapping']?->region,
            ]);

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
                'address_mapping_id' => $addressMatch['mapping']?->id,
                'source_channel' => 'manual_shop',
                'address_confidence' => $addressMatch['confidence'],
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

}
