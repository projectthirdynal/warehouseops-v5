<?php

namespace Tests\Unit\Services;

use App\Domain\Shop\Models\Conversation;
use App\Domain\Shop\Services\ConversationSlaService;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class ConversationSlaServiceTest extends TestCase
{
    private ConversationSlaService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new ConversationSlaService;
        Carbon::setTestNow(Carbon::parse('2026-08-23 12:00:00'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_unanswered_thread_breaches_after_threshold(): void
    {
        $snapshot = $this->service->classifyFirstResponse(
            createdAt: Carbon::parse('2026-08-23 10:00:00'),
            firstResponseAt: null,
            thresholdMinutes: 60,
        );

        $this->assertSame(ConversationSlaService::STATE_BREACHED, $snapshot['state']);
        $this->assertSame(120, $snapshot['minutes_elapsed']);
        $this->assertFalse($snapshot['responded_late']);
    }

    public function test_unanswered_thread_walks_pending_then_warning(): void
    {
        $created = Carbon::parse('2026-08-23 11:30:00');

        // 10 min elapsed (< 80% of 60) → pending; 50 min elapsed (≥ 48) → warning
        $pending = $this->service->classifyFirstResponse($created, null, 60, now: Carbon::parse('2026-08-23 11:40:00'));
        $warning = $this->service->classifyFirstResponse($created, null, 60, now: Carbon::parse('2026-08-23 12:20:00'));

        $this->assertSame(ConversationSlaService::STATE_PENDING, $pending['state']);
        $this->assertSame(ConversationSlaService::STATE_WARNING, $warning['state']);
    }

    public function test_timely_response_stays_ok_even_much_later(): void
    {
        // Responded in 5 minutes; three days pass. Must NOT breach — the old
        // implementation kept the clock running off first_response_at.
        $snapshot = $this->service->classifyFirstResponse(
            createdAt: Carbon::parse('2026-08-20 09:00:00'),
            firstResponseAt: Carbon::parse('2026-08-20 09:05:00'),
            thresholdMinutes: 60,
            now: Carbon::parse('2026-08-23 12:00:00'),
        );

        $this->assertSame(ConversationSlaService::STATE_OK, $snapshot['state']);
        $this->assertSame(5, $snapshot['minutes_elapsed']);
        $this->assertSame(5, $snapshot['response_minutes']);
        $this->assertFalse($snapshot['responded_late']);
    }

    public function test_late_response_is_recorded_as_breached_retroactively(): void
    {
        // Responded after double the threshold — a miss regardless of recency.
        $snapshot = $this->service->classifyFirstResponse(
            createdAt: Carbon::parse('2026-08-23 10:00:00'),
            firstResponseAt: Carbon::parse('2026-08-23 11:05:00'),
            thresholdMinutes: 60,
            now: Carbon::parse('2026-08-23 11:06:00'),
        );

        $this->assertSame(ConversationSlaService::STATE_BREACHED, $snapshot['state']);
        $this->assertTrue($snapshot['responded_late']);
        $this->assertSame(65, $snapshot['response_minutes']);
    }

    public function test_exact_boundary_response_counts_as_breach(): void
    {
        $snapshot = $this->service->classifyFirstResponse(
            createdAt: Carbon::parse('2026-08-23 11:00:00'),
            firstResponseAt: Carbon::parse('2026-08-23 12:00:00'),
            thresholdMinutes: 60,
            now: Carbon::parse('2026-08-23 12:01:00'),
        );

        $this->assertSame(ConversationSlaService::STATE_BREACHED, $snapshot['state']);
    }

    public function test_missing_created_at_yields_none(): void
    {
        $snapshot = $this->service->classifyFirstResponse(null, null, 60);

        $this->assertSame(ConversationSlaService::STATE_NONE, $snapshot['state']);
        $this->assertNull($snapshot['due_at']);
    }

    public function test_custom_warning_percent_widens_the_band(): void
    {
        $created = Carbon::parse('2026-08-23 11:25:00');
        $now = Carbon::parse('2026-08-23 12:00:00'); // 35 min elapsed

        $defaultBand = $this->service->classifyFirstResponse($created, null, 60, 80, $now);
        $wideBand = $this->service->classifyFirstResponse($created, null, 60, 50, $now);

        $this->assertSame(ConversationSlaService::STATE_PENDING, $defaultBand['state']);
        $this->assertSame(ConversationSlaService::STATE_WARNING, $wideBand['state']);
    }

    public function test_evaluate_skips_terminal_statuses_without_thresholds(): void
    {
        $conversation = (new Conversation)->forceFill([
            'status' => 'archived',
            'created_at' => Carbon::parse('2026-08-01 00:00:00'),
        ]);

        $snapshot = $this->service->evaluate($conversation, thresholds: ['archived' => null]);

        $this->assertSame(ConversationSlaService::STATE_NONE, $snapshot['state']);
        $this->assertNull($snapshot['threshold_minutes']);
    }

    public function test_explicit_null_override_disables_a_default_threshold(): void
    {
        $conversation = (new Conversation)->forceFill([
            'status' => 'new',
            'created_at' => Carbon::parse('2026-08-23 09:00:00'),
        ]);

        $disabled = $this->service->evaluate(
            $conversation,
            thresholds: ['new' => null],
            warningPercent: 80,
            now: Carbon::parse('2026-08-23 12:00:00'),
        );

        $this->assertSame(ConversationSlaService::STATE_NONE, $disabled['state']);
    }

    public function test_threshold_falls_back_to_model_defaults_when_not_overridden(): void
    {
        $conversation = (new Conversation)->forceFill([
            'status' => 'new',
            'created_at' => Carbon::parse('2026-08-23 09:00:00'),
        ]);

        // Explicit thresholds bypass SiteSetting entirely (no DB required).
        $snapshot = $this->service->evaluate(
            $conversation,
            thresholds: ['assigned' => 240],
            warningPercent: 80,
            now: Carbon::parse('2026-08-23 12:00:00'),
        );

        $this->assertSame(60, $snapshot['threshold_minutes']);
        $this->assertSame(ConversationSlaService::STATE_BREACHED, $snapshot['state']); // 180 > 60 default
    }
}
