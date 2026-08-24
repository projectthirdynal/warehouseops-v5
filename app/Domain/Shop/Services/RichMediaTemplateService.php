<?php

declare(strict_types=1);

namespace App\Domain\Shop\Services;

use App\Models\ReplyTemplate;
use Illuminate\Support\Collection;

class RichMediaTemplateService
{
    public const MIN_BUTTONS = 1;

    public const MAX_BUTTONS = 3;

    public const MAX_CARDS = 10;

    public const BUTTON_TITLE_MAX_LENGTH = 20;

    public const ELEMENT_TITLE_MAX_LENGTH = 80;

    public const ELEMENT_SUBTITLE_MAX_LENGTH = 80;

    private const BUTTON_TYPES = ['postback', 'web_url', 'phone_number'];

    /**
     * Validate media config for a given media type.
     *
     * @return array{valid: bool, errors: string[]}
     */
    public function validateConfig(string $mediaType, ?array $config): array
    {
        $errors = [];

        if ($mediaType === ReplyTemplate::MEDIA_TEXT) {
            return ['valid' => true, 'errors' => []];
        }

        if ($config === null) {
            return ['valid' => false, 'errors' => ['Media config is required for non-text templates.']];
        }

        switch ($mediaType) {
            case ReplyTemplate::MEDIA_BUTTON:
                $errors = $this->validateButtonConfig($config);
                break;
            case ReplyTemplate::MEDIA_CARD:
                $errors = $this->validateCardConfig($config);
                break;
            case ReplyTemplate::MEDIA_CAROUSEL:
                $errors = $this->validateCarouselConfig($config);
                break;
            default:
                $errors[] = "Unknown media type: {$mediaType}";
        }

        return ['valid' => empty($errors), 'errors' => $errors];
    }

    /**
     * Validate button template config.
     */
    private function validateButtonConfig(array $config): array
    {
        if (! isset($config['buttons']) || ! is_array($config['buttons'])) {
            return ['Buttons array is required.'];
        }

        $errors = [];

        if (count($config['buttons']) < self::MIN_BUTTONS) {
            $errors[] = 'At least '.self::MIN_BUTTONS.' button is required.';
        }

        if (count($config['buttons']) > self::MAX_BUTTONS) {
            $errors[] = 'Maximum '.self::MAX_BUTTONS.' buttons allowed.';
        }

        foreach ($config['buttons'] as $i => $button) {
            $errors = array_merge(
                $errors,
                $this->validateButtonFields(is_array($button) ? $button : [], "Button {$i}"),
            );
        }

        return $errors;
    }

    /**
     * Validate a single button definition.
     *
     * @return string[]
     */
    private function validateButtonFields(array $button, string $label): array
    {
        $errors = [];

        $title = $button['title'] ?? null;
        if (! is_string($title) || trim($title) === '') {
            $errors[] = "{$label}: title is required.";
        } elseif (mb_strlen(trim($title)) > self::BUTTON_TITLE_MAX_LENGTH) {
            $errors[] = "{$label}: title must not exceed ".self::BUTTON_TITLE_MAX_LENGTH.' characters.';
        }

        $type = $button['type'] ?? null;
        if (! is_string($type) || ! in_array($type, self::BUTTON_TYPES, true)) {
            $errors[] = "{$label}: type must be postback, web_url, or phone_number.";

            return $errors;
        }

        $value = $button['value'] ?? null;
        if (! is_string($value) || trim($value) === '') {
            $errors[] = "{$label}: value is required.";

            return $errors;
        }

        if ($type === 'web_url' && filter_var($value, FILTER_VALIDATE_URL) === false) {
            $errors[] = "{$label}: value must be a valid URL for web_url buttons.";
        }

        return $errors;
    }

    /**
     * Validate card template config.
     */
    private function validateCardConfig(array $config): array
    {
        $errors = [];

        foreach ([self::ELEMENT_TITLE_MAX_LENGTH, self::ELEMENT_SUBTITLE_MAX_LENGTH] as $index => $maxLength) {
            $field = $index === 0 ? 'title' : 'subtitle';
            $label = $index === 0 ? 'Card title' : 'Card subtitle';
            $value = $config[$field] ?? null;

            if (! is_string($value) || trim($value) === '') {
                $errors[] = "{$label} is required.";
            } elseif (mb_strlen(trim($value)) > $maxLength) {
                $errors[] = "{$label} must not exceed {$maxLength} characters.";
            }
        }

        if (isset($config['image_url']) && ! filter_var($config['image_url'], FILTER_VALIDATE_URL) && ! str_starts_with((string) $config['image_url'], '/storage/')) {
            $errors[] = 'Card image URL must be a valid URL or storage path.';
        }

        if (isset($config['default_action_url']) && filter_var((string) $config['default_action_url'], FILTER_VALIDATE_URL) === false) {
            $errors[] = 'Card default action URL must be a valid URL.';
        }

        if (isset($config['buttons']) && is_array($config['buttons'])) {
            if (count($config['buttons']) > self::MAX_BUTTONS) {
                $errors[] = 'Maximum '.self::MAX_BUTTONS.' buttons per card.';
            }
            foreach ($config['buttons'] as $i => $button) {
                $errors = array_merge(
                    $errors,
                    $this->validateButtonFields(is_array($button) ? $button : [], "Card button {$i}"),
                );
            }
        }

        return $errors;
    }

    /**
     * Validate carousel template config.
     */
    private function validateCarouselConfig(array $config): array
    {
        $errors = [];

        if (! isset($config['cards']) || ! is_array($config['cards']) || count($config['cards']) === 0) {
            $errors[] = 'Carousel requires at least one card.';

            return $errors;
        }

        if (count($config['cards']) > self::MAX_CARDS) {
            $errors[] = 'Maximum '.self::MAX_CARDS.' cards per carousel.';
        }

        foreach ($config['cards'] as $i => $card) {
            $cardErrors = $this->validateCardConfig(is_array($card) ? $card : []);
            foreach ($cardErrors as $err) {
                $errors[] = "Card {$i}: {$err}";
            }
        }

        return $errors;
    }

    /**
     * Build a Facebook Messenger-compatible message payload from a template.
     */
    public function buildPayload(ReplyTemplate $template): array
    {
        $config = $template->media_config ?? [];

        return match ($template->media_type) {
            ReplyTemplate::MEDIA_BUTTON => $this->buildButtonPayload($template, $config),
            ReplyTemplate::MEDIA_CARD => $this->buildCardPayload($template, $config),
            ReplyTemplate::MEDIA_CAROUSEL => $this->buildCarouselPayload($template, $config),
            default => ['text' => $template->content],
        };
    }

    private function buildButtonPayload(ReplyTemplate $template, array $config): array
    {
        $buttons = array_map(fn ($b) => $this->buildButton(is_array($b) ? $b : []), $config['buttons'] ?? []);

        return [
            'attachment' => [
                'type' => 'template',
                'payload' => [
                    'template_type' => 'button',
                    'text' => $template->content,
                    'buttons' => $buttons,
                ],
            ],
        ];
    }

    private function buildCardPayload(ReplyTemplate $template, array $config): array
    {
        $buttons = array_map(fn ($b) => $this->buildButton(is_array($b) ? $b : []), $config['buttons'] ?? []);

        $card = [
            'title' => mb_substr((string) ($config['title'] ?? $template->title), 0, self::ELEMENT_TITLE_MAX_LENGTH),
            'subtitle' => mb_substr((string) ($config['subtitle'] ?? ''), 0, self::ELEMENT_SUBTITLE_MAX_LENGTH),
        ];

        if (isset($config['image_url'])) {
            $card['image_url'] = $this->resolveImageUrl((string) $config['image_url']);
        }

        if (isset($config['default_action_url'])) {
            $card['default_action'] = [
                'type' => 'web_url',
                'url' => $config['default_action_url'],
            ];
        }

        if (! empty($buttons)) {
            $card['buttons'] = $buttons;
        }

        return [
            'attachment' => [
                'type' => 'template',
                'payload' => [
                    'template_type' => 'generic',
                    'elements' => [$card],
                ],
            ],
        ];
    }

    private function buildCarouselPayload(ReplyTemplate $template, array $config): array
    {
        $elements = array_map(fn ($card) => $this->buildCardElement(is_array($card) ? $card : []), $config['cards'] ?? []);

        return [
            'attachment' => [
                'type' => 'template',
                'payload' => [
                    'template_type' => 'generic',
                    'elements' => $elements,
                ],
            ],
        ];
    }

    private function buildCardElement(array $card): array
    {
        $element = [
            'title' => mb_substr((string) ($card['title'] ?? ''), 0, self::ELEMENT_TITLE_MAX_LENGTH),
            'subtitle' => mb_substr((string) ($card['subtitle'] ?? ''), 0, self::ELEMENT_SUBTITLE_MAX_LENGTH),
        ];

        if (isset($card['image_url'])) {
            $element['image_url'] = $this->resolveImageUrl((string) $card['image_url']);
        }

        if (isset($card['default_action_url'])) {
            $element['default_action'] = [
                'type' => 'web_url',
                'url' => $card['default_action_url'],
            ];
        }

        if (isset($card['buttons']) && is_array($card['buttons'])) {
            $element['buttons'] = array_map(fn ($b) => $this->buildButton(is_array($b) ? $b : []), $card['buttons']);
        }

        return $element;
    }

    private function buildButton(array $button): array
    {
        $title = mb_substr((string) ($button['title'] ?? ''), 0, self::BUTTON_TITLE_MAX_LENGTH);

        return match ($button['type'] ?? 'postback') {
            'web_url' => [
                'type' => 'web_url',
                'title' => $title,
                'url' => (string) ($button['value'] ?? ''),
            ],
            'phone_number' => [
                'type' => 'phone_number',
                'title' => $title,
                'payload' => (string) ($button['value'] ?? ''),
            ],
            default => [
                'type' => 'postback',
                'title' => $title,
                'payload' => (string) ($button['value'] ?? ''),
            ],
        };
    }

    /**
     * Resolve image URL — absolute URLs pass through; storage paths become full URLs.
     */
    private function resolveImageUrl(string $url): string
    {
        if (str_starts_with($url, '/storage/')) {
            return url($url);
        }

        return $url;
    }

    /**
     * Generate a carousel config from a set of products.
     */
    public function generateCarouselFromProducts(Collection $products, ?float $discountPercent = null): array
    {
        $cards = [];

        foreach ($products->take(self::MAX_CARDS) as $product) {
            $price = (float) $product->selling_price;
            $originalPrice = $price;

            if ($discountPercent && $discountPercent > 0) {
                $price = round($price * (1 - $discountPercent / 100), 2);
            }

            $subtitle = '₱'.number_format($price, 2);
            if ($discountPercent && $discountPercent > 0) {
                $subtitle .= ' (was ₱'.number_format($originalPrice, 2).')';
            }

            if ($product->brand) {
                $subtitle .= " · {$product->brand}";
            }

            if ($product->category) {
                $subtitle .= " · {$product->category}";
            }

            $card = [
                'title' => $product->name,
                'subtitle' => $subtitle,
                'buttons' => [
                    [
                        'type' => 'postback',
                        'title' => 'Order This',
                        'value' => "ORDER_PRODUCT:{$product->id}",
                    ],
                ],
            ];

            $imageUrl = trim((string) ($product->image_url ?? ''));

            if ($imageUrl !== '') {
                $card['image_url'] = str_starts_with($imageUrl, 'http://') || str_starts_with($imageUrl, 'https://')
                    ? $imageUrl
                    : '/storage/'.ltrim($imageUrl, '/');
            }

            $cards[] = $card;
        }

        return ['cards' => $cards];
    }

    /**
     * Get statistics about rich media templates.
     */
    public function stats(): array
    {
        $total = ReplyTemplate::query()->count();
        $byType = ReplyTemplate::query()
            ->selectRaw('media_type, count(*) as count')
            ->groupBy('media_type')
            ->pluck('count', 'media_type')
            ->toArray();

        $recentRich = ReplyTemplate::query()
            ->whereIn('media_type', [ReplyTemplate::MEDIA_BUTTON, ReplyTemplate::MEDIA_CARD, ReplyTemplate::MEDIA_CAROUSEL])
            ->with('creator:id,name')
            ->orderByDesc('updated_at')
            ->limit(10)
            ->get(['id', 'title', 'media_type', 'media_config', 'updated_at', 'created_by'])
            ->map(fn (ReplyTemplate $t) => [
                'id' => $t->id,
                'title' => $t->title,
                'media_type' => $t->media_type,
                'media_type_label' => ReplyTemplate::MEDIA_TYPES[$t->media_type] ?? $t->media_type,
                'card_count' => $t->media_type === ReplyTemplate::MEDIA_CAROUSEL
                    ? count($t->media_config['cards'] ?? [])
                    : ($t->media_type === ReplyTemplate::MEDIA_CARD ? 1 : 0),
                'button_count' => match ($t->media_type) {
                    ReplyTemplate::MEDIA_BUTTON => count($t->media_config['buttons'] ?? []),
                    ReplyTemplate::MEDIA_CARD => count($t->media_config['buttons'] ?? []),
                    ReplyTemplate::MEDIA_CAROUSEL => collect($t->media_config['cards'] ?? [])->sum(fn ($c) => count(is_array($c) ? ($c['buttons'] ?? []) : [])),
                    default => 0,
                },
                'updated_at' => $t->updated_at?->toIso8601String(),
                'creator' => $t->creator?->name,
            ])
            ->all();

        return [
            'total' => $total,
            'text' => $byType[ReplyTemplate::MEDIA_TEXT] ?? 0,
            'button' => $byType[ReplyTemplate::MEDIA_BUTTON] ?? 0,
            'card' => $byType[ReplyTemplate::MEDIA_CARD] ?? 0,
            'carousel' => $byType[ReplyTemplate::MEDIA_CAROUSEL] ?? 0,
            'rich_total' => ($byType[ReplyTemplate::MEDIA_BUTTON] ?? 0)
                + ($byType[ReplyTemplate::MEDIA_CARD] ?? 0)
                + ($byType[ReplyTemplate::MEDIA_CAROUSEL] ?? 0),
            'recent' => $recentRich,
        ];
    }

    /**
     * Preview a template's media payload without saving.
     */
    public function preview(string $mediaType, string $content, ?array $config): array
    {
        $template = new ReplyTemplate([
            'content' => $content,
            'media_type' => $mediaType,
            'media_config' => $config,
        ]);

        $validation = $this->validateConfig($mediaType, $config);

        return [
            'valid' => $validation['valid'],
            'errors' => $validation['errors'],
            'payload' => $validation['valid'] ? $this->buildPayload($template) : null,
        ];
    }
}
