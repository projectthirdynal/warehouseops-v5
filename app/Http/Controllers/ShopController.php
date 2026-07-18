<?php

namespace App\Http\Controllers;

use App\Domain\Order\Enums\OrderStatus;
use App\Domain\Order\Services\OrderFulfillmentService;
use App\Events\ConversationStatusChanged;
use App\Domain\Order\Models\Order;
use App\Domain\Product\Models\Product;
use App\Domain\Shop\Models\AddressCorrectionHistory;
use App\Domain\Shop\Models\Conversation;
use App\Domain\Shop\Models\CourierExportBatch;
use App\Domain\Shop\Models\FacebookPage;
use App\Domain\Shop\Models\FacebookWebhookEvent;
use App\Domain\Shop\Models\Message;
use App\Domain\Shop\Models\PageAssignmentRule;
use App\Domain\Shop\Models\PageStatusLabel;
use App\Domain\Shop\Models\PageStatusRule;
use App\Domain\Shop\Models\ScheduledMessage;
use App\Domain\Inventory\Exceptions\InsufficientStockException;
use App\Domain\Inventory\Models\Warehouse;
use App\Domain\Inventory\Services\StockService;
use App\Domain\Shop\Services\AddressMappingService;
use App\Domain\Shop\Services\AddressFormatService;
use App\Domain\Shop\Services\CourierExportService;
use App\Domain\Shop\Services\GeocodingService;
use App\Domain\Shop\Services\CustomerAddressService;
use App\Domain\Shop\Services\CustomerIdentityService;
use App\Domain\Shop\Services\CustomerMergeService;
use App\Domain\Shop\Services\CustomerNoteService;
use App\Domain\Shop\Services\CustomerTimelineService;
use App\Domain\Shop\Services\FacebookConnectorService;
use App\Domain\Shop\Services\MetaConversationIngestor;
use App\Domain\Shop\Services\PhoneDetectionService;
use App\Domain\Shop\Services\ConversationExportService;
use App\Domain\Shop\Services\MessageTranslationService;
use App\Domain\Shop\Services\SentimentAnalysisService;
use App\Domain\Shop\Services\ShippingRateService;
use App\Domain\Shop\Models\ConversationExport;
use App\Domain\Shop\Models\ConversationAssignmentHistory;
use App\Domain\Shop\Models\ConversationStatusHistory;
use App\Domain\Shop\Models\OrderRemark;
use App\Domain\Shop\Models\RemarkTemplate;
use App\Domain\Shop\Models\ShopReplyTemplate;
use App\Domain\Shop\Models\ShopOrderItem;
use App\Domain\Shop\Models\CartTemplate;
use App\Domain\Shop\Models\Tag;
use App\Models\Customer;
use App\Models\CustomerAddress;
use App\Models\AgentProfile;
use App\Models\SiteSetting;
use App\Models\User;
use App\Notifications\RemarkMentionedNotification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use App\Exports\OrderRemarksExport;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class ShopController extends Controller
{
    public function __construct(
        private readonly PhoneDetectionService $phones,
        private readonly CustomerIdentityService $customerIdentities,
        private readonly CustomerMergeService $customerMerge,
        private readonly AddressMappingService $addressMappings,
        private readonly FacebookConnectorService $facebookConnector,
        private readonly CourierExportService $courierExports,
        private readonly MetaConversationIngestor $metaIngestor,
        private readonly CustomerAddressService $customerAddresses,
        private readonly CustomerNoteService $customerNotes,
        private readonly CustomerTimelineService $customerTimeline,
        private readonly SentimentAnalysisService $sentimentAnalyzer,
        private readonly StockService $stockService,
        private readonly ConversationExportService $conversationExports,
        private readonly MessageTranslationService $translator,
        private readonly ShippingRateService $shippingRates,
        private readonly OrderFulfillmentService $fulfillment,
        private readonly GeocodingService $geocoder,
        private readonly AddressFormatService $addressFormatter,
    ) {}

    public function index(): Response
    {
        return Inertia::render('Shop/Index', [
            'stats' => $this->stats(),
            'work_queues' => $this->workQueues(),
            'facebook_pages' => FacebookPage::query()
                ->latest('last_sync_at')
                ->limit(8)
                ->get(['id', 'page_id', 'page_name', 'connected_status', 'webhook_status', 'last_sync_at']),
            'modules' => [
                [
                    'name' => 'POS Core Schema',
                    'status' => 'Live',
                    'description' => 'Database foundation for Facebook identities, conversations, messages, order items, remarks, address mapping, and courier exports.',
                    'items' => ['Customer identities', 'Conversations', 'Messages', 'Export batches'],
                ],
                [
                    'name' => 'Facebook Connector',
                    'status' => 'Live',
                    'description' => 'Meta OAuth, Page token storage, webhook verification, Page subscription, raw event capture, and diagnostics.',
                    'items' => ['OAuth connect', 'Page list sync', 'Webhook subscribe', 'Webhook diagnostics'],
                ],
                [
                    'name' => 'Multi-page Inbox',
                    'status' => 'Live',
                    'description' => 'Central inbox for Messenger messages and Page comments across connected selling Pages.',
                    'items' => ['Page filters', 'Agent filters', 'Status workflow', 'Conversation detail'],
                ],
                [
                    'name' => 'Order Desk',
                    'status' => 'Live',
                    'description' => 'Create structured orders from conversations with products, COD amount, remarks, and customer details.',
                    'items' => ['Multi-item cart', 'Conversation to order', 'Customer profile', 'Agent remarks'],
                ],
                [
                    'name' => 'Encoder & Export',
                    'status' => 'Ready',
                    'description' => 'Validate addresses, map regions, and export courier-ready sheets for J&T, Flash, and other COD couriers.',
                    'items' => ['Address correction', 'Bulk selection', 'Courier batches', 'Courier CSV validation'],
                ],
                [
                    'name' => 'Reports & Automation',
                    'status' => 'Automation Ready',
                    'description' => 'Operational reporting, duplicate checks, saved reply templates, and customer profile updates.',
                    'items' => ['Sales dashboard', 'Duplicate warnings', 'Reply template library', 'Customer profile edits'],
                ],
            ],
            'workflow' => [
                'Connect Pages',
                'Receive Messages',
                'Detect Phone',
                'Match Customer',
                'Create Order',
                'Assign Agent',
                'Validate Address',
                'Export Courier File',
            ],
            'next_actions' => [
                'Finalize Meta production checklist and App Review screencast.',
                'Set live Meta support contact and confirm policy URLs.',
                'Add stock-aware validation before Shop order confirmation.',
                'Add message labels and follow-up reminders per conversation.',
            ],
        ]);
    }

    public function metaReadiness(): Response
    {
        $pages = FacebookPage::query()
            ->latest('last_sync_at')
            ->get(['id', 'page_id', 'page_name', 'connected_status', 'webhook_status', 'last_sync_at', 'metadata']);

        $webhookEventsReady = Schema::hasTable('facebook_webhook_events');
        $conversationsReady = Schema::hasTable('conversations');

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
            'summary' => [
                'connected_pages' => $pages->where('connected_status', 'connected')->count(),
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
                    'label' => 'Compliance URLs live',
                    'status' => 'ready',
                    'detail' => 'Privacy policy, terms, and data deletion callback are public.',
                ],
                [
                    'label' => 'Meta support contact set',
                    'status' => filled(config('services.meta.support_email')) ? 'ready' : 'needs_action',
                    'detail' => filled(config('services.meta.support_email'))
                        ? (string) config('services.meta.support_email')
                        : 'Set META_SUPPORT_EMAIL to a monitored inbox before App Review.',
                ],
                [
                    'label' => 'At least one Page connected',
                    'status' => $pages->where('connected_status', 'connected')->isNotEmpty() ? 'ready' : 'needs_action',
                    'detail' => $pages->where('connected_status', 'connected')->isNotEmpty()
                        ? 'A Shop Page is connected and available for review.'
                        : 'Connect a Facebook Page through Shop before App Review.',
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
                    ->whereIn('status', Conversation::ACTIVE_STATUSES)
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

    public function exportConversationStatuses(Request $request): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        $query = Conversation::query()
            ->whereNull('merged_into_id')
            ->with([
                'facebookPage:id,page_name',
                'customerIdentity:id,display_name',
                'assignedAgent:id,name',
                'tags:id,name',
            ])
            ->latest('last_message_at');

        if ($request->filled('page_id')) {
            $query->where('facebook_page_id', $request->integer('page_id'));
        }
        if ($request->filled('status')) {
            $query->where('status', $request->string('status'));
        }
        if ($request->filled('assigned_agent_id')) {
            $agentFilter = $request->string('assigned_agent_id')->toString();
            match ($agentFilter) {
                'unassigned' => $query->whereNull('assigned_agent_id'),
                'me' => $query->where('assigned_agent_id', $request->user()->id),
                default => $query->where('assigned_agent_id', $request->integer('assigned_agent_id')),
            };
        }
        if ($request->filled('priority')) {
            $query->where('priority', $request->string('priority'));
        }
        if ($request->boolean('flagged')) {
            $query->where('is_flagged', true);
        }

        $headers = [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="conversation-statuses-' . date('Y-m-d') . '.csv"',
        ];

        $columns = [
            'id', 'page', 'customer', 'channel', 'status', 'priority',
            'assigned_agent', 'is_flagged', 'flag_reason',
            'sentiment', 'snoozed_until', 'reminder_at',
            'first_response_at', 'first_response_time_seconds',
            'resolved_at', 'resolution_time_seconds',
            'last_message_at', 'created_at', 'tags',
        ];

        return response()->stream(function () use ($query, $columns) {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, $columns);

            $query->chunk(200, function ($conversations) use ($handle, $columns) {
                foreach ($conversations as $c) {
                    $row = [
                        $c->id,
                        $c->facebookPage?->page_name ?? '',
                        $c->customerIdentity?->display_name ?? '',
                        $c->channel ?? '',
                        $c->status ?? '',
                        $c->priority ?? '',
                        $c->assignedAgent?->name ?? 'Unassigned',
                        $c->is_flagged ? 'yes' : 'no',
                        $c->flag_reason ?? '',
                        $c->sentiment ?? '',
                        $c->snoozed_until ?? '',
                        $c->reminder_at ?? '',
                        $c->first_response_at ?? '',
                        $c->first_response_time_seconds ?? '',
                        $c->resolved_at ?? '',
                        $c->resolution_time_seconds ?? '',
                        $c->last_message_at ?? '',
                        $c->created_at?->toDateTimeString() ?? '',
                        $c->tags->pluck('name')->implode(';'),
                    ];
                    fputcsv($handle, $row);
                }
            });

            fclose($handle);
        }, 200, $headers);
    }

    public function inbox(Request $request): Response
    {
        $query = Conversation::query()
            ->whereNull('merged_into_id')
            ->with([
                'facebookPage:id,page_name,page_id',
                'customer:id,name,phone,normalized_phone',
                'identity:id,display_name,phone_detected',
                'assignedAgent:id,name',
                'tags:id,name,color',
            ])
            ->withCount('messages')
            ->latest('last_message_at');

        if ($request->filled('tag_id')) {
            $query->whereHas('tags', fn ($q) => $q->where('tags.id', $request->integer('tag_id')));
        }

        if ($request->filled('page_id')) {
            $query->where('facebook_page_id', $request->integer('page_id'));
        }

        if ($request->filled('status')) {
            $query->where('status', $request->string('status'));
        }

        if ($request->filled('assigned_agent_id')) {
            $agentFilter = $request->string('assigned_agent_id')->toString();
            match ($agentFilter) {
                'unassigned' => $query->whereNull('assigned_agent_id'),
                'me' => $query->where('assigned_agent_id', $request->user()->id),
                default => $query->where('assigned_agent_id', $request->integer('assigned_agent_id')),
            };
        }

        if ($request->filled('priority')) {
            $query->where('priority', $request->string('priority'));
        }

        if ($request->boolean('flagged')) {
            $query->where('is_flagged', true);
        }

        if ($request->string('snoozed')->toString() === 'active') {
            $query->whereNotNull('snoozed_until')->where('snoozed_until', '>', now());
        } elseif ($request->string('snoozed')->toString() === 'expired') {
            $query->whereNotNull('snoozed_until')->where('snoozed_until', '<=', now());
        } elseif ($request->string('snoozed')->toString() === 'none') {
            $query->whereNull('snoozed_until');
        }

        $pages = FacebookPage::query()
            ->orderBy('page_name')
            ->get(['id', 'page_id', 'page_name', 'connected_status', 'webhook_status'])
            ->map(function (FacebookPage $page) {
                $page->unread_count = Conversation::query()
                    ->whereNull('merged_into_id')
                    ->where('facebook_page_id', $page->id)
                    ->where('unread_count', '>', 0)
                    ->count();
                return $page;
            });

        $favoritePageIds = auth()->user()
            ? auth()->user()->favoritePages()->pluck('facebook_pages.id')->toArray()
            : [];

        $assignmentRules = PageAssignmentRule::query()
            ->with('agent:id,name')
            ->get(['id', 'facebook_page_id', 'user_id', 'is_active'])
            ->map(fn (PageAssignmentRule $rule) => [
                'id' => $rule->id,
                'facebook_page_id' => $rule->facebook_page_id,
                'user_id' => $rule->user_id,
                'agent_name' => $rule->agent?->name,
                'is_active' => $rule->is_active,
            ]);

        $statusRules = PageStatusRule::query()
            ->get(['id', 'facebook_page_id', 'from_status', 'to_status', 'inactivity_minutes', 'is_active'])
            ->map(fn (PageStatusRule $rule) => [
                'id' => $rule->id,
                'facebook_page_id' => $rule->facebook_page_id,
                'from_status' => $rule->from_status,
                'to_status' => $rule->to_status,
                'inactivity_minutes' => $rule->inactivity_minutes,
                'is_active' => $rule->is_active,
            ]);

        $pendingComments = Message::query()
            ->where('direction', 'inbound')
            ->where('moderation_status', 'pending')
            ->with([
                'facebookPage:id,page_name,page_id',
                'identity:id,display_name,provider_user_id',
                'conversation:id,thread_key,channel',
            ])
            ->latest('sent_at')
            ->limit(50)
            ->get(['id', 'conversation_id', 'facebook_page_id', 'customer_identity_id', 'body', 'sent_at', 'moderation_status']);

        $pageCannedResponses = ShopReplyTemplate::query()
            ->whereNotNull('facebook_page_id')
            ->with('facebookPage:id,page_name')
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get(['id', 'name', 'message', 'category', 'is_active', 'sort_order', 'facebook_page_id'])
            ->map(fn (ShopReplyTemplate $template) => [
                'id' => $template->id,
                'name' => $template->name,
                'message' => $template->message,
                'category' => $template->category,
                'is_active' => $template->is_active,
                'sort_order' => $template->sort_order,
                'facebook_page_id' => $template->facebook_page_id,
                'page_name' => $template->facebookPage?->page_name,
            ]);

        $canViewAll = $request->user()->isSupervisor();

        if (! $canViewAll && ! $request->filled('assigned_agent_id')) {
            $query->where('assigned_agent_id', $request->user()->id);
        }

        $statusCounts = Schema::hasTable('conversations')
            ? Conversation::query()
                ->whereNull('merged_into_id')
                ->select('status', \DB::raw('count(*) as count'))
                ->groupBy('status')
                ->pluck('count', 'status')
                ->toArray()
            : [];

        $slaThresholds = Conversation::slaThresholds();

        $paginated = $query->paginate(20)->withQueryString();
        $paginated->getCollection()->transform(function (Conversation $conv) use ($slaThresholds) {
            $conv->sla = $this->computeSla($conv, $slaThresholds);
            return $conv;
        });

        $statusLabels = PageStatusLabel::query()
            ->get(['facebook_page_id', 'status', 'label', 'color'])
            ->groupBy('facebook_page_id')
            ->map(fn ($items) => $items->mapWithKeys(fn ($item) => [$item->status => ['label' => $item->label, 'color' => $item->color]])
            ->toArray())
            ->toArray();

        $statusFunnel = $this->statusFunnel($statusCounts);

        $escalationCount = Schema::hasTable('conversations')
            ? Conversation::query()
                ->whereNull('merged_into_id')
                ->where('is_flagged', true)
                ->whereNull('resolved_at')
                ->count()
            : 0;

        return Inertia::render('Shop/Inbox', [
            'conversations' => $paginated,
            'pages' => $pages,
            'favorite_page_ids' => $favoritePageIds,
            'assignment_rules' => $assignmentRules,
            'status_rules' => $statusRules,
            'pending_comments' => $pendingComments,
            'page_canned_responses' => $pageCannedResponses,
            'agents' => $this->shopAgents(),
            'can_view_all' => $canViewAll,
            'current_user_id' => $request->user()->id,
            'user_role' => $request->user()->role,
            'my_status' => $request->user()->agentStatus(),
            'statuses' => $this->conversationStatuses(),
            'status_counts' => $statusCounts,
            'sla_thresholds' => $slaThresholds,
            'status_labels' => $statusLabels,
            'status_funnel' => $statusFunnel,
            'escalation_count' => $escalationCount,
            'priorities' => ['low', 'normal', 'high', 'urgent'],
            'tags' => Tag::query()->orderBy('name')->get(['id', 'name', 'color']),
            'workload_report' => $canViewAll ? $this->workloadReport() : null,
            'filters' => $request->only(['page_id', 'status', 'assigned_agent_id', 'priority', 'flagged', 'tag_id', 'snoozed']),
        ]);
    }

    public function updateAgentStatus(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'is_available' => ['required', 'boolean'],
        ]);

        $profile = $request->user()->agentProfile()->firstOrCreate(
            ['user_id' => $request->user()->id],
            ['is_available' => true]
        );

        $profile->forceFill([
            'is_available' => $validated['is_available'],
            'last_seen_at' => now(),
        ])->save();

        return back()->with('success', $validated['is_available'] ? 'You are now online.' : 'You are now away.');
    }

    public function toggleAgentAutoAssign(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'user_id' => ['required', 'integer', 'exists:users,id'],
            'auto_assign_enabled' => ['required', 'boolean'],
        ]);

        $profile = AgentProfile::query()->firstOrCreate(
            ['user_id' => $validated['user_id']],
            ['auto_assign_enabled' => false]
        );

        $profile->forceFill([
            'auto_assign_enabled' => $validated['auto_assign_enabled'],
        ])->save();

        $agentName = User::query()->where('id', $validated['user_id'])->value('name');

        return back()->with('success', "Auto-assignment " . ($validated['auto_assign_enabled'] ? 'enabled' : 'disabled') . " for {$agentName}.");
    }

    public function updateAgentSkills(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'user_id' => ['required', 'integer', 'exists:users,id'],
            'product_skills' => ['nullable', 'array'],
            'product_skills.*' => ['string', 'max:100'],
            'regions' => ['nullable', 'array'],
            'regions.*' => ['string', 'max:100'],
            'category_skills' => ['nullable', 'array'],
            'category_skills.*' => ['string', 'max:100'],
        ]);

        $profile = AgentProfile::query()->firstOrCreate(
            ['user_id' => $validated['user_id']],
        );

        $profile->forceFill([
            'product_skills' => $validated['product_skills'] ?? [],
            'regions' => $validated['regions'] ?? [],
            'category_skills' => $validated['category_skills'] ?? [],
        ])->save();

        $agentName = User::query()->where('id', $validated['user_id'])->value('name');

        return back()->with('success', "Skills updated for {$agentName}.");
    }

    public function updateAgentQueueLimit(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'user_id' => ['required', 'integer', 'exists:users,id'],
            'max_active_conversations' => ['required', 'integer', 'min:1', 'max:100'],
            'overflow_enabled' => ['required', 'boolean'],
        ]);

        $profile = AgentProfile::query()->firstOrCreate(
            ['user_id' => $validated['user_id']],
        );

        $profile->forceFill([
            'max_active_conversations' => $validated['max_active_conversations'],
            'overflow_enabled' => $validated['overflow_enabled'],
        ])->save();

        $agentName = User::query()->where('id', $validated['user_id'])->value('name');

        return back()->with('success', "Queue limit updated for {$agentName}.");
    }

    public function updateAgentShiftSchedule(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'user_id' => ['required', 'integer', 'exists:users,id'],
            'shift_start' => ['nullable', 'string', 'date_format:H:i'],
            'shift_end' => ['nullable', 'string', 'date_format:H:i'],
        ]);

        $profile = AgentProfile::query()->firstOrCreate(
            ['user_id' => $validated['user_id']],
        );

        $profile->forceFill([
            'shift_start' => $validated['shift_start'] ?? null,
            'shift_end' => $validated['shift_end'] ?? null,
        ])->save();

        $agentName = User::query()->where('id', $validated['user_id'])->value('name');

        return back()->with('success', "Shift schedule updated for {$agentName}.");
    }

    public function updateAgentIdleThreshold(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'user_id' => ['required', 'integer', 'exists:users,id'],
            'idle_threshold_minutes' => ['required', 'integer', 'min:1', 'max:120'],
        ]);

        $profile = AgentProfile::query()->firstOrCreate(
            ['user_id' => $validated['user_id']],
        );

        $profile->forceFill([
            'idle_threshold_minutes' => $validated['idle_threshold_minutes'],
        ])->save();

        $agentName = User::query()->where('id', $validated['user_id'])->value('name');

        return back()->with('success', "Idle threshold updated for {$agentName}.");
    }

    public function togglePageFavorite(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'page_id' => ['required', 'integer', 'exists:facebook_pages,id'],
        ]);

        $user = $request->user();
        $pageId = $validated['page_id'];

        if ($user->favoritePages()->where('facebook_page_id', $pageId)->exists()) {
            $user->favoritePages()->detach($pageId);
        } else {
            $user->favoritePages()->attach($pageId);
        }

        return back();
    }

    public function storeStatusLabel(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'page_id' => ['required', 'integer', 'exists:facebook_pages,id'],
            'status' => ['required', 'string', 'in:' . implode(',', Conversation::STATUSES)],
            'label' => ['required', 'string', 'max:50'],
            'color' => ['nullable', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
        ]);

        PageStatusLabel::updateOrCreate(
            [
                'facebook_page_id' => $validated['page_id'],
                'status' => $validated['status'],
            ],
            [
                'label' => $validated['label'],
                'color' => $validated['color'] ?? null,
            ]
        );

        return back()->with('success', 'Status label saved.');
    }

    public function destroyStatusLabel(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'page_id' => ['required', 'integer', 'exists:facebook_pages,id'],
            'status' => ['required', 'string', 'in:' . implode(',', Conversation::STATUSES)],
        ]);

        PageStatusLabel::where('facebook_page_id', $validated['page_id'])
            ->where('status', $validated['status'])
            ->delete();

        return back()->with('success', 'Status label removed.');
    }

    public function storeAssignmentRule(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'facebook_page_id' => ['required', 'integer', 'exists:facebook_pages,id'],
            'user_id' => ['required', 'integer', 'exists:users,id'],
        ]);

        PageAssignmentRule::firstOrCreate(
            [
                'facebook_page_id' => $validated['facebook_page_id'],
                'user_id' => $validated['user_id'],
            ],
            ['is_active' => true]
        );

        return back()->with('success', 'Assignment rule created.');
    }

    public function destroyAssignmentRule(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'rule_id' => ['required', 'integer', 'exists:page_assignment_rules,id'],
        ]);

        PageAssignmentRule::where('id', $validated['rule_id'])->delete();

        return back()->with('success', 'Assignment rule removed.');
    }

    public function storeStatusRule(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'facebook_page_id' => ['required', 'integer', 'exists:facebook_pages,id'],
            'from_status' => ['required', 'string', 'in:' . implode(',', Conversation::STATUSES)],
            'to_status' => ['required', 'string', 'in:' . implode(',', Conversation::STATUSES)],
            'inactivity_minutes' => ['nullable', 'integer', 'min:0', 'max:525600'],
        ]);

        PageStatusRule::firstOrCreate(
            [
                'facebook_page_id' => $validated['facebook_page_id'],
                'from_status' => $validated['from_status'],
                'to_status' => $validated['to_status'],
            ],
            [
                'inactivity_minutes' => $validated['inactivity_minutes'] ?? 0,
                'is_active' => true,
            ]
        );

        return back()->with('success', 'Status automation rule created.');
    }

    public function destroyStatusRule(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'rule_id' => ['required', 'integer', 'exists:page_status_rules,id'],
        ]);

        PageStatusRule::where('id', $validated['rule_id'])->delete();

        return back()->with('success', 'Status automation rule removed.');
    }

    public function moderateComment(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'message_id' => ['required', 'integer', 'exists:messages,id'],
            'action' => ['required', 'string', 'in:approve,hide'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        $message = Message::findOrFail($validated['message_id']);

        $message->update([
            'moderation_status' => $validated['action'] === 'approve' ? 'approved' : 'hidden',
            'moderation_note' => $validated['note'] ?? null,
            'moderated_at' => now(),
            'moderated_by' => $request->user()->id,
        ]);

        return back()->with('success', "Comment {$validated['action']}d.");
    }

    public function storePageCannedResponse(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'facebook_page_id' => ['required', 'integer', 'exists:facebook_pages,id'],
            'name' => ['required', 'string', 'max:255'],
            'message' => ['required', 'string', 'max:2000'],
            'category' => ['nullable', 'string', 'max:50'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:9999'],
        ]);

        preg_match_all('/\{(\w+)\}/', $validated['message'], $matches);

        ShopReplyTemplate::query()->create([
            'name' => $validated['name'],
            'message' => $validated['message'],
            'category' => $validated['category'] ?? null,
            'variables' => $matches[0] ?? [],
            'sort_order' => $validated['sort_order'] ?? 0,
            'is_active' => true,
            'created_by' => $request->user()->id,
            'facebook_page_id' => $validated['facebook_page_id'],
        ]);

        return back()->with('success', 'Page canned response created.');
    }

    public function destroyPageCannedResponse(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'template_id' => ['required', 'integer', 'exists:shop_reply_templates,id'],
        ]);

        ShopReplyTemplate::where('id', $validated['template_id'])
            ->whereNotNull('facebook_page_id')
            ->delete();

        return back()->with('success', 'Page canned response removed.');
    }

    public function conversation(Request $request, Conversation $conversation): Response
    {
        $conversation->load([
            'facebookPage:id,page_id,page_name,webhook_status',
            'customer:id,name,phone,normalized_phone,canonical_address,landmark,barangay,city_municipality,province,region,last_order_date,total_orders,successful_orders,returned_orders,success_rate,total_revenue,risk_level,is_blacklisted,blacklist_reason',
            'identity:id,display_name,provider_user_id,phone_detected',
            'assignedAgent:id,name',
            'tags:id,name,color',
        ]);

        $totalMessages = Message::query()->where('conversation_id', $conversation->id)->count();
        $messageLimit = 50;
        $initialMessages = Message::query()
            ->where('conversation_id', $conversation->id)
            ->with(['sender:id,name'])
            ->orderBy('sent_at')
            ->orderBy('id')
            ->latest('id')
            ->limit($messageLimit)
            ->get()
            ->reverse()
            ->values()
            ->map(fn (Message $m) => array_merge($m->toArray(), [
                'sender_name' => $m->sender?->name,
            ]));

        $conversation->forceFill(['unread_count' => 0])->save();

        return Inertia::render('Shop/Conversation', [
            'conversation' => $conversation,
            'messages' => $initialMessages,
            'has_more_messages' => $totalMessages > $messageLimit,
            'total_message_count' => $totalMessages,
            'recent_orders' => $conversation->customer_id
                ? Order::query()
                    ->with('product:id,name,sku', 'shopItems:id,order_id,product_name,quantity,line_total')
                    ->where('customer_id', $conversation->customer_id)
                    ->latest()
                    ->limit(5)
                    ->get(['id', 'order_number', 'product_id', 'status', 'total_amount', 'receiver_address', 'created_at'])
                : [],
            'quick_replies' => $this->quickRepliesForConversation($conversation),
            'saved_templates' => $this->savedTemplatesForConversation($conversation),
            'agents' => $this->shopAgents(),
            'user_role' => $request->user()->role,
            'statuses' => $this->conversationStatuses(),
            'priorities' => ['low', 'normal', 'high', 'urgent'],
            'tags' => Tag::query()->orderBy('name')->get(['id', 'name', 'color']),
            'merge_candidates' => Conversation::query()
                ->where('id', '!=', $conversation->id)
                ->whereNull('merged_into_id')
                ->when($conversation->customer_id, fn ($q) => $q->where('customer_id', $conversation->customer_id))
                ->orWhere(function ($q) use ($conversation) {
                    $q->where('id', '!=', $conversation->id)
                        ->whereNull('merged_into_id')
                        ->when($conversation->customer_identity_id, fn ($sub) => $sub->where('customer_identity_id', $conversation->customer_identity_id));
                })
                ->with(['customer:id,name', 'identity:id,display_name'])
                ->latest('last_message_at')
                ->limit(20)
                ->get(['id', 'customer_id', 'customer_identity_id', 'last_message_preview', 'status', 'last_message_at']),
            'scheduled_messages' => ScheduledMessage::query()
                ->where('conversation_id', $conversation->id)
                ->where('status', 'pending')
                ->orderBy('scheduled_at')
                ->get(['id', 'body', 'scheduled_at', 'status']),
            'assignment_history' => $conversation->assignmentHistories()
                ->with(['fromAgent:id,name', 'toAgent:id,name', 'assignedBy:id,name'])
                ->latest('id')
                ->limit(20)
                ->get()
                ->map(fn ($h) => [
                    'id' => $h->id,
                    'from_agent' => $h->fromAgent?->name,
                    'to_agent' => $h->toAgent?->name ?? 'Unassigned',
                    'assigned_by' => $h->assignedBy?->name,
                    'reason' => $h->reason,
                    'created_at' => $h->created_at?->toIso8601String(),
                ]),
            'status_history' => Schema::hasTable('conversation_status_histories')
                ? $conversation->statusHistories()
                    ->with(['changedBy:id,name'])
                    ->limit(30)
                    ->get()
                    ->map(fn ($h) => [
                        'id' => $h->id,
                        'from_status' => $h->from_status,
                        'to_status' => $h->to_status,
                        'changed_by' => $h->changedBy?->name ?? 'System',
                        'changed_by_role' => $h->changed_by_role,
                        'created_at' => $h->created_at?->toIso8601String(),
                    ])
                : [],
            'sla' => $this->computeSla($conversation, Conversation::slaThresholds()),
            'sla_thresholds' => Conversation::slaThresholds(),
            'status_labels' => PageStatusLabel::query()
                ->where('facebook_page_id', $conversation->facebook_page_id)
                ->get(['status', 'label', 'color'])
                ->mapWithKeys(fn ($item) => [$item->status => ['label' => $item->label, 'color' => $item->color]])
                ->toArray(),
            'remarks' => OrderRemark::query()
                ->where('conversation_id', $conversation->id)
                ->where('type', 'conversation_note')
                ->with('user:id,name')
                ->latest('id')
                ->limit(50)
                ->get()
                ->map(fn (OrderRemark $r) => [
                    'id' => $r->id,
                    'body' => $r->body,
                    'user_name' => $r->user?->name ?? 'System',
                    'created_at' => $r->created_at?->toIso8601String(),
                ]),
        ]);
    }

    public function customers(Request $request): Response
    {
        $query = Customer::query()
            ->with('defaultAddress:id,customer_id,label,canonical_address,barangay,city_municipality,province')
            ->when($request->filled('q'), fn ($q) => $this->applyCustomerSearch($q, $request->string('q')->toString()))
            ->latest('last_order_date')
            ->latest('id');

        return Inertia::render('Shop/Customers/Index', [
            'customers' => $query->paginate(25)->withQueryString(),
            'filters' => $request->only(['q']),
        ]);
    }

    public function showCustomer(Request $request, Customer $customer): Response
    {
        return Inertia::render('Shop/Customers/Show', [
            'customer' => $customer->load([
                'addresses',
                'defaultAddress',
                'notes.user:id,name',
            ]),
        ]);
    }

    public function customerMergeSuggestions(Customer $customer): JsonResponse
    {
        if (empty($customer->normalized_phone)) {
            return response()->json(['suggestions' => []]);
        }

        $suggestions = Customer::query()
            ->where('normalized_phone', $customer->normalized_phone)
            ->whereKeyNot($customer->id)
            ->orderByDesc('total_orders')
            ->limit(10)
            ->get([
                'id',
                'name',
                'phone',
                'normalized_phone',
                'total_orders',
                'successful_orders',
                'returned_orders',
                'risk_level',
                'created_at',
            ]);

        return response()->json(['suggestions' => $suggestions]);
    }

    public function mergeCustomerSuggestion(Customer $customer, Customer $source): JsonResponse
    {
        abort_if(
            empty($customer->normalized_phone) || $customer->normalized_phone !== $source->normalized_phone,
            422,
            'Customers must share the same normalized phone number before they can be merged.'
        );

        $mergedCustomer = $this->customerMerge->merge($customer, $source);

        return response()->json(['customer' => $mergedCustomer]);
    }

    public function customerOrderHistory(Request $request, Customer $customer): JsonResponse
    {
        $orders = Order::query()
            ->with('shopItems:id,order_id,product_name,quantity,line_total')
            ->where('customer_id', $customer->id)
            ->latest()
            ->limit(10)
            ->get([
                'id',
                'order_number',
                'status',
                'total_amount',
                'cod_amount',
                'receiver_address',
                'created_at',
                'delivered_at',
            ]);

        return response()->json(['orders' => $orders]);
    }

    public function exportCustomers(Request $request): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        $query = Customer::query()
            ->with('defaultAddress:id,customer_id,label,canonical_address,barangay,city_municipality,province')
            ->when($request->filled('q'), fn ($q) => $this->applyCustomerSearch($q, $request->string('q')->toString()))
            ->latest('last_order_date')
            ->latest('id');

        $headers = [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="customers-' . date('Y-m-d') . '.csv"',
        ];

        $columns = [
            'id', 'name', 'phone', 'normalized_phone', 'facebook_name',
            'canonical_address', 'barangay', 'city_municipality', 'province', 'region',
            'total_orders', 'successful_orders', 'returned_orders', 'success_rate',
            'total_revenue', 'average_order_value',
            'preferred_courier', 'payment_method',
            'risk_level', 'is_blacklisted',
            'last_order_date', 'last_page_ordered_from',
            'tags',
        ];

        return response()->stream(function () use ($query, $columns) {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, $columns);

            $query->chunk(200, function ($customers) use ($handle, $columns) {
                foreach ($customers as $customer) {
                    $row = [];
                    foreach ($columns as $col) {
                        $value = $customer->{$col};
                        if ($col === 'tags' && is_array($value)) {
                            $value = implode(';', $value);
                        }
                        if ($col === 'is_blacklisted') {
                            $value = $value ? 'yes' : 'no';
                        }
                        $row[$col] = $value ?? '';
                    }
                    fputcsv($handle, $row);
                }
            });

            fclose($handle);
        }, 200, $headers);
    }

    public function exportCustomerProfile(Request $request, Customer $customer): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        $customer->load([
            'addresses',
            'notes.user:id,name',
        ]);

        $orders = Order::query()
            ->with('shopItems:id,order_id,product_name,quantity,line_total')
            ->where('customer_id', $customer->id)
            ->latest()
            ->limit(50)
            ->get([
                'id', 'order_number', 'status', 'total_amount', 'cod_amount',
                'receiver_address', 'created_at', 'delivered_at',
            ]);

        $headers = [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="customer-' . $customer->id . '-' . date('Y-m-d') . '.csv"',
        ];

        return response()->stream(function () use ($customer, $orders) {
            $handle = fopen('php://output', 'w');

            fputcsv($handle, ['Customer Profile Export']);
            fputcsv($handle, []);

            fputcsv($handle, ['Field', 'Value']);
            fputcsv($handle, ['ID', $customer->id]);
            fputcsv($handle, ['Name', $customer->name]);
            fputcsv($handle, ['Phone', $customer->phone]);
            fputcsv($handle, ['Normalized Phone', $customer->normalized_phone]);
            fputcsv($handle, ['Facebook Name', $customer->facebook_name ?? '']);
            fputcsv($handle, ['Address', $customer->canonical_address ?? '']);
            fputcsv($handle, ['Barangay', $customer->barangay ?? '']);
            fputcsv($handle, ['City/Municipality', $customer->city_municipality ?? '']);
            fputcsv($handle, ['Province', $customer->province ?? '']);
            fputcsv($handle, ['Region', $customer->region ?? '']);
            fputcsv($handle, ['Total Orders', $customer->total_orders]);
            fputcsv($handle, ['Successful Orders', $customer->successful_orders]);
            fputcsv($handle, ['Returned Orders', $customer->returned_orders]);
            fputcsv($handle, ['Success Rate', $customer->success_rate]);
            fputcsv($handle, ['Total Revenue', $customer->total_revenue]);
            fputcsv($handle, ['Average Order Value', $customer->average_order_value]);
            fputcsv($handle, ['Preferred Courier', $customer->preferred_courier ?? '']);
            fputcsv($handle, ['Payment Method', $customer->payment_method ?? '']);
            fputcsv($handle, ['Risk Level', $customer->risk_level]);
            fputcsv($handle, ['Blacklisted', $customer->is_blacklisted ? 'yes' : 'no']);
            fputcsv($handle, ['Tags', is_array($customer->tags) ? implode(';', $customer->tags) : '']);
            fputcsv($handle, ['Last Order Date', $customer->last_order_date ?? '']);
            fputcsv($handle, ['Created At', $customer->created_at]);

            fputcsv($handle, []);
            fputcsv($handle, ['Saved Addresses']);
            fputcsv($handle, ['Label', 'Address', 'Barangay', 'City', 'Province', 'Is Default']);
            foreach ($customer->addresses as $address) {
                fputcsv($handle, [
                    $address->label ?? '',
                    $address->canonical_address ?? '',
                    $address->barangay ?? '',
                    $address->city_municipality ?? '',
                    $address->province ?? '',
                    $address->is_default ? 'yes' : 'no',
                ]);
            }

            fputcsv($handle, []);
            fputcsv($handle, ['Notes']);
            fputcsv($handle, ['Date', 'Author', 'Type', 'Body']);
            foreach ($customer->notes as $note) {
                fputcsv($handle, [
                    $note->created_at,
                    $note->user?->name ?? '',
                    $note->note_type ?? '',
                    $note->body,
                ]);
            }

            fputcsv($handle, []);
            fputcsv($handle, ['Recent Orders (last 50)']);
            fputcsv($handle, ['Order #', 'Status', 'Total', 'COD Amount', 'Address', 'Created At', 'Delivered At', 'Items']);
            foreach ($orders as $order) {
                $items = $order->shopItems
                    ->map(fn ($item) => "{$item->product_name} x{$item->quantity}")
                    ->join('; ');
                fputcsv($handle, [
                    $order->order_number,
                    $order->status,
                    $order->total_amount,
                    $order->cod_amount,
                    $order->receiver_address,
                    $order->created_at,
                    $order->delivered_at ?? '',
                    $items,
                ]);
            }

            fclose($handle);
        }, 200, $headers);
    }

    public function searchCustomers(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'q' => ['required', 'string', 'min:1', 'max:255'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);

        $query = Customer::query();
        $this->applyCustomerSearch($query, $validated['q']);

        $customers = $query
            ->limit($validated['limit'] ?? 10)
            ->get([
                'id',
                'name',
                'phone',
                'normalized_phone',
                'facebook_name',
                'canonical_address',
                'landmark',
                'barangay',
                'city_municipality',
                'province',
                'risk_level',
                'is_blacklisted',
                'total_orders',
                'total_revenue',
                'average_order_value',
                'last_order_date',
            ]);

        return response()->json(['customers' => $customers]);
    }

    /**
     * Apply name/phone/facebook_name search to a customer query.
     */
    private function applyCustomerSearch($query, string $term): void
    {
        $normalized = preg_replace('/[^0-9]/', '', $term) ?? '';

        $query->where(function ($q) use ($term, $normalized) {
            $q->where('name', 'ilike', "%{$term}%")
              ->orWhere('facebook_name', 'ilike', "%{$term}%");

            if ($normalized !== '') {
                $q->orWhere('phone', 'ilike', "%{$normalized}%")
                  ->orWhere('normalized_phone', 'ilike', "%{$normalized}%");
            }
        });
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
            'preferred_courier' => ['nullable', 'string', 'max:50'],
            'payment_method' => ['nullable', 'string', 'max:50'],
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
            'preferred_courier' => $validated['preferred_courier'] ?? null,
            'payment_method' => $validated['payment_method'] ?? null,
        ])->save();

        if (! empty($validated['canonical_address'])) {
            $this->customerAddresses->record($customer, [
                'label' => 'Default',
                'canonical_address' => $validated['canonical_address'] ?? null,
                'landmark' => $validated['landmark'] ?? null,
                'barangay' => $validated['barangay'] ?? null,
                'city_municipality' => $validated['city_municipality'] ?? null,
                'province' => $validated['province'] ?? null,
                'region' => $addressMatch['mapping']?->region ?? $customer->region,
            ], true, 'profile_update');
        }

        return back()->with('success', 'Customer profile updated.');
    }

    public function customerAddresses(Request $request, Customer $customer): JsonResponse
    {
        $addresses = $customer->addresses()->get([
            'id',
            'label',
            'canonical_address',
            'landmark',
            'barangay',
            'city_municipality',
            'province',
            'region',
            'postal_code',
            'contact_name',
            'contact_phone',
            'is_default',
            'source',
            'used_at',
            'created_at',
        ]);

        return response()->json(['addresses' => $addresses]);
    }

    public function storeCustomerAddress(Request $request, Customer $customer): JsonResponse
    {
        $validated = $request->validate([
            'label' => ['nullable', 'string', 'max:255'],
            'canonical_address' => ['required', 'string', 'max:2000'],
            'landmark' => ['nullable', 'string', 'max:255'],
            'barangay' => ['nullable', 'string', 'max:255'],
            'city_municipality' => ['nullable', 'string', 'max:255'],
            'province' => ['nullable', 'string', 'max:255'],
            'region' => ['nullable', 'string', 'max:255'],
            'postal_code' => ['nullable', 'string', 'max:30'],
            'contact_name' => ['nullable', 'string', 'max:255'],
            'contact_phone' => ['nullable', 'string', 'max:30'],
            'is_default' => ['boolean'],
        ]);

        $address = $this->customerAddresses->record(
            $customer,
            $validated,
            $validated['is_default'] ?? false,
            'manual'
        );

        return response()->json(['address' => $address], 201);
    }

    public function setDefaultCustomerAddress(Request $request, Customer $customer, CustomerAddress $address): JsonResponse
    {
        abort_unless($address->customer_id === $customer->id, 403, 'Address does not belong to this customer.');

        $this->customerAddresses->setDefault($customer, $address);

        return response()->json(['address' => $address->fresh()]);
    }

    public function customerNotes(Request $request, Customer $customer): JsonResponse
    {
        $notes = $customer->notes()->with('user:id,name')->get([
            'id',
            'customer_id',
            'user_id',
            'note_type',
            'body',
            'tags',
            'pinned_until',
            'created_at',
        ]);

        return response()->json([
            'notes' => $notes,
            'customer_tags' => $customer->tags ?? [],
            'preferred_courier' => $customer->preferred_courier,
            'payment_method' => $customer->payment_method,
        ]);
    }

    public function storeCustomerNote(Request $request, Customer $customer): JsonResponse
    {
        $validated = $request->validate([
            'body' => ['required', 'string', 'max:5000'],
            'tags' => ['nullable', 'array'],
            'tags.*' => ['string', 'max:50'],
            'note_type' => ['nullable', 'string', 'max:50'],
            'pinned_until' => ['nullable', 'date'],
        ]);

        $note = $this->customerNotes->addNote($customer, $validated);

        return response()->json(['note' => $note->load('user:id,name')], 201);
    }

    public function updateCustomerTags(Request $request, Customer $customer): JsonResponse
    {
        $validated = $request->validate([
            'tags' => ['required', 'array'],
            'tags.*' => ['string', 'max:50'],
        ]);

        $this->customerNotes->setTags($customer, $validated['tags']);

        return response()->json(['customer' => $customer->only(['id', 'tags'])]);
    }

    public function updateCustomerPreferences(Request $request, Customer $customer): JsonResponse
    {
        $validated = $request->validate([
            'preferred_courier' => ['nullable', 'string', 'max:50'],
            'payment_method' => ['nullable', 'string', 'max:50'],
        ]);

        $customer->forceFill([
            'preferred_courier' => $validated['preferred_courier'] ?? null,
            'payment_method' => $validated['payment_method'] ?? null,
        ])->save();

        return response()->json([
            'customer' => $customer->only(['id', 'preferred_courier', 'payment_method']),
        ]);
    }

    public function customerTimeline(Request $request, Customer $customer): JsonResponse
    {
        $validated = $request->validate([
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $activities = $this->customerTimeline->build($customer, $validated['limit'] ?? 50);

        return response()->json(['activities' => $activities]);
    }

    public function updateConversationAssignment(Request $request, Conversation $conversation): RedirectResponse
    {
        $validated = $request->validate([
            'assigned_agent_id' => ['nullable', 'integer', 'exists:users,id'],
        ]);

        $oldAgentId = $conversation->assigned_agent_id;
        $newAgentId = $validated['assigned_agent_id'] ?? null;

        if ($oldAgentId === $newAgentId) {
            return back()->with('success', 'No change in assignment.');
        }

        $conversation->forceFill([
            'assigned_agent_id' => $newAgentId,
        ])->save();

        ConversationAssignmentHistory::create([
            'conversation_id' => $conversation->id,
            'from_agent_id' => $oldAgentId,
            'to_agent_id' => $newAgentId,
            'assigned_by_id' => $request->user()->id,
            'reason' => 'manual',
        ]);

        return back()->with('success', 'Conversation assignment updated.');
    }

    public function updateConversationStatus(Request $request, Conversation $conversation): RedirectResponse
    {
        $validated = $request->validate([
            'status' => ['required', 'string', 'in:' . implode(',', $this->conversationStatuses())],
        ]);

        $role = $request->user()->role;

        if (! $conversation->canTransitionTo($validated['status'], $role)) {
            return back()->with('error', "Cannot transition conversation from '{$conversation->status}' to '{$validated['status']}'.");
        }

        $updates = ['status' => $validated['status']];

        // Track resolution time when conversation is resolved
        if ($validated['status'] === Conversation::STATUS_RESOLVED && !$conversation->resolved_at) {
            $updates['resolved_at'] = now();
            $updates['resolution_time_seconds'] = $conversation->created_at
                ? (int) now()->diffInSeconds($conversation->created_at)
                : null;
        }

        // Reset resolution if reopened
        if ($validated['status'] !== Conversation::STATUS_RESOLVED && $conversation->resolved_at) {
            $updates['resolved_at'] = null;
            $updates['resolution_time_seconds'] = null;
        }

        $oldStatus = $conversation->status;

        $conversation->forceFill($updates)->save();

        ConversationStatusHistory::create([
            'conversation_id' => $conversation->id,
            'from_status' => $oldStatus,
            'to_status' => $validated['status'],
            'changed_by_id' => $request->user()->id,
            'changed_by_role' => $role,
        ]);

        ConversationStatusChanged::dispatch($conversation, $oldStatus, $validated['status'], $request->user());

        return back()->with('success', 'Conversation status updated.');
    }

    public function bulkUpdateConversationStatus(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'conversation_ids' => ['required', 'array', 'min:1'],
            'conversation_ids.*' => ['integer', 'exists:conversations,id'],
            'status' => ['required', 'string', 'in:' . implode(',', $this->conversationStatuses())],
        ]);

        $role = $request->user()->role;
        $targetStatus = $validated['status'];

        $conversations = Conversation::query()
            ->whereIn('id', $validated['conversation_ids'])
            ->get(['id', 'status', 'resolved_at', 'created_at']);

        $validIds = [];
        $historyRows = [];
        foreach ($conversations as $conversation) {
            if ($conversation->canTransitionTo($targetStatus, $role)) {
                $validIds[] = $conversation->id;
                $historyRows[] = [
                    'conversation_id' => $conversation->id,
                    'from_status' => $conversation->status,
                    'to_status' => $targetStatus,
                    'changed_by_id' => $request->user()->id,
                    'changed_by_role' => $role,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }
        }

        if (empty($validIds)) {
            return back()->with('error', 'No conversations can be transitioned to the selected status.');
        }

        $now = now();
        $updateData = [
            'status' => $targetStatus,
            'updated_at' => $now,
        ];

        if ($targetStatus === Conversation::STATUS_RESOLVED) {
            $updateData['resolved_at'] = $now;
            // Set resolution_time_seconds for conversations that don't have it yet
            Conversation::query()
                ->whereIn('id', $validIds)
                ->whereNull('resolved_at')
                ->each(function (Conversation $conv) use ($now) {
                    $seconds = $conv->created_at ? (int) $now->diffInSeconds($conv->created_at) : null;
                    $conv->forceFill([
                        'resolved_at' => $now,
                        'resolution_time_seconds' => $seconds,
                    ])->save();
                });
        } else {
            // Reset resolution if reopening
            $updateData['resolved_at'] = null;
            $updateData['resolution_time_seconds'] = null;
            Conversation::query()
                ->whereIn('id', $validIds)
                ->update($updateData);
        }

        // For resolved status, update without overwriting resolution_time_seconds
        if ($targetStatus === Conversation::STATUS_RESOLVED) {
            Conversation::query()
                ->whereIn('id', $validIds)
                ->whereNotNull('resolved_at')
                ->update(['status' => $targetStatus, 'updated_at' => $now]);
        }

        $count = count($validIds);
        $skipped = count($validated['conversation_ids']) - $count;

        if (!empty($historyRows)) {
            ConversationStatusHistory::insert($historyRows);
        }

        foreach ($conversations as $conversation) {
            if (in_array($conversation->id, $validIds, true)) {
                ConversationStatusChanged::dispatch($conversation, $conversation->status, $targetStatus, $request->user());
            }
        }

        $message = "{$count} conversation(s) marked as {$targetStatus}.";
        if ($skipped > 0) {
            $message .= " {$skipped} skipped due to transition rules.";
        }

        return back()->with('success', $message);
    }

    public function bulkAssignConversations(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'conversation_ids' => ['required', 'array', 'min:1'],
            'conversation_ids.*' => ['integer', 'exists:conversations,id'],
            'assigned_agent_id' => ['nullable', 'integer', 'exists:users,id'],
        ]);

        $newAgentId = $validated['assigned_agent_id'] ?? null;
        $userId = $request->user()->id;

        $conversations = Conversation::query()
            ->whereIn('id', $validated['conversation_ids'])
            ->get(['id', 'assigned_agent_id']);

        foreach ($conversations as $conversation) {
            if ($conversation->assigned_agent_id === $newAgentId) {
                continue;
            }

            ConversationAssignmentHistory::create([
                'conversation_id' => $conversation->id,
                'from_agent_id' => $conversation->assigned_agent_id,
                'to_agent_id' => $newAgentId,
                'assigned_by_id' => $userId,
                'reason' => 'bulk',
            ]);

            $conversation->forceFill(['assigned_agent_id' => $newAgentId])->save();
        }

        $count = count($validated['conversation_ids']);
        $agentName = $newAgentId
            ? User::query()->where('id', $newAgentId)->value('name')
            : null;

        $message = $agentName
            ? "{$count} conversation(s) assigned to {$agentName}."
            : "{$count} conversation(s) unassigned.";

        return back()->with('success', $message);
    }

    public function bulkUpdateConversationPriority(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'conversation_ids' => ['required', 'array', 'min:1'],
            'conversation_ids.*' => ['integer', 'exists:conversations,id'],
            'priority' => ['required', 'string', 'in:low,normal,high,urgent'],
        ]);

        Conversation::query()
            ->whereIn('id', $validated['conversation_ids'])
            ->update(['priority' => $validated['priority']]);

        $count = count($validated['conversation_ids']);
        return back()->with('success', "{$count} conversation(s) priority set to {$validated['priority']}.");
    }

    public function bulkTagConversations(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'conversation_ids' => ['required', 'array', 'min:1'],
            'conversation_ids.*' => ['integer', 'exists:conversations,id'],
            'tag_id' => ['required', 'integer', 'exists:tags,id'],
        ]);

        $conversations = Conversation::query()
            ->whereIn('id', $validated['conversation_ids'])
            ->get(['id']);

        foreach ($conversations as $conversation) {
            $conversation->tags()->syncWithoutDetaching([$validated['tag_id']]);
        }

        $count = $conversations->count();
        return back()->with('success', "{$count} conversation(s) tagged.");
    }

    public function updateConversationPriority(Request $request, Conversation $conversation): RedirectResponse
    {
        $validated = $request->validate([
            'priority' => ['required', 'string', 'in:low,normal,high,urgent'],
            'is_flagged' => ['boolean'],
            'flag_reason' => ['nullable', 'string', 'max:500'],
        ]);

        $conversation->forceFill([
            'priority' => $validated['priority'],
            'is_flagged' => $validated['is_flagged'] ?? false,
            'flag_reason' => $validated['is_flagged'] ?? false ? ($validated['flag_reason'] ?? null) : null,
            'flagged_at' => $validated['is_flagged'] ?? false ? ($conversation->flagged_at ?? now()) : null,
        ])->save();

        return back()->with('success', 'Conversation priority updated.');
    }

    public function updateConversationTags(Request $request, Conversation $conversation): RedirectResponse
    {
        $validated = $request->validate([
            'tags' => ['nullable', 'array'],
            'tags.*' => ['integer', 'exists:tags,id'],
        ]);

        $conversation->tags()->sync($validated['tags'] ?? []);

        return back()->with('success', 'Conversation tags updated.');
    }

    public function storeTag(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:50'],
            'color' => ['nullable', 'string', 'max:7', 'regex:/^#[0-9a-fA-F]{6}$/'],
        ]);

        Tag::query()->firstOrCreate(
            ['slug' => str($validated['name'])->slug()->toString()],
            [
                'name' => $validated['name'],
                'color' => $validated['color'] ?? '#64748b',
            ]
        );

        return back()->with('success', 'Tag created.');
    }

    public function snoozeConversation(Request $request, Conversation $conversation): RedirectResponse
    {
        $validated = $request->validate([
            'snoozed_until' => ['required', 'date', 'after:now'],
            'snooze_reason' => ['nullable', 'string', 'max:500'],
        ]);

        $conversation->forceFill([
            'snoozed_until' => $validated['snoozed_until'],
            'snooze_reason' => $validated['snooze_reason'] ?? null,
        ])->save();

        return back()->with('success', 'Conversation snoozed until ' . $validated['snoozed_until']);
    }

    public function unsnoozeConversation(Conversation $conversation): RedirectResponse
    {
        $conversation->forceFill([
            'snoozed_until' => null,
            'snooze_reason' => null,
        ])->save();

        return back()->with('success', 'Conversation unsnoozed.');
    }

    public function setConversationReminder(Request $request, Conversation $conversation): RedirectResponse
    {
        $validated = $request->validate([
            'reminder_at' => ['required', 'date', 'after:now'],
        ]);

        $conversation->forceFill([
            'reminder_at' => $validated['reminder_at'],
        ])->save();

        return back()->with('success', 'Reminder set for ' . $validated['reminder_at']);
    }

    public function clearConversationReminder(Conversation $conversation): RedirectResponse
    {
        $conversation->forceFill([
            'reminder_at' => null,
        ])->save();

        return back()->with('success', 'Reminder cleared.');
    }

    public function mergeConversations(Request $request, Conversation $conversation): RedirectResponse
    {
        $validated = $request->validate([
            'source_conversation_id' => ['required', 'integer', 'exists:conversations,id'],
        ]);

        $source = Conversation::query()->findOrFail($validated['source_conversation_id']);

        if ($source->id === $conversation->id) {
            return back()->withErrors('Cannot merge a conversation into itself.');
        }

        if ($conversation->merged_into_id) {
            return back()->withErrors('Target conversation is already merged into another.');
        }

        DB::transaction(function () use ($source, $conversation): void {
            // Reassign all messages from source to target
            $source->messages()->update(['conversation_id' => $conversation->id]);

            // Copy tags from source to target
            $sourceTagIds = $source->tags()->pluck('tags.id');
            $conversation->tags()->syncWithoutDetaching($sourceTagIds);

            // Mark source as merged
            $source->forceFill([
                'merged_into_id' => $conversation->id,
                'status' => 'archived',
            ])->save();

            // Update last message info on target if source has newer activity
            if ($source->last_message_at && (!$conversation->last_message_at || $source->last_message_at > $conversation->last_message_at)) {
                $conversation->forceFill([
                    'last_message_at' => $source->last_message_at,
                    'last_message_preview' => $source->last_message_preview,
                ])->save();
            }

            // Add unread count from source
            if ($source->unread_count > 0) {
                $conversation->increment('unread_count', $source->unread_count);
            }
        });

        return back()->with('success', "Conversation #{$source->id} merged into this conversation.");
    }

    public function conversationAnalytics(Request $request): Response
    {
        $range = $request->string('range')->toString();
        $startDate = match ($range) {
            '7d' => now()->subDays(7),
            '30d' => now()->subDays(30),
            '90d' => now()->subDays(90),
            default => now()->subDays(30),
        };

        $baseQuery = Conversation::query()
            ->whereNull('merged_into_id')
            ->where('created_at', '>=', $startDate);

        $totalConversations = (clone $baseQuery)->count();
        $respondedConversations = (clone $baseQuery)->whereNotNull('first_response_at')->count();
        $resolvedConversations = (clone $baseQuery)->whereNotNull('resolved_at')->count();

        $avgFirstResponse = (clone $baseQuery)
            ->whereNotNull('first_response_time_seconds')
            ->avg('first_response_time_seconds');

        $avgResolution = (clone $baseQuery)
            ->whereNotNull('resolution_time_seconds')
            ->avg('resolution_time_seconds');

        $medianFirstResponse = (clone $baseQuery)
            ->whereNotNull('first_response_time_seconds')
            ->orderBy('first_response_time_seconds')
            ->value('first_response_time_seconds');

        $medianResolution = (clone $baseQuery)
            ->whereNotNull('resolution_time_seconds')
            ->orderBy('resolution_time_seconds')
            ->value('resolution_time_seconds');

        // Per-agent breakdown
        $perAgent = User::query()
            ->select('id', 'name')
            ->whereHas('conversations', fn ($q) => $q->whereNull('merged_into_id')->where('created_at', '>=', $startDate))
            ->withCount([
                'conversations as assigned_count' => fn ($q) => $q->whereNull('merged_into_id')->where('created_at', '>=', $startDate),
                'conversations as responded_count' => fn ($q) => $q->whereNull('merged_into_id')->where('created_at', '>=', $startDate)->whereNotNull('first_response_at'),
                'conversations as resolved_count' => fn ($q) => $q->whereNull('merged_into_id')->where('created_at', '>=', $startDate)->whereNotNull('resolved_at'),
            ])
            ->withAvg([
                'conversations as avg_response_seconds' => fn ($q) => $q->whereNull('merged_into_id')->where('created_at', '>=', $startDate)->whereNotNull('first_response_time_seconds'),
            ], 'first_response_time_seconds')
            ->withAvg([
                'conversations as avg_resolution_seconds' => fn ($q) => $q->whereNull('merged_into_id')->where('created_at', '>=', $startDate)->whereNotNull('resolution_time_seconds'),
            ], 'resolution_time_seconds')
            ->orderByDesc('assigned_count')
            ->get()
            ->filter(fn ($agent) => $agent->assigned_count > 0)
            ->values();

        // Status distribution
        $statusDistribution = (clone $baseQuery)
            ->select('status', DB::raw('count(*) as count'))
            ->groupBy('status')
            ->pluck('count', 'status');

        // Sentiment distribution
        $sentimentDistribution = (clone $baseQuery)
            ->select('sentiment', DB::raw('count(*) as count'))
            ->groupBy('sentiment')
            ->pluck('count', 'sentiment');

        // Daily trend
        $dailyTrend = (clone $baseQuery)
            ->selectRaw("DATE(created_at) as date, COUNT(*) as total, SUM(CASE WHEN first_response_at IS NOT NULL THEN 1 ELSE 0 END) as responded, SUM(CASE WHEN resolved_at IS NOT NULL THEN 1 ELSE 0 END) as resolved")
            ->groupByRaw('DATE(created_at)')
            ->orderByRaw('DATE(created_at)')
            ->get();

        // Per-page breakdown
        $perPage = FacebookPage::query()
            ->select('id', 'page_name', 'page_id')
            ->whereHas('conversations', fn ($q) => $q->whereNull('merged_into_id')->where('created_at', '>=', $startDate))
            ->withCount([
                'conversations as total_conversations' => fn ($q) => $q->whereNull('merged_into_id')->where('created_at', '>=', $startDate),
                'conversations as responded_count' => fn ($q) => $q->whereNull('merged_into_id')->where('created_at', '>=', $startDate)->whereNotNull('first_response_at'),
                'conversations as resolved_count' => fn ($q) => $q->whereNull('merged_into_id')->where('created_at', '>=', $startDate)->whereNotNull('resolved_at'),
            ])
            ->withAvg([
                'conversations as avg_response_seconds' => fn ($q) => $q->whereNull('merged_into_id')->where('created_at', '>=', $startDate)->whereNotNull('first_response_time_seconds'),
            ], 'first_response_time_seconds')
            ->withAvg([
                'conversations as avg_resolution_seconds' => fn ($q) => $q->whereNull('merged_into_id')->where('created_at', '>=', $startDate)->whereNotNull('resolution_time_seconds'),
            ], 'resolution_time_seconds')
            ->orderByDesc('total_conversations')
            ->get()
            ->map(fn ($page) => [
                'id' => $page->id,
                'page_name' => $page->page_name,
                'page_id' => $page->page_id,
                'total_conversations' => $page->total_conversations,
                'responded_count' => $page->responded_count,
                'resolved_count' => $page->resolved_count,
                'response_rate' => $page->total_conversations > 0 ? round(($page->responded_count / $page->total_conversations) * 100, 1) : 0,
                'resolution_rate' => $page->total_conversations > 0 ? round(($page->resolved_count / $page->total_conversations) * 100, 1) : 0,
                'avg_response_seconds' => $page->avg_response_seconds ? (int) $page->avg_response_seconds : null,
                'avg_resolution_seconds' => $page->avg_resolution_seconds ? (int) $page->avg_resolution_seconds : null,
            ])
            ->values();

        return Inertia::render('Shop/ConversationAnalytics', [
            'stats' => [
                'total_conversations' => $totalConversations,
                'responded_conversations' => $respondedConversations,
                'resolved_conversations' => $resolvedConversations,
                'response_rate' => $totalConversations > 0 ? round(($respondedConversations / $totalConversations) * 100, 1) : 0,
                'resolution_rate' => $totalConversations > 0 ? round(($resolvedConversations / $totalConversations) * 100, 1) : 0,
                'avg_first_response_seconds' => $avgFirstResponse ? (int) $avgFirstResponse : null,
                'avg_resolution_seconds' => $avgResolution ? (int) $avgResolution : null,
                'median_first_response_seconds' => $medianFirstResponse ? (int) $medianFirstResponse : null,
                'median_resolution_seconds' => $medianResolution ? (int) $medianResolution : null,
            ],
            'per_agent' => $perAgent,
            'per_page' => $perPage,
            'status_distribution' => $statusDistribution,
            'sentiment_distribution' => $sentimentDistribution,
            'daily_trend' => $dailyTrend,
            'recent_exports' => ConversationExport::query()
                ->latest()
                ->limit(10)
                ->get(['id', 'export_number', 'status', 'conversation_count', 'message_count', 'file_path', 'created_at']),
            'range' => $range ?: '30d',
        ]);
    }

    public function exportConversations(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date'],
            'status' => ['nullable', 'string', 'in:open,assigned,resolved,archived,closed'],
            'sentiment' => ['nullable', 'string', 'in:positive,neutral,negative'],
        ]);

        $export = $this->conversationExports->createExport($validated, auth()->id());

        return redirect()
            ->route('shop.analytics')
            ->with('success', "Compliance export {$export->export_number} created ({$export->conversation_count} conversations, {$export->message_count} messages).");
    }

    public function downloadConversationExport(ConversationExport $export): BinaryFileResponse
    {
        abort_unless($export->file_path && file_exists(storage_path("app/{$export->file_path}")), 404);

        return response()->download(storage_path("app/{$export->file_path}"));
    }

    public function sendReply(Request $request, Conversation $conversation): RedirectResponse
    {
        $validated = $request->validate([
            'body' => ['required', 'string', 'max:2000'],
            'quick_replies' => ['nullable', 'array', 'max:11'],
            'quick_replies.*.title' => ['required', 'string', 'max:20'],
            'quick_replies.*.payload' => ['required', 'string', 'max:1000'],
        ]);

        $conversation->load(['facebookPage', 'identity']);
        $delivery = ['status' => 'logged'];
        $quickReplies = $validated['quick_replies'] ?? [];

        if ($conversation->facebookPage?->page_access_token && $conversation->identity?->provider_user_id) {
            try {
                if ($quickReplies !== []) {
                    $delivery = $this->facebookConnector->sendMessageWithQuickReplies(
                        $conversation->facebookPage,
                        $conversation->identity->provider_user_id,
                        $validated['body'],
                        $quickReplies
                    );
                } else {
                    $delivery = $this->facebookConnector->sendMessage(
                        $conversation->facebookPage,
                        $conversation->identity->provider_user_id,
                        $validated['body']
                    );
                }
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
            'sent_by' => $request->user()->id,
            'external_message_id' => 'local-' . str()->uuid(),
            'direction' => 'outbound',
            'message_type' => $quickReplies !== [] ? 'quick_reply' : 'text',
            'body' => $validated['body'],
            'metadata' => $quickReplies !== [] ? ['quick_replies' => $quickReplies] : null,
            'raw_payload' => $delivery,
            'sent_at' => now(),
            'send_status' => $delivery['status'],
            'send_error' => $delivery['error'] ?? null,
            'retry_count' => 0,
        ]);

        $conversation->forceFill([
            'last_message_preview' => $validated['body'],
            'last_message_at' => now(),
            'draft_body' => null,
        ])->save();

        // Track first response time
        if (!$conversation->first_response_at && $conversation->created_at) {
            $conversation->forceFill([
                'first_response_at' => now(),
                'first_response_time_seconds' => (int) now()->diffInSeconds($conversation->created_at),
            ])->save();
        }

        return back()->with($delivery['status'] === 'failed' ? 'error' : 'success', $delivery['status'] === 'failed'
            ? 'Reply saved locally, but Meta send failed.'
            : 'Reply saved.');
    }

    /**
     * Mark inbound messages in a conversation as read.
     */
    public function markMessagesRead(Request $request, Conversation $conversation): JsonResponse
    {
        $request->validate([
            'before_message_id' => ['nullable', 'integer', 'exists:messages,id'],
        ]);

        $query = Message::query()
            ->where('conversation_id', $conversation->id)
            ->where('direction', 'inbound')
            ->whereNull('read_at');

        if ($request->filled('before_message_id')) {
            $query->where('id', '<=', $request->input('before_message_id'));
        }

        $query->update(['read_at' => now()]);

        return response()->json(['status' => 'ok']);
    }

    public function pollMessages(Request $request, Conversation $conversation): JsonResponse
    {
        $validated = $request->validate([
            'after_message_id' => ['nullable', 'integer', 'exists:messages,id'],
        ]);

        $query = Message::query()
            ->where('conversation_id', $conversation->id)
            ->orderBy('sent_at')
            ->orderBy('id');

        if ($validated['after_message_id'] ?? null) {
            $query->where('id', '>', $validated['after_message_id']);
        }

        $messages = $query
            ->with(['sender:id,name'])
            ->get([
                'id',
                'sent_by',
                'direction',
                'body',
                'message_type',
                'attachments',
                'metadata',
                'reactions',
                'is_flagged',
                'flag_reason',
                'translated_body',
                'translated_lang',
                'sent_at',
                'raw_payload',
                'phone_candidates',
            ])
            ->map(fn (Message $m) => array_merge($m->toArray(), [
                'sender_name' => $m->sender?->name,
            ]));

        $conversation->refresh();

        // Customer is typing if typing_at was set within the last 15 seconds
        $isTyping = $conversation->typing_at !== null
            && $conversation->typing_at->gt(now()->subSeconds(15));

        return response()->json([
            'messages' => $messages,
            'last_message_preview' => $conversation->last_message_preview,
            'last_message_at' => $conversation->last_message_at,
            'unread_count' => $conversation->unread_count,
            'status' => $conversation->status,
            'is_typing' => $isTyping,
        ]);
    }

    public function sendTypingIndicator(Conversation $conversation): JsonResponse
    {
        $conversation->load(['facebookPage', 'identity']);

        if ($conversation->facebookPage?->page_access_token && $conversation->identity?->provider_user_id) {
            try {
                $this->facebookConnector->sendTypingIndicator(
                    $conversation->facebookPage,
                    $conversation->identity->provider_user_id
                );
            } catch (\Throwable) {
                // Silently ignore — typing indicators are best-effort
            }
        }

        return response()->json(['status' => 'ok']);
    }

    public function fetchOlderMessages(Request $request, Conversation $conversation): JsonResponse
    {
        $validated = $request->validate([
            'before_id' => ['required', 'integer', 'exists:messages,id'],
        ]);

        $messageLimit = 50;
        $messages = Message::query()
            ->where('conversation_id', $conversation->id)
            ->where('id', '<', $validated['before_id'])
            ->with(['sender:id,name'])
            ->orderByDesc('id')
            ->limit($messageLimit + 1)
            ->get([
                'id',
                'sent_by',
                'direction',
                'body',
                'message_type',
                'attachments',
                'metadata',
                'reactions',
                'is_flagged',
                'flag_reason',
                'translated_body',
                'translated_lang',
                'sent_at',
                'raw_payload',
                'phone_candidates',
            ])
            ->map(fn (Message $m) => array_merge($m->toArray(), [
                'sender_name' => $m->sender?->name,
            ]))
            ->reverse()
            ->values();

        $hasMore = $messages->count() > $messageLimit;

        return response()->json([
            'messages' => $messages->take($messageLimit),
            'has_more' => $hasMore,
        ]);
    }

    public function toggleReaction(Request $request, Message $message): JsonResponse
    {
        $validated = $request->validate([
            'emoji' => ['required', 'string', 'max:10'],
        ]);

        $reactions = $message->reactions ?? [];
        $agentKey = 'agent:' . $request->user()->id;

        if (($reactions[$agentKey] ?? null) === $validated['emoji']) {
            unset($reactions[$agentKey]);
        } else {
            $reactions[$agentKey] = $validated['emoji'];
        }

        $message->forceFill(['reactions' => $reactions])->save();

        return response()->json(['reactions' => $reactions]);
    }

    public function searchMessages(Request $request, Conversation $conversation): JsonResponse
    {
        $validated = $request->validate([
            'q' => ['required', 'string', 'min:1', 'max:200'],
        ]);

        $query = '%' . $validated['q'] . '%';

        $results = Message::query()
            ->where('conversation_id', $conversation->id)
            ->where(function ($q) use ($query) {
                $q->where('body', 'like', $query)
                    ->orWhere('metadata', 'like', $query);
            })
            ->orderBy('sent_at')
            ->orderBy('id')
            ->limit(50)
            ->get([
                'id',
                'direction',
                'body',
                'message_type',
                'attachments',
                'metadata',
                'reactions',
                'is_flagged',
                'flag_reason',
                'translated_body',
                'translated_lang',
                'sent_at',
                'raw_payload',
                'phone_candidates',
            ]);

        return response()->json([
            'messages' => $results,
            'query' => $validated['q'],
        ]);
    }

    public function toggleMessageFlag(Request $request, Message $message): JsonResponse
    {
        $validated = $request->validate([
            'flag_reason' => ['nullable', 'string', 'max:500'],
        ]);

        if ($message->is_flagged) {
            $message->forceFill(['is_flagged' => false, 'flag_reason' => null])->save();
        } else {
            $message->forceFill([
                'is_flagged' => true,
                'flag_reason' => $validated['flag_reason'] ?? 'Flagged by agent',
            ])->save();
        }

        return response()->json([
            'is_flagged' => $message->is_flagged,
            'flag_reason' => $message->flag_reason,
        ]);
    }

    public function translateMessage(Request $request, Message $message): JsonResponse
    {
        $targetLang = $request->input('target_lang', 'en');

        if ($message->translated_body && $message->translated_lang === $targetLang) {
            return response()->json([
                'translated_body' => $message->translated_body,
                'translated_lang' => $message->translated_lang,
                'cached' => true,
            ]);
        }

        if (! $message->body) {
            return response()->json(['error' => 'No text to translate'], 422);
        }

        $result = $this->translator->translate($message->body, $targetLang);

        if (! $result) {
            return response()->json(['error' => 'Translation failed'], 422);
        }

        $message->forceFill([
            'translated_body' => $result['translated'],
            'translated_lang' => $targetLang,
        ])->save();

        return response()->json([
            'translated_body' => $result['translated'],
            'translated_lang' => $targetLang,
            'detected_source' => $result['detected_source'],
            'cached' => false,
        ]);
    }

    public function saveDraft(Request $request, Conversation $conversation): JsonResponse
    {
        $validated = $request->validate([
            'draft_body' => ['nullable', 'string', 'max:5000'],
        ]);

        $conversation->forceFill([
            'draft_body' => $validated['draft_body'] !== '' ? $validated['draft_body'] : null,
        ])->save();

        return response()->json(['status' => 'ok']);
    }

    public function scheduleMessage(Request $request, Conversation $conversation): JsonResponse
    {
        $validated = $request->validate([
            'body' => ['required', 'string', 'max:2000'],
            'scheduled_at' => ['required', 'date', 'after:now'],
            'quick_replies' => ['nullable', 'array', 'max:11'],
            'quick_replies.*.title' => ['required', 'string', 'max:20'],
            'quick_replies.*.payload' => ['required', 'string', 'max:1000'],
        ]);

        $scheduled = ScheduledMessage::query()->create([
            'conversation_id' => $conversation->id,
            'facebook_page_id' => $conversation->facebook_page_id,
            'customer_identity_id' => $conversation->customer_identity_id,
            'body' => $validated['body'],
            'quick_replies' => $validated['quick_replies'] ?? null,
            'scheduled_at' => $validated['scheduled_at'],
            'status' => 'pending',
            'created_by' => $request->user()->id,
        ]);

        return response()->json([
            'scheduled_message' => $scheduled->only(['id', 'body', 'scheduled_at', 'status']),
        ]);
    }

    public function cancelScheduledMessage(Request $request, ScheduledMessage $scheduledMessage): JsonResponse
    {
        if ($scheduledMessage->status !== 'pending') {
            return response()->json(['error' => 'Cannot cancel a non-pending scheduled message'], 422);
        }

        $scheduledMessage->forceFill(['status' => 'cancelled'])->save();

        return response()->json(['status' => 'cancelled']);
    }

    public function broadcastMessage(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'body' => ['required', 'string', 'max:2000'],
            'conversation_ids' => ['required', 'array', 'min:1', 'max:50'],
            'conversation_ids.*' => ['required', 'integer', 'exists:conversations,id'],
        ]);

        $conversations = Conversation::query()
            ->whereIn('id', $validated['conversation_ids'])
            ->with(['facebookPage', 'identity'])
            ->get();

        $results = [];

        foreach ($conversations as $conversation) {
            if (! $conversation->facebookPage?->page_access_token || ! $conversation->identity?->provider_user_id) {
                $results[] = [
                    'conversation_id' => $conversation->id,
                    'status' => 'skipped',
                    'error' => 'Missing page token or customer PSID',
                ];
                continue;
            }

            try {
                $delivery = $this->facebookConnector->sendMessage(
                    $conversation->facebookPage,
                    $conversation->identity->provider_user_id,
                    $validated['body']
                );

                Message::query()->create([
                    'conversation_id' => $conversation->id,
                    'facebook_page_id' => $conversation->facebook_page_id,
                    'customer_identity_id' => $conversation->customer_identity_id,
                    'external_message_id' => $delivery['message_id'] ?? ('local-' . str()->uuid()),
                    'direction' => 'outbound',
                    'message_type' => 'text',
                    'body' => $validated['body'],
                    'raw_payload' => $delivery,
                    'sent_at' => now(),
                    'send_status' => 'sent',
                    'retry_count' => 0,
                ]);

                $conversation->forceFill([
                    'last_message_preview' => $validated['body'],
                    'last_message_at' => now(),
                    'draft_body' => null,
                ])->save();

                $results[] = [
                    'conversation_id' => $conversation->id,
                    'status' => 'sent',
                ];
            } catch (\Throwable $e) {
                $results[] = [
                    'conversation_id' => $conversation->id,
                    'status' => 'failed',
                    'error' => $e->getMessage(),
                ];
            }
        }

        $sent = count(array_filter($results, fn ($r) => $r['status'] === 'sent'));
        $failed = count(array_filter($results, fn ($r) => $r['status'] === 'failed'));
        $skipped = count(array_filter($results, fn ($r) => $r['status'] === 'skipped'));

        return response()->json([
            'results' => $results,
            'summary' => ['sent' => $sent, 'failed' => $failed, 'skipped' => $skipped],
        ]);
    }

    public function encoder(Request $request): Response
    {
        $orders = Order::query()
            ->with(['customer:id,name,phone,normalized_phone', 'product:id,name,sku', 'shopItems:id,order_id,product_name,quantity'])
            ->whereIn('status', [OrderStatus::CONFIRMED, OrderStatus::QA_APPROVED])
            ->whereNull('encoded_at')
            ->when($request->boolean('needs_review'), function ($q) {
                $q->where(function ($sub) {
                    $sub->whereNull('receiver_address')
                        ->orWhere('receiver_address', '')
                        ->orWhereNull('state')
                        ->orWhere('state', '')
                        ->orWhereNull('city')
                        ->orWhere('city', '')
                        ->orWhere('address_confidence', '<', 90)
                        ->orWhereNull('address_mapping_id');
                });
            })
            ->latest()
            ->paginate(25)
            ->withQueryString();

        $orders->getCollection()->each(function (Order $order) {
            $flags = [];
            if (empty($order->receiver_address)) {
                $flags[] = 'missing_address';
            }
            if (empty($order->barangay)) {
                $flags[] = 'missing_barangay';
            }
            if (empty($order->city)) {
                $flags[] = 'missing_city';
            }
            if (empty($order->state)) {
                $flags[] = 'missing_province';
            }
            if (floatval($order->address_confidence) < 90 && !empty($order->state)) {
                $flags[] = 'low_confidence';
            }
            if (empty($order->address_mapping_id)) {
                $flags[] = 'unmapped';
            }
            $order->setAttribute('address_flags', $flags);
        });

        return Inertia::render('Shop/Encoder', [
            'orders' => $orders,
            'recent_batches' => CourierExportBatch::query()
                ->withCount(['rows as failed_row_count' => fn ($q) => $q->where('status', 'failed')])
                ->with(['creator:id,name'])
                ->latest()
                ->limit(10)
                ->get(['id', 'batch_number', 'courier_code', 'region', 'status', 'row_count', 'file_path', 'exported_at', 'downloaded_at', 'archived_at', 'notes', 'created_by', 'created_at']),
            'couriers' => [
                ['value' => 'JNT', 'label' => 'J&T Express'],
                ['value' => 'FLASH', 'label' => 'Flash Express'],
                ['value' => 'GENERIC', 'label' => 'Generic CSV'],
            ],
            'filters' => $request->only(['needs_review']),
            'encoders' => \App\Models\User::query()
                ->where('is_active', true)
                ->whereIn('role', ['agent', 'supervisor', 'admin', 'superadmin'])
                ->orderBy('name')
                ->get(['id', 'name']),
            'tags' => \App\Domain\Shop\Models\Tag::query()
                ->orderBy('name')
                ->get(['id', 'name', 'slug', 'color']),
        ]);
    }

    public function exportCourier(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'courier_code' => ['required', 'string', 'max:30'],
            'order_ids' => ['nullable', 'array'],
            'order_ids.*' => ['integer', 'exists:orders,id'],
            'group_by_region' => ['nullable', 'boolean'],
        ]);

        $orders = Order::query()
            ->with(['product:id,name,sku', 'shopItems:id,order_id,product_name,quantity'])
            ->whereIn('status', [OrderStatus::CONFIRMED, OrderStatus::QA_APPROVED])
            ->whereNull('encoded_at')
            ->when(! empty($validated['order_ids']), fn ($query) => $query->whereIn('id', $validated['order_ids']))
            ->limit(500)
            ->get();

        if ($orders->isEmpty()) {
            return back()->with('error', 'No encoder-ready orders found for export.');
        }

        $validOrders = $orders->filter(fn (Order $o) => empty($this->getAddressIssues($o)));
        $skippedCount = $orders->count() - $validOrders->count();

        if ($validOrders->isEmpty()) {
            return back()->with('error', "All {$orders->count()} order(s) have unresolved address issues. Please correct addresses before exporting.");
        }

        $orders = $validOrders;
        $skipWarning = $skippedCount > 0 ? " ({$skippedCount} skipped due to address issues)" : '';

        if (! empty($validated['group_by_region'])) {
            $batches = $this->courierExports->createBatchesByRegion($orders, $validated['courier_code'], auth()->id());
            $count = $batches->count();
            $regions = $batches->map(fn ($b) => $b->region)->filter()->unique()->implode(', ');

            return redirect()
                ->route('shop.encoder')
                ->with('success', "Created {$count} batch(es) by region: {$regions}{$skipWarning}");
        }

        $batch = $this->courierExports->createBatch($orders, $validated['courier_code'], auth()->id());

        return redirect()
            ->route('shop.encoder')
            ->with('success', "Export batch {$batch->batch_number} created.{$skipWarning}");
    }

    public function exportMultipleCouriers(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'courier_codes' => ['required', 'array', 'min:1'],
            'courier_codes.*' => ['string', 'max:30'],
            'order_ids' => ['nullable', 'array'],
            'order_ids.*' => ['integer', 'exists:orders,id'],
        ]);

        $orders = Order::query()
            ->with(['product:id,name,sku', 'shopItems:id,order_id,product_name,quantity'])
            ->whereIn('status', [OrderStatus::CONFIRMED, OrderStatus::QA_APPROVED])
            ->whereNull('encoded_at')
            ->when(! empty($validated['order_ids']), fn ($query) => $query->whereIn('id', $validated['order_ids']))
            ->limit(500)
            ->get();

        if ($orders->isEmpty()) {
            return back()->with('error', 'No encoder-ready orders found for export.');
        }

        $validOrders = $orders->filter(fn (Order $o) => empty($this->getAddressIssues($o)));
        $skippedCount = $orders->count() - $validOrders->count();

        if ($validOrders->isEmpty()) {
            return back()->with('error', "All {$orders->count()} order(s) have unresolved address issues. Please correct addresses before exporting.");
        }

        $orders = $validOrders;
        $skipWarning = $skippedCount > 0 ? " ({$skippedCount} skipped due to address issues)" : '';

        $batches = $this->courierExports->createBatchesForCouriers($orders, $validated['courier_codes'], auth()->id());

        $count = $batches->count();
        $couriers = $batches->map(fn ($b) => $b->courier_code)->unique()->implode(', ');

        return redirect()
            ->route('shop.encoder')
            ->with('success', "Created {$count} batch(es) for couriers: {$couriers}{$skipWarning}");
    }

    public function archiveCourierBatch(CourierExportBatch $batch): RedirectResponse
    {
        if (! in_array($batch->status, [CourierExportBatch::STATUS_DOWNLOADED, CourierExportBatch::STATUS_READY])) {
            return back()->with('error', 'Only ready or downloaded batches can be archived.');
        }

        $batch->forceFill([
            'status' => CourierExportBatch::STATUS_ARCHIVED,
            'archived_at' => now(),
        ])->save();

        return back()->with('success', "Batch {$batch->batch_number} archived.");
    }

    public function deleteCourierBatch(CourierExportBatch $batch): RedirectResponse
    {
        if ($batch->status !== CourierExportBatch::STATUS_ARCHIVED) {
            return back()->with('error', 'Only archived batches can be deleted.');
        }

        if ($batch->file_path && Storage::disk('local')->exists($batch->file_path)) {
            Storage::disk('local')->delete($batch->file_path);
        }

        $batch->rows()->delete();
        $batch->delete();

        return back()->with('success', "Batch {$batch->batch_number} deleted.");
    }

    public function updateBatchNotes(Request $request, CourierExportBatch $batch): RedirectResponse
    {
        $validated = $request->validate([
            'notes' => ['nullable', 'string', 'max:5000'],
        ]);

        $batch->forceFill(['notes' => $validated['notes']])->save();

        return back()->with('success', "Notes updated for batch {$batch->batch_number}.");
    }

    public function previewBatch(CourierExportBatch $batch): JsonResponse
    {
        $rows = $batch->rows()
            ->orderBy('row_number')
            ->limit(100)
            ->get(['id', 'row_number', 'status', 'receiver_name', 'phone_number', 'complete_address', 'province', 'city', 'barangay', 'product_name', 'cod_amount', 'quantity', 'remarks', 'error_message']);

        return response()->json([
            'batch' => [
                'id' => $batch->id,
                'batch_number' => $batch->batch_number,
                'courier_code' => $batch->courier_code,
                'region' => $batch->region,
                'status' => $batch->status,
                'row_count' => $batch->row_count,
            ],
            'rows' => $rows,
        ]);
    }

    public function batchAnalytics(): JsonResponse
    {
        $batches = CourierExportBatch::query()
            ->withCount(['rows as total_rows'])
            ->withCount(['rows as exported_rows' => fn ($q) => $q->where('status', 'exported')])
            ->withCount(['rows as failed_rows' => fn ($q) => $q->where('status', 'failed')])
            ->latest()
            ->limit(50)
            ->get(['id', 'batch_number', 'courier_code', 'region', 'status', 'row_count', 'created_at']);

        $perBatch = $batches->map(fn ($b) => [
            'id' => $b->id,
            'batch_number' => $b->batch_number,
            'courier_code' => $b->courier_code,
            'region' => $b->region,
            'status' => $b->status,
            'total_rows' => $b->total_rows,
            'exported_rows' => $b->exported_rows,
            'failed_rows' => $b->failed_rows,
            'success_rate' => $b->total_rows > 0 ? round(($b->exported_rows / $b->total_rows) * 100, 1) : 0,
            'created_at' => $b->created_at?->toIso8601String(),
        ]);

        $totalBatches = $batches->count();
        $totalRows = $batches->sum('total_rows');
        $totalExported = $batches->sum('exported_rows');
        $totalFailed = $batches->sum('failed_rows');

        $byCourier = $batches->groupBy('courier_code')->map(fn ($group, $courier) => [
            'courier' => $courier,
            'batch_count' => $group->count(),
            'total_rows' => $group->sum('total_rows'),
            'exported_rows' => $group->sum('exported_rows'),
            'failed_rows' => $group->sum('failed_rows'),
            'success_rate' => $group->sum('total_rows') > 0
                ? round(($group->sum('exported_rows') / $group->sum('total_rows')) * 100, 1)
                : 0,
        ])->values();

        return response()->json([
            'per_batch' => $perBatch,
            'summary' => [
                'total_batches' => $totalBatches,
                'total_rows' => $totalRows,
                'total_exported' => $totalExported,
                'total_failed' => $totalFailed,
                'overall_success_rate' => $totalRows > 0 ? round(($totalExported / $totalRows) * 100, 1) : 0,
            ],
            'by_courier' => $byCourier,
        ]);
    }

    public function retryCourierBatch(CourierExportBatch $batch): RedirectResponse
    {
        $failedCount = $batch->rows()->where('status', 'failed')->count();

        if ($failedCount === 0) {
            return back()->with('error', 'No failed rows to retry in this batch.');
        }

        $this->courierExports->rebuildBatch($batch);

        $stillFailed = $batch->fresh()->rows()->where('status', 'failed')->count();

        return back()->with(
            $stillFailed > 0 ? 'warning' : 'success',
            $stillFailed > 0
                ? 'Rebuilt batch ' . $batch->batch_number . ': ' . ($failedCount - $stillFailed) . ' rows fixed, ' . $stillFailed . ' still failing.'
                : 'Rebuilt batch ' . $batch->batch_number . ': all ' . $failedCount . ' failed rows fixed.'
        );
    }

    public function updateOrderAddress(Request $request, Order $order): RedirectResponse
    {
        $validated = $request->validate([
            'receiver_address' => ['required', 'string', 'max:2000'],
            'barangay' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:255'],
            'state' => ['nullable', 'string', 'max:255'],
            'postal_code' => ['nullable', 'string', 'max:30'],
            'landmark' => ['nullable', 'string', 'max:255'],
            'nearest_landmark' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $before = [
            'receiver_address' => $order->receiver_address,
            'barangay' => $order->barangay,
            'city' => $order->city,
            'state' => $order->state,
            'postal_code' => $order->postal_code,
            'landmark' => $order->landmark,
            'nearest_landmark' => $order->nearest_landmark,
        ];
        $confidenceBefore = floatval($order->address_confidence ?? 0);

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
            'landmark' => $validated['landmark'] ?? null,
            'nearest_landmark' => $validated['nearest_landmark'] ?? null,
            'notes' => $validated['notes'] ?? $order->notes,
            'address_mapping_id' => $addressMatch['mapping']?->id,
            'address_confidence' => $addressMatch['confidence'],
        ])->save();

        AddressCorrectionHistory::create([
            'order_id' => $order->id,
            'user_id' => $request->user()?->id,
            'before' => $before,
            'after' => [
                'receiver_address' => $order->receiver_address,
                'barangay' => $order->barangay,
                'city' => $order->city,
                'state' => $order->state,
                'postal_code' => $order->postal_code,
                'landmark' => $order->landmark,
                'nearest_landmark' => $order->nearest_landmark,
            ],
            'confidence_before' => $confidenceBefore,
            'confidence_after' => floatval($addressMatch['confidence']),
            'action' => 'manual_edit',
        ]);

        return back()->with('success', "Address updated for {$order->order_number}.");
    }

    public function markEncoded(Order $order): RedirectResponse
    {
        $issues = $this->getAddressIssues($order);

        if (!empty($issues)) {
            return back()->with('error', "Cannot encode {$order->order_number}: " . implode(', ', $issues));
        }

        $order->forceFill([
            'encoded_at' => now(),
            'export_status' => 'ready',
        ])->save();

        return back()->with('success', "{$order->order_number} marked encoded.");
    }

    /**
     * @return string[]
     */
    private function getAddressIssues(Order $order): array
    {
        $issues = [];
        if (empty($order->receiver_address)) {
            $issues[] = 'missing address';
        }
        if (empty($order->barangay)) {
            $issues[] = 'missing barangay';
        }
        if (empty($order->city)) {
            $issues[] = 'missing city';
        }
        if (empty($order->state)) {
            $issues[] = 'missing province';
        }
        if (floatval($order->address_confidence) < 90 && !empty($order->state)) {
            $issues[] = 'low address confidence';
        }
        if (empty($order->address_mapping_id)) {
            $issues[] = 'unmapped address';
        }
        return $issues;
    }

    public function validateAddress(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'province' => ['nullable', 'string', 'max:255'],
            'city_municipality' => ['nullable', 'string', 'max:255'],
            'barangay' => ['nullable', 'string', 'max:255'],
        ]);

        return response()->json(
            $this->addressMappings->validate($validated)
        );
    }

    public function autocompleteAddress(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'field' => ['required', 'string', 'in:province,city_municipality,barangay'],
            'q' => ['required', 'string', 'min:1', 'max:255'],
            'province' => ['nullable', 'string', 'max:255'],
            'city_municipality' => ['nullable', 'string', 'max:255'],
        ]);

        return response()->json(
            $this->addressMappings->autocomplete($validated)
        );
    }

    public function suggestCorrection(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'field' => ['required', 'string', 'in:province,city_municipality,barangay'],
            'q' => ['required', 'string', 'min:2', 'max:255'],
            'province' => ['nullable', 'string', 'max:255'],
            'city_municipality' => ['nullable', 'string', 'max:255'],
        ]);

        return response()->json(
            $this->addressMappings->suggestCorrections($validated)
        );
    }

    public function addressValidationReport(): JsonResponse
    {
        $orders = Order::query()
            ->whereIn('status', [OrderStatus::CONFIRMED, OrderStatus::QA_APPROVED])
            ->whereNull('encoded_at')
            ->limit(500)
            ->get(['id', 'order_number', 'receiver_name', 'receiver_address', 'barangay', 'city', 'state', 'address_confidence', 'address_mapping_id']);

        $issueCounts = [
            'missing_address' => 0,
            'missing_barangay' => 0,
            'missing_city' => 0,
            'missing_province' => 0,
            'low_confidence' => 0,
            'unmapped' => 0,
        ];

        $ordersWithIssues = [];

        foreach ($orders as $order) {
            $issues = $this->getAddressIssues($order);
            if (empty($issues)) {
                continue;
            }
            foreach ($issues as $issue) {
                $key = match ($issue) {
                    'missing address' => 'missing_address',
                    'missing barangay' => 'missing_barangay',
                    'missing city' => 'missing_city',
                    'missing province' => 'missing_province',
                    'low address confidence' => 'low_confidence',
                    'unmapped address' => 'unmapped',
                    default => null,
                };
                if ($key) {
                    $issueCounts[$key]++;
                }
            }
            $ordersWithIssues[] = [
                'id' => $order->id,
                'order_number' => $order->order_number,
                'receiver_name' => $order->receiver_name,
                'issues' => $issues,
                'address_confidence' => floatval($order->address_confidence),
            ];
        }

        return response()->json([
            'total_orders' => $orders->count(),
            'valid_orders' => $orders->count() - count($ordersWithIssues),
            'orders_with_issues' => count($ordersWithIssues),
            'issue_summary' => $issueCounts,
            'orders' => $ordersWithIssues,
        ]);
    }

    public function addressCorrectionHistory(Order $order): JsonResponse
    {
        $history = AddressCorrectionHistory::query()
            ->where('order_id', $order->id)
            ->with('user:id,name')
            ->latest()
            ->limit(20)
            ->get()
            ->map(fn (AddressCorrectionHistory $entry) => [
                'id' => $entry->id,
                'user' => $entry->user?->name ?? 'System',
                'before' => $entry->before,
                'after' => $entry->after,
                'confidence_before' => $entry->confidence_before,
                'confidence_after' => $entry->confidence_after,
                'action' => $entry->action,
                'created_at' => $entry->created_at?->toIso8601String(),
            ]);

        return response()->json(['history' => $history]);
    }

    public function bulkAddressUpdate(Request $request): JsonResponse
    {
        $request->validate([
            'file' => ['required', 'file', 'mimes:csv,txt', 'max:5120'],
        ]);

        $path = $request->file('file')->getRealPath();
        $handle = fopen($path, 'r');
        if ($handle === false) {
            return response()->json(['error' => 'Cannot read file.'], 422);
        }

        $header = fgetcsv($handle);
        if ($header === false) {
            fclose($handle);
            return response()->json(['error' => 'Empty CSV file.'], 422);
        }

        $header = array_map(fn ($h) => strtolower(trim($h)), $header);
        $required = ['order_number'];
        foreach ($required as $col) {
            if (!in_array($col, $header, true)) {
                fclose($handle);
                return response()->json(['error' => "Missing required column: {$col}"], 422);
            }
        }

        $colMap = [];
        foreach (['order_number', 'receiver_address', 'barangay', 'city', 'state', 'postal_code', 'landmark', 'nearest_landmark'] as $field) {
            $idx = array_search($field, $header, true);
            if ($idx !== false) {
                $colMap[$field] = $idx;
            }
        }

        $updated = 0;
        $skipped = 0;
        $errors = [];
        $lineNum = 1;

        while (($row = fgetcsv($handle)) !== false) {
            $lineNum++;
            $orderNumber = trim($row[$colMap['order_number']] ?? '');
            if ($orderNumber === '') {
                $errors[] = "Line {$lineNum}: missing order_number";
                $skipped++;
                continue;
            }

            $order = Order::query()->where('order_number', $orderNumber)->first();
            if (!$order) {
                $errors[] = "Line {$lineNum}: order {$orderNumber} not found";
                $skipped++;
                continue;
            }

            $before = [
                'receiver_address' => $order->receiver_address,
                'barangay' => $order->barangay,
                'city' => $order->city,
                'state' => $order->state,
                'postal_code' => $order->postal_code,
                'landmark' => $order->landmark,
                'nearest_landmark' => $order->nearest_landmark,
            ];
            $confidenceBefore = floatval($order->address_confidence ?? 0);

            $changed = false;
            foreach ($colMap as $field => $idx) {
                if ($field === 'order_number') continue;
                $value = trim($row[$idx] ?? '');
                if ($value !== '' && $value !== $order->{$field}) {
                    $order->{$field} = $value;
                    $changed = true;
                }
            }

            if (!$changed) {
                $skipped++;
                continue;
            }

            $addressMatch = $this->addressMappings->match([
                'province' => $order->state,
                'city_municipality' => $order->city,
                'barangay' => $order->barangay,
                'address' => $order->receiver_address,
            ]);

            $order->address_mapping_id = $addressMatch['mapping']?->id;
            $order->address_confidence = $addressMatch['confidence'];
            $order->save();

            AddressCorrectionHistory::create([
                'order_id' => $order->id,
                'user_id' => $request->user()?->id,
                'before' => $before,
                'after' => [
                    'receiver_address' => $order->receiver_address,
                    'barangay' => $order->barangay,
                    'city' => $order->city,
                    'state' => $order->state,
                    'postal_code' => $order->postal_code,
                    'landmark' => $order->landmark,
                    'nearest_landmark' => $order->nearest_landmark,
                ],
                'confidence_before' => $confidenceBefore,
                'confidence_after' => floatval($addressMatch['confidence']),
                'action' => 'bulk_csv',
            ]);

            $updated++;
        }

        fclose($handle);

        return response()->json([
            'updated' => $updated,
            'skipped' => $skipped,
            'errors' => $errors,
        ]);
    }

    public function geocodeAddress(Request $request, Order $order): JsonResponse
    {
        $fullAddress = trim(($order->receiver_address ?? '') . ', ' . ($order->barangay ?? '') . ', ' . ($order->city ?? '') . ', ' . ($order->state ?? ''));

        $result = $this->geocoder->geocode($fullAddress);

        if (!$result['success']) {
            return response()->json([
                'success' => false,
                'message' => 'Could not geocode this address. Please check the address fields.',
            ]);
        }

        $order->forceFill([
            'latitude' => $result['latitude'],
            'longitude' => $result['longitude'],
        ])->save();

        $suggestions = [];
        foreach ($result['components'] as $field => $value) {
            if ($value !== null && $value !== '') {
                $current = match ($field) {
                    'province' => $order->state,
                    'city_municipality' => $order->city,
                    'barangay' => $order->barangay,
                    'postal_code' => $order->postal_code,
                    default => null,
                };
                if (!$current || strtolower(trim($current)) !== strtolower(trim($value))) {
                    $suggestions[$field] = $value;
                }
            }
        }

        return response()->json([
            'success' => true,
            'latitude' => $result['latitude'],
            'longitude' => $result['longitude'],
            'display_name' => $result['display_name'],
            'suggestions' => $suggestions,
        ]);
    }

    public function suggestPreviousAddress(Order $order): JsonResponse
    {
        $phone = $order->receiver_phone ? $this->phones->normalize($order->receiver_phone) : null;

        if (!$phone) {
            return response()->json(['suggestions' => []]);
        }

        $previous = Order::query()
            ->where('receiver_phone', $phone)
            ->where('id', '!=', $order->id)
            ->whereNotNull('receiver_address')
            ->where('receiver_address', '!=', '')
            ->whereIn('status', [
                \App\Domain\Order\Enums\OrderStatus::CONFIRMED,
                \App\Domain\Order\Enums\OrderStatus::QA_APPROVED,
                \App\Domain\Order\Enums\OrderStatus::DISPATCHED,
                \App\Domain\Order\Enums\OrderStatus::DELIVERED,
            ])
            ->latest()
            ->limit(20)
            ->get([
                'id',
                'order_number',
                'receiver_address',
                'barangay',
                'city',
                'state',
                'postal_code',
                'landmark',
                'nearest_landmark',
                'created_at',
            ]);

        $seen = [];
        $suggestions = [];

        foreach ($previous as $prev) {
            $key = md5(
                ($prev->receiver_address ?? '') . '|' .
                ($prev->barangay ?? '') . '|' .
                ($prev->city ?? '') . '|' .
                ($prev->state ?? '')
            );

            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;

            $suggestions[] = [
                'order_number' => $prev->order_number,
                'receiver_address' => $prev->receiver_address,
                'barangay' => $prev->barangay,
                'city' => $prev->city,
                'state' => $prev->state,
                'postal_code' => $prev->postal_code,
                'landmark' => $prev->landmark,
                'nearest_landmark' => $prev->nearest_landmark,
                'created_at' => $prev->created_at?->toIso8601String(),
            ];

            if (count($suggestions) >= 5) {
                break;
            }
        }

        return response()->json(['suggestions' => $suggestions]);
    }

    public function formatAddressByCourier(Request $request, Order $order): JsonResponse
    {
        $courierCode = $request->query('courier', $order->courier_code ?? 'GENERIC');

        $result = $this->addressFormatter->formatForCourier($order, $courierCode);

        return response()->json($result);
    }

    public function addressValidationAnalytics(): JsonResponse
    {
        $orders = Order::query()
            ->whereIn('status', [OrderStatus::CONFIRMED, OrderStatus::QA_APPROVED, OrderStatus::DISPATCHED, OrderStatus::DELIVERED])
            ->limit(1000)
            ->get(['id', 'order_number', 'receiver_address', 'barangay', 'city', 'state', 'postal_code', 'address_confidence', 'address_mapping_id', 'latitude', 'longitude', 'encoded_at']);

        $total = $orders->count();

        // Confidence distribution
        $confidenceBuckets = ['0-25' => 0, '26-50' => 0, '51-75' => 0, '76-90' => 0, '91-100' => 0];
        $confidenceSum = 0;
        $confidenceCount = 0;

        // Issue counts
        $issueCounts = [
            'missing_address' => 0,
            'missing_barangay' => 0,
            'missing_city' => 0,
            'missing_province' => 0,
            'low_confidence' => 0,
            'unmapped' => 0,
        ];

        // Geocoding coverage
        $geocoded = 0;
        $notGeocoded = 0;

        // Encoding status
        $encoded = 0;
        $notEncoded = 0;

        foreach ($orders as $order) {
            $confidence = floatval($order->address_confidence ?? 0);
            $confidenceSum += $confidence;
            $confidenceCount++;

            if ($confidence <= 25) $confidenceBuckets['0-25']++;
            elseif ($confidence <= 50) $confidenceBuckets['26-50']++;
            elseif ($confidence <= 75) $confidenceBuckets['51-75']++;
            elseif ($confidence <= 90) $confidenceBuckets['76-90']++;
            else $confidenceBuckets['91-100']++;

            $issues = $this->getAddressIssues($order);
            foreach ($issues as $issue) {
                $key = match ($issue) {
                    'missing address' => 'missing_address',
                    'missing barangay' => 'missing_barangay',
                    'missing city' => 'missing_city',
                    'missing province' => 'missing_province',
                    'low address confidence' => 'low_confidence',
                    'unmapped address' => 'unmapped',
                    default => null,
                };
                if ($key) $issueCounts[$key]++;
            }

            if ($order->latitude !== null && $order->longitude !== null) {
                $geocoded++;
            } else {
                $notGeocoded++;
            }

            if ($order->encoded_at !== null) {
                $encoded++;
            } else {
                $notEncoded++;
            }
        }

        // Correction history stats
        $totalCorrections = AddressCorrectionHistory::query()->count();
        $correctionsByAction = AddressCorrectionHistory::query()
            ->selectRaw('action, COUNT(*) as count')
            ->groupBy('action')
            ->pluck('count', 'action')
            ->toArray();

        $avgConfidenceBefore = (float) AddressCorrectionHistory::query()->avg('confidence_before');
        $avgConfidenceAfter = (float) AddressCorrectionHistory::query()->avg('confidence_after');

        // Top provinces with issues
        $provinceStats = $orders
            ->filter(fn ($o) => !empty($this->getAddressIssues($o)))
            ->groupBy(fn ($o) => $o->state ?? 'Unknown')
            ->map(fn ($group) => $group->count())
            ->sortDesc()
            ->take(10)
            ->toArray();

        return response()->json([
            'total_orders' => $total,
            'avg_confidence' => $confidenceCount > 0 ? round($confidenceSum / $confidenceCount, 2) : 0,
            'confidence_distribution' => $confidenceBuckets,
            'issue_summary' => $issueCounts,
            'orders_with_issues' => $orders->filter(fn ($o) => !empty($this->getAddressIssues($o)))->count(),
            'orders_valid' => $orders->filter(fn ($o) => empty($this->getAddressIssues($o)))->count(),
            'geocoding' => [
                'geocoded' => $geocoded,
                'not_geocoded' => $notGeocoded,
                'coverage_pct' => $total > 0 ? round(($geocoded / $total) * 100, 1) : 0,
            ],
            'encoding' => [
                'encoded' => $encoded,
                'not_encoded' => $notEncoded,
            ],
            'corrections' => [
                'total' => $totalCorrections,
                'by_action' => $correctionsByAction,
                'avg_confidence_before' => round($avgConfidenceBefore, 2),
                'avg_confidence_after' => round($avgConfidenceAfter, 2),
                'avg_improvement' => round($avgConfidenceAfter - $avgConfidenceBefore, 2),
            ],
            'top_provinces_with_issues' => $provinceStats,
        ]);
    }

    public function bulkStatusUpdate(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'order_ids'   => ['required', 'array', 'min:1'],
            'order_ids.*' => ['required', 'integer', 'exists:orders,id'],
            'status'      => ['required', 'string', 'in:CONFIRMED,QA_APPROVED,QA_PENDING,QA_REJECTED,CANCELLED,DISPATCHED,DELIVERED,RETURNED'],
            'reason'      => ['nullable', 'string', 'max:500'],
        ]);

        $status = OrderStatus::from($validated['status']);
        $reason = $validated['reason'] ?? null;
        $updated = 0;
        $skipped = [];
        $now = now();

        foreach ($validated['order_ids'] as $orderId) {
            $order = Order::query()->find($orderId);
            if (! $order) {
                $skipped[] = "Order #{$orderId} not found";
                continue;
            }

            if ($order->status->isTerminal()) {
                $skipped[] = "{$order->order_number} is terminal ({$order->status->label()})";
                continue;
            }

            $updateData = ['status' => $status];

            if ($status === OrderStatus::CONFIRMED && ! $order->confirmed_at) {
                $updateData['confirmed_at'] = $now;
            }
            if ($status === OrderStatus::DISPATCHED && ! $order->dispatched_at) {
                $updateData['dispatched_at'] = $now;
            }
            if ($status === OrderStatus::DELIVERED && ! $order->delivered_at) {
                $updateData['delivered_at'] = $now;
            }
            if ($status === OrderStatus::RETURNED && ! $order->returned_at) {
                $updateData['returned_at'] = $now;
            }
            if ($status === OrderStatus::QA_REJECTED && $reason) {
                $updateData['rejection_reason'] = $reason;
            }

            $order->forceFill($updateData)->save();
            $updated++;
        }

        return response()->json([
            'updated'  => $updated,
            'skipped'  => count($skipped),
            'errors'   => $skipped,
        ]);
    }

    public function bulkAssignEncoder(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'order_ids'   => ['required', 'array', 'min:1'],
            'order_ids.*' => ['required', 'integer', 'exists:orders,id'],
            'encoder_id'  => ['required', 'integer', 'exists:users,id'],
        ]);

        $updated = Order::query()
            ->whereIn('id', $validated['order_ids'])
            ->update(['encoder_id' => $validated['encoder_id']]);

        return response()->json([
            'updated'  => $updated,
            'skipped'  => 0,
            'errors'   => [],
        ]);
    }

    public function bulkPrintLabels(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'order_ids'   => ['required', 'array', 'min:1'],
            'order_ids.*' => ['required', 'integer', 'exists:orders,id'],
        ]);

        $orders = Order::query()
            ->whereIn('id', $validated['order_ids'])
            ->get(['id', 'order_number', 'receiver_name', 'receiver_phone', 'receiver_address', 'barangay', 'city', 'state', 'postal_code', 'courier_code', 'cod_amount', 'total_amount', 'quantity', 'product_id'])
            ->load('product:id,name,sku');

        $labels = $orders->map(fn (Order $order) => [
            'order_number'   => $order->order_number,
            'receiver_name'  => $order->receiver_name,
            'receiver_phone' => $order->receiver_phone,
            'address_line'   => trim(
                implode(', ', array_filter([
                    $order->receiver_address,
                    $order->barangay,
                    $order->city,
                    $order->state,
                    $order->postal_code,
                ]))
            ),
            'courier_code'   => $order->courier_code ?? 'MANUAL',
            'cod_amount'     => (float) $order->cod_amount,
            'quantity'       => $order->quantity,
            'product_name'   => $order->product?->name ?? 'N/A',
        ]);

        return response()->json([
            'labels' => $labels,
            'count'  => $labels->count(),
        ]);
    }

    public function bulkCodVerify(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'order_ids'   => ['required', 'array', 'min:1'],
            'order_ids.*' => ['required', 'integer', 'exists:orders,id'],
        ]);

        $orders = Order::query()
            ->whereIn('id', $validated['order_ids'])
            ->get(['id', 'order_number', 'receiver_name', 'total_amount', 'cod_amount', 'shipping_cost', 'discount_amount', 'tax_amount', 'quantity', 'unit_price']);

        $items = $orders->map(fn (Order $order) => [
            'id'              => $order->id,
            'order_number'    => $order->order_number,
            'receiver_name'   => $order->receiver_name,
            'quantity'        => $order->quantity,
            'unit_price'      => (float) $order->unit_price,
            'subtotal'        => (float) $order->unit_price * $order->quantity,
            'shipping_cost'   => (float) ($order->shipping_cost ?? 0),
            'discount_amount' => (float) ($order->discount_amount ?? 0),
            'tax_amount'      => (float) ($order->tax_amount ?? 0),
            'expected_cod'    => round((float) $order->unit_price * $order->quantity + (float) ($order->shipping_cost ?? 0) - (float) ($order->discount_amount ?? 0) + (float) ($order->tax_amount ?? 0), 2),
            'actual_cod'      => (float) $order->cod_amount,
            'discrepancy'     => round((float) $order->cod_amount - ((float) $order->unit_price * $order->quantity + (float) ($order->shipping_cost ?? 0) - (float) ($order->discount_amount ?? 0) + (float) ($order->tax_amount ?? 0)), 2),
            'is_correct'      => abs((float) $order->cod_amount - ((float) $order->unit_price * $order->quantity + (float) ($order->shipping_cost ?? 0) - (float) ($order->discount_amount ?? 0) + (float) ($order->tax_amount ?? 0))) < 0.01,
        ]);

        $correct = $items->filter(fn ($item) => $item['is_correct'])->count();
        $discrepant = $items->count() - $correct;

        return response()->json([
            'items'       => $items,
            'total'        => $items->count(),
            'correct'      => $correct,
            'discrepant'   => $discrepant,
            'total_discrepancy' => round($items->sum(fn ($item) => $item['discrepancy']), 2),
        ]);
    }

    public function bulkCodUpdate(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'updates'             => ['required', 'array', 'min:1'],
            'updates.*.order_id'  => ['required', 'integer', 'exists:orders,id'],
            'updates.*.cod_amount' => ['required', 'numeric', 'min:0'],
        ]);

        $updated = 0;
        $errors = [];

        foreach ($validated['updates'] as $update) {
            $order = Order::query()->find($update['order_id']);
            if (! $order) {
                $errors[] = "Order #{$update['order_id']} not found";
                continue;
            }
            $order->forceFill(['cod_amount' => $update['cod_amount']])->save();
            $updated++;
        }

        return response()->json([
            'updated' => $updated,
            'errors'  => $errors,
        ]);
    }

    public function bulkDuplicateDetect(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'order_ids'   => ['required', 'array', 'min:1'],
            'order_ids.*' => ['required', 'integer', 'exists:orders,id'],
        ]);

        $orders = Order::query()
            ->with('product:id,name,sku')
            ->whereIn('id', $validated['order_ids'])
            ->get(['id', 'order_number', 'receiver_name', 'receiver_phone', 'receiver_address', 'product_id', 'quantity', 'total_amount', 'cod_amount', 'status', 'created_at']);

        // Group by normalized phone + product_id
        $byPhoneProduct = $orders->groupBy(function (Order $order) {
            $phone = $this->phones->normalize($order->receiver_phone) ?: $order->receiver_phone;
            return $phone . '|' . $order->product_id;
        })->filter(fn ($group) => $group->count() > 1);

        // Group by normalized phone + address (first 50 chars)
        $byPhoneAddress = $orders->groupBy(function (Order $order) {
            $phone = $this->phones->normalize($order->receiver_phone) ?: $order->receiver_phone;
            return $phone . '|' . substr(strtolower(trim($order->receiver_address ?? '')), 0, 50);
        })->filter(fn ($group) => $group->count() > 1);

        // Merge groups, deduplicate by order ID set
        $seenKeys = [];
        $groups = [];

        foreach ($byPhoneProduct as $key => $group) {
            $orderIds = $group->pluck('id')->sort()->values()->all();
            $groupKey = implode(',', $orderIds);
            if (isset($seenKeys[$groupKey])) {
                continue;
            }
            $seenKeys[$groupKey] = true;
            $groups[] = [
                'match_type'  => 'phone+product',
                'phone'       => $this->phones->normalize($group->first()->receiver_phone) ?: $group->first()->receiver_phone,
                'product'     => $group->first()->product?->only(['id', 'name', 'sku']),
                'order_count' => $group->count(),
                'orders'      => $group->map(fn (Order $o) => [
                    'id'            => $o->id,
                    'order_number'  => $o->order_number,
                    'receiver_name' => $o->receiver_name,
                    'receiver_phone'=> $o->receiver_phone,
                    'quantity'      => $o->quantity,
                    'total_amount'  => (float) $o->total_amount,
                    'cod_amount'    => (float) $o->cod_amount,
                    'status'        => $o->status->value,
                    'created_at'    => $o->created_at?->toIso8601String(),
                ])->values()->all(),
            ];
        }

        foreach ($byPhoneAddress as $key => $group) {
            $orderIds = $group->pluck('id')->sort()->values()->all();
            $groupKey = implode(',', $orderIds);
            if (isset($seenKeys[$groupKey])) {
                continue;
            }
            $seenKeys[$groupKey] = true;
            $groups[] = [
                'match_type'  => 'phone+address',
                'phone'       => $this->phones->normalize($group->first()->receiver_phone) ?: $group->first()->receiver_phone,
                'address'     => substr($group->first()->receiver_address ?? '', 0, 80),
                'order_count' => $group->count(),
                'orders'      => $group->map(fn (Order $o) => [
                    'id'            => $o->id,
                    'order_number'  => $o->order_number,
                    'receiver_name' => $o->receiver_name,
                    'receiver_phone'=> $o->receiver_phone,
                    'quantity'      => $o->quantity,
                    'total_amount'  => (float) $o->total_amount,
                    'cod_amount'    => (float) $o->cod_amount,
                    'status'        => $o->status->value,
                    'created_at'    => $o->created_at?->toIso8601String(),
                ])->values()->all(),
            ];
        }

        $ordersInGroups = collect($groups)->flatMap(fn ($g) => array_column($g['orders'], 'id'))->unique()->count();

        return response()->json([
            'groups'           => $groups,
            'group_count'      => count($groups),
            'orders_in_groups' => $ordersInGroups,
            'total_checked'    => $orders->count(),
            'unique_orders'    => $orders->count() - $ordersInGroups,
        ]);
    }

    public function bulkHoldRelease(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'order_ids'   => ['required', 'array', 'min:1'],
            'order_ids.*' => ['required', 'integer', 'exists:orders,id'],
            'action'      => ['required', 'string', 'in:hold,release'],
            'reason'      => ['nullable', 'string', 'max:500'],
        ]);

        $orders = Order::query()
            ->whereIn('id', $validated['order_ids'])
            ->whereIn('status', [OrderStatus::CONFIRMED, OrderStatus::QA_APPROVED, OrderStatus::ON_HOLD])
            ->get();

        $held = 0;
        $released = 0;
        $skipped = 0;

        foreach ($orders as $order) {
            if ($validated['action'] === 'hold' && ! $order->status->isTerminal() && $order->status !== OrderStatus::ON_HOLD) {
                $order->forceFill([
                    'status'      => OrderStatus::ON_HOLD,
                    'held_at'     => now(),
                    'hold_reason' => $validated['reason'] ?? null,
                ])->save();
                $held++;
            } elseif ($validated['action'] === 'release' && $order->status === OrderStatus::ON_HOLD) {
                $order->forceFill([
                    'status'      => OrderStatus::CONFIRMED,
                    'held_at'     => null,
                    'hold_reason' => null,
                ])->save();
                $released++;
            } else {
                $skipped++;
            }
        }

        return response()->json([
            'held'    => $held,
            'released'=> $released,
            'skipped' => $skipped,
            'errors'  => [],
        ]);
    }

    public function bulkTagUpdate(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'order_ids'   => ['required', 'array', 'min:1'],
            'order_ids.*' => ['required', 'integer', 'exists:orders,id'],
            'tag_ids'     => ['nullable', 'array'],
            'tag_ids.*'   => ['integer', 'exists:tags,id'],
            'mode'        => ['required', 'string', 'in:add,replace,remove'],
        ]);

        $orders = Order::query()
            ->whereIn('id', $validated['order_ids'])
            ->get(['id']);

        $tagIds = $validated['tag_ids'] ?? [];
        $affected = 0;

        foreach ($orders as $order) {
            if ($validated['mode'] === 'add') {
                $order->tags()->syncWithoutDetaching($tagIds);
            } elseif ($validated['mode'] === 'replace') {
                $order->tags()->sync($tagIds);
            } elseif ($validated['mode'] === 'remove') {
                $order->tags()->detach($tagIds);
            }
            $affected++;
        }

        return response()->json([
            'updated'  => $affected,
            'mode'     => $validated['mode'],
            'tag_count'=> count($tagIds),
            'errors'   => [],
        ]);
    }

    public function downloadExport(CourierExportBatch $batch): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        if (! $batch->file_path || ! Storage::disk('local')->exists($batch->file_path)) {
            abort(404, 'Export file not found.');
        }

        if ($batch->status === CourierExportBatch::STATUS_READY) {
            $batch->forceFill([
                'status' => CourierExportBatch::STATUS_DOWNLOADED,
                'downloaded_at' => now(),
            ])->save();
        }

        $filename = $batch->batch_number . '.csv';

        return Storage::disk('local')->download($batch->file_path, $filename, [
            'Content-Type' => 'text/csv',
        ]);
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

    public function disconnectFacebookPage(FacebookPage $page): RedirectResponse
    {
        $page->forceFill([
            'connected_status' => 'disconnected',
            'webhook_status' => 'unsubscribed',
            'page_access_token' => null,
            'token_expires_at' => null,
        ])->save();

        return back()->with('success', "{$page->page_name} disconnected. Reconnect via Facebook OAuth to restore access.");
    }

    public function pos(): Response
    {
        $products = Product::query()
            ->with([
                'activeVariants:id,product_id,sku,variant_name,selling_price',
                'activeVariants.stock:id,product_id,variant_id,current_stock,reserved_stock',
                'stock:id,product_id,variant_id,current_stock,reserved_stock',
            ])
            ->where('is_active', true)
            ->orderBy('name')
            ->limit(100)
            ->get(['id', 'sku', 'name', 'brand', 'selling_price', 'image_url']);

        return Inertia::render('Shop/POS/Index', [
            'products' => $products->map(fn (Product $p) => [
                'id' => $p->id,
                'sku' => $p->sku,
                'name' => $p->name,
                'brand' => $p->brand,
                'selling_price' => (float) $p->selling_price,
                'image_url' => $p->image_url,
                'available_stock' => $p->stock?->available_stock ?? 0,
                'variants' => $p->activeVariants->map(fn ($v) => [
                    'id' => $v->id,
                    'sku' => $v->sku,
                    'variant_name' => $v->variant_name,
                    'selling_price' => (float) $v->selling_price,
                    'available_stock' => $v->stock?->available_stock ?? 0,
                ])->values(),
            ])->values(),
            'payment_methods' => [
                ['value' => 'CASH', 'label' => 'Cash'],
                ['value' => 'GCASH', 'label' => 'GCash'],
                ['value' => 'CARD', 'label' => 'Card'],
                ['value' => 'COD', 'label' => 'Cash on Delivery'],
            ],
        ]);
    }

    public function posSearch(Request $request)
    {
        $request->validate(['q' => ['nullable', 'string', 'max:100']]);
        $q = $request->string('q')->toString();

        $products = Product::query()
            ->with([
                'activeVariants:id,product_id,sku,variant_name,selling_price',
                'activeVariants.stock:id,product_id,variant_id,current_stock,reserved_stock',
                'stock:id,product_id,variant_id,current_stock,reserved_stock',
            ])
            ->where('is_active', true)
            ->when($q !== '', fn ($query) => $query->search($q))
            ->orderBy('name')
            ->limit(30)
            ->get(['id', 'sku', 'name', 'brand', 'selling_price', 'image_url']);

        return response()->json([
            'products' => $products->map(fn (Product $p) => [
                'id' => $p->id,
                'sku' => $p->sku,
                'name' => $p->name,
                'brand' => $p->brand,
                'selling_price' => (float) $p->selling_price,
                'image_url' => $p->image_url,
                'available_stock' => $p->stock?->available_stock ?? 0,
                'variants' => $p->activeVariants->map(fn ($v) => [
                    'id' => $v->id,
                    'sku' => $v->sku,
                    'variant_name' => $v->variant_name,
                    'selling_price' => (float) $v->selling_price,
                    'available_stock' => $v->stock?->available_stock ?? 0,
                ])->values(),
            ])->values(),
        ]);
    }

    public function posCheckout(Request $request)
    {
        $validated = $request->validate([
            'customer_name' => ['required', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:30'],
            'items' => ['required', 'array', 'min:1', 'max:50'],
            'items.*.product_id' => ['required', 'exists:products,id'],
            'items.*.variant_id' => ['nullable', 'exists:product_variants,id'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:999'],
            'items.*.unit_price' => ['required', 'numeric', 'min:0', 'max:999999.99'],
            'payment_method' => ['required', 'string', 'in:CASH,GCASH,CARD,COD'],
            'discount_amount' => ['nullable', 'numeric', 'min:0', 'max:999999.99'],
            'amount_paid' => ['nullable', 'numeric', 'min:0', 'max:999999.99'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $products = Product::query()
            ->with(['variants:id,product_id,sku,variant_name,selling_price'])
            ->whereIn('id', collect($validated['items'])->pluck('product_id')->all())
            ->get()
            ->keyBy('id');

        $preparedItems = collect($validated['items'])->map(function (array $item) use ($products) {
            $product = $products->get((int) $item['product_id']);
            abort_unless($product, 422, 'Selected product was not found.');

            $variant = null;
            if (! empty($item['variant_id'])) {
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
                'display_name' => $variant ? "{$product->name} - {$variant->variant_name}" : $product->name,
                'sku' => $variant?->sku ?? $product->sku,
            ];
        })->values();

        $primaryItem = $preparedItems->first();
        $discountAmount = (float) ($validated['discount_amount'] ?? 0);
        $totalQuantity = (int) $preparedItems->sum('quantity');
        $subtotal = (float) $preparedItems->sum('line_total');
        $totalAmount = max(0, $subtotal - $discountAmount);
        $amountPaid = (float) ($validated['amount_paid'] ?? 0);
        $change = max(0, $amountPaid - $totalAmount);

        $order = DB::transaction(function () use ($validated, $preparedItems, $primaryItem, $discountAmount, $totalQuantity, $subtotal, $totalAmount, $amountPaid, $change) {
            $customer = null;
            if (! empty($validated['phone'])) {
                $customer = $this->customerIdentities->firstOrCreateFromPhone([
                    'name' => $validated['customer_name'],
                    'phone' => $validated['phone'],
                ]);
            }

            $warehouse = Warehouse::query()->orderBy('id')->first();
            if (! $warehouse) {
                throw new \RuntimeException('No warehouse configured for stock deduction.');
            }

            foreach ($preparedItems as $item) {
                try {
                    $this->stockService->stockOut(
                        productId: $item['product']->id,
                        variantId: $item['variant']?->id,
                        warehouseId: $warehouse->id,
                        quantity: $item['quantity'],
                        referenceType: 'pos_order',
                        referenceId: 0,
                        performedBy: auth()->id(),
                    );
                } catch (InsufficientStockException $e) {
                    abort(422, "Insufficient stock for {$item['display_name']}: requested {$e->requested}, available {$e->available}.");
                }
            }

            $order = Order::query()->create([
                'order_number' => Order::generateOrderNumber(),
                'customer_id' => $customer?->id,
                'assigned_agent_id' => auth()->id(),
                'status' => OrderStatus::CONFIRMED,
                'courier_code' => 'MANUAL',
                'quantity' => $totalQuantity,
                'unit_price' => $primaryItem['unit_price'],
                'total_amount' => $totalAmount,
                'cod_amount' => $validated['payment_method'] === 'COD' ? $totalAmount : 0,
                'receiver_name' => $validated['customer_name'],
                'receiver_phone' => $validated['phone'] ?? null,
                'source_channel' => 'pos',
                'export_status' => 'pos',
                'confirmed_at' => now(),
                'notes' => $validated['notes'] ?? null,
            ]);

            foreach ($preparedItems as $item) {
                ShopOrderItem::query()->create([
                    'order_id' => $order->id,
                    'product_id' => $item['product']->id,
                    'variant_id' => $item['variant']?->id,
                    'sku' => $item['sku'],
                    'product_name' => $item['display_name'],
                    'quantity' => $item['quantity'],
                    'unit_price' => $item['unit_price'],
                    'discount_amount' => 0,
                    'line_total' => $item['line_total'],
                    'metadata' => ['pos_payment_method' => $validated['payment_method']],
                ]);
            }

            return $order;
        });

        return response()->json([
            'success' => true,
            'order' => [
                'id' => $order->id,
                'order_number' => $order->order_number,
                'total_amount' => (float) $order->total_amount,
                'change' => $change,
                'payment_method' => $validated['payment_method'],
                'items_count' => $totalQuantity,
            ],
        ]);
    }

    public function recommendProducts(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'product_ids' => ['required', 'array', 'min:1'],
            'product_ids.*' => ['integer'],
        ]);

        $productIds = $validated['product_ids'];

        $orderIds = ShopOrderItem::query()
            ->whereIn('product_id', $productIds)
            ->pluck('order_id')
            ->unique()
            ->take(500);

        $recommended = ShopOrderItem::query()
            ->whereIn('order_id', $orderIds)
            ->whereNotIn('product_id', $productIds)
            ->select('product_id', DB::raw('COUNT(*) as frequency'))
            ->groupBy('product_id')
            ->orderByDesc('frequency')
            ->limit(5)
            ->pluck('product_id');

        $products = Product::query()
            ->whereIn('id', $recommended)
            ->where('is_active', true)
            ->get(['id', 'sku', 'name', 'selling_price'])
            ->keyBy('id');

        $sorted = $recommended
            ->filter(fn ($id) => $products->has($id))
            ->map(fn ($id) => [
                'id' => $id,
                'sku' => $products[$id]->sku,
                'name' => $products[$id]->name,
                'selling_price' => (float) $products[$id]->selling_price,
            ])
            ->values();

        return response()->json(['recommendations' => $sorted]);
    }

    public function listCartTemplates(): JsonResponse
    {
        $templates = CartTemplate::query()
            ->sharedOrOwned(auth()->id())
            ->latest()
            ->limit(50)
            ->get(['id', 'name', 'items', 'courier_code', 'shipping_fee', 'discount_amount', 'tax_rate', 'remarks', 'is_shared', 'user_id', 'created_at'])
            ->map(fn ($t) => [
                'id'              => $t->id,
                'name'            => $t->name,
                'items'           => $t->items ?? [],
                'courier_code'    => $t->courier_code,
                'shipping_fee'    => (float) $t->shipping_fee,
                'discount_amount' => (float) $t->discount_amount,
                'tax_rate'        => (float) $t->tax_rate,
                'remarks'         => $t->remarks,
                'is_shared'       => $t->is_shared,
                'is_owner'        => $t->user_id === auth()->id(),
                'items_count'     => is_array($t->items) ? count($t->items) : 0,
                'created_at'      => $t->created_at?->toIso8601String(),
            ]);

        return response()->json(['templates' => $templates]);
    }

    public function storeCartTemplate(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name'              => ['required', 'string', 'max:100'],
            'items'             => ['required', 'array', 'min:1', 'max:20'],
            'items.*.product_id'  => ['required', 'exists:products,id'],
            'items.*.variant_id'  => ['nullable', 'exists:product_variants,id'],
            'items.*.quantity'    => ['required', 'integer', 'min:1', 'max:999'],
            'items.*.unit_price'  => ['required', 'numeric', 'min:0', 'max:999999.99'],
            'items.*.discount_amount' => ['nullable', 'numeric', 'min:0', 'max:999999.99'],
            'courier_code'      => ['nullable', 'string', 'max:30'],
            'shipping_fee'      => ['nullable', 'numeric', 'min:0', 'max:999999.99'],
            'discount_amount'   => ['nullable', 'numeric', 'min:0', 'max:999999.99'],
            'tax_rate'          => ['nullable', 'numeric', 'min:0', 'max:100'],
            'remarks'           => ['nullable', 'string', 'max:2000'],
            'is_shared'         => ['nullable', 'boolean'],
        ]);

        $template = CartTemplate::query()->create([
            'user_id'         => auth()->id(),
            'name'            => $validated['name'],
            'items'           => $validated['items'],
            'courier_code'    => $validated['courier_code'] ?? 'MANUAL',
            'shipping_fee'    => $validated['shipping_fee'] ?? 0,
            'discount_amount' => $validated['discount_amount'] ?? 0,
            'tax_rate'        => $validated['tax_rate'] ?? 0,
            'remarks'         => $validated['remarks'] ?? null,
            'is_shared'       => $validated['is_shared'] ?? false,
        ]);

        return response()->json(['success' => true, 'template_id' => $template->id]);
    }

    public function deleteCartTemplate(Request $request, CartTemplate $template): JsonResponse
    {
        if ($template->user_id !== auth()->id()) {
            abort(403);
        }

        $template->delete();

        return response()->json(['success' => true]);
    }

    public function checkDuplicates(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'phone' => ['nullable', 'string', 'max:30'],
            'product_ids' => ['nullable', 'array'],
            'product_ids.*' => ['integer'],
        ]);

        $phone = $validated['phone'] ?? '';
        $productIds = $validated['product_ids'] ?? [];

        $normalizedPhone = $this->phones->normalize($phone);
        if (! $normalizedPhone) {
            return response()->json(['duplicates' => []]);
        }

        $query = Order::query()
            ->with('product:id,name,sku')
            ->where('receiver_phone', $normalizedPhone)
            ->whereIn('source_channel', ['manual_shop', 'facebook_shop'])
            ->where('created_at', '>=', now()->subDays(30))
            ->where('status', '!=', OrderStatus::DRAFT)
            ->latest()
            ->limit(10);

        if (! empty($productIds)) {
            $query->where(function ($q) use ($productIds) {
                $q->whereIn('product_id', $productIds)
                    ->orWhereHas('shopItems', function ($sq) use ($productIds) {
                        $sq->whereIn('product_id', $productIds);
                    });
            });
        }

        $duplicates = $query->get(['id', 'order_number', 'product_id', 'status', 'total_amount', 'created_at'])
            ->map(fn ($o) => [
                'id' => $o->id,
                'order_number' => $o->order_number,
                'status' => $o->status->value,
                'total_amount' => (float) $o->total_amount,
                'created_at' => $o->created_at?->toIso8601String(),
                'product' => $o->product?->only(['id', 'name', 'sku']),
            ]);

        return response()->json(['duplicates' => $duplicates]);
    }

    public function calculateShipping(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'province' => ['nullable', 'string', 'max:255'],
            'city_municipality' => ['nullable', 'string', 'max:255'],
            'barangay' => ['nullable', 'string', 'max:255'],
            'address' => ['nullable', 'string', 'max:2000'],
            'courier_code' => ['nullable', 'string', 'max:30'],
            'weight' => ['nullable', 'numeric', 'min:0'],
        ]);

        $result = $this->shippingRates->calculate(
            [
                'province' => $validated['province'] ?? null,
                'city_municipality' => $validated['city_municipality'] ?? null,
                'barangay' => $validated['barangay'] ?? null,
                'address' => $validated['address'] ?? null,
            ],
            $validated['courier_code'] ?? 'MANUAL',
            ['weight' => (float) ($validated['weight'] ?? 0)],
        );

        return response()->json([
            'fee' => $result['fee'],
            'zone' => $result['zone'],
            'has_rate' => $result['has_rate'],
        ]);
    }

    public function orders(Request $request): Response
    {
        $query = Order::query()
            ->with([
                'customer:id,name,phone,normalized_phone',
                'shopItems:id,order_id,product_name,quantity',
                'agent:id,name',
            ])
            ->whereIn('source_channel', ['manual_shop', 'facebook_shop'])
            ->when($request->filled('q'), function ($q) use ($request) {
                $search = $request->string('q')->toString();
                $q->where(function ($sub) use ($search) {
                    $sub->where('order_number', 'like', "%{$search}%")
                        ->orWhere('receiver_name', 'like', "%{$search}%")
                        ->orWhere('receiver_phone', 'like', "%{$search}%");
                });
            })
            ->when($request->filled('status'), function ($q) use ($request) {
                $q->where('status', $request->string('status')->toString());
            })
            ->when($request->filled('remark_q'), function ($q) use ($request) {
                $search = $request->string('remark_q')->toString();
                $q->whereHas('remarks', function ($sub) use ($search) {
                    $sub->where('body', 'like', "%{$search}%");
                });
            })
            ->when($request->filled('remark_type'), function ($q) use ($request) {
                $type = $request->string('remark_type')->toString();
                $q->whereHas('remarks', function ($sub) use ($type) {
                    $sub->where('type', $type);
                });
            })
            ->when($request->filled('remark_author'), function ($q) use ($request) {
                $authorId = (int) $request->string('remark_author')->toString();
                $q->whereHas('remarks', function ($sub) use ($authorId) {
                    $sub->where('user_id', $authorId);
                });
            })
            ->when($request->filled('remark_tag'), function ($q) use ($request) {
                $tag = $request->string('remark_tag')->toString();
                $q->whereHas('remarks', function ($sub) use ($tag) {
                    $sub->whereJsonContains('tags', $tag);
                });
            })
            ->latest();

        $orders = $query->paginate(25)->withQueryString();

        $remarkAuthors = OrderRemark::query()
            ->select('user_id')
            ->distinct()
            ->with('user:id,name')
            ->whereHas('order', function ($q) {
                $q->whereIn('source_channel', ['manual_shop', 'facebook_shop']);
            })
            ->get()
            ->pluck('user.name', 'user.id')
            ->filter()
            ->toArray();

        $remarkTags = OrderRemark::query()
            ->whereNotNull('tags')
            ->whereHas('order', function ($q) {
                $q->whereIn('source_channel', ['manual_shop', 'facebook_shop']);
            })
            ->pluck('tags')
            ->flatten()
            ->filter()
            ->unique()
            ->values()
            ->toArray();

        return Inertia::render('Shop/Orders/Index', [
            'orders' => $orders,
            'filters' => $request->only(['q', 'status', 'remark_q', 'remark_type', 'remark_author', 'remark_tag']),
            'remarkAuthors' => $remarkAuthors,
            'remarkTags' => $remarkTags,
        ]);
    }

    public function order(Request $request, Order $order): Response
    {
        $order->load([
            'customer:id,name,phone,normalized_phone,risk_level,is_blacklisted,canonical_address,barangay,city_municipality,province',
            'shopItems.product:id,name,sku',
            'shopItems.variant:id,product_id,sku,variant_name',
            'agent:id,name',
            'remarks' => fn ($q) => $q->whereNull('parent_id')->orderByDesc('is_pinned')->orderBy('created_at'),
            'remarks.replies.user:id,name',
            'remarks.user:id,name',
            'remarks.pinnedBy:id,name',
        ]);

        return Inertia::render('Shop/Orders/Show', [
            'order' => $order,
            'remarkTemplates' => RemarkTemplate::query()
                ->where('is_active', true)
                ->orderBy('name')
                ->get(['id', 'name', 'body', 'type', 'visibility']),
            'mentionableUsers' => User::query()
                ->whereIn('role', ['supervisor', 'admin', 'superadmin'])
                ->where('is_active', true)
                ->orderBy('name')
                ->get(['id', 'name', 'role']),
        ]);
    }

    public function createOrder(Request $request): Response
    {
        $conversation = null;

        if ($request->filled('conversation_id')) {
            $conversation = Conversation::query()
                ->with([
                    'facebookPage:id,page_name,page_id,default_courier',
                    'customer:id,name,phone,normalized_phone,canonical_address,preferred_courier',
                    'identity:id,display_name,provider_user_id,phone_detected',
                    'messages' => fn ($query) => $query->latest('sent_at')->limit(5),
                ])
                ->find($request->integer('conversation_id'));
        }

        return Inertia::render('Shop/CreateOrder', [
            'products' => Product::query()
                ->with([
                    'activeVariants:id,product_id,sku,variant_name,selling_price',
                    'activeVariants.stock:id,product_id,variant_id,current_stock,reserved_stock',
                    'stock:id,product_id,variant_id,current_stock,reserved_stock',
                ])
                ->where('is_active', true)
                ->orderBy('name')
                ->get(['id', 'sku', 'name', 'selling_price'])
                ->map(fn ($p) => [
                    'id' => $p->id,
                    'sku' => $p->sku,
                    'name' => $p->name,
                    'selling_price' => $p->selling_price,
                    'available_stock' => $p->available_stock,
                    'active_variants' => $p->activeVariants->map(fn ($v) => [
                        'id' => $v->id,
                        'sku' => $v->sku,
                        'variant_name' => $v->variant_name,
                        'selling_price' => $v->selling_price,
                        'available_stock' => $v->stock?->available_stock ?? 0,
                    ])->values(),
                ]),
            'couriers' => [
                ['value' => 'MANUAL', 'label' => 'Manual'],
                ['value' => 'JNT', 'label' => 'J&T Express'],
                ['value' => 'FLASH', 'label' => 'Flash Express'],
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
                'normalized_phone' => $conversation->customer?->normalized_phone
                    ?? ($conversation->customer?->phone ? null : null),
                'customer_id' => $conversation->customer?->id,
                'customer_risk_level' => $conversation->customer?->risk_level,
                'customer_is_blacklisted' => (bool) $conversation->customer?->is_blacklisted,
                'complete_address' => $request->filled('complete_address')
                    ? $request->string('complete_address')->toString()
                    : ($conversation->customer?->canonical_address ?? ''),
                'landmark' => $request->filled('landmark')
                    ? $request->string('landmark')->toString()
                    : ($conversation->customer?->landmark ?? ''),
                'barangay' => $request->filled('barangay')
                    ? $request->string('barangay')->toString()
                    : ($conversation->customer?->barangay ?? ''),
                'city_municipality' => $request->filled('city_municipality')
                    ? $request->string('city_municipality')->toString()
                    : ($conversation->customer?->city_municipality ?? ''),
                'province' => $request->filled('province')
                    ? $request->string('province')->toString()
                    : ($conversation->customer?->province ?? ''),
                'remarks' => trim(implode("\n", array_filter([
                    "Conversation #{$conversation->id}",
                    $conversation->facebookPage ? "Page: {$conversation->facebookPage->page_name}" : null,
                    $conversation->last_message_preview ? "Last message: {$conversation->last_message_preview}" : null,
                ]))),
                'courier_code' => $conversation->facebookPage?->default_courier
                    ?: $conversation->customer?->preferred_courier
                    ?: 'MANUAL',
            ] : null,
            'duplicate_warnings' => $this->duplicateWarningsForPhone(
                $conversation?->customer?->normalized_phone
                    ?? $conversation?->customer?->phone
                    ?? $conversation?->identity?->phone_detected
            ),
            'drafts' => Order::query()
                ->where('status', OrderStatus::DRAFT)
                ->where('assigned_agent_id', auth()->id())
                ->latest()
                ->limit(10)
                ->get(['id', 'order_number', 'receiver_name', 'receiver_phone', 'created_at', 'draft_data'])
                ->map(fn ($o) => [
                    'id' => $o->id,
                    'order_number' => $o->order_number,
                    'customer_name' => $o->receiver_name,
                    'phone' => $o->receiver_phone,
                    'created_at' => $o->created_at?->toDateTimeString(),
                    'items_count' => isset($o->draft_data['items']) ? count($o->draft_data['items']) : 0,
                ]),
        ]);
    }

    public function editOrder(Request $request, Order $order): Response
    {
        $order->load(['shopItems.product.activeVariants', 'shopItems.variant', 'customer:id,normalized_phone,risk_level,is_blacklisted']);

        $prefill = [
            'conversation_id' => $order->conversation_id ? (string) $order->conversation_id : '',
            'customer_name' => $order->receiver_name ?? '',
            'phone' => $order->receiver_phone ?? '',
            'normalized_phone' => $order->customer?->normalized_phone,
            'customer_id' => $order->customer?->id,
            'customer_risk_level' => $order->customer?->risk_level,
            'customer_is_blacklisted' => (bool) $order->customer?->is_blacklisted,
            'complete_address' => $order->receiver_address ?? '',
            'barangay' => $order->barangay ?? '',
            'city_municipality' => $order->city ?? '',
            'province' => $order->state ?? '',
            'courier_code' => $order->courier_code ?? 'MANUAL',
            'shipping_fee' => (string) ($order->shipping_cost ?? 0),
            'discount_amount' => (string) ($order->discount_amount ?? 0),
            'tax_rate' => (string) ($order->tax_rate ?? 0),
            'cod_amount' => (string) ($order->cod_amount ?? 0),
            'remarks' => $order->remarks ?? $order->notes ?? '',
            'items' => $order->shopItems->map(fn ($item) => [
                'product_id' => (string) $item->product_id,
                'variant_id' => $item->variant_id ? (string) $item->variant_id : '',
                'quantity' => (string) $item->quantity,
                'unit_price' => (string) $item->unit_price,
                'discount_amount' => (string) ($item->discount_amount ?? 0),
            ])->toArray(),
        ];

        return Inertia::render('Shop/CreateOrder', [
            'products' => Product::query()
                ->with([
                    'activeVariants:id,product_id,sku,variant_name,selling_price',
                    'activeVariants.stock:id,product_id,variant_id,current_stock,reserved_stock',
                    'stock:id,product_id,variant_id,current_stock,reserved_stock',
                ])
                ->where('is_active', true)
                ->orderBy('name')
                ->get(['id', 'sku', 'name', 'selling_price'])
                ->map(fn ($p) => [
                    'id' => $p->id,
                    'sku' => $p->sku,
                    'name' => $p->name,
                    'selling_price' => $p->selling_price,
                    'available_stock' => $p->available_stock,
                    'active_variants' => $p->activeVariants->map(fn ($v) => [
                        'id' => $v->id,
                        'sku' => $v->sku,
                        'variant_name' => $v->variant_name,
                        'selling_price' => $v->selling_price,
                        'available_stock' => $v->stock?->available_stock ?? 0,
                    ])->values(),
                ]),
            'couriers' => [
                ['value' => 'MANUAL', 'label' => 'Manual'],
                ['value' => 'JNT', 'label' => 'J&T Express'],
                ['value' => 'FLASH', 'label' => 'Flash Express'],
            ],
            'prefill' => $prefill,
            'edit_order_id' => $order->id,
            'edit_order_number' => $order->order_number,
            'duplicate_warnings' => [],
            'drafts' => [],
        ]);
    }

    public function updateOrder(Request $request, Order $order): RedirectResponse
    {
        if ($order->status->isTerminal()) {
            return back()->with('error', 'Cannot edit a completed order.');
        }

        $validated = $request->validate([
            'customer_name' => ['required', 'string', 'max:255'],
            'phone' => ['required', 'string', 'max:30'],
            'customer_id' => ['nullable', 'integer', 'exists:customers,id'],
            'update_customer_phone' => ['nullable', 'boolean'],
            'complete_address' => ['required', 'string', 'max:2000'],
            'landmark' => ['nullable', 'string', 'max:255'],
            'barangay' => ['nullable', 'string', 'max:255'],
            'city_municipality' => ['nullable', 'string', 'max:255'],
            'province' => ['nullable', 'string', 'max:255'],
            'items' => ['required', 'array', 'min:1', 'max:20'],
            'items.*.product_id' => ['required', 'exists:products,id'],
            'items.*.variant_id' => ['nullable', 'exists:product_variants,id'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:999'],
            'items.*.unit_price' => ['required', 'numeric', 'min:0', 'max:999999.99'],
            'items.*.discount_amount' => ['nullable', 'numeric', 'min:0', 'max:999999.99'],
            'shipping_fee' => ['nullable', 'numeric', 'min:0', 'max:999999.99'],
            'discount_amount' => ['nullable', 'numeric', 'min:0', 'max:999999.99'],
            'tax_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'tax_amount' => ['nullable', 'numeric', 'min:0', 'max:999999.99'],
            'courier_code' => ['nullable', 'string', 'max:30'],
            'remarks' => ['nullable', 'string', 'max:2000'],
            'cod_amount' => ['nullable', 'numeric', 'min:0', 'max:9999999.99'],
        ]);

        $products = Product::query()
            ->with('variants:id,product_id,sku,variant_name,selling_price')
            ->whereIn('id', collect($validated['items'])->pluck('product_id')->all())
            ->get()
            ->keyBy('id');

        $preparedItems = collect($validated['items'])->map(function (array $item) use ($products) {
            $product = $products->get((int) $item['product_id']);
            abort_unless($product, 422, 'Selected product was not found.');

            $variant = null;
            if (! empty($item['variant_id'])) {
                $variant = $product->variants->firstWhere('id', (int) $item['variant_id']);
                abort_unless($variant, 422, 'Selected variant does not belong to the product.');
            }

            $quantity = (int) $item['quantity'];
            $unitPrice = (float) $item['unit_price'];
            $lineDiscount = (float) ($item['discount_amount'] ?? 0);
            $lineTotal = max(0, ($quantity * $unitPrice) - $lineDiscount);

            return [
                'product' => $product,
                'variant' => $variant,
                'quantity' => $quantity,
                'unit_price' => $unitPrice,
                'discount_amount' => $lineDiscount,
                'line_total' => $lineTotal,
                'display_name' => $variant ? "{$product->name} - {$variant->variant_name}" : $product->name,
                'sku' => $variant?->sku ?? $product->sku,
            ];
        })->values();

        $primaryItem = $preparedItems->first();
        abort_unless($primaryItem, 422, 'At least one cart item is required.');
        $shippingFee = (float) ($validated['shipping_fee'] ?? 0);
        $orderDiscount = (float) ($validated['discount_amount'] ?? 0);
        $taxRate = (float) ($validated['tax_rate'] ?? 0);
        $taxableAmount = max(0, (float) $preparedItems->sum('line_total') - $orderDiscount);
        $taxAmount = $taxRate > 0 ? round($taxableAmount * $taxRate / 100, 2) : (float) ($validated['tax_amount'] ?? 0);
        $totalQuantity = (int) $preparedItems->sum('quantity');
        $totalAmount = max(0, $taxableAmount + $shippingFee + $taxAmount);
        $normalizedPhone = $this->phones->normalize($validated['phone']);

        $order = DB::transaction(function () use (
            $validated, $order, $preparedItems, $primaryItem,
            $shippingFee, $orderDiscount, $taxRate, $taxAmount,
            $totalQuantity, $totalAmount, $normalizedPhone
        ) {
            $customer = $this->resolveCustomerForOrder($validated);

            if ($order->conversation_id) {
                $conversation = Conversation::find($order->conversation_id);
                if ($conversation && ! $conversation->customer_id) {
                    $conversation->forceFill(['customer_id' => $customer->id])->save();
                }
            }

            $previousRemarks = $order->remarks ?? $order->notes;

            $order->forceFill([
                'customer_id' => $customer->id,
                'product_id' => $primaryItem['product']->id,
                'variant_id' => $primaryItem['variant']?->id,
                'courier_code' => $validated['courier_code'] ?? 'MANUAL',
                'quantity' => $totalQuantity,
                'unit_price' => $primaryItem['unit_price'],
                'total_amount' => $totalAmount,
                'cod_amount' => isset($validated['cod_amount']) && $validated['cod_amount'] !== ''
                    ? (float) $validated['cod_amount']
                    : $totalAmount,
                'shipping_cost' => $shippingFee,
                'discount_amount' => $orderDiscount,
                'tax_rate' => $taxRate,
                'tax_amount' => $taxAmount,
                'receiver_name' => $validated['customer_name'],
                'receiver_phone' => $normalizedPhone ?: $validated['phone'],
                'receiver_address' => $validated['complete_address'],
                'city' => $validated['city_municipality'] ?? null,
                'state' => $validated['province'] ?? null,
                'barangay' => $validated['barangay'] ?? null,
                'notes' => $validated['remarks'] ?? null,
                'remarks' => $validated['remarks'] ?? null,
            ])->save();

            $order->shopItems()->delete();

            foreach ($preparedItems as $item) {
                ShopOrderItem::query()->create([
                    'order_id' => $order->id,
                    'product_id' => $item['product']->id,
                    'variant_id' => $item['variant']?->id,
                    'sku' => $item['sku'],
                    'product_name' => $item['display_name'],
                    'quantity' => $item['quantity'],
                    'unit_price' => $item['unit_price'],
                    'discount_amount' => $item['discount_amount'],
                    'line_total' => $item['line_total'],
                ]);
            }

            $newRemarks = $validated['remarks'] ?? null;
            if (! empty($newRemarks) && $newRemarks !== $previousRemarks) {
                OrderRemark::query()->create([
                    'order_id' => $order->id,
                    'user_id' => auth()->id(),
                    'type' => 'agent_note',
                    'visibility' => 'internal',
                    'body' => $newRemarks,
                ]);
            }

            return $order;
        });

        return redirect()
            ->route('orders.show', $order)
            ->with('success', "Order {$order->order_number} updated.");
    }

    public function manualFollowUp(Request $request, Order $order): RedirectResponse
    {
        if ($order->status->isTerminal()) {
            return back()->with('error', 'Cannot follow up on a completed order.');
        }

        if (! $order->conversation_id) {
            return back()->with('error', 'Order is not linked to a conversation.');
        }

        $conversation = Conversation::find($order->conversation_id);
        if (! $conversation) {
            return back()->with('error', 'Linked conversation not found.');
        }

        $elapsedDays = $order->dispatched_at
            ? (int) now()->diffInDays($order->dispatched_at)
            : 0;

        $body = $elapsedDays > 0
            ? "⏰ Follow-up: Order {$order->order_number} has been dispatched for {$elapsedDays} day(s) with no delivery confirmation. Please check courier tracking."
            : "⏰ Follow-up: Order {$order->order_number} ({$order->status->label()}). Please check status with courier.";

        Message::query()->create([
            'conversation_id' => $order->conversation_id,
            'facebook_page_id' => $conversation->facebook_page_id,
            'sent_by' => $request->user()->id,
            'external_message_id' => 'system-' . str()->uuid(),
            'direction' => 'system',
            'message_type' => 'order_followup',
            'body' => $body,
            'metadata' => [
                'order_id' => $order->id,
                'order_number' => $order->order_number,
                'order_status' => $order->status->value,
                'days_dispatched' => $elapsedDays,
            ],
            'sent_at' => now(),
            'send_status' => 'logged',
            'retry_count' => 0,
        ]);

        $conversation->forceFill([
            'last_message_preview' => $body,
            'last_message_at' => now(),
        ])->save();

        OrderRemark::query()->create([
            'order_id' => $order->id,
            'conversation_id' => $order->conversation_id,
            'user_id' => $request->user()->id,
            'type' => 'follow_up',
            'body' => $body,
            'metadata' => ['days_dispatched' => $elapsedDays, 'manual' => true],
        ]);

        return back()->with('success', "Follow-up posted for order {$order->order_number}.");
    }

    public function splitOrder(Request $request, Order $order): RedirectResponse
    {
        if ($order->status->isTerminal()) {
            return back()->with('error', 'Cannot split a completed order.');
        }

        $order->load('shopItems');
        if ($order->shopItems->count() < 2) {
            return back()->with('error', 'Cannot split: order must have at least 2 items.');
        }

        $validated = $request->validate([
            'item_ids'   => ['required', 'array', 'min:1'],
            'item_ids.*' => ['integer', 'exists:shop_order_items,id'],
        ]);

        $validItemIds = $order->shopItems->pluck('id')->toArray();
        $splitIds = array_filter($validated['item_ids'], fn ($id) => in_array($id, $validItemIds));

        if (count($splitIds) === 0 || count($splitIds) >= count($validItemIds)) {
            return back()->with('error', 'Cannot split: must select at least one item but not all items.');
        }

        try {
            $childOrder = $this->fulfillment->splitOrder($order, $splitIds);
        } catch (\InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', "Order split: {$order->order_number} → {$childOrder->order_number}.");
    }

    public function storeDraft(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'customer_name' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:30'],
            'complete_address' => ['nullable', 'string', 'max:2000'],
            'landmark' => ['nullable', 'string', 'max:255'],
            'barangay' => ['nullable', 'string', 'max:255'],
            'city_municipality' => ['nullable', 'string', 'max:255'],
            'province' => ['nullable', 'string', 'max:255'],
            'items' => ['nullable', 'array', 'max:20'],
            'items.*.product_id' => ['nullable', 'string'],
            'items.*.variant_id' => ['nullable', 'string'],
            'items.*.quantity' => ['nullable', 'string'],
            'items.*.unit_price' => ['nullable', 'string'],
            'items.*.discount_amount' => ['nullable', 'string'],
            'shipping_fee' => ['nullable', 'string'],
            'discount_amount' => ['nullable', 'string'],
            'tax_rate' => ['nullable', 'string'],
            'courier_code' => ['nullable', 'string', 'max:30'],
            'remarks' => ['nullable', 'string', 'max:2000'],
            'conversation_id' => ['nullable', 'string'],
            'draft_id' => ['nullable', 'integer', 'exists:orders,id'],
        ]);

        $draftData = $validated;

        if (! empty($validated['draft_id'])) {
            $order = Order::query()->where('id', $validated['draft_id'])->where('status', OrderStatus::DRAFT)->first();
            if ($order) {
                $order->forceFill([
                    'receiver_name' => $validated['customer_name'] ?? '',
                    'receiver_phone' => $validated['phone'] ?? '',
                    'receiver_address' => $validated['complete_address'] ?? '',
                    'courier_code' => $validated['courier_code'] ?? 'MANUAL',
                    'notes' => $validated['remarks'] ?? null,
                    'draft_data' => $draftData,
                ])->save();

                return response()->json(['success' => true, 'draft_id' => $order->id]);
            }
        }

        $order = Order::query()->create([
            'order_number' => Order::generateOrderNumber(),
            'assigned_agent_id' => auth()->id(),
            'status' => OrderStatus::DRAFT,
            'courier_code' => $validated['courier_code'] ?? 'MANUAL',
            'receiver_name' => $validated['customer_name'] ?? '',
            'receiver_phone' => $validated['phone'] ?? '',
            'receiver_address' => $validated['complete_address'] ?? '',
            'total_amount' => 0,
            'cod_amount' => 0,
            'source_channel' => 'manual_shop',
            'notes' => $validated['remarks'] ?? null,
            'draft_data' => $draftData,
        ]);

        return response()->json(['success' => true, 'draft_id' => $order->id]);
    }

    public function loadDraft(Request $request, Order $order): JsonResponse
    {
        if ($order->status !== OrderStatus::DRAFT) {
            abort(404);
        }

        return response()->json([
            'success' => true,
            'draft' => $order->draft_data ?? [],
        ]);
    }

    public function deleteDraft(Request $request, Order $order): JsonResponse
    {
        if ($order->status !== OrderStatus::DRAFT) {
            abort(404);
        }

        $order->delete();

        return response()->json(['success' => true]);
    }

    public function storeOrder(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'customer_name' => ['required', 'string', 'max:255'],
            'phone' => ['required', 'string', 'max:30'],
            'customer_id' => ['nullable', 'integer', 'exists:customers,id'],
            'update_customer_phone' => ['nullable', 'boolean'],
            'complete_address' => ['required', 'string', 'max:2000'],
            'landmark' => ['nullable', 'string', 'max:255'],
            'barangay' => ['nullable', 'string', 'max:255'],
            'city_municipality' => ['nullable', 'string', 'max:255'],
            'province' => ['nullable', 'string', 'max:255'],
            'items' => ['required', 'array', 'min:1', 'max:20'],
            'items.*.product_id' => ['required', 'exists:products,id'],
            'items.*.variant_id' => ['nullable', 'exists:product_variants,id'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:999'],
            'items.*.unit_price' => ['required', 'numeric', 'min:0', 'max:999999.99'],
            'items.*.discount_amount' => ['nullable', 'numeric', 'min:0', 'max:999999.99'],
            'shipping_fee' => ['nullable', 'numeric', 'min:0', 'max:999999.99'],
            'discount_amount' => ['nullable', 'numeric', 'min:0', 'max:999999.99'],
            'tax_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'tax_amount' => ['nullable', 'numeric', 'min:0', 'max:999999.99'],
            'courier_code' => ['nullable', 'string', 'max:30'],
            'remarks' => ['nullable', 'string', 'max:2000'],
            'conversation_id' => ['nullable', 'integer', 'exists:conversations,id'],
            'cod_amount' => ['nullable', 'numeric', 'min:0', 'max:9999999.99'],
            'send_confirmation' => ['nullable', 'boolean'],
        ]);

        $products = Product::query()
            ->with('variants:id,product_id,sku,variant_name,selling_price')
            ->whereIn('id', collect($validated['items'])->pluck('product_id')->all())
            ->get()
            ->keyBy('id');

        $preparedItems = collect($validated['items'])->map(function (array $item) use ($products) {
            /** @var Product $product */
            $product = $products->get((int) $item['product_id']);
            abort_unless($product, 422, 'Selected product was not found.');

            $variant = null;

            if (! empty($item['variant_id'])) {
                $variant = $product->variants->firstWhere('id', (int) $item['variant_id']);
                abort_unless($variant, 422, 'Selected variant does not belong to the product.');
            }

            $quantity = (int) $item['quantity'];
            $unitPrice = (float) $item['unit_price'];
            $lineDiscount = (float) ($item['discount_amount'] ?? 0);
            $lineTotal = max(0, ($quantity * $unitPrice) - $lineDiscount);

            return [
                'product' => $product,
                'variant' => $variant,
                'quantity' => $quantity,
                'unit_price' => $unitPrice,
                'discount_amount' => $lineDiscount,
                'line_total' => $lineTotal,
                'display_name' => $variant ? "{$product->name} - {$variant->variant_name}" : $product->name,
                'sku' => $variant?->sku ?? $product->sku,
            ];
        })->values();

        $primaryItem = $preparedItems->first();
        abort_unless($primaryItem, 422, 'At least one cart item is required.');
        $shippingFee = (float) ($validated['shipping_fee'] ?? 0);
        $orderDiscount = (float) ($validated['discount_amount'] ?? 0);
        $taxRate = (float) ($validated['tax_rate'] ?? 0);
        $taxableAmount = max(0, (float) $preparedItems->sum('line_total') - $orderDiscount);
        $taxAmount = $taxRate > 0 ? round($taxableAmount * $taxRate / 100, 2) : (float) ($validated['tax_amount'] ?? 0);
        $totalQuantity = (int) $preparedItems->sum('quantity');
        $totalAmount = max(0, $taxableAmount + $shippingFee + $taxAmount);
        $normalizedPhone = $this->phones->normalize($validated['phone']);
        $possibleDuplicates = $this->possibleDuplicateOrders(
            $normalizedPhone ?: $validated['phone'],
            $preparedItems->pluck('product.id')->map(fn ($id) => (int) $id)->all()
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
            $orderDiscount,
            $taxRate,
            $taxAmount,
            $totalQuantity,
            $totalAmount,
            $normalizedPhone,
            $possibleDuplicates,
            $addressMatch
        ) {
            $conversation = ! empty($validated['conversation_id'])
                ? Conversation::query()->find($validated['conversation_id'])
                : null;

            $customer = $this->resolveCustomerForOrder($validated, $addressMatch['mapping']?->region);

            $order = Order::query()->create([
                'order_number' => Order::generateOrderNumber(),
                'conversation_id' => $conversation?->id,
                'facebook_page_id' => $conversation?->facebook_page_id,
                'customer_id' => $customer->id,
                'product_id' => $primaryItem['product']->id,
                'variant_id' => $primaryItem['variant']?->id,
                'assigned_agent_id' => auth()->id(),
                'status' => OrderStatus::CONFIRMED,
                'courier_code' => $validated['courier_code'] ?? 'MANUAL',
                'quantity' => $totalQuantity,
                'unit_price' => $primaryItem['unit_price'],
                'total_amount' => $totalAmount,
                'cod_amount' => isset($validated['cod_amount']) && $validated['cod_amount'] !== ''
                    ? (float) $validated['cod_amount']
                    : $totalAmount,
                'shipping_cost' => $shippingFee,
                'discount_amount' => $orderDiscount,
                'tax_rate' => $taxRate,
                'tax_amount' => $taxAmount,
                'receiver_name' => $validated['customer_name'],
                'receiver_phone' => $normalizedPhone ?: $validated['phone'],
                'receiver_address' => $validated['complete_address'],
                'city' => $validated['city_municipality'] ?? null,
                'state' => $validated['province'] ?? null,
                'barangay' => $validated['barangay'] ?? null,
                'address_mapping_id' => $addressMatch['mapping']?->id,
                'source_channel' => $conversation ? 'facebook_shop' : 'manual_shop',
                'address_confidence' => $addressMatch['confidence'],
                'export_status' => 'pending',
                'confirmed_at' => now(),
                'notes' => $validated['remarks'] ?? null,
                'remarks' => $validated['remarks'] ?? null,
            ]);

            $this->customerAddresses->record($customer, [
                'label' => $conversation?->facebookPage?->page_name ? "Order from {$conversation->facebookPage->page_name}" : 'Order',
                'canonical_address' => $validated['complete_address'],
                'landmark' => $validated['landmark'] ?? null,
                'barangay' => $validated['barangay'] ?? null,
                'city_municipality' => $validated['city_municipality'] ?? null,
                'province' => $validated['province'] ?? null,
                'region' => $addressMatch['mapping']?->region,
                'contact_name' => $validated['customer_name'],
                'contact_phone' => $validated['phone'],
            ], false, 'order');

            foreach ($preparedItems as $item) {
                ShopOrderItem::query()->create([
                    'order_id' => $order->id,
                    'product_id' => $item['product']->id,
                    'variant_id' => $item['variant']?->id,
                    'sku' => $item['sku'],
                    'product_name' => $item['display_name'],
                    'quantity' => $item['quantity'],
                    'unit_price' => $item['unit_price'],
                    'discount_amount' => $item['discount_amount'],
                    'line_total' => $item['line_total'],
                ]);
            }

            if (! empty($validated['remarks'])) {
                OrderRemark::query()->create([
                    'order_id' => $order->id,
                    'user_id' => auth()->id(),
                    'type' => 'agent_note',
                    'visibility' => 'internal',
                    'body' => $validated['remarks'],
                ]);
            }

            foreach ($possibleDuplicates as $possibleDuplicate) {
                OrderRemark::query()->create([
                    'order_id' => $order->id,
                    'user_id' => auth()->id(),
                    'type' => 'duplicate_warning',
                    'visibility' => 'internal',
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
                    'visibility' => 'internal',
                    'body' => "Created from Shop conversation #{$conversation->id}.",
                    'metadata' => [
                        'conversation_id' => $conversation->id,
                        'facebook_page_id' => $conversation->facebook_page_id,
                        'customer_identity_id' => $conversation->customer_identity_id,
                    ],
                ]);

                $conversation->forceFill([
                    'customer_id' => $customer->id,
                    'status' => 'resolved',
                    'metadata' => array_merge($conversation->metadata ?? [], [
                        'latest_order_id' => $order->id,
                        'converted_at' => now()->toIso8601String(),
                    ]),
                ])->save();
            }

            return $order;
        });

        $confirmationSent = false;
        $confirmationError = null;

        $autoConfirm = SiteSetting::get('shop_auto_order_confirmation', '1') === '1';
        $shouldSendConfirmation = ($autoConfirm || ! empty($validated['send_confirmation'])) && $conversation;

        if ($shouldSendConfirmation) {
            $conversation->load(['facebookPage', 'identity']);

            $itemList = $preparedItems->map(fn ($item) =>
                "• {$item['display_name']} ×{$item['quantity']} — ₱" . number_format($item['line_total'], 2)
            )->implode("\n");

            $courierLabel = match($validated['courier_code'] ?? 'MANUAL') {
                'JNT' => 'J&T Express',
                'FLASH' => 'Flash Express',
                default => 'Manual',
            };

            $confirmationBody = "✅ Order Confirmed!\n\n"
                . "Order #: {$order->order_number}\n"
                . "Items:\n{$itemList}\n\n"
                . "Total COD: ₱" . number_format((float) $order->cod_amount, 2) . "\n"
                . "Courier: {$courierLabel}\n\n"
                . "Thank you for your order! We'll notify you once it's shipped.";

            $delivery = ['status' => 'logged'];

            if ($conversation->facebookPage?->page_access_token && $conversation->identity?->provider_user_id) {
                try {
                    $delivery = $this->facebookConnector->sendMessage(
                        $conversation->facebookPage,
                        $conversation->identity->provider_user_id,
                        $confirmationBody
                    );
                    $delivery['status'] = 'sent';
                    $confirmationSent = true;
                } catch (\Throwable $exception) {
                    $delivery = [
                        'status' => 'failed',
                        'error' => $exception->getMessage(),
                    ];
                    $confirmationError = $exception->getMessage();
                }
            }

            Message::query()->create([
                'conversation_id' => $conversation->id,
                'facebook_page_id' => $conversation->facebook_page_id,
                'customer_identity_id' => $conversation->customer_identity_id,
                'sent_by' => auth()->id(),
                'external_message_id' => 'local-' . str()->uuid(),
                'direction' => 'outbound',
                'message_type' => 'text',
                'body' => $confirmationBody,
                'metadata' => ['order_confirmation' => true, 'order_id' => $order->id],
                'raw_payload' => $delivery,
                'sent_at' => now(),
                'send_status' => $delivery['status'],
                'send_error' => $delivery['error'] ?? null,
                'retry_count' => 0,
            ]);

            $conversation->forceFill([
                'last_message_preview' => $confirmationBody,
                'last_message_at' => now(),
            ])->save();
        }

        $message = "Shop order {$order->order_number} created.";
        if ($confirmationSent) {
            $message .= ' Confirmation sent to customer.';
        } elseif ($confirmationError) {
            $message .= ' Confirmation message saved but delivery failed.';
        }

        return redirect()
            ->route('orders.show', $order)
            ->with(
                $possibleDuplicates->isNotEmpty() ? 'warning' : 'success',
                $possibleDuplicates->isNotEmpty()
                    ? "{$message} Possible duplicates found: {$possibleDuplicates->pluck('order_number')->implode(', ')}."
                    : $message
            );
    }

    private function stats(): array
    {
        return [
            'connected_pages' => $this->countWhenReady('facebook_pages', fn () => DB::table('facebook_pages')
                ->where('connected_status', 'connected')
                ->count()),
            'open_conversations' => $this->countWhenReady('conversations', fn () => DB::table('conversations')
                ->where('status', Conversation::STATUS_NEW)
                ->count()),
            'orders_today' => $this->countWhenReady('orders', fn () => DB::table('orders')
                ->whereDate('created_at', today())
                ->count()),
            'for_encoding' => $this->forEncodingCount(),
        ];
    }

    private function workQueues(): array
    {
        return [
            'inbox' => $this->countWhenReady('conversations', fn () => DB::table('conversations')
                ->whereIn('status', Conversation::ACTIVE_STATUSES)
                ->count()),
            'phone_detected' => $this->countWhenReady('customer_identities', fn () => DB::table('customer_identities')
                ->whereNotNull('phone_detected')
                ->count()),
            'ready_orders' => $this->forEncodingCount(),
            'courier_export' => $this->countWhenReady('courier_export_batches', fn () => DB::table('courier_export_batches')
                ->whereDate('created_at', today())
                ->count()),
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

    private function shopOrderQuery(): \Illuminate\Database\Query\Builder
    {
        if (! Schema::hasTable('orders')) {
            return DB::table('orders')->whereRaw('1 = 0');
        }

        $query = DB::table('orders')->whereIn('source_channel', ['manual_shop', 'facebook_shop']);

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
                    ->where('conversations.status', 'resolved');

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
                    ->whereIn('orders.source_channel', ['manual_shop', 'facebook_shop']);

                $this->applyReportOrderFilters($query, array_merge($filters, ['page_id' => null]));
            }, 'orders_count')
            ->selectSub(function ($query) use ($filters) {
                $query->from('orders')
                    ->selectRaw('COALESCE(SUM(orders.total_amount), 0)')
                    ->whereColumn('orders.facebook_page_id', 'facebook_pages.id')
                    ->whereIn('orders.source_channel', ['manual_shop', 'facebook_shop']);

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
                    ->where('conversations.status', 'resolved');

                $this->applyReportConversationFilters($query, array_merge($filters, ['agent_id' => null]));
            }, 'converted_conversations')
            ->selectSub(function ($query) use ($filters) {
                $query->from('orders')
                    ->selectRaw('COUNT(*)')
                    ->whereColumn('orders.assigned_agent_id', 'users.id')
                    ->whereIn('orders.source_channel', ['manual_shop', 'facebook_shop']);

                $this->applyReportOrderFilters($query, array_merge($filters, ['agent_id' => null]));
            }, 'orders_count')
            ->selectSub(function ($query) use ($filters) {
                $query->from('orders')
                    ->selectRaw('COALESCE(SUM(orders.total_amount), 0)')
                    ->whereColumn('orders.assigned_agent_id', 'users.id')
                    ->whereIn('orders.source_channel', ['manual_shop', 'facebook_shop']);

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
            ->whereIn('orders.source_channel', ['manual_shop', 'facebook_shop']);

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

    private function resolveCustomerForOrder(array $validated, ?string $region = null): Customer
    {
        if (($validated['update_customer_phone'] ?? false) && ! empty($validated['customer_id'])) {
            $customer = Customer::query()->findOrFail($validated['customer_id']);
            $matchedCustomer = $this->customerIdentities->findByPhone($validated['phone']);

            abort_if(
                $matchedCustomer && $matchedCustomer->id !== $customer->id,
                422,
                'This phone number is already linked to another customer.'
            );

            $customer->forceFill([
                'phone' => $validated['phone'],
                'normalized_phone' => $this->phones->normalize($validated['phone']),
            ])->save();
        }

        return $this->customerIdentities->firstOrCreateFromPhone([
            'name' => $validated['customer_name'],
            'phone' => $validated['phone'],
            'address' => $validated['complete_address'],
            'landmark' => $validated['landmark'] ?? null,
            'barangay' => $validated['barangay'] ?? null,
            'city_municipality' => $validated['city_municipality'] ?? null,
            'province' => $validated['province'] ?? null,
            'region' => $region,
        ]);
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
            ->whereIn('source_channel', ['manual_shop', 'facebook_shop'])
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
            ->where(function ($q) use ($productIds) {
                $q->whereIn('product_id', $productIds)
                    ->orWhereHas('shopItems', function ($sq) use ($productIds) {
                        $sq->whereIn('product_id', $productIds);
                    });
            })
            ->whereIn('source_channel', ['manual_shop', 'facebook_shop'])
            ->where('created_at', '>=', now()->subDays(14))
            ->where('status', '!=', OrderStatus::DRAFT)
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

        $pageId = $conversation->facebook_page_id;

        return ShopReplyTemplate::query()
            ->where('is_active', true)
            ->where(function ($q) use ($pageId) {
                $q->where('facebook_page_id', $pageId)->orWhereNull('facebook_page_id');
            })
            ->orderByRaw("CASE WHEN facebook_page_id = ? THEN 0 ELSE 1 END", [$pageId])
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get(['id', 'name', 'message', 'category', 'variables', 'facebook_page_id'])
            ->map(fn (ShopReplyTemplate $template) => [
                'id' => $template->id,
                'name' => $template->name,
                'category' => $template->category,
                'body' => $this->renderReplyTemplate($template->message, $conversation),
                'variables' => $template->variables ?? [],
                'is_page_specific' => $template->facebook_page_id !== null,
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
        $activeStatuses = Conversation::ACTIVE_STATUSES;
        $thirtyDaysAgo = now()->subDays(30);

        return User::query()
            ->where('is_active', true)
            ->whereIn('role', ['agent', 'supervisor', 'admin', 'superadmin'])
            ->with('agentProfile:id,user_id,is_available,last_seen_at,auto_assign_enabled,product_skills,regions,category_skills,performance_score,max_active_conversations,overflow_enabled,shift_start,shift_end,idle_threshold_minutes')
            ->withCount([
                'conversations as active_conversations' => fn ($q) => $q->whereIn('status', $activeStatuses)->whereNull('merged_into_id'),
                'conversations as total_assigned_30d' => fn ($q) => $q->whereNull('merged_into_id')->where('created_at', '>=', $thirtyDaysAgo),
                'conversations as resolved_30d' => fn ($q) => $q->whereNull('merged_into_id')->where('created_at', '>=', $thirtyDaysAgo)->whereNotNull('resolved_at'),
            ])
            ->withAvg([
                'conversations as avg_response_seconds_30d' => fn ($q) => $q->whereNull('merged_into_id')->where('created_at', '>=', $thirtyDaysAgo)->whereNotNull('first_response_time_seconds'),
            ], 'first_response_time_seconds')
            ->orderBy('name')
            ->get(['id', 'name', 'role'])
            ->map(fn (User $user) => [
                'id' => $user->id,
                'name' => $user->name,
                'role' => $user->role,
                'status' => $user->agentStatus(),
                'active_conversations' => $user->active_conversations,
                'auto_assign_enabled' => $user->agentProfile?->auto_assign_enabled ?? false,
                'product_skills' => $user->agentProfile?->product_skills ?? [],
                'regions' => $user->agentProfile?->regions ?? [],
                'category_skills' => $user->agentProfile?->category_skills ?? [],
                'performance_score' => (float) ($user->agentProfile?->performance_score ?? 50),
                'total_assigned_30d' => $user->total_assigned_30d,
                'resolved_30d' => $user->resolved_30d,
                'resolution_rate' => $user->total_assigned_30d > 0
                    ? round(($user->resolved_30d / $user->total_assigned_30d) * 100, 1)
                    : 0,
                'avg_response_seconds_30d' => $user->avg_response_seconds_30d
                    ? (int) $user->avg_response_seconds_30d
                    : null,
                'max_active_conversations' => $user->agentProfile?->max_active_conversations ?? 15,
                'overflow_enabled' => $user->agentProfile?->overflow_enabled ?? true,
                'shift_start' => $user->agentProfile?->shift_start,
                'shift_end' => $user->agentProfile?->shift_end,
                'idle_threshold_minutes' => $user->agentProfile?->idle_threshold_minutes ?? 15,
                'is_idle' => $this->isAgentIdle($user),
            ]);
    }

    /**
     * @return array<int, string>
     */
    private function conversationStatuses(): array
    {
        return Conversation::STATUSES;
    }

    /**
     * @param  array<string, int>  $statusCounts
     * @return array<int, array{status: string, count: int, percentage: float, avg_time_minutes: int|null}>
     */
    private function statusFunnel(array $statusCounts): array
    {
        $statuses = Conversation::STATUSES;
        $total = array_sum($statusCounts) ?: 1;

        // Average time spent in each status (in minutes) from status history
        $avgTimes = [];
        if (Schema::hasTable('conversation_status_histories')) {
            $histories = ConversationStatusHistory::query()
                ->select('conversation_id', 'to_status', 'created_at')
                ->orderBy('conversation_id')
                ->orderBy('created_at')
                ->get();

            $durationsByStatus = [];
            $prev = null;
            foreach ($histories as $h) {
                if ($prev && $prev->conversation_id === $h->conversation_id && $prev->to_status) {
                    $minutes = (int) round($h->created_at->diffInMinutes($prev->created_at));
                    if ($minutes >= 0 && $minutes < 525600) {
                        $durationsByStatus[$prev->to_status][] = $minutes;
                    }
                }
                $prev = $h;
            }

            foreach ($statuses as $status) {
                if (!empty($durationsByStatus[$status])) {
                    $avgTimes[$status] = (int) round(array_sum($durationsByStatus[$status]) / count($durationsByStatus[$status]));
                }
            }
        }

        $funnel = [];
        foreach ($statuses as $status) {
            $count = $statusCounts[$status] ?? 0;
            $funnel[] = [
                'status' => $status,
                'count' => $count,
                'percentage' => round(($count / $total) * 100, 1),
                'avg_time_minutes' => $avgTimes[$status] ?? null,
            ];
        }

        return $funnel;
    }

    private function computeSla(Conversation $conversation, array $thresholds): array
    {
        $threshold = $thresholds[$conversation->status] ?? null;

        if ($threshold === null) {
            return [
                'elapsed_minutes' => null,
                'threshold_minutes' => null,
                'remaining_minutes' => null,
                'status' => 'none',
            ];
        }

        $startedAt = $conversation->updated_at;

        if (Schema::hasTable('conversation_status_histories')) {
            $latest = $conversation->statusHistories()->latest('id')->first();
            if ($latest) {
                $startedAt = $latest->created_at;
            }
        }

        $elapsedMinutes = $startedAt ? (int) now()->diffInMinutes($startedAt) : 0;
        $remainingMinutes = $threshold - $elapsedMinutes;
        $warningAt = (int) ($threshold * Conversation::SLA_WARNING_PERCENT / 100);

        $slaStatus = 'ok';
        if ($elapsedMinutes >= $threshold) {
            $slaStatus = 'breached';
        } elseif ($elapsedMinutes >= $warningAt) {
            $slaStatus = 'warning';
        }

        return [
            'elapsed_minutes' => $elapsedMinutes,
            'threshold_minutes' => $threshold,
            'remaining_minutes' => max(0, $remainingMinutes),
            'status' => $slaStatus,
        ];
    }

    private function isAgentIdle(User $user): bool
    {
        $profile = $user->agentProfile;

        if (! $profile || ! $profile->is_available) {
            return false;
        }

        if ($user->active_conversations < 1) {
            return false;
        }

        $threshold = $profile->idle_threshold_minutes ?? 15;

        if (! $profile->last_seen_at) {
            return true;
        }

        return $profile->last_seen_at->lt(now()->subMinutes($threshold));
    }

    /**
     * @return array{
     *   total_active: int,
     *   total_agents: int,
     *   avg_per_agent: float,
     *   max_assigned: int,
     *   min_assigned: int,
     *   imbalance_ratio: float,
     *   status: string,
     *   recommendations: array<int, array{agent_id: int, agent_name: string, active: int, max: int, suggestion: string}>,
     *   distribution: array<int, array{agent_id: int, agent_name: string, active: int, max: int, utilization: float}>
     * }
     */
    private function workloadReport(): array
    {
        $agents = $this->shopAgents()
            ->filter(fn ($a) => $a['role'] === 'agent' || $a['role'] === 'supervisor');

        $totalActive = $agents->sum('active_conversations');
        $totalAgents = $agents->count();

        if ($totalAgents === 0) {
            return [
                'total_active' => 0,
                'total_agents' => 0,
                'avg_per_agent' => 0,
                'max_assigned' => 0,
                'min_assigned' => 0,
                'imbalance_ratio' => 0,
                'status' => 'no_agents',
                'recommendations' => [],
                'distribution' => [],
            ];
        }

        $avgPerAgent = round($totalActive / $totalAgents, 1);
        $maxAssigned = $agents->max('active_conversations');
        $minAssigned = $agents->min('active_conversations');

        $imbalanceRatio = $avgPerAgent > 0
            ? round(($maxAssigned - $minAssigned) / $avgPerAgent, 2)
            : 0;

        $status = match (true) {
            $imbalanceRatio <= 0.3 => 'balanced',
            $imbalanceRatio <= 0.7 => 'slightly_imbalanced',
            default => 'imbalanced',
        };

        $distribution = $agents
            ->sortByDesc('active_conversations')
            ->map(fn ($a) => [
                'agent_id' => $a['id'],
                'agent_name' => $a['name'],
                'active' => $a['active_conversations'],
                'max' => $a['max_active_conversations'],
                'utilization' => $a['max_active_conversations'] > 0
                    ? round(($a['active_conversations'] / $a['max_active_conversations']) * 100, 1)
                    : 0,
            ])
            ->values()
            ->all();

        $recommendations = [];

        foreach ($agents as $a) {
            $utilization = $a['max_active_conversations'] > 0
                ? ($a['active_conversations'] / $a['max_active_conversations']) * 100
                : 0;

            if ($utilization >= 100 && $a['overflow_enabled']) {
                $overloaded = $agents->firstWhere('active_conversations', $minAssigned);
                if ($overloaded && $overloaded['id'] !== $a['id'] && $overloaded['active_conversations'] < $a['active_conversations'] - 3) {
                    $recommendations[] = [
                        'agent_id' => $a['id'],
                        'agent_name' => $a['name'],
                        'active' => $a['active_conversations'],
                        'max' => $a['max_active_conversations'],
                        'suggestion' => "Reassign 2-3 conversations from {$a['name']} to {$overloaded['name']} (currently {$overloaded['active_conversations']} active).",
                    ];
                }
            } elseif ($utilization >= 80 && ! $a['overflow_enabled']) {
                $recommendations[] = [
                    'agent_id' => $a['id'],
                    'agent_name' => $a['name'],
                    'active' => $a['active_conversations'],
                    'max' => $a['max_active_conversations'],
                    'suggestion' => "{$a['name']} is at " . round($utilization) . "% capacity with overflow disabled. Consider enabling overflow or reassigning.",
                ];
            }
        }

        return [
            'total_active' => $totalActive,
            'total_agents' => $totalAgents,
            'avg_per_agent' => $avgPerAgent,
            'max_assigned' => $maxAssigned,
            'min_assigned' => $minAssigned,
            'imbalance_ratio' => $imbalanceRatio,
            'status' => $status,
            'recommendations' => $recommendations,
            'distribution' => $distribution,
        ];
    }

    public function toggleBlock(Request $request, Conversation $conversation): RedirectResponse
    {
        $validated = $request->validate([
            'block' => ['required', 'boolean'],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        $customer = $conversation->customer;

        if (! $customer) {
            return back()->with('error', 'No customer linked to this conversation.');
        }

        if ($validated['block']) {
            $customer->update([
                'is_blacklisted' => true,
                'blacklist_reason' => $validated['reason'] ?? 'Blocked from conversation',
                'blacklisted_at' => now(),
                'risk_level' => 'BLACKLISTED',
            ]);
            $message = 'Customer blocked successfully.';
        } else {
            $customer->update([
                'is_blacklisted' => false,
                'blacklist_reason' => null,
                'blacklisted_at' => null,
            ]);
            $message = 'Customer unblocked successfully.';
        }

        return back()->with('success', $message);
    }

    public function storeRemarkTemplate(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'body' => ['required', 'string', 'max:2000'],
            'type' => ['nullable', 'string', 'in:agent_note,follow_up,escalation,customer_feedback'],
            'visibility' => ['nullable', 'string', 'in:internal,customer_visible'],
        ]);

        RemarkTemplate::query()->create([
            'name' => $validated['name'],
            'body' => $validated['body'],
            'type' => $validated['type'] ?? 'agent_note',
            'visibility' => $validated['visibility'] ?? 'internal',
            'created_by' => $request->user()->id,
        ]);

        return back()->with('success', 'Remark template created.');
    }

    public function destroyRemarkTemplate(Request $request, RemarkTemplate $remarkTemplate): RedirectResponse
    {
        $remarkTemplate->delete();

        return back()->with('success', 'Remark template deleted.');
    }

    public function storeOrderRemark(Request $request, Order $order): RedirectResponse
    {
        $validated = $request->validate([
            'body' => ['required', 'string', 'max:2000'],
            'type' => ['nullable', 'string', 'in:agent_note,follow_up,escalation,customer_feedback'],
            'visibility' => ['nullable', 'string', 'in:internal,customer_visible'],
            'parent_id' => ['nullable', 'integer', 'exists:order_remarks,id'],
            'mentions' => ['nullable', 'array'],
            'mentions.*' => ['integer', 'exists:users,id'],
            'tags' => ['nullable', 'array'],
            'tags.*' => ['string', 'max:50'],
        ]);

        if (! empty($validated['parent_id'])) {
            $parent = OrderRemark::query()
                ->where('id', $validated['parent_id'])
                ->where('order_id', $order->id)
                ->first();

            if (! $parent) {
                return back()->with('error', 'Parent remark not found for this order.');
            }
        }

        $mentions = ! empty($validated['mentions'])
            ? User::query()
                ->whereIn('id', $validated['mentions'])
                ->whereIn('role', ['supervisor', 'admin', 'superadmin'])
                ->pluck('id')
                ->all()
            : [];

        $remark = OrderRemark::query()->create([
            'order_id' => $order->id,
            'parent_id' => $validated['parent_id'] ?? null,
            'user_id' => $request->user()->id,
            'type' => $validated['type'] ?? 'agent_note',
            'visibility' => $validated['visibility'] ?? 'internal',
            'body' => $validated['body'],
            'mentions' => $mentions,
            'tags' => ! empty($validated['tags']) ? array_values(array_unique(array_filter($validated['tags']))) : null,
        ]);

        if (! empty($mentions)) {
            $mentioner = $request->user()->name;
            $mentionUsers = User::query()->whereIn('id', $mentions)->get();
            \Illuminate\Support\Facades\Notification::send(
                $mentionUsers,
                new RemarkMentionedNotification($remark->fresh(['order']), $mentioner),
            );
        }

        return back()->with('success', 'Remark added.');
    }

    public function storeConversationRemark(Request $request, Conversation $conversation): RedirectResponse
    {
        $validated = $request->validate([
            'body' => ['required', 'string', 'max:2000'],
        ]);

        OrderRemark::query()->create([
            'order_id' => 0,
            'conversation_id' => $conversation->id,
            'user_id' => $request->user()->id,
            'type' => 'conversation_note',
            'body' => $validated['body'],
        ]);

        return back()->with('success', 'Remark added.');
    }

    public function updateOrderRemark(Request $request, Order $order, OrderRemark $remark): RedirectResponse
    {
        if ($remark->order_id !== $order->id) {
            return back()->with('error', 'Remark does not belong to this order.');
        }

        if (! in_array($remark->type, ['agent_note', 'follow_up', 'escalation', 'customer_feedback'])) {
            return back()->with('error', 'System remarks cannot be edited.');
        }

        if ($remark->user_id !== $request->user()->id) {
            return back()->with('error', 'You can only edit your own remarks.');
        }

        if ($remark->created_at->diffInHours(now()) > 24) {
            return back()->with('error', 'Remarks can only be edited within 24 hours of creation.');
        }

        $validated = $request->validate([
            'body' => ['required', 'string', 'max:2000'],
            'type' => ['nullable', 'string', 'in:agent_note,follow_up,escalation,customer_feedback'],
            'visibility' => ['nullable', 'string', 'in:internal,customer_visible'],
            'mentions' => ['nullable', 'array'],
            'mentions.*' => ['integer', 'exists:users,id'],
            'tags' => ['nullable', 'array'],
            'tags.*' => ['string', 'max:50'],
        ]);
        $mentions = ! empty($validated['mentions'])
            ? User::query()
                ->whereIn('id', $validated['mentions'])
                ->whereIn('role', ['supervisor', 'admin', 'superadmin'])
                ->pluck('id')
                ->all()
            : [];

        $previousMentions = $remark->mentions ?? [];
        $newMentions = array_values(array_diff($mentions, $previousMentions));

        $remark->forceFill([
            'body' => $validated['body'],
            'type' => $validated['type'] ?? $remark->type,
            'visibility' => $validated['visibility'] ?? $remark->visibility,
            'mentions' => $mentions,
            'tags' => ! empty($validated['tags']) ? array_values(array_unique(array_filter($validated['tags']))) : null,
        ])->save();

        if (! empty($newMentions)) {
            $mentioner = $request->user()->name;
            $mentionUsers = User::query()->whereIn('id', $newMentions)->get();
            \Illuminate\Support\Facades\Notification::send(
                $mentionUsers,
                new RemarkMentionedNotification($remark->fresh(['order']), $mentioner),
            );
        }

        return back()->with('success', 'Remark updated.');
    }

    public function destroyOrderRemark(Request $request, Order $order, OrderRemark $remark): RedirectResponse
    {
        if ($remark->order_id !== $order->id) {
            return back()->with('error', 'Remark does not belong to this order.');
        }

        if (! in_array($remark->type, ['agent_note', 'follow_up', 'escalation', 'customer_feedback'])) {
            return back()->with('error', 'System remarks cannot be deleted.');
        }

        if ($remark->user_id !== $request->user()->id) {
            return back()->with('error', 'You can only delete your own remarks.');
        }

        if ($remark->created_at->diffInHours(now()) > 24) {
            return back()->with('error', 'Remarks can only be deleted within 24 hours of creation.');
        }

        $remark->delete();

        return back()->with('success', 'Remark deleted.');
    }

    public function togglePinOrderRemark(Request $request, Order $order, OrderRemark $remark): RedirectResponse
    {
        if ($remark->order_id !== $order->id) {
            return back()->with('error', 'Remark does not belong to this order.');
        }

        if (! in_array($request->user()->role, ['supervisor', 'admin', 'superadmin'])) {
            return back()->with('error', 'Only supervisors can pin remarks.');
        }

        if ($remark->is_pinned) {
            $remark->forceFill([
                'is_pinned' => false,
                'pinned_at' => null,
                'pinned_by' => null,
            ])->save();

            return back()->with('success', 'Remark unpinned.');
        }

        $remark->forceFill([
            'is_pinned' => true,
            'pinned_at' => now(),
            'pinned_by' => $request->user()->id,
        ])->save();

        return back()->with('success', 'Remark pinned.');
    }

    public function exportOrderRemarks(Request $request): BinaryFileResponse
    {
        $filters = $request->only([
            'order_id',
            'remark_q',
            'remark_type',
            'remark_author',
            'remark_tag',
        ]);

        $format = $request->query('format', 'xlsx');
        $filename = 'order_remarks_' . now()->format('Ymd_His');

        $export = new OrderRemarksExport($filters);

        if ($format === 'csv') {
            return \Maatwebsite\Excel\Facades\Excel::download(
                $export,
                "{$filename}.csv",
                \Maatwebsite\Excel\Excel::CSV,
                ['Content-Type' => 'text/csv; charset=UTF-8'],
            );
        }

        return \Maatwebsite\Excel\Facades\Excel::download($export, "{$filename}.xlsx");
    }

    public function deleteConversationRemark(Request $request, OrderRemark $remark): RedirectResponse
    {
        if ($remark->type !== 'conversation_note') {
            return back()->with('error', 'Only conversation notes can be deleted here.');
        }

        $remark->delete();

        return back()->with('success', 'Remark deleted.');
    }

}
