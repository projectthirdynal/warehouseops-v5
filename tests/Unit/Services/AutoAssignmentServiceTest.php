<?php

namespace Tests\Unit\Services;

use App\Domain\Shop\Models\Conversation;
use App\Domain\Shop\Services\AutoAssignmentService;
use App\Models\AgentProfile;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Tests\TestCase;

class AutoAssignmentServiceTest extends TestCase
{
    private AutoAssignmentService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new AutoAssignmentService;
        Carbon::setTestNow(Carbon::parse('2026-08-23 12:00:00'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function makeProfile(array $overrides = []): AgentProfile
    {
        $data = array_merge([
            'user_id' => 1,
            'auto_assign_enabled' => true,
            'is_available' => true,
            'product_skills' => [],
            'regions' => [],
            'category_skills' => [],
            'max_active_conversations' => 15,
            'concurrent_lead_cap' => null,
            'max_daily_leads' => null,
            'overflow_enabled' => true,
            'shift_start' => null,
            'shift_end' => null,
            'last_assignment_at' => null,
            'performance_score' => 50,
        ], $overrides);

        return (new AgentProfile)->forceFill($data);
    }

    private function makeUser(int $id, AgentProfile $profile, string $role = 'agent'): User
    {
        $user = (new User)->forceFill([
            'id' => $id,
            'name' => "Agent {$id}",
            'role' => $role,
            'is_active' => true,
        ]);
        $user->setRelation('agentProfile', $profile);

        return $user;
    }

    // -------------------------------------------------------------------------
    // Settings (require DB - tested in Feature suite)
    // -------------------------------------------------------------------------

    /*
    public function test_is_enabled_defaults_to_true(): void
    {
        $this->assertTrue($this->service->isEnabled());
    }

    public function test_strategy_defaults_to_hybrid(): void
    {
        $this->assertSame(AutoAssignmentService::STRATEGY_HYBRID, $this->service->getStrategy());
    }

    public function test_queue_limit_uses_profile_cap_when_set(): void
    {
        $profile = $this->makeProfile(['concurrent_lead_cap' => 8]);
        $this->assertSame(8, $this->service->queueLimit($profile));
    }

    public function test_queue_limit_falls_back_to_setting_then_default(): void
    {
        $profile = $this->makeProfile(['concurrent_lead_cap' => null]);
        $this->assertSame(15, $this->service->queueLimit($profile)); // default 15
    }

    public function test_daily_limit_uses_profile_cap_when_set(): void
    {
        $profile = $this->makeProfile(['max_daily_leads' => 30]);
        $this->assertSame(30, $this->service->dailyLimit($profile));
    }

    public function test_daily_limit_falls_back_to_setting_then_default(): void
    {
        $profile = $this->makeProfile(['max_daily_leads' => null]);
        $this->assertSame(50, $this->service->dailyLimit($profile)); // default 50
    }
    */

    // -------------------------------------------------------------------------
    // Shift window
    // -------------------------------------------------------------------------

    public function test_agents_without_shift_are_always_in_shift(): void
    {
        $profile = $this->makeProfile(['shift_start' => null, 'shift_end' => null]);
        $this->assertTrue($this->service->isWithinShift($profile, Carbon::parse('03:00:00')));
    }

    public function test_day_shift_window(): void
    {
        $profile = $this->makeProfile(['shift_start' => '09:00:00', 'shift_end' => '17:00:00']);
        $this->assertTrue($this->service->isWithinShift($profile, Carbon::parse('12:00:00')));
        $this->assertFalse($this->service->isWithinShift($profile, Carbon::parse('08:00:00')));
        $this->assertFalse($this->service->isWithinShift($profile, Carbon::parse('18:00:00')));
    }

    public function test_overnight_shift_window(): void
    {
        $profile = $this->makeProfile(['shift_start' => '22:00:00', 'shift_end' => '06:00:00']);
        $this->assertTrue($this->service->isWithinShift($profile, Carbon::parse('23:00:00')));
        $this->assertTrue($this->service->isWithinShift($profile, Carbon::parse('02:00:00')));
        $this->assertTrue($this->service->isWithinShift($profile, Carbon::parse('05:59:00')));
        $this->assertFalse($this->service->isWithinShift($profile, Carbon::parse('12:00:00')));
        $this->assertFalse($this->service->isWithinShift($profile, Carbon::parse('06:01:00')));
    }

    // -------------------------------------------------------------------------
    // Skill scoring
    // -------------------------------------------------------------------------

    public function test_skill_score_neutral_when_no_applicable_dimensions(): void
    {
        $profile = $this->makeProfile();
        $context = ['product' => null, 'region' => null, 'category' => null];
        $this->assertSame(0.5, $this->service->skillScore($profile, $context));
    }

    public function test_skill_score_exact_match_returns_one(): void
    {
        $profile = $this->makeProfile([
            'product_skills' => ['fashion'],
            'regions' => ['NCR'],
            'category_skills' => ['apparel'],
        ]);
        $context = ['product' => 'Fashion', 'region' => 'NCR', 'category' => 'Apparel'];
        $this->assertSame(1.0, $this->service->skillScore($profile, $context));
    }

    public function test_skill_score_substring_match_both_directions(): void
    {
        $profile = $this->makeProfile(['product_skills' => ['fashion']]);
        $context = ['product' => 'Fashion & Apparel', 'region' => null, 'category' => null];
        $this->assertSame(1.0, $this->service->skillScore($profile, $context));

        $profile2 = $this->makeProfile(['product_skills' => ['fashion & apparel']]);
        $context2 = ['product' => 'Fashion', 'region' => null, 'category' => null];
        $this->assertSame(1.0, $this->service->skillScore($profile2, $context2));
    }

    public function test_skill_score_partial_match_averages(): void
    {
        $profile = $this->makeProfile([
            'product_skills' => ['fashion'],
            'regions' => ['NCR'],
            'category_skills' => [],
        ]);
        $context = ['product' => 'Fashion', 'region' => 'VISAYAS', 'category' => null];
        // product matches (1.0), region misses (0.0), category N/A → average = 0.5
        $this->assertSame(0.5, $this->service->skillScore($profile, $context));
    }

    public function test_skill_score_empty_skill_list_is_neutral(): void
    {
        $profile = $this->makeProfile(['product_skills' => []]);
        $context = ['product' => 'Fashion', 'region' => null, 'category' => null];
        $this->assertSame(0.5, $this->service->skillScore($profile, $context));
    }

    // -------------------------------------------------------------------------
    // Recency score
    // -------------------------------------------------------------------------

    public function test_recency_score_never_assigned_is_one(): void
    {
        $profile = $this->makeProfile(['last_assignment_at' => null]);
        $this->assertSame(1.0, $this->service->recencyScore($profile));
    }

    public function test_recency_score_linear_over_24h(): void
    {
        $profile = $this->makeProfile(['last_assignment_at' => Carbon::parse('2026-08-22 12:00:00')]);
        $this->assertSame(1.0, $this->service->recencyScore($profile)); // 24h idle = 1.0 (capped)

        $profile2 = $this->makeProfile(['last_assignment_at' => Carbon::parse('2026-08-23 06:00:00')]);
        $this->assertSame(0.25, $this->service->recencyScore($profile2)); // 6h idle = 0.25
    }

    // -------------------------------------------------------------------------
    // Context extraction
    // -------------------------------------------------------------------------

    public function test_context_for_extracts_metadata_and_customer_region(): void
    {
        $conversation = (new Conversation)->forceFill([
            'metadata' => ['product' => 'Shoes', 'category' => 'Footwear', 'region' => 'CEBU'],
            'facebook_page_id' => 1,
        ]);

        $page = (new \App\Domain\Shop\Models\FacebookPage)->forceFill(['category' => 'Sports']);
        $conversation->setRelation('facebookPage', $page);

        $customer = (new \App\Models\Customer)->forceFill(['region' => 'MANILA', 'province' => null, 'city_municipality' => null]);
        $conversation->setRelation('customer', $customer);

        $context = $this->service->contextFor($conversation);

        $this->assertSame('Shoes', $context['product']);
        $this->assertSame('CEBU', $context['region']); // metadata wins over customer
        $this->assertSame('Footwear', $context['category']);
    }

    public function test_context_for_falls_back_to_page_category(): void
    {
        $conversation = (new Conversation)->forceFill(['metadata' => [], 'facebook_page_id' => 1]);
        $page = (new \App\Domain\Shop\Models\FacebookPage)->forceFill(['category' => 'Electronics']);
        $conversation->setRelation('facebookPage', $page);
        $conversation->setRelation('customer', null);

        $context = $this->service->contextFor($conversation);
        $this->assertSame('Electronics', $context['category']);
    }

    // -------------------------------------------------------------------------
    // Strategy selectors (pure functions on collections)
    // -------------------------------------------------------------------------

    private function buildCandidates(array $profiles): Collection
    {
        $collection = new Collection;
        foreach ($profiles as $i => $profile) {
            $user = $this->makeUser($i + 1, $profile);
            $collection->put($user->id, [
                'user' => $user,
                'profile' => $profile,
                'active_count' => 0,
            ]);
        }
        return $collection;
    }

    public function test_pick_by_round_robin_prefers_never_assigned_then_oldest(): void
    {
        $p1 = $this->makeProfile(['last_assignment_at' => Carbon::parse('2026-08-23 10:00:00')]);
        $p2 = $this->makeProfile(['last_assignment_at' => null]);
        $p3 = $this->makeProfile(['last_assignment_at' => Carbon::parse('2026-08-23 11:00:00')]);

        $candidates = $this->buildCandidates([$p1, $p2, $p3]);

        $chosen = $this->service->pickByRoundRobin($candidates);
        $this->assertSame(2, $chosen['user']->id); // never assigned wins
    }

    public function test_pick_by_workload_prefers_fewest_active_then_oldest(): void
    {
        $p1 = $this->makeProfile(['last_assignment_at' => Carbon::parse('2026-08-23 10:00:00')]);
        $p2 = $this->makeProfile(['last_assignment_at' => Carbon::parse('2026-08-23 11:00:00')]);
        $p3 = $this->makeProfile(['last_assignment_at' => Carbon::parse('2026-08-23 09:00:00')]);

        $candidates = new Collection;
        $candidates->put(1, ['user' => $this->makeUser(1, $p1), 'profile' => $p1, 'active_count' => 2]);
        $candidates->put(2, ['user' => $this->makeUser(2, $p2), 'profile' => $p2, 'active_count' => 1]);
        $candidates->put(3, ['user' => $this->makeUser(3, $p3), 'profile' => $p3, 'active_count' => 1]); // tie, oldest (09:00) wins

        $chosen = $this->service->pickByWorkload($candidates);
        $this->assertSame(3, $chosen['user']->id);
    }

    public function test_pick_by_skill_score_prefers_highest_skill_then_performance(): void
    {
        $p1 = $this->makeProfile(['product_skills' => ['fashion'], 'performance_score' => 50]);
        $p2 = $this->makeProfile(['product_skills' => ['fashion'], 'performance_score' => 80]);
        $p3 = $this->makeProfile(['product_skills' => ['electronics'], 'performance_score' => 90]);

        $candidates = $this->buildCandidates([$p1, $p2, $p3]);
        $conversation = (new Conversation)->forceFill(['metadata' => ['product' => 'Fashion']]);
        $conversation->setRelation('customer', null);
        $conversation->setRelation('facebookPage', (new \App\Domain\Shop\Models\FacebookPage)->forceFill(['category' => null]));

        $chosen = $this->service->pickBySkillScore($candidates, $conversation);
        $this->assertSame(2, $chosen['user']->id); // same skill, higher performance
    }

    //    public function test_pick_by_hybrid_blends_weights(): void
//    {
//        // p1: high skill (1.0), medium load (0.5), old assignment (0.0)
//        // p2: medium skill (0.5), low load (1.0), recent (0.0)
//        // p3: low skill (0.0), no load (1.0), never assigned (1.0)
//        $p1 = $this->makeProfile([
//            'product_skills' => ['fashion'],
//            'last_assignment_at' => Carbon::parse('2026-08-23 11:59:00'),
//        ]);
//        $p2 = $this->makeProfile([
//            'product_skills' => ['electronics'],
//            'last_assignment_at' => Carbon::parse('2026-08-23 11:59:00'),
//        ]);
//        $p3 = $this->makeProfile([
//            'product_skills' => ['beauty'],
//            'last_assignment_at' => null,
//        ]);
//
//        $candidates = new Collection;
//        $candidates->put(1, ['user' => $this->makeUser(1, $p1), 'profile' => $p1, 'active_count' => 7]); // load 7/15=0.47 → 1-0.47=0.53
//        $candidates->put(2, ['user' => $this->makeUser(2, $p2), 'profile' => $p2, 'active_count' => 1]); // load 1/15=0.07 → 0.93
//        $candidates->put(3, ['user' => $this->makeUser(3, $p3), 'profile' => $p3, 'active_count' => 0]); // load 0 → 1.0
//
//        $conversation = (new Conversation)->forceFill(['metadata' => ['product' => 'Fashion']]);
//        $conversation->setRelation('customer', null);
//        $conversation->setRelation('facebookPage', (new \App\Domain\Shop\Models\FacebookPage)->forceFill(['category' => null]));
//
//        $chosen = $this->service->pickByHybrid($candidates, $conversation);
//        // Hybrid = 0.45*skill + 0.35*workload + 0.20*recency
//        // p1: 0.45*1.0 + 0.35*0.53 + 0.20*0.0 = 0.45 + 0.186 + 0 = 0.636
//        // p2: 0.45*0.5 + 0.35*0.93 + 0.20*0.0 = 0.225 + 0.326 + 0 = 0.551
//        // p3: 0.45*0.0 + 0.35*1.0 + 0.20*1.0 = 0 + 0.35 + 0.20 = 0.55
//        // p1 wins
//        $this->assertSame(1, $chosen['user']->id);
//    }

    // -------------------------------------------------------------------------
    // pickByStrategy dispatcher
    // -------------------------------------------------------------------------

    public function test_pick_by_strategy_dispatches_correctly(): void
    {
        $p1 = $this->makeProfile(['last_assignment_at' => Carbon::parse('2026-08-23 10:00:00'), 'product_skills' => ['fashion']]);
        $p2 = $this->makeProfile(['last_assignment_at' => null, 'product_skills' => []]);
        $candidates = $this->buildCandidates([$p1, $p2]);

        $conversation = (new Conversation)->forceFill(['metadata' => ['product' => 'Fashion']]);
        $conversation->setRelation('customer', null);
        $conversation->setRelation('facebookPage', (new \App\Domain\Shop\Models\FacebookPage)->forceFill(['category' => null]));

        $rr = $this->service->pickByStrategy($candidates, $conversation, AutoAssignmentService::STRATEGY_ROUND_ROBIN);
        $this->assertSame(2, $rr['user']->id); // never assigned wins

        $wl = $this->service->pickByStrategy($candidates, $conversation, AutoAssignmentService::STRATEGY_WORKLOAD);
        $this->assertSame(2, $wl['user']->id); // never assigned wins (active_count=0, null timestamp)

        $sk = $this->service->pickByStrategy($candidates, $conversation, AutoAssignmentService::STRATEGY_SKILL_BASED);
        $this->assertSame(1, $sk['user']->id); // p1 has fashion skill match

        // Hybrid depends on queueLimit() which reads SiteSetting (DB) — tested in Feature suite
    }

    public function test_pick_by_strategy_returns_null_for_empty(): void
    {
        $this->assertNull($this->service->pickByStrategy(new Collection, new Conversation, AutoAssignmentService::STRATEGY_ROUND_ROBIN));
    }
}