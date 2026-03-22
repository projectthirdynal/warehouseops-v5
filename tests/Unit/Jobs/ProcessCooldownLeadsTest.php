<?php

namespace Tests\Unit\Jobs;

use App\Domain\Lead\Enums\PoolStatus;
use App\Domain\Lead\Models\Lead;
use App\Jobs\ProcessCooldownLeads;
use App\Services\LeadAuditService;
use App\Services\LeadPoolService;
use App\Services\LeadRecyclingService;
use Database\Seeders\RecyclingRulesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class ProcessCooldownLeadsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RecyclingRulesSeeder::class);
    }

    public function test_job_processes_expired_cooldown_leads(): void
    {
        // Create leads with expired cooldown
        $lead1 = Lead::factory()->create([
            'pool_status' => PoolStatus::COOLDOWN,
            'cooldown_until' => now()->subHour(),
            'total_cycles' => 1,
        ]);

        $lead2 = Lead::factory()->create([
            'pool_status' => PoolStatus::COOLDOWN,
            'cooldown_until' => now()->subMinutes(30),
            'total_cycles' => 1,
        ]);

        // Create lead still in cooldown (not expired)
        $lead3 = Lead::factory()->create([
            'pool_status' => PoolStatus::COOLDOWN,
            'cooldown_until' => now()->addHour(),
            'total_cycles' => 1,
        ]);

        Log::shouldReceive('info')
            ->once()
            ->withArgs(function ($message) {
                return str_contains($message, 'Processed 2 leads');
            });

        $job = new ProcessCooldownLeads();
        $job->handle(app(LeadRecyclingService::class));

        $lead1->refresh();
        $lead2->refresh();
        $lead3->refresh();

        $this->assertEquals(PoolStatus::AVAILABLE, $lead1->pool_status);
        $this->assertEquals(PoolStatus::AVAILABLE, $lead2->pool_status);
        $this->assertEquals(PoolStatus::COOLDOWN, $lead3->pool_status);
    }

    public function test_job_logs_processed_count(): void
    {
        // Create a lead with expired cooldown
        Lead::factory()->create([
            'pool_status' => PoolStatus::COOLDOWN,
            'cooldown_until' => now()->subHour(),
            'total_cycles' => 1,
        ]);

        Log::shouldReceive('info')
            ->once()
            ->withArgs(function ($message) {
                return str_contains($message, 'ProcessCooldownLeads:') &&
                       str_contains($message, 'Processed 1 leads');
            });

        $job = new ProcessCooldownLeads();
        $job->handle(app(LeadRecyclingService::class));
    }

    public function test_job_handles_empty_cooldown_queue(): void
    {
        Log::shouldReceive('info')
            ->once()
            ->withArgs(function ($message) {
                return str_contains($message, 'Processed 0 leads');
            });

        $job = new ProcessCooldownLeads();
        $job->handle(app(LeadRecyclingService::class));
    }
}
