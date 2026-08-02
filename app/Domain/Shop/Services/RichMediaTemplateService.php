<?php

declare(strict_types=1);

namespace App\Domain\Shop\Services;

use App\Models\ReplyTemplate;
use Illuminate\Support\Collection;

class RichMediaTemplateService
{
    public const MAX_BUTTONS = 3;

    public const MAX_CARDS = 10;

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
        $errors = [];

        if (! isset($config['buttons']) || ! is_array($config['buttons'])) {
            $errors[] = 'Buttons array is required.';

            return $errors;
        }

        if (count($config['buttons']) > self::MAX_BUTTONS) {
            $errors[] = 'Maximum '.self::MAX_BUTTONS.' buttons allowed.';
        }

        foreach ($config['buttons'] as $i => $button) {
            if (! isset($button['title']) || empty($button['title'])) {
                $errors[] = "Button {$i}: title is required.";
            }
            if (! isset($button['type']) || ! in_array($button['type'], ['postback', 'web_url', 'phone_number'])) {
                $errors[] = "Button {$i}: type must be postback, web_url, or phone_number.";
            }
            if (! isset($button['value']) || empty($button['value'])) {
                $errors[] = "Button {$i}: value is required.";
            }
        }

        return $errors;
    }

    /**
     * Validate card template config.
     */
    private function validateCardConfig(array $config): array
    {
        $errors = [];

        if (! isset($config['title']) || empty($config['title'])) {
            $errors[] = 'Card title is required.';
        }
        if (! isset($config['subtitle']) || empty($config['subtitle'])) {
            $errors[] = 'Card subtitle is required.';
        }

        if (isset($config['image_url']) && ! filter_var($config['image_url'], FILTER_VALIDATE_URL) && ! str_starts_with($config['image_url'], '/storage/')) {
            $errors[] = 'Card image URL must be a valid URL or storage path.';
        }

        if (isset($config['buttons']) && is_array($config['buttons'])) {
            if (count($config['buttons']) > self::MAX_BUTTONS) {
                $errors[] = 'Maximum '.self::MAX_BUTTONS.' buttons per card.';
            }
            foreach ($config['buttons'] as $i => $button) {
                if (! isset($button['title']) || empty($button['title'])) {
                    $errors[] = "Card button {$i}: title is required.";
                }
                if (! isset($button['type']) || ! in_array($button['type'], ['postback', 'web_url', 'phone_number'])) {
                    $errors[] = "Card button {$i}: type must be postback, web_url, or phone_number.";
                }
                if (! isset($button['value']) || empty($button['value'])) {
                    $errors[] = "Card button {$i}: value is required.";
                }
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
            $cardErrors = $this->validateCardConfig($card);
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
        $buttons = array_map(fn ($b) => $this->buildButton($b), $config['buttons'] ?? []);

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
        $buttons = array_map(fn ($b) => $this->buildButton($b), $config['buttons'] ?? []);

        $card = [
            'title' => $config['title'] ?? $template->title,
            'subtitle' => $config['subtitle'] ?? '',
        ];

        if (isset($config['image_url'])) {
            $card['image_url'] = $this->resolveImageUrl($config['image_url']);
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
        $elements = array_map(fn ($card) => $this->buildCardElement($card), $config['cards'] ?? []);

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
            'title' => $card['title'] ?? '',
            'subtitle' => $card['subtitle'] ?? '',
        ];

        if (isset($card['image_url'])) {
            $element['image_url'] = $this->resolveImageUrl($card['image_url']);
        }

        if (isset($card['default_action_url'])) {
            $element['default_action'] = [
                'type' => 'web_url',
                'url' => $card['default_action_url'],
            ];
        }

        if (isset($card['buttons']) && is_array($card['buttons'])) {
            $element['buttons'] = array_map(fn ($b) => $this->buildButton($b), $card['buttons']);
        }

        return $element;
    }

    private function buildButton(array $button): array
    {
        return match ($button['type']) {
            'postback' => [
                'type' => 'postback',
                'title' => $button['title'],
                'payload' => $button['value'],
            ],
            'web_url' => [
                'type' => 'web_url',
                'title' => $button['title'],
                'url' => $button['value'],
            ],
            'phone_number' => [
                'type' => 'phone_number',
                'title' => $button['title'],
                'payload' => $button['value'],
            ],
            default => [
                'type' => 'postback',
                'title' => $button['title'] ?? '',
                'payload' => $button['value'] ?? '',
            ],
        };
    }

    /**
     * Resolve image URL — if it's a storage path, convert to full URL.
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

        foreach ($products as $product) {
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

            if ($product->image_path) {
                $card['image_url'] = '/storage/'.$product->image_path;
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
                    ReplyTemplate::MEDIA_CAROUSEL => collect($t->media_config['cards'] ?? [])->sum(fn ($c) => count($c['buttons'] ?? [])),
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
