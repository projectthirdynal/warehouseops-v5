<?php

declare(strict_types=1);

namespace Modules\Orders\Services;

use App\Models\AutoMergeSuggestion;
use App\Models\Customer;
use App\Models\DuplicateAuditLog;
use App\Models\DuplicateDetectionRule;
use App\Models\DuplicateFamily;
use App\Models\DuplicateFamilyMember;
use App\Models\DuplicateMlModel;
use App\Models\DuplicateNotification;
use App\Models\DuplicateReviewItem;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Modules\Orders\Enums\OrderStatus;
use Modules\Orders\Models\Order;
use Modules\Shop\Models\Conversation;
use Modules\Shop\Models\CustomerIdentity;
use Modules\Shop\Models\FacebookPage;
use Modules\Shop\Models\ShopOrderItem;
use Modules\Shop\Services\CustomerMergeService;
use Modules\Shop\Services\PhoneDetectionService;

class DuplicateDetectionService
{
    private int $defaultTimeWindowHours = 72;

    private array $excludedStatuses = [
        OrderStatus::DRAFT,
        OrderStatus::CANCELLED,
    ];

    public function __construct(
        private readonly PhoneDetectionService $phones,
    ) {}

    /**
     * Detect duplicate orders by customer phone + product within a time window.
     *
     * @param  string  $phone  Receiver phone (raw or normalized)
     * @param  array<int>  $productIds  Product IDs to check against
     * @param  int|null  $timeWindowHours  Override the default time window
     * @param  int|null  $excludeOrderId  Order ID to exclude (e.g., the order being edited)
     * @return array<string, mixed>
     */
    public function detectDuplicateOrders(
        string $phone,
        array $productIds,
        ?int $timeWindowHours = null,
        ?int $excludeOrderId = null,
    ): array {
        $normalizedPhone = $this->phones->normalize($phone);
        $windowHours = $timeWindowHours ?? $this->defaultTimeWindowHours;
        $cutoff = Carbon::now()->subHours($windowHours);

        if (empty($productIds)) {
            return $this->emptyResult($normalizedPhone, $windowHours);
        }

        $query = Order::query()
            ->with(['product:id,name,sku', 'shopItems:id,order_id,product_id,product_name,quantity,line_total'])
            ->where('receiver_phone', $normalizedPhone)
            ->where('created_at', '>=', $cutoff)
            ->whereNotIn('status', array_map(fn ($s) => $s->value, $this->excludedStatuses));

        if ($excludeOrderId) {
            $query->where('id', '!=', $excludeOrderId);
        }

        $orders = $query->get();

        $duplicates = $orders->filter(function (Order $order) use ($productIds) {
            if (in_array($order->product_id, $productIds, true)) {
                return true;
            }

            return $order->shopItems->contains(fn (ShopOrderItem $item) => in_array($item->product_id, $productIds, true));
        });

        $duplicateWarnings = $duplicates->map(function (Order $order) {
            $matchedProducts = $order->shopItems
                ->filter(fn (ShopOrderItem $item) => $item->product_id !== null)
                ->map(fn (ShopOrderItem $item) => [
                    'product_id' => $item->product_id,
                    'product_name' => $item->product_name,
                    'quantity' => $item->quantity,
                ])
                ->values();

            if ($order->product_id && $matchedProducts->isEmpty()) {
                $matchedProducts = collect([[
                    'product_id' => $order->product_id,
                    'product_name' => $order->product?->name ?? 'Unknown',
                    'quantity' => $order->quantity,
                ]]);
            }

            return [
                'order_id' => $order->id,
                'order_number' => $order->order_number,
                'status' => $order->status->value,
                'receiver_name' => $order->receiver_name,
                'receiver_phone' => $order->receiver_phone,
                'total_amount' => (float) $order->total_amount,
                'created_at' => $order->created_at?->toIso8601String(),
                'created_at_formatted' => $order->created_at?->format('M j, Y g:i A'),
                'hours_ago' => $order->created_at?->diffInHours(now()) ?? 0,
                'matched_products' => $matchedProducts->toArray(),
                'courier_code' => $order->courier_code,
            ];
        })->values();

        $isDuplicate = $duplicateWarnings->isNotEmpty();

        return [
            'is_duplicate' => $isDuplicate,
            'phone' => $normalizedPhone,
            'product_ids' => $productIds,
            'time_window_hours' => $windowHours,
            'duplicate_count' => $duplicateWarnings->count(),
            'duplicates' => $duplicateWarnings->toArray(),
            'severity' => $this->determineSeverity($duplicateWarnings->count(), $windowHours),
        ];
    }

    /**
     * Check for any recent orders by phone (broader check for the create-order page).
     *
     * @param  int|null  $days  How many days back to look (default 30)
     * @return array<string, mixed>
     */
    public function checkRecentOrdersByPhone(string $phone, ?int $days = 30): array
    {
        $normalizedPhone = $this->phones->normalize($phone);
        $cutoff = Carbon::now()->subDays($days);

        $orders = Order::query()
            ->with('product:id,name,sku')
            ->where('receiver_phone', $normalizedPhone)
            ->where('created_at', '>=', $cutoff)
            ->whereNotIn('status', [OrderStatus::DRAFT->value, OrderStatus::CANCELLED->value])
            ->latest()
            ->limit(10)
            ->get(['id', 'order_number', 'product_id', 'status', 'total_amount', 'created_at', 'receiver_name']);

        return [
            'phone' => $normalizedPhone,
            'days' => $days,
            'count' => $orders->count(),
            'orders' => $orders->map(fn (Order $o) => [
                'order_id' => $o->id,
                'order_number' => $o->order_number,
                'status' => $o->status->value,
                'product_name' => $o->product?->name ?? 'Unknown',
                'total_amount' => (float) $o->total_amount,
                'created_at' => $o->created_at?->toIso8601String(),
                'created_at_formatted' => $o->created_at?->format('M j, Y g:i A'),
            ])->values()->toArray(),
        ];
    }

    /**
     * Detect duplicate conversations by PSID (page-scoped ID).
     *
     * Finds multiple active conversations for the same customer identity (PSID)
     * on the same or different Facebook pages, indicating the same person may
     * have multiple open conversation threads.
     *
     * @param  string  $psid  The provider_user_id (PSID) to check
     * @param  int|null  $facebookPageId  Optional: scope to a specific page
     * @param  int|null  $excludeConversationId  Conversation ID to exclude
     * @return array<string, mixed>
     */
    public function detectDuplicateConversationsByPsid(
        string $psid,
        ?int $facebookPageId = null,
        ?int $excludeConversationId = null,
    ): array {
        if (empty($psid)) {
            return [
                'is_duplicate' => false,
                'psid' => '',
                'duplicate_count' => 0,
                'duplicates' => [],
                'severity' => 'none',
            ];
        }

        $identityQuery = CustomerIdentity::query()
            ->where('provider', 'facebook')
            ->where('provider_user_id', $psid);

        if ($facebookPageId) {
            $identityQuery->where('facebook_page_id', $facebookPageId);
        }

        $identities = $identityQuery->get();

        if ($identities->isEmpty()) {
            return [
                'is_duplicate' => false,
                'psid' => $psid,
                'duplicate_count' => 0,
                'duplicates' => [],
                'severity' => 'none',
            ];
        }

        $identityIds = $identities->pluck('id')->all();

        $conversationQuery = Conversation::query()
            ->whereIn('customer_identity_id', $identityIds)
            ->whereIn('status', Conversation::ACTIVE_STATUSES)
            ->whereNull('merged_into_id');

        if ($excludeConversationId) {
            $conversationQuery->where('id', '!=', $excludeConversationId);
        }

        $conversations = $conversationQuery
            ->with([
                'identity:id,provider_user_id,display_name,phone_detected,facebook_page_id',
                'facebookPage:id,page_id,page_name',
                'customer:id,name,phone,normalized_phone',
                'assignedAgent:id,name',
            ])
            ->latest()
            ->get();

        $duplicates = $conversations->map(function (Conversation $conv) {
            return [
                'conversation_id' => $conv->id,
                'status' => $conv->status,
                'channel' => $conv->channel,
                'priority' => $conv->priority,
                'is_flagged' => $conv->is_flagged,
                'flag_reason' => $conv->flag_reason,
                'last_message_at' => $conv->last_message_at?->toIso8601String(),
                'last_message_preview' => $conv->last_message_preview,
                'unread_count' => $conv->unread_count ?? 0,
                'psid' => $conv->identity?->provider_user_id,
                'display_name' => $conv->identity?->display_name,
                'phone_detected' => $conv->identity?->phone_detected,
                'page_name' => $conv->facebookPage?->page_name,
                'facebook_page_id' => $conv->facebook_page_id,
                'customer_name' => $conv->customer?->name,
                'customer_phone' => $conv->customer?->normalized_phone ?? $conv->customer?->phone,
                'assigned_agent' => $conv->assignedAgent?->name,
                'created_at' => $conv->created_at?->toIso8601String(),
                'created_at_formatted' => $conv->created_at?->format('M j, Y g:i A'),
                'hours_ago' => $conv->created_at?->diffInHours(now()) ?? 0,
            ];
        })->values();

        $count = $duplicates->count();

        return [
            'is_duplicate' => $count > 0,
            'psid' => $psid,
            'identity_count' => $identities->count(),
            'duplicate_count' => $count,
            'duplicates' => $duplicates->toArray(),
            'severity' => $this->determineConversationSeverity($count),
        ];
    }

    /**
     * Detect duplicate conversations by customer identity ID.
     *
     * Convenience method to check for duplicate conversations when
     * the customer_identity_id is already known (e.g., from a webhook).
     *
     * @return array<string, mixed>
     */
    public function detectDuplicateConversationsByIdentity(int $identityId, ?int $excludeConversationId = null): array
    {
        $identity = CustomerIdentity::find($identityId);

        if (! $identity || $identity->provider !== 'facebook') {
            return [
                'is_duplicate' => false,
                'psid' => '',
                'duplicate_count' => 0,
                'duplicates' => [],
                'severity' => 'none',
            ];
        }

        return $this->detectDuplicateConversationsByPsid(
            $identity->provider_user_id,
            $identity->facebook_page_id,
            $excludeConversationId,
        );
    }

    /**
     * Determine severity for conversation duplicates based on count.
     *
     * @return string 'none', 'low', 'medium', 'high'
     */
    private function determineConversationSeverity(int $count): string
    {
        if ($count === 0) {
            return 'none';
        }

        if ($count >= 3) {
            return 'high';
        }

        if ($count >= 2) {
            return 'medium';
        }

        return 'low';
    }

    /**
     * Determine the severity level based on duplicate count and time window.
     *
     * @return string 'none', 'low', 'medium', 'high'
     */
    private function determineSeverity(int $count, int $windowHours): string
    {
        if ($count === 0) {
            return 'none';
        }

        if ($count >= 3 || $windowHours <= 24) {
            return 'high';
        }

        if ($count >= 2 || $windowHours <= 48) {
            return 'medium';
        }

        return 'low';
    }

    /**
     * Detect duplicate customer records by phone, PSID, or name similarity.
     *
     * @param  int  $customerId  The primary customer to check against
     * @param  array  $methods  Detection methods: 'phone', 'psid', 'name'
     * @return array<string, mixed>
     */
    public function detectDuplicateCustomers(int $customerId, array $methods = ['phone', 'psid', 'name']): array
    {
        $customer = Customer::find($customerId);

        if (! $customer) {
            return [
                'is_duplicate' => false,
                'customer_id' => $customerId,
                'duplicate_count' => 0,
                'duplicates' => [],
                'severity' => 'none',
            ];
        }

        $duplicateIds = collect();

        // Match by normalized phone
        if (in_array('phone', $methods, true) && $customer->normalized_phone) {
            $phoneMatches = Customer::query()
                ->where('normalized_phone', $customer->normalized_phone)
                ->whereKeyNot($customer->id)
                ->pluck('id');
            $duplicateIds = $duplicateIds->merge($phoneMatches);
        }

        // Match by PSID (via customer_identities)
        if (in_array('psid', $methods, true)) {
            $psids = CustomerIdentity::query()
                ->where('customer_id', $customer->id)
                ->where('provider', 'facebook')
                ->pluck('provider_user_id');

            if ($psids->isNotEmpty()) {
                $psidCustomerIds = CustomerIdentity::query()
                    ->whereIn('provider_user_id', $psids->all())
                    ->where('customer_id', '!=', $customer->id)
                    ->whereNotNull('customer_id')
                    ->pluck('customer_id')
                    ->unique();
                $duplicateIds = $duplicateIds->merge($psidCustomerIds);
            }
        }

        // Match by name (case-insensitive exact match on facebook_name or name)
        if (in_array('name', $methods, true) && ($customer->name || $customer->facebook_name)) {
            $nameQuery = Customer::query()->whereKeyNot($customer->id);
            $nameQuery->where(function ($q) use ($customer) {
                if ($customer->name) {
                    $q->whereRaw('LOWER(name) = ?', [strtolower($customer->name)]);
                }
                if ($customer->facebook_name) {
                    $q->orWhereRaw('LOWER(facebook_name) = ?', [strtolower($customer->facebook_name)]);
                }
            });
            $nameMatches = $nameQuery->pluck('id');
            $duplicateIds = $duplicateIds->merge($nameMatches);
        }

        $duplicateIds = $duplicateIds->unique()->values();

        if ($duplicateIds->isEmpty()) {
            return [
                'is_duplicate' => false,
                'customer_id' => $customerId,
                'duplicate_count' => 0,
                'duplicates' => [],
                'severity' => 'none',
            ];
        }

        $duplicates = Customer::query()
            ->whereIn('id', $duplicateIds->all())
            ->withCount(['orders as orders_count'])
            ->orderByDesc('total_orders')
            ->get()
            ->map(function (Customer $dup) use ($customer) {
                $matchReasons = [];
                if ($customer->normalized_phone && $dup->normalized_phone === $customer->normalized_phone) {
                    $matchReasons[] = 'phone';
                }

                $sharedPsids = CustomerIdentity::query()
                    ->whereIn('customer_id', [$customer->id, $dup->id])
                    ->where('provider', 'facebook')
                    ->selectRaw('provider_user_id, COUNT(DISTINCT customer_id) as cust_count')
                    ->groupBy('provider_user_id')
                    ->havingRaw('cust_count > 1')
                    ->pluck('provider_user_id');
                if ($sharedPsids->isNotEmpty()) {
                    $matchReasons[] = 'psid';
                }

                if (
                    ($customer->name && $dup->name && strtolower($customer->name) === strtolower($dup->name))
                    || ($customer->facebook_name && $dup->facebook_name
                        && strtolower($customer->facebook_name) === strtolower($dup->facebook_name))
                ) {
                    $matchReasons[] = 'name';
                }

                return [
                    'id' => $dup->id,
                    'name' => $dup->name,
                    'facebook_name' => $dup->facebook_name,
                    'phone' => $dup->phone,
                    'normalized_phone' => $dup->normalized_phone,
                    'total_orders' => (int) ($dup->total_orders ?? 0),
                    'successful_orders' => (int) ($dup->successful_orders ?? 0),
                    'returned_orders' => (int) ($dup->returned_orders ?? 0),
                    'total_revenue' => (float) ($dup->total_revenue ?? 0),
                    'risk_level' => $dup->risk_level ?? 'LOW',
                    'is_blacklisted' => (bool) $dup->is_blacklisted,
                    'created_at' => $dup->created_at?->toIso8601String(),
                    'created_at_formatted' => $dup->created_at?->format('M j, Y'),
                    'orders_count' => $dup->orders_count ?? 0,
                    'match_reasons' => $matchReasons,
                ];
            })->values();

        $count = $duplicates->count();

        return [
            'is_duplicate' => $count > 0,
            'customer_id' => $customerId,
            'duplicate_count' => $count,
            'duplicates' => $duplicates->toArray(),
            'severity' => $this->determineConversationSeverity($count),
        ];
    }

    /**
     * Preview what will happen when merging $source into $target.
     *
     * @return array<string, mixed>
     */
    public function previewMerge(int $targetId, int $sourceId): array
    {
        $target = Customer::find($targetId);
        $source = Customer::find($sourceId);

        if (! $target || ! $source || $targetId === $sourceId) {
            return [
                'can_merge' => false,
                'reason' => 'Invalid customer IDs or same customer.',
            ];
        }

        $ordersCount = Order::query()->where('customer_id', $sourceId)->count();
        $conversationsCount = Conversation::query()->where('customer_id', $sourceId)->count();
        $identitiesCount = CustomerIdentity::query()->where('customer_id', $sourceId)->count();

        $addressCount = DB::table('customer_addresses')->where('customer_id', $sourceId)->count();
        $notesCount = DB::table('customer_notes')->where('customer_id', $sourceId)->count();
        $leadsCount = DB::table('leads')->where('customer_id', $sourceId)->count();

        $totalRecords = $ordersCount + $conversationsCount + $identitiesCount + $addressCount + $notesCount + $leadsCount;

        // Determine which fields will be filled from source
        $filledFields = [];
        foreach (['phone', 'facebook_name', 'canonical_address', 'landmark', 'barangay', 'city_municipality', 'province', 'region'] as $field) {
            if (empty($target->{$field}) && ! empty($source->{$field})) {
                $filledFields[] = $field;
            }
        }

        // Risk level will change?
        $riskOrder = ['LOW' => 0, 'MEDIUM' => 1, 'HIGH' => 2, 'BLACKLISTED' => 3];
        $riskWillChange = ($riskOrder[$source->risk_level ?? 'LOW'] ?? 0) > ($riskOrder[$target->risk_level ?? 'LOW'] ?? 0);

        return [
            'can_merge' => true,
            'target' => [
                'id' => $target->id,
                'name' => $target->name,
                'phone' => $target->phone,
                'normalized_phone' => $target->normalized_phone,
                'total_orders' => (int) ($target->total_orders ?? 0),
                'total_revenue' => (float) ($target->total_revenue ?? 0),
                'risk_level' => $target->risk_level ?? 'LOW',
                'is_blacklisted' => (bool) $target->is_blacklisted,
            ],
            'source' => [
                'id' => $source->id,
                'name' => $source->name,
                'phone' => $source->phone,
                'normalized_phone' => $source->normalized_phone,
                'total_orders' => (int) ($source->total_orders ?? 0),
                'total_revenue' => (float) ($source->total_revenue ?? 0),
                'risk_level' => $source->risk_level ?? 'LOW',
                'is_blacklisted' => (bool) $source->is_blacklisted,
            ],
            'transfer_summary' => [
                'orders' => $ordersCount,
                'conversations' => $conversationsCount,
                'identities' => $identitiesCount,
                'addresses' => $addressCount,
                'notes' => $notesCount,
                'leads' => $leadsCount,
                'total_records' => $totalRecords,
            ],
            'merged_stats' => [
                'total_orders' => (int) ($target->total_orders ?? 0) + (int) ($source->total_orders ?? 0),
                'successful_orders' => (int) ($target->successful_orders ?? 0) + (int) ($source->successful_orders ?? 0),
                'returned_orders' => (int) ($target->returned_orders ?? 0) + (int) ($source->returned_orders ?? 0),
                'total_revenue' => (float) ($target->total_revenue ?? 0) + (float) ($source->total_revenue ?? 0),
            ],
            'filled_fields' => $filledFields,
            'risk_will_change' => $riskWillChange,
            'new_risk_level' => $riskWillChange ? $source->risk_level : $target->risk_level,
        ];
    }

    /**
     * Detect fuzzy duplicate customers by name and address similarity.
     *
     * Uses similar_text for name similarity and token-based Jaccard
     * similarity for address fields. Returns candidates above the
     * configured thresholds.
     *
     * @param  int  $customerId  The primary customer to check against
     * @param  float  $nameThreshold  Minimum name similarity (0-100, default 80)
     * @param  float  $addressThreshold  Minimum address similarity (0-1, default 0.6)
     * @param  int  $limit  Max results (default 20)
     * @return array<string, mixed>
     */
    public function detectFuzzyDuplicateCustomers(
        int $customerId,
        float $nameThreshold = 80.0,
        float $addressThreshold = 0.6,
        int $limit = 20,
    ): array {
        $customer = Customer::find($customerId);

        if (! $customer) {
            return [
                'is_duplicate' => false,
                'customer_id' => $customerId,
                'duplicate_count' => 0,
                'duplicates' => [],
                'severity' => 'none',
            ];
        }

        // Build a candidate pool — customers that share at least one
        // address component or have a similar-ish name (first 3 chars).
        $candidates = collect();

        $addressFields = ['barangay', 'city_municipality', 'province'];
        foreach ($addressFields as $field) {
            if (! empty($customer->{$field})) {
                $matches = Customer::query()
                    ->whereKeyNot($customer->id)
                    ->where($field, $customer->{$field})
                    ->limit(50)
                    ->get();
                $candidates = $candidates->merge($matches);
            }
        }

        // Also grab customers with same first 3 chars of name (broad net)
        $namePrefix = substr($customer->name ?? $customer->facebook_name ?? '', 0, 3);
        if (strlen($namePrefix) >= 2) {
            $nameMatches = Customer::query()
                ->whereKeyNot($customer->id)
                ->where(function ($q) use ($namePrefix) {
                    $q->whereRaw('LOWER(name) LIKE ?', [strtolower($namePrefix).'%'])
                        ->orWhereRaw('LOWER(facebook_name) LIKE ?', [strtolower($namePrefix).'%']);
                })
                ->limit(50)
                ->get();
            $candidates = $candidates->merge($nameMatches);
        }

        // Also include phone-based matches as a baseline (already exact, but
        // useful to show alongside fuzzy results)
        if ($customer->normalized_phone) {
            $phoneMatches = Customer::query()
                ->where('normalized_phone', $customer->normalized_phone)
                ->whereKeyNot($customer->id)
                ->limit(10)
                ->get();
            $candidates = $candidates->merge($phoneMatches);
        }

        $candidates = $candidates->unique('id')->take(100);

        $results = [];

        foreach ($candidates as $candidate) {
            $nameScore = $this->nameSimilarity(
                $customer->name ?? $customer->facebook_name ?? '',
                $candidate->name ?? $candidate->facebook_name ?? '',
            );

            $addressScore = $this->addressSimilarity($customer, $candidate);

            // Must exceed at least one threshold to be considered fuzzy
            if ($nameScore < $nameThreshold && $addressScore < $addressThreshold) {
                continue;
            }

            // Combined score: weighted average (name 60%, address 40%)
            $combinedScore = ($nameScore * 0.6) + ($addressScore * 0.4);

            $results[] = [
                'id' => $candidate->id,
                'name' => $candidate->name,
                'facebook_name' => $candidate->facebook_name,
                'phone' => $candidate->phone,
                'normalized_phone' => $candidate->normalized_phone,
                'canonical_address' => $candidate->canonical_address,
                'barangay' => $candidate->barangay,
                'city_municipality' => $candidate->city_municipality,
                'province' => $candidate->province,
                'total_orders' => (int) ($candidate->total_orders ?? 0),
                'total_revenue' => (float) ($candidate->total_revenue ?? 0),
                'risk_level' => $candidate->risk_level ?? 'LOW',
                'is_blacklisted' => (bool) $candidate->is_blacklisted,
                'created_at' => $candidate->created_at?->toIso8601String(),
                'created_at_formatted' => $candidate->created_at?->format('M j, Y'),
                'similarity' => [
                    'name' => round($nameScore, 1),
                    'address' => round($addressScore, 3),
                    'combined' => round($combinedScore, 1),
                ],
                'match_type' => $nameScore >= $nameThreshold && $addressScore >= $addressThreshold
                    ? 'name+address'
                    : ($nameScore >= $nameThreshold ? 'name' : 'address'),
            ];
        }

        // Sort by combined similarity descending
        usort($results, fn ($a, $b) => $b['similarity']['combined'] <=> $a['similarity']['combined']);

        $results = array_slice($results, 0, $limit);

        $count = count($results);

        return [
            'is_duplicate' => $count > 0,
            'customer_id' => $customerId,
            'thresholds' => [
                'name' => $nameThreshold,
                'address' => $addressThreshold,
            ],
            'duplicate_count' => $count,
            'duplicates' => $results,
            'severity' => $this->determineConversationSeverity($count),
        ];
    }

    /**
     * Calculate name similarity using similar_text (returns 0-100 percentage).
     */
    private function nameSimilarity(string $a, string $b): float
    {
        $a = trim(strtolower($a));
        $b = trim(strtolower($b));

        if ($a === '' || $b === '') {
            return 0.0;
        }

        if ($a === $b) {
            return 100.0;
        }

        similar_text($a, $b, $percent);

        return (float) $percent;
    }

    /**
     * Calculate address similarity using token-based Jaccard overlap.
     *
     * Compares canonical_address + barangay + city_municipality + province
     * as token sets. Returns 0-1.
     */
    private function addressSimilarity(Customer $a, Customer $b): float
    {
        $tokensA = $this->tokenizeAddress($a);
        $tokensB = $this->tokenizeAddress($b);

        if (empty($tokensA) || empty($tokensB)) {
            return 0.0;
        }

        $intersection = count(array_intersect($tokensA, $tokensB));
        $union = count(array_unique(array_merge($tokensA, $tokensB)));

        return $union > 0 ? $intersection / $union : 0.0;
    }

    /**
     * Tokenize a customer's address into a normalized set of tokens.
     *
     * @return array<int, string>
     */
    private function tokenizeAddress(Customer $c): array
    {
        $parts = array_filter([
            $c->canonical_address,
            $c->barangay,
            $c->city_municipality,
            $c->province,
            $c->region,
        ]);

        $combined = implode(' ', $parts);

        // Lowercase, remove punctuation, split on whitespace
        $normalized = preg_replace('/[^\p{L}\p{N}\s]/u', ' ', strtolower($combined));
        $tokens = array_filter(preg_split('/\s+/', $normalized ?? '') ?: [], fn ($t) => strlen($t) >= 2);

        // Remove common stop words
        $stopWords = ['the', 'st', 'street', 'brgy', 'barangay', 'city', 'municipality', 'province', 'region', 'of', 'and'];
        $tokens = array_filter($tokens, fn ($t) => ! in_array($t, $stopWords, true));

        return array_values(array_unique($tokens));
    }

    /**
     * Scan for all duplicate types and populate the review queue.
     *
     * @param  int  $limit  Max items per type to create
     * @return array{created: int, skipped: int, type_breakdown: array<string, int>}
     */
    public function scanForReviewQueue(int $limit = 50): array
    {
        $created = 0;
        $skipped = 0;
        $typeBreakdown = ['order' => 0, 'customer' => 0, 'conversation' => 0];

        // 1. Scan for duplicate orders (phone + product within 72h)
        $orderDuplicates = $this->scanDuplicateOrdersForQueue($limit);
        foreach ($orderDuplicates as $item) {
            $exists = DuplicateReviewItem::query()
                ->where('type', 'order')
                ->where('primary_ref_id', $item['primary_ref_id'])
                ->where('duplicate_ref_id', $item['duplicate_ref_id'])
                ->where('status', 'pending')
                ->exists();

            if ($exists) {
                $skipped++;

                continue;
            }

            DuplicateReviewItem::create($item);
            $created++;
            $typeBreakdown['order']++;
        }

        // 2. Scan for duplicate customers (phone)
        $customerDuplicates = $this->scanDuplicateCustomersForQueue($limit);
        foreach ($customerDuplicates as $item) {
            $exists = DuplicateReviewItem::query()
                ->where('type', 'customer')
                ->where('primary_ref_id', $item['primary_ref_id'])
                ->where('duplicate_ref_id', $item['duplicate_ref_id'])
                ->where('status', 'pending')
                ->exists();

            if ($exists) {
                $skipped++;

                continue;
            }

            DuplicateReviewItem::create($item);
            $created++;
            $typeBreakdown['customer']++;
        }

        // 3. Scan for duplicate conversations (PSID)
        $conversationDuplicates = $this->scanDuplicateConversationsForQueue($limit);
        foreach ($conversationDuplicates as $item) {
            $exists = DuplicateReviewItem::query()
                ->where('type', 'conversation')
                ->where('primary_ref_id', $item['primary_ref_id'])
                ->where('duplicate_ref_id', $item['duplicate_ref_id'])
                ->where('status', 'pending')
                ->exists();

            if ($exists) {
                $skipped++;

                continue;
            }

            DuplicateReviewItem::create($item);
            $created++;
            $typeBreakdown['conversation']++;
        }

        return [
            'created' => $created,
            'skipped' => $skipped,
            'type_breakdown' => $typeBreakdown,
        ];
    }

    /**
     * Get the review queue with filtering and pagination.
     *
     * @param  array{type?: string, status?: string, severity?: string, per_page?: int}  $filters
     * @return array<string, mixed>
     */
    public function getReviewQueue(array $filters = []): array
    {
        $query = DuplicateReviewItem::query()
            ->with('reviewer:id,name')
            ->orderByDesc('severity')
            ->orderByDesc('created_at');

        if (! empty($filters['type'])) {
            $query->where('type', $filters['type']);
        }

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        } else {
            $query->where('status', 'pending');
        }

        if (! empty($filters['severity'])) {
            $query->where('severity', $filters['severity']);
        }

        $perPage = $filters['per_page'] ?? 25;
        $items = $query->paginate($perPage);

        return [
            'items' => $items->items(),
            'meta' => [
                'current_page' => $items->currentPage(),
                'last_page' => $items->lastPage(),
                'per_page' => $items->perPage(),
                'total' => $items->total(),
                'from' => $items->firstItem(),
                'to' => $items->lastItem(),
            ],
        ];
    }

    /**
     * Resolve a review item (mark as reviewed, dismissed, or actioned).
     *
     * @param  string  $status  reviewed|dismissed|actioned
     */
    public function resolveReviewItem(int $itemId, string $status, int $userId, ?string $note = null): ?DuplicateReviewItem
    {
        $item = DuplicateReviewItem::find($itemId);

        if (! $item) {
            return null;
        }

        $item->update([
            'status' => $status,
            'reviewed_by' => $userId,
            'reviewed_at' => now(),
            'review_note' => $note,
        ]);

        return $item->fresh();
    }

    /**
     * Get summary stats for the review queue.
     *
     * @return array<string, mixed>
     */
    public function getReviewQueueStats(): array
    {
        $total = DuplicateReviewItem::count();
        $pending = DuplicateReviewItem::where('status', 'pending')->count();
        $reviewed = DuplicateReviewItem::where('status', 'reviewed')->count();
        $dismissed = DuplicateReviewItem::where('status', 'dismissed')->count();
        $actioned = DuplicateReviewItem::where('status', 'actioned')->count();

        $byType = DuplicateReviewItem::query()
            ->selectRaw('type, status, COUNT(*) as count')
            ->groupBy('type', 'status')
            ->get()
            ->groupBy('type')
            ->map(fn ($group) => $group->pluck('count', 'status')->toArray())
            ->toArray();

        $bySeverity = DuplicateReviewItem::query()
            ->selectRaw('severity, COUNT(*) as count')
            ->where('status', 'pending')
            ->groupBy('severity')
            ->pluck('count', 'severity')
            ->toArray();

        return [
            'total' => $total,
            'pending' => $pending,
            'reviewed' => $reviewed,
            'dismissed' => $dismissed,
            'actioned' => $actioned,
            'by_type' => $byType,
            'by_severity' => $bySeverity,
        ];
    }

    /**
     * Scan for duplicate orders and return review item data.
     *
     * @return array<int, array<string, mixed>>
     */
    private function scanDuplicateOrdersForQueue(int $limit): array
    {
        $cutoff = Carbon::now()->subHours($this->defaultTimeWindowHours);

        $orders = Order::query()
            ->where('created_at', '>=', $cutoff)
            ->whereNotIn('status', array_map(fn ($s) => $s->value, $this->excludedStatuses))
            ->orderByDesc('created_at')
            ->limit($limit * 2)
            ->get(['id', 'order_number', 'receiver_phone', 'product_id', 'created_at']);

        $items = [];
        $seen = [];

        foreach ($orders as $order) {
            if (empty($order->receiver_phone)) {
                continue;
            }

            $duplicates = Order::query()
                ->where('receiver_phone', $order->receiver_phone)
                ->where('id', '!=', $order->id)
                ->where('created_at', '>=', $cutoff)
                ->whereNotIn('status', array_map(fn ($s) => $s->value, $this->excludedStatuses))
                ->limit(5)
                ->get(['id', 'order_number', 'created_at']);

            foreach ($duplicates as $dup) {
                $key = $order->id < $dup->id ? "{$order->id}-{$dup->id}" : "{$dup->id}-{$order->id}";
                if (isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;

                $hoursAgo = $order->created_at?->diffInHours(now()) ?? 0;
                $severity = $hoursAgo <= 24 ? 'high' : ($hoursAgo <= 48 ? 'medium' : 'low');

                $items[] = [
                    'type' => 'order',
                    'primary_ref_id' => $order->id,
                    'duplicate_ref_id' => $dup->id,
                    'primary_label' => $order->order_number,
                    'duplicate_label' => $dup->order_number,
                    'match_method' => 'phone+product',
                    'similarity_score' => null,
                    'severity' => $severity,
                    'status' => 'pending',
                    'metadata' => [
                        'phone' => $order->receiver_phone,
                        'primary_created_at' => $order->created_at?->toIso8601String(),
                        'duplicate_created_at' => $dup->created_at?->toIso8601String(),
                    ],
                ];

                if (count($items) >= $limit) {
                    return $items;
                }
            }
        }

        return $items;
    }

    /**
     * Scan for duplicate customers by phone and return review item data.
     *
     * @return array<int, array<string, mixed>>
     */
    private function scanDuplicateCustomersForQueue(int $limit): array
    {
        $duplicatePhones = Customer::query()
            ->select('normalized_phone')
            ->whereNotNull('normalized_phone')
            ->groupBy('normalized_phone')
            ->havingRaw('COUNT(*) > 1')
            ->limit($limit)
            ->pluck('normalized_phone');

        $items = [];

        foreach ($duplicatePhones as $phone) {
            $records = Customer::query()
                ->where('normalized_phone', $phone)
                ->orderBy('id')
                ->get(['id', 'name', 'phone', 'normalized_phone', 'total_orders', 'created_at']);

            if ($records->count() < 2) {
                continue;
            }

            $target = $records->first();

            foreach ($records->skip(1) as $source) {
                $items[] = [
                    'type' => 'customer',
                    'primary_ref_id' => $target->id,
                    'duplicate_ref_id' => $source->id,
                    'primary_label' => $target->name ?? "Customer #{$target->id}",
                    'duplicate_label' => $source->name ?? "Customer #{$source->id}",
                    'match_method' => 'phone',
                    'similarity_score' => 100.0,
                    'severity' => 'high',
                    'status' => 'pending',
                    'metadata' => [
                        'normalized_phone' => $phone,
                        'primary_total_orders' => (int) ($target->total_orders ?? 0),
                        'duplicate_total_orders' => (int) ($source->total_orders ?? 0),
                    ],
                ];

                if (count($items) >= $limit) {
                    return $items;
                }
            }
        }

        return $items;
    }

    /**
     * Scan for duplicate conversations by PSID and return review item data.
     *
     * @return array<int, array<string, mixed>>
     */
    private function scanDuplicateConversationsForQueue(int $limit): array
    {
        $duplicatePsids = CustomerIdentity::query()
            ->select('provider_user_id')
            ->where('provider', 'facebook')
            ->groupBy('provider_user_id')
            ->havingRaw('COUNT(*) > 1')
            ->limit($limit)
            ->pluck('provider_user_id');

        $items = [];

        foreach ($duplicatePsids as $psid) {
            $identities = CustomerIdentity::query()
                ->where('provider', 'facebook')
                ->where('provider_user_id', $psid)
                ->with('conversations:id,customer_identity_id,status,created_at')
                ->get(['id', 'provider_user_id', 'display_name', 'customer_id']);

            $conversations = collect();
            foreach ($identities as $identity) {
                foreach ($identity->conversations as $conv) {
                    if (in_array($conv->status, Conversation::ACTIVE_STATUSES) && ! $conv->merged_into_id) {
                        $conversations->push($conv);
                    }
                }
            }

            if ($conversations->count() < 2) {
                continue;
            }

            $sorted = $conversations->sortBy('id')->values();
            $primary = $sorted->first();

            foreach ($sorted->skip(1) as $dup) {
                $items[] = [
                    'type' => 'conversation',
                    'primary_ref_id' => $primary->id,
                    'duplicate_ref_id' => $dup->id,
                    'primary_label' => "Conversation #{$primary->id}",
                    'duplicate_label' => "Conversation #{$dup->id}",
                    'match_method' => 'psid',
                    'similarity_score' => 100.0,
                    'severity' => 'medium',
                    'status' => 'pending',
                    'metadata' => [
                        'psid' => $psid,
                        'display_name' => $identities->first()?->display_name,
                    ],
                ];

                if (count($items) >= $limit) {
                    return $items;
                }
            }
        }

        return $items;
    }

    // ── Configuration Rules ──────────────────────────────────────────

    /**
     * Get all detection rules, ordered by priority.
     *
     * @return Collection<int, DuplicateDetectionRule>
     */
    public function getAllRules()
    {
        return DuplicateDetectionRule::query()
            ->with(['creator:id,name', 'updater:id,name'])
            ->orderBy('priority')
            ->orderBy('name')
            ->get();
    }

    /**
     * Get enabled rules for a given type, ordered by priority.
     *
     * @param  string  $type  order|customer|conversation
     * @return Collection<int, DuplicateDetectionRule>
     */
    public function getActiveRules(string $type)
    {
        return DuplicateDetectionRule::query()
            ->where('type', $type)
            ->where('is_enabled', true)
            ->orderBy('priority')
            ->get();
    }

    /**
     * Create a new detection rule.
     *
     * @param  array{name:string,type:string,match_method?:string|null,is_enabled?:bool,priority?:int,config?:array|null,description?:string|null}  $data
     */
    public function createRule(array $data, int $userId): DuplicateDetectionRule
    {
        return DuplicateDetectionRule::create([
            'name' => $data['name'],
            'type' => $data['type'],
            'match_method' => $data['match_method'] ?? null,
            'is_enabled' => $data['is_enabled'] ?? true,
            'priority' => $data['priority'] ?? 0,
            'config' => $data['config'] ?? null,
            'description' => $data['description'] ?? null,
            'created_by' => $userId,
            'updated_by' => $userId,
        ]);
    }

    /**
     * Update an existing detection rule.
     *
     * @param  array{name?:string,type?:string,match_method?:string|null,is_enabled?:bool,priority?:int,config?:array|null,description?:string|null}  $data
     */
    public function updateRule(int $ruleId, array $data, int $userId): ?DuplicateDetectionRule
    {
        $rule = DuplicateDetectionRule::find($ruleId);

        if (! $rule) {
            return null;
        }

        $updateData = [];
        foreach (['name', 'type', 'match_method', 'is_enabled', 'priority', 'config', 'description'] as $field) {
            if (array_key_exists($field, $data)) {
                $updateData[$field] = $data[$field];
            }
        }
        $updateData['updated_by'] = $userId;

        $rule->update($updateData);

        return $rule->fresh();
    }

    /**
     * Delete a detection rule.
     */
    public function deleteRule(int $ruleId): bool
    {
        $rule = DuplicateDetectionRule::find($ruleId);

        if (! $rule) {
            return false;
        }

        return $rule->delete();
    }

    /**
     * Toggle a rule's enabled status.
     */
    public function toggleRule(int $ruleId, int $userId): ?DuplicateDetectionRule
    {
        $rule = DuplicateDetectionRule::find($ruleId);

        if (! $rule) {
            return null;
        }

        $rule->update([
            'is_enabled' => ! $rule->is_enabled,
            'updated_by' => $userId,
        ]);

        return $rule->fresh();
    }

    /**
     * Get the effective config for a detection type by merging
     * enabled rules' config values. Later-priority rules override earlier.
     *
     * @return array<string, mixed>
     */
    public function getEffectiveConfig(string $type): array
    {
        $rules = $this->getActiveRules($type);

        $config = [];

        foreach ($rules as $rule) {
            if ($rule->config) {
                $config = array_merge($config, $rule->config);
            }
        }

        return $config;
    }

    // ── Analytics ────────────────────────────────────────────────────

    /**
     * Get a comprehensive analytics overview for the duplicate review system.
     *
     * @param  int  $days  Look-back period (default 30)
     * @return array<string, mixed>
     */
    public function getAnalyticsOverview(int $days = 30): array
    {
        $cutoff = Carbon::now()->subDays($days);

        $totalItems = DuplicateReviewItem::count();
        $itemsInPeriod = DuplicateReviewItem::where('created_at', '>=', $cutoff)->count();

        $byType = DuplicateReviewItem::query()
            ->selectRaw('type, COUNT(*) as count')
            ->groupBy('type')
            ->pluck('count', 'type')
            ->toArray();

        $byStatus = DuplicateReviewItem::query()
            ->selectRaw('status, COUNT(*) as count')
            ->groupBy('status')
            ->pluck('count', 'status')
            ->toArray();

        $bySeverity = DuplicateReviewItem::query()
            ->selectRaw('severity, COUNT(*) as count')
            ->groupBy('severity')
            ->pluck('count', 'severity')
            ->toArray();

        $byMatchMethod = DuplicateReviewItem::query()
            ->selectRaw('match_method, COUNT(*) as count')
            ->whereNotNull('match_method')
            ->groupBy('match_method')
            ->pluck('count', 'match_method')
            ->toArray();

        $resolutionRate = $totalItems > 0
            ? round((($byStatus['reviewed'] ?? 0) + ($byStatus['actioned'] ?? 0)) / $totalItems * 100, 1)
            : 0;

        $avgResolutionHours = null;
        $resolvedCount = ($byStatus['reviewed'] ?? 0) + ($byStatus['actioned'] ?? 0) + ($byStatus['dismissed'] ?? 0);
        if ($resolvedCount > 0) {
            $avgResolutionHours = (float) DuplicateReviewItem::query()
                ->whereIn('status', ['reviewed', 'actioned', 'dismissed'])
                ->whereNotNull('reviewed_at')
                ->selectRaw('AVG(EXTRACT(EPOCH FROM (reviewed_at - created_at)) / 3600) as avg_hours')
                ->value('avg_hours');
            $avgResolutionHours = $avgResolutionHours ? round($avgResolutionHours, 1) : null;
        }

        $topReviewers = DuplicateReviewItem::query()
            ->selectRaw('reviewed_by, COUNT(*) as count')
            ->whereNotNull('reviewed_by')
            ->groupBy('reviewed_by')
            ->orderByDesc('count')
            ->limit(5)
            ->get()
            ->map(function ($row) {
                $user = User::find($row->reviewed_by);

                return [
                    'user_id' => $row->reviewed_by,
                    'name' => $user?->name ?? "User #{$row->reviewed_by}",
                    'count' => (int) $row->count,
                ];
            })
            ->values()
            ->toArray();

        $activeRulesCount = DuplicateDetectionRule::where('is_enabled', true)->count();
        $totalRulesCount = DuplicateDetectionRule::count();

        return [
            'period_days' => $days,
            'total_items' => $totalItems,
            'items_in_period' => $itemsInPeriod,
            'by_type' => $byType,
            'by_status' => $byStatus,
            'by_severity' => $bySeverity,
            'by_match_method' => $byMatchMethod,
            'resolution_rate' => $resolutionRate,
            'avg_resolution_hours' => $avgResolutionHours,
            'top_reviewers' => $topReviewers,
            'active_rules' => $activeRulesCount,
            'total_rules' => $totalRulesCount,
        ];
    }

    /**
     * Get daily/weekly trend of duplicate items created and resolved.
     *
     * @param  int  $days  Look-back period (default 30)
     * @return array<int, array<string, mixed>>
     */
    public function getAnalyticsTrend(int $days = 30): array
    {
        $cutoff = Carbon::now()->subDays($days);

        $created = DuplicateReviewItem::query()
            ->selectRaw('DATE(created_at) as date, COUNT(*) as count')
            ->where('created_at', '>=', $cutoff)
            ->groupByRaw('DATE(created_at)')
            ->orderByRaw('DATE(created_at)')
            ->get()
            ->pluck('count', 'date')
            ->toArray();

        $resolved = DuplicateReviewItem::query()
            ->selectRaw('DATE(reviewed_at) as date, COUNT(*) as count')
            ->where('reviewed_at', '>=', $cutoff)
            ->whereNotNull('reviewed_at')
            ->groupByRaw('DATE(reviewed_at)')
            ->orderByRaw('DATE(reviewed_at)')
            ->get()
            ->pluck('count', 'date')
            ->toArray();

        $dates = array_unique(array_merge(array_keys($created), array_keys($resolved)));
        sort($dates);

        $trend = [];
        $cumulativeCreated = 0;
        $cumulativeResolved = 0;

        foreach ($dates as $date) {
            $createdCount = $created[$date] ?? 0;
            $resolvedCount = $resolved[$date] ?? 0;
            $cumulativeCreated += $createdCount;
            $cumulativeResolved += $resolvedCount;

            $trend[] = [
                'date' => $date,
                'created' => $createdCount,
                'resolved' => $resolvedCount,
                'cumulative_created' => $cumulativeCreated,
                'cumulative_resolved' => $cumulativeResolved,
                'backlog' => $cumulativeCreated - $cumulativeResolved,
            ];
        }

        return $trend;
    }

    /**
     * Get breakdown analytics by type with status and severity cross-tabs.
     *
     * @return array<string, mixed>
     */
    public function getAnalyticsBreakdown(): array
    {
        $types = ['order', 'customer', 'conversation'];

        $breakdown = [];

        foreach ($types as $type) {
            $items = DuplicateReviewItem::where('type', $type);

            $total = $items->count();
            $pending = (clone $items)->where('status', 'pending')->count();
            $reviewed = (clone $items)->where('status', 'reviewed')->count();
            $dismissed = (clone $items)->where('status', 'dismissed')->count();
            $actioned = (clone $items)->where('status', 'actioned')->count();

            $bySeverity = (clone $items)
                ->selectRaw('severity, COUNT(*) as count')
                ->groupBy('severity')
                ->pluck('count', 'severity')
                ->toArray();

            $byMatchMethod = (clone $items)
                ->selectRaw('match_method, COUNT(*) as count')
                ->whereNotNull('match_method')
                ->groupBy('match_method')
                ->pluck('count', 'match_method')
                ->toArray();

            $breakdown[$type] = [
                'total' => $total,
                'pending' => $pending,
                'reviewed' => $reviewed,
                'dismissed' => $dismissed,
                'actioned' => $actioned,
                'by_severity' => $bySeverity,
                'by_match_method' => $byMatchMethod,
            ];
        }

        return $breakdown;
    }

    // ── Auto-Merge Suggestions ───────────────────────────────────────

    /**
     * Scan for high-confidence duplicate customer pairs and create
     * auto-merge suggestions.
     *
     * Confidence scoring:
     *  - Exact phone match: +40
     *  - Shared PSID: +35
     *  - Exact name match: +15
     *  - Exact address match: +10
     *  - Minimum threshold to create suggestion: 70
     *
     * @param  int  $limit  Max pairs to evaluate
     * @return array{created: int, skipped: int, evaluated: int}
     */
    public function scanForAutoMergeSuggestions(int $limit = 100): array
    {
        $created = 0;
        $skipped = 0;
        $evaluated = 0;

        // 1. Find customers sharing the same normalized_phone
        $phoneGroups = Customer::query()
            ->select('normalized_phone')
            ->whereNotNull('normalized_phone')
            ->where('normalized_phone', '!=', '')
            ->groupBy('normalized_phone')
            ->havingRaw('COUNT(*) > 1')
            ->limit($limit)
            ->pluck('normalized_phone');

        foreach ($phoneGroups as $phone) {
            $customers = Customer::query()
                ->where('normalized_phone', $phone)
                ->orderBy('id')
                ->get();

            $evaluated += $customers->count();

            for ($i = 0; $i < $customers->count(); $i++) {
                for ($j = $i + 1; $j < $customers->count(); $j++) {
                    $target = $customers[$i];
                    $source = $customers[$j];

                    $result = $this->evaluateAutoMergePair($target, $source);
                    if ($result === null) {
                        $skipped++;

                        continue;
                    }

                    $exists = AutoMergeSuggestion::query()
                        ->where(function ($q) use ($target, $source) {
                            $q->where('target_customer_id', $target->id)
                                ->where('source_customer_id', $source->id);
                        })
                        ->orWhere(function ($q) use ($target, $source) {
                            $q->where('target_customer_id', $source->id)
                                ->where('source_customer_id', $target->id);
                        })
                        ->where('status', 'pending')
                        ->exists();

                    if ($exists) {
                        $skipped++;

                        continue;
                    }

                    AutoMergeSuggestion::create($result);
                    $created++;
                }
            }
        }

        // 2. Find customers sharing the same PSID
        $psidGroups = CustomerIdentity::query()
            ->select('provider_user_id')
            ->where('provider', 'facebook')
            ->whereNotNull('provider_user_id')
            ->groupBy('provider_user_id')
            ->havingRaw('COUNT(DISTINCT customer_id) > 1')
            ->limit($limit)
            ->pluck('provider_user_id');

        foreach ($psidGroups as $psid) {
            $customerIds = CustomerIdentity::query()
                ->where('provider', 'facebook')
                ->where('provider_user_id', $psid)
                ->distinct()
                ->pluck('customer_id');

            $customers = Customer::query()
                ->whereIn('id', $customerIds)
                ->orderBy('id')
                ->get();

            $evaluated += $customers->count();

            for ($i = 0; $i < $customers->count(); $i++) {
                for ($j = $i + 1; $j < $customers->count(); $j++) {
                    $target = $customers[$i];
                    $source = $customers[$j];

                    // Skip if already paired via phone (same pair)
                    $exists = AutoMergeSuggestion::query()
                        ->where(function ($q) use ($target, $source) {
                            $q->where('target_customer_id', $target->id)
                                ->where('source_customer_id', $source->id);
                        })
                        ->orWhere(function ($q) use ($target, $source) {
                            $q->where('target_customer_id', $source->id)
                                ->where('source_customer_id', $target->id);
                        })
                        ->where('status', 'pending')
                        ->exists();

                    if ($exists) {
                        $skipped++;

                        continue;
                    }

                    $result = $this->evaluateAutoMergePair($target, $source);
                    if ($result === null) {
                        $skipped++;

                        continue;
                    }

                    AutoMergeSuggestion::create($result);
                    $created++;
                }
            }
        }

        return [
            'created' => $created,
            'skipped' => $skipped,
            'evaluated' => $evaluated,
        ];
    }

    /**
     * Evaluate a customer pair for auto-merge suitability.
     *
     * @return array<string, mixed>|null Suggestion data or null if below threshold
     */
    private function evaluateAutoMergePair(Customer $target, Customer $source): ?array
    {
        if ($target->id === $source->id) {
            return null;
        }

        // Skip if either is blacklisted — require manual review
        if ($target->is_blacklisted || $source->is_blacklisted) {
            return null;
        }

        $confidence = 0;
        $matchReasons = [];

        // Phone match
        if (
            $target->normalized_phone
            && $target->normalized_phone === $source->normalized_phone
        ) {
            $confidence += 40;
            $matchReasons[] = 'phone';
        }

        // PSID match
        $sharedPsids = CustomerIdentity::query()
            ->whereIn('customer_id', [$target->id, $source->id])
            ->where('provider', 'facebook')
            ->selectRaw('provider_user_id, COUNT(DISTINCT customer_id) as cust_count')
            ->groupBy('provider_user_id')
            ->havingRaw('cust_count > 1')
            ->pluck('provider_user_id');
        if ($sharedPsids->isNotEmpty()) {
            $confidence += 35;
            $matchReasons[] = 'psid';
        }

        // Name match
        if (
            ($target->name && $source->name && strtolower($target->name) === strtolower($source->name))
            || ($target->facebook_name && $source->facebook_name
                && strtolower($target->facebook_name) === strtolower($source->facebook_name))
        ) {
            $confidence += 15;
            $matchReasons[] = 'name';
        }

        // Address match
        if (
            $target->canonical_address
            && $source->canonical_address
            && strtolower($target->canonical_address) === strtolower($source->canonical_address)
        ) {
            $confidence += 10;
            $matchReasons[] = 'address';
        }

        // Must meet minimum confidence threshold
        if ($confidence < 70) {
            return null;
        }

        // Target = customer with more orders (canonical record)
        if (($source->total_orders ?? 0) > ($target->total_orders ?? 0)) {
            [$target, $source] = [$source, $target];
        }

        // Build merge preview
        $preview = $this->previewMerge($target->id, $source->id);

        return [
            'target_customer_id' => $target->id,
            'source_customer_id' => $source->id,
            'confidence_score' => min($confidence, 100),
            'match_reasons' => $matchReasons,
            'merge_preview' => $preview,
            'status' => 'pending',
        ];
    }

    /**
     * Get paginated auto-merge suggestions with filters.
     *
     * @param  array{status?: string, min_confidence?: float, per_page?: int}  $filters
     * @return array<string, mixed>
     */
    public function getAutoMergeSuggestions(array $filters = []): array
    {
        $query = AutoMergeSuggestion::query()
            ->with([
                'targetCustomer:id,name,phone,normalized_phone,facebook_name,total_orders,total_revenue,risk_level,is_blacklisted,created_at',
                'sourceCustomer:id,name,phone,normalized_phone,facebook_name,total_orders,total_revenue,risk_level,is_blacklisted,created_at',
                'actioner:id,name',
            ])
            ->orderByDesc('confidence_score')
            ->orderByDesc('created_at');

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        } else {
            $query->where('status', 'pending');
        }

        if (! empty($filters['min_confidence'])) {
            $query->where('confidence_score', '>=', (float) $filters['min_confidence']);
        }

        $perPage = $filters['per_page'] ?? 25;
        $items = $query->paginate($perPage);

        return [
            'items' => $items->items(),
            'meta' => [
                'current_page' => $items->currentPage(),
                'last_page' => $items->lastPage(),
                'per_page' => $items->perPage(),
                'total' => $items->total(),
                'from' => $items->firstItem(),
                'to' => $items->lastItem(),
            ],
        ];
    }

    /**
     * Approve and execute an auto-merge suggestion.
     *
     * @return array{success: bool, suggestion?: AutoMergeSuggestion, error?: string}
     */
    public function approveAutoMergeSuggestion(int $suggestionId, int $userId, ?string $note = null): array
    {
        $suggestion = AutoMergeSuggestion::find($suggestionId);

        if (! $suggestion) {
            return ['success' => false, 'error' => 'Suggestion not found.'];
        }

        if ($suggestion->status !== 'pending') {
            return ['success' => false, 'error' => 'Suggestion has already been actioned.'];
        }

        $target = Customer::find($suggestion->target_customer_id);
        $source = Customer::find($suggestion->source_customer_id);

        if (! $target || ! $source) {
            $suggestion->update([
                'status' => 'rejected',
                'actioned_by' => $userId,
                'actioned_at' => now(),
                'action_note' => 'Customer record no longer exists.',
            ]);

            return ['success' => false, 'error' => 'Customer record no longer exists.'];
        }

        // Execute the merge
        $mergeService = app(CustomerMergeService::class);
        $mergeService->merge($target, $source);

        $suggestion->update([
            'status' => 'merged',
            'actioned_by' => $userId,
            'actioned_at' => now(),
            'action_note' => $note,
        ]);

        // Also resolve any related review items
        DuplicateReviewItem::query()
            ->where('type', 'customer')
            ->where(function ($q) use ($target, $source) {
                $q->where(function ($q2) use ($target, $source) {
                    $q2->where('primary_ref_id', $target->id)
                        ->where('duplicate_ref_id', $source->id);
                })->orWhere(function ($q2) use ($target, $source) {
                    $q2->where('primary_ref_id', $source->id)
                        ->where('duplicate_ref_id', $target->id);
                });
            })
            ->where('status', 'pending')
            ->update([
                'status' => 'actioned',
                'reviewed_by' => $userId,
                'reviewed_at' => now(),
                'review_note' => 'Auto-merged via suggestion #'.$suggestionId,
            ]);

        return ['success' => true, 'suggestion' => $suggestion->fresh()];
    }

    /**
     * Reject an auto-merge suggestion.
     */
    public function rejectAutoMergeSuggestion(int $suggestionId, int $userId, ?string $note = null): ?AutoMergeSuggestion
    {
        $suggestion = AutoMergeSuggestion::find($suggestionId);

        if (! $suggestion) {
            return null;
        }

        $suggestion->update([
            'status' => 'rejected',
            'actioned_by' => $userId,
            'actioned_at' => now(),
            'action_note' => $note,
        ]);

        return $suggestion->fresh();
    }

    /**
     * Get summary stats for auto-merge suggestions.
     *
     * @return array<string, mixed>
     */
    public function getAutoMergeStats(): array
    {
        $total = AutoMergeSuggestion::count();
        $pending = AutoMergeSuggestion::where('status', 'pending')->count();
        $merged = AutoMergeSuggestion::where('status', 'merged')->count();
        $rejected = AutoMergeSuggestion::where('status', 'rejected')->count();

        $byConfidence = [
            'high' => AutoMergeSuggestion::where('status', 'pending')->where('confidence_score', '>=', 90)->count(),
            'medium' => AutoMergeSuggestion::where('status', 'pending')->whereBetween('confidence_score', [75, 89])->count(),
            'low' => AutoMergeSuggestion::where('status', 'pending')->whereBetween('confidence_score', [70, 74])->count(),
        ];

        $avgConfidence = (float) AutoMergeSuggestion::where('status', 'pending')
            ->avg('confidence_score');

        return [
            'total' => $total,
            'pending' => $pending,
            'merged' => $merged,
            'rejected' => $rejected,
            'by_confidence' => $byConfidence,
            'avg_confidence' => round($avgConfidence, 1),
        ];
    }

    // ── Duplicate Family Grouping ────────────────────────────────────

    /**
     * Build duplicate families by grouping customers that share the
     * same normalized_phone or the same PSID.
     *
     * Each group of 2+ customers becomes a DuplicateFamily with members.
     * Existing active families for the same group_key are skipped.
     *
     * @param  int  $limit  Max groups to process per method
     * @return array{created: int, skipped: int, members_grouped: int}
     */
    public function buildFamilies(int $limit = 100): array
    {
        $created = 0;
        $skipped = 0;
        $membersGrouped = 0;

        // 1. Group by normalized_phone
        $phoneGroups = Customer::query()
            ->select('normalized_phone')
            ->whereNotNull('normalized_phone')
            ->where('normalized_phone', '!=', '')
            ->groupBy('normalized_phone')
            ->havingRaw('COUNT(*) > 1')
            ->limit($limit)
            ->pluck('normalized_phone');

        foreach ($phoneGroups as $phone) {
            $groupKey = "phone:{$phone}";

            $exists = DuplicateFamily::query()
                ->where('type', 'customer')
                ->where('group_key', $groupKey)
                ->where('status', 'active')
                ->exists();

            if ($exists) {
                $skipped++;

                continue;
            }

            $customers = Customer::query()
                ->where('normalized_phone', $phone)
                ->orderByDesc('total_orders')
                ->orderBy('id')
                ->get();

            if ($customers->count() < 2) {
                continue;
            }

            $anchor = $customers->first();
            $family = DuplicateFamily::create([
                'type' => 'customer',
                'group_key' => $groupKey,
                'group_method' => 'phone',
                'anchor_ref_id' => $anchor->id,
                'anchor_label' => $anchor->name ?? $anchor->facebook_name ?? "Customer #{$anchor->id}",
                'member_count' => $customers->count(),
                'merged_count' => 0,
                'status' => 'active',
                'metadata' => [
                    'phone' => $phone,
                    'total_orders' => $customers->sum(fn ($c) => $c->total_orders ?? 0),
                    'total_revenue' => $customers->sum(fn ($c) => (float) ($c->total_revenue ?? 0)),
                ],
            ]);

            foreach ($customers as $idx => $customer) {
                DuplicateFamilyMember::create([
                    'family_id' => $family->id,
                    'customer_id' => $customer->id,
                    'is_anchor' => $idx === 0,
                    'member_data' => [
                        'name' => $customer->name,
                        'facebook_name' => $customer->facebook_name,
                        'phone' => $customer->phone,
                        'normalized_phone' => $customer->normalized_phone,
                        'total_orders' => (int) ($customer->total_orders ?? 0),
                        'total_revenue' => (float) ($customer->total_revenue ?? 0),
                        'risk_level' => $customer->risk_level ?? 'LOW',
                        'is_blacklisted' => (bool) $customer->is_blacklisted,
                        'created_at' => $customer->created_at?->toIso8601String(),
                    ],
                    'match_reason' => 'phone',
                    'similarity_score' => 100.0,
                ]);
                $membersGrouped++;
            }
            $created++;
        }

        // 2. Group by PSID
        $psidGroups = CustomerIdentity::query()
            ->select('provider_user_id')
            ->where('provider', 'facebook')
            ->whereNotNull('provider_user_id')
            ->groupBy('provider_user_id')
            ->havingRaw('COUNT(DISTINCT customer_id) > 1')
            ->limit($limit)
            ->pluck('provider_user_id');

        foreach ($psidGroups as $psid) {
            $groupKey = "psid:{$psid}";

            $exists = DuplicateFamily::query()
                ->where('type', 'customer')
                ->where('group_key', $groupKey)
                ->where('status', 'active')
                ->exists();

            if ($exists) {
                $skipped++;

                continue;
            }

            $customerIds = CustomerIdentity::query()
                ->where('provider', 'facebook')
                ->where('provider_user_id', $psid)
                ->distinct()
                ->pluck('customer_id');

            $customers = Customer::query()
                ->whereIn('id', $customerIds)
                ->orderByDesc('total_orders')
                ->orderBy('id')
                ->get();

            if ($customers->count() < 2) {
                continue;
            }

            $anchor = $customers->first();
            $family = DuplicateFamily::create([
                'type' => 'customer',
                'group_key' => $groupKey,
                'group_method' => 'psid',
                'anchor_ref_id' => $anchor->id,
                'anchor_label' => $anchor->name ?? $anchor->facebook_name ?? "Customer #{$anchor->id}",
                'member_count' => $customers->count(),
                'merged_count' => 0,
                'status' => 'active',
                'metadata' => [
                    'psid' => $psid,
                    'total_orders' => $customers->sum(fn ($c) => $c->total_orders ?? 0),
                    'total_revenue' => $customers->sum(fn ($c) => (float) ($c->total_revenue ?? 0)),
                ],
            ]);

            foreach ($customers as $idx => $customer) {
                DuplicateFamilyMember::create([
                    'family_id' => $family->id,
                    'customer_id' => $customer->id,
                    'is_anchor' => $idx === 0,
                    'member_data' => [
                        'name' => $customer->name,
                        'facebook_name' => $customer->facebook_name,
                        'phone' => $customer->phone,
                        'normalized_phone' => $customer->normalized_phone,
                        'total_orders' => (int) ($customer->total_orders ?? 0),
                        'total_revenue' => (float) ($customer->total_revenue ?? 0),
                        'risk_level' => $customer->risk_level ?? 'LOW',
                        'is_blacklisted' => (bool) $customer->is_blacklisted,
                        'created_at' => $customer->created_at?->toIso8601String(),
                    ],
                    'match_reason' => 'psid',
                    'similarity_score' => 100.0,
                ]);
                $membersGrouped++;
            }
            $created++;
        }

        return [
            'created' => $created,
            'skipped' => $skipped,
            'members_grouped' => $membersGrouped,
        ];
    }

    /**
     * Get paginated duplicate families with filters.
     *
     * @param  array{status?: string, method?: string, min_members?: int, per_page?: int}  $filters
     * @return array<string, mixed>
     */
    public function getFamilies(array $filters = []): array
    {
        $query = DuplicateFamily::query()
            ->withCount('members')
            ->with('actioner:id,name')
            ->orderByDesc('member_count')
            ->orderByDesc('created_at');

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        } else {
            $query->where('status', 'active');
        }

        if (! empty($filters['method'])) {
            $query->where('group_method', $filters['method']);
        }

        if (! empty($filters['min_members'])) {
            $query->having('members_count', '>=', (int) $filters['min_members']);
        }

        $perPage = $filters['per_page'] ?? 25;
        $items = $query->paginate($perPage);

        return [
            'items' => $items->items(),
            'meta' => [
                'current_page' => $items->currentPage(),
                'last_page' => $items->lastPage(),
                'per_page' => $items->perPage(),
                'total' => $items->total(),
                'from' => $items->firstItem(),
                'to' => $items->lastItem(),
            ],
        ];
    }

    /**
     * Get a single family with all members and merge preview.
     *
     * @return array<string, mixed>|null
     */
    public function getFamilyDetail(int $familyId): ?array
    {
        $family = DuplicateFamily::with(['members', 'actioner:id,name'])->find($familyId);

        if (! $family) {
            return null;
        }

        // Build merge previews for each non-anchor member into the anchor
        $members = $family->members->map(function ($member) use ($family) {
            $data = $member->member_data ?? [];
            $preview = null;

            if (! $member->is_anchor && $member->customer_id && $family->anchor_ref_id) {
                $preview = $this->previewMerge($family->anchor_ref_id, $member->customer_id);
            }

            return [
                'id' => $member->id,
                'customer_id' => $member->customer_id,
                'is_anchor' => $member->is_anchor,
                'match_reason' => $member->match_reason,
                'similarity_score' => $member->similarity_score,
                'name' => $data['name'] ?? null,
                'facebook_name' => $data['facebook_name'] ?? null,
                'phone' => $data['phone'] ?? null,
                'total_orders' => $data['total_orders'] ?? 0,
                'total_revenue' => $data['total_revenue'] ?? 0,
                'risk_level' => $data['risk_level'] ?? 'LOW',
                'is_blacklisted' => $data['is_blacklisted'] ?? false,
                'created_at' => $data['created_at'] ?? null,
                'merge_preview' => $preview,
            ];
        });

        return [
            'id' => $family->id,
            'type' => $family->type,
            'group_key' => $family->group_key,
            'group_method' => $family->group_method,
            'anchor_ref_id' => $family->anchor_ref_id,
            'anchor_label' => $family->anchor_label,
            'member_count' => $family->member_count,
            'merged_count' => $family->merged_count,
            'status' => $family->status,
            'metadata' => $family->metadata,
            'actioned_by' => $family->actioned_by,
            'actioned_at' => $family->actioned_at?->toIso8601String(),
            'action_note' => $family->action_note,
            'actioner_name' => $family->actioner?->name,
            'created_at' => $family->created_at?->toIso8601String(),
            'members' => $members->toArray(),
        ];
    }

    /**
     * Merge all non-anchor members of a family into the anchor customer.
     *
     * @return array{success: bool, merged_count?: int, error?: string}
     */
    public function mergeFamily(int $familyId, int $userId, ?string $note = null): array
    {
        $family = DuplicateFamily::with('members')->find($familyId);

        if (! $family) {
            return ['success' => false, 'error' => 'Family not found.'];
        }

        if ($family->status !== 'active') {
            return ['success' => false, 'error' => 'Family has already been actioned.'];
        }

        $anchor = Customer::find($family->anchor_ref_id);
        if (! $anchor) {
            return ['success' => false, 'error' => 'Anchor customer no longer exists.'];
        }

        $mergeService = app(CustomerMergeService::class);
        $mergedCount = 0;

        foreach ($family->members as $member) {
            if ($member->is_anchor || ! $member->customer_id) {
                continue;
            }

            $source = Customer::find($member->customer_id);
            if (! $source) {
                continue;
            }

            $mergeService->merge($anchor, $source);
            $mergedCount++;
        }

        $family->update([
            'status' => 'merged',
            'merged_count' => $mergedCount,
            'actioned_by' => $userId,
            'actioned_at' => now(),
            'action_note' => $note,
        ]);

        return ['success' => true, 'merged_count' => $mergedCount];
    }

    /**
     * Dismiss a family without merging.
     */
    public function dismissFamily(int $familyId, int $userId, ?string $note = null): ?DuplicateFamily
    {
        $family = DuplicateFamily::find($familyId);

        if (! $family) {
            return null;
        }

        $family->update([
            'status' => 'dismissed',
            'actioned_by' => $userId,
            'actioned_at' => now(),
            'action_note' => $note,
        ]);

        return $family->fresh();
    }

    /**
     * Get summary stats for duplicate families.
     *
     * @return array<string, mixed>
     */
    public function getFamilyStats(): array
    {
        $total = DuplicateFamily::count();
        $active = DuplicateFamily::where('status', 'active')->count();
        $merged = DuplicateFamily::where('status', 'merged')->count();
        $dismissed = DuplicateFamily::where('status', 'dismissed')->count();

        $byMethod = DuplicateFamily::query()
            ->selectRaw('group_method, COUNT(*) as count')
            ->where('status', 'active')
            ->groupBy('group_method')
            ->pluck('count', 'group_method')
            ->toArray();

        $totalMembers = DuplicateFamilyMember::count();
        $activeMembers = DuplicateFamilyMember::query()
            ->whereIn('family_id', fn ($q) => $q->select('id')->from('duplicate_families')->where('status', 'active'))
            ->count();

        $avgFamilySize = $active > 0
            ? round(DuplicateFamilyMember::query()
                ->whereIn('family_id', fn ($q) => $q->select('id')->from('duplicate_families')->where('status', 'active'))
                ->count() / $active, 1)
            : 0;

        $largestFamily = DuplicateFamily::where('status', 'active')
            ->orderByDesc('member_count')
            ->first(['id', 'anchor_label', 'member_count']);

        return [
            'total' => $total,
            'active' => $active,
            'merged' => $merged,
            'dismissed' => $dismissed,
            'by_method' => $byMethod,
            'total_members' => $totalMembers,
            'active_members' => $activeMembers,
            'avg_family_size' => $avgFamilySize,
            'largest_family' => $largestFamily ? [
                'id' => $largestFamily->id,
                'label' => $largestFamily->anchor_label,
                'members' => $largestFamily->member_count,
            ] : null,
        ];
    }

    // ── Duplicate Notifications ──────────────────────────────────────

    /**
     * Create a single duplicate notification.
     *
     * @param  array<string, mixed>  $data
     */
    public function createNotification(array $data): DuplicateNotification
    {
        return DuplicateNotification::create([
            'user_id' => $data['user_id'] ?? null,
            'type' => $data['type'] ?? 'review_item',
            'severity' => $data['severity'] ?? 'medium',
            'title' => $data['title'] ?? 'Duplicate Detected',
            'message' => $data['message'] ?? '',
            'entity_type' => $data['entity_type'] ?? null,
            'entity_id' => $data['entity_id'] ?? null,
            'action_url' => $data['action_url'] ?? null,
            'action_label' => $data['action_label'] ?? null,
            'metadata' => $data['metadata'] ?? null,
        ]);
    }

    /**
     * Generate notifications from the current state of the duplicate
     * review queue, auto-merge suggestions, and families.
     *
     * - High-severity review items → notification for supervisors
     * - New auto-merge suggestions with confidence >= 90 → notification
     * - Large families (3+ members) → notification
     *
     * @param  int|null  $supervisorId  Target supervisor user ID, null = broadcast
     * @return array{created: int, skipped: int}
     */
    public function generateNotificationsFromScan(?int $supervisorId = null): array
    {
        $created = 0;
        $skipped = 0;

        // 1. High-severity pending review items
        $highSeverityItems = DuplicateReviewItem::query()
            ->where('status', 'pending')
            ->whereIn('severity', ['high', 'critical'])
            ->orderByDesc('created_at')
            ->limit(50)
            ->get();

        foreach ($highSeverityItems as $item) {
            $exists = DuplicateNotification::query()
                ->where('type', 'review_item')
                ->where('entity_type', $item->type)
                ->where('entity_id', $item->id)
                ->whereNull('read_at')
                ->when($supervisorId, fn ($q) => $q->where('user_id', $supervisorId))
                ->exists();

            if ($exists) {
                $skipped++;

                continue;
            }

            $this->createNotification([
                'user_id' => $supervisorId,
                'type' => 'review_item',
                'severity' => $item->severity,
                'title' => "High-severity duplicate: {$item->primary_label}",
                'message' => "Duplicate {$item->type} detected between \"{$item->primary_label}\" and \"{$item->duplicate_label}\" with {$item->severity} severity. Match method: {$item->match_method}.",
                'entity_type' => $item->type,
                'entity_id' => $item->id,
                'action_url' => '/shop/duplicate-review',
                'action_label' => 'Review Queue',
                'metadata' => [
                    'review_item_id' => $item->id,
                    'match_method' => $item->match_method,
                    'similarity_score' => $item->similarity_score,
                ],
            ]);
            $created++;
        }

        // 2. High-confidence auto-merge suggestions
        $highConfidenceSuggestions = AutoMergeSuggestion::query()
            ->where('status', 'pending')
            ->where('confidence_score', '>=', 90)
            ->orderByDesc('confidence_score')
            ->limit(50)
            ->get();

        foreach ($highConfidenceSuggestions as $suggestion) {
            $exists = DuplicateNotification::query()
                ->where('type', 'auto_merge')
                ->where('entity_id', $suggestion->id)
                ->whereNull('read_at')
                ->when($supervisorId, fn ($q) => $q->where('user_id', $supervisorId))
                ->exists();

            if ($exists) {
                $skipped++;

                continue;
            }

            $targetName = $suggestion->targetCustomer?->name ?? "Customer #{$suggestion->target_customer_id}";
            $sourceName = $suggestion->sourceCustomer?->name ?? "Customer #{$suggestion->source_customer_id}";

            $this->createNotification([
                'user_id' => $supervisorId,
                'type' => 'auto_merge',
                'severity' => 'high',
                'title' => "Auto-merge suggestion: {$targetName}",
                'message' => "High-confidence ({$suggestion->confidence_score}%) merge suggestion between \"{$targetName}\" and \"{$sourceName}\". Match reasons: ".implode(', ', $suggestion->match_reasons ?? []).'.',
                'entity_type' => 'customer',
                'entity_id' => $suggestion->id,
                'action_url' => '/shop/duplicate-review/auto-merge',
                'action_label' => 'Review Suggestions',
                'metadata' => [
                    'suggestion_id' => $suggestion->id,
                    'confidence_score' => $suggestion->confidence_score,
                    'match_reasons' => $suggestion->match_reasons,
                ],
            ]);
            $created++;
        }

        // 3. Large duplicate families (3+ members)
        $largeFamilies = DuplicateFamily::query()
            ->where('status', 'active')
            ->where('member_count', '>=', 3)
            ->orderByDesc('member_count')
            ->limit(20)
            ->get();

        foreach ($largeFamilies as $family) {
            $exists = DuplicateNotification::query()
                ->where('type', 'family')
                ->where('entity_id', $family->id)
                ->whereNull('read_at')
                ->when($supervisorId, fn ($q) => $q->where('user_id', $supervisorId))
                ->exists();

            if ($exists) {
                $skipped++;

                continue;
            }

            $this->createNotification([
                'user_id' => $supervisorId,
                'type' => 'family',
                'severity' => 'medium',
                'title' => "Duplicate family: {$family->anchor_label}",
                'message' => "Duplicate family with {$family->member_count} members detected via {$family->group_method}. Anchor: \"{$family->anchor_label}\". Consider merging to consolidate records.",
                'entity_type' => 'customer',
                'entity_id' => $family->id,
                'action_url' => '/shop/duplicate-review/families',
                'action_label' => 'View Families',
                'metadata' => [
                    'family_id' => $family->id,
                    'member_count' => $family->member_count,
                    'group_method' => $family->group_method,
                ],
            ]);
            $created++;
        }

        return [
            'created' => $created,
            'skipped' => $skipped,
        ];
    }

    /**
     * Get paginated notifications with filters.
     *
     * @param  array{user_id?: int, type?: string, severity?: string, unread_only?: bool, per_page?: int}  $filters
     * @return array<string, mixed>
     */
    public function getNotifications(array $filters = []): array
    {
        $query = DuplicateNotification::query()
            ->with('reader:id,name')
            ->orderByDesc('created_at');

        if (! empty($filters['user_id'])) {
            $query->where(function ($q) use ($filters) {
                $q->where('user_id', $filters['user_id'])
                    ->orWhereNull('user_id');
            });
        }

        if (! empty($filters['type'])) {
            $query->where('type', $filters['type']);
        }

        if (! empty($filters['severity'])) {
            $query->where('severity', $filters['severity']);
        }

        if (! empty($filters['unread_only'])) {
            $query->whereNull('read_at');
        }

        $perPage = $filters['per_page'] ?? 25;
        $items = $query->paginate($perPage);

        return [
            'items' => $items->items(),
            'meta' => [
                'current_page' => $items->currentPage(),
                'last_page' => $items->lastPage(),
                'per_page' => $items->perPage(),
                'total' => $items->total(),
                'from' => $items->firstItem(),
                'to' => $items->lastItem(),
            ],
        ];
    }

    /**
     * Mark a single notification as read.
     */
    public function markNotificationRead(int $notificationId, int $userId): ?DuplicateNotification
    {
        $notification = DuplicateNotification::find($notificationId);

        if (! $notification) {
            return null;
        }

        if ($notification->read_at === null) {
            $notification->update([
                'read_at' => now(),
                'read_by' => $userId,
            ]);
        }

        return $notification->fresh();
    }

    /**
     * Mark all unread notifications as read for a user (including broadcasts).
     *
     * @return int Number of notifications marked read
     */
    public function markAllNotificationsRead(int $userId): int
    {
        return DuplicateNotification::query()
            ->whereNull('read_at')
            ->where(function ($q) use ($userId) {
                $q->where('user_id', $userId)
                    ->orWhereNull('user_id');
            })
            ->update([
                'read_at' => now(),
                'read_by' => $userId,
            ]);
    }

    /**
     * Get notification summary stats.
     *
     * @param  int|null  $userId  Filter to user + broadcasts, null = all
     * @return array<string, mixed>
     */
    public function getNotificationStats(?int $userId = null): array
    {
        $query = DuplicateNotification::query();
        $unreadQuery = DuplicateNotification::query()->whereNull('read_at');

        if ($userId) {
            $query->where(function ($q) use ($userId) {
                $q->where('user_id', $userId)->orWhereNull('user_id');
            });
            $unreadQuery->where(function ($q) use ($userId) {
                $q->where('user_id', $userId)->orWhereNull('user_id');
            });
        }

        $total = $query->count();
        $unread = $unreadQuery->count();

        $byType = (clone $query)
            ->selectRaw('type, COUNT(*) as count')
            ->groupBy('type')
            ->pluck('count', 'type')
            ->toArray();

        $bySeverity = (clone $query)
            ->selectRaw('severity, COUNT(*) as count')
            ->groupBy('severity')
            ->pluck('count', 'severity')
            ->toArray();

        $unreadByType = (clone $unreadQuery)
            ->selectRaw('type, COUNT(*) as count')
            ->groupBy('type')
            ->pluck('count', 'type')
            ->toArray();

        $unreadBySeverity = (clone $unreadQuery)
            ->selectRaw('severity, COUNT(*) as count')
            ->groupBy('severity')
            ->pluck('count', 'severity')
            ->toArray();

        $recentCount = (clone $query)
            ->where('created_at', '>=', Carbon::now()->subDays(7))
            ->count();

        return [
            'total' => $total,
            'unread' => $unread,
            'recent_7d' => $recentCount,
            'by_type' => $byType,
            'by_severity' => $bySeverity,
            'unread_by_type' => $unreadByType,
            'unread_by_severity' => $unreadBySeverity,
        ];
    }

    // ── Duplicate Audit Log ──────────────────────────────────────────

    /**
     * Record an audit log entry.
     *
     * @param  array<string, mixed>  $data
     */
    public function logAction(array $data): DuplicateAuditLog
    {
        return DuplicateAuditLog::create([
            'user_id' => $data['user_id'] ?? null,
            'action' => $data['action'],
            'entity_type' => $data['entity_type'] ?? null,
            'entity_id' => $data['entity_id'] ?? null,
            'entity_label' => $data['entity_label'] ?? null,
            'before_state' => $data['before_state'] ?? null,
            'after_state' => $data['after_state'] ?? null,
            'note' => $data['note'] ?? null,
            'ip_address' => $data['ip_address'] ?? null,
            'user_agent' => $data['user_agent'] ?? null,
        ]);
    }

    /**
     * Get paginated audit logs with filters.
     *
     * @param  array{user_id?: int, action?: string, entity_type?: string, entity_id?: int, from?: string, to?: string, per_page?: int}  $filters
     * @return array<string, mixed>
     */
    public function getAuditLogs(array $filters = []): array
    {
        $query = DuplicateAuditLog::query()
            ->with('user:id,name')
            ->orderByDesc('created_at');

        if (! empty($filters['user_id'])) {
            $query->where('user_id', $filters['user_id']);
        }

        if (! empty($filters['action'])) {
            $query->where('action', $filters['action']);
        }

        if (! empty($filters['entity_type'])) {
            $query->where('entity_type', $filters['entity_type']);
        }

        if (! empty($filters['entity_id'])) {
            $query->where('entity_id', $filters['entity_id']);
        }

        if (! empty($filters['from'])) {
            $query->where('created_at', '>=', $filters['from']);
        }

        if (! empty($filters['to'])) {
            $query->where('created_at', '<=', $filters['to']);
        }

        $perPage = $filters['per_page'] ?? 50;
        $items = $query->paginate($perPage);

        return [
            'items' => $items->items(),
            'meta' => [
                'current_page' => $items->currentPage(),
                'last_page' => $items->lastPage(),
                'per_page' => $items->perPage(),
                'total' => $items->total(),
                'from' => $items->firstItem(),
                'to' => $items->lastItem(),
            ],
        ];
    }

    /**
     * Get audit log summary stats.
     *
     * @param  int  $days  Lookback period
     * @return array<string, mixed>
     */
    public function getAuditLogStats(int $days = 30): array
    {
        $cutoff = Carbon::now()->subDays($days);

        $total = DuplicateAuditLog::where('created_at', '>=', $cutoff)->count();

        $byAction = DuplicateAuditLog::query()
            ->where('created_at', '>=', $cutoff)
            ->selectRaw('action, COUNT(*) as count')
            ->groupBy('action')
            ->orderByDesc('count')
            ->pluck('count', 'action')
            ->toArray();

        $byEntityType = DuplicateAuditLog::query()
            ->where('created_at', '>=', $cutoff)
            ->selectRaw('entity_type, COUNT(*) as count')
            ->groupBy('entity_type')
            ->orderByDesc('count')
            ->pluck('count', 'entity_type')
            ->toArray();

        $byUser = DuplicateAuditLog::query()
            ->where('created_at', '>=', $cutoff)
            ->whereNotNull('user_id')
            ->selectRaw('user_id, COUNT(*) as count')
            ->groupBy('user_id')
            ->orderByDesc('count')
            ->limit(10)
            ->pluck('count', 'user_id')
            ->toArray();

        $topUsers = [];
        if (! empty($byUser)) {
            $users = User::whereIn('id', array_keys($byUser))->pluck('name', 'id');
            foreach ($byUser as $uid => $count) {
                $topUsers[] = [
                    'user_id' => $uid,
                    'name' => $users[$uid] ?? "User #{$uid}",
                    'count' => $count,
                ];
            }
        }

        $merges = $byAction['merge'] ?? 0;
        $autoMergeApproves = $byAction['auto_merge_approve'] ?? 0;
        $familyMerges = $byAction['family_merge'] ?? 0;
        $totalMerges = $merges + $autoMergeApproves + $familyMerges;

        $dismissals = ($byAction['dismiss'] ?? 0) + ($byAction['auto_merge_reject'] ?? 0) + ($byAction['family_dismiss'] ?? 0);

        $dailyTrend = DuplicateAuditLog::query()
            ->where('created_at', '>=', $cutoff)
            ->selectRaw('DATE(created_at) as date, COUNT(*) as count')
            ->groupBy('date')
            ->orderBy('date')
            ->pluck('count', 'date')
            ->toArray();

        return [
            'total' => $total,
            'days' => $days,
            'total_merges' => $totalMerges,
            'total_dismissals' => $dismissals,
            'by_action' => $byAction,
            'by_entity_type' => $byEntityType,
            'top_users' => $topUsers,
            'daily_trend' => $dailyTrend,
        ];
    }

    /**
     * Export audit logs as CSV.
     *
     * @param  array<string, mixed>  $filters
     * @return string CSV content
     */
    public function exportAuditLogsCsv(array $filters = []): string
    {
        $query = DuplicateAuditLog::query()
            ->with('user:id,name')
            ->orderByDesc('created_at');

        if (! empty($filters['action'])) {
            $query->where('action', $filters['action']);
        }

        if (! empty($filters['entity_type'])) {
            $query->where('entity_type', $filters['entity_type']);
        }

        if (! empty($filters['from'])) {
            $query->where('created_at', '>=', $filters['from']);
        }

        if (! empty($filters['to'])) {
            $query->where('created_at', '<=', $filters['to']);
        }

        $logs = $query->limit(5000)->get();

        $rows = [];
        $rows[] = ['ID', 'Timestamp', 'User', 'Action', 'Entity Type', 'Entity ID', 'Entity Label', 'Note', 'IP Address'];

        foreach ($logs as $log) {
            $rows[] = [
                $log->id,
                $log->created_at?->toIso8601String(),
                $log->user?->name ?? 'System',
                $log->action,
                $log->entity_type ?? '',
                $log->entity_id ?? '',
                $log->entity_label ?? '',
                $log->note ?? '',
                $log->ip_address ?? '',
            ];
        }

        $csv = '';
        foreach ($rows as $row) {
            $csv .= implode(',', array_map(fn ($v) => '"'.str_replace('"', '""', (string) $v).'"', $row))."\n";
        }

        return $csv;
    }

    // ── Duplicate Export ─────────────────────────────────────────────

    /**
     * Export review queue items as CSV.
     *
     * @param  array{type?: string, status?: string, severity?: string}  $filters
     */
    public function exportReviewQueueCsv(array $filters = []): string
    {
        $query = DuplicateReviewItem::query()
            ->with('reviewer:id,name')
            ->orderByDesc('severity')
            ->orderByDesc('created_at');

        if (! empty($filters['type'])) {
            $query->where('type', $filters['type']);
        }
        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }
        if (! empty($filters['severity'])) {
            $query->where('severity', $filters['severity']);
        }

        $items = $query->limit(5000)->get();

        $rows = [];
        $rows[] = ['ID', 'Type', 'Primary Label', 'Duplicate Label', 'Match Method', 'Similarity Score', 'Severity', 'Status', 'Reviewer', 'Reviewed At', 'Review Note', 'Created At'];

        foreach ($items as $item) {
            $rows[] = [
                $item->id,
                $item->type,
                $item->primary_label,
                $item->duplicate_label,
                $item->match_method,
                number_format((float) ($item->similarity_score ?? 0), 2),
                $item->severity,
                $item->status,
                $item->reviewer?->name ?? '',
                $item->reviewed_at?->toIso8601String() ?? '',
                $item->review_note ?? '',
                $item->created_at?->toIso8601String() ?? '',
            ];
        }

        return $this->buildCsv($rows);
    }

    /**
     * Export auto-merge suggestions as CSV.
     *
     * @param  array{status?: string, min_confidence?: float}  $filters
     */
    public function exportAutoMergeSuggestionsCsv(array $filters = []): string
    {
        $query = AutoMergeSuggestion::query()
            ->with([
                'targetCustomer:id,name,phone,normalized_phone,total_orders,total_revenue',
                'sourceCustomer:id,name,phone,normalized_phone,total_orders,total_revenue',
                'actioner:id,name',
            ])
            ->orderByDesc('confidence_score')
            ->orderByDesc('created_at');

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }
        if (! empty($filters['min_confidence'])) {
            $query->where('confidence_score', '>=', (float) $filters['min_confidence']);
        }

        $items = $query->limit(5000)->get();

        $rows = [];
        $rows[] = ['ID', 'Target Customer', 'Target Phone', 'Target Orders', 'Target Revenue', 'Source Customer', 'Source Phone', 'Source Orders', 'Source Revenue', 'Confidence Score', 'Match Reasons', 'Status', 'Actioned By', 'Actioned At', 'Action Note', 'Created At'];

        foreach ($items as $item) {
            $rows[] = [
                $item->id,
                $item->targetCustomer?->name ?? "Customer #{$item->target_customer_id}",
                $item->targetCustomer?->phone ?? '',
                (int) ($item->targetCustomer?->total_orders ?? 0),
                (float) ($item->targetCustomer?->total_revenue ?? 0),
                $item->sourceCustomer?->name ?? "Customer #{$item->source_customer_id}",
                $item->sourceCustomer?->phone ?? '',
                (int) ($item->sourceCustomer?->total_orders ?? 0),
                (float) ($item->sourceCustomer?->total_revenue ?? 0),
                number_format((float) ($item->confidence_score ?? 0), 2),
                is_array($item->match_reasons) ? implode('; ', $item->match_reasons) : '',
                $item->status,
                $item->actioner?->name ?? '',
                $item->actioned_at?->toIso8601String() ?? '',
                $item->action_note ?? '',
                $item->created_at?->toIso8601String() ?? '',
            ];
        }

        return $this->buildCsv($rows);
    }

    /**
     * Export duplicate families as CSV.
     *
     * @param  array{status?: string, method?: string, min_members?: int}  $filters
     */
    public function exportFamiliesCsv(array $filters = []): string
    {
        $query = DuplicateFamily::query()
            ->withCount('members')
            ->with('actioner:id,name')
            ->orderByDesc('member_count')
            ->orderByDesc('created_at');

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }
        if (! empty($filters['method'])) {
            $query->where('group_method', $filters['method']);
        }

        $items = $query->limit(5000)->get();

        $rows = [];
        $rows[] = ['ID', 'Type', 'Group Key', 'Group Method', 'Anchor Label', 'Member Count', 'Merged Count', 'Status', 'Actioned By', 'Actioned At', 'Action Note', 'Created At'];

        foreach ($items as $item) {
            $rows[] = [
                $item->id,
                $item->type,
                $item->group_key,
                $item->group_method,
                $item->anchor_label,
                $item->member_count,
                $item->merged_count ?? 0,
                $item->status,
                $item->actioner?->name ?? '',
                $item->actioned_at?->toIso8601String() ?? '',
                $item->action_note ?? '',
                $item->created_at?->toIso8601String() ?? '',
            ];
        }

        return $this->buildCsv($rows);
    }

    /**
     * Export cross-page duplicates as CSV.
     */
    public function exportCrossPageCsv(): string
    {
        $result = $this->scanCrossPageDuplicates(5000);

        $rows = [];
        $rows[] = ['Type', 'Key', 'Label', 'Page Count', 'Order Count', 'Conversation Count', 'Customer ID', 'Customer Phone', 'Severity', 'Pages'];

        foreach ($result['groups'] as $group) {
            $pagesSummary = collect($group['pages'])
                ->map(fn ($p) => $p['page_name'].' ('.($p['order_count'] ?? $p['conversation_count'] ?? $p['identity_count'] ?? 0).')')
                ->implode('; ');

            $rows[] = [
                $group['type'],
                $group['key'],
                $group['label'],
                $group['page_count'],
                $group['order_count'] ?? '',
                $group['conversation_count'] ?? '',
                $group['customer_id'] ?? '',
                $group['customer_phone'] ?? '',
                $group['severity'],
                $pagesSummary,
            ];
        }

        return $this->buildCsv($rows);
    }

    /**
     * Export a combined duplicate report with all sections.
     */
    public function exportAllDuplicatesCsv(): string
    {
        $csv = '';

        $csv .= "DUPLICATE REVIEW QUEUE\n";
        $csv .= $this->exportReviewQueueCsv([]);
        $csv .= "\n\n";

        $csv .= "AUTO-MERGE SUGGESTIONS\n";
        $csv .= $this->exportAutoMergeSuggestionsCsv([]);
        $csv .= "\n\n";

        $csv .= "DUPLICATE FAMILIES\n";
        $csv .= $this->exportFamiliesCsv([]);
        $csv .= "\n\n";

        $csv .= "CROSS-PAGE DUPLICATES\n";
        $csv .= $this->exportCrossPageCsv();
        $csv .= "\n\n";

        $csv .= "AUDIT LOGS\n";
        $csv .= $this->exportAuditLogsCsv([]);

        return $csv;
    }

    /**
     * Build CSV from array of rows.
     *
     * @param  array<int, array<int, mixed>>  $rows
     */
    private function buildCsv(array $rows): string
    {
        $csv = '';
        foreach ($rows as $row) {
            $csv .= implode(',', array_map(fn ($v) => '"'.str_replace('"', '""', (string) $v).'"', $row))."\n";
        }

        return $csv;
    }

    // ── Cross-Page Duplicate Detection ───────────────────────────────

    /**
     * Detect cross-page duplicates for a given phone or PSID.
     *
     * Finds orders, conversations, or customer identities that span
     * multiple Facebook pages for the same customer.
     *
     * @param  array{phone?: string, psid?: string, time_window_hours?: int}  $params
     * @return array<string, mixed>
     */
    public function detectCrossPageDuplicates(array $params): array
    {
        $results = [
            'is_duplicate' => false,
            'phone' => $params['phone'] ?? null,
            'psid' => $params['psid'] ?? null,
            'cross_page_orders' => [],
            'cross_page_conversations' => [],
            'cross_page_identities' => [],
            'page_count' => 0,
            'severity' => 'none',
        ];

        $pageIds = collect();

        // Cross-page orders by phone
        if (! empty($params['phone'])) {
            $normalizedPhone = $this->phones->normalize($params['phone']);
            $windowHours = $params['time_window_hours'] ?? $this->defaultTimeWindowHours;
            $cutoff = Carbon::now()->subHours($windowHours);

            $orders = Order::query()
                ->with(['product:id,name', 'facebookPage:id,page_id,page_name'])
                ->where('receiver_phone', $normalizedPhone)
                ->where('created_at', '>=', $cutoff)
                ->whereNotIn('status', array_map(fn ($s) => $s->value, $this->excludedStatuses))
                ->whereNotNull('facebook_page_id')
                ->latest()
                ->limit(50)
                ->get();

            $ordersByPage = $orders->groupBy('facebook_page_id');

            if ($ordersByPage->count() > 1) {
                $results['cross_page_orders'] = $ordersByPage->map(function ($pageOrders, $pageId) {
                    $page = $pageOrders->first()->facebookPage;

                    return [
                        'facebook_page_id' => (int) $pageId,
                        'page_name' => $page?->page_name ?? "Page #{$pageId}",
                        'order_count' => $pageOrders->count(),
                        'orders' => $pageOrders->map(fn (Order $o) => [
                            'order_id' => $o->id,
                            'order_number' => $o->order_number,
                            'status' => $o->status->value,
                            'product_name' => $o->product?->name ?? 'Unknown',
                            'total_amount' => (float) $o->total_amount,
                            'created_at' => $o->created_at?->toIso8601String(),
                            'created_at_formatted' => $o->created_at?->format('M j, Y g:i A'),
                        ])->values()->toArray(),
                    ];
                })->values()->toArray();

                $pageIds = $pageIds->merge($ordersByPage->keys());
            }
        }

        // Cross-page conversations by PSID
        if (! empty($params['psid'])) {
            $identities = CustomerIdentity::query()
                ->where('provider', 'facebook')
                ->where('provider_user_id', $params['psid'])
                ->with(['facebookPage:id,page_id,page_name', 'conversations' => function ($q) {
                    $q->whereIn('status', Conversation::ACTIVE_STATUSES)
                        ->whereNull('merged_into_id')
                        ->select('id', 'customer_identity_id', 'status', 'last_message_at', 'last_message_preview', 'created_at');
                }])
                ->get();

            $identitiesByPage = $identities->groupBy('facebook_page_id');

            if ($identitiesByPage->count() > 1) {
                $results['cross_page_conversations'] = $identitiesByPage->map(function ($pageIdentities, $pageId) {
                    $page = $pageIdentities->first()->facebookPage;
                    $conversations = $pageIdentities->flatMap->conversations;

                    return [
                        'facebook_page_id' => (int) $pageId,
                        'page_name' => $page?->page_name ?? "Page #{$pageId}",
                        'conversation_count' => $conversations->count(),
                        'conversations' => $conversations->map(fn ($conv) => [
                            'conversation_id' => $conv->id,
                            'status' => $conv->status,
                            'last_message_at' => $conv->last_message_at?->toIso8601String(),
                            'last_message_preview' => $conv->last_message_preview,
                            'created_at' => $conv->created_at?->toIso8601String(),
                        ])->values()->toArray(),
                    ];
                })->values()->toArray();

                $pageIds = $pageIds->merge($identitiesByPage->keys());
            }

            $results['cross_page_identities'] = $identities->map(fn ($identity) => [
                'identity_id' => $identity->id,
                'facebook_page_id' => $identity->facebook_page_id,
                'page_name' => $identity->facebookPage?->page_name ?? "Page #{$identity->facebook_page_id}",
                'display_name' => $identity->display_name,
                'customer_id' => $identity->customer_id,
                'phone_detected' => $identity->phone_detected,
            ])->values()->toArray();
        }

        $pageCount = $pageIds->unique()->count();
        $results['page_count'] = $pageCount;
        $results['is_duplicate'] = $pageCount > 1;
        $results['severity'] = $this->determineCrossPageSeverity($pageCount);

        return $results;
    }

    /**
     * Scan for all cross-page duplicates across the system.
     *
     * @param  int  $limit  Max number of cross-page groups to return
     * @return array<string, mixed>
     */
    public function scanCrossPageDuplicates(int $limit = 100): array
    {
        $crossPageGroups = [];

        // 1. Cross-page orders: group by receiver_phone, find phones with orders on multiple pages
        $cutoff = Carbon::now()->subHours($this->defaultTimeWindowHours);

        $phonePageGroups = Order::query()
            ->where('created_at', '>=', $cutoff)
            ->whereNotIn('status', array_map(fn ($s) => $s->value, $this->excludedStatuses))
            ->whereNotNull('facebook_page_id')
            ->whereNotNull('receiver_phone')
            ->selectRaw('receiver_phone, COUNT(DISTINCT facebook_page_id) as page_count, COUNT(*) as order_count')
            ->groupBy('receiver_phone')
            ->havingRaw('page_count > 1')
            ->orderByDesc('order_count')
            ->limit($limit)
            ->get();

        foreach ($phonePageGroups as $group) {
            $orders = Order::query()
                ->with(['product:id,name', 'facebookPage:id,page_id,page_name'])
                ->where('receiver_phone', $group->receiver_phone)
                ->where('created_at', '>=', $cutoff)
                ->whereNotIn('status', array_map(fn ($s) => $s->value, $this->excludedStatuses))
                ->whereNotNull('facebook_page_id')
                ->latest()
                ->get();

            $pages = $orders->groupBy('facebook_page_id')->map(fn ($pageOrders, $pageId) => [
                'facebook_page_id' => (int) $pageId,
                'page_name' => $pageOrders->first()->facebookPage?->page_name ?? "Page #{$pageId}",
                'order_count' => $pageOrders->count(),
                'first_order_at' => $pageOrders->last()?->created_at?->toIso8601String(),
                'latest_order_at' => $pageOrders->first()?->created_at?->toIso8601String(),
            ])->values()->toArray();

            $crossPageGroups[] = [
                'type' => 'order',
                'key' => $group->receiver_phone,
                'label' => "Phone: {$group->receiver_phone}",
                'page_count' => (int) $group->page_count,
                'order_count' => (int) $group->order_count,
                'pages' => $pages,
                'severity' => $this->determineCrossPageSeverity((int) $group->page_count),
            ];
        }

        // 2. Cross-page conversations: PSIDs with conversations on multiple pages
        $psidPageGroups = CustomerIdentity::query()
            ->where('provider', 'facebook')
            ->whereNotNull('facebook_page_id')
            ->selectRaw('provider_user_id, COUNT(DISTINCT facebook_page_id) as page_count')
            ->groupBy('provider_user_id')
            ->havingRaw('page_count > 1')
            ->orderByDesc('page_count')
            ->limit($limit)
            ->get();

        foreach ($psidPageGroups as $group) {
            $identities = CustomerIdentity::query()
                ->where('provider', 'facebook')
                ->where('provider_user_id', $group->provider_user_id)
                ->with(['facebookPage:id,page_id,page_name', 'conversations' => function ($q) {
                    $q->whereIn('status', Conversation::ACTIVE_STATUSES)
                        ->whereNull('merged_into_id');
                }])
                ->get();

            $pages = $identities->groupBy('facebook_page_id')->map(fn ($pageIdentities, $pageId) => [
                'facebook_page_id' => (int) $pageId,
                'page_name' => $pageIdentities->first()->facebookPage?->page_name ?? "Page #{$pageId}",
                'conversation_count' => $pageIdentities->sum(fn ($i) => $i->conversations->count()),
                'display_name' => $pageIdentities->first()?->display_name,
            ])->values()->toArray();

            $totalConversations = $identities->sum(fn ($i) => $i->conversations->count());

            if ($totalConversations > 0) {
                $crossPageGroups[] = [
                    'type' => 'conversation',
                    'key' => $group->provider_user_id,
                    'label' => "PSID: {$group->provider_user_id}",
                    'page_count' => (int) $group->page_count,
                    'conversation_count' => $totalConversations,
                    'pages' => $pages,
                    'severity' => $this->determineCrossPageSeverity((int) $group->page_count),
                ];
            }
        }

        // 3. Cross-page customers: customers with identities on multiple pages
        $customerPageGroups = CustomerIdentity::query()
            ->where('provider', 'facebook')
            ->whereNotNull('customer_id')
            ->whereNotNull('facebook_page_id')
            ->selectRaw('customer_id, COUNT(DISTINCT facebook_page_id) as page_count')
            ->groupBy('customer_id')
            ->havingRaw('page_count > 1')
            ->orderByDesc('page_count')
            ->limit($limit)
            ->get();

        foreach ($customerPageGroups as $group) {
            $customer = Customer::find($group->customer_id);
            if (! $customer) {
                continue;
            }

            $identities = CustomerIdentity::query()
                ->where('provider', 'facebook')
                ->where('customer_id', $group->customer_id)
                ->with('facebookPage:id,page_id,page_name')
                ->get();

            $pages = $identities->groupBy('facebook_page_id')->map(fn ($pageIdentities, $pageId) => [
                'facebook_page_id' => (int) $pageId,
                'page_name' => $pageIdentities->first()->facebookPage?->page_name ?? "Page #{$pageId}",
                'identity_count' => $pageIdentities->count(),
            ])->values()->toArray();

            $crossPageGroups[] = [
                'type' => 'customer',
                'key' => (string) $group->customer_id,
                'label' => $customer->name ?? "Customer #{$group->customer_id}",
                'customer_id' => $group->customer_id,
                'customer_phone' => $customer->normalized_phone ?? $customer->phone,
                'page_count' => (int) $group->page_count,
                'pages' => $pages,
                'severity' => $this->determineCrossPageSeverity((int) $group->page_count),
            ];
        }

        // Sort by page_count desc, then by type
        usort($crossPageGroups, fn ($a, $b) => $b['page_count'] <=> $a['page_count']);

        return [
            'total_groups' => count($crossPageGroups),
            'groups' => array_slice($crossPageGroups, 0, $limit),
        ];
    }

    /**
     * Get cross-page duplicate stats.
     *
     * @return array<string, mixed>
     */
    public function getCrossPageStats(): array
    {
        $cutoff = Carbon::now()->subHours($this->defaultTimeWindowHours);

        // Cross-page order groups
        $crossPageOrderPhones = Order::query()
            ->where('created_at', '>=', $cutoff)
            ->whereNotIn('status', array_map(fn ($s) => $s->value, $this->excludedStatuses))
            ->whereNotNull('facebook_page_id')
            ->whereNotNull('receiver_phone')
            ->selectRaw('receiver_phone, COUNT(DISTINCT facebook_page_id) as page_count')
            ->groupBy('receiver_phone')
            ->havingRaw('page_count > 1')
            ->count();

        // Cross-page conversation PSIDs
        $crossPagePsids = CustomerIdentity::query()
            ->where('provider', 'facebook')
            ->whereNotNull('facebook_page_id')
            ->selectRaw('provider_user_id, COUNT(DISTINCT facebook_page_id) as page_count')
            ->groupBy('provider_user_id')
            ->havingRaw('page_count > 1')
            ->count();

        // Cross-page customers
        $crossPageCustomers = CustomerIdentity::query()
            ->where('provider', 'facebook')
            ->whereNotNull('customer_id')
            ->whereNotNull('facebook_page_id')
            ->selectRaw('customer_id, COUNT(DISTINCT facebook_page_id) as page_count')
            ->groupBy('customer_id')
            ->havingRaw('page_count > 1')
            ->count();

        // Total affected pages
        $affectedPages = FacebookPage::query()
            ->whereHas('conversations', function ($q) {
                $q->whereIn('status', Conversation::ACTIVE_STATUSES)
                    ->whereNull('merged_into_id');
            })
            ->orWhereHas('orders', function ($q) use ($cutoff) {
                $q->where('created_at', '>=', $cutoff)
                    ->whereNotIn('status', array_map(fn ($s) => $s->value, $this->excludedStatuses));
            })
            ->count();

        // Top pages by cross-page order volume
        $topPages = Order::query()
            ->where('created_at', '>=', $cutoff)
            ->whereNotIn('status', array_map(fn ($s) => $s->value, $this->excludedStatuses))
            ->whereNotNull('facebook_page_id')
            ->selectRaw('facebook_page_id, COUNT(*) as order_count, COUNT(DISTINCT receiver_phone) as unique_phones')
            ->groupBy('facebook_page_id')
            ->orderByDesc('order_count')
            ->limit(10)
            ->get()
            ->map(function ($row) {
                $page = FacebookPage::find($row->facebook_page_id);

                return [
                    'facebook_page_id' => $row->facebook_page_id,
                    'page_name' => $page?->page_name ?? "Page #{$row->facebook_page_id}",
                    'order_count' => $row->order_count,
                    'unique_phones' => $row->unique_phones,
                ];
            })
            ->toArray();

        return [
            'cross_page_order_phones' => $crossPageOrderPhones,
            'cross_page_psids' => $crossPagePsids,
            'cross_page_customers' => $crossPageCustomers,
            'affected_pages' => $affectedPages,
            'total_pages' => FacebookPage::count(),
            'top_pages' => $topPages,
        ];
    }

    /**
     * Determine severity for cross-page duplicates based on page count.
     *
     * @return string 'none', 'low', 'medium', 'high'
     */
    private function determineCrossPageSeverity(int $pageCount): string
    {
        if ($pageCount <= 1) {
            return 'none';
        }

        if ($pageCount >= 4) {
            return 'high';
        }

        if ($pageCount >= 3) {
            return 'medium';
        }

        return 'low';
    }

    // ── ML-Based Duplicate Scoring ───────────────────────────────────

    private array $defaultFeatureWeights = [
        'phone_exact' => 2.5,
        'phone_normalized' => 2.0,
        'psid_shared' => 2.2,
        'name_exact' => 1.5,
        'name_fuzzy' => 1.0,
        'address_exact' => 1.2,
        'address_fuzzy' => 0.8,
        'barangay_match' => 0.5,
        'city_match' => 0.4,
        'province_match' => 0.3,
        'order_overlap' => 0.6,
        'account_age_similarity' => 0.3,
        'risk_level_match' => 0.2,
    ];

    public function extractFeatures(Customer $a, Customer $b): array
    {
        $features = [];
        $features['phone_exact'] = ($a->phone && $b->phone && $a->phone === $b->phone) ? 1.0 : 0.0;
        $features['phone_normalized'] = ($a->normalized_phone && $b->normalized_phone && $a->normalized_phone === $b->normalized_phone) ? 1.0 : 0.0;

        $sharedPsids = CustomerIdentity::query()
            ->whereIn('customer_id', [$a->id, $b->id])
            ->where('provider', 'facebook')
            ->selectRaw('provider_user_id, COUNT(DISTINCT customer_id) as cust_count')
            ->groupBy('provider_user_id')
            ->havingRaw('cust_count > 1')
            ->count();
        $features['psid_shared'] = $sharedPsids > 0 ? 1.0 : 0.0;

        $nameA = strtolower($a->name ?? $a->facebook_name ?? '');
        $nameB = strtolower($b->name ?? $b->facebook_name ?? '');
        $features['name_exact'] = ($nameA && $nameB && $nameA === $nameB) ? 1.0 : 0.0;

        $nameScore = 0.0;
        if ($nameA && $nameB) {
            similar_text($nameA, $nameB, $percent);
            $nameScore = $percent / 100.0;
        }
        $features['name_fuzzy'] = $nameScore;

        $addrA = strtolower($a->canonical_address ?? '');
        $addrB = strtolower($b->canonical_address ?? '');
        $features['address_exact'] = ($addrA && $addrB && $addrA === $addrB) ? 1.0 : 0.0;
        $features['address_fuzzy'] = $this->jaccardSimilarity($addrA, $addrB);

        $features['barangay_match'] = ($a->barangay && $b->barangay && $a->barangay === $b->barangay) ? 1.0 : 0.0;
        $features['city_match'] = ($a->city_municipality && $b->city_municipality && $a->city_municipality === $b->city_municipality) ? 1.0 : 0.0;
        $features['province_match'] = ($a->province && $b->province && $a->province === $b->province) ? 1.0 : 0.0;

        $productsA = Order::where('customer_id', $a->id)->whereNotNull('product_id')->pluck('product_id')->unique();
        $productsB = Order::where('customer_id', $b->id)->whereNotNull('product_id')->pluck('product_id')->unique();
        if ($productsA->isNotEmpty() && $productsB->isNotEmpty()) {
            $intersection = $productsA->intersect($productsB)->count();
            $union = $productsA->merge($productsB)->unique()->count();
            $features['order_overlap'] = $union > 0 ? $intersection / $union : 0.0;
        } else {
            $features['order_overlap'] = 0.0;
        }

        $ageA = $a->created_at?->diffInDays(now()) ?? 0;
        $ageB = $b->created_at?->diffInDays(now()) ?? 0;
        $maxAge = max($ageA, $ageB, 1);
        $features['account_age_similarity'] = 1.0 - (abs($ageA - $ageB) / $maxAge);

        $features['risk_level_match'] = ($a->risk_level && $b->risk_level && $a->risk_level === $b->risk_level) ? 1.0 : 0.0;

        return $features;
    }

    public function scorePair(Customer $a, Customer $b): array
    {
        $features = $this->extractFeatures($a, $b);
        $weights = $this->getActiveFeatureWeights();

        $z = 0.0;
        $contributions = [];
        foreach ($features as $key => $value) {
            $weight = $weights[$key] ?? 0.0;
            $contribution = $weight * $value;
            $z += $contribution;
            $contributions[$key] = round($contribution, 4);
        }

        $score = 1.0 / (1.0 + exp(-$z));
        $scorePercent = round($score * 100, 2);
        arsort($contributions);

        return [
            'score' => $scorePercent,
            'features' => $features,
            'feature_contributions' => $contributions,
        ];
    }

    private function jaccardSimilarity(string $a, string $b): float
    {
        $tokensA = array_filter(preg_split('/\s+/', strtolower($a)) ?: [], fn ($t) => strlen($t) >= 2);
        $tokensB = array_filter(preg_split('/\s+/', strtolower($b)) ?: [], fn ($t) => strlen($t) >= 2);

        if (empty($tokensA) || empty($tokensB)) {
            return 0.0;
        }

        $intersection = count(array_intersect($tokensA, $tokensB));
        $union = count(array_unique(array_merge($tokensA, $tokensB)));

        return $union > 0 ? $intersection / $union : 0.0;
    }

    private function getActiveFeatureWeights(): array
    {
        $model = DuplicateMlModel::where('name', 'default')->where('is_active', true)->latest()->first();

        if ($model && $model->feature_weights) {
            return $model->feature_weights;
        }

        return $this->defaultFeatureWeights;
    }

    private function getActiveModelVersion(): string
    {
        $model = DuplicateMlModel::where('name', 'default')->where('is_active', true)->latest()->first();

        if ($model) {
            return $model->version.' (trained '.$model->trained_at?->diffForHumans().')';
        }

        return 'default (untrained)';
    }

    /**
     * Score a batch of customer pairs.
     *
     * @param  array<array{customer_a: int, customer_b: int}>  $pairs
     * @return array<int, array<string, mixed>>
     */
    public function scoreBatch(array $pairs): array
    {
        $results = [];
        $customerIds = collect();
        foreach ($pairs as $pair) {
            $customerIds = $customerIds->merge([$pair['customer_a'], $pair['customer_b']]);
        }
        $customers = Customer::whereIn('id', $customerIds->unique()->values()->all())->get()->keyBy('id');

        foreach ($pairs as $pair) {
            $a = $customers->get($pair['customer_a']);
            $b = $customers->get($pair['customer_b']);

            if (! $a || ! $b) {
                continue;
            }

            $result = $this->scorePair($a, $b);
            $results[] = [
                'customer_a_id' => $a->id,
                'customer_a_name' => $a->name ?? "Customer #{$a->id}",
                'customer_b_id' => $b->id,
                'customer_b_name' => $b->name ?? "Customer #{$b->id}",
                'score' => $result['score'],
                'features' => $result['features'],
                'feature_contributions' => $result['feature_contributions'],
            ];
        }

        usort($results, fn ($x, $y) => $y['score'] <=> $x['score']);

        return $results;
    }

    /**
     * Scan for high-scoring duplicate pairs using the ML model.
     *
     * @param  float  $minScore  Minimum score (0-100)
     * @param  int  $limit  Max pairs to return
     * @return array<string, mixed>
     */
    public function scanMlDuplicates(float $minScore = 70.0, int $limit = 100): array
    {
        $candidatePairs = collect();

        $phoneGroups = Customer::query()
            ->whereNotNull('normalized_phone')
            ->selectRaw('normalized_phone, COUNT(*) as cnt, GROUP_CONCAT(id) as ids')
            ->groupBy('normalized_phone')
            ->havingRaw('cnt > 1')
            ->limit(200)
            ->get();

        foreach ($phoneGroups as $group) {
            $ids = explode(',', $group->ids);
            for ($i = 0; $i < count($ids); $i++) {
                for ($j = $i + 1; $j < count($ids); $j++) {
                    $candidatePairs->push([(int) $ids[$i], (int) $ids[$j]]);
                }
            }
        }

        $psidGroups = CustomerIdentity::query()
            ->where('provider', 'facebook')
            ->whereNotNull('customer_id')
            ->selectRaw('provider_user_id, COUNT(DISTINCT customer_id) as cnt, GROUP_CONCAT(DISTINCT customer_id) as ids')
            ->groupBy('provider_user_id')
            ->havingRaw('cnt > 1')
            ->limit(200)
            ->get();

        foreach ($psidGroups as $group) {
            $ids = explode(',', $group->ids);
            for ($i = 0; $i < count($ids); $i++) {
                for ($j = $i + 1; $j < count($ids); $j++) {
                    $candidatePairs->push([(int) $ids[$i], (int) $ids[$j]]);
                }
            }
        }

        $nameGroups = Customer::query()
            ->whereNotNull('name')
            ->selectRaw('SUBSTRING(LOWER(name), 1, 3) as prefix, COUNT(*) as cnt, GROUP_CONCAT(id) as ids')
            ->groupBy('prefix')
            ->havingRaw('cnt > 1')
            ->limit(100)
            ->get();

        foreach ($nameGroups as $group) {
            $ids = explode(',', $group->ids);
            for ($i = 0; $i < count($ids); $i++) {
                for ($j = $i + 1; $j < count($ids); $j++) {
                    $candidatePairs->push([(int) $ids[$i], (int) $ids[$j]]);
                }
            }
        }

        $candidatePairs = $candidatePairs->unique(function ($pair) {
            $sorted = $pair;
            sort($sorted);

            return implode('-', $sorted);
        })->take(500);

        $allIds = $candidatePairs->flatten()->unique()->values()->all();
        $customers = Customer::whereIn('id', $allIds)->get()->keyBy('id');

        $results = [];
        foreach ($candidatePairs as $pair) {
            $a = $customers->get($pair[0]);
            $b = $customers->get($pair[1]);

            if (! $a || ! $b || $a->id === $b->id) {
                continue;
            }
            if ($a->is_blacklisted || $b->is_blacklisted) {
                continue;
            }

            $result = $this->scorePair($a, $b);

            if ($result['score'] >= $minScore) {
                $results[] = [
                    'customer_a_id' => $a->id,
                    'customer_a_name' => $a->name ?? "Customer #{$a->id}",
                    'customer_a_phone' => $a->normalized_phone ?? $a->phone,
                    'customer_a_orders' => (int) ($a->total_orders ?? 0),
                    'customer_b_id' => $b->id,
                    'customer_b_name' => $b->name ?? "Customer #{$b->id}",
                    'customer_b_phone' => $b->normalized_phone ?? $b->phone,
                    'customer_b_orders' => (int) ($b->total_orders ?? 0),
                    'score' => $result['score'],
                    'features' => $result['features'],
                    'feature_contributions' => $result['feature_contributions'],
                ];
            }

            if (count($results) >= $limit) {
                break;
            }
        }

        usort($results, fn ($x, $y) => $y['score'] <=> $x['score']);

        return [
            'total_pairs' => count($results),
            'pairs' => $results,
            'model_version' => $this->getActiveModelVersion(),
        ];
    }

    /**
     * Train the ML model using past review decisions as labeled data.
     *
     * Uses gradient descent on logistic regression to optimize weights.
     * Positive: review items 'actioned' + auto-merge 'merged'.
     * Negative: review items 'dismissed' + auto-merge 'rejected'.
     *
     * @return array<string, mixed>
     */
    public function trainModel(int $epochs = 100, float $learningRate = 0.01): array
    {
        $positivePairs = collect();
        $negativePairs = collect();

        // From review queue
        $actioned = DuplicateReviewItem::where('status', 'actioned')->where('type', 'customer')->limit(500)->get();
        $dismissed = DuplicateReviewItem::where('status', 'dismissed')->where('type', 'customer')->limit(500)->get();

        foreach ($actioned as $item) {
            $meta = $item->metadata ?? [];
            if (! empty($meta['target_customer_id']) && ! empty($meta['source_customer_id'])) {
                $positivePairs->push([$meta['target_customer_id'], $meta['source_customer_id']]);
            }
        }
        foreach ($dismissed as $item) {
            $meta = $item->metadata ?? [];
            if (! empty($meta['target_customer_id']) && ! empty($meta['source_customer_id'])) {
                $negativePairs->push([$meta['target_customer_id'], $meta['source_customer_id']]);
            }
        }

        // From auto-merge suggestions
        $merged = AutoMergeSuggestion::where('status', 'merged')->limit(500)->get();
        $rejected = AutoMergeSuggestion::where('status', 'rejected')->limit(500)->get();

        foreach ($merged as $s) {
            $positivePairs->push([$s->target_customer_id, $s->source_customer_id]);
        }
        foreach ($rejected as $s) {
            $negativePairs->push([$s->target_customer_id, $s->source_customer_id]);
        }

        $positivePairs = $positivePairs->unique(fn ($p) => min($p).'-'.max($p));
        $negativePairs = $negativePairs->unique(fn ($p) => min($p).'-'.max($p));

        if ($positivePairs->isEmpty() && $negativePairs->isEmpty()) {
            return [
                'success' => false,
                'message' => 'No training data available. Need reviewed duplicate items or actioned auto-merge suggestions.',
                'positive_samples' => 0,
                'negative_samples' => 0,
            ];
        }

        // Build training dataset
        $allIds = $positivePairs->merge($negativePairs)->flatten()->unique()->values()->all();
        $customers = Customer::whereIn('id', $allIds)->get()->keyBy('id');

        $trainingData = [];
        foreach ($positivePairs as $pair) {
            $a = $customers->get($pair[0]);
            $b = $customers->get($pair[1]);
            if ($a && $b) {
                $trainingData[] = ['features' => $this->extractFeatures($a, $b), 'label' => 1.0];
            }
        }
        foreach ($negativePairs as $pair) {
            $a = $customers->get($pair[0]);
            $b = $customers->get($pair[1]);
            if ($a && $b) {
                $trainingData[] = ['features' => $this->extractFeatures($a, $b), 'label' => 0.0];
            }
        }

        if (count($trainingData) < 4) {
            return [
                'success' => false,
                'message' => 'Insufficient training data (need at least 4 samples).',
                'positive_samples' => $positivePairs->count(),
                'negative_samples' => $negativePairs->count(),
            ];
        }

        // Gradient descent on logistic regression
        $weights = $this->getActiveFeatureWeights();
        $featureKeys = array_keys($this->defaultFeatureWeights);

        for ($epoch = 0; $epoch < $epochs; $epoch++) {
            $gradients = array_fill_keys($featureKeys, 0.0);

            foreach ($trainingData as $sample) {
                $z = 0.0;
                foreach ($featureKeys as $key) {
                    $z += ($weights[$key] ?? 0.0) * ($sample['features'][$key] ?? 0.0);
                }
                $pred = 1.0 / (1.0 + exp(-$z));
                $error = $pred - $sample['label'];

                foreach ($featureKeys as $key) {
                    $gradients[$key] += $error * ($sample['features'][$key] ?? 0.0);
                }
            }

            $n = (float) count($trainingData);
            foreach ($featureKeys as $key) {
                $weights[$key] -= $learningRate * ($gradients[$key] / $n);
            }
        }

        // Evaluate model
        $tp = $fp = $tn = $fn = 0;
        foreach ($trainingData as $sample) {
            $z = 0.0;
            foreach ($featureKeys as $key) {
                $z += ($weights[$key] ?? 0.0) * ($sample['features'][$key] ?? 0.0);
            }
            $pred = 1.0 / (1.0 + exp(-$z));
            $predicted = $pred >= 0.5 ? 1.0 : 0.0;
            $actual = $sample['label'];

            if ($predicted == 1 && $actual == 1) {
                $tp++;
            } elseif ($predicted == 1 && $actual == 0) {
                $fp++;
            } elseif ($predicted == 0 && $actual == 0) {
                $tn++;
            } else {
                $fn++;
            }
        }

        $accuracy = ($tp + $tn) / max($tp + $fp + $tn + $fn, 1);
        $precision = $tp / max($tp + $fp, 1);
        $recall = $tp / max($tp + $fn, 1);
        $f1 = 2.0 * $precision * $recall / max($precision + $recall, 0.0001);

        // Deactivate old models and save new one
        DuplicateMlModel::where('name', 'default')->update(['is_active' => false]);

        $modelCount = DuplicateMlModel::where('name', 'default')->count();
        $model = DuplicateMlModel::create([
            'name' => 'default',
            'version' => 'v'.($modelCount + 1),
            'feature_weights' => $weights,
            'training_stats' => [
                'tp' => $tp, 'fp' => $fp, 'tn' => $tn, 'fn' => $fn,
            ],
            'training_samples' => count($trainingData),
            'accuracy' => round($accuracy, 4),
            'precision' => round($precision, 4),
            'recall' => round($recall, 4),
            'f1_score' => round($f1, 4),
            'trained_at' => now(),
            'is_active' => true,
        ]);

        return [
            'success' => true,
            'model_id' => $model->id,
            'version' => $model->version,
            'training_samples' => count($trainingData),
            'positive_samples' => $positivePairs->count(),
            'negative_samples' => $negativePairs->count(),
            'accuracy' => round($accuracy, 4),
            'precision' => round($precision, 4),
            'recall' => round($recall, 4),
            'f1_score' => round($f1, 4),
            'confusion_matrix' => ['tp' => $tp, 'fp' => $fp, 'tn' => $tn, 'fn' => $fn],
            'feature_weights' => $weights,
        ];
    }

    /**
     * Get ML model stats and current model info.
     *
     * @return array<string, mixed>
     */
    public function getMlModelStats(): array
    {
        $activeModel = DuplicateMlModel::where('name', 'default')->where('is_active', true)->latest()->first();
        $allModels = DuplicateMlModel::where('name', 'default')->orderByDesc('created_at')->limit(10)->get();

        $featureWeights = $activeModel?->feature_weights ?? $this->defaultFeatureWeights;

        // Sort weights by absolute value desc for importance ranking
        arsort($featureWeights);

        return [
            'active_model' => $activeModel ? [
                'id' => $activeModel->id,
                'version' => $activeModel->version,
                'training_samples' => $activeModel->training_samples,
                'accuracy' => $activeModel->accuracy,
                'precision' => $activeModel->precision,
                'recall' => $activeModel->recall,
                'f1_score' => $activeModel->f1_score,
                'trained_at' => $activeModel->trained_at?->toIso8601String(),
                'trained_at_formatted' => $activeModel->trained_at?->format('M j, Y g:i A'),
            ] : null,
            'feature_weights' => $featureWeights,
            'feature_importance' => $featureWeights,
            'model_history' => $allModels->map(fn ($m) => [
                'id' => $m->id,
                'version' => $m->version,
                'training_samples' => $m->training_samples,
                'accuracy' => $m->accuracy,
                'f1_score' => $m->f1_score,
                'trained_at' => $m->trained_at?->toIso8601String(),
                'is_active' => $m->is_active,
            ])->toArray(),
            'default_weights' => $this->defaultFeatureWeights,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function emptyResult(string $phone, int $windowHours): array
    {
        return [
            'is_duplicate' => false,
            'phone' => $phone,
            'product_ids' => [],
            'time_window_hours' => $windowHours,
            'duplicate_count' => 0,
            'duplicates' => [],
            'severity' => 'none',
        ];
    }
}
