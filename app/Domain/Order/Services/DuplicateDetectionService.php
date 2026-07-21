<?php

declare(strict_types=1);

namespace App\Domain\Order\Services;

use App\Domain\Order\Enums\OrderStatus;
use App\Domain\Order\Models\Order;
use App\Domain\Shop\Models\Conversation;
use App\Domain\Shop\Models\CustomerIdentity;
use App\Domain\Shop\Models\ShopOrderItem;
use App\Domain\Shop\Services\PhoneDetectionService;
use App\Models\Customer;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

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
     * @param string      $phone      Receiver phone (raw or normalized)
     * @param array<int>  $productIds Product IDs to check against
     * @param int|null    $timeWindowHours  Override the default time window
     * @param int|null    $excludeOrderId   Order ID to exclude (e.g., the order being edited)
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

        $duplicateWarnings = $duplicates->map(function (Order $order) use ($normalizedPhone) {
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
     * @param string      $phone
     * @param int|null    $days  How many days back to look (default 30)
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
     * @param string      $psid             The provider_user_id (PSID) to check
     * @param int|null    $facebookPageId   Optional: scope to a specific page
     * @param int|null    $excludeConversationId  Conversation ID to exclude
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
     * @param int      $identityId
     * @param int|null $excludeConversationId
     * @return array<string, mixed>
     */
    public function detectDuplicateConversationsByIdentity(int $identityId, ?int $excludeConversationId = null): array
    {
        $identity = CustomerIdentity::find($identityId);

        if (!$identity || $identity->provider !== 'facebook') {
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
     * @param int $count
     * @return string  'none', 'low', 'medium', 'high'
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
     * @param int $count
     * @param int $windowHours
     * @return string  'none', 'low', 'medium', 'high'
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
     * @param int      $customerId    The primary customer to check against
     * @param array    $methods       Detection methods: 'phone', 'psid', 'name'
     * @return array<string, mixed>
     */
    public function detectDuplicateCustomers(int $customerId, array $methods = ['phone', 'psid', 'name']): array
    {
        $customer = Customer::find($customerId);

        if (!$customer) {
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
     * @param int $targetId
     * @param int $sourceId
     * @return array<string, mixed>
     */
    public function previewMerge(int $targetId, int $sourceId): array
    {
        $target = Customer::find($targetId);
        $source = Customer::find($sourceId);

        if (!$target || !$source || $targetId === $sourceId) {
            return [
                'can_merge' => false,
                'reason' => 'Invalid customer IDs or same customer.',
            ];
        }

        $ordersCount = Order::query()->where('customer_id', $sourceId)->count();
        $conversationsCount = Conversation::query()->where('customer_id', $sourceId)->count();
        $identitiesCount = CustomerIdentity::query()->where('customer_id', $sourceId)->count();

        $addressCount = \DB::table('customer_addresses')->where('customer_id', $sourceId)->count();
        $notesCount = \DB::table('customer_notes')->where('customer_id', $sourceId)->count();
        $leadsCount = \DB::table('leads')->where('customer_id', $sourceId)->count();

        $totalRecords = $ordersCount + $conversationsCount + $identitiesCount + $addressCount + $notesCount + $leadsCount;

        // Determine which fields will be filled from source
        $filledFields = [];
        foreach (['phone', 'facebook_name', 'canonical_address', 'landmark', 'barangay', 'city_municipality', 'province', 'region'] as $field) {
            if (empty($target->{$field}) && !empty($source->{$field})) {
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
     * @param int      $customerId       The primary customer to check against
     * @param float    $nameThreshold    Minimum name similarity (0-100, default 80)
     * @param float    $addressThreshold Minimum address similarity (0-1, default 0.6)
     * @param int      $limit            Max results (default 20)
     * @return array<string, mixed>
     */
    public function detectFuzzyDuplicateCustomers(
        int $customerId,
        float $nameThreshold = 80.0,
        float $addressThreshold = 0.6,
        int $limit = 20,
    ): array {
        $customer = Customer::find($customerId);

        if (!$customer) {
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
            if (!empty($customer->{$field})) {
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
                    $q->whereRaw('LOWER(name) LIKE ?', [strtolower($namePrefix) . '%'])
                        ->orWhereRaw('LOWER(facebook_name) LIKE ?', [strtolower($namePrefix) . '%']);
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
        $tokens = array_filter($tokens, fn ($t) => !in_array($t, $stopWords, true));

        return array_values(array_unique($tokens));
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
