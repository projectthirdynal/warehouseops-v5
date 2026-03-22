# Lead Pool Management System Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build a secure lead distribution system replacing spreadsheets, with click-to-call, audit trails, and automated recycling for 75 agents.

**Architecture:** Laravel backend services handle lead pool management, distribution, and recycling. React/Inertia frontend provides agent and supervisor interfaces. MicroSIP integration via sip: protocol links. Background jobs process cooldowns and fraud detection.

**Tech Stack:** Laravel 10, Inertia.js, React 18, TypeScript, PostgreSQL, shadcn/ui components, Laravel Queues

**Spec Reference:** [2026-03-22-lead-pool-management-design.md](../specs/2026-03-22-lead-pool-management-design.md)

---

## File Structure

### Backend (app/)

```
app/
├── Http/
│   ├── Controllers/
│   │   ├── LeadPoolController.php        # Supervisor: pool management, distribution
│   │   ├── AgentLeadController.php       # Agent: my leads, call, outcome
│   │   └── LeadImportController.php      # CSV import
│   ├── Middleware/
│   │   └── HidePhoneNumber.php           # Strip phone from agent responses
│   └── Resources/
│       ├── AgentLeadResource.php         # Lead without phone for agents
│       └── LeadPoolResource.php          # Lead with phone for supervisors
├── Services/
│   ├── LeadPoolService.php               # Pool state management
│   ├── LeadDistributionService.php       # Distribution logic
│   ├── LeadRecyclingService.php          # Outcome rules, cooldown
│   ├── LeadAuditService.php              # Audit trail logging
│   ├── CallTrackingService.php           # Click-to-call, sip: links
│   └── FraudDetectionService.php         # Pattern detection, flags
├── Models/
│   ├── Lead.php                          # (modify) Add pool_status, scopes
│   ├── LeadCycle.php                     # (modify) Add call tracking
│   ├── RecyclingRule.php                 # Outcome-based rules
│   ├── FraudFlag.php                     # Fraud detection flags
│   └── LeadPoolAudit.php                 # Audit log entries
├── Jobs/
│   ├── ProcessCooldownLeads.php          # Move expired cooldowns
│   ├── DetectFraudPatterns.php           # Analyze agent behavior
│   └── CreateLeadFromWaybill.php         # Auto-create on delivery
├── Observers/
│   └── WaybillObserver.php               # Trigger lead creation
└── Enums/
    ├── PoolStatus.php                    # AVAILABLE, ASSIGNED, COOLDOWN, EXHAUSTED
    └── LeadOutcome.php                   # NO_ANSWER, CALLBACK, INTERESTED, etc.
```

### Frontend (resources/js/)

```
resources/js/
├── pages/
│   ├── LeadPool/
│   │   ├── Index.tsx                     # Supervisor: pool view + distribution
│   │   ├── AgentPerformance.tsx          # Agent stats grid
│   │   └── ActivityFeed.tsx              # Real-time activity
│   ├── AgentLeads/
│   │   ├── Index.tsx                     # Agent: my leads list
│   │   ├── LeadCard.tsx                  # Individual lead card
│   │   └── OutcomeModal.tsx              # Outcome selection
│   └── Admin/
│       └── RecyclingRules.tsx            # Configure rules
├── components/
│   ├── leads/
│   │   ├── CallButton.tsx                # Click-to-call
│   │   ├── DistributionModal.tsx         # Distribution form
│   │   └── LeadAuditTimeline.tsx         # Audit trail viewer
│   └── ui/                               # (existing shadcn components)
└── types/
    └── lead-pool.ts                      # TypeScript interfaces
```

### Database

```
database/migrations/
├── 2026_03_22_000001_add_pool_status_to_leads.php
├── 2026_03_22_000002_add_call_tracking_to_lead_cycles.php
├── 2026_03_22_000003_create_recycling_rules_table.php
├── 2026_03_22_000004_create_fraud_flags_table.php
└── 2026_03_22_000005_create_lead_pool_audit_table.php
```

---

## Phase 1: Core Infrastructure

### Task 1: Database Migrations - Pool Status

**Files:**
- Create: `database/migrations/2026_03_22_000001_add_pool_status_to_leads.php`
- Modify: `app/Models/Lead.php`

- [ ] **Step 1: Create migration for pool_status**

```bash
php artisan make:migration add_pool_status_to_leads
```

- [ ] **Step 2: Write migration content**

```php
// database/migrations/2026_03_22_000001_add_pool_status_to_leads.php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->string('pool_status')->default('AVAILABLE')->after('is_exhausted');
            $table->timestamp('cooldown_until')->nullable()->after('pool_status');
            $table->index(['pool_status']);
            $table->index(['pool_status', 'cooldown_until']);
        });
    }

    public function down(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->dropIndex(['pool_status']);
            $table->dropIndex(['pool_status', 'cooldown_until']);
            $table->dropColumn(['pool_status', 'cooldown_until']);
        });
    }
};
```

- [ ] **Step 3: Run migration**

```bash
php artisan migrate
```
Expected: Migration runs successfully

- [ ] **Step 4: Create PoolStatus enum**

```php
// app/Enums/PoolStatus.php
<?php

namespace App\Enums;

enum PoolStatus: string
{
    case AVAILABLE = 'AVAILABLE';
    case ASSIGNED = 'ASSIGNED';
    case COOLDOWN = 'COOLDOWN';
    case EXHAUSTED = 'EXHAUSTED';
}
```

- [ ] **Step 5: Update Lead model with pool_status**

Add to `app/Models/Lead.php`:

```php
use App\Enums\PoolStatus;

// Add to $fillable array:
'pool_status',
'cooldown_until',

// Add to $casts array:
'pool_status' => PoolStatus::class,
'cooldown_until' => 'datetime',

// Add scopes:
public function scopeAvailable($query)
{
    return $query->where('pool_status', PoolStatus::AVAILABLE);
}

public function scopeAssigned($query)
{
    return $query->where('pool_status', PoolStatus::ASSIGNED);
}

public function scopeInCooldown($query)
{
    return $query->where('pool_status', PoolStatus::COOLDOWN);
}

public function scopeExhausted($query)
{
    return $query->where('pool_status', PoolStatus::EXHAUSTED);
}

public function scopeCooldownExpired($query)
{
    return $query->where('pool_status', PoolStatus::COOLDOWN)
        ->where('cooldown_until', '<=', now());
}
```

- [ ] **Step 6: Commit**

```bash
git add -A
git commit -m "feat(leads): add pool_status and cooldown_until fields

- Add migration for pool_status enum and cooldown_until timestamp
- Create PoolStatus enum with AVAILABLE, ASSIGNED, COOLDOWN, EXHAUSTED
- Add model scopes for filtering by pool status"
```

---

### Task 2: Database Migrations - Call Tracking

**Files:**
- Create: `database/migrations/2026_03_22_000002_add_call_tracking_to_lead_cycles.php`
- Modify: `app/Models/LeadCycle.php`

- [ ] **Step 1: Create migration**

```bash
php artisan make:migration add_call_tracking_to_lead_cycles
```

- [ ] **Step 2: Write migration content**

```php
// database/migrations/2026_03_22_000002_add_call_tracking_to_lead_cycles.php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lead_cycles', function (Blueprint $table) {
            $table->timestamp('last_call_at')->nullable()->after('closed_at');
            $table->integer('call_count')->default(0)->after('last_call_at');
            $table->timestamp('callback_at')->nullable()->after('call_count');
            $table->text('callback_notes')->nullable()->after('callback_at');
        });
    }

    public function down(): void
    {
        Schema::table('lead_cycles', function (Blueprint $table) {
            $table->dropColumn(['last_call_at', 'call_count', 'callback_at', 'callback_notes']);
        });
    }
};
```

- [ ] **Step 3: Run migration**

```bash
php artisan migrate
```

- [ ] **Step 4: Update LeadCycle model**

Add to `app/Models/LeadCycle.php`:

```php
// Add to $fillable:
'last_call_at',
'call_count',
'callback_at',
'callback_notes',

// Add to $casts:
'last_call_at' => 'datetime',
'callback_at' => 'datetime',

// Add method:
public function recordCall(): void
{
    $this->increment('call_count');
    $this->update(['last_call_at' => now()]);
}
```

- [ ] **Step 5: Commit**

```bash
git add -A
git commit -m "feat(lead-cycles): add call tracking fields

- Add last_call_at, call_count for tracking call attempts
- Add callback_at, callback_notes for scheduled callbacks
- Add recordCall() helper method"
```

---

### Task 3: Database Migrations - Recycling Rules

**Files:**
- Create: `database/migrations/2026_03_22_000003_create_recycling_rules_table.php`
- Create: `app/Models/RecyclingRule.php`
- Create: `app/Enums/LeadOutcome.php`

- [ ] **Step 1: Create LeadOutcome enum**

```php
// app/Enums/LeadOutcome.php
<?php

namespace App\Enums;

enum LeadOutcome: string
{
    case NO_ANSWER = 'NO_ANSWER';
    case CALLBACK = 'CALLBACK';
    case INTERESTED = 'INTERESTED';
    case ORDERED = 'ORDERED';
    case NOT_INTERESTED = 'NOT_INTERESTED';
    case WRONG_NUMBER = 'WRONG_NUMBER';

    public function label(): string
    {
        return match($this) {
            self::NO_ANSWER => 'No Answer',
            self::CALLBACK => 'Callback',
            self::INTERESTED => 'Interested',
            self::ORDERED => 'Ordered/Sold',
            self::NOT_INTERESTED => 'Not Interested',
            self::WRONG_NUMBER => 'Wrong Number',
        };
    }
}
```

- [ ] **Step 2: Create migration**

```bash
php artisan make:migration create_recycling_rules_table
```

- [ ] **Step 3: Write migration content**

```php
// database/migrations/2026_03_22_000003_create_recycling_rules_table.php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recycling_rules', function (Blueprint $table) {
            $table->id();
            $table->string('outcome')->unique();
            $table->integer('cooldown_hours')->default(24);
            $table->integer('max_cycles')->default(3);
            $table->string('next_action')->default('RECYCLE'); // RECYCLE or EXHAUST
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // Insert default rules
        DB::table('recycling_rules')->insert([
            ['outcome' => 'NO_ANSWER', 'cooldown_hours' => 24, 'max_cycles' => 5, 'next_action' => 'RECYCLE', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
            ['outcome' => 'CALLBACK', 'cooldown_hours' => 0, 'max_cycles' => 999, 'next_action' => 'RECYCLE', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
            ['outcome' => 'INTERESTED', 'cooldown_hours' => 48, 'max_cycles' => 3, 'next_action' => 'RECYCLE', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
            ['outcome' => 'NOT_INTERESTED', 'cooldown_hours' => 720, 'max_cycles' => 2, 'next_action' => 'EXHAUST', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
            ['outcome' => 'WRONG_NUMBER', 'cooldown_hours' => 0, 'max_cycles' => 1, 'next_action' => 'EXHAUST', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
            ['outcome' => 'ORDERED', 'cooldown_hours' => 1440, 'max_cycles' => 999, 'next_action' => 'RECYCLE', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('recycling_rules');
    }
};
```

- [ ] **Step 4: Create RecyclingRule model**

```php
// app/Models/RecyclingRule.php
<?php

namespace App\Models;

use App\Enums\LeadOutcome;
use Illuminate\Database\Eloquent\Model;

class RecyclingRule extends Model
{
    protected $fillable = [
        'outcome',
        'cooldown_hours',
        'max_cycles',
        'next_action',
        'is_active',
    ];

    protected $casts = [
        'outcome' => LeadOutcome::class,
        'is_active' => 'boolean',
    ];

    public static function forOutcome(LeadOutcome $outcome): ?self
    {
        return self::where('outcome', $outcome->value)
            ->where('is_active', true)
            ->first();
    }

    public function shouldExhaust(): bool
    {
        return $this->next_action === 'EXHAUST';
    }
}
```

- [ ] **Step 5: Run migration**

```bash
php artisan migrate
```

- [ ] **Step 6: Commit**

```bash
git add -A
git commit -m "feat(recycling): add recycling rules table with defaults

- Create LeadOutcome enum for standardized outcomes
- Create recycling_rules table with outcome-based configuration
- Seed default rules: NO_ANSWER(24h/5), CALLBACK(agent keeps), etc."
```

---

### Task 4: Database Migrations - Fraud Flags

**Files:**
- Create: `database/migrations/2026_03_22_000004_create_fraud_flags_table.php`
- Create: `app/Models/FraudFlag.php`

- [ ] **Step 1: Create migration**

```bash
php artisan make:migration create_fraud_flags_table
```

- [ ] **Step 2: Write migration content**

```php
// database/migrations/2026_03_22_000004_create_fraud_flags_table.php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fraud_flags', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agent_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('lead_id')->nullable()->constrained()->nullOnDelete();
            $table->string('flag_type'); // SUSPICIOUS_VELOCITY, NO_CALL_INITIATED, etc.
            $table->string('severity')->default('WARNING'); // WARNING, CRITICAL
            $table->json('details')->nullable();
            $table->boolean('is_reviewed')->default(false);
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('resolution_notes')->nullable();
            $table->timestamps();

            $table->index(['agent_id', 'is_reviewed']);
            $table->index(['flag_type', 'is_reviewed']);
            $table->index(['created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fraud_flags');
    }
};
```

- [ ] **Step 3: Create FraudFlag model**

```php
// app/Models/FraudFlag.php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FraudFlag extends Model
{
    protected $fillable = [
        'agent_id',
        'lead_id',
        'flag_type',
        'severity',
        'details',
        'is_reviewed',
        'reviewed_by',
        'reviewed_at',
        'resolution_notes',
    ];

    protected $casts = [
        'details' => 'array',
        'is_reviewed' => 'boolean',
        'reviewed_at' => 'datetime',
    ];

    public function agent(): BelongsTo
    {
        return $this->belongsTo(User::class, 'agent_id');
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function scopeUnreviewed($query)
    {
        return $query->where('is_reviewed', false);
    }

    public function scopeCritical($query)
    {
        return $query->where('severity', 'CRITICAL');
    }

    public function markReviewed(int $reviewerId, ?string $notes = null): void
    {
        $this->update([
            'is_reviewed' => true,
            'reviewed_by' => $reviewerId,
            'reviewed_at' => now(),
            'resolution_notes' => $notes,
        ]);
    }
}
```

- [ ] **Step 4: Run migration**

```bash
php artisan migrate
```

- [ ] **Step 5: Commit**

```bash
git add -A
git commit -m "feat(fraud): add fraud_flags table for suspicious activity

- Track SUSPICIOUS_VELOCITY, NO_CALL_INITIATED, OUTCOME_TAMPERING
- Support WARNING and CRITICAL severity levels
- Include review workflow with reviewer tracking"
```

---

### Task 5: Database Migrations - Audit Log

**Files:**
- Create: `database/migrations/2026_03_22_000005_create_lead_pool_audit_table.php`
- Create: `app/Models/LeadPoolAudit.php`

- [ ] **Step 1: Create migration**

```bash
php artisan make:migration create_lead_pool_audit_table
```

- [ ] **Step 2: Write migration content**

```php
// database/migrations/2026_03_22_000005_create_lead_pool_audit_table.php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lead_pool_audit', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lead_id')->constrained()->cascadeOnDelete();
            $table->foreignId('lead_cycle_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('action'); // CREATED, DISTRIBUTED, CALL_INITIATED, OUTCOME_SET, etc.
            $table->string('old_value')->nullable();
            $table->string('new_value')->nullable();
            $table->json('metadata')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->timestamps();

            $table->index(['lead_id', 'created_at']);
            $table->index(['user_id', 'created_at']);
            $table->index(['action', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lead_pool_audit');
    }
};
```

- [ ] **Step 3: Create LeadPoolAudit model**

```php
// app/Models/LeadPoolAudit.php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LeadPoolAudit extends Model
{
    protected $table = 'lead_pool_audit';

    protected $fillable = [
        'lead_id',
        'lead_cycle_id',
        'user_id',
        'action',
        'old_value',
        'new_value',
        'metadata',
        'ip_address',
    ];

    protected $casts = [
        'metadata' => 'array',
    ];

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    public function leadCycle(): BelongsTo
    {
        return $this->belongsTo(LeadCycle::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
```

- [ ] **Step 4: Run migration**

```bash
php artisan migrate
```

- [ ] **Step 5: Commit**

```bash
git add -A
git commit -m "feat(audit): add lead_pool_audit table for full tracking

- Log all lead actions: CREATED, DISTRIBUTED, CALL_INITIATED, OUTCOME_SET
- Track old/new values, metadata, and IP address
- Index by lead, user, and action for efficient queries"
```

---

### Task 6: LeadAuditService

**Files:**
- Create: `app/Services/LeadAuditService.php`
- Create: `tests/Unit/Services/LeadAuditServiceTest.php`

- [ ] **Step 1: Create Services directory**

```bash
mkdir -p app/Services
mkdir -p tests/Unit/Services
```

- [ ] **Step 2: Write failing test**

```php
// tests/Unit/Services/LeadAuditServiceTest.php
<?php

namespace Tests\Unit\Services;

use App\Models\Lead;
use App\Models\LeadPoolAudit;
use App\Models\User;
use App\Services\LeadAuditService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LeadAuditServiceTest extends TestCase
{
    use RefreshDatabase;

    private LeadAuditService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new LeadAuditService();
    }

    public function test_logs_action_with_user(): void
    {
        $user = User::factory()->create();
        $lead = Lead::factory()->create();

        $this->service->log(
            lead: $lead,
            action: 'CALL_INITIATED',
            user: $user
        );

        $this->assertDatabaseHas('lead_pool_audit', [
            'lead_id' => $lead->id,
            'user_id' => $user->id,
            'action' => 'CALL_INITIATED',
        ]);
    }

    public function test_logs_action_with_old_and_new_values(): void
    {
        $lead = Lead::factory()->create();

        $this->service->log(
            lead: $lead,
            action: 'POOL_STATUS_CHANGED',
            oldValue: 'AVAILABLE',
            newValue: 'ASSIGNED'
        );

        $this->assertDatabaseHas('lead_pool_audit', [
            'lead_id' => $lead->id,
            'action' => 'POOL_STATUS_CHANGED',
            'old_value' => 'AVAILABLE',
            'new_value' => 'ASSIGNED',
        ]);
    }

    public function test_logs_action_with_metadata(): void
    {
        $lead = Lead::factory()->create();

        $this->service->log(
            lead: $lead,
            action: 'DISTRIBUTED',
            metadata: ['batch_size' => 20, 'agent_count' => 5]
        );

        $audit = LeadPoolAudit::where('lead_id', $lead->id)->first();

        $this->assertEquals(['batch_size' => 20, 'agent_count' => 5], $audit->metadata);
    }
}
```

- [ ] **Step 3: Run test to verify it fails**

```bash
php artisan test tests/Unit/Services/LeadAuditServiceTest.php
```
Expected: FAIL - LeadAuditService class not found

- [ ] **Step 4: Implement LeadAuditService**

```php
// app/Services/LeadAuditService.php
<?php

namespace App\Services;

use App\Models\Lead;
use App\Models\LeadCycle;
use App\Models\LeadPoolAudit;
use App\Models\User;
use Illuminate\Support\Facades\Request;

class LeadAuditService
{
    public function log(
        Lead $lead,
        string $action,
        ?User $user = null,
        ?LeadCycle $cycle = null,
        ?string $oldValue = null,
        ?string $newValue = null,
        ?array $metadata = null,
    ): LeadPoolAudit {
        return LeadPoolAudit::create([
            'lead_id' => $lead->id,
            'lead_cycle_id' => $cycle?->id,
            'user_id' => $user?->id ?? auth()->id(),
            'action' => $action,
            'old_value' => $oldValue,
            'new_value' => $newValue,
            'metadata' => $metadata,
            'ip_address' => Request::ip(),
        ]);
    }

    public function getLeadHistory(Lead $lead, int $limit = 50): \Illuminate\Database\Eloquent\Collection
    {
        return LeadPoolAudit::where('lead_id', $lead->id)
            ->with('user')
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get();
    }
}
```

- [ ] **Step 5: Run test to verify it passes**

```bash
php artisan test tests/Unit/Services/LeadAuditServiceTest.php
```
Expected: PASS

- [ ] **Step 6: Commit**

```bash
git add -A
git commit -m "feat(audit): implement LeadAuditService

- Log lead actions with user, cycle, old/new values, metadata
- Capture IP address automatically
- Add getLeadHistory for audit trail retrieval"
```

---

### Task 7: LeadPoolService

**Files:**
- Create: `app/Services/LeadPoolService.php`
- Create: `tests/Unit/Services/LeadPoolServiceTest.php`

- [ ] **Step 1: Write failing test**

```php
// tests/Unit/Services/LeadPoolServiceTest.php
<?php

namespace Tests\Unit\Services;

use App\Enums\PoolStatus;
use App\Models\Lead;
use App\Models\User;
use App\Services\LeadAuditService;
use App\Services\LeadPoolService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LeadPoolServiceTest extends TestCase
{
    use RefreshDatabase;

    private LeadPoolService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new LeadPoolService(new LeadAuditService());
    }

    public function test_get_available_leads_returns_only_available(): void
    {
        Lead::factory()->create(['pool_status' => PoolStatus::AVAILABLE]);
        Lead::factory()->create(['pool_status' => PoolStatus::ASSIGNED]);
        Lead::factory()->create(['pool_status' => PoolStatus::COOLDOWN]);

        $available = $this->service->getAvailableLeads();

        $this->assertCount(1, $available);
        $this->assertEquals(PoolStatus::AVAILABLE, $available->first()->pool_status);
    }

    public function test_mark_as_assigned_changes_status(): void
    {
        $lead = Lead::factory()->create(['pool_status' => PoolStatus::AVAILABLE]);
        $agent = User::factory()->create(['role' => 'agent']);

        $this->service->markAsAssigned($lead, $agent);

        $lead->refresh();
        $this->assertEquals(PoolStatus::ASSIGNED, $lead->pool_status);
        $this->assertEquals($agent->id, $lead->assigned_to);
    }

    public function test_mark_as_cooldown_sets_cooldown_until(): void
    {
        $lead = Lead::factory()->create(['pool_status' => PoolStatus::ASSIGNED]);

        $this->service->markAsCooldown($lead, 24);

        $lead->refresh();
        $this->assertEquals(PoolStatus::COOLDOWN, $lead->pool_status);
        $this->assertNotNull($lead->cooldown_until);
        $this->assertTrue($lead->cooldown_until->isFuture());
    }

    public function test_mark_as_exhausted_changes_status(): void
    {
        $lead = Lead::factory()->create(['pool_status' => PoolStatus::ASSIGNED]);

        $this->service->markAsExhausted($lead);

        $lead->refresh();
        $this->assertEquals(PoolStatus::EXHAUSTED, $lead->pool_status);
        $this->assertTrue($lead->is_exhausted);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

```bash
php artisan test tests/Unit/Services/LeadPoolServiceTest.php
```
Expected: FAIL

- [ ] **Step 3: Implement LeadPoolService**

```php
// app/Services/LeadPoolService.php
<?php

namespace App\Services;

use App\Enums\PoolStatus;
use App\Models\Lead;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;

class LeadPoolService
{
    public function __construct(
        private LeadAuditService $auditService
    ) {}

    public function getAvailableLeads(?array $filters = null): Collection
    {
        $query = Lead::available()->with('customer');

        if ($filters) {
            if (isset($filters['source'])) {
                $query->where('source', $filters['source']);
            }
            if (isset($filters['city'])) {
                $query->where('city', $filters['city']);
            }
            if (isset($filters['product_name'])) {
                $query->where('product_name', 'ILIKE', "%{$filters['product_name']}%");
            }
        }

        return $query->orderBy('created_at', 'asc')->get();
    }

    public function getPoolStats(): array
    {
        return [
            'available' => Lead::available()->count(),
            'assigned' => Lead::assigned()->count(),
            'cooldown' => Lead::inCooldown()->count(),
            'exhausted' => Lead::exhausted()->count(),
        ];
    }

    public function markAsAssigned(Lead $lead, User $agent): void
    {
        $oldStatus = $lead->pool_status->value;

        $lead->update([
            'pool_status' => PoolStatus::ASSIGNED,
            'assigned_to' => $agent->id,
            'assigned_at' => now(),
        ]);

        $this->auditService->log(
            lead: $lead,
            action: 'POOL_STATUS_CHANGED',
            oldValue: $oldStatus,
            newValue: PoolStatus::ASSIGNED->value,
            metadata: ['agent_id' => $agent->id]
        );
    }

    public function markAsCooldown(Lead $lead, int $cooldownHours): void
    {
        $oldStatus = $lead->pool_status->value;

        $lead->update([
            'pool_status' => PoolStatus::COOLDOWN,
            'cooldown_until' => now()->addHours($cooldownHours),
            'assigned_to' => null,
        ]);

        $this->auditService->log(
            lead: $lead,
            action: 'POOL_STATUS_CHANGED',
            oldValue: $oldStatus,
            newValue: PoolStatus::COOLDOWN->value,
            metadata: ['cooldown_hours' => $cooldownHours]
        );
    }

    public function markAsAvailable(Lead $lead): void
    {
        $oldStatus = $lead->pool_status->value;

        $lead->update([
            'pool_status' => PoolStatus::AVAILABLE,
            'cooldown_until' => null,
            'assigned_to' => null,
        ]);

        $this->auditService->log(
            lead: $lead,
            action: 'POOL_STATUS_CHANGED',
            oldValue: $oldStatus,
            newValue: PoolStatus::AVAILABLE->value
        );
    }

    public function markAsExhausted(Lead $lead): void
    {
        $oldStatus = $lead->pool_status->value;

        $lead->update([
            'pool_status' => PoolStatus::EXHAUSTED,
            'is_exhausted' => true,
        ]);

        $this->auditService->log(
            lead: $lead,
            action: 'POOL_STATUS_CHANGED',
            oldValue: $oldStatus,
            newValue: PoolStatus::EXHAUSTED->value
        );
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

```bash
php artisan test tests/Unit/Services/LeadPoolServiceTest.php
```
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add -A
git commit -m "feat(pool): implement LeadPoolService for state management

- Get available leads with optional filters
- Get pool stats (available, assigned, cooldown, exhausted)
- Mark leads as assigned, cooldown, available, or exhausted
- All state changes logged to audit trail"
```

---

### Task 8: LeadDistributionService

**Files:**
- Create: `app/Services/LeadDistributionService.php`
- Create: `tests/Unit/Services/LeadDistributionServiceTest.php`

- [ ] **Step 1: Write failing test**

```php
// tests/Unit/Services/LeadDistributionServiceTest.php
<?php

namespace Tests\Unit\Services;

use App\Enums\PoolStatus;
use App\Models\Lead;
use App\Models\LeadCycle;
use App\Models\User;
use App\Models\AgentProfile;
use App\Services\LeadAuditService;
use App\Services\LeadDistributionService;
use App\Services\LeadPoolService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LeadDistributionServiceTest extends TestCase
{
    use RefreshDatabase;

    private LeadDistributionService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $auditService = new LeadAuditService();
        $poolService = new LeadPoolService($auditService);
        $this->service = new LeadDistributionService($poolService, $auditService);
    }

    public function test_distribute_equal_splits_leads_evenly(): void
    {
        $leads = Lead::factory()->count(20)->create(['pool_status' => PoolStatus::AVAILABLE]);
        $agents = User::factory()->count(4)->create(['role' => 'agent']);
        foreach ($agents as $agent) {
            AgentProfile::factory()->create(['user_id' => $agent->id, 'is_available' => true]);
        }

        $supervisor = User::factory()->create(['role' => 'supervisor']);

        $result = $this->service->distributeEqual(
            leadIds: $leads->pluck('id')->toArray(),
            agentIds: $agents->pluck('id')->toArray(),
            supervisorId: $supervisor->id
        );

        $this->assertEquals(20, $result['total_distributed']);
        $this->assertEquals(4, $result['agent_count']);
        $this->assertEquals(5, $result['per_agent']);

        // Each agent should have 5 assigned leads
        foreach ($agents as $agent) {
            $assigned = Lead::where('assigned_to', $agent->id)->count();
            $this->assertEquals(5, $assigned);
        }
    }

    public function test_distribute_custom_assigns_specified_counts(): void
    {
        $leads = Lead::factory()->count(30)->create(['pool_status' => PoolStatus::AVAILABLE]);
        $agents = User::factory()->count(3)->create(['role' => 'agent']);
        foreach ($agents as $agent) {
            AgentProfile::factory()->create(['user_id' => $agent->id, 'is_available' => true]);
        }

        $supervisor = User::factory()->create(['role' => 'supervisor']);

        $distribution = [
            $agents[0]->id => 10,
            $agents[1]->id => 15,
            $agents[2]->id => 5,
        ];

        $result = $this->service->distributeCustom(
            leadIds: $leads->pluck('id')->toArray(),
            distribution: $distribution,
            supervisorId: $supervisor->id
        );

        $this->assertEquals(30, $result['total_distributed']);

        $this->assertEquals(10, Lead::where('assigned_to', $agents[0]->id)->count());
        $this->assertEquals(15, Lead::where('assigned_to', $agents[1]->id)->count());
        $this->assertEquals(5, Lead::where('assigned_to', $agents[2]->id)->count());
    }

    public function test_distribution_creates_lead_cycles(): void
    {
        $lead = Lead::factory()->create(['pool_status' => PoolStatus::AVAILABLE]);
        $agent = User::factory()->create(['role' => 'agent']);
        AgentProfile::factory()->create(['user_id' => $agent->id, 'is_available' => true]);
        $supervisor = User::factory()->create(['role' => 'supervisor']);

        $this->service->distributeEqual(
            leadIds: [$lead->id],
            agentIds: [$agent->id],
            supervisorId: $supervisor->id
        );

        $this->assertDatabaseHas('lead_cycles', [
            'lead_id' => $lead->id,
            'assigned_agent_id' => $agent->id,
            'status' => 'ACTIVE',
        ]);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

```bash
php artisan test tests/Unit/Services/LeadDistributionServiceTest.php
```
Expected: FAIL

- [ ] **Step 3: Implement LeadDistributionService**

```php
// app/Services/LeadDistributionService.php
<?php

namespace App\Services;

use App\Enums\PoolStatus;
use App\Models\Lead;
use App\Models\LeadCycle;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class LeadDistributionService
{
    public function __construct(
        private LeadPoolService $poolService,
        private LeadAuditService $auditService
    ) {}

    public function distributeEqual(array $leadIds, array $agentIds, int $supervisorId): array
    {
        $leadsPerAgent = (int) floor(count($leadIds) / count($agentIds));
        $distribution = array_fill_keys($agentIds, $leadsPerAgent);

        // Distribute remainder
        $remainder = count($leadIds) % count($agentIds);
        $i = 0;
        foreach ($distribution as $agentId => $count) {
            if ($i < $remainder) {
                $distribution[$agentId]++;
            }
            $i++;
        }

        return $this->distributeCustom($leadIds, $distribution, $supervisorId);
    }

    public function distributeCustom(array $leadIds, array $distribution, int $supervisorId): array
    {
        $totalDistributed = 0;
        $leads = Lead::whereIn('id', $leadIds)
            ->where('pool_status', PoolStatus::AVAILABLE)
            ->get()
            ->shuffle();

        $leadIndex = 0;

        DB::transaction(function () use ($leads, $distribution, $supervisorId, &$totalDistributed, &$leadIndex) {
            foreach ($distribution as $agentId => $count) {
                $agent = User::find($agentId);

                for ($i = 0; $i < $count && $leadIndex < $leads->count(); $i++) {
                    $lead = $leads[$leadIndex];

                    // Create new cycle
                    $cycleNumber = $lead->total_cycles + 1;
                    $cycle = LeadCycle::create([
                        'lead_id' => $lead->id,
                        'cycle_number' => $cycleNumber,
                        'assigned_agent_id' => $agentId,
                        'status' => 'ACTIVE',
                        'opened_at' => now(),
                    ]);

                    // Update lead
                    $lead->update([
                        'pool_status' => PoolStatus::ASSIGNED,
                        'assigned_to' => $agentId,
                        'assigned_at' => now(),
                        'total_cycles' => $cycleNumber,
                    ]);

                    // Audit log
                    $this->auditService->log(
                        lead: $lead,
                        action: 'DISTRIBUTED',
                        user: User::find($supervisorId),
                        cycle: $cycle,
                        metadata: [
                            'agent_id' => $agentId,
                            'agent_name' => $agent->name,
                            'cycle_number' => $cycleNumber,
                        ]
                    );

                    $totalDistributed++;
                    $leadIndex++;
                }
            }
        });

        return [
            'total_distributed' => $totalDistributed,
            'agent_count' => count($distribution),
            'per_agent' => $totalDistributed > 0 ? (int) ceil($totalDistributed / count($distribution)) : 0,
        ];
    }

    public function getAvailableAgents(): \Illuminate\Database\Eloquent\Collection
    {
        return User::where('role', 'agent')
            ->where('is_active', true)
            ->whereHas('agentProfile', fn($q) => $q->where('is_available', true))
            ->with('agentProfile')
            ->get();
    }
}
```

- [ ] **Step 4: Add agentProfile relationship to User model**

Add to `app/Models/User.php`:

```php
use App\Models\AgentProfile;

public function agentProfile(): \Illuminate\Database\Eloquent\Relations\HasOne
{
    return $this->hasOne(AgentProfile::class);
}
```

- [ ] **Step 5: Run test to verify it passes**

```bash
php artisan test tests/Unit/Services/LeadDistributionServiceTest.php
```
Expected: PASS

- [ ] **Step 6: Commit**

```bash
git add -A
git commit -m "feat(distribution): implement LeadDistributionService

- distributeEqual() splits leads evenly among agents
- distributeCustom() assigns specific counts per agent
- Creates LeadCycle records for each assignment
- All distributions logged to audit trail"
```

---

### Task 9: CallTrackingService

**Files:**
- Create: `app/Services/CallTrackingService.php`
- Create: `tests/Unit/Services/CallTrackingServiceTest.php`

- [ ] **Step 1: Write failing test**

```php
// tests/Unit/Services/CallTrackingServiceTest.php
<?php

namespace Tests\Unit\Services;

use App\Enums\PoolStatus;
use App\Models\Lead;
use App\Models\LeadCycle;
use App\Models\User;
use App\Services\CallTrackingService;
use App\Services\LeadAuditService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CallTrackingServiceTest extends TestCase
{
    use RefreshDatabase;

    private CallTrackingService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new CallTrackingService(new LeadAuditService());
    }

    public function test_initiate_call_returns_sip_link(): void
    {
        $agent = User::factory()->create(['role' => 'agent']);
        $lead = Lead::factory()->create([
            'phone' => '09171234567',
            'pool_status' => PoolStatus::ASSIGNED,
            'assigned_to' => $agent->id,
        ]);
        $cycle = LeadCycle::factory()->create([
            'lead_id' => $lead->id,
            'assigned_agent_id' => $agent->id,
            'status' => 'ACTIVE',
        ]);

        $sipLink = $this->service->initiateCall($lead, $cycle, $agent);

        $this->assertEquals('sip:09171234567', $sipLink);
    }

    public function test_initiate_call_increments_call_count(): void
    {
        $agent = User::factory()->create(['role' => 'agent']);
        $lead = Lead::factory()->create([
            'phone' => '09171234567',
            'pool_status' => PoolStatus::ASSIGNED,
            'assigned_to' => $agent->id,
        ]);
        $cycle = LeadCycle::factory()->create([
            'lead_id' => $lead->id,
            'assigned_agent_id' => $agent->id,
            'status' => 'ACTIVE',
            'call_count' => 0,
        ]);

        $this->service->initiateCall($lead, $cycle, $agent);

        $cycle->refresh();
        $this->assertEquals(1, $cycle->call_count);
        $this->assertNotNull($cycle->last_call_at);
    }

    public function test_initiate_call_logs_audit(): void
    {
        $agent = User::factory()->create(['role' => 'agent']);
        $lead = Lead::factory()->create([
            'phone' => '09171234567',
            'pool_status' => PoolStatus::ASSIGNED,
            'assigned_to' => $agent->id,
        ]);
        $cycle = LeadCycle::factory()->create([
            'lead_id' => $lead->id,
            'assigned_agent_id' => $agent->id,
        ]);

        $this->service->initiateCall($lead, $cycle, $agent);

        $this->assertDatabaseHas('lead_pool_audit', [
            'lead_id' => $lead->id,
            'user_id' => $agent->id,
            'action' => 'CALL_INITIATED',
        ]);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

```bash
php artisan test tests/Unit/Services/CallTrackingServiceTest.php
```
Expected: FAIL

- [ ] **Step 3: Implement CallTrackingService**

```php
// app/Services/CallTrackingService.php
<?php

namespace App\Services;

use App\Models\Lead;
use App\Models\LeadCycle;
use App\Models\User;

class CallTrackingService
{
    public function __construct(
        private LeadAuditService $auditService
    ) {}

    public function initiateCall(Lead $lead, LeadCycle $cycle, User $agent): string
    {
        // Record the call attempt
        $cycle->increment('call_count');
        $cycle->update(['last_call_at' => now()]);

        // Update lead's last_called_at
        $lead->update([
            'last_called_at' => now(),
            'call_attempts' => $lead->call_attempts + 1,
        ]);

        // Log to audit trail
        $this->auditService->log(
            lead: $lead,
            action: 'CALL_INITIATED',
            user: $agent,
            cycle: $cycle,
            metadata: [
                'call_number' => $cycle->call_count,
                'total_attempts' => $lead->call_attempts,
            ]
        );

        // Return SIP link for MicroSIP
        return 'sip:' . $lead->phone;
    }

    public function getAgentCallStats(User $agent, ?string $period = 'today'): array
    {
        $query = LeadCycle::where('assigned_agent_id', $agent->id);

        if ($period === 'today') {
            $query->whereDate('last_call_at', today());
        }

        return [
            'total_calls' => $query->sum('call_count'),
            'leads_called' => $query->whereNotNull('last_call_at')->count(),
        ];
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

```bash
php artisan test tests/Unit/Services/CallTrackingServiceTest.php
```
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add -A
git commit -m "feat(calls): implement CallTrackingService

- initiateCall() returns sip: link for MicroSIP
- Track call count and last_call_at per cycle
- Log CALL_INITIATED to audit trail
- Get agent call stats by period"
```

---

### Task 10: LeadRecyclingService

**Files:**
- Create: `app/Services/LeadRecyclingService.php`
- Create: `tests/Unit/Services/LeadRecyclingServiceTest.php`

- [ ] **Step 1: Write failing test**

```php
// tests/Unit/Services/LeadRecyclingServiceTest.php
<?php

namespace Tests\Unit\Services;

use App\Enums\LeadOutcome;
use App\Enums\PoolStatus;
use App\Models\Lead;
use App\Models\LeadCycle;
use App\Models\RecyclingRule;
use App\Models\User;
use App\Services\LeadAuditService;
use App\Services\LeadPoolService;
use App\Services\LeadRecyclingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LeadRecyclingServiceTest extends TestCase
{
    use RefreshDatabase;

    private LeadRecyclingService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $auditService = new LeadAuditService();
        $poolService = new LeadPoolService($auditService);
        $this->service = new LeadRecyclingService($poolService, $auditService);

        // Seed default rules
        $this->seed(\Database\Seeders\RecyclingRulesSeeder::class);
    }

    public function test_process_outcome_moves_to_cooldown_for_no_answer(): void
    {
        $agent = User::factory()->create(['role' => 'agent']);
        $lead = Lead::factory()->create([
            'pool_status' => PoolStatus::ASSIGNED,
            'assigned_to' => $agent->id,
            'total_cycles' => 1,
        ]);
        $cycle = LeadCycle::factory()->create([
            'lead_id' => $lead->id,
            'assigned_agent_id' => $agent->id,
            'status' => 'ACTIVE',
        ]);

        $this->service->processOutcome($lead, $cycle, LeadOutcome::NO_ANSWER, $agent);

        $lead->refresh();
        $cycle->refresh();

        $this->assertEquals(PoolStatus::COOLDOWN, $lead->pool_status);
        $this->assertEquals('CLOSED', $cycle->status);
        $this->assertEquals('NO_ANSWER', $cycle->outcome);
    }

    public function test_process_outcome_exhausts_lead_at_max_cycles(): void
    {
        $agent = User::factory()->create(['role' => 'agent']);
        $lead = Lead::factory()->create([
            'pool_status' => PoolStatus::ASSIGNED,
            'assigned_to' => $agent->id,
            'total_cycles' => 5, // At max for NO_ANSWER
        ]);
        $cycle = LeadCycle::factory()->create([
            'lead_id' => $lead->id,
            'assigned_agent_id' => $agent->id,
            'status' => 'ACTIVE',
        ]);

        $this->service->processOutcome($lead, $cycle, LeadOutcome::NO_ANSWER, $agent);

        $lead->refresh();
        $this->assertEquals(PoolStatus::EXHAUSTED, $lead->pool_status);
        $this->assertTrue($lead->is_exhausted);
    }

    public function test_process_outcome_wrong_number_exhausts_immediately(): void
    {
        $agent = User::factory()->create(['role' => 'agent']);
        $lead = Lead::factory()->create([
            'pool_status' => PoolStatus::ASSIGNED,
            'assigned_to' => $agent->id,
            'total_cycles' => 1,
        ]);
        $cycle = LeadCycle::factory()->create([
            'lead_id' => $lead->id,
            'assigned_agent_id' => $agent->id,
            'status' => 'ACTIVE',
        ]);

        $this->service->processOutcome($lead, $cycle, LeadOutcome::WRONG_NUMBER, $agent);

        $lead->refresh();
        $this->assertEquals(PoolStatus::EXHAUSTED, $lead->pool_status);
    }

    public function test_process_expired_cooldowns_moves_to_available(): void
    {
        // Create lead with expired cooldown
        $lead = Lead::factory()->create([
            'pool_status' => PoolStatus::COOLDOWN,
            'cooldown_until' => now()->subHour(),
            'total_cycles' => 1,
        ]);

        $processed = $this->service->processExpiredCooldowns();

        $lead->refresh();
        $this->assertEquals(PoolStatus::AVAILABLE, $lead->pool_status);
        $this->assertEquals(1, $processed);
    }
}
```

- [ ] **Step 2: Create RecyclingRulesSeeder**

```php
// database/seeders/RecyclingRulesSeeder.php
<?php

namespace Database\Seeders;

use App\Models\RecyclingRule;
use Illuminate\Database\Seeder;

class RecyclingRulesSeeder extends Seeder
{
    public function run(): void
    {
        $rules = [
            ['outcome' => 'NO_ANSWER', 'cooldown_hours' => 24, 'max_cycles' => 5, 'next_action' => 'RECYCLE'],
            ['outcome' => 'CALLBACK', 'cooldown_hours' => 0, 'max_cycles' => 999, 'next_action' => 'RECYCLE'],
            ['outcome' => 'INTERESTED', 'cooldown_hours' => 48, 'max_cycles' => 3, 'next_action' => 'RECYCLE'],
            ['outcome' => 'NOT_INTERESTED', 'cooldown_hours' => 720, 'max_cycles' => 2, 'next_action' => 'EXHAUST'],
            ['outcome' => 'WRONG_NUMBER', 'cooldown_hours' => 0, 'max_cycles' => 1, 'next_action' => 'EXHAUST'],
            ['outcome' => 'ORDERED', 'cooldown_hours' => 1440, 'max_cycles' => 999, 'next_action' => 'RECYCLE'],
        ];

        foreach ($rules as $rule) {
            RecyclingRule::updateOrCreate(
                ['outcome' => $rule['outcome']],
                $rule
            );
        }
    }
}
```

- [ ] **Step 3: Run test to verify it fails**

```bash
php artisan test tests/Unit/Services/LeadRecyclingServiceTest.php
```
Expected: FAIL

- [ ] **Step 4: Implement LeadRecyclingService**

```php
// app/Services/LeadRecyclingService.php
<?php

namespace App\Services;

use App\Enums\LeadOutcome;
use App\Enums\PoolStatus;
use App\Models\Lead;
use App\Models\LeadCycle;
use App\Models\RecyclingRule;
use App\Models\User;

class LeadRecyclingService
{
    public function __construct(
        private LeadPoolService $poolService,
        private LeadAuditService $auditService
    ) {}

    public function processOutcome(
        Lead $lead,
        LeadCycle $cycle,
        LeadOutcome $outcome,
        User $agent,
        ?string $remarks = null,
        ?\DateTime $callbackAt = null
    ): void {
        // Close the cycle
        $cycle->update([
            'status' => 'CLOSED',
            'outcome' => $outcome->value,
            'notes' => $remarks,
            'closed_at' => now(),
            'callback_at' => $callbackAt,
            'callback_notes' => $callbackAt ? $remarks : null,
        ]);

        // Log outcome
        $this->auditService->log(
            lead: $lead,
            action: 'OUTCOME_SET',
            user: $agent,
            cycle: $cycle,
            newValue: $outcome->value,
            metadata: ['remarks' => $remarks, 'callback_at' => $callbackAt?->toISOString()]
        );

        // Get recycling rule
        $rule = RecyclingRule::forOutcome($outcome);

        if (!$rule) {
            // No rule, just close
            $this->poolService->markAsAvailable($lead);
            return;
        }

        // Check if at max cycles
        if ($lead->total_cycles >= $rule->max_cycles || $rule->shouldExhaust()) {
            $this->poolService->markAsExhausted($lead);
            return;
        }

        // Handle CALLBACK - keep with agent
        if ($outcome === LeadOutcome::CALLBACK && $callbackAt) {
            // Keep assigned, don't change status
            return;
        }

        // Move to cooldown
        if ($rule->cooldown_hours > 0) {
            $this->poolService->markAsCooldown($lead, $rule->cooldown_hours);
        } else {
            $this->poolService->markAsAvailable($lead);
        }
    }

    public function processExpiredCooldowns(): int
    {
        $leads = Lead::cooldownExpired()->get();
        $processed = 0;

        foreach ($leads as $lead) {
            // Check if lead has hit max cycles
            $lastCycle = $lead->cycles()->latest()->first();
            $outcome = $lastCycle ? LeadOutcome::tryFrom($lastCycle->outcome) : null;

            if ($outcome) {
                $rule = RecyclingRule::forOutcome($outcome);
                if ($rule && $lead->total_cycles >= $rule->max_cycles) {
                    $this->poolService->markAsExhausted($lead);
                    $processed++;
                    continue;
                }
            }

            $this->poolService->markAsAvailable($lead);
            $processed++;
        }

        return $processed;
    }

    public function reviveLead(Lead $lead, User $supervisor): void
    {
        $this->poolService->markAsAvailable($lead);

        $this->auditService->log(
            lead: $lead,
            action: 'SUPERVISOR_OVERRIDE',
            user: $supervisor,
            metadata: ['action' => 'REVIVE_EXHAUSTED']
        );
    }
}
```

- [ ] **Step 5: Run test to verify it passes**

```bash
php artisan test tests/Unit/Services/LeadRecyclingServiceTest.php
```
Expected: PASS

- [ ] **Step 6: Commit**

```bash
git add -A
git commit -m "feat(recycling): implement LeadRecyclingService

- processOutcome() applies recycling rules based on outcome
- Handle cooldown periods, max cycles, immediate exhaustion
- processExpiredCooldowns() for background job
- reviveLead() for supervisor overrides"
```

---

## Phase 2: Agent Interface

### Task 11: AgentLeadResource (Phone Hidden)

**Files:**
- Create: `app/Http/Resources/AgentLeadResource.php`
- Create: `tests/Feature/Resources/AgentLeadResourceTest.php`

- [ ] **Step 1: Write failing test**

```php
// tests/Feature/Resources/AgentLeadResourceTest.php
<?php

namespace Tests\Feature\Resources;

use App\Enums\PoolStatus;
use App\Http\Resources\AgentLeadResource;
use App\Models\Lead;
use App\Models\User;
use App\Models\Customer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AgentLeadResourceTest extends TestCase
{
    use RefreshDatabase;

    public function test_phone_is_hidden_from_output(): void
    {
        $lead = Lead::factory()->create([
            'phone' => '09171234567',
            'name' => 'Test Customer',
        ]);

        $resource = new AgentLeadResource($lead);
        $array = $resource->toArray(request());

        $this->assertArrayNotHasKey('phone', $array);
    }

    public function test_includes_safe_lead_data(): void
    {
        $customer = Customer::factory()->create();
        $lead = Lead::factory()->create([
            'name' => 'John Doe',
            'city' => 'Manila',
            'product_name' => 'Widget',
            'amount' => 999.00,
            'customer_id' => $customer->id,
        ]);

        $resource = new AgentLeadResource($lead);
        $array = $resource->toArray(request());

        $this->assertEquals('John Doe', $array['name']);
        $this->assertEquals('Manila', $array['city']);
        $this->assertEquals('Widget', $array['product_name']);
        $this->assertEquals(999.00, $array['amount']);
    }

    public function test_includes_call_history(): void
    {
        $agent = User::factory()->create(['role' => 'agent']);
        $lead = Lead::factory()->create([
            'assigned_to' => $agent->id,
            'pool_status' => PoolStatus::ASSIGNED,
        ]);
        $lead->cycles()->create([
            'cycle_number' => 1,
            'assigned_agent_id' => $agent->id,
            'status' => 'ACTIVE',
            'opened_at' => now(),
            'call_count' => 3,
        ]);

        $resource = new AgentLeadResource($lead->load('cycles'));
        $array = $resource->toArray(request());

        $this->assertArrayHasKey('cycles', $array);
        $this->assertEquals(3, $array['cycles'][0]['call_count']);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

```bash
php artisan test tests/Feature/Resources/AgentLeadResourceTest.php
```
Expected: FAIL

- [ ] **Step 3: Implement AgentLeadResource**

```php
// app/Http/Resources/AgentLeadResource.php
<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AgentLeadResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'city' => $this->city,
            'state' => $this->state,
            'barangay' => $this->barangay,
            'product_name' => $this->product_name,
            'product_brand' => $this->product_brand,
            'amount' => $this->amount,
            'status' => $this->status,
            'sales_status' => $this->sales_status,
            'pool_status' => $this->pool_status,
            'total_cycles' => $this->total_cycles,
            'call_attempts' => $this->call_attempts,
            'last_called_at' => $this->last_called_at?->toISOString(),
            'assigned_at' => $this->assigned_at?->toISOString(),
            'created_at' => $this->created_at->toISOString(),

            // Customer info (safe)
            'customer' => $this->whenLoaded('customer', fn() => [
                'id' => $this->customer->id,
                'name' => $this->customer->name,
                'total_orders' => $this->customer->total_orders,
                'successful_orders' => $this->customer->successful_orders,
                'success_rate' => $this->customer->success_rate,
            ]),

            // Cycles with call history
            'cycles' => $this->whenLoaded('cycles', fn() =>
                $this->cycles->map(fn($cycle) => [
                    'id' => $cycle->id,
                    'cycle_number' => $cycle->cycle_number,
                    'status' => $cycle->status,
                    'outcome' => $cycle->outcome,
                    'call_count' => $cycle->call_count,
                    'last_call_at' => $cycle->last_call_at?->toISOString(),
                    'callback_at' => $cycle->callback_at?->toISOString(),
                    'notes' => $cycle->notes,
                    'opened_at' => $cycle->opened_at->toISOString(),
                    'closed_at' => $cycle->closed_at?->toISOString(),
                ])
            ),

            // NOTE: phone and address are INTENTIONALLY excluded
        ];
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

```bash
php artisan test tests/Feature/Resources/AgentLeadResourceTest.php
```
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add -A
git commit -m "feat(security): implement AgentLeadResource without phone

- Excludes phone and full address from agent responses
- Includes safe customer data and call history
- Prevents data leakage via API"
```

---

### Task 12: AgentLeadController

**Files:**
- Create: `app/Http/Controllers/AgentLeadController.php`
- Create: `tests/Feature/Controllers/AgentLeadControllerTest.php`

- [ ] **Step 1: Write failing test**

```php
// tests/Feature/Controllers/AgentLeadControllerTest.php
<?php

namespace Tests\Feature\Controllers;

use App\Enums\PoolStatus;
use App\Models\Lead;
use App\Models\LeadCycle;
use App\Models\User;
use App\Models\AgentProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AgentLeadControllerTest extends TestCase
{
    use RefreshDatabase;

    private User $agent;

    protected function setUp(): void
    {
        parent::setUp();
        $this->agent = User::factory()->create(['role' => 'agent']);
        AgentProfile::factory()->create(['user_id' => $this->agent->id]);
    }

    public function test_index_returns_only_assigned_leads(): void
    {
        // Lead assigned to this agent
        $myLead = Lead::factory()->create([
            'pool_status' => PoolStatus::ASSIGNED,
            'assigned_to' => $this->agent->id,
        ]);

        // Lead assigned to another agent
        $otherAgent = User::factory()->create(['role' => 'agent']);
        Lead::factory()->create([
            'pool_status' => PoolStatus::ASSIGNED,
            'assigned_to' => $otherAgent->id,
        ]);

        $response = $this->actingAs($this->agent)
            ->get('/api/agent/leads');

        $response->assertOk();
        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.id', $myLead->id);
    }

    public function test_index_does_not_include_phone(): void
    {
        Lead::factory()->create([
            'phone' => '09171234567',
            'pool_status' => PoolStatus::ASSIGNED,
            'assigned_to' => $this->agent->id,
        ]);

        $response = $this->actingAs($this->agent)
            ->get('/api/agent/leads');

        $response->assertOk();
        $response->assertJsonMissingPath('data.0.phone');
    }

    public function test_call_returns_sip_link(): void
    {
        $lead = Lead::factory()->create([
            'phone' => '09171234567',
            'pool_status' => PoolStatus::ASSIGNED,
            'assigned_to' => $this->agent->id,
        ]);
        LeadCycle::factory()->create([
            'lead_id' => $lead->id,
            'assigned_agent_id' => $this->agent->id,
            'status' => 'ACTIVE',
        ]);

        $response = $this->actingAs($this->agent)
            ->post("/api/agent/leads/{$lead->id}/call");

        $response->assertOk();
        $response->assertJsonPath('sip_link', 'sip:09171234567');
    }

    public function test_call_denied_for_unassigned_lead(): void
    {
        $otherAgent = User::factory()->create(['role' => 'agent']);
        $lead = Lead::factory()->create([
            'pool_status' => PoolStatus::ASSIGNED,
            'assigned_to' => $otherAgent->id,
        ]);

        $response = $this->actingAs($this->agent)
            ->post("/api/agent/leads/{$lead->id}/call");

        $response->assertForbidden();
    }

    public function test_outcome_closes_cycle(): void
    {
        $lead = Lead::factory()->create([
            'pool_status' => PoolStatus::ASSIGNED,
            'assigned_to' => $this->agent->id,
        ]);
        $cycle = LeadCycle::factory()->create([
            'lead_id' => $lead->id,
            'assigned_agent_id' => $this->agent->id,
            'status' => 'ACTIVE',
        ]);

        $response = $this->actingAs($this->agent)
            ->post("/api/agent/leads/{$lead->id}/outcome", [
                'outcome' => 'NO_ANSWER',
                'remarks' => 'Tried 3 times',
            ]);

        $response->assertOk();

        $cycle->refresh();
        $this->assertEquals('CLOSED', $cycle->status);
        $this->assertEquals('NO_ANSWER', $cycle->outcome);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

```bash
php artisan test tests/Feature/Controllers/AgentLeadControllerTest.php
```
Expected: FAIL

- [ ] **Step 3: Implement AgentLeadController**

```php
// app/Http/Controllers/AgentLeadController.php
<?php

namespace App\Http\Controllers;

use App\Enums\LeadOutcome;
use App\Enums\PoolStatus;
use App\Http\Resources\AgentLeadResource;
use App\Models\Lead;
use App\Models\LeadCycle;
use App\Services\CallTrackingService;
use App\Services\LeadRecyclingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class AgentLeadController extends Controller
{
    public function __construct(
        private CallTrackingService $callService,
        private LeadRecyclingService $recyclingService
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $leads = Lead::where('assigned_to', auth()->id())
            ->where('pool_status', PoolStatus::ASSIGNED)
            ->with(['customer', 'cycles' => fn($q) => $q->where('assigned_agent_id', auth()->id())])
            ->orderByRaw("CASE WHEN callback_at IS NOT NULL AND callback_at <= NOW() THEN 0 ELSE 1 END")
            ->orderBy('assigned_at', 'asc')
            ->get();

        return AgentLeadResource::collection($leads);
    }

    public function show(Lead $lead): AgentLeadResource
    {
        $this->authorize('view', $lead);

        $lead->load(['customer', 'cycles' => fn($q) => $q->where('assigned_agent_id', auth()->id())]);

        return new AgentLeadResource($lead);
    }

    public function call(Lead $lead): JsonResponse
    {
        // Check authorization
        if ($lead->assigned_to !== auth()->id()) {
            abort(403, 'You are not assigned to this lead');
        }

        // Get active cycle
        $cycle = $lead->cycles()
            ->where('assigned_agent_id', auth()->id())
            ->where('status', 'ACTIVE')
            ->firstOrFail();

        $sipLink = $this->callService->initiateCall($lead, $cycle, auth()->user());

        return response()->json([
            'sip_link' => $sipLink,
            'call_count' => $cycle->fresh()->call_count,
        ]);
    }

    public function outcome(Request $request, Lead $lead): JsonResponse
    {
        // Check authorization
        if ($lead->assigned_to !== auth()->id()) {
            abort(403, 'You are not assigned to this lead');
        }

        $validated = $request->validate([
            'outcome' => ['required', 'string', 'in:NO_ANSWER,CALLBACK,INTERESTED,ORDERED,NOT_INTERESTED,WRONG_NUMBER'],
            'remarks' => ['nullable', 'string', 'max:1000'],
            'callback_at' => ['nullable', 'required_if:outcome,CALLBACK', 'date', 'after:now'],
        ]);

        $cycle = $lead->cycles()
            ->where('assigned_agent_id', auth()->id())
            ->where('status', 'ACTIVE')
            ->firstOrFail();

        $outcome = LeadOutcome::from($validated['outcome']);
        $callbackAt = isset($validated['callback_at']) ? new \DateTime($validated['callback_at']) : null;

        $this->recyclingService->processOutcome(
            $lead,
            $cycle,
            $outcome,
            auth()->user(),
            $validated['remarks'] ?? null,
            $callbackAt
        );

        return response()->json([
            'message' => 'Outcome recorded',
            'lead' => new AgentLeadResource($lead->fresh(['customer', 'cycles'])),
        ]);
    }

    public function callbacks(): AnonymousResourceCollection
    {
        $leads = Lead::where('assigned_to', auth()->id())
            ->whereHas('cycles', fn($q) =>
                $q->where('assigned_agent_id', auth()->id())
                  ->whereNotNull('callback_at')
                  ->where('status', 'ACTIVE')
            )
            ->with(['customer', 'cycles'])
            ->get();

        return AgentLeadResource::collection($leads);
    }
}
```

- [ ] **Step 4: Add routes**

Add to `routes/api.php` (create if doesn't exist):

```php
// routes/api.php
<?php

use App\Http\Controllers\AgentLeadController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum'])->group(function () {
    // Agent routes
    Route::prefix('agent')->group(function () {
        Route::get('leads', [AgentLeadController::class, 'index']);
        Route::get('leads/callbacks', [AgentLeadController::class, 'callbacks']);
        Route::get('leads/{lead}', [AgentLeadController::class, 'show']);
        Route::post('leads/{lead}/call', [AgentLeadController::class, 'call']);
        Route::post('leads/{lead}/outcome', [AgentLeadController::class, 'outcome']);
    });
});
```

- [ ] **Step 5: Run test to verify it passes**

```bash
php artisan test tests/Feature/Controllers/AgentLeadControllerTest.php
```
Expected: PASS

- [ ] **Step 6: Commit**

```bash
git add -A
git commit -m "feat(agent-api): implement AgentLeadController

- GET /api/agent/leads - list assigned leads (no phone)
- POST /api/agent/leads/{id}/call - get sip: link
- POST /api/agent/leads/{id}/outcome - record outcome
- Authorization checks prevent accessing other agents' leads"
```

---

### Task 13: TypeScript Types

**Files:**
- Create: `resources/js/types/lead-pool.ts`

- [ ] **Step 1: Create TypeScript types**

```typescript
// resources/js/types/lead-pool.ts

export type PoolStatus = 'AVAILABLE' | 'ASSIGNED' | 'COOLDOWN' | 'EXHAUSTED';

export type LeadOutcome =
  | 'NO_ANSWER'
  | 'CALLBACK'
  | 'INTERESTED'
  | 'ORDERED'
  | 'NOT_INTERESTED'
  | 'WRONG_NUMBER';

export interface LeadCycle {
  id: number;
  cycle_number: number;
  status: 'ACTIVE' | 'CLOSED';
  outcome: LeadOutcome | null;
  call_count: number;
  last_call_at: string | null;
  callback_at: string | null;
  notes: string | null;
  opened_at: string;
  closed_at: string | null;
}

export interface AgentLead {
  id: number;
  name: string;
  city: string | null;
  state: string | null;
  barangay: string | null;
  product_name: string | null;
  product_brand: string | null;
  amount: number | null;
  status: string;
  sales_status: string;
  pool_status: PoolStatus;
  total_cycles: number;
  call_attempts: number;
  last_called_at: string | null;
  assigned_at: string | null;
  created_at: string;
  customer?: {
    id: number;
    name: string;
    total_orders: number;
    successful_orders: number;
    success_rate: number;
  };
  cycles?: LeadCycle[];
}

export interface PoolStats {
  available: number;
  assigned: number;
  cooldown: number;
  exhausted: number;
}

export interface AgentPerformance {
  id: number;
  name: string;
  active_leads: number;
  called_today: number;
  sold_today: number;
  no_answer_today: number;
  conversion_rate: number;
  status: 'ONLINE' | 'IDLE' | 'OFFLINE';
}

export interface FraudFlag {
  id: number;
  agent_id: number;
  agent_name: string;
  flag_type: 'SUSPICIOUS_VELOCITY' | 'NO_CALL_INITIATED' | 'OUTCOME_TAMPERING' | 'LEAD_HOARDING';
  severity: 'WARNING' | 'CRITICAL';
  details: Record<string, unknown>;
  is_reviewed: boolean;
  created_at: string;
}

export interface OutcomeFormData {
  outcome: LeadOutcome;
  remarks?: string;
  callback_at?: string;
}
```

- [ ] **Step 2: Export from types/index**

Add to `resources/js/types/index.ts` (if exists, otherwise create):

```typescript
export * from './lead-pool';
```

- [ ] **Step 3: Commit**

```bash
git add -A
git commit -m "feat(types): add TypeScript types for lead pool system

- AgentLead, LeadCycle, PoolStatus, LeadOutcome
- AgentPerformance, FraudFlag for supervisor views
- OutcomeFormData for form handling"
```

---

### Task 14: CallButton Component

**Files:**
- Create: `resources/js/components/leads/CallButton.tsx`

- [ ] **Step 1: Create CallButton component**

```tsx
// resources/js/components/leads/CallButton.tsx
import { useState } from 'react';
import { Phone, Loader2 } from 'lucide-react';
import { Button } from '@/components/ui/button';

interface CallButtonProps {
  leadId: number;
  disabled?: boolean;
  onCallInitiated?: (callCount: number) => void;
}

export function CallButton({ leadId, disabled, onCallInitiated }: CallButtonProps) {
  const [isLoading, setIsLoading] = useState(false);

  const handleCall = async () => {
    setIsLoading(true);

    try {
      const response = await fetch(`/api/agent/leads/${leadId}/call`, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'X-CSRF-TOKEN': document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content || '',
        },
      });

      if (!response.ok) {
        throw new Error('Failed to initiate call');
      }

      const data = await response.json();

      // Open SIP link - MicroSIP will handle it
      window.location.href = data.sip_link;

      if (onCallInitiated) {
        onCallInitiated(data.call_count);
      }
    } catch (error) {
      console.error('Call failed:', error);
      alert('Failed to initiate call. Please try again.');
    } finally {
      setIsLoading(false);
    }
  };

  return (
    <Button
      onClick={handleCall}
      disabled={disabled || isLoading}
      className="bg-green-600 hover:bg-green-700"
    >
      {isLoading ? (
        <Loader2 className="mr-2 h-4 w-4 animate-spin" />
      ) : (
        <Phone className="mr-2 h-4 w-4" />
      )}
      Call
    </Button>
  );
}
```

- [ ] **Step 2: Commit**

```bash
git add -A
git commit -m "feat(ui): add CallButton component with SIP integration

- Initiates call via API
- Opens sip: link for MicroSIP
- Loading state during API call
- Reports call count on success"
```

---

### Task 15: OutcomeModal Component

**Files:**
- Create: `resources/js/components/leads/OutcomeModal.tsx`

- [ ] **Step 1: Create OutcomeModal component**

```tsx
// resources/js/components/leads/OutcomeModal.tsx
import { useState } from 'react';
import { router } from '@inertiajs/react';
import {
  Dialog,
  DialogContent,
  DialogHeader,
  DialogTitle,
  DialogFooter,
} from '@/components/ui/dialog';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { RadioGroup, RadioGroupItem } from '@/components/ui/radio-group';
import type { LeadOutcome, OutcomeFormData } from '@/types/lead-pool';

interface OutcomeModalProps {
  leadId: number;
  isOpen: boolean;
  onClose: () => void;
  onSuccess?: () => void;
}

const outcomes: { value: LeadOutcome; label: string; description: string }[] = [
  { value: 'NO_ANSWER', label: 'No Answer', description: "Couldn't reach the customer" },
  { value: 'CALLBACK', label: 'Callback', description: 'Customer requested callback' },
  { value: 'INTERESTED', label: 'Interested', description: 'Warm lead, needs follow-up' },
  { value: 'ORDERED', label: 'Ordered/Sold', description: 'Successful sale!' },
  { value: 'NOT_INTERESTED', label: 'Not Interested', description: 'Customer declined' },
  { value: 'WRONG_NUMBER', label: 'Wrong Number', description: 'Invalid contact' },
];

export function OutcomeModal({ leadId, isOpen, onClose, onSuccess }: OutcomeModalProps) {
  const [selectedOutcome, setSelectedOutcome] = useState<LeadOutcome | null>(null);
  const [remarks, setRemarks] = useState('');
  const [callbackDate, setCallbackDate] = useState('');
  const [callbackTime, setCallbackTime] = useState('');
  const [isSubmitting, setIsSubmitting] = useState(false);

  const handleSubmit = async () => {
    if (!selectedOutcome) return;

    setIsSubmitting(true);

    const data: OutcomeFormData = {
      outcome: selectedOutcome,
      remarks: remarks || undefined,
    };

    if (selectedOutcome === 'CALLBACK' && callbackDate && callbackTime) {
      data.callback_at = `${callbackDate}T${callbackTime}:00`;
    }

    try {
      const response = await fetch(`/api/agent/leads/${leadId}/outcome`, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'X-CSRF-TOKEN': document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content || '',
        },
        body: JSON.stringify(data),
      });

      if (!response.ok) {
        throw new Error('Failed to record outcome');
      }

      // Reset form
      setSelectedOutcome(null);
      setRemarks('');
      setCallbackDate('');
      setCallbackTime('');

      onSuccess?.();
      onClose();

      // Refresh page data
      router.reload();
    } catch (error) {
      console.error('Failed to record outcome:', error);
      alert('Failed to record outcome. Please try again.');
    } finally {
      setIsSubmitting(false);
    }
  };

  return (
    <Dialog open={isOpen} onOpenChange={onClose}>
      <DialogContent className="sm:max-w-md">
        <DialogHeader>
          <DialogTitle>Record Call Outcome</DialogTitle>
        </DialogHeader>

        <div className="space-y-4 py-4">
          <RadioGroup
            value={selectedOutcome || ''}
            onValueChange={(value) => setSelectedOutcome(value as LeadOutcome)}
          >
            {outcomes.map((outcome) => (
              <div key={outcome.value} className="flex items-start space-x-3 p-2 rounded hover:bg-muted">
                <RadioGroupItem value={outcome.value} id={outcome.value} />
                <Label htmlFor={outcome.value} className="cursor-pointer flex-1">
                  <div className="font-medium">{outcome.label}</div>
                  <div className="text-sm text-muted-foreground">{outcome.description}</div>
                </Label>
              </div>
            ))}
          </RadioGroup>

          {selectedOutcome === 'CALLBACK' && (
            <div className="space-y-2 p-3 bg-muted rounded-lg">
              <Label>Schedule Callback</Label>
              <div className="flex gap-2">
                <input
                  type="date"
                  value={callbackDate}
                  onChange={(e) => setCallbackDate(e.target.value)}
                  min={new Date().toISOString().split('T')[0]}
                  className="flex h-10 rounded-md border border-input bg-background px-3 py-2 text-sm"
                />
                <input
                  type="time"
                  value={callbackTime}
                  onChange={(e) => setCallbackTime(e.target.value)}
                  className="flex h-10 rounded-md border border-input bg-background px-3 py-2 text-sm"
                />
              </div>
            </div>
          )}

          <div className="space-y-2">
            <Label htmlFor="remarks">Remarks (optional)</Label>
            <Textarea
              id="remarks"
              value={remarks}
              onChange={(e) => setRemarks(e.target.value)}
              placeholder="Add notes about this call..."
              rows={3}
            />
          </div>
        </div>

        <DialogFooter>
          <Button variant="outline" onClick={onClose}>
            Cancel
          </Button>
          <Button
            onClick={handleSubmit}
            disabled={!selectedOutcome || isSubmitting}
          >
            {isSubmitting ? 'Saving...' : 'Save Outcome'}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}
```

- [ ] **Step 2: Commit**

```bash
git add -A
git commit -m "feat(ui): add OutcomeModal for recording call results

- Radio selection for all outcome types
- Callback date/time picker when CALLBACK selected
- Optional remarks field
- Refreshes page data on success"
```

---

### Task 16: Agent Leads Page

**Files:**
- Create: `resources/js/pages/AgentLeads/Index.tsx`
- Create: `resources/js/pages/AgentLeads/LeadCard.tsx`

- [ ] **Step 1: Create LeadCard component**

```tsx
// resources/js/pages/AgentLeads/LeadCard.tsx
import { useState } from 'react';
import { Clock, MapPin, Package, TrendingUp, Phone } from 'lucide-react';
import { Card, CardContent } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { CallButton } from '@/components/leads/CallButton';
import { OutcomeModal } from '@/components/leads/OutcomeModal';
import type { AgentLead } from '@/types/lead-pool';
import { formatDistanceToNow } from 'date-fns';

interface LeadCardProps {
  lead: AgentLead;
}

export function LeadCard({ lead }: LeadCardProps) {
  const [showOutcomeModal, setShowOutcomeModal] = useState(false);
  const [callCount, setCallCount] = useState(lead.cycles?.[0]?.call_count || 0);

  const activeCycle = lead.cycles?.find((c) => c.status === 'ACTIVE');
  const hasCallback = activeCycle?.callback_at && new Date(activeCycle.callback_at) <= new Date();

  return (
    <>
      <Card className={hasCallback ? 'border-yellow-500 border-2' : ''}>
        <CardContent className="p-4">
          <div className="flex justify-between items-start">
            <div className="space-y-1">
              <h3 className="font-semibold text-lg">{lead.name}</h3>
              <div className="flex items-center gap-2 text-sm text-muted-foreground">
                <MapPin className="h-4 w-4" />
                {lead.city || 'Unknown location'}
                {lead.barangay && `, ${lead.barangay}`}
              </div>
            </div>
            <Badge variant={lead.pool_status === 'ASSIGNED' ? 'default' : 'secondary'}>
              Cycle {lead.total_cycles}
            </Badge>
          </div>

          {hasCallback && (
            <div className="mt-2 p-2 bg-yellow-50 rounded-md flex items-center gap-2 text-yellow-800">
              <Clock className="h-4 w-4" />
              <span className="text-sm font-medium">Callback due now!</span>
            </div>
          )}

          <div className="mt-4 grid grid-cols-2 gap-4 text-sm">
            <div className="flex items-center gap-2">
              <Package className="h-4 w-4 text-muted-foreground" />
              <span>{lead.product_name || 'No product'}</span>
            </div>
            {lead.amount && (
              <div className="flex items-center gap-2">
                <TrendingUp className="h-4 w-4 text-green-600" />
                <span className="font-medium text-green-600">
                  ₱{lead.amount.toLocaleString()}
                </span>
              </div>
            )}
          </div>

          {lead.customer && (
            <div className="mt-3 p-2 bg-muted rounded-md text-sm">
              <div className="flex justify-between">
                <span>Previous orders: {lead.customer.total_orders}</span>
                <span className="text-green-600">
                  {lead.customer.success_rate}% success
                </span>
              </div>
            </div>
          )}

          <div className="mt-4 flex items-center justify-between">
            <div className="text-sm text-muted-foreground">
              <Phone className="h-4 w-4 inline mr-1" />
              {callCount} calls this cycle
            </div>
            <div className="flex gap-2">
              <CallButton
                leadId={lead.id}
                onCallInitiated={(count) => {
                  setCallCount(count);
                  // Show outcome modal after short delay
                  setTimeout(() => setShowOutcomeModal(true), 2000);
                }}
              />
              <Button
                variant="outline"
                onClick={() => setShowOutcomeModal(true)}
              >
                Set Outcome
              </Button>
            </div>
          </div>
        </CardContent>
      </Card>

      <OutcomeModal
        leadId={lead.id}
        isOpen={showOutcomeModal}
        onClose={() => setShowOutcomeModal(false)}
      />
    </>
  );
}
```

- [ ] **Step 2: Create Agent Leads Index page**

```tsx
// resources/js/pages/AgentLeads/Index.tsx
import { Head } from '@inertiajs/react';
import { Phone, Clock, CheckCircle, XCircle } from 'lucide-react';
import AppLayout from '@/layouts/AppLayout';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { LeadCard } from './LeadCard';
import type { AgentLead } from '@/types/lead-pool';

interface Props {
  leads: AgentLead[];
  stats: {
    active: number;
    called_today: number;
    sold_today: number;
    callbacks_due: number;
  };
}

export default function AgentLeadsIndex({ leads, stats }: Props) {
  const callbacksDue = leads.filter((lead) => {
    const cycle = lead.cycles?.find((c) => c.status === 'ACTIVE');
    return cycle?.callback_at && new Date(cycle.callback_at) <= new Date();
  });

  const otherLeads = leads.filter((lead) => {
    const cycle = lead.cycles?.find((c) => c.status === 'ACTIVE');
    return !cycle?.callback_at || new Date(cycle.callback_at) > new Date();
  });

  return (
    <AppLayout>
      <Head title="My Leads" />

      <div className="space-y-6">
        <div>
          <h1 className="text-2xl font-bold tracking-tight">My Leads</h1>
          <p className="text-muted-foreground">
            Your assigned leads to call today
          </p>
        </div>

        {/* Stats */}
        <div className="grid gap-4 md:grid-cols-4">
          <Card>
            <CardHeader className="flex flex-row items-center justify-between pb-2">
              <CardTitle className="text-sm font-medium text-muted-foreground">
                Active Leads
              </CardTitle>
              <Phone className="h-4 w-4 text-muted-foreground" />
            </CardHeader>
            <CardContent>
              <div className="text-2xl font-bold">{stats.active}</div>
            </CardContent>
          </Card>
          <Card>
            <CardHeader className="flex flex-row items-center justify-between pb-2">
              <CardTitle className="text-sm font-medium text-muted-foreground">
                Called Today
              </CardTitle>
              <Phone className="h-4 w-4 text-blue-600" />
            </CardHeader>
            <CardContent>
              <div className="text-2xl font-bold text-blue-600">{stats.called_today}</div>
            </CardContent>
          </Card>
          <Card>
            <CardHeader className="flex flex-row items-center justify-between pb-2">
              <CardTitle className="text-sm font-medium text-muted-foreground">
                Sold Today
              </CardTitle>
              <CheckCircle className="h-4 w-4 text-green-600" />
            </CardHeader>
            <CardContent>
              <div className="text-2xl font-bold text-green-600">{stats.sold_today}</div>
            </CardContent>
          </Card>
          <Card>
            <CardHeader className="flex flex-row items-center justify-between pb-2">
              <CardTitle className="text-sm font-medium text-muted-foreground">
                Callbacks Due
              </CardTitle>
              <Clock className="h-4 w-4 text-yellow-600" />
            </CardHeader>
            <CardContent>
              <div className="text-2xl font-bold text-yellow-600">{stats.callbacks_due}</div>
            </CardContent>
          </Card>
        </div>

        {/* Callbacks Due Section */}
        {callbacksDue.length > 0 && (
          <div>
            <h2 className="text-lg font-semibold mb-3 flex items-center gap-2">
              <Clock className="h-5 w-5 text-yellow-600" />
              Callbacks Due ({callbacksDue.length})
            </h2>
            <div className="grid gap-4 md:grid-cols-2 lg:grid-cols-3">
              {callbacksDue.map((lead) => (
                <LeadCard key={lead.id} lead={lead} />
              ))}
            </div>
          </div>
        )}

        {/* All Leads */}
        <div>
          <h2 className="text-lg font-semibold mb-3">
            All Leads ({otherLeads.length})
          </h2>
          {otherLeads.length > 0 ? (
            <div className="grid gap-4 md:grid-cols-2 lg:grid-cols-3">
              {otherLeads.map((lead) => (
                <LeadCard key={lead.id} lead={lead} />
              ))}
            </div>
          ) : (
            <Card>
              <CardContent className="py-8 text-center text-muted-foreground">
                No leads assigned. Check back later!
              </CardContent>
            </Card>
          )}
        </div>
      </div>
    </AppLayout>
  );
}
```

- [ ] **Step 3: Add controller method for Inertia page**

Add to `app/Http/Controllers/AgentLeadController.php`:

```php
use Inertia\Inertia;
use Inertia\Response;

public function dashboard(): Response
{
    $leads = Lead::where('assigned_to', auth()->id())
        ->where('pool_status', PoolStatus::ASSIGNED)
        ->with(['customer', 'cycles' => fn($q) => $q->where('assigned_agent_id', auth()->id())])
        ->orderByRaw("CASE WHEN EXISTS (
            SELECT 1 FROM lead_cycles
            WHERE lead_cycles.lead_id = leads.id
            AND lead_cycles.callback_at IS NOT NULL
            AND lead_cycles.callback_at <= NOW()
            AND lead_cycles.status = 'ACTIVE'
        ) THEN 0 ELSE 1 END")
        ->orderBy('assigned_at', 'asc')
        ->get();

    $stats = [
        'active' => $leads->count(),
        'called_today' => LeadCycle::where('assigned_agent_id', auth()->id())
            ->whereDate('last_call_at', today())
            ->count(),
        'sold_today' => LeadCycle::where('assigned_agent_id', auth()->id())
            ->where('outcome', 'ORDERED')
            ->whereDate('closed_at', today())
            ->count(),
        'callbacks_due' => LeadCycle::where('assigned_agent_id', auth()->id())
            ->where('status', 'ACTIVE')
            ->whereNotNull('callback_at')
            ->where('callback_at', '<=', now())
            ->count(),
    ];

    return Inertia::render('AgentLeads/Index', [
        'leads' => AgentLeadResource::collection($leads)->resolve(),
        'stats' => $stats,
    ]);
}
```

- [ ] **Step 4: Add route**

Add to `routes/web.php`:

```php
use App\Http\Controllers\AgentLeadController;

// Inside the auth middleware group:
Route::get('/my-leads', [AgentLeadController::class, 'dashboard'])->name('agent.leads');
```

- [ ] **Step 5: Commit**

```bash
git add -A
git commit -m "feat(agent-ui): add Agent Leads page with click-to-call

- LeadCard component with call button and outcome modal
- Agent Leads Index page with callbacks section
- Stats: active leads, called today, sold today, callbacks due
- Phone numbers never visible - only click-to-call"
```

---

## Phase 3: Supervisor Dashboard (Continued in Part 2)

> **Note:** This plan continues with Tasks 17-30 covering:
> - LeadPoolController for supervisors
> - Distribution modal and supervisor dashboard
> - Agent performance monitoring
> - Activity feed (real-time)
> - Fraud flags review

See Part 2 of this plan for supervisor features.

---

## Phase 4: Automation (Continued in Part 2)

> **Note:** Tasks 31-40 cover:
> - ProcessCooldownLeads job
> - DetectFraudPatterns job
> - WaybillObserver for auto-lead creation
> - CSV import functionality

See Part 2 of this plan for automation features.

---

## Testing Checklist

Before marking implementation complete:

- [ ] All unit tests pass: `php artisan test --testsuite=Unit`
- [ ] All feature tests pass: `php artisan test --testsuite=Feature`
- [ ] Phone numbers are NOT visible in any agent response (verify manually)
- [ ] Click-to-call opens MicroSIP correctly
- [ ] Outcome recording triggers correct recycling behavior
- [ ] Distribution creates proper lead cycles
- [ ] Audit trail logs all actions
- [ ] Supervisor can see all agents' performance
- [ ] Background jobs process cooldowns correctly

---

## Deployment Notes

1. Run migrations: `php artisan migrate`
2. Seed recycling rules: `php artisan db:seed --class=RecyclingRulesSeeder`
3. Schedule background jobs in `app/Console/Kernel.php`:
   ```php
   $schedule->job(new ProcessCooldownLeads)->everyFifteenMinutes();
   $schedule->job(new DetectFraudPatterns)->everyThirtyMinutes();
   ```
4. Ensure MicroSIP is installed on all agent workstations
5. Configure supervisor to run queue workers

---

*End of Implementation Plan - Part 1*
