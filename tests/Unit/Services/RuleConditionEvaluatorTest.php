<?php

namespace Tests\Unit\Services;

use App\Domain\Lead\Enums\LeadSource;
use App\Domain\Lead\Models\Lead;
use App\Models\DistributionRule;
use App\Services\RuleConditionEvaluator;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class RuleConditionEvaluatorTest extends TestCase
{
    use DatabaseTransactions;

    private RuleConditionEvaluator $evaluator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->evaluator = new RuleConditionEvaluator;
    }

    public function test_returns_true_when_rule_has_no_filters(): void
    {
        $lead = Lead::factory()->create();
        $rule = DistributionRule::factory()->create(['filters' => null]);

        $this->assertTrue($this->evaluator->matches($lead, $rule));
    }

    public function test_returns_true_when_conditions_key_is_empty(): void
    {
        $lead = Lead::factory()->create();
        $rule = DistributionRule::factory()->create([
            'filters' => ['regions' => ['Metro Manila']],
        ]);

        $this->assertTrue($this->evaluator->matches($lead, $rule));
    }

    public function test_min_quality_score_passes_when_lead_score_above_threshold(): void
    {
        $lead = Lead::factory()->create(['quality_score' => 80]);
        $rule = DistributionRule::factory()->create([
            'filters' => ['conditions' => ['min_quality_score' => 70]],
        ]);

        $this->assertTrue($this->evaluator->matches($lead, $rule));
    }

    public function test_min_quality_score_fails_when_lead_score_below_threshold(): void
    {
        $lead = Lead::factory()->create(['quality_score' => 50]);
        $rule = DistributionRule::factory()->create([
            'filters' => ['conditions' => ['min_quality_score' => 70]],
        ]);

        $this->assertFalse($this->evaluator->matches($lead, $rule));
    }

    public function test_max_quality_score_fails_when_lead_score_above_threshold(): void
    {
        $lead = Lead::factory()->create(['quality_score' => 90]);
        $rule = DistributionRule::factory()->create([
            'filters' => ['conditions' => ['max_quality_score' => 70]],
        ]);

        $this->assertFalse($this->evaluator->matches($lead, $rule));
    }

    public function test_quality_score_range_matches_when_within_bounds(): void
    {
        $lead = Lead::factory()->create(['quality_score' => 75]);
        $rule = DistributionRule::factory()->create([
            'filters' => ['conditions' => [
                'min_quality_score' => 70,
                'max_quality_score' => 80,
            ]],
        ]);

        $this->assertTrue($this->evaluator->matches($lead, $rule));
    }

    public function test_lead_regions_matches_by_state(): void
    {
        $lead = Lead::factory()->create(['state' => 'Metro Manila']);
        $rule = DistributionRule::factory()->create([
            'filters' => ['conditions' => ['lead_regions' => ['Metro Manila', 'Cebu']]],
        ]);

        $this->assertTrue($this->evaluator->matches($lead, $rule));
    }

    public function test_lead_regions_matches_by_city_case_insensitive(): void
    {
        $lead = Lead::factory()->create(['city' => 'Quezon City']);
        $rule = DistributionRule::factory()->create([
            'filters' => ['conditions' => ['lead_regions' => ['quezon city']]],
        ]);

        $this->assertTrue($this->evaluator->matches($lead, $rule));
    }

    public function test_lead_regions_fails_when_no_match(): void
    {
        $lead = Lead::factory()->create(['state' => 'Davao', 'city' => 'Davao City', 'barangay' => 'Buhangin']);
        $rule = DistributionRule::factory()->create([
            'filters' => ['conditions' => ['lead_regions' => ['Metro Manila']]],
        ]);

        $this->assertFalse($this->evaluator->matches($lead, $rule));
    }

    public function test_lead_products_matches_case_insensitive_contains(): void
    {
        $lead = Lead::factory()->create(['product_name' => 'Samsung Galaxy S21']);
        $rule = DistributionRule::factory()->create([
            'filters' => ['conditions' => ['lead_products' => ['samsung']]],
        ]);

        $this->assertTrue($this->evaluator->matches($lead, $rule));
    }

    public function test_lead_products_fails_when_no_match(): void
    {
        $lead = Lead::factory()->create(['product_name' => 'iPhone 15']);
        $rule = DistributionRule::factory()->create([
            'filters' => ['conditions' => ['lead_products' => ['Samsung']]],
        ]);

        $this->assertFalse($this->evaluator->matches($lead, $rule));
    }

    public function test_lead_products_fails_when_lead_has_no_product(): void
    {
        $lead = Lead::factory()->create(['product_name' => null]);
        $rule = DistributionRule::factory()->create([
            'filters' => ['conditions' => ['lead_products' => ['Samsung']]],
        ]);

        $this->assertFalse($this->evaluator->matches($lead, $rule));
    }

    public function test_lead_sources_matches_by_enum_value(): void
    {
        $lead = Lead::factory()->create(['source' => LeadSource::WAYBILL]);
        $rule = DistributionRule::factory()->create([
            'filters' => ['conditions' => ['lead_sources' => ['WAYBILL', 'XLSX_IMPORT']]],
        ]);

        $this->assertTrue($this->evaluator->matches($lead, $rule));
    }

    public function test_lead_sources_fails_when_source_not_in_list(): void
    {
        $lead = Lead::factory()->create(['source' => LeadSource::MANUAL]);
        $rule = DistributionRule::factory()->create([
            'filters' => ['conditions' => ['lead_sources' => ['WAYBILL']]],
        ]);

        $this->assertFalse($this->evaluator->matches($lead, $rule));
    }

    public function test_min_amount_passes_when_lead_amount_above_threshold(): void
    {
        $lead = Lead::factory()->create(['amount' => 5000]);
        $rule = DistributionRule::factory()->create([
            'filters' => ['conditions' => ['min_amount' => 1000]],
        ]);

        $this->assertTrue($this->evaluator->matches($lead, $rule));
    }

    public function test_max_amount_fails_when_lead_amount_above_threshold(): void
    {
        $lead = Lead::factory()->create(['amount' => 10000]);
        $rule = DistributionRule::factory()->create([
            'filters' => ['conditions' => ['max_amount' => 5000]],
        ]);

        $this->assertFalse($this->evaluator->matches($lead, $rule));
    }

    public function test_combined_conditions_all_must_pass(): void
    {
        $lead = Lead::factory()->create([
            'quality_score' => 85,
            'state' => 'Metro Manila',
            'product_name' => 'Samsung Galaxy',
            'source' => LeadSource::WAYBILL,
            'amount' => 3000,
        ]);
        $rule = DistributionRule::factory()->create([
            'filters' => ['conditions' => [
                'min_quality_score' => 80,
                'lead_regions' => ['Metro Manila'],
                'lead_products' => ['Samsung'],
                'lead_sources' => ['WAYBILL'],
                'min_amount' => 1000,
                'max_amount' => 5000,
            ]],
        ]);

        $this->assertTrue($this->evaluator->matches($lead, $rule));
    }

    public function test_combined_conditions_fail_if_any_fails(): void
    {
        $lead = Lead::factory()->create([
            'quality_score' => 50,
            'state' => 'Metro Manila',
        ]);
        $rule = DistributionRule::factory()->create([
            'filters' => ['conditions' => [
                'min_quality_score' => 80,
                'lead_regions' => ['Metro Manila'],
            ]],
        ]);

        $this->assertFalse($this->evaluator->matches($lead, $rule));
    }

    public function test_null_rule_always_matches(): void
    {
        $lead = Lead::factory()->create();

        $this->assertTrue($this->evaluator->matches($lead, null));
    }
}
