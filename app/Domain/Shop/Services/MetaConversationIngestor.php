<?php

declare(strict_types=1);

namespace App\Domain\Shop\Services;

use App\Domain\Order\Enums\OrderStatus;
use App\Domain\Order\Models\Order;
use App\Domain\Shop\Models\Conversation;
use App\Domain\Shop\Models\FacebookWebhookEvent;
use App\Domain\Shop\Models\Message;
use App\Domain\Shop\Models\OrderRemark;
use App\Domain\Shop\Models\ShopOrderItem;
use App\Models\Customer;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class MetaConversationIngestor
{
    public function __construct(
        private readonly PhoneDetectionService $phones,
        private readonly CustomerIdentityService $customerIdentities,
        private readonly AddressMappingService $addressMappings,
        private readonly FacebookConnectorService $facebookConnector,
        private readonly ProductMessageParser $productMessages,
    ) {}

    public function process(FacebookWebhookEvent $webhookEvent): void
    {
        if ($webhookEvent->processed_at !== null || $webhookEvent->facebookPage === null) {
            return;
        }

        $payload = $webhookEvent->payload ?? [];
        $senderPsid = data_get($payload, 'sender.id');

        if (! is_string($senderPsid) || $senderPsid === '') {
            $webhookEvent->forceFill([
                'processed_at' => now(),
                'error_message' => 'Missing sender PSID.',
            ])->save();

            return;
        }

        DB::transaction(function () use ($webhookEvent, $payload, $senderPsid) {
            $body = $this->messageBody($payload);
            $preview = $body !== '' ? $body : $this->messagePreview($payload);
            $detectedPhones = $this->phones->extract($body);
            $addressCapture = $this->captureAddressFromMessage($body, $detectedPhones);
            $facebookProfile = $this->facebookConnector->fetchMessengerProfile($webhookEvent->facebookPage, $senderPsid);
            $facebookDisplayName = $this->facebookDisplayName($facebookProfile);
            $facebookProfilePic = $this->facebookProfilePic($facebookProfile);
            $customer = $detectedPhones === [] ? null : $this->customerIdentities->findByPhone($detectedPhones[0]);

            $identity = $this->customerIdentities->upsertFacebookIdentity(
                page: $webhookEvent->facebookPage,
                psid: $senderPsid,
                customer: $customer,
                displayName: $facebookDisplayName,
                profilePicUrl: $facebookProfilePic,
                detectedPhone: $detectedPhones[0] ?? null,
                metadata: array_filter([
                    'source' => 'meta_webhook',
                    'profile_synced_at' => $facebookProfile ? now()->toIso8601String() : null,
                    'profile_lookup_failed' => $facebookProfile === null,
                ])
            );

            $conversation = Conversation::query()->firstOrNew([
                'thread_key' => "facebook:{$webhookEvent->facebookPage->page_id}:{$senderPsid}",
            ]);

            $conversation->fill([
                'facebook_page_id' => $webhookEvent->facebook_page_id,
                'customer_id' => $customer?->id,
                'customer_identity_id' => $identity->id,
                'channel' => 'messenger',
                'status' => $detectedPhones === [] ? 'open' : 'pending_details',
                'last_message_preview' => str($preview)->limit(160)->toString(),
                'last_message_at' => $this->eventTimestamp($payload),
                'unread_count' => ((int) $conversation->unread_count) + 1,
                'tags' => $this->conversationTags($conversation, $detectedPhones, $payload),
            ])->save();

            Message::query()->firstOrCreate(
                ['external_message_id' => $webhookEvent->event_id],
                [
                    'conversation_id' => $conversation->id,
                    'facebook_page_id' => $webhookEvent->facebook_page_id,
                    'customer_identity_id' => $identity->id,
                    'direction' => 'inbound',
                    'message_type' => $this->messageType($payload),
                    'body' => $body !== '' ? $body : null,
                    'attachments' => data_get($payload, 'message.attachments'),
                    'phone_candidates' => $detectedPhones,
                    'raw_payload' => $payload,
                    'sent_at' => $this->eventTimestamp($payload),
                ]
            );

            $order = $this->captureOrderFromPhone(
                conversation: $conversation,
                webhookEvent: $webhookEvent,
                customer: $customer,
                detectedPhones: $detectedPhones,
                body: $body,
                addressCapture: $addressCapture,
                facebookDisplayName: $facebookDisplayName
            );

            $order ??= $this->activeOrderForConversation($conversation);

            if ($order !== null && $addressCapture !== null) {
                $this->applyAddressCapture($order, $addressCapture, $body);
            }

            if ($order !== null) {
                if ($body !== '') {
                    $this->applyProductCapture($order, $body);
                }

                if ($order->customer && $facebookDisplayName !== null) {
                    $this->applyFacebookProfileToCustomer($order->customer, $facebookDisplayName);
                    $this->applyFacebookProfileToOrder($order, $facebookDisplayName);
                }

                if ($identity->customer_id !== $order->customer_id) {
                    $identity->forceFill(['customer_id' => $order->customer_id])->save();
                }

                $conversation->forceFill([
                    'customer_id' => $order->customer_id,
                    'status' => 'pending_details',
                    'metadata' => array_merge($conversation->metadata ?? [], [
                        'auto_order_id' => $order->id,
                        'latest_order_id' => $order->id,
                        'auto_order_created_at' => ($conversation->metadata['auto_order_created_at'] ?? now()->toIso8601String()),
                        'latest_address_capture' => $addressCapture,
                        'latest_facebook_profile' => array_filter([
                            'display_name' => $facebookDisplayName,
                            'profile_pic_url' => $facebookProfilePic,
                        ]),
                    ]),
                ])->save();
            }

            $webhookEvent->forceFill([
                'processed_at' => now(),
                'error_message' => null,
            ])->save();

            $webhookEvent->facebookPage->forceFill([
                'last_sync_at' => now(),
                'webhook_status' => 'subscribed',
            ])->save();
        });
    }

    /**
     * @param array<int, string> $detectedPhones
     */
    private function captureOrderFromPhone(
        Conversation $conversation,
        FacebookWebhookEvent $webhookEvent,
        ?Customer $customer,
        array $detectedPhones,
        string $body,
        ?array $addressCapture,
        ?string $facebookDisplayName
    ): ?Order {
        if ($detectedPhones === []) {
            return null;
        }

        $existingOrder = Order::query()
            ->where('conversation_id', $conversation->id)
            ->where('source_channel', 'facebook_shop')
            ->whereNotIn('status', [
                OrderStatus::DELIVERED->value,
                OrderStatus::RETURNED->value,
                OrderStatus::CANCELLED->value,
                OrderStatus::QA_REJECTED->value,
            ])
            ->latest()
            ->first();

        if ($existingOrder) {
            return $existingOrder;
        }

        $phone = $detectedPhones[0];
        $customer ??= $this->customerIdentities->firstOrCreateFromPhone([
            'name' => $facebookDisplayName ?? $conversation->identity?->display_name ?? 'Facebook Customer',
            'facebook_name' => $facebookDisplayName,
            'phone' => $phone,
            'address' => $addressCapture['address'] ?? null,
            'barangay' => $addressCapture['barangay'] ?? null,
            'city_municipality' => $addressCapture['city'] ?? null,
            'province' => $addressCapture['province'] ?? null,
            'region' => $addressCapture['region'] ?? null,
        ]);

        if ($addressCapture !== null) {
            $this->applyAddressToCustomer($customer, $addressCapture);
        }

        if ($facebookDisplayName !== null) {
            $this->applyFacebookProfileToCustomer($customer, $facebookDisplayName);
        }

        $order = Order::query()->create([
            'order_number' => Order::generateOrderNumber(),
            'conversation_id' => $conversation->id,
            'facebook_page_id' => $webhookEvent->facebook_page_id,
            'customer_id' => $customer->id,
            'status' => OrderStatus::PENDING,
            'courier_code' => 'MANUAL',
            'quantity' => 0,
            'unit_price' => 0,
            'total_amount' => 0,
            'cod_amount' => 0,
            'shipping_cost' => 0,
            'payment_method' => 'COD',
            'payment_status' => 'UNPAID',
            'paid_amount' => 0,
            'discount_amount' => 0,
            'surcharge_amount' => 0,
            'receiver_name' => $this->orderReceiverName($customer, $facebookDisplayName),
            'receiver_phone' => $this->phones->normalize($phone) ?: $phone,
            'receiver_address' => $addressCapture['address'] ?? 'Pending address from Messenger',
            'city' => $addressCapture['city'] ?? null,
            'state' => $addressCapture['province'] ?? null,
            'barangay' => $addressCapture['barangay'] ?? null,
            'postal_code' => $addressCapture['postal_code'] ?? null,
            'address_mapping_id' => $addressCapture['mapping_id'] ?? null,
            'address_confidence' => $addressCapture['confidence'] ?? null,
            'source_channel' => 'facebook_shop',
            'export_status' => 'pending',
            'notes' => trim(implode("\n", array_filter([
                'Auto-created from Messenger phone capture.',
                $body !== '' ? "Message: {$body}" : null,
            ]))),
        ]);

        OrderRemark::query()->create([
            'order_id' => $order->id,
            'conversation_id' => $conversation->id,
            'type' => 'auto_capture',
            'body' => "Auto-created from Messenger conversation #{$conversation->id} after phone number detection.",
            'metadata' => [
                'webhook_event_id' => $webhookEvent->id,
                'facebook_page_id' => $webhookEvent->facebook_page_id,
                'detected_phone' => $phone,
            ],
        ]);

        return $order;
    }

    private function activeOrderForConversation(Conversation $conversation): ?Order
    {
        return Order::query()
            ->where('conversation_id', $conversation->id)
            ->where('source_channel', 'facebook_shop')
            ->whereNotIn('status', [
                OrderStatus::DELIVERED->value,
                OrderStatus::RETURNED->value,
                OrderStatus::CANCELLED->value,
                OrderStatus::QA_REJECTED->value,
            ])
            ->latest()
            ->first();
    }

    /**
     * @param array<string, mixed>|null $profile
     */
    private function facebookDisplayName(?array $profile): ?string
    {
        $name = data_get($profile, 'name');

        if (is_string($name) && trim($name) !== '') {
            return trim($name);
        }

        $parts = array_filter([
            data_get($profile, 'first_name'),
            data_get($profile, 'last_name'),
        ], fn ($part) => is_string($part) && trim($part) !== '');

        $fallback = trim(implode(' ', $parts));

        return $fallback !== '' ? $fallback : null;
    }

    /**
     * @param array<string, mixed>|null $profile
     */
    private function facebookProfilePic(?array $profile): ?string
    {
        $profilePic = data_get($profile, 'profile_pic');

        return is_string($profilePic) && trim($profilePic) !== '' ? trim($profilePic) : null;
    }

    private function orderReceiverName(Customer $customer, ?string $facebookDisplayName): string
    {
        if (filled($customer->name) && ! $this->isGenericCustomerName($customer->name)) {
            return $customer->name;
        }

        if (filled($facebookDisplayName)) {
            return $facebookDisplayName;
        }

        return 'Facebook Customer';
    }

    private function applyFacebookProfileToCustomer(Customer $customer, string $facebookDisplayName): void
    {
        $updates = ['facebook_name' => $facebookDisplayName];

        if (! filled($customer->name) || $this->isGenericCustomerName($customer->name)) {
            $updates['name'] = $facebookDisplayName;
        }

        $customer->forceFill($updates)->save();
    }

    private function applyFacebookProfileToOrder(Order $order, string $facebookDisplayName): void
    {
        if (! filled($order->receiver_name) || $this->isGenericCustomerName($order->receiver_name)) {
            $order->forceFill(['receiver_name' => $facebookDisplayName])->save();
        }
    }

    private function isGenericCustomerName(?string $name): bool
    {
        return in_array(mb_strtolower(trim((string) $name)), [
            '',
            'facebook customer',
            'customer',
            'unknown customer',
        ], true);
    }

    /**
     * @param array<int, string> $detectedPhones
     * @return array<string, mixed>|null
     */
    private function captureAddressFromMessage(string $body, array $detectedPhones): ?array
    {
        if ($body === '') {
            return null;
        }

        $candidate = $body;

        foreach ($detectedPhones as $phone) {
            $candidate = str_replace($phone, ' ', $candidate);
            $candidate = str_replace($this->phones->normalize($phone), ' ', $candidate);
        }

        $candidate = preg_replace('/\b(?:phone|number|contact|cp|mobile|name|product|order|qty|quantity|address|addr|location|loc|delivery address)\s*[:=-]\s*/i', ' ', $candidate) ?? $candidate;
        $candidate = trim(preg_replace('/\s+/', ' ', $candidate) ?? $candidate);

        if (! $this->looksLikeAddress($candidate)) {
            return null;
        }

        $match = $this->addressMappings->match(['address' => $candidate]);
        $mapping = $match['mapping'];

        return [
            'address' => $candidate,
            'province' => $mapping?->province,
            'city' => $mapping?->city_municipality,
            'barangay' => $mapping?->barangay,
            'postal_code' => $mapping?->postal_code,
            'region' => $mapping?->region,
            'courier_zone' => $mapping?->courier_zone,
            'mapping_id' => $mapping?->id,
            'confidence' => $match['confidence'],
            'requires_encoder_review' => $match['requires_encoder_review'],
        ];
    }

    private function looksLikeAddress(string $candidate): bool
    {
        if (mb_strlen($candidate) < 12) {
            return false;
        }

        if (preg_match('/\b(?:brgy|barangay|blk|block|lot|phase|street|st\.?|road|rd\.?|subd|village|purok|zone|sitio|city|province|municipality|calamba|laguna|tarlac|metro manila|cavite|rizal|bulacan|pampanga|batangas)\b/i', $candidate) === 1) {
            return true;
        }

        $match = $this->addressMappings->match(['address' => $candidate]);

        return $match['mapping'] !== null;
    }

    /**
     * @param array<string, mixed> $addressCapture
     */
    private function applyAddressCapture(Order $order, array $addressCapture, string $body): void
    {
        $order->forceFill([
            'receiver_address' => $addressCapture['address'],
            'city' => $addressCapture['city'],
            'state' => $addressCapture['province'],
            'barangay' => $addressCapture['barangay'],
            'postal_code' => $addressCapture['postal_code'],
            'address_mapping_id' => $addressCapture['mapping_id'],
            'address_confidence' => $addressCapture['confidence'],
            'notes' => trim(implode("\n", array_filter([
                $order->notes,
                'Address auto-captured from Messenger.',
                $body !== '' ? "Address message: {$body}" : null,
            ]))),
        ])->save();

        if ($order->customer) {
            $this->applyAddressToCustomer($order->customer, $addressCapture);
        }

        OrderRemark::query()->firstOrCreate(
            [
                'order_id' => $order->id,
                'conversation_id' => $order->conversation_id,
                'type' => 'address_auto_capture',
            ],
            [
                'body' => 'Address details were auto-captured from Messenger and applied to this order.',
                'metadata' => $addressCapture,
            ]
        );
    }

    /**
     * @param array<string, mixed> $addressCapture
     */
    private function applyAddressToCustomer(Customer $customer, array $addressCapture): void
    {
        $customer->forceFill([
            'canonical_address' => $addressCapture['address'],
            'barangay' => $addressCapture['barangay'],
            'city_municipality' => $addressCapture['city'],
            'province' => $addressCapture['province'],
            'region' => $addressCapture['region'] ?? $customer->region,
        ])->save();
    }

    private function applyProductCapture(Order $order, string $body): void
    {
        if ($order->shopItems()->exists()) {
            return;
        }

        $facebookPage = $order->facebookPage ?: null;
        $parsedItems = $this->productMessages->parse($body, $facebookPage);

        if ($parsedItems->isEmpty()) {
            return;
        }

        DB::transaction(function () use ($order, $parsedItems) {
            foreach ($parsedItems as $item) {
                ShopOrderItem::query()->create([
                    'order_id' => $order->id,
                    'product_id' => $item['product']->id,
                    'variant_id' => $item['variant']?->id,
                    'sku' => $item['sku'],
                    'product_name' => $item['product_name'],
                    'quantity' => $item['quantity'],
                    'unit_price' => $item['unit_price'],
                    'line_total' => $item['line_total'],
                    'metadata' => [
                        'source' => 'messenger_product_parse',
                        'matched_text' => $item['matched_text'],
                        'confidence' => $item['confidence'],
                    ],
                ]);
            }

            $primaryItem = $parsedItems->first();
            $itemsTotal = (float) $parsedItems->sum('line_total');
            $quantity = (int) $parsedItems->sum('quantity');
            $shippingCost = (float) ($order->shipping_cost ?? 0);
            $discountAmount = (float) ($order->discount_amount ?? 0);
            $surchargeAmount = (float) ($order->surcharge_amount ?? 0);
            $paidAmount = (float) ($order->paid_amount ?? 0);
            $totalAmount = max(0, $itemsTotal + $shippingCost + $surchargeAmount - $discountAmount);
            $codAmount = max(0, $totalAmount - $paidAmount);

            $order->forceFill([
                'product_id' => $primaryItem['product']->id,
                'variant_id' => $primaryItem['variant']?->id,
                'quantity' => $quantity,
                'unit_price' => $primaryItem['unit_price'],
                'total_amount' => $totalAmount,
                'cod_amount' => $codAmount,
                'payment_status' => $paidAmount <= 0 ? 'UNPAID' : ($paidAmount >= $totalAmount ? 'PAID' : 'PARTIAL'),
            ])->save();

            OrderRemark::query()->firstOrCreate(
                [
                    'order_id' => $order->id,
                    'conversation_id' => $order->conversation_id,
                    'type' => 'product_auto_capture',
                ],
                [
                    'body' => 'Product line items were auto-captured from Messenger and attached to this order.',
                    'metadata' => [
                        'items' => $parsedItems->map(fn (array $item) => [
                            'product_id' => $item['product']->id,
                            'variant_id' => $item['variant']?->id,
                            'sku' => $item['sku'],
                            'product_name' => $item['product_name'],
                            'quantity' => $item['quantity'],
                            'unit_price' => $item['unit_price'],
                            'line_total' => $item['line_total'],
                            'confidence' => $item['confidence'],
                        ])->values()->all(),
                    ],
                ]
            );
        });
    }

    private function messageBody(array $payload): string
    {
        $body = data_get($payload, 'message.text')
            ?? data_get($payload, 'message.quick_reply.payload')
            ?? data_get($payload, 'postback.title')
            ?? data_get($payload, 'postback.payload')
            ?? '';

        return is_string($body) ? trim($body) : '';
    }

    private function messagePreview(array $payload): string
    {
        if (data_get($payload, 'message.attachments')) {
            return 'Attachment received';
        }

        if (data_get($payload, 'referral')) {
            return 'Referral received';
        }

        if (data_get($payload, 'delivery')) {
            return 'Delivery receipt';
        }

        if (data_get($payload, 'read')) {
            return 'Read receipt';
        }

        return 'Unsupported Messenger event';
    }

    private function messageType(array $payload): string
    {
        if (data_get($payload, 'message.attachments')) {
            return 'attachment';
        }

        if (data_get($payload, 'postback')) {
            return 'postback';
        }

        if (data_get($payload, 'message.quick_reply')) {
            return 'quick_reply';
        }

        return 'text';
    }

    /**
     * @return array<int, string>|null
     */
    private function conversationTags(Conversation $conversation, array $detectedPhones, array $payload): ?array
    {
        $tags = is_array($conversation->tags) ? $conversation->tags : [];

        if ($detectedPhones !== []) {
            $tags[] = 'phone_detected';
        }

        if (data_get($payload, 'message.attachments')) {
            $tags[] = 'attachment';
        }

        if (data_get($payload, 'postback')) {
            $tags[] = 'postback';
        }

        $tags = array_values(array_unique(array_filter($tags)));

        return $tags === [] ? null : $tags;
    }

    private function eventTimestamp(array $payload): Carbon
    {
        $timestamp = data_get($payload, 'timestamp');

        if (is_numeric($timestamp)) {
            return Carbon::createFromTimestampMs((int) $timestamp);
        }

        return now();
    }
}
