<?php

declare(strict_types=1);

namespace Modules\Shop\Services;

class WebhookEventKeyGenerator
{
    /**
     * Generate a stable event key from Meta webhook payload.
     *
     * Uses Meta-provided identifiers when available, falling back to
     * a canonical JSON hash with recursively sorted keys.
     */
    public static function generate(?int $pageId, ?string $eventType, array $payload): string
    {
        $providerId = self::extractProviderId($payload);

        if ($providerId !== null) {
            return hash('sha256', implode('|', [
                $pageId ?? 'null',
                $eventType ?? 'unknown',
                $providerId,
            ]));
        }

        $canonical = self::canonicalEncode($payload);

        return hash('sha256', implode('|', [
            $pageId ?? 'null',
            $eventType ?? 'unknown',
            $canonical,
        ]));
    }

    /**
     * Extract a stable provider identifier from the payload if available.
     */
    protected static function extractProviderId(array $payload): ?string
    {
        // Messenger message mid
        $mid = data_get($payload, 'message.mid');
        if (is_string($mid) && $mid !== '') {
            return $mid;
        }

        // Message delivery / read watermark
        $watermark = data_get($payload, 'delivery.watermark') ?? data_get($payload, 'read.watermark');
        if (is_numeric($watermark)) {
            $senderId = data_get($payload, 'sender.id') ?? data_get($payload, 'recipient.id') ?? '';

            return "delivery:{$senderId}:{$watermark}";
        }

        // Post / comment ID from feed events
        $postId = data_get($payload, 'post_id') ?? data_get($payload, 'comment_id');
        if (is_string($postId) && $postId !== '') {
            return $postId;
        }

        // Leadgen ID
        $leadgenId = data_get($payload, 'leadgen_id');
        if (is_string($leadgenId) && $leadgenId !== '') {
            return $leadgenId;
        }

        // Reaction mid
        $reactionMid = data_get($payload, 'reaction.mid');
        if (is_string($reactionMid) && $reactionMid !== '') {
            return "reaction:{$reactionMid}";
        }

        // Postback mid / payload
        $postbackPayload = data_get($payload, 'postback.payload');
        $senderId = data_get($payload, 'sender.id');
        $timestamp = data_get($payload, 'timestamp');
        if (is_string($postbackPayload) && is_string($senderId) && is_numeric($timestamp)) {
            return "postback:{$senderId}:{$timestamp}";
        }

        return null;
    }

    /**
     * Recursively sort associative keys while preserving indexed array order,
     * then JSON-encode with stable flags.
     */
    protected static function canonicalEncode(array $data): string
    {
        $sorted = self::sortKeysRecursive($data);

        return json_encode(
            $sorted,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        );
    }

    /**
     * Recursively sort associative arrays by key, preserving indexed arrays.
     */
    protected static function sortKeysRecursive(mixed $data): mixed
    {
        if (! is_array($data)) {
            return $data;
        }

        if (array_is_list($data)) {
            return array_map(fn ($item) => self::sortKeysRecursive($item), $data);
        }

        ksort($data, SORT_STRING);

        return array_map(fn ($item) => self::sortKeysRecursive($item), $data);
    }
}
