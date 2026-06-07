# Leads Distribution Architecture

## 1. Executive Summary

This document describes the current and proposed architecture for lead distribution within WarehouseOps. The existing system handles basic lead assignment (equal split, custom split), recycling, and cooldowns. The proposed architecture introduces **real-time routing**, **capacity-aware allocation**, **skill/region matching**, **performance-weighted distribution**, and **automated distribution triggers**.

---

## 2. Current Architecture (As-Is)

### 2.1 Domain Model

```
┌─────────────┐     1:N     ┌──────────────┐     1:N     ┌─────────────┐
│    Lead     │◄───────────│  LeadCycle   │◄───────────│  LeadLog    │
└──────┬──────┘             └──────┬───────┘             └─────────────┘
       │                           │
       │ N:1                       │ N:1
       ▼                           ▼
┌─────────────┐               ┌─────────────┐
│    User     │               │    User     │
│   (Agent)   │               │ (Assigned)  │
└─────────────┘               └─────────────┘

┌─────────────┐     1:1     ┌─────────────┐
│    User     │◄───────────│AgentProfile  │
└─────────────┘             └─────────────┘
```

### 2.2 Status Dimensions

| Dimension | Enum | Values |
|-----------|------|--------|
| **Lead Status** | `LeadStatus` | NEW → CALLING → SALE/REORDER/DELIVERED/RETURNED/NO_ANSWER/REJECT/CALLBACK/CANCELLED/ARCHIVED |
| **Pool Status** | `PoolStatus` | AVAILABLE → ASSIGNED → COOLDOWN → EXHAUSTED |
| **Sales Status** | `SalesStatus` | NEW → QA_PENDING → APPROVED → REJECTED |
| **Cycle Status** | string | ACTIVE → CLOSED |

### 2.3 Existing Services

| Service | Responsibility |
|---------|-------------|
| `LeadDistributionService` | Equal split, custom split, get available agents |
| `LeadPoolService` | Manage pool status transitions, filter available leads |
| `LeadRecyclingService` | Process outcomes, cooldown expiry, callback expiry, revive |
| `LeadAuditService` | Immutable audit log for all lead events |
| `LeadImportService` | Import leads from CSV/Excel |

### 2.4 Distribution Flow (Current)

```
┌──────────────┐    ┌──────────────┐    ┌─────────────────┐    ┌──────────────┐
│ Lead Import  │───▶│ Lead Pool    │───▶│ Supervisor      │───▶│ Agents       │
│ / API / Form │    │ (AVAILABLE)  │    │ Selects agents  │    │ (ASSIGNED)   │
└──────────────┘    └──────────────┘    │ & Distributes   │    └──────────────┘
                                          └─────────────────┘
```

**Problems with current flow:**
1. **Manual trigger only** — Supervisor must manually initiate distribution
2. **No capacity guard** — Agents can be overloaded beyond `max_active_cycles`
3. **No skill matching** — Leads are randomly assigned regardless of agent expertise
4. **No priority weighting** — Top performers and new agents get equal shares
5. **No region affinity** — Geographic mismatches cause delays
6. **No real-time notifications** — Agents must refresh to see new assignments

---

## 3. Proposed Architecture (To-Be)

### 3.1 Design Principles

| Principle | Description |
|-----------|-------------|
| **Fairness** | Equal opportunity with performance-weighted rewards |
| **Capacity Guard** | Never exceed agent's `max_active_cycles` |
| **Skill Match** | Route product-specific leads to skilled agents |
| **Proximity** | Prefer agents with regional expertise |
| **Transparency** | Every assignment decision is auditable |
| **Autonomy** | Supervisors can override with reason tracking |

### 3.2 New Domain Components

```
┌─────────────────────────────────────────────────────────────────────────────┐
│                         LEAD DISTRIBUTION ENGINE                            │
├─────────────────────────────────────────────────────────────────────────────┤
│                                                                             │
│  ┌─────────────────┐   ┌──────────────────┐   ┌─────────────────────────┐  │
│  │  Rule Engine    │   │  Scoring System  │   │  Capacity Manager       │  │
│  │                 │   │                  │   │                         │  │
│  │  • Round-robin  │   │  • Performance   │   │  • max_active_cycles    │  │
│  │  • Weighted     │   │  • Conversion    │   │  • concurrent_leads    │  │
│  │  • Skill-based  │   │  • Response time │   │  • availability_window  │  │
│  │  • Territory    │   │  • Quality score │   │  • break/shift status   │  │
│  └─────────────────┘   └──────────────────┘   └─────────────────────────┘  │
│                                                                             │
│  ┌─────────────────┐   ┌──────────────────┐   ┌─────────────────────────┐  │
│  │  Queue Manager  │   │  Event Bus       │   │  Notification Service   │  │
│  │                 │   │                  │   │                         │  │
│  │  • FIFO         │   │  • New lead      │   │  • WebSocket push       │  │
│  │  • Priority     │   │  • Agent avail   │   │  • In-app badge         │  │
│  │  • Batching     │   │  • Cooldown end  │   │  • SMS/Email (opt)      │  │
│  └─────────────────┘   └──────────────────┘   └─────────────────────────┘  │
│                                                                             │
└─────────────────────────────────────────────────────────────────────────────┘
```

### 3.3 New/Modified Data Model

```php
// === AgentProfile additions ===
class AgentProfile extends Model
{
    protected $fillable = [
        // ... existing ...
        'distribution_weight',      // 0.5–2.0 multiplier (default 1.0)
        'auto_assign_enabled',      // bool — opt-in to auto-distribution
        'shift_start',              // time
        'shift_end',                // time
        'max_daily_leads',          // int — hard cap per day
        'concurrent_lead_cap',      // int — override max_active_cycles
        'preferred_lead_sources',   // array — e.g. ['facebook', 'organic']
        'excluded_regions',         // array — opt-out regions
    ];
}

// === New: DistributionRule ===
class DistributionRule extends Model
{
    protected $fillable = [
        'name',
        'strategy',                 // 'round_robin' | 'weighted' | 'skill_match' | 'territory' | 'hybrid'
        'priority',                 // int — rule precedence
        'filters',                  // json — { product_skills: [...], regions: [...], sources: [...] }
        'weight_formula',           // json — { performance: 0.4, response_time: 0.3, availability: 0.3 }
        'is_active',
        'supervisor_id',            // nullable — who created it
    ];
}

// === New: DistributionQueue ===
class DistributionQueue extends Model
{
    protected $fillable = [
        'lead_id',
        'rule_id',
        'status',                   // 'pending' | 'assigned' | 'failed' | 'cancelled'
        'assigned_agent_id',
        'score_snapshot',           // json — agent scores at assignment time
        'attempt_count',
        'processed_at',
    ];
}

// === New: AgentWorkload === (materialized view or cache table)
class AgentWorkload extends Model
{
    protected $fillable = [
        'agent_id',
        'active_leads_count',
        'today_assigned_count',
        'today_converted_count',
        'last_assigned_at',
        'next_available_at',
    ];
}
```

### 3.4 Distribution Strategies

| Strategy | Description | Use Case |
|----------|-------------|----------|
| **Round Robin** | Circular queue across eligible agents | Default fallback, fair rotation |
| **Weighted** | Weight = performance × availability × priority | Reward top performers |
| **Skill Match** | Match `lead.product_name` to `agent_profile.product_skills` | Product expertise matters |
| **Territory** | Match `lead.city/region` to `agent_profile.regions` | Local knowledge matters |
| **Hybrid** | Weighted combination of all above with configurable weights | Default recommended strategy |
| **Supervisor Override** | Manual assignment with reason audit | Escalations, VIP leads |

### 3.5 Hybrid Scoring Formula

```
Score(agent, lead) =
    w_perf  × normalize(performance_score) +
    w_avail × availability_factor(agent) +
    w_skill × skill_match(agent.skills, lead.product) +
    w_reg   × region_match(agent.regions, lead.city) +
    w_load  × (1 - load_factor(agent)) +
    w_time  × time_since_last_assignment(agent)

where:
    availability_factor = 0   if agent.off_shift or on_break
                        = 0.5 if agent.max_cycles_reached
                        = 1.0 otherwise

    load_factor = active_leads / max_active_cycles

    time_since_last_assignment = hours since last assignment (capped at 24h)
```

### 3.6 System Flow (Proposed)

```
                              ┌──────────────────┐
                              │   New Lead In    │
                              │ (Import/API/Form)│
                              └────────┬─────────┘
                                       │
                              ┌────────▼─────────┐
                              │  Quality Score   │
                              │  & Enrichment    │
                              └────────┬─────────┘
                                       │
                              ┌────────▼─────────┐
                              │  Apply Rules     │
                              │  (Priority order)│
                              └────────┬─────────┘
                                       │
                              ┌────────▼─────────┐
                              │  Filter Eligible │
                              │  Agents          │
                              │  (capacity/skill)│
                              └────────┬─────────┘
                                       │
                              ┌────────▼─────────┐
                              │  Score & Rank    │
                              │  Agents          │
                              └────────┬─────────┘
                                       │
                    ┌──────────────────┼──────────────────┐
                    │                  │                  │
           ┌────────▼────────┐  ┌─────▼──────┐  ┌────────▼────────┐
           │  Auto-Assign    │  │  Queue     │  │  Supervisor     │
           │  (Top scorer)   │  │  (FIFO)    │  │  Override       │
           └────────┬────────┘  └─────┬──────┘  └────────┬────────┘
                    │                 │                  │
                    └─────────────────┼──────────────────┘
                                      │
                              ┌───────▼────────┐
                              │  Create Cycle  │
                              │  + Audit Log   │
                              └───────┬────────┘
                                      │
                    ┌─────────────────┼─────────────────┐
                    │                 │                 │
           ┌────────▼────────┐ ┌────▼─────┐ ┌─────────▼─────────┐
           │  WebSocket Push │ │In-App    │ │  Agent Profile    │
           │  to Agent       │ │Badge     │ │  Update           │
           └─────────────────┘ └──────────┘ └───────────────────┘
```

---

## 4. Implementation Phases

### Phase 1: Foundation (Week 1)
- [ ] Add `AgentProfile` fields: `distribution_weight`, `auto_assign_enabled`, `shift_start`, `shift_end`, `max_daily_leads`
- [ ] Create `DistributionRule` migration + model + enum
- [ ] Create `DistributionQueue` migration + model
- [ ] Create `AgentWorkload` materialized view
- [ ] Add `Lead.quality_score` scoring hook on import

### Phase 2: Core Engine (Week 2)
- [ ] Build `DistributionEngine` service class
- [ ] Implement scoring strategies (RoundRobin, Weighted, SkillMatch, Territory, Hybrid)
- [ ] Implement `CapacityManager` — enforce `max_active_cycles` + `max_daily_leads`
- [ ] Add `AgentAvailability` helper (shift check, break status)
- [ ] Supervisor UI: rule CRUD, manual assignment override

### Phase 3: Automation (Week 3)
- [ ] Cron job: auto-distribute leads from queue every 60s
- [ ] Event listener: trigger distribution on `LeadCreated` event
- [ ] Queue worker: process distribution jobs in background
- [ ] WebSocket integration: push assignments to agents

### Phase 4: Monitoring & Tuning (Week 4)
- [ ] Distribution analytics dashboard
- [ ] Agent performance correlation with distribution weights
- [ ] A/B testing framework for strategy comparison
- [ ] Alerting: agents approaching capacity, queue backlog

---

## 5. API Surface

### Distribution Endpoints

| Method | Endpoint | Description |
|--------|----------|-------------|
| POST | `/api/leads/distribute` | Trigger auto-distribution (supervisor) |
| POST | `/api/leads/{lead}/assign` | Manual assignment with reason |
| POST | `/api/leads/{lead}/reassign` | Reassign to different agent |
| GET | `/api/distribution/rules` | List active rules |
| POST | `/api/distribution/rules` | Create new rule |
| GET | `/api/agents/{agent}/workload` | Current load stats |
| GET | `/api/distribution/queue` | Pending queue status |
| POST | `/api/distribution/queue/process` | Process pending queue |

### WebSocket Events

| Event | Direction | Payload |
|-------|-----------|---------|
| `lead.assigned` | Server → Agent | `{ lead_id, customer_name, product, priority }` |
| `agent.capacity.warning` | Server → Agent | `{ active_count, max_count, remaining }` |
| `queue.updated` | Server → Supervisor | `{ pending_count, processing_count }` |

---

## 6. UI Components Needed

| Component | Purpose |
|-----------|---------|
| `LeadDistributionPanel` | Supervisor view: rules, manual assign, queue status |
| `AgentCapacityIndicator` | Agent card showing current load vs max |
| `DistributionRuleForm` | Create/edit distribution rules with visual formula builder |
| `LeadQueueMonitor` | Real-time queue dashboard |
| `AssignmentHistory` | Audit trail of who got what and why |
| `AgentPerformanceCard` | Performance metrics that feed into scoring |

---

## 7. Key Files (Current → New)

| Current | Status | New / Change |
|---------|--------|-------------|
| `LeadDistributionService.php` | Extend | Add `distributeAuto()`, `scoreAgents()`, `applyRule()` |
| `LeadPoolService.php` | Minor | Add `enqueue()`, `dequeue()` |
| `AgentProfile.php` | Extend | Add distribution fields |
| `Lead.php` | Minor | Add `quality_score` default, scope `unassigned()` |
| — | **New** | `DistributionRule.php` model |
| — | **New** | `DistributionQueue.php` model |
| — | **New** | `DistributionEngine.php` service |
| — | **New** | `AgentWorkloadCache.php` helper |
| — | **New** | `AutoDistributeLeads.php` job |
| — | **New** | `LeadAssigned.php` event + listener |
| `resources/js/pages/Leads/` | Extend | Add distribution UI tab |
| `resources/js/pages/Agents/Index.tsx` | Extend | Add capacity indicator |

---

## 8. Risk Mitigation

| Risk | Mitigation |
|------|-----------|
| Agent overload | Hard cap via `max_active_cycles` + `max_daily_leads` |
| Uneven distribution | Weekly rebalancing report + weight adjustments |
| System downtime | Queue persists; jobs retry with exponential backoff |
| Supervisor resistance | Gradual rollout: auto-distribute only from queue, not live leads |
| Data privacy | No PII in WebSocket payload; only lead_id + meta |

---

## 9. Success Metrics

| Metric | Target | Measurement |
|--------|--------|-------------|
| Average time-to-assign | < 5 minutes | From lead creation to cycle open |
| Agent utilization | 70–90% | `active_leads / max_active_cycles` |
| Conversion rate by strategy | +10% vs current | Compare hybrid vs round-robin |
| Supervisor time saved | -80% | Time spent on manual distribution |
| Queue backlog | < 50 leads | Pending queue size |

---

*Document version: 1.0*
*Last updated: 2026-06-07*
