# Lead Distribution System — Refactor Architecture Guide
**WarehouseOps v5 · June 2026**

> Single source of truth for current architecture and refactoring standards.

---

## 1. Agent Workflow Map

```
06:00  Agent clocks in → System checks shift_start / auto_assign_enabled
08:00  Supervisor imports leads → AutoDistributeOnLeadCreated fires
08:05  Agent receives lead via WebSocket → Opens "My Leads"
08:10  Agent calls customer → Logs disposition
08:15  System closes cycle, updates workload, recycles or queues QA
12:00  Supervisor reviews pool stats → Manual redistribution if needed
17:00  Agent clocks out → System blocks new assignments
```

| Agent Action | Backend Component | Frontend Component |
|-------------|-------------------|-------------------|
| Clock in | `AgentAvailability::isWithinShift()` | Agent dashboard |
| Import leads | `TelesalesLeadImportService` | `Telesales/Import.tsx` |
| Auto-distribution | `AutoDistributeOnLeadCreated` → `AutoDistributeLeads` job | — |
| Lead assigned | `DistributionEngine::findBestAgent()` | WebSocket → `LeadPool/Index.tsx` |
| Agent calls | `LeadCycle::create()` | `Agent/Leads.tsx` |
| Disposition | `LeadRecyclingService` | Disposition modal |
| Manual redistribute | `LeadDistributionService::distributeEqual/Custom()` | `DistributionModal.tsx` |

---

## 2. Domain Model (Production Truth)

### 2.1 Entity Map

```
Lead ──1:N── LeadCycle ──1:N── LeadLog
 │             │
 │ N:1         │ N:1
 ▼             ▼
Customer    User (agent) ──1:1── AgentProfile
                              │
                              └── AgentWorkload

DistributionRule ──1:N── DistributionQueue
```

### 2.2 Core Enums

```php
enum LeadStatus: string { NEW, CALLING, NO_ANSWER, REJECT, CALLBACK, SALE, REORDER, CANCELLED }
enum PoolStatus: string { AVAILABLE, ASSIGNED, COOLDOWN, EXHAUSTED }
enum SalesStatus: string { NEW, CONTACTED, AGENT_CONFIRMED, QA_PENDING, QA_APPROVED, QA_REJECTED, OPS_APPROVED, CANCELLED, WAYBILL_CREATED }
enum DistributionStrategy: string { ROUND_ROBIN, WEIGHTED, SKILL_MATCH, TERRITORY, HYBRID }
enum LeadSource: string { TELESALES_IMPORT, XLSX_IMPORT, MANUAL_ENTRY, API, FACEBOOK, ORGANIC }
```

### 2.3 Lead Defaults

```php
status          => LeadStatus::NEW
sales_status    => SalesStatus::NEW
pool_status     => PoolStatus::AVAILABLE
total_cycles    => 0
max_cycles      => 3
is_exhausted    => false
quality_score   => 50
```

---

## 3. Component Architecture

### 3.1 Layers

```
PRESENTATION (React + Inertia)
├── Leads/Index.tsx       (legacy — redirects to LeadPool)
├── LeadPool/Index.tsx    (unified: pool / imported / all tabs)
├── Distribution/Index.tsx (rules, queue, workloads)
├── Agent/Leads.tsx       (agent workspace)
└── components/leads/DistributionModal.tsx

CONTROLLERS
├── LeadController        (index→redirect, show, qc, recycling)
├── LeadPoolController    (unified index, distribute, agentPerformance)
├── DistributionController (rules CRUD, assign, reassign, autoDistribute)
└── TelesalesImportController (import, store)

SERVICES
├── DistributionEngine      (rule evaluation, scoring, fallback round-robin)
├── LeadDistributionService (batch manual: equal/custom split)
├── CapacityManager         (canAcceptLead, recordAssignment, recordCycleClose)
├── AgentAvailability       (shift check, excluded regions, source preference)
├── LeadPoolService         (pool status transitions, stats, cache invalidation)
├── LeadAuditService        (immutable audit logging)
└── TelesalesLeadImportService (CSV/XLSX parsing, phone normalization)

JOBS / LISTENERS
├── AutoDistributeLeads     (batch processor: queue + fresh leads)
└── AutoDistributeOnLeadCreated (debounced listener, 30s window)

DATA
├── Lead, LeadCycle, LeadLog, Customer, User
├── AgentProfile, AgentWorkload
├── DistributionRule, DistributionQueue
└── Cache key: lead_pool:stats (30s TTL)
```

### 3.2 Responsibility Matrix

| Component | Must Do | Must NOT Do |
|-----------|---------|-------------|
| `DistributionEngine` | Find best agent for one lead | Handle batch distribution or UI |
| `LeadDistributionService` | Execute batch manual distribution | Compute scores or rules |
| `CapacityManager` | Check capacity, record changes | Make assignment decisions |
| `AgentAvailability` | Shift, region, source checks | Check capacity |
| `LeadPoolService` | Transition pool statuses with audit | Perform actual agent assignment |
| `LeadAuditService` | Write immutable logs | Business logic |
| `AutoDistributeLeads` | Process queue + fresh leads in batches | Run synchronously |

---

## 4. Data Flow

### 4.1 Import → Auto-Distribute

```
Supervisor uploads XLSX
→ TelesalesLeadImportService::import()
  → normalizePhone() (scientific notation → +63...)
  → create/update Customer
  → create Lead (AVAILABLE) → dispatch LeadCreated
    → AutoDistributeOnLeadCreated
      → debounce (30s Cache::add)
      → dispatch AutoDistributeLeads(batchSize: 5)
        → process DistributionQueue (pending, < 4 attempts)
        → DistributionEngine::findBestAgent()
          → match rules → filter eligible → score → rank
        → if found: assignLead()
          → DB::transaction
          → race guard (refresh + check AVAILABLE)
          → LeadCycle::create(ACTIVE)
          → lead→update(ASSIGNED, assigned_to, total_cycles++)
          → CapacityManager::recordAssignment()
          → LeadAuditService::log()
          → LeadAssigned::dispatch() (WebSocket)
        → if not found: DistributionQueue::create(pending)
```

### 4.2 Agent Work → Close

```
Agent submits disposition
→ close LeadCycle (CLOSED, outcome, closed_at)
→ CapacityManager::recordCycleClose(agent_id)
→ LeadPoolService transitions lead:
  SALE      → status: SALE, sales_status: QA_PENDING
  NO_ANSWER → pool_status: COOLDOWN, cooldown_until: +12h
  CALLBACK  → pool_status: COOLDOWN, cooldown_until: scheduled
  CANCELLED → pool_status: AVAILABLE (or EXHAUSTED if max cycles hit)
```

### 4.3 Manual Distribution

```
Supervisor selects leads + agents in LeadPool/Index.tsx
→ POST /lead-pool/distribute
→ LeadPoolController::distribute()
  → validate lead_ids, agent_ids, method
  → LeadDistributionService::distributeEqual() or distributeCustom()
    → fetch AVAILABLE leads, shuffle
    → DB::transaction
    → per agent: iterate slots, race-guard each lead
    → LeadCycle::create(), lead→update(), recordAssignment(), log()
```

---

## 5. Service Contracts

### 5.1 DistributionEngine

```php
/**
 * @return array{agent_id: ?int, rule_id: ?int, score: float, reason: string}
 */
public function findBestAgent(Lead $lead): array;

/**
 * Guards: capacity, shift, excluded regions.
 * @return Collection<int, AgentProfile>
 */
public function filterEligibleAgents(Lead $lead, ?DistributionRule $rule): Collection;
```

### 5.2 CapacityManager

```php
public function canAcceptLead(int $agentId): bool;
public function recordAssignment(int $agentId): void;
public function recordCycleClose(int $agentId): void;
```

### 5.3 LeadDistributionService

```php
/**
 * @return array{total_distributed: int, agent_count: int, per_agent: int}
 */
public function distributeEqual(array $leadIds, array $agentIds, int $supervisorId): array;
public function distributeCustom(array $leadIds, array $distribution, int $supervisorId): array;
```

---

## 6. Controller Boundaries

| Controller | Owns | Must Delegate |
|-----------|------|---------------|
| `LeadPoolController` | Unified index view, manual distribute form, agentPerformance stats | Assignment logic → `LeadDistributionService`; Scoring → `DistributionEngine` |
| `DistributionController` | Rules CRUD, single assign/reassign, autoDistribute trigger, queue API | Scoring → `DistributionEngine`; Batch → `LeadDistributionService` |
| `LeadController` | Lead show, QC queue, recycling pool | Assignment → `LeadPoolController` or `DistributionController` |
| `TelesalesImportController` | Upload handler, progress tracking | Parsing → `TelesalesLeadImportService` |

---

## 7. Frontend Component Map

| Component | Props | Events | Data Source |
|-----------|-------|--------|-------------|
| `LeadPool/Index.tsx` | `leads`, `stats`, `agents`, `filters`, `viewMode` | `switchView(mode)`, `handleSearch`, `toggleLead`, `handlePageChange` | `LeadPoolController@index` |
| `DistributionModal.tsx` | `isOpen`, `selectedLeadIds`, `agents` | `onClose`, distribute form POST | `LeadPoolController@distribute` |
| `Distribution/Index.tsx` | `rules`, `queue`, `agents`, `workloads` | CRUD rules, manual queue process | `DistributionController@index` |
| `Agent/Leads.tsx` | `leads` (assigned to me) | disposition submit | Agent-specific endpoint |

---

## 8. Event / Job Pipeline

```
LeadCreated (event)
└── AutoDistributeOnLeadCreated (listener)
    └── AutoDistributeLeads (job, queue: default, tries: 3, backoff: [30, 120, 300])
        └── LeadAssigned (event)
            └── (WebSocket broadcast to agent)

LeadPoolService::markAs* (any status change)
└── Cache::forget('lead_pool:stats')
```

| Job | Trigger | Tries | Backoff |
|-----|---------|-------|---------|
| `AutoDistributeLeads` | `LeadCreated` (debounced) | 3 | 30s, 120s, 300s |

---

## 9. Refactoring Standards

### 9.1 General Rules

1. **Single Responsibility:** A service must never call a controller. A controller must never contain business logic beyond validation and response formatting.
2. **Enum Constants:** Never use raw strings for enum values (`'NEW'`). Always use `LeadStatus::NEW`.
3. **Cache Invalidation:** Any method that changes `pool_status` must call `Cache::forget('lead_pool:stats')`.
4. **Race Guards:** Any assignment method must `refresh()` the lead inside a `DB::transaction` and re-check `pool_status === AVAILABLE` before writing.
5. **Workload Symmetry:** Every `recordAssignment()` must have a corresponding `recordCycleClose()` when the cycle ends or is reassigned.

### 9.2 N+1 Prevention

- Pre-aggregate counts with `groupBy` before iterating over collections.
- Eager-load relationships (`with(['assignedAgent', 'customer'])`) at the query level.
- Never call `::count()`, `::first()`, or `::find()` inside a loop over agents or leads.

### 9.3 Query Patterns

```php
// CORRECT: single aggregation query
$activeLeadCounts = Lead::whereIn('assigned_to', $agentIds)
    ->where('pool_status', PoolStatus::ASSIGNED)
    ->selectRaw('assigned_to, count(*) as count')
    ->groupBy('assigned_to')
    ->pluck('count', 'assigned_to');

// INCORRECT: N+1 inside loop
foreach ($agents as $agent) {
    $count = Lead::where('assigned_to', $agent->id)->count(); // NEVER
}
```

### 9.4 Transaction Boundaries

All assignment operations must be wrapped in `DB::transaction()`. The transaction must:
- Re-read (refresh) the lead
- Verify availability
- Create the cycle
- Update the lead
- Record workload
- Write audit log

### 9.5 Frontend State Standards

- `view_mode` (pool / imported / all) must persist across pagination and filters via query string.
- Selection state (`selectedLeads`) must reset when switching views.
- The Distribute button must only appear in `pool` view mode.

---

## 10. Critical Issues Registry

| ID | Issue | Severity | Component | Fix Required |
|----|-------|----------|-----------|-------------|
| ISS-001 | `AgentWorkload::isDailyCapReached()` never resets counter | **Critical** | `AgentWorkload` | Reset `today_assigned_count` when stale |
| ISS-002 | `DistributionController::autoDistribute()` inflates count outside tx | **Critical** | `DistributionController` | Return boolean from tx, increment only on true |
| ISS-003 | `LeadPoolService::markAsCooldown/Available` leaks workload | **Critical** | `LeadPoolService` | Call `recordCycleClose()` before nulling |
| ISS-004 | `LeadController::recyclingPool()` OR query ungrouped | **Critical** | `LeadController` | Wrap in closure |
| ISS-005 | `TelesalesLeadImportService` duplicate check misses XLSX_IMPORT | **Critical** | `TelesalesLeadImportService` | Check both sources |
| ISS-006 | `LeadPoolController::agentPerformance()` N+1 | **High** | `LeadPoolController` | Pre-aggregate |
| ISS-007 | `DistributionEngine::scoreAgents()` N+1 on workloads | **High** | `DistributionEngine` | Batch-fetch before map |
| ISS-008 | `Lead::activeCycle()` `HasOne` with `latest()` unreliable | **High** | `Lead` | Use `latestOfMany()` |
| ISS-009 | Controllers use raw strings for enum stats | **High** | Both controllers | Use enum constants |
| ISS-010 | `DistributionController::reassign()` no ASSIGNED guard | **Medium** | `DistributionController` | Validate pool_status |
| ISS-011 | `Lead::canRecycle()` ignores `cooldown_until` | **Medium** | `Lead` | Check field |
| ISS-012 | Debounce not atomic with file cache | **Medium** | Listener | Document Redis requirement |
| ISS-013 | Round-robin cache key not namespaced | **Medium** | `DistributionEngine` | Prefix env |
| ISS-014 | XLSX import hardcodes 15 columns | **Medium** | `TelesalesLeadImportService` | Use highest column |
| ISS-015 | Imported/all stats un-cached | **Medium** | `LeadPoolController` | Add cache |
| ISS-016 | `distributeCustom()` loses slot on race | **Medium** | `LeadDistributionService` | Fix slot logic |

---

## 11. Testing Contract

```bash
# PHP syntax
php -l app/Http/Controllers/*.php app/Services/*.php app/Models/*.php app/Jobs/*.php

# TypeScript
npx tsc --noEmit
npx eslint . --ext ts,tsx

# Key scenarios
1. Import 100 leads → exactly 1 AutoDistributeLeads job
2. High load → no duplicate assignments
3. Daily cap hit → no further auto-assignments
4. Reassign → old workload decrements, new increments
5. Cooldown lead → hidden in pool view, visible in all view
6. XLSX re-import → updates existing, no duplicates
7. Non-supervisor → 403 on /lead-pool
```

---

## 12. File Index

| File | Purpose | Last Refactored |
|------|---------|-----------------|
| `app/Services/DistributionEngine.php` | Rule evaluation + scoring | June 2026 |
| `app/Services/LeadDistributionService.php` | Batch manual distribution | June 2026 |
| `app/Services/CapacityManager.php` | Capacity tracking | June 2026 |
| `app/Services/AgentAvailability.php` | Shift & region checks | June 2026 |
| `app/Services/LeadPoolService.php` | Pool transitions | June 2026 |
| `app/Services/TelesalesLeadImportService.php` | CSV/XLSX import | June 2026 |
| `app/Jobs/AutoDistributeLeads.php` | Batch auto-assignment | June 2026 |
| `app/Listeners/AutoDistributeOnLeadCreated.php` | Debounced listener | June 2026 |
| `app/Http/Controllers/LeadPoolController.php` | Unified pool view | June 2026 |
| `app/Http/Controllers/DistributionController.php` | Rules + assign | June 2026 |
| `app/Http/Controllers/LeadController.php` | Legacy leads | June 2026 |
| `app/Domain/Lead/Models/Lead.php` | Core lead model | June 2026 |
| `app/Models/AgentWorkload.php` | Workload state | June 2026 |
| `resources/js/pages/LeadPool/Index.tsx` | Unified pool UI | June 2026 |
| `resources/js/layouts/AppLayout.tsx` | Navigation | June 2026 |

---

*End of document*
