<?php

namespace Tests\Unit\Services;

use App\Domain\Shop\Services\ProductRecommendationService;
use Tests\TestCase;

class ProductRecommendationServiceTest extends TestCase
{
    private ProductRecommendationService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new ProductRecommendationService;
    }

    public function test_cosine_similarity_of_identical_sets_is_one(): void
    {
        $this->assertSame(1.0, $this->service->cosineFromSets([1, 2, 3], [3, 2, 1]));
        $this->assertSame(1.0, $this->service->cosineFromCounts(5, 5, 5));
    }

    public function test_cosine_similarity_of_disjoint_sets_is_zero(): void
    {
        $this->assertSame(0.0, $this->service->cosineFromSets([1, 2], [3, 4]));
    }

    public function test_cosine_similarity_computes_overlap_ratio(): void
    {
        // overlap=2, sizes 3 and 4 → 2 / sqrt(12) ≈ 0.5774
        $this->assertEqualsWithDelta(0.5774, $this->service->cosineFromSets([1, 2, 3], [2, 3, 4, 5]), 0.0001);
        $this->assertSame(0.3536, $this->service->cosineFromSets([1, 2], [2, 3, 4, 5]));
    }

    public function test_cosine_handles_empty_and_zero_sizes(): void
    {
        $this->assertSame(0.0, $this->service->cosineFromSets([], [1, 2]));
        $this->assertSame(0.0, $this->service->cosineFromCounts(3, 0, 5));
        $this->assertSame(0.0, $this->service->cosineFromCounts(-1, 5, 5));
    }

    public function test_content_score_full_match_is_one(): void
    {
        $score = $this->service->contentScore(
            ['category' => 'Shoes', 'brand' => 'Nike', 'selling_price' => 100.0],
            ['category' => 'shoes ', 'brand' => 'NIKE', 'selling_price' => 100.0],
        );

        $this->assertSame(1.0, $score);
    }

    public function test_content_score_renormalises_when_target_has_no_brand(): void
    {
        // Only category (0.45) and price (0.25) apply; category matches exactly.
        $score = $this->service->contentScore(
            ['category' => 'Shoes', 'brand' => null, 'selling_price' => 100.0],
            ['category' => 'Shoes', 'brand' => null, 'selling_price' => 100.0],
        );

        $this->assertSame(1.0, $score);
    }

    public function test_content_score_price_only_proximity(): void
    {
        // Target without category/brand: price dim only. Candidate at half price → 0.5.
        $score = $this->service->contentScore(
            ['category' => null, 'brand' => null, 'selling_price' => 200.0],
            ['category' => 'Shoes', 'brand' => 'Nike', 'selling_price' => 100.0],
        );

        $this->assertSame(0.5, $score);
    }

    public function test_content_score_with_no_usable_dimensions_is_zero(): void
    {
        $score = $this->service->contentScore(
            ['category' => null, 'brand' => null, 'selling_price' => 0.0],
            ['category' => 'Shoes', 'brand' => 'Nike', 'selling_price' => 50.0],
        );

        $this->assertSame(0.0, $score);
    }

    public function test_hybrid_blend_weights_item_and_content_sixty_five_thirty_five(): void
    {
        $itemRows = [['id' => 10, 'score' => 1.0, 'frequency' => 7]];
        $contentRows = [['id' => 10, 'score' => 0.5, 'frequency' => 0]];

        $blended = $this->service->blendComponentRows($itemRows, $contentRows, 0.65, 0.35);

        $this->assertCount(1, $blended);
        $row = $blended[0];
        $this->assertSame(10, $row['id']);
        $this->assertEqualsWithDelta(0.65 * 1.0 + 0.35 * 0.5, $row['score'], 0.0001);
        $this->assertSame(1.0, $row['item_score']);
        $this->assertEqualsWithDelta(0.5, $row['content_score'], 0.0001);
    }

    public function test_hybrid_blend_treats_missing_component_as_zero(): void
    {
        $contentOnly = $this->service->blendComponentRows([], [['id' => 20, 'score' => 0.8, 'frequency' => 0]], 0.65, 0.35);

        $this->assertCount(1, $contentOnly);
        $this->assertEqualsWithDelta(0.35 * 0.8, $contentOnly[0]['score'], 0.0001);
        $this->assertSame(0.0, $contentOnly[0]['item_score']);
        $this->assertSame(0, $contentOnly[0]['frequency']);
    }

    public function test_hybrid_blend_orders_by_blended_score_then_frequency(): void
    {
        $itemRows = [
            ['id' => 1, 'score' => 0.30, 'frequency' => 9],
            ['id' => 2, 'score' => 0.31, 'frequency' => 2],
            ['id' => 3, 'score' => 0.30, 'frequency' => 9],
        ];

        $blended = $this->service->blendComponentRows($itemRows, [], 1.0, 0.0);

        // 2 wins on score; 1 and 3 tie → higher frequency first, then lower id.
        $this->assertSame([2, 1, 3], array_column($blended, 'id'));
    }
}
