<?php

namespace App\Http\Controllers;

use App\Domain\Order\Enums\OrderStatus;
use App\Domain\Order\Models\Order;
use App\Domain\Product\Models\Product;
use App\Domain\Shop\Models\Conversation;
use App\Domain\Shop\Models\CourierExportBatch;
use App\Domain\Shop\Models\FacebookPage;
use App\Domain\Shop\Models\FacebookWebhookEvent;
use App\Domain\Shop\Models\Message;
use App\Domain\Shop\Services\AddressMappingService;
use App\Domain\Shop\Services\CourierExportService;
use App\Domain\Shop\Services\CustomerIdentityService;
use App\Domain\Shop\Services\FacebookConnectorService;
use App\Domain\Shop\Services\MetaConversationIngestor;
use App\Domain\Shop\Services\PhoneDetectionService;
use App\Domain\Shop\Models\OrderRemark;
use App\Domain\Shop\Models\ShopReplyTemplate;
use App\Domain\Shop\Models\ShopOrderItem;
use App\Models\Customer;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
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
        private readonly MetaConversationIngestor $metaIngestor,
    ) {}

    public function index(): Response
    {
        return Inertia::render('Shop/Index', array_merge($this->dashboardPayload(), [
            'workspaces' => [
                [
                    'name' => 'POS Register',
                    'href' => route('shop.pos'),
                    'status' => 'Live',
                    'description' => 'Create manual, chat-assisted, and Facebook Shop orders with line items, COD totals, courier preference, and duplicate warnings.',
                    'items' => ['Cart line items', 'Customer details', 'COD amount', 'Duplicate checks'],
                ],
                [
                    'name' => 'CRM Inbox',
                    'href' => route('shop.inbox'),
                    'status' => 'Live',
                    'description' => 'Handle Page conversations, detect phones, assign agents, update customer profiles, and convert chats into orders.',
                    'items' => ['Facebook conversations', 'Agent assignment', 'Quick replies', 'Customer profile'],
                ],
                [
                    'name' => 'Orders Desk',
                    'href' => route('shop.orders.index'),
                    'status' => 'Live',
                    'description' => 'Track Shop orders from capture through encoding and fulfillment without mixing them into procurement workflows.',
                    'items' => ['Order search', 'Status filters', 'Channel split', 'Fulfillment handoff'],
                ],
                [
                    'name' => 'Customer CRM',
                    'href' => route('shop.customers.index'),
                    'status' => 'Live',
                    'description' => 'Maintain a customer book with phone identity, address profile, revenue, order history, and risk indicators.',
                    'items' => ['Customer search', 'Risk level', 'Revenue totals', 'Repeat order context'],
                ],
                [
                    'name' => 'Encoder & Dispatch',
                    'href' => route('shop.encoder'),
                    'status' => 'Ready',
                    'description' => 'Validate addresses, map regions, and export courier-ready sheets for J&T, Flash, and other COD couriers.',
                    'items' => ['Address correction', 'Bulk selection', 'Courier batches', 'Courier CSV validation'],
                ],
                [
                    'name' => 'Reports & Setup',
                    'href' => route('shop.reports'),
                    'status' => 'Automation Ready',
                    'description' => 'Monitor sales, agent conversion, page performance, templates, webhook health, and Meta readiness.',
                    'items' => ['Sales analytics', 'Agent performance', 'Reply templates', 'Meta diagnostics'],
                ],
            ],
            'workflow' => [
                'Lead or Walk-in',
                'CRM Conversation',
                'Customer Profile',
                'POS Order',
                'Stock Review',
                'Address Encoding',
                'Courier Export',
                'Fulfillment Tracking',
            ],
            'next_actions' => [
                'Add stock-aware validation before Shop order confirmation.',
                'Add cashier shift sessions once physical counter sales are enabled.',
                'Add customer tags and follow-up reminders per conversation.',
                'Connect courier booking API after CSV export stabilizes.',
            ],
        ]));
    }

    public function metrics(): JsonResponse
    {
        return response()->json($this->dashboardPayload());
    }

    public function metaReadiness(): Response
    {
        $pages = FacebookPage::query()
            ->latest('last_sync_at')
            ->get(['id', 'page_id', 'page_name', 'connected_status', 'webhook_status', 'last_sync_at', 'metadata']);

        $webhookEventsReady = Schema::hasTable('facebook_webhook_events');
        $conversationsReady = Schema::hasTable('conversations');
        $supportEmail = (string) config('services.meta.support_email');
        $supportEmailReady = filled($supportEmail)
            && filter_var($supportEmail, FILTER_VALIDATE_EMAIL)
            && ! str_ends_with($supportEmail, '.local')
            && ! str_contains($supportEmail, 'warehouseops.local');

        return Inertia::render('Shop/MetaReadiness', [
            'config' => [
                'app_id_configured' => filled(config('services.meta.app_id')),
                'app_secret_configured' => filled(config('services.meta.app_secret')),
                'login_config_id' => config('services.meta.login_config_id'),
                'redirect_uri' => config('services.meta.redirect_uri'),
                'requested_scopes' => $this->facebookConnector->requestedScopes(),
                'required_webhook_fields' => $this->facebookConnector->requiredWebhookFields(),
                'callback_url' => url('/api/webhooks/meta'),
                'verify_token' => config('services.meta.webhook_verify_token'),
                'privacy_url' => route('meta.privacy'),
                'terms_url' => route('meta.terms'),
                'data_deletion_url' => route('meta.data-deletion.handle'),
                'support_email' => config('services.meta.support_email'),
            ],
            'login_diagnostics' => [
                [
                    'title' => 'Complete Meta app details',
                    'detail' => 'In Meta App Dashboard, fill app name, contact email, privacy policy URL, terms URL, data deletion URL, app domain, business info, and support contact.',
                ],
                [
                    'title' => 'Finish Data Use Checkup',
                    'detail' => 'If Data Use Checkup or any Meta certification is pending, Facebook can block Login before WarehouseOps receives a callback.',
                ],
                [
                    'title' => 'Set Facebook Login access',
                    'detail' => 'Enable Client OAuth Login and Web OAuth Login, then add this exact Valid OAuth Redirect URI: ' . config('services.meta.redirect_uri'),
                ],
                [
                    'title' => 'Grant app access to the user',
                    'detail' => 'While the app is not fully live/reviewed, the Facebook user must be an app Admin, Developer, or Tester. Public users require Advanced Access/App Review.',
                ],
                [
                    'title' => 'Request production permissions',
                    'detail' => 'Shop page sync needs pages_show_list, pages_manage_metadata, and pages_messaging. These usually require Advanced Access, business verification, and review evidence.',
                ],
            ],
            'summary' => [
                'connected_pages' => $pages->where('connected_status', 'connected')->count(),
                'manual_pages' => $pages->where('connected_status', 'manual')->count(),
                'subscribed_pages' => $pages->where('webhook_status', 'subscribed')->count(),
                'pages_needing_retry' => $pages->where('webhook_status', 'needs_retry')->count(),
                'webhook_events' => $webhookEventsReady ? FacebookWebhookEvent::query()->count() : 0,
                'processed_events' => $webhookEventsReady ? FacebookWebhookEvent::query()->whereNotNull('processed_at')->count() : 0,
                'conversations' => $conversationsReady ? Conversation::query()->count() : 0,
            ],
            'pages' => $pages->map(function (FacebookPage $page) {
                $metadata = $page->metadata ?? [];

                return [
                    'id' => $page->id,
                    'page_id' => $page->page_id,
                    'page_name' => $page->page_name,
                    'connected_status' => $page->connected_status,
                    'webhook_status' => $page->webhook_status,
                    'last_sync_at' => optional($page->last_sync_at)?->toIso8601String(),
                    'tasks' => $metadata['tasks'] ?? [],
                    'subscribed_fields' => $metadata['subscribed_fields'] ?? [],
                    'subscription_fields' => $metadata['subscription_fields'] ?? [],
                    'subscription_missing_fields' => $metadata['subscription_missing_fields'] ?? [],
                    'subscription_checked_at' => $metadata['subscription_checked_at'] ?? null,
                    'connection_mode' => $metadata['connection_mode'] ?? 'oauth',
                    'token_present' => $metadata['token_present'] ?? filled($page->page_access_token),
                ];
            })->values(),
            'recent_events' => $webhookEventsReady
                ? FacebookWebhookEvent::query()
                    ->with('facebookPage:id,page_name,page_id')
                    ->latest()
                    ->limit(10)
                    ->get(['id', 'facebook_page_id', 'event_id', 'event_type', 'sender_psid', 'signature_valid', 'processed_at', 'error_message', 'created_at'])
                    ->map(fn (FacebookWebhookEvent $event) => [
                        'id' => $event->id,
                        'event_id' => $event->event_id,
                        'event_type' => $event->event_type,
                        'sender_psid' => $event->sender_psid,
                        'signature_valid' => $event->signature_valid,
                        'processed_at' => optional($event->processed_at)?->toIso8601String(),
                        'error_message' => $event->error_message,
                        'created_at' => optional($event->created_at)?->toIso8601String(),
                        'facebook_page' => $event->facebookPage ? [
                            'id' => $event->facebookPage->id,
                            'page_id' => $event->facebookPage->page_id,
                            'page_name' => $event->facebookPage->page_name,
                        ] : null,
                    ])->values()
                : [],
            'review_items' => [
                [
                    'label' => 'App credentials configured',
                    'status' => filled(config('services.meta.app_id')) && filled(config('services.meta.app_secret')) ? 'ready' : 'needs_action',
                    'detail' => 'META_APP_ID and META_APP_SECRET must be set on production.',
                ],
                [
                    'label' => 'Facebook Login redirect URI',
                    'status' => filled(config('services.meta.redirect_uri')) ? 'ready' : 'needs_action',
                    'detail' => (string) config('services.meta.redirect_uri'),
                ],
                [
                    'label' => 'Facebook Login for Business configuration',
                    'status' => filled(config('services.meta.login_config_id')) ? 'ready' : 'needs_action',
                    'detail' => filled(config('services.meta.login_config_id'))
                        ? 'META_LOGIN_CONFIG_ID is set and will be sent as config_id during OAuth.'
                        : 'Create a configuration under Facebook Login for Business, include the required permissions, then set META_LOGIN_CONFIG_ID.',
                ],
                [
                    'label' => 'Compliance URLs live',
                    'status' => 'ready',
                    'detail' => 'Privacy policy, terms, and data deletion callback are public.',
                ],
                [
                    'label' => 'Meta support contact set',
                    'status' => $supportEmailReady ? 'ready' : 'needs_action',
                    'detail' => $supportEmailReady
                        ? $supportEmail
                        : 'Set META_SUPPORT_EMAIL to a real monitored email address. Current value is not production-ready.',
                ],
                [
                    'label' => 'At least one Page connected',
                    'status' => $pages->whereIn('connected_status', ['connected', 'manual'])->isNotEmpty() ? 'ready' : 'needs_action',
                    'detail' => $pages->whereIn('connected_status', ['connected', 'manual'])->isNotEmpty()
                        ? 'A Shop Page is available for CRM/order capture. OAuth Pages can send replies; manual Pages can receive matched webhook payloads when Meta is configured.'
                        : 'Connect through Facebook Login or register a Page manually while Meta public access is pending.',
                ],
                [
                    'label' => 'At least one Page subscribed',
                    'status' => $pages->where('webhook_status', 'subscribed')->isNotEmpty() ? 'ready' : 'needs_action',
                    'detail' => $pages->where('webhook_status', 'subscribed')->isNotEmpty()
                        ? 'Webhook subscription is active for at least one Page.'
                        : 'Subscribe at least one Page to webhook fields before review.',
                ],
                [
                    'label' => 'Webhook processing demonstrated',
                    'status' => $webhookEventsReady && FacebookWebhookEvent::query()->whereNotNull('processed_at')->exists() ? 'ready' : 'needs_action',
                    'detail' => $webhookEventsReady && FacebookWebhookEvent::query()->whereNotNull('processed_at')->exists()
                        ? 'Processed webhook events exist in production.'
                        : 'Send a real test message or use diagnostics to generate a processed event.',
                ],
                [
                    'label' => 'Inbox conversation available for screencast',
                    'status' => $conversationsReady && Conversation::query()->exists() ? 'ready' : 'needs_action',
                    'detail' => $conversationsReady && Conversation::query()->exists()
                        ? 'Inbox has at least one conversation for review demo.'
                        : 'Create one live or simulated conversation before recording the review flow.',
                ],
            ],
            'permission_justifications' => [
                [
                    'scope' => 'pages_show_list',
                    'purpose' => 'List Facebook Pages the authenticated user can manage.',
                    'usage' => 'WarehouseOps calls /me/accounts after Facebook Login so an admin can select which selling Page to connect to the Shop inbox.',
                    'review_evidence' => 'Show Connect Facebook, complete login, then show the connected Page list in Shop.',
                ],
                [
                    'scope' => 'pages_manage_metadata',
                    'purpose' => 'Subscribe selected Pages to webhook events and maintain Page connection status.',
                    'usage' => 'WarehouseOps calls /{page-id}/subscribed_apps to subscribe messages, postbacks, and feed events for the selected Page.',
                    'review_evidence' => 'Show a connected Page, click Subscribe or Resubscribe, then show webhook status as subscribed.',
                ],
                [
                    'scope' => 'pages_messaging',
                    'purpose' => 'Read and reply to Messenger conversations from connected Facebook Pages.',
                    'usage' => 'WarehouseOps receives Page messages through Meta webhooks and sends manual agent replies through the Send API.',
                    'review_evidence' => 'Send a test message to the Page, show it in Shop Inbox, then reply from WarehouseOps.',
                ],
            ],
            'screencast_steps' => [
                [
                    'title' => 'Open Shop Meta Readiness',
                    'detail' => 'Start at /shop/meta-readiness and show that app credentials, compliance URLs, redirect URI, and webhook callback are configured.',
                    'target' => route('shop.meta-readiness'),
                ],
                [
                    'title' => 'Connect Facebook',
                    'detail' => 'Click Connect Facebook and authorize the app using a Facebook user that has access to the test Page.',
                    'target' => route('shop.facebook.connect'),
                ],
                [
                    'title' => 'Confirm Page Sync',
                    'detail' => 'Return to Shop and show the connected Page row with connected status and Page ID.',
                    'target' => route('shop.index'),
                ],
                [
                    'title' => 'Subscribe Webhooks',
                    'detail' => 'Open Shop Webhooks or Meta Readiness, subscribe the Page, and show required fields are present.',
                    'target' => route('shop.webhooks'),
                ],
                [
                    'title' => 'Receive Message',
                    'detail' => 'Send a Messenger message to the connected Page from a tester account and show the message appears in Shop Inbox.',
                    'target' => route('shop.inbox'),
                ],
                [
                    'title' => 'Reply From WarehouseOps',
                    'detail' => 'Open the conversation, send a manual reply, and show the outbound message or send status in the conversation detail.',
                    'target' => route('shop.inbox'),
                ],
                [
                    'title' => 'Create Order From Conversation',
                    'detail' => 'Show the customer profile and Create Order workflow to demonstrate why the messages are used for order processing.',
                    'target' => route('shop.orders.create'),
                ],
            ],
            'docs' => [
                [
                    'label' => 'Meta App Review',
                    'url' => 'https://developers.facebook.com/docs/app-review/',
                ],
                [
                    'label' => 'Facebook Login Permissions',
                    'url' => 'https://developers.facebook.com/docs/facebook-login/permissions/',
                ],
                [
                    'label' => 'Meta Webhooks',
                    'url' => 'https://developers.facebook.com/docs/graph-api/webhooks/',
                ],
                [
                    'label' => 'Data Deletion Callback',
                    'url' => 'https://developers.facebook.com/docs/development/create-an-app/app-dashboard/data-deletion-callback/',
                ],
            ],
            'manual_page_setup' => [
                'store_url' => route('shop.facebook.pages.manual'),
                'purpose' => 'Use this when public Facebook Login is blocked by Meta review. It lets WarehouseOps recognize Page webhook recipient IDs and keep the Shop funnel moving.',
                'limitations' => [
                    'Without a Page access token, WarehouseOps can receive and match incoming webhook events but cannot send Messenger replies or fetch customer profile photos.',
                    'With a Page access token, admins can subscribe and check webhooks from this screen, subject to Meta permission access.',
                    'Public users still require Meta Advanced Access and business verification before self-service OAuth works.',
                ],
            ],
        ]);
    }

    public function webhooks(): Response
    {
        return Inertia::render('Shop/Webhooks', [
            'stats' => [
                'events' => $this->countWhenReady('facebook_webhook_events', fn () => DB::table('facebook_webhook_events')->count()),
                'processed' => $this->countWhenReady('facebook_webhook_events', fn () => DB::table('facebook_webhook_events')->whereNotNull('processed_at')->count()),
                'failed' => $this->countWhenReady('facebook_webhook_events', fn () => DB::table('facebook_webhook_events')->whereNotNull('error_message')->count()),
                'conversations' => $this->countWhenReady('conversations', fn () => DB::table('conversations')->count()),
            ],
            'pages' => FacebookPage::query()
                ->orderBy('page_name')
                ->get(['id', 'page_id', 'page_name', 'connected_status', 'webhook_status', 'last_sync_at']),
            'events' => FacebookWebhookEvent::query()
                ->with('facebookPage:id,page_name,page_id')
                ->latest()
                ->limit(30)
                ->get(['id', 'facebook_page_id', 'event_id', 'event_type', 'sender_psid', 'recipient_id', 'signature_valid', 'processed_at', 'error_message', 'created_at']),
            'callback_url' => url('/api/webhooks/meta'),
            'verify_token' => config('services.meta.webhook_verify_token'),
        ]);
    }

    public function reports(Request $request): Response
    {
        $filters = [
            'date_from' => $request->string('date_from')->toString() ?: today()->subDays(6)->toDateString(),
            'date_to' => $request->string('date_to')->toString() ?: today()->toDateString(),
            'page_id' => $request->string('page_id')->toString(),
            'agent_id' => $request->string('agent_id')->toString(),
        ];

        return Inertia::render('Shop/Reports', [
            'summary' => [
                'shop_orders' => $this->filteredShopOrderQuery($filters)->count(),
                'sales_today' => $this->shopOrderQuery()
                    ->whereDate('created_at', today())
                    ->sum('total_amount'),
                'orders_today' => $this->filteredShopOrderQuery($filters)->count(),
                'confirmed_orders' => $this->filteredShopOrderQuery($filters)
                    ->whereIn('status', [OrderStatus::CONFIRMED->value, OrderStatus::QA_APPROVED->value])
                    ->count(),
                'open_conversations' => $this->countWhenReady('conversations', fn () => DB::table('conversations')
                    ->whereIn('status', ['open', 'pending_details', 'for_confirmation'])
                    ->count()),
                'webhook_events_today' => $this->countWhenReady('facebook_webhook_events', fn () => DB::table('facebook_webhook_events')
                    ->whereDate('created_at', today())
                    ->count()),
            ],
            'page_performance' => $this->pagePerformanceReport($filters),
            'agent_performance' => $this->agentPerformanceReport($filters),
            'conversation_statuses' => $this->conversationStatusReport($filters),
            'order_statuses' => $this->orderStatusReport($filters),
            'top_products' => $this->topProductReport($filters),
            'daily_sales' => $this->dailySalesReport($filters),
            'filters' => $filters,
            'pages' => FacebookPage::query()->orderBy('page_name')->get(['id', 'page_name']),
            'agents' => $this->shopAgents(),
        ]);
    }

    public function orders(Request $request): Response
    {
        $filters = [
            'q' => $request->string('q')->toString(),
            'status' => $request->string('status')->toString(),
            'source_channel' => $request->string('source_channel')->toString(),
            'fulfillment' => $request->string('fulfillment')->toString(),
            'page_id' => $request->string('page_id')->toString(),
        ];

        $query = Order::query()
            ->with([
                'customer:id,name,phone,normalized_phone,risk_level,is_blacklisted',
                'facebookPage:id,page_name,page_id',
                'product:id,name,sku',
                'shopItems:id,order_id,product_name,quantity,line_total',
            ])
            ->whereIn('source_channel', ['manual_shop', 'facebook_shop', 'walk_in', 'phone_order']);

        if ($filters['q'] !== '') {
            $search = $filters['q'];
            $query->where(function ($query) use ($search) {
                $query->where('order_number', 'ILIKE', "%{$search}%")
                    ->orWhere('receiver_name', 'ILIKE', "%{$search}%")
                    ->orWhere('receiver_phone', 'ILIKE', "%{$search}%");
            });
        }

        if ($filters['status'] !== '') {
            $query->where('status', $filters['status']);
        }

        if (in_array($filters['source_channel'], ['manual_shop', 'facebook_shop', 'walk_in', 'phone_order'], true)) {
            $query->where('source_channel', $filters['source_channel']);
        }

        if ($filters['page_id'] !== '') {
            $query->where('facebook_page_id', (int) $filters['page_id']);
        }

        if ($filters['fulfillment'] === 'needs_encoding') {
            $query->whereIn('status', [OrderStatus::CONFIRMED, OrderStatus::QA_APPROVED])
                ->whereNull('encoded_at');
        } elseif ($filters['fulfillment'] === 'encoded') {
            $query->whereNotNull('encoded_at');
        } elseif ($filters['fulfillment'] === 'export_pending') {
            $query->where('export_status', 'pending');
        }

        $summaryBase = Order::query()
            ->whereIn('source_channel', ['manual_shop', 'facebook_shop', 'walk_in', 'phone_order']);

        return Inertia::render('Shop/Orders', [
            'orders' => $query->latest()->paginate(20)->withQueryString(),
            'filters' => $filters,
            'statuses' => collect(OrderStatus::cases())
                ->map(fn (OrderStatus $status) => [
                    'value' => $status->value,
                    'label' => $status->label(),
                ])
                ->values(),
            'status_counts' => (clone $summaryBase)
                ->select('status', DB::raw('COUNT(*) as total'))
                ->groupBy('status')
                ->pluck('total', 'status'),
            'channel_counts' => [
                'all' => (clone $summaryBase)->count(),
                'manual_shop' => (clone $summaryBase)->where('source_channel', 'manual_shop')->count(),
                'facebook_shop' => (clone $summaryBase)->where('source_channel', 'facebook_shop')->count(),
                'walk_in' => (clone $summaryBase)->where('source_channel', 'walk_in')->count(),
                'phone_order' => (clone $summaryBase)->where('source_channel', 'phone_order')->count(),
            ],
            'page_tabs' => FacebookPage::query()
                ->orderBy('page_name')
                ->get(['id', 'page_name'])
                ->map(fn (FacebookPage $page) => [
                    'id' => $page->id,
                    'page_name' => $page->page_name,
                    'orders_count' => (clone $summaryBase)->where('facebook_page_id', $page->id)->count(),
                ])
                ->filter(fn (array $page) => $page['orders_count'] > 0)
                ->values(),
            'summary' => [
                'orders_today' => (clone $summaryBase)->whereDate('created_at', today())->count(),
                'sales_today' => (clone $summaryBase)->whereDate('created_at', today())->sum('total_amount'),
                'needs_encoding' => $this->forEncodingCount(),
                'open_orders' => (clone $summaryBase)->whereNotIn('status', [
                    OrderStatus::DELIVERED->value,
                    OrderStatus::RETURNED->value,
                    OrderStatus::CANCELLED->value,
                    OrderStatus::QA_REJECTED->value,
                ])->count(),
            ],
        ]);
    }

    public function customers(Request $request): Response
    {
        $filters = [
            'q' => $request->string('q')->toString(),
            'risk_level' => $request->string('risk_level')->toString(),
        ];

        $query = Customer::query()
            ->select([
                'customers.id',
                'customers.name',
                'customers.phone',
                'customers.normalized_phone',
                'customers.facebook_name',
                'customers.canonical_address',
                'customers.barangay',
                'customers.city_municipality',
                'customers.province',
                'customers.last_order_date',
                'customers.total_orders',
                'customers.successful_orders',
                'customers.returned_orders',
                'customers.success_rate',
                'customers.total_revenue',
                'customers.risk_level',
                'customers.is_blacklisted',
                'customers.blacklist_reason',
                'customers.updated_at',
            ])
            ->selectSub(function ($query) {
                $query->from('orders')
                    ->selectRaw('COUNT(*)')
                    ->whereColumn('orders.customer_id', 'customers.id')
                    ->whereIn('orders.source_channel', ['manual_shop', 'facebook_shop', 'walk_in', 'phone_order'])
                    ->whereNull('orders.deleted_at');
            }, 'shop_orders_count')
            ->selectSub(function ($query) {
                $query->from('orders')
                    ->selectRaw('COALESCE(SUM(total_amount), 0)')
                    ->whereColumn('orders.customer_id', 'customers.id')
                    ->whereIn('orders.source_channel', ['manual_shop', 'facebook_shop', 'walk_in', 'phone_order'])
                    ->whereNull('orders.deleted_at');
            }, 'shop_revenue_total')
            ->selectSub(function ($query) {
                $query->from('conversations')
                    ->selectRaw('COUNT(*)')
                    ->whereColumn('conversations.customer_id', 'customers.id')
                    ->whereNull('conversations.deleted_at');
            }, 'conversations_count');

        if ($filters['q'] !== '') {
            $search = $filters['q'];
            $query->where(function ($query) use ($search) {
                $query->where('customers.name', 'ILIKE', "%{$search}%")
                    ->orWhere('customers.phone', 'ILIKE', "%{$search}%")
                    ->orWhere('customers.normalized_phone', 'ILIKE', "%{$search}%")
                    ->orWhere('customers.facebook_name', 'ILIKE', "%{$search}%");
            });
        }

        if (in_array($filters['risk_level'], ['LOW', 'MEDIUM', 'HIGH', 'BLACKLISTED'], true)) {
            $query->where('customers.risk_level', $filters['risk_level']);
        }

        return Inertia::render('Shop/Customers', [
            'customers' => $query
                ->orderByRaw('customers.last_order_date IS NULL')
                ->latest('customers.last_order_date')
                ->latest('customers.updated_at')
                ->paginate(24)
                ->withQueryString(),
            'filters' => $filters,
            'risk_levels' => ['LOW', 'MEDIUM', 'HIGH', 'BLACKLISTED'],
            'summary' => [
                'customers' => Customer::query()->count(),
                'with_shop_orders' => Customer::query()
                    ->whereExists(function ($query) {
                        $query->from('orders')
                            ->selectRaw('1')
                            ->whereColumn('orders.customer_id', 'customers.id')
                            ->whereIn('orders.source_channel', ['manual_shop', 'facebook_shop', 'walk_in', 'phone_order'])
                            ->whereNull('orders.deleted_at');
                    })
                    ->count(),
                'high_risk' => Customer::query()
                    ->whereIn('risk_level', ['HIGH', 'BLACKLISTED'])
                    ->count(),
                'blacklisted' => Customer::query()
                    ->where('is_blacklisted', true)
                    ->count(),
            ],
        ]);
    }

    public function order(Order $order): Response
    {
        abort_unless(in_array($order->source_channel, ['manual_shop', 'facebook_shop', 'walk_in', 'phone_order'], true), 404);

        $order->load([
            'customer:id,name,phone,normalized_phone,facebook_name,canonical_address,landmark,barangay,city_municipality,province,total_orders,successful_orders,returned_orders,success_rate,total_revenue,risk_level,is_blacklisted,blacklist_reason,last_order_date',
            'facebookPage:id,page_name,page_id',
            'product:id,name,sku,selling_price',
            'variant:id,product_id,sku,variant_name,selling_price',
            'shopItems:id,order_id,product_id,variant_id,sku,product_name,quantity,unit_price,discount_amount,line_total',
            'agent:id,name',
            'remarks' => fn ($query) => $query->with('user:id,name')->latest()->limit(20),
        ]);

        return Inertia::render('Shop/Order', [
            'order' => $order,
            'products' => Product::query()
                ->with([
                    'stock:id,product_id,variant_id,current_stock,reserved_stock,reorder_point',
                    'activeVariants:id,product_id,sku,variant_name,selling_price',
                    'activeVariants.stock:id,product_id,variant_id,current_stock,reserved_stock,reorder_point',
                ])
                ->where('is_active', true)
                ->orderBy('name')
                ->get(['id', 'sku', 'name', 'selling_price'])
                ->map(fn (Product $product) => [
                    'id' => $product->id,
                    'sku' => $product->sku,
                    'name' => $product->name,
                    'selling_price' => $product->selling_price,
                    'available_stock' => $product->stock
                        ? max(0, (int) $product->stock->current_stock - (int) $product->stock->reserved_stock)
                        : null,
                    'is_low_stock' => $product->stock
                        ? ((int) $product->stock->current_stock - (int) $product->stock->reserved_stock) <= (int) $product->stock->reorder_point
                        : false,
                    'active_variants' => $product->activeVariants->map(fn ($variant) => [
                        'id' => $variant->id,
                        'sku' => $variant->sku,
                        'variant_name' => $variant->variant_name,
                        'selling_price' => $variant->selling_price,
                        'available_stock' => $variant->stock
                            ? max(0, (int) $variant->stock->current_stock - (int) $variant->stock->reserved_stock)
                            : null,
                        'is_low_stock' => $variant->stock
                            ? ((int) $variant->stock->current_stock - (int) $variant->stock->reserved_stock) <= (int) $variant->stock->reorder_point
                            : false,
                    ])->values(),
                ])->values(),
            'statuses' => collect(OrderStatus::cases())
                ->map(fn (OrderStatus $status) => [
                    'value' => $status->value,
                    'label' => $status->label(),
                ])
                ->values(),
            'agents' => $this->shopAgents(),
            'couriers' => [
                ['value' => 'MANUAL', 'label' => 'Manual'],
                ['value' => 'JNT', 'label' => 'J&T Express'],
                ['value' => 'FLASH', 'label' => 'Flash Express'],
            ],
        ]);
    }

    public function updateOrder(Request $request, Order $order): RedirectResponse
    {
        abort_unless(in_array($order->source_channel, ['manual_shop', 'facebook_shop', 'walk_in', 'phone_order'], true), 404);

        $validated = $request->validate([
            'assigned_agent_id' => ['nullable', 'integer', 'exists:users,id'],
            'status' => ['required', 'string', 'in:' . collect(OrderStatus::cases())->pluck('value')->implode(',')],
            'courier_code' => ['nullable', 'string', 'max:30'],
            'receiver_name' => ['required', 'string', 'max:255'],
            'receiver_phone' => ['required', 'string', 'max:30'],
            'receiver_address' => ['required', 'string', 'max:2000'],
            'barangay' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:255'],
            'state' => ['nullable', 'string', 'max:255'],
            'postal_code' => ['nullable', 'string', 'max:30'],
            'shipping_cost' => ['nullable', 'numeric', 'min:0', 'max:999999.99'],
            'discount_amount' => ['nullable', 'numeric', 'min:0', 'max:999999.99'],
            'surcharge_amount' => ['nullable', 'numeric', 'min:0', 'max:999999.99'],
            'paid_amount' => ['nullable', 'numeric', 'min:0', 'max:999999.99'],
            'payment_method' => ['nullable', 'string', 'in:COD,CASH,GCASH,BANK_TRANSFER,CARD'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'internal_note' => ['nullable', 'string', 'max:2000'],
            'items' => ['nullable', 'array', 'max:20'],
            'items.*.product_id' => ['nullable', 'integer', 'exists:products,id'],
            'items.*.variant_id' => ['nullable', 'integer', 'exists:product_variants,id'],
            'items.*.product_name' => ['nullable', 'string', 'max:255'],
            'items.*.quantity' => ['nullable', 'integer', 'min:1', 'max:999'],
            'items.*.unit_price' => ['nullable', 'numeric', 'min:0', 'max:999999.99'],
        ]);

        $preparedItems = null;

        if (array_key_exists('items', $validated)) {
            $requestedItems = collect($validated['items'] ?? [])
                ->filter(fn (array $item) => ! empty($item['product_id']) || trim((string) ($item['product_name'] ?? '')) !== '')
                ->values();

            $products = Product::query()
                ->with('variants:id,product_id,sku,variant_name,selling_price')
                ->whereIn('id', $requestedItems->pluck('product_id')->all())
                ->get()
                ->keyBy('id');

            $preparedItems = $requestedItems->map(function (array $item) use ($products) {
                /** @var Product|null $product */
                $product = ! empty($item['product_id']) ? $products->get((int) $item['product_id']) : null;

                if (! empty($item['product_id']) && ! $product) {
                    throw ValidationException::withMessages([
                        'items' => 'One selected product was not found.',
                    ]);
                }

                $variant = null;

                if (! empty($item['variant_id']) && $product) {
                    $variant = $product->variants->firstWhere('id', (int) $item['variant_id']);

                    if (! $variant) {
                        throw ValidationException::withMessages([
                            'items' => 'One selected variant does not belong to its product.',
                        ]);
                    }
                }

                $quantity = max(1, (int) ($item['quantity'] ?? 1));
                $unitPrice = (float) ($item['unit_price'] ?? $variant?->selling_price ?? $product?->selling_price ?? 0);
                $lineTotal = $quantity * $unitPrice;
                $displayName = $product
                    ? ($variant ? "{$product->name} - {$variant->variant_name}" : $product->name)
                    : trim((string) ($item['product_name'] ?? 'Manual item'));

                return [
                    'product' => $product,
                    'variant' => $variant,
                    'quantity' => $quantity,
                    'unit_price' => $unitPrice,
                    'line_total' => $lineTotal,
                    'display_name' => $displayName,
                    'sku' => $variant?->sku ?? $product?->sku,
                ];
            })->values();
        }

        if (($validated['status'] ?? $order->status->value) !== OrderStatus::PENDING->value && $preparedItems !== null && $preparedItems->isEmpty()) {
            throw ValidationException::withMessages([
                'items' => 'Add at least one product before moving this order beyond Pending.',
            ]);
        }

        if (($validated['status'] ?? $order->status->value) !== OrderStatus::PENDING->value && trim($validated['receiver_address']) === 'Pending address from Messenger') {
            throw ValidationException::withMessages([
                'receiver_address' => 'Replace the Messenger placeholder with the customer delivery address before confirming.',
            ]);
        }

        $itemsTotal = $preparedItems !== null
            ? (float) $preparedItems->sum('line_total')
            : (float) ShopOrderItem::query()
                ->where('order_id', $order->id)
                ->sum('line_total');
        $primaryItem = $preparedItems?->first();
        $totalQuantity = $preparedItems !== null ? (int) $preparedItems->sum('quantity') : (int) $order->quantity;
        $shippingCost = (float) ($validated['shipping_cost'] ?? 0);
        $discountAmount = (float) ($validated['discount_amount'] ?? $order->discount_amount ?? 0);
        $surchargeAmount = (float) ($validated['surcharge_amount'] ?? $order->surcharge_amount ?? 0);
        $paidAmount = (float) ($validated['paid_amount'] ?? $order->paid_amount ?? 0);
        $totalAmount = max(0, $itemsTotal + $shippingCost + $surchargeAmount - $discountAmount);
        $codAmount = max(0, $totalAmount - $paidAmount);
        $paymentStatus = match (true) {
            $paidAmount <= 0 => 'UNPAID',
            $paidAmount >= $totalAmount => 'PAID',
            default => 'PARTIAL',
        };
        $addressMatch = $this->addressMappings->match([
            'province' => $validated['state'] ?? null,
            'city_municipality' => $validated['city'] ?? null,
            'barangay' => $validated['barangay'] ?? null,
            'address' => $validated['receiver_address'],
        ]);

        DB::transaction(function () use (
            $order,
            $validated,
            $shippingCost,
            $discountAmount,
            $surchargeAmount,
            $paidAmount,
            $totalAmount,
            $codAmount,
            $paymentStatus,
            $addressMatch,
            $preparedItems,
            $primaryItem,
            $totalQuantity
        ) {
            $order->forceFill([
                'assigned_agent_id' => $validated['assigned_agent_id'] ?? null,
                'status' => $validated['status'],
                'courier_code' => $validated['courier_code'] ?? 'MANUAL',
                'product_id' => $primaryItem['product']?->id ?? ($preparedItems !== null ? null : $order->product_id),
                'variant_id' => $primaryItem['variant']?->id ?? ($preparedItems !== null ? null : $order->variant_id),
                'quantity' => $totalQuantity,
                'unit_price' => $primaryItem['unit_price'] ?? ($preparedItems !== null ? 0 : $order->unit_price),
                'receiver_name' => $validated['receiver_name'],
                'receiver_phone' => $this->phones->normalize($validated['receiver_phone']) ?: $validated['receiver_phone'],
                'receiver_address' => $validated['receiver_address'],
                'barangay' => $validated['barangay'] ?? null,
                'city' => $validated['city'] ?? null,
                'state' => $validated['state'] ?? null,
                'postal_code' => $validated['postal_code'] ?? null,
                'shipping_cost' => $shippingCost,
                'total_amount' => $totalAmount,
                'cod_amount' => $codAmount,
                'payment_method' => $validated['payment_method'] ?? $order->payment_method ?? 'COD',
                'payment_status' => $paymentStatus,
                'paid_amount' => $paidAmount,
                'discount_amount' => $discountAmount,
                'surcharge_amount' => $surchargeAmount,
                'notes' => $validated['notes'] ?? null,
                'address_mapping_id' => $addressMatch['mapping']?->id,
                'address_confidence' => $addressMatch['confidence'],
            ])->save();

            if ($preparedItems !== null) {
                ShopOrderItem::query()
                    ->where('order_id', $order->id)
                    ->delete();

                foreach ($preparedItems as $item) {
                    ShopOrderItem::query()->create([
                        'order_id' => $order->id,
                        'product_id' => $item['product']?->id,
                        'variant_id' => $item['variant']?->id,
                        'sku' => $item['sku'],
                        'product_name' => $item['display_name'],
                        'quantity' => $item['quantity'],
                        'unit_price' => $item['unit_price'],
                        'line_total' => $item['line_total'],
                    ]);
                }
            }

            if ($order->customer) {
                $order->customer->forceFill([
                    'name' => $validated['receiver_name'],
                    'phone' => $validated['receiver_phone'],
                    'normalized_phone' => $this->phones->normalize($validated['receiver_phone']),
                    'canonical_address' => $validated['receiver_address'],
                    'barangay' => $validated['barangay'] ?? null,
                    'city_municipality' => $validated['city'] ?? null,
                    'province' => $validated['state'] ?? null,
                    'region' => $addressMatch['mapping']?->region ?? $order->customer->region,
                ])->save();
            }

            if (! empty($validated['internal_note'])) {
                OrderRemark::query()->create([
                    'order_id' => $order->id,
                    'conversation_id' => $order->conversation_id,
                    'user_id' => auth()->id(),
                    'type' => 'agent_note',
                    'body' => $validated['internal_note'],
                ]);
            }
        });

        return back()->with('success', "{$order->order_number} updated.");
    }

    public function templates(): Response
    {
        return Inertia::render('Shop/Templates', [
            'templates' => ShopReplyTemplate::query()
                ->with('creator:id,name')
                ->orderBy('sort_order')
                ->orderBy('name')
                ->paginate(24),
        ]);
    }

    public function storeTemplate(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'message' => ['required', 'string', 'max:2000'],
            'category' => ['nullable', 'string', 'max:50'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:9999'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        preg_match_all('/\{(\w+)\}/', $validated['message'], $matches);

        ShopReplyTemplate::query()->create([
            'name' => $validated['name'],
            'message' => $validated['message'],
            'category' => $validated['category'] ?? null,
            'variables' => $matches[0] ?? [],
            'sort_order' => $validated['sort_order'] ?? 0,
            'is_active' => $validated['is_active'] ?? true,
            'created_by' => $request->user()->id,
        ]);

        return back()->with('success', 'Shop reply template created.');
    }

    public function destroyTemplate(ShopReplyTemplate $template): RedirectResponse
    {
        $template->delete();

        return back()->with('success', 'Shop reply template deleted.');
    }

    public function simulateWebhook(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'facebook_page_id' => ['required', 'integer', 'exists:facebook_pages,id'],
            'sender_psid' => ['nullable', 'string', 'max:255'],
            'body' => ['required', 'string', 'max:2000'],
        ]);

        $page = FacebookPage::query()->findOrFail($validated['facebook_page_id']);
        $senderPsid = $validated['sender_psid'] ?: 'manual-test-' . Str::lower(Str::random(10));
        $eventId = 'manual-' . str()->uuid();

        $payload = [
            'sender' => ['id' => $senderPsid],
            'recipient' => ['id' => $page->page_id],
            'timestamp' => now()->getTimestampMs(),
            'message' => [
                'mid' => $eventId,
                'text' => $validated['body'],
            ],
        ];

        $event = FacebookWebhookEvent::query()->create([
            'facebook_page_id' => $page->id,
            'event_id' => $eventId,
            'object' => 'page',
            'event_type' => 'messaging',
            'sender_psid' => $senderPsid,
            'recipient_id' => $page->page_id,
            'payload' => $payload,
            'signature_valid' => false,
        ]);

        $this->metaIngestor->process($event);

        return redirect()
            ->route('shop.inbox')
            ->with('success', 'Simulated inbound message processed. Check the Shop inbox.');
    }

    public function inbox(Request $request): Response
    {
        $query = Conversation::query()
            ->with([
                'facebookPage:id,page_name,page_id',
                'customer:id,name,phone,normalized_phone',
                'identity:id,display_name,phone_detected',
                'assignedAgent:id,name',
            ])
            ->withCount('messages')
            ->latest('last_message_at');

        if ($request->filled('page_id')) {
            $query->where('facebook_page_id', $request->integer('page_id'));
        }

        if ($request->filled('status')) {
            $query->where('status', $request->string('status'));
        }

        if ($request->filled('assigned_agent_id')) {
            $request->string('assigned_agent_id')->toString() === 'unassigned'
                ? $query->whereNull('assigned_agent_id')
                : $query->where('assigned_agent_id', $request->integer('assigned_agent_id'));
        }

        return Inertia::render('Shop/Inbox', [
            'conversations' => $query->paginate(20)->withQueryString(),
            'pages' => FacebookPage::query()->orderBy('page_name')->get(['id', 'page_id', 'page_name']),
            'agents' => $this->shopAgents(),
            'statuses' => $this->conversationStatuses(),
            'filters' => $request->only(['page_id', 'status', 'assigned_agent_id']),
        ]);
    }

    public function conversation(Conversation $conversation): Response
    {
        $conversation->load([
            'facebookPage:id,page_id,page_name,webhook_status',
            'customer:id,name,phone,normalized_phone,canonical_address,landmark,barangay,city_municipality,province,region,last_order_date,total_orders,successful_orders,returned_orders,success_rate,total_revenue,risk_level,is_blacklisted,blacklist_reason',
            'identity:id,display_name,provider_user_id,phone_detected',
            'assignedAgent:id,name',
            'messages' => fn ($query) => $query->orderBy('sent_at')->orderBy('id'),
        ]);

        $conversation->forceFill(['unread_count' => 0])->save();

        return Inertia::render('Shop/Conversation', [
            'conversation' => $conversation,
            'recent_orders' => $conversation->customer_id
                ? Order::query()
                    ->with('product:id,name,sku')
                    ->where('customer_id', $conversation->customer_id)
                    ->latest()
                    ->limit(5)
                    ->get(['id', 'order_number', 'product_id', 'status', 'total_amount', 'receiver_address', 'created_at'])
                : [],
            'quick_replies' => $this->quickRepliesForConversation($conversation),
            'saved_templates' => $this->savedTemplatesForConversation($conversation),
            'agents' => $this->shopAgents(),
            'statuses' => $this->conversationStatuses(),
        ]);
    }

    public function updateCustomer(Request $request, Customer $customer): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'phone' => ['required', 'string', 'max:30'],
            'canonical_address' => ['nullable', 'string', 'max:2000'],
            'landmark' => ['nullable', 'string', 'max:255'],
            'barangay' => ['nullable', 'string', 'max:255'],
            'city_municipality' => ['nullable', 'string', 'max:255'],
            'province' => ['nullable', 'string', 'max:255'],
        ]);

        $addressMatch = $this->addressMappings->match([
            'province' => $validated['province'] ?? null,
            'city_municipality' => $validated['city_municipality'] ?? null,
            'barangay' => $validated['barangay'] ?? null,
            'address' => $validated['canonical_address'] ?? '',
        ]);

        $customer->forceFill([
            'name' => $validated['name'],
            'phone' => $validated['phone'],
            'normalized_phone' => $this->phones->normalize($validated['phone']),
            'canonical_address' => $validated['canonical_address'] ?? null,
            'landmark' => $validated['landmark'] ?? null,
            'barangay' => $validated['barangay'] ?? null,
            'city_municipality' => $validated['city_municipality'] ?? null,
            'province' => $validated['province'] ?? null,
            'region' => $addressMatch['mapping']?->region ?? $customer->region,
        ])->save();

        return back()->with('success', 'Customer profile updated.');
    }

    public function updateConversationAssignment(Request $request, Conversation $conversation): RedirectResponse
    {
        $validated = $request->validate([
            'assigned_agent_id' => ['nullable', 'integer', 'exists:users,id'],
        ]);

        $conversation->forceFill([
            'assigned_agent_id' => $validated['assigned_agent_id'] ?? null,
        ])->save();

        return back()->with('success', 'Conversation assignment updated.');
    }

    public function updateConversationStatus(Request $request, Conversation $conversation): RedirectResponse
    {
        $validated = $request->validate([
            'status' => ['required', 'string', 'in:' . implode(',', $this->conversationStatuses())],
        ]);

        $conversation->forceFill([
            'status' => $validated['status'],
        ])->save();

        return back()->with('success', 'Conversation status updated.');
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
                ->with(['customer:id,name,phone,normalized_phone', 'product:id,name,sku', 'shopItems:id,order_id,product_name,quantity'])
                ->whereIn('source_channel', ['manual_shop', 'facebook_shop', 'walk_in', 'phone_order'])
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
            ->with(['product:id,name,sku', 'shopItems:id,order_id,product_name,quantity'])
            ->whereIn('source_channel', ['manual_shop', 'facebook_shop', 'walk_in', 'phone_order'])
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

        $state = Str::random(48);
        session(['shop_facebook_oauth_state' => $state]);

        return redirect()->away($this->facebookConnector->authorizationUrl($state));
    }

    public function facebookCallback(Request $request): RedirectResponse
    {
        if ($request->filled('error')) {
            session()->forget('shop_facebook_oauth_state');

            return redirect()
                ->route('shop.meta-readiness')
                ->with('error', (string) $request->query('error_description', 'Facebook connection cancelled before WarehouseOps could sync Pages.'));
        }

        $request->validate(['code' => ['required', 'string']]);

        $expectedState = (string) session('shop_facebook_oauth_state', '');
        session()->forget('shop_facebook_oauth_state');

        if ($expectedState === '' || ! hash_equals($expectedState, (string) $request->query('state', ''))) {
            return redirect()->route('shop.meta-readiness')->with('error', 'Facebook connection state check failed. Start the connection again from Meta Readiness.');
        }

        try {
            $pageCount = $this->facebookConnector->connectFromCallback($request->user(), (string) $request->query('code'));
        } catch (\Throwable $exception) {
            return redirect()
                ->route('shop.meta-readiness')
                ->with('error', "Facebook connection failed after callback: {$exception->getMessage()}");
        }

        return redirect()
            ->route('shop.index')
            ->with('success', "Facebook connected. {$pageCount} Pages synced.");
    }

    public function storeManualFacebookPage(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'page_id' => ['required', 'string', 'max:255'],
            'page_name' => ['required', 'string', 'max:255'],
            'category' => ['nullable', 'string', 'max:255'],
            'page_access_token' => ['nullable', 'string', 'max:2000'],
        ]);

        $hasPageToken = filled($validated['page_access_token'] ?? null);

        FacebookPage::query()->updateOrCreate(
            ['page_id' => $validated['page_id']],
            [
                'connected_by' => $request->user()->id,
                'page_name' => $validated['page_name'],
                'category' => $validated['category'] ?? null,
                'page_access_token' => $hasPageToken ? $validated['page_access_token'] : null,
                'connected_status' => $hasPageToken ? 'connected' : 'manual',
                'webhook_status' => $hasPageToken ? 'pending' : 'manual_pending',
                'last_sync_at' => now(),
                'metadata' => [
                    'connection_mode' => 'manual_page_registration',
                    'manual_registered_at' => now()->toIso8601String(),
                    'manual_registered_by' => $request->user()->id,
                    'token_present' => $hasPageToken,
                    'public_oauth_bypass' => true,
                ],
            ]
        );

        return back()->with(
            'success',
            $hasPageToken
                ? 'Facebook Page registered manually. Subscribe/check webhooks next.'
                : 'Facebook Page registered manually. Webhook ingestion can match this Page ID, but replies/profile sync need a Page access token.'
        );
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

    public function createOrder(Request $request): Response
    {
        $conversation = null;

        if ($request->filled('conversation_id')) {
            $conversation = Conversation::query()
                ->with([
                    'facebookPage:id,page_name,page_id',
                    'customer:id,name,phone,normalized_phone,canonical_address',
                    'identity:id,display_name,provider_user_id,phone_detected',
                    'messages' => fn ($query) => $query->latest('sent_at')->limit(5),
                ])
                ->find($request->integer('conversation_id'));
        }

        return Inertia::render('Shop/CreateOrder', [
            'products' => Product::query()
                ->with([
                    'stock:id,product_id,variant_id,current_stock,reserved_stock,reorder_point',
                    'activeVariants:id,product_id,sku,variant_name,selling_price',
                    'activeVariants.stock:id,product_id,variant_id,current_stock,reserved_stock,reorder_point',
                ])
                ->where('is_active', true)
                ->orderBy('name')
                ->get(['id', 'sku', 'name', 'selling_price'])
                ->map(fn (Product $product) => [
                    'id' => $product->id,
                    'sku' => $product->sku,
                    'name' => $product->name,
                    'selling_price' => $product->selling_price,
                    'available_stock' => $product->stock
                        ? max(0, (int) $product->stock->current_stock - (int) $product->stock->reserved_stock)
                        : null,
                    'is_low_stock' => $product->stock
                        ? ((int) $product->stock->current_stock - (int) $product->stock->reserved_stock) <= (int) $product->stock->reorder_point
                        : false,
                    'active_variants' => $product->activeVariants->map(fn ($variant) => [
                        'id' => $variant->id,
                        'sku' => $variant->sku,
                        'variant_name' => $variant->variant_name,
                        'selling_price' => $variant->selling_price,
                        'available_stock' => $variant->stock
                            ? max(0, (int) $variant->stock->current_stock - (int) $variant->stock->reserved_stock)
                            : null,
                        'is_low_stock' => $variant->stock
                            ? ((int) $variant->stock->current_stock - (int) $variant->stock->reserved_stock) <= (int) $variant->stock->reorder_point
                            : false,
                    ])->values(),
                ])->values(),
            'customers' => Customer::query()
                ->latest('last_order_date')
                ->limit(80)
                ->get([
                    'id',
                    'name',
                    'phone',
                    'normalized_phone',
                    'canonical_address',
                    'landmark',
                    'barangay',
                    'city_municipality',
                    'province',
                    'risk_level',
                    'is_blacklisted',
                    'total_orders',
                    'successful_orders',
                    'returned_orders',
                    'success_rate',
                    'last_order_date',
                ]),
            'facebook_pages' => FacebookPage::query()
                ->where('connected_status', 'connected')
                ->orderBy('page_name')
                ->get(['id', 'page_name', 'page_id', 'webhook_status']),
            'couriers' => [
                ['value' => 'MANUAL', 'label' => 'Manual'],
                ['value' => 'JNT', 'label' => 'J&T Express'],
                ['value' => 'FLASH', 'label' => 'Flash Express'],
            ],
            'payment_methods' => [
                ['value' => 'COD', 'label' => 'COD'],
                ['value' => 'CASH', 'label' => 'Cash'],
                ['value' => 'GCASH', 'label' => 'GCash'],
                ['value' => 'BANK_TRANSFER', 'label' => 'Bank transfer'],
                ['value' => 'CARD', 'label' => 'Card'],
            ],
            'prefill' => $conversation ? [
                'conversation_id' => $conversation->id,
                'customer_name' => $conversation->customer?->name
                    ?? $conversation->identity?->display_name
                    ?? 'Facebook Customer',
                'phone' => $conversation->customer?->normalized_phone
                    ?? $conversation->customer?->phone
                    ?? $conversation->identity?->phone_detected
                    ?? '',
                'complete_address' => $conversation->customer?->canonical_address ?? '',
                'remarks' => trim(implode("\n", array_filter([
                    "Conversation #{$conversation->id}",
                    $conversation->facebookPage ? "Page: {$conversation->facebookPage->page_name}" : null,
                    $conversation->last_message_preview ? "Last message: {$conversation->last_message_preview}" : null,
                ]))),
            ] : null,
            'duplicate_warnings' => $this->duplicateWarningsForPhone(
                $conversation?->customer?->normalized_phone
                    ?? $conversation?->customer?->phone
                    ?? $conversation?->identity?->phone_detected
            ),
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
            'items' => ['required', 'array', 'min:1', 'max:20'],
            'items.*.product_id' => ['nullable', 'exists:products,id'],
            'items.*.variant_id' => ['nullable', 'exists:product_variants,id'],
            'items.*.product_name' => ['nullable', 'string', 'max:255'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:999'],
            'items.*.unit_price' => ['required', 'numeric', 'min:0', 'max:999999.99'],
            'shipping_fee' => ['nullable', 'numeric', 'min:0', 'max:999999.99'],
            'discount_amount' => ['nullable', 'numeric', 'min:0', 'max:999999.99'],
            'surcharge_amount' => ['nullable', 'numeric', 'min:0', 'max:999999.99'],
            'paid_amount' => ['nullable', 'numeric', 'min:0', 'max:999999.99'],
            'payment_method' => ['nullable', 'string', 'in:COD,CASH,GCASH,BANK_TRANSFER,CARD'],
            'initial_status' => ['nullable', 'string', 'in:PENDING,CONFIRMED'],
            'sales_channel' => ['nullable', 'string', 'in:walk_in,manual_shop,facebook_shop,phone_order'],
            'facebook_page_id' => ['nullable', 'integer', 'exists:facebook_pages,id'],
            'courier_code' => ['nullable', 'string', 'max:30'],
            'remarks' => ['nullable', 'string', 'max:2000'],
            'conversation_id' => ['nullable', 'integer', 'exists:conversations,id'],
        ]);

        $products = Product::query()
            ->with('variants:id,product_id,sku,variant_name,selling_price')
            ->whereIn('id', collect($validated['items'])->pluck('product_id')->filter()->all())
            ->get()
            ->keyBy('id');

        $preparedItems = collect($validated['items'])->map(function (array $item) use ($products) {
            /** @var Product $product */
            $product = ! empty($item['product_id']) ? $products->get((int) $item['product_id']) : null;
            abort_unless($product || trim((string) ($item['product_name'] ?? '')) !== '', 422, 'Each cart item needs a catalog product or manual product name.');
            abort_unless(empty($item['product_id']) || $product, 422, 'Selected product was not found.');

            $variant = null;

            if (! empty($item['variant_id']) && $product) {
                $variant = $product->variants->firstWhere('id', (int) $item['variant_id']);
                abort_unless($variant, 422, 'Selected variant does not belong to the product.');
            }

            $quantity = (int) $item['quantity'];
            $unitPrice = (float) $item['unit_price'];
            $lineTotal = $quantity * $unitPrice;

            return [
                'product' => $product,
                'variant' => $variant,
                'quantity' => $quantity,
                'unit_price' => $unitPrice,
                'line_total' => $lineTotal,
                'display_name' => $product
                    ? ($variant ? "{$product->name} - {$variant->variant_name}" : $product->name)
                    : trim((string) ($item['product_name'] ?? 'Manual item')),
                'sku' => $variant?->sku ?? $product?->sku,
            ];
        })->values();

        $primaryItem = $preparedItems->first();
        abort_unless($primaryItem, 422, 'At least one cart item is required.');
        $shippingFee = (float) ($validated['shipping_fee'] ?? 0);
        $discountAmount = (float) ($validated['discount_amount'] ?? 0);
        $surchargeAmount = (float) ($validated['surcharge_amount'] ?? 0);
        $paidAmount = (float) ($validated['paid_amount'] ?? 0);
        $totalQuantity = (int) $preparedItems->sum('quantity');
        $itemsTotal = (float) $preparedItems->sum('line_total');
        $totalAmount = max(0, $itemsTotal + $shippingFee + $surchargeAmount - $discountAmount);
        $codAmount = max(0, $totalAmount - $paidAmount);
        $paymentMethod = $validated['payment_method'] ?? 'COD';
        $paymentStatus = match (true) {
            $paidAmount <= 0 => 'UNPAID',
            $paidAmount >= $totalAmount => 'PAID',
            default => 'PARTIAL',
        };
        $normalizedPhone = $this->phones->normalize($validated['phone']);
        $possibleDuplicates = $this->possibleDuplicateOrders(
            $normalizedPhone ?: $validated['phone'],
            $preparedItems->pluck('product.id')->filter()->map(fn ($id) => (int) $id)->all()
        );
        $addressMatch = $this->addressMappings->match([
            'province' => $validated['province'] ?? null,
            'city_municipality' => $validated['city_municipality'] ?? null,
            'barangay' => $validated['barangay'] ?? null,
            'address' => $validated['complete_address'],
        ]);

        $order = DB::transaction(function () use (
            $validated,
            $preparedItems,
            $primaryItem,
            $shippingFee,
            $discountAmount,
            $surchargeAmount,
            $paidAmount,
            $paymentMethod,
            $paymentStatus,
            $totalQuantity,
            $totalAmount,
            $codAmount,
            $normalizedPhone,
            $possibleDuplicates,
            $addressMatch
        ) {
            $conversation = ! empty($validated['conversation_id'])
                ? Conversation::query()->find($validated['conversation_id'])
                : null;

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
                'conversation_id' => $conversation?->id,
                'facebook_page_id' => $conversation?->facebook_page_id,
                'customer_id' => $customer->id,
                'product_id' => $primaryItem['product']?->id,
                'variant_id' => $primaryItem['variant']?->id,
                'assigned_agent_id' => auth()->id(),
                'status' => OrderStatus::from($validated['initial_status'] ?? OrderStatus::CONFIRMED->value),
                'courier_code' => $validated['courier_code'] ?? 'MANUAL',
                'quantity' => $totalQuantity,
                'unit_price' => $primaryItem['unit_price'],
                'total_amount' => $totalAmount,
                'cod_amount' => $codAmount,
                'shipping_cost' => $shippingFee,
                'payment_method' => $paymentMethod,
                'payment_status' => $paymentStatus,
                'paid_amount' => $paidAmount,
                'discount_amount' => $discountAmount,
                'surcharge_amount' => $surchargeAmount,
                'receiver_name' => $validated['customer_name'],
                'receiver_phone' => $normalizedPhone ?: $validated['phone'],
                'receiver_address' => $validated['complete_address'],
                'city' => $validated['city_municipality'] ?? null,
                'state' => $validated['province'] ?? null,
                'barangay' => $validated['barangay'] ?? null,
                'address_mapping_id' => $addressMatch['mapping']?->id,
                'source_channel' => $conversation ? 'facebook_shop' : ($validated['sales_channel'] ?? 'manual_shop'),
                'address_confidence' => $addressMatch['confidence'],
                'export_status' => 'pending',
                'confirmed_at' => ($validated['initial_status'] ?? OrderStatus::CONFIRMED->value) === OrderStatus::CONFIRMED->value ? now() : null,
                'notes' => $validated['remarks'] ?? null,
            ]);

            if (! $conversation && ! empty($validated['facebook_page_id']) && ($validated['sales_channel'] ?? null) === 'facebook_shop') {
                $order->forceFill(['facebook_page_id' => (int) $validated['facebook_page_id']])->save();
            }

            foreach ($preparedItems as $item) {
                ShopOrderItem::query()->create([
                    'order_id' => $order->id,
                    'product_id' => $item['product']?->id,
                    'variant_id' => $item['variant']?->id,
                    'sku' => $item['sku'],
                    'product_name' => $item['display_name'],
                    'quantity' => $item['quantity'],
                    'unit_price' => $item['unit_price'],
                    'line_total' => $item['line_total'],
                ]);
            }

            if (! empty($validated['remarks'])) {
                OrderRemark::query()->create([
                    'order_id' => $order->id,
                    'user_id' => auth()->id(),
                    'type' => 'agent_note',
                    'body' => $validated['remarks'],
                ]);
            }

            foreach ($possibleDuplicates as $possibleDuplicate) {
                OrderRemark::query()->create([
                    'order_id' => $order->id,
                    'user_id' => auth()->id(),
                    'type' => 'duplicate_warning',
                    'body' => "Possible duplicate of {$possibleDuplicate->order_number} from {$possibleDuplicate->created_at->format('M j, Y g:i A')}.",
                    'metadata' => [
                        'duplicate_order_id' => $possibleDuplicate->id,
                        'duplicate_order_number' => $possibleDuplicate->order_number,
                    ],
                ]);
            }

            if ($conversation) {
                OrderRemark::query()->create([
                    'order_id' => $order->id,
                    'user_id' => auth()->id(),
                    'type' => 'conversation_source',
                    'body' => "Created from Shop conversation #{$conversation->id}.",
                    'metadata' => [
                        'conversation_id' => $conversation->id,
                        'facebook_page_id' => $conversation->facebook_page_id,
                        'customer_identity_id' => $conversation->customer_identity_id,
                    ],
                ]);

                $conversation->forceFill([
                    'customer_id' => $customer->id,
                    'status' => 'converted',
                    'metadata' => array_merge($conversation->metadata ?? [], [
                        'latest_order_id' => $order->id,
                        'converted_at' => now()->toIso8601String(),
                    ]),
                ])->save();
            }

            $customer->forceFill([
                'last_order_date' => now(),
                'last_page_ordered_from' => $order->facebook_page_id,
                'total_orders' => ((int) $customer->total_orders) + 1,
                'total_revenue' => ((float) $customer->total_revenue) + $totalAmount,
            ])->save();

            return $order;
        });

        return redirect()
            ->route('shop.orders.show', $order)
            ->with(
                $possibleDuplicates->isNotEmpty() ? 'warning' : 'success',
                $possibleDuplicates->isNotEmpty()
                    ? "Shop order {$order->order_number} created. Possible duplicates found: {$possibleDuplicates->pluck('order_number')->implode(', ')}."
                    : "Shop order {$order->order_number} created."
            );
    }

    private function dashboardPayload(): array
    {
        return [
            'stats' => $this->stats(),
            'work_queues' => $this->workQueues(),
            'facebook_pages' => $this->dashboardFacebookPages(),
            'channel_metrics' => $this->channelMetrics(),
            'fulfillment_pipeline' => $this->fulfillmentPipeline(),
            'payment_mix' => $this->paymentMix(),
            'recent_orders' => $this->recentShopOrders(),
            'recent_conversations' => $this->recentShopConversations(),
            'dashboard_alerts' => $this->dashboardAlerts(),
        ];
    }

    private function stats(): array
    {
        $ordersToday = $this->countWhenReady('orders', fn () => $this->shopOrderQuery()
            ->whereDate('created_at', today())
            ->count());
        $salesToday = $this->sumWhenReady('orders', fn () => $this->shopOrderQuery()
            ->whereDate('created_at', today())
            ->sum('total_amount'));

        return [
            'connected_pages' => $this->countWhenReady('facebook_pages', fn () => DB::table('facebook_pages')
                ->where('connected_status', 'connected')
                ->count()),
            'open_conversations' => $this->countWhenReady('conversations', fn () => DB::table('conversations')
                ->where('status', 'open')
                ->count()),
            'orders_today' => $ordersToday,
            'sales_today' => $salesToday,
            'paid_today' => $this->sumWhenReady('orders', fn () => $this->shopOrderQuery()
                ->whereDate('created_at', today())
                ->sum('paid_amount')),
            'cod_receivable' => $this->sumWhenReady('orders', fn () => $this->shopOrderQuery()
                ->whereDate('created_at', today())
                ->sum('cod_amount')),
            'avg_order_value' => $ordersToday > 0 ? round($salesToday / $ordersToday, 2) : 0,
            'customers' => $this->countWhenReady('customers', fn () => DB::table('customers')->count()),
            'for_encoding' => $this->forEncodingCount(),
        ];
    }

    private function workQueues(): array
    {
        return [
            'inbox' => $this->countWhenReady('conversations', fn () => DB::table('conversations')
                ->whereIn('status', ['open', 'pending_details', 'for_confirmation'])
                ->count()),
            'pending_details' => $this->countWhenReady('conversations', fn () => DB::table('conversations')
                ->where('status', 'pending_details')
                ->count()),
            'ready_orders' => $this->forEncodingCount(),
            'courier_export' => $this->countWhenReady('courier_export_batches', fn () => DB::table('courier_export_batches')
                ->whereDate('created_at', today())
                ->count()),
            'pending_orders' => $this->countWhenReady('orders', fn () => $this->shopOrderQuery()
                ->where('status', OrderStatus::PENDING->value)
                ->count()),
            'unpaid_orders' => $this->countWhenReady('orders', fn () => $this->shopOrderQuery()
                ->whereIn('payment_status', ['UNPAID', 'PARTIAL'])
                ->whereNotIn('status', [
                    OrderStatus::DELIVERED->value,
                    OrderStatus::RETURNED->value,
                    OrderStatus::CANCELLED->value,
                    OrderStatus::QA_REJECTED->value,
                ])
                ->count()),
            'failed_webhooks' => $this->countWhenReady('facebook_webhook_events', fn () => DB::table('facebook_webhook_events')
                ->whereNull('processed_at')
                ->whereNotNull('error_message')
                ->count()),
        ];
    }

    private function dashboardFacebookPages(): \Illuminate\Support\Collection
    {
        if (! Schema::hasTable('facebook_pages')) {
            return collect();
        }

        return FacebookPage::query()
            ->latest('last_sync_at')
            ->limit(8)
            ->get(['id', 'page_id', 'page_name', 'connected_status', 'webhook_status', 'last_sync_at'])
            ->map(function (FacebookPage $page) {
                $ordersToday = Schema::hasTable('orders')
                    ? $this->shopOrderQuery()
                        ->where('facebook_page_id', $page->id)
                        ->whereDate('created_at', today())
                        ->count()
                    : 0;
                $salesToday = Schema::hasTable('orders')
                    ? $this->shopOrderQuery()
                        ->where('facebook_page_id', $page->id)
                        ->whereDate('created_at', today())
                        ->sum('total_amount')
                    : 0;

                return [
                    'id' => $page->id,
                    'page_id' => $page->page_id,
                    'page_name' => $page->page_name,
                    'connected_status' => $page->connected_status,
                    'webhook_status' => $page->webhook_status,
                    'last_sync_at' => optional($page->last_sync_at)?->toIso8601String(),
                    'orders_today' => (int) $ordersToday,
                    'sales_today' => (float) $salesToday,
                ];
            });
    }

    private function channelMetrics(): array
    {
        $channels = [
            'manual_shop' => 'Manual',
            'walk_in' => 'Walk-in',
            'facebook_shop' => 'Facebook',
            'phone_order' => 'Phone',
        ];

        return collect($channels)
            ->map(fn (string $label, string $channel) => [
                'channel' => $channel,
                'label' => $label,
                'orders_today' => $this->countWhenReady('orders', fn () => $this->shopOrderQuery()
                    ->where('source_channel', $channel)
                    ->whereDate('created_at', today())
                    ->count()),
                'sales_today' => $this->sumWhenReady('orders', fn () => $this->shopOrderQuery()
                    ->where('source_channel', $channel)
                    ->whereDate('created_at', today())
                    ->sum('total_amount')),
                'open_orders' => $this->countWhenReady('orders', fn () => $this->shopOrderQuery()
                    ->where('source_channel', $channel)
                    ->whereNotIn('status', [
                        OrderStatus::DELIVERED->value,
                        OrderStatus::RETURNED->value,
                        OrderStatus::CANCELLED->value,
                        OrderStatus::QA_REJECTED->value,
                    ])
                    ->count()),
            ])
            ->values()
            ->all();
    }

    private function fulfillmentPipeline(): array
    {
        $groups = [
            'capture' => ['label' => 'Capture', 'statuses' => [OrderStatus::PENDING->value]],
            'confirmed' => ['label' => 'Confirmed', 'statuses' => [OrderStatus::CONFIRMED->value, OrderStatus::QA_APPROVED->value]],
            'processing' => ['label' => 'Processing', 'statuses' => [OrderStatus::PROCESSING->value, OrderStatus::DISPATCHED->value]],
            'completed' => ['label' => 'Completed', 'statuses' => [OrderStatus::DELIVERED->value]],
            'exceptions' => ['label' => 'Exceptions', 'statuses' => [OrderStatus::RETURNED->value, OrderStatus::CANCELLED->value, OrderStatus::QA_REJECTED->value]],
        ];

        return collect($groups)
            ->map(fn (array $group, string $key) => [
                'key' => $key,
                'label' => $group['label'],
                'count' => $this->countWhenReady('orders', fn () => $this->shopOrderQuery()
                    ->whereIn('status', $group['statuses'])
                    ->count()),
            ])
            ->values()
            ->all();
    }

    private function paymentMix(): array
    {
        if (! Schema::hasTable('orders') || ! Schema::hasColumn('orders', 'payment_method')) {
            return [];
        }

        return $this->shopOrderQuery()
            ->whereDate('created_at', today())
            ->select('payment_method', DB::raw('COUNT(*) as orders_count'), DB::raw('COALESCE(SUM(total_amount), 0) as sales_total'))
            ->groupBy('payment_method')
            ->orderByDesc('orders_count')
            ->get()
            ->map(fn ($row) => [
                'method' => $row->payment_method ?: 'UNKNOWN',
                'orders_count' => (int) $row->orders_count,
                'sales_total' => (float) $row->sales_total,
            ])
            ->values()
            ->all();
    }

    private function recentShopOrders(): \Illuminate\Support\Collection
    {
        if (! Schema::hasTable('orders')) {
            return collect();
        }

        return Order::query()
            ->with([
                'customer:id,name,is_blacklisted,risk_level',
                'facebookPage:id,page_name',
                'shopItems:id,order_id,product_name,quantity,line_total',
            ])
            ->whereIn('source_channel', ['manual_shop', 'facebook_shop', 'walk_in', 'phone_order'])
            ->latest()
            ->limit(8)
            ->get([
                'id',
                'order_number',
                'status',
                'source_channel',
                'facebook_page_id',
                'customer_id',
                'receiver_name',
                'receiver_phone',
                'total_amount',
                'cod_amount',
                'payment_method',
                'payment_status',
                'created_at',
            ])
            ->map(fn (Order $order) => [
                'id' => $order->id,
                'order_number' => $order->order_number,
                'status' => $order->status->value,
                'source_channel' => $order->source_channel,
                'receiver_name' => $order->receiver_name,
                'receiver_phone' => $order->receiver_phone,
                'total_amount' => $order->total_amount,
                'cod_amount' => $order->cod_amount,
                'payment_method' => $order->payment_method,
                'payment_status' => $order->payment_status,
                'created_at' => optional($order->created_at)?->toIso8601String(),
                'facebook_page' => $order->facebookPage ? [
                    'id' => $order->facebookPage->id,
                    'page_name' => $order->facebookPage->page_name,
                ] : null,
                'customer' => $order->customer ? [
                    'id' => $order->customer->id,
                    'name' => $order->customer->name,
                    'risk_level' => $order->customer->risk_level,
                    'is_blacklisted' => $order->customer->is_blacklisted,
                ] : null,
                'items_summary' => $order->shopItems
                    ->map(fn ($item) => "{$item->product_name} x{$item->quantity}")
                    ->implode(', '),
            ]);
    }

    private function recentShopConversations(): \Illuminate\Support\Collection
    {
        if (! Schema::hasTable('conversations')) {
            return collect();
        }

        return Conversation::query()
            ->with([
                'facebookPage:id,page_name',
                'customer:id,name,normalized_phone,phone',
                'identity:id,display_name,phone_detected',
                'assignedAgent:id,name',
            ])
            ->latest('last_message_at')
            ->limit(8)
            ->get(['id', 'facebook_page_id', 'customer_id', 'customer_identity_id', 'assigned_agent_id', 'status', 'last_message_preview', 'last_message_at', 'unread_count'])
            ->map(fn (Conversation $conversation) => [
                'id' => $conversation->id,
                'status' => $conversation->status,
                'customer_name' => $conversation->customer?->name
                    ?? $conversation->identity?->display_name
                    ?? 'Facebook Customer',
                'phone' => $conversation->customer?->normalized_phone
                    ?? $conversation->customer?->phone
                    ?? $conversation->identity?->phone_detected,
                'page_name' => $conversation->facebookPage?->page_name,
                'assigned_agent' => $conversation->assignedAgent?->name,
                'last_message_preview' => $conversation->last_message_preview,
                'last_message_at' => optional($conversation->last_message_at)?->toIso8601String(),
                'unread_count' => $conversation->unread_count,
            ]);
    }

    private function dashboardAlerts(): array
    {
        $alerts = [];
        $failedWebhooks = $this->workQueues()['failed_webhooks'];
        $pagesNeedingSubscription = $this->countWhenReady('facebook_pages', fn () => DB::table('facebook_pages')
            ->where('connected_status', 'connected')
            ->where('webhook_status', '!=', 'subscribed')
            ->count());
        $pendingOrders = $this->workQueues()['pending_orders'];

        if ($pagesNeedingSubscription > 0) {
            $alerts[] = [
                'level' => 'warning',
                'title' => 'Page subscription needs attention',
                'detail' => "{$pagesNeedingSubscription} connected Page(s) are not fully subscribed to webhooks.",
                'href' => route('shop.meta-readiness'),
            ];
        }

        if ($failedWebhooks > 0) {
            $alerts[] = [
                'level' => 'danger',
                'title' => 'Webhook processing failures',
                'detail' => "{$failedWebhooks} webhook event(s) need review.",
                'href' => route('shop.webhooks'),
            ];
        }

        if ($pendingOrders > 0) {
            $alerts[] = [
                'level' => 'info',
                'title' => 'Pending POS orders',
                'detail' => "{$pendingOrders} order(s) are waiting for confirmation.",
                'href' => route('shop.orders.index', ['status' => OrderStatus::PENDING->value]),
            ];
        }

        return $alerts;
    }

    private function forEncodingCount(): int
    {
        if (! Schema::hasTable('orders') || ! Schema::hasColumn('orders', 'encoded_at')) {
            return 0;
        }

        return (int) DB::table('orders')
            ->whereIn('source_channel', ['manual_shop', 'facebook_shop', 'walk_in', 'phone_order'])
            ->whereIn('status', ['CONFIRMED', 'QA_APPROVED', 'For Encoding', 'for_encoding'])
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

    private function sumWhenReady(string $table, callable $callback): float
    {
        if (! Schema::hasTable($table)) {
            return 0;
        }

        return (float) $callback();
    }

    private function shopOrderQuery(): \Illuminate\Database\Query\Builder
    {
        if (! Schema::hasTable('orders')) {
            return DB::table('orders')->whereRaw('1 = 0');
        }

        $query = DB::table('orders')->whereIn('source_channel', ['manual_shop', 'facebook_shop', 'walk_in', 'phone_order']);

        if (Schema::hasColumn('orders', 'deleted_at')) {
            $query->whereNull('deleted_at');
        }

        return $query;
    }

    private function filteredShopOrderQuery(array $filters): \Illuminate\Database\Query\Builder
    {
        return $this->applyReportOrderFilters($this->shopOrderQuery(), $filters);
    }

    private function applyReportOrderFilters(\Illuminate\Database\Query\Builder $query, array $filters): \Illuminate\Database\Query\Builder
    {
        if (! empty($filters['date_from'])) {
            $query->whereDate('orders.created_at', '>=', $filters['date_from']);
        }

        if (! empty($filters['date_to'])) {
            $query->whereDate('orders.created_at', '<=', $filters['date_to']);
        }

        if (! empty($filters['page_id'])) {
            $query->where('orders.facebook_page_id', (int) $filters['page_id']);
        }

        if (! empty($filters['agent_id'])) {
            $query->where('orders.assigned_agent_id', (int) $filters['agent_id']);
        }

        return $query;
    }

    private function applyReportConversationFilters(\Illuminate\Database\Query\Builder $query, array $filters): \Illuminate\Database\Query\Builder
    {
        if (! empty($filters['date_from'])) {
            $query->whereDate('conversations.created_at', '>=', $filters['date_from']);
        }

        if (! empty($filters['date_to'])) {
            $query->whereDate('conversations.created_at', '<=', $filters['date_to']);
        }

        if (! empty($filters['page_id'])) {
            $query->where('conversations.facebook_page_id', (int) $filters['page_id']);
        }

        if (! empty($filters['agent_id'])) {
            $query->where('conversations.assigned_agent_id', (int) $filters['agent_id']);
        }

        return $query;
    }

    private function pagePerformanceReport(array $filters): \Illuminate\Support\Collection
    {
        if (! Schema::hasTable('facebook_pages') || ! Schema::hasTable('conversations')) {
            return collect();
        }

        return DB::table('facebook_pages')
            ->when(! empty($filters['page_id']), fn ($query) => $query->where('facebook_pages.id', (int) $filters['page_id']))
            ->select([
                'facebook_pages.id',
                'facebook_pages.page_name',
                'facebook_pages.connected_status',
                'facebook_pages.webhook_status',
            ])
            ->selectSub(function ($query) use ($filters) {
                $query->from('conversations')
                    ->selectRaw('COUNT(*)')
                    ->whereColumn('conversations.facebook_page_id', 'facebook_pages.id');

                $this->applyReportConversationFilters($query, array_merge($filters, ['page_id' => null]));
            }, 'conversations_count')
            ->selectSub(function ($query) use ($filters) {
                $query->from('conversations')
                    ->selectRaw('COUNT(*)')
                    ->whereColumn('conversations.facebook_page_id', 'facebook_pages.id')
                    ->where('conversations.status', 'converted');

                $this->applyReportConversationFilters($query, array_merge($filters, ['page_id' => null]));
            }, 'converted_count')
            ->selectSub(function ($query) use ($filters) {
                $query->from('messages')
                    ->join('conversations', 'conversations.id', '=', 'messages.conversation_id')
                    ->selectRaw('COUNT(*)')
                    ->whereColumn('conversations.facebook_page_id', 'facebook_pages.id');

                $this->applyReportConversationFilters($query, array_merge($filters, ['page_id' => null]));
            }, 'messages_count')
            ->selectSub(function ($query) use ($filters) {
                $query->from('orders')
                    ->selectRaw('COUNT(*)')
                    ->whereColumn('orders.facebook_page_id', 'facebook_pages.id')
                    ->whereIn('orders.source_channel', ['manual_shop', 'facebook_shop', 'walk_in', 'phone_order']);

                $this->applyReportOrderFilters($query, array_merge($filters, ['page_id' => null]));
            }, 'orders_count')
            ->selectSub(function ($query) use ($filters) {
                $query->from('orders')
                    ->selectRaw('COALESCE(SUM(orders.total_amount), 0)')
                    ->whereColumn('orders.facebook_page_id', 'facebook_pages.id')
                    ->whereIn('orders.source_channel', ['manual_shop', 'facebook_shop', 'walk_in', 'phone_order']);

                $this->applyReportOrderFilters($query, array_merge($filters, ['page_id' => null]));
            }, 'sales_total')
            ->orderByDesc('conversations_count')
            ->limit(12)
            ->get();
    }

    private function agentPerformanceReport(array $filters): \Illuminate\Support\Collection
    {
        if (! Schema::hasTable('users')) {
            return collect();
        }

        return DB::table('users')
            ->when(! empty($filters['agent_id']), fn ($query) => $query->where('users.id', (int) $filters['agent_id']))
            ->whereIn('users.role', ['agent', 'supervisor', 'admin', 'superadmin'])
            ->select([
                'users.id',
                'users.name',
                'users.role',
            ])
            ->selectSub(function ($query) use ($filters) {
                $query->from('conversations')
                    ->selectRaw('COUNT(*)')
                    ->whereColumn('conversations.assigned_agent_id', 'users.id');

                $this->applyReportConversationFilters($query, array_merge($filters, ['agent_id' => null]));
            }, 'assigned_conversations')
            ->selectSub(function ($query) use ($filters) {
                $query->from('conversations')
                    ->selectRaw('COUNT(*)')
                    ->whereColumn('conversations.assigned_agent_id', 'users.id')
                    ->where('conversations.status', 'converted');

                $this->applyReportConversationFilters($query, array_merge($filters, ['agent_id' => null]));
            }, 'converted_conversations')
            ->selectSub(function ($query) use ($filters) {
                $query->from('orders')
                    ->selectRaw('COUNT(*)')
                    ->whereColumn('orders.assigned_agent_id', 'users.id')
                    ->whereIn('orders.source_channel', ['manual_shop', 'facebook_shop', 'walk_in', 'phone_order']);

                $this->applyReportOrderFilters($query, array_merge($filters, ['agent_id' => null]));
            }, 'orders_count')
            ->selectSub(function ($query) use ($filters) {
                $query->from('orders')
                    ->selectRaw('COALESCE(SUM(orders.total_amount), 0)')
                    ->whereColumn('orders.assigned_agent_id', 'users.id')
                    ->whereIn('orders.source_channel', ['manual_shop', 'facebook_shop', 'walk_in', 'phone_order']);

                $this->applyReportOrderFilters($query, array_merge($filters, ['agent_id' => null]));
            }, 'sales_total')
            ->orderByDesc('orders_count')
            ->get()
            ->filter(fn ($agent) => (int) $agent->assigned_conversations > 0 || (int) $agent->orders_count > 0)
            ->take(12)
            ->values();
    }

    private function conversationStatusReport(array $filters): \Illuminate\Support\Collection
    {
        if (! Schema::hasTable('conversations')) {
            return collect();
        }

        return $this->applyReportConversationFilters(DB::table('conversations'), $filters)
            ->select('status', DB::raw('COUNT(*) as total'))
            ->groupBy('status')
            ->orderByDesc('total')
            ->get();
    }

    private function orderStatusReport(array $filters): \Illuminate\Support\Collection
    {
        return $this->filteredShopOrderQuery($filters)
            ->select('status', DB::raw('COUNT(*) as total'), DB::raw('COALESCE(SUM(total_amount), 0) as sales_total'))
            ->groupBy('status')
            ->orderByDesc('total')
            ->get();
    }

    private function topProductReport(array $filters): \Illuminate\Support\Collection
    {
        if (! Schema::hasTable('shop_order_items')) {
            return collect();
        }

        $query = DB::table('shop_order_items')
            ->join('orders', 'orders.id', '=', 'shop_order_items.order_id')
            ->whereIn('orders.source_channel', ['manual_shop', 'facebook_shop', 'walk_in', 'phone_order']);

        $this->applyReportOrderFilters($query, $filters);

        return $query
            ->select([
                'shop_order_items.product_name',
                DB::raw('SUM(shop_order_items.quantity) as quantity_sold'),
                DB::raw('COUNT(DISTINCT orders.id) as orders_count'),
                DB::raw('COALESCE(SUM(shop_order_items.line_total), 0) as sales_total'),
            ])
            ->groupBy('shop_order_items.product_name')
            ->orderByDesc('quantity_sold')
            ->limit(10)
            ->get();
    }

    private function dailySalesReport(array $filters): array
    {
        $from = \Carbon\Carbon::parse($filters['date_from'] ?? today()->subDays(6)->toDateString())->startOfDay();
        $to = \Carbon\Carbon::parse($filters['date_to'] ?? today()->toDateString())->startOfDay();
        $days = (int) min(31, $from->diffInDays($to));

        return collect(range($days, 0))
            ->map(function (int $daysAgo) use ($to, $filters) {
                $date = $to->copy()->subDays($daysAgo);
                $query = $this->filteredShopOrderQuery($filters)->whereDate('orders.created_at', $date);

                return [
                    'date' => $date->toDateString(),
                    'label' => $date->format('M j'),
                    'orders_count' => $query->count(),
                    'sales_total' => $this->filteredShopOrderQuery($filters)
                        ->whereDate('orders.created_at', $date)
                        ->sum('total_amount'),
                ];
            })
            ->values()
            ->all();
    }

    private function duplicateWarningsForPhone(?string $phone): \Illuminate\Support\Collection
    {
        $normalizedPhone = $phone ? $this->phones->normalize($phone) : null;

        if (! $normalizedPhone || ! Schema::hasTable('orders')) {
            return collect();
        }

        return Order::query()
            ->with('product:id,name,sku')
            ->where('receiver_phone', $normalizedPhone)
            ->whereIn('source_channel', ['manual_shop', 'facebook_shop', 'walk_in', 'phone_order'])
            ->where('created_at', '>=', now()->subDays(30))
            ->latest()
            ->limit(5)
            ->get(['id', 'order_number', 'product_id', 'status', 'total_amount', 'created_at']);
    }

    private function possibleDuplicateOrders(string $phone, array $productIds): \Illuminate\Support\Collection
    {
        $normalizedPhone = $this->phones->normalize($phone) ?: $phone;

        return Order::query()
            ->where('receiver_phone', $normalizedPhone)
            ->whereIn('product_id', $productIds)
            ->whereIn('source_channel', ['manual_shop', 'facebook_shop', 'walk_in', 'phone_order'])
            ->where('created_at', '>=', now()->subDays(14))
            ->latest()
            ->get(['id', 'order_number', 'created_at']);
    }

    private function quickRepliesForConversation(Conversation $conversation): array
    {
        $address = $conversation->customer?->canonical_address
            ?: collect([
                $conversation->customer?->barangay,
                $conversation->customer?->city_municipality,
                $conversation->customer?->province,
            ])->filter()->implode(', ');

        $replies = [
            [
                'label' => 'Ask complete details',
                'body' => 'Hello po, paki-send po complete name, complete address, landmark, and active phone number para ma-process po ang order ninyo.',
            ],
            [
                'label' => 'Confirm order',
                'body' => 'Confirm ko lang po ang order ninyo. Paki-check po kung tama ang product, quantity, complete address, and COD amount bago namin i-process.',
            ],
            [
                'label' => 'Ask active phone',
                'body' => 'May active contact number po ba kayo na reachable for courier delivery?',
            ],
        ];

        if ($address) {
            array_unshift($replies, [
                'label' => 'Same address?',
                'body' => "Hello po, same address pa rin po ba ito?\n{$address}",
            ]);
        }

        return $replies;
    }

    private function savedTemplatesForConversation(Conversation $conversation): array
    {
        if (! Schema::hasTable('shop_reply_templates')) {
            return [];
        }

        return ShopReplyTemplate::query()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get(['id', 'name', 'message', 'category', 'variables'])
            ->map(fn (ShopReplyTemplate $template) => [
                'id' => $template->id,
                'name' => $template->name,
                'category' => $template->category,
                'body' => $this->renderReplyTemplate($template->message, $conversation),
                'variables' => $template->variables ?? [],
            ])
            ->all();
    }

    private function renderReplyTemplate(string $message, Conversation $conversation): string
    {
        $address = $conversation->customer?->canonical_address
            ?: collect([
                $conversation->customer?->barangay,
                $conversation->customer?->city_municipality,
                $conversation->customer?->province,
            ])->filter()->implode(', ');

        $replacements = [
            '{customer_name}' => $conversation->customer?->name
                ?? $conversation->identity?->display_name
                ?? 'Customer',
            '{phone}' => $conversation->customer?->normalized_phone
                ?? $conversation->customer?->phone
                ?? $conversation->identity?->phone_detected
                ?? '',
            '{address}' => $address,
            '{page_name}' => $conversation->facebookPage?->page_name ?? 'our Page',
            '{status}' => $conversation->status,
            '{last_message}' => $conversation->last_message_preview ?? '',
        ];

        return str_replace(array_keys($replacements), array_values($replacements), $message);
    }

    private function shopAgents(): \Illuminate\Support\Collection
    {
        return User::query()
            ->where('is_active', true)
            ->whereIn('role', ['agent', 'supervisor', 'admin', 'superadmin'])
            ->orderBy('name')
            ->get(['id', 'name', 'role']);
    }

    /**
     * @return array<int, string>
     */
    private function conversationStatuses(): array
    {
        return ['open', 'pending_details', 'for_confirmation', 'confirmed', 'converted', 'closed'];
    }

}
