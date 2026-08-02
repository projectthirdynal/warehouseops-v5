<?php

namespace Tests\Feature\Controllers;

use App\Domain\Lead\Enums\PoolStatus;
use App\Domain\Lead\Models\Lead;
use App\Http\Middleware\VerifyCsrfToken;
use App\Models\RecyclingRule;
use App\Models\User;
use Database\Seeders\RecyclingRulesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RecyclingControllerTest extends TestCase
{
    use RefreshDatabase;

    private User $supervisor;

    protected function setUp(): void
    {
        parent::setUp();

        // Clean up pre-existing leads (PostgreSQL doesn't reset on rollback)
        Lead::query()->delete();

        $this->supervisor = User::factory()->create(['role' => 'supervisor']);
        $this->actingAs($this->supervisor);
        $this->withoutMiddleware([VerifyCsrfToken::class]);
        $this->seed(RecyclingRulesSeeder::class);
    }

    public function test_index_returns_inertia_view_with_data(): void
    {
        $response = $this->get('/recycling');

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page
            ->has('stats')
            ->has('rules')
        );
    }

    public function test_stats_returns_json(): void
    {
        $response = $this->get('/recycling/stats');

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'pool_size',
            'recycled_today',
            'avg_days_in_pool',
            'reassigned_today',
            'cooldown_count',
            'cooldown_expired',
            'exhausted_count',
            'available_count',
            'expired_callbacks',
            'rules_count',
            'outcome_breakdown',
        ]);
    }

    public function test_rules_index_returns_all_rules(): void
    {
        $response = $this->get('/recycling/rules');

        $response->assertStatus(200);
        $response->assertJsonCount(6); // 6 default seeded rules
    }

    public function test_store_rule_creates_new_rule(): void
    {
        // Delete the seeded NO_ANSWER rule first so we can create a new one
        RecyclingRule::where('outcome', 'NO_ANSWER')->delete();

        $response = $this->post('/recycling/rules', [
            'outcome' => 'NO_ANSWER',
            'cooldown_hours' => 48,
            'max_cycles' => 3,
            'next_action' => 'RECYCLE',
            'is_active' => true,
        ]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('recycling_rules', [
            'outcome' => 'NO_ANSWER',
            'cooldown_hours' => 48,
        ]);
    }

    public function test_store_rule_validates_outcome(): void
    {
        $response = $this->post('/recycling/rules', [
            'outcome' => 'INVALID_OUTCOME',
            'cooldown_hours' => 24,
            'max_cycles' => 3,
            'next_action' => 'RECYCLE',
            'is_active' => true,
        ]);

        $response->assertSessionHasErrors('outcome');
    }

    public function test_update_rule_modifies_existing(): void
    {
        $rule = RecyclingRule::where('outcome', 'NO_ANSWER')->first();

        $response = $this->patch("/recycling/rules/{$rule->id}", [
            'cooldown_hours' => 72,
            'max_cycles' => 10,
        ]);

        $response->assertStatus(200);
        $rule->refresh();
        $this->assertEquals(72, $rule->cooldown_hours);
        $this->assertEquals(10, $rule->max_cycles);
    }

    public function test_destroy_rule_deletes_existing(): void
    {
        $rule = RecyclingRule::where('outcome', 'INTERESTED')->first();

        $response = $this->delete("/recycling/rules/{$rule->id}");

        $response->assertStatus(204);
        $this->assertDatabaseMissing('recycling_rules', ['id' => $rule->id]);
    }

    public function test_trigger_processes_all(): void
    {
        Lead::factory()->create([
            'pool_status' => PoolStatus::COOLDOWN,
            'cooldown_until' => now()->subHour(),
            'total_cycles' => 1,
        ]);

        $response = $this->post('/recycling/trigger', ['type' => 'all']);

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'cooldown_processed',
            'callbacks_processed',
            'total_processed',
        ]);
    }

    public function test_trigger_cooldown_only(): void
    {
        Lead::factory()->create([
            'pool_status' => PoolStatus::COOLDOWN,
            'cooldown_until' => now()->subHour(),
            'total_cycles' => 1,
        ]);

        $response = $this->post('/recycling/trigger', ['type' => 'cooldown']);

        $response->assertStatus(200);
        $response->assertJsonPath('cooldown_processed', 1);
        $response->assertJsonPath('callbacks_processed', 0);
    }

    public function test_revive_exhausted_lead(): void
    {
        $lead = Lead::factory()->create([
            'pool_status' => PoolStatus::EXHAUSTED,
            'is_exhausted' => true,
        ]);

        $response = $this->post("/recycling/{$lead->id}/revive");

        $response->assertStatus(200);
        $lead->refresh();
        $this->assertEquals(PoolStatus::AVAILABLE, $lead->pool_status);
    }
}
