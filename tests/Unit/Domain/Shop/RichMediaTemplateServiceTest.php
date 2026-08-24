<?php

namespace Tests\Unit\Domain\Shop;

use App\Domain\Product\Models\Product;
use App\Domain\Shop\Services\RichMediaTemplateService;
use App\Models\ReplyTemplate;
use Database\Factories\Domain\Product\Models\ProductFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Tests\TestCase;

class RichMediaTemplateServiceTest extends TestCase
{
    use RefreshDatabase;

    private RichMediaTemplateService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new RichMediaTemplateService;
    }

    public function test_text_media_type_is_always_valid(): void
    {
        $result = $this->service->validateConfig(ReplyTemplate::MEDIA_TEXT, null);

        $this->assertTrue($result['valid']);
        $this->assertSame([], $result['errors']);
    }

    public function test_non_text_media_type_requires_config(): void
    {
        $result = $this->service->validateConfig(ReplyTemplate::MEDIA_BUTTON, null);

        $this->assertFalse($result['valid']);
        $this->assertContains('Media config is required for non-text templates.', $result['errors']);
    }

    public function test_button_template_requires_at_least_one_button(): void
    {
        $result = $this->service->validateConfig(ReplyTemplate::MEDIA_BUTTON, ['buttons' => []]);

        $this->assertFalse($result['valid']);
        $this->assertContains('At least 1 button is required.', $result['errors']);
    }

    public function test_button_template_rejects_more_than_three_buttons(): void
    {
        $buttons = [];

        for ($i = 0; $i < 4; $i++) {
            $buttons[] = ['title' => 'Btn '.$i, 'type' => 'postback', 'value' => 'PAYLOAD_'.$i];
        }

        $result = $this->service->validateConfig(ReplyTemplate::MEDIA_BUTTON, ['buttons' => $buttons]);

        $this->assertFalse($result['valid']);
        $this->assertContains('Maximum 3 buttons allowed.', $result['errors']);
    }

    public function test_button_validation_reports_missing_and_invalid_fields(): void
    {
        $result = $this->service->validateConfig(ReplyTemplate::MEDIA_BUTTON, [
            'buttons' => [
                ['title' => '', 'type' => 'postback', 'value' => 'X'],
                ['title' => 'Go', 'type' => 'carrier_pigeon', 'value' => 'X'],
                ['title' => 'Buy', 'type' => 'web_url', 'value' => 'not-a-url'],
                ['title' => 'Ok', 'type' => 'postback'],
            ],
        ]);

        $this->assertFalse($result['valid']);
        $this->assertContains('Button 0: title is required.', $result['errors']);
        $this->assertContains('Button 1: type must be postback, web_url, or phone_number.', $result['errors']);
        $this->assertContains('Button 2: value must be a valid URL for web_url buttons.', $result['errors']);
        $this->assertContains('Button 3: value is required.', $result['errors']);
    }

    public function test_button_title_length_is_enforced(): void
    {
        $result = $this->service->validateConfig(ReplyTemplate::MEDIA_BUTTON, [
            'buttons' => [
                ['title' => str_repeat('x', RichMediaTemplateService::BUTTON_TITLE_MAX_LENGTH + 1), 'type' => 'postback', 'value' => 'X'],
            ],
        ]);

        $this->assertFalse($result['valid']);
        $this->assertContains('Button 0: title must not exceed 20 characters.', $result['errors']);

        $valid = $this->service->validateConfig(ReplyTemplate::MEDIA_BUTTON, [
            'buttons' => [
                ['title' => str_repeat('x', RichMediaTemplateService::BUTTON_TITLE_MAX_LENGTH), 'type' => 'postback', 'value' => 'X'],
            ],
        ]);

        $this->assertTrue($valid['valid']);
    }

    public function test_valid_button_config_passes(): void
    {
        $result = $this->service->validateConfig(ReplyTemplate::MEDIA_BUTTON, [
            'buttons' => [
                ['title' => 'Order Now', 'type' => 'postback', 'value' => 'ORDER:1'],
                ['title' => 'Visit Site', 'type' => 'web_url', 'value' => 'https://example.com'],
                ['title' => 'Call Us', 'type' => 'phone_number', 'value' => '+639171234567'],
            ],
        ]);

        $this->assertTrue($result['valid'], print_r($result['errors'], true));
    }

    public function test_card_config_requires_title_and_subtitle(): void
    {
        $result = $this->service->validateConfig(ReplyTemplate::MEDIA_CARD, []);

        $this->assertFalse($result['valid']);
        $this->assertContains('Card title is required.', $result['errors']);
        $this->assertContains('Card subtitle is required.', $result['errors']);
    }

    public function test_card_config_enforces_element_lengths(): void
    {
        $result = $this->service->validateConfig(ReplyTemplate::MEDIA_CARD, [
            'title' => str_repeat('t', 81),
            'subtitle' => str_repeat('s', 81),
        ]);

        $this->assertFalse($result['valid']);
        $this->assertContains('Card title must not exceed 80 characters.', $result['errors']);
        $this->assertContains('Card subtitle must not exceed 80 characters.', $result['errors']);
    }

    public function test_card_config_rejects_invalid_image_and_action_urls(): void
    {
        $result = $this->service->validateConfig(ReplyTemplate::MEDIA_CARD, [
            'title' => 'Title',
            'subtitle' => 'Subtitle',
            'image_url' => 'not a valid url',
            'default_action_url' => 'nope',
        ]);

        $this->assertFalse($result['valid']);
        $this->assertContains('Card image URL must be a valid URL or storage path.', $result['errors']);
        $this->assertContains('Card default action URL must be a valid URL.', $result['errors']);
    }

    public function test_card_config_accepts_storage_image_path(): void
    {
        $result = $this->service->validateConfig(ReplyTemplate::MEDIA_CARD, [
            'title' => 'Title',
            'subtitle' => 'Subtitle',
            'image_url' => '/storage/products/shoe.jpg',
        ]);

        $this->assertTrue($result['valid']);
    }

    public function test_card_config_validates_nested_buttons(): void
    {
        $result = $this->service->validateConfig(ReplyTemplate::MEDIA_CARD, [
            'title' => 'Title',
            'subtitle' => 'Subtitle',
            'buttons' => [['type' => 'postback']],
        ]);

        $this->assertFalse($result['valid']);
        $this->assertContains('Card button 0: title is required.', $result['errors']);
        $this->assertContains('Card button 0: value is required.', $result['errors']);
    }

    public function test_carousel_requires_cards(): void
    {
        $result = $this->service->validateConfig(ReplyTemplate::MEDIA_CAROUSEL, ['cards' => []]);

        $this->assertFalse($result['valid']);
        $this->assertContains('Carousel requires at least one card.', $result['errors']);
    }

    public function test_carousel_limits_card_count(): void
    {
        $card = ['title' => 'T', 'subtitle' => 'S'];
        $result = $this->service->validateConfig(ReplyTemplate::MEDIA_CAROUSEL, [
            'cards' => array_fill(0, RichMediaTemplateService::MAX_CARDS + 1, $card),
        ]);

        $this->assertFalse($result['valid']);
        $this->assertContains('Maximum 10 cards per carousel.', $result['errors']);
    }

    public function test_carousel_prefixes_card_errors_with_index(): void
    {
        $result = $this->service->validateConfig(ReplyTemplate::MEDIA_CAROUSEL, [
            'cards' => [
                ['title' => 'Good Card', 'subtitle' => 'Fine'],
                ['title' => '', 'subtitle' => 'Missing Title'],
            ],
        ]);

        $this->assertFalse($result['valid']);
        $this->assertContains('Card 1: Card title is required.', $result['errors']);
    }

    public function test_unknown_media_type_is_invalid(): void
    {
        $result = $this->service->validateConfig('hologram', ['whatever' => true]);

        $this->assertFalse($result['valid']);
        $this->assertContains('Unknown media type: hologram', $result['errors']);
    }

    public function test_build_payload_for_button_template_maps_button_types(): void
    {
        $template = new ReplyTemplate([
            'content' => 'Choose an option',
            'media_type' => ReplyTemplate::MEDIA_BUTTON,
            'media_config' => [
                'buttons' => [
                    ['title' => 'Postback', 'type' => 'postback', 'value' => 'PB_1'],
                    ['title' => 'Website', 'type' => 'web_url', 'value' => 'https://example.com'],
                    ['title' => 'Hotline', 'type' => 'phone_number', 'value' => '+63917'],
                ],
            ],
        ]);

        $payload = $this->service->buildPayload($template);

        $inner = $payload['attachment']['payload'];
        $this->assertSame('template', $payload['attachment']['type']);
        $this->assertSame('button', $inner['template_type']);
        $this->assertSame('Choose an option', $inner['text']);
        $this->assertSame(['type' => 'postback', 'title' => 'Postback', 'payload' => 'PB_1'], $inner['buttons'][0]);
        $this->assertSame(['type' => 'web_url', 'title' => 'Website', 'url' => 'https://example.com'], $inner['buttons'][1]);
        $this->assertSame(['type' => 'phone_number', 'title' => 'Hotline', 'payload' => '+63917'], $inner['buttons'][2]);
    }

    public function test_build_payload_truncates_oversized_titles_defensively(): void
    {
        $template = new ReplyTemplate([
            'content' => 'Pick one',
            'media_type' => ReplyTemplate::MEDIA_BUTTON,
            'media_config' => [
                'buttons' => [
                    ['title' => str_repeat('B', 50), 'type' => 'postback', 'value' => 'X'],
                ],
            ],
        ]);

        $payload = $this->service->buildPayload($template);

        $this->assertSame(
            RichMediaTemplateService::BUTTON_TITLE_MAX_LENGTH,
            mb_strlen($payload['attachment']['payload']['buttons'][0]['title']),
        );
    }

    public function test_build_payload_for_card_resolves_storage_image_to_absolute_url(): void
    {
        $template = new ReplyTemplate([
            'title' => 'Fallback Title',
            'content' => '',
            'media_type' => ReplyTemplate::MEDIA_CARD,
            'media_config' => [
                'title' => 'Nike Air Max',
                'subtitle' => '₱4,500 · Nike',
                'image_url' => '/storage/products/airmax.jpg',
                'default_action_url' => 'https://shop.example.test/p/airmax',
            ],
        ]);

        $payload = $this->service->buildPayload($template);
        $element = $payload['attachment']['payload']['elements'][0];

        $this->assertSame('generic', $payload['attachment']['payload']['template_type']);
        $this->assertSame('Nike Air Max', $element['title']);
        $this->assertStringStartsWith('http', $element['image_url']);
        $this->assertStringEndsWith('/storage/products/airmax.jpg', $element['image_url']);
        $this->assertSame(['type' => 'web_url', 'url' => 'https://shop.example.test/p/airmax'], $element['default_action']);
        $this->assertArrayNotHasKey('buttons', $element);
    }

    public function test_build_payload_for_carousel_builds_all_elements(): void
    {
        $template = new ReplyTemplate([
            'content' => '',
            'media_type' => ReplyTemplate::MEDIA_CAROUSEL,
            'media_config' => [
                'cards' => [
                    ['title' => 'Card A', 'subtitle' => 'Sub A'],
                    ['title' => 'Card B', 'subtitle' => 'Sub B', 'buttons' => [
                        ['title' => 'Buy B', 'type' => 'postback', 'value' => 'ORDER:2'],
                    ]],
                ],
            ],
        ]);

        $payload = $this->service->buildPayload($template);
        $elements = $payload['attachment']['payload']['elements'];

        $this->assertCount(2, $elements);
        $this->assertSame('Card A', $elements[0]['title']);
        $this->assertArrayNotHasKey('buttons', $elements[0]);
        $this->assertSame([['type' => 'postback', 'title' => 'Buy B', 'payload' => 'ORDER:2']], $elements[1]['buttons']);
    }

    public function test_generate_carousel_from_products_formats_cards(): void
    {
        $productA = ProductFactory::new()->create([
            'name' => 'Rubber Shoes',
            'brand' => 'World Balance',
            'category' => 'Sports',
            'selling_price' => 1500,
            'image_url' => 'products/rubber-shoes.jpg',
        ]);
        $productB = ProductFactory::new()->create([
            'name' => 'Hoodie',
            'brand' => null,
            'category' => null,
            'selling_price' => 999.50,
            'image_url' => 'https://cdn.example.test/hoodie.png',
        ]);

        $config = $this->service->generateCarouselFromProducts(new Collection([$productA, $productB]), 10.0);

        $cards = $config['cards'];
        $this->assertCount(2, $cards);

        $this->assertSame('Rubber Shoes', $cards[0]['title']);
        $this->assertSame('₱1,350.00 (was ₱1,500.00) · World Balance · Sports', $cards[0]['subtitle']);
        $this->assertSame('/storage/products/rubber-shoes.jpg', $cards[0]['image_url']);
        $this->assertSame('ORDER_PRODUCT:'.$productA->id, $cards[0]['buttons'][0]['value']);

        $this->assertSame('₱899.55 (was ₱999.50)', $cards[1]['subtitle']);
        $this->assertSame('https://cdn.example.test/hoodie.png', $cards[1]['image_url']);
    }

    public function test_generate_carousel_caps_products_at_max_cards(): void
    {
        $products = ProductFactory::new()->count(RichMediaTemplateService::MAX_CARDS + 5)->create();

        $config = $this->service->generateCarouselFromProducts($products);

        $this->assertCount(RichMediaTemplateService::MAX_CARDS, $config['cards']);
    }

    public function test_preview_returns_errors_without_payload_when_invalid(): void
    {
        $preview = $this->service->preview(ReplyTemplate::MEDIA_BUTTON, 'Hello', ['buttons' => []]);

        $this->assertFalse($preview['valid']);
        $this->assertNotNull($preview['errors']);
        $this->assertNull($preview['payload']);
    }

    public function test_preview_returns_payload_when_valid(): void
    {
        $preview = $this->service->preview(
            ReplyTemplate::MEDIA_TEXT,
            'Plain message',
            null,
        );

        $this->assertTrue($preview['valid']);
        $this->assertSame(['text' => 'Plain message'], $preview['payload']);
    }

    public function test_stats_counts_templates_by_media_type(): void
    {
        ReplyTemplate::query()->create(['title' => 'Text', 'content' => 'Hi', 'media_type' => ReplyTemplate::MEDIA_TEXT]);
        $button = ReplyTemplate::query()->create([
            'title' => 'Buttons',
            'content' => 'Pick',
            'media_type' => ReplyTemplate::MEDIA_BUTTON,
            'media_config' => ['buttons' => [
                ['title' => 'One', 'type' => 'postback', 'value' => 'A'],
                ['title' => 'Two', 'type' => 'postback', 'value' => 'B'],
            ]],
        ]);
        ReplyTemplate::query()->create([
            'title' => 'Carousel',
            'content' => '',
            'media_type' => ReplyTemplate::MEDIA_CAROUSEL,
            'media_config' => ['cards' => [
                ['title' => 'C1', 'subtitle' => 's', 'buttons' => [['title' => 'x', 'type' => 'postback', 'value' => 'v']]],
                ['title' => 'C2', 'subtitle' => 's'],
            ]],
        ]);

        $stats = $this->service->stats();

        $this->assertSame(3, $stats['total']);
        $this->assertSame(1, $stats['text']);
        $this->assertSame(1, $stats['button']);
        $this->assertSame(1, $stats['carousel']);
        $this->assertSame(2, $stats['rich_total']);

        $recent = collect($stats['recent']);
        $buttonEntry = $recent->firstWhere('id', $button->id);
        $this->assertNotNull($buttonEntry);
        $this->assertSame('Buttons', $buttonEntry['media_type_label']);
        $this->assertSame(0, $buttonEntry['card_count']);
        $this->assertSame(2, $buttonEntry['button_count']);
    }

    public function test_product_model_has_image_url_column_not_image_path(): void
    {
        $product = Product::factory()->create(['image_url' => 'products/x.jpg']);

        $this->assertSame('products/x.jpg', $product->image_url);
        $this->assertFalse(isset($product->image_path));
    }
}
