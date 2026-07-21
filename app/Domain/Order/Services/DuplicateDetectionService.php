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
use App\Models\DuplicateDetectionRule;
use App\Models\DuplicateReviewItem;
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
     * Scan for all duplicate types and populate the review queue.
     *
     * @param int $limit  Max items per type to create
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
     * @param array{type?: string, status?: string, severity?: string, per_page?: int} $filters
     * @return array<string, mixed>
     */
    public function getReviewQueue(array $filters = []): array
    {
        $query = DuplicateReviewItem::query()
            ->with('reviewer:id,name')
            ->orderByDesc('severity')
            ->orderByDesc('created_at');

        if (!empty($filters['type'])) {
            $query->where('type', $filters['type']);
        }

        if (!empty($filters['status'])) {
            $query->where('status', $filters['status']);
        } else {
            $query->where('status', 'pending');
        }

        if (!empty($filters['severity'])) {
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
     * @param int    $itemId
     * @param string $status     reviewed|dismissed|actioned
     * @param int    $userId
     * @param string|null $note
     * @return DuplicateReviewItem|null
     */
    public function resolveReviewItem(int $itemId, string $status, int $userId, ?string $note = null): ?DuplicateReviewItem
    {
        $item = DuplicateReviewItem::find($itemId);

        if (!$item) {
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
     * @param int $limit
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
     * @param int $limit
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
     * @param int $limit
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
                    if (in_array($conv->status, Conversation::ACTIVE_STATUSES) && !$conv->merged_into_id) {
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
     * @return \Illuminate\Database\Eloquent\Collection<int, DuplicateDetectionRule>
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
     * @param string $type  order|customer|conversation
     * @return \Illuminate\Database\Eloquent\Collection<int, DuplicateDetectionRule>
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
     * @param array{name:string,type:string,match_method?:string|null,is_enabled?:bool,priority?:int,config?:array|null,description?:string|null} $data
     * @param int $userId
     * @return DuplicateDetectionRule
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
     * @param int $ruleId
     * @param array{name?:string,type?:string,match_method?:string|null,is_enabled?:bool,priority?:int,config?:array|null,description?:string|null} $data
     * @param int $userId
     * @return DuplicateDetectionRule|null
     */
    public function updateRule(int $ruleId, array $data, int $userId): ?DuplicateDetectionRule
    {
        $rule = DuplicateDetectionRule::find($ruleId);

        if (!$rule) {
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
     *
     * @param int $ruleId
     * @return bool
     */
    public function deleteRule(int $ruleId): bool
    {
        $rule = DuplicateDetectionRule::find($ruleId);

        if (!$rule) {
            return false;
        }

        return $rule->delete();
    }

    /**
     * Toggle a rule's enabled status.
     *
     * @param int $ruleId
     * @param int $userId
     * @return DuplicateDetectionRule|null
     */
    public function toggleRule(int $ruleId, int $userId): ?DuplicateDetectionRule
    {
        $rule = DuplicateDetectionRule::find($ruleId);

        if (!$rule) {
            return null;
        }

        $rule->update([
            'is_enabled' => !$rule->is_enabled,
            'updated_by' => $userId,
        ]);

        return $rule->fresh();
    }

    /**
     * Get the effective config for a detection type by merging
     * enabled rules' config values. Later-priority rules override earlier.
     *
     * @param string $type
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
     * @param int $days  Look-back period (default 30)
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
                $user = \App\Models\User::find($row->reviewed_by);
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
     * @param int $days  Look-back period (default 30)
     * @return array<int, array<string, mixed>>
     */
    public function getAnalyticsTrend(int $days = 30): array
    {
        $cutoff = Carbon::now()->subDays($days);

        $created = DuplicateReviewItem::query()
            ->selectRaw("DATE(created_at) as date, COUNT(*) as count")
            ->where('created_at', '>=', $cutoff)
            ->groupByRaw("DATE(created_at)")
            ->orderByRaw("DATE(created_at)")
            ->get()
            ->pluck('count', 'date')
            ->toArray();

        $resolved = DuplicateReviewItem::query()
            ->selectRaw("DATE(reviewed_at) as date, COUNT(*) as count")
            ->where('reviewed_at', '>=', $cutoff)
            ->whereNotNull('reviewed_at')
            ->groupByRaw("DATE(reviewed_at)")
            ->orderByRaw("DATE(reviewed_at)")
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
