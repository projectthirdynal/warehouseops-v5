# Lead Distribution System
**WarehouseOps — Full Architecture & Implementation Guide**
Version 1.0 · June 2026 · Confidential — Internal Use Only

---

## Table of Contents

1. [Executive Summary](#1-executive-summary)
2. [Lead Data Model (Source of Truth)](#2-lead-data-model-source-of-truth)
3. [Current Architecture (As-Is)](#3-current-architecture-as-is)
4. [Proposed Architecture (To-Be)](#4-proposed-architecture-to-be)
5. [Distribution Strategies](#5-distribution-strategies)
6. [Proposed System Flow](#6-proposed-system-flow)
7. [New Data Model Additions](#7-new-data-model-additions)
8. [API Surface](#8-api-surface)
9. [Implementation Roadmap](#9-implementation-roadmap)
10. [UI Components](#10-ui-components)
11. [Risk Mitigation](#11-risk-mitigation)
12. [Success Metrics](#12-success-metrics)

---

## 1. Executive Summary

This document provides a complete reference for the WarehouseOps Lead Distribution System — covering the current as-is architecture, the proposed to-be architecture, the lead data model, distribution strategies, implementation roadmap, and operational guidelines.

The existing system handles basic equal-split and custom-split assignment initiated manually by supervisors. The proposed architecture introduces real-time routing, capacity-aware allocation, skill and region matching, performance-weighted distribution, and automated triggers — transforming lead distribution from a manual chore into an intelligent, self-managing engine.

| Metric | Current Baseline | Target |
|--------|-----------------|--------|
| Time-to-assign | Manual / variable | < 5 minutes |
| Agent utilization | Untracked | 70 – 90% |
| Supervisor effort | High (daily manual work) | -80% reduction |
| Conversion rate lift | — | +10% vs. round-robin |
| Queue backlog | N/A | < 50 leads at any time |

---

## 2. Lead Data Model (Source of Truth)

All leads originate from a single source of truth. The canonical lead record contains the following fields, derived from the live import format.

### 2.1 Lead Record Schema

| Field | Type | Example | Notes |
|-------|------|---------|-------|
| `customer_name` | string | Emelisa Bello Bautista | Full name of the customer |
| `phone_number` | string (E.164) | 09772053856 | Primary contact number |
| `full_address` | text | Pagsibol Village, Catmon, Santa Maria, Bulacan | Complete delivery address |
| `province` | string | BULACAN | Province code — uppercase |
| `city_municipality` | string | BULACAN-SANTA-MARIA | City or municipality slug |
| `barangay` | string | CATMON | Barangay — uppercase |
| `amount` | decimal | 199.00 | Order value in PHP |
| `product_name` | string | AVOCAFE 1 SET B1T2 | Product ordered |
| `lead_status` | enum | Delivered | Current disposition status |

> **⚠️ Import Note:** Phone numbers arrive as scientific notation (e.g., `9.772053856E9`) in the raw XLSX import. The import pipeline must normalize these to the 11-digit PH mobile format: prepend `0` and round to the nearest integer before persisting.

### 2.2 Upload Format (XLSX)

The sample upload file (`leads format.xlsx`) contains **no header row**. Data begins on the first sheet row. Each row maps to a single lead as follows:

| Excel Column | Index | Field | Type | Example | Notes |
|--------------|-------|-------|------|---------|-------|
| A | 0 | `customer_name` | string | `Emelisa Bello Bautista` | Full customer name |
| B | 1 | `phone_number` | number | `9772053856` | Stored as Excel number; must be normalized to 11-digit string |
| C | 2 | `full_address` | text | `Pagsibol Village, Catmon, Santa Maria, Bulacan` | Complete delivery address |
| D | 3 | `province` | string | `BULACAN` | Province — uppercase |
| E | 4 | `city_municipality` | string | `BULACAN-SANTA-MARIA` | City/municipality slug — uppercase, dash-separated |
| F | 5 | `barangay` | string | `CATMON` | Barangay — uppercase |
| G–K | 6–10 | *(unused)* | — | — | Empty columns; ignored during import |
| L | 11 | `amount` | number | `199` | Order value in PHP |
| M | 12 | `product_name` | string | `AVOCAFE 1 SET B1T2` | Product SKU / name |
| N | 13 | `lead_status` | string | `Delivered` | Current disposition (maps to `LeadStatus` enum) |

**Phone Number Normalization Pipeline:**
```
Raw Excel value:  9772053856  (stored as number)
Scientific form:  9.772053856E9  (Excel internal representation)
Normalized:       "09772053856"  (prepend "0", round to integer, cast to string)
```

**Import Validation Rules:**
1. `customer_name` — required, non-empty, trimmed
2. `phone_number` — required, must resolve to 11 digits after normalization; reject row if unresolvable
3. `province` / `city_municipality` / `barangay` — required, uppercase on save
4. `amount` — optional; default to `0.00` if blank
5. `product_name` — required; used for skill-match routing
6. `lead_status` — optional on import; default to `NEW` if blank. If provided, must map to a valid `LeadStatus` enum value.

### 2.3 Lead Status Lifecycle

A lead moves through the following statuses during its lifecycle:

| Status | Meaning | Triggers Next |
|--------|---------|--------------|
| `NEW` | Just imported; not yet assigned | Supervisor distributes / auto-distribute fires |
| `CALLING` | Assigned to an agent; cycle is open | Agent logs a disposition |
| `SALE` | Order confirmed by agent | QA review → APPROVED / REJECTED |
| `REORDER` | Repeat purchase confirmed | QA review |
| `DELIVERED` | Item delivered; cycle closed | Lead archived after 30 days |
| `RETURNED` | Item returned by customer | Recycled to pool after cooldown |
| `NO_ANSWER` | No contact established | Recycled after cooldown period |
| `CALLBACK` | Customer requested callback at specific time | Re-queued at scheduled time |
| `REJECT` | Lead is invalid or duplicate | Archived immediately |
| `CANCELLED` | Order cancelled pre-delivery | Recycled or archived |
| `ARCHIVED` | Terminal state; removed from active pool | — |

### 2.4 Pool Status

In addition to the lead's own status, the pool tracks assignment availability:

| Pool Status | Description |
|------------|-------------|
| `AVAILABLE` | Lead is ready to be assigned to an agent |
| `ASSIGNED` | Lead is actively assigned; cycle is open |
| `COOLDOWN` | Lead is in a waiting period after NO_ANSWER or RETURNED |
| `EXHAUSTED` | Lead has reached max cycle attempts; no further assignment |

### 2.5 Sales Status

| Sales Status | Description |
|-------------|-------------|
| `NEW` | Sale record just created; pending review |
| `QA_PENDING` | Submitted to quality assurance team |
| `APPROVED` | QA has approved the sale |
| `REJECTED` | QA has rejected the sale; may trigger lead recycle |

### 2.6 Product Catalog (Source of Truth)

The Lead Distribution System **does not own product data**. Product names, SKUs, categories, and attributes are maintained in a separate **Product Catalog** (source of truth — e.g., ERP, PIM, or dedicated product service).

**Integration contract:**

| Field | Source | Used By |
|-------|--------|---------|
| `product_id` / `sku` | Product Catalog | `Lead.product_name` stores the SKU; the Distribution Engine resolves it to a `product_id` via catalog lookup at import time |
| `category_id` | Product Catalog | Fallback for Skill Match when no exact product skill match exists |
| `product_name` | Product Catalog | Display-only; never used for routing logic |

**Rules:**
1. `Lead.product_name` at import stores the raw SKU string from the upload file.
2. During enrichment (Step 2 in §6.2), the system queries the Product Catalog to resolve `product_name` → `product_id` + `category_id`.
3. If the SKU is unknown, the lead is flagged as `product_unresolved` and routed to the supervisor queue for manual classification.
4. Agent `product_skills` and `category_skills` are arrays of **catalog IDs**, never raw strings. This ensures consistency when product names change.
5. The Product Catalog provides a read-only API (or cached sync) for:
   - SKU → ID resolution
   - Category hierarchy
   - Product attributes (for future filtering)

> **Why this matters:** Product names change. SKUs get reorganized. By referencing the Product Catalog as the single source of truth, agent skills remain stable even when the catalog is updated.

---

## 3. Current Architecture (As-Is)

### 3.1 Domain Model

The existing domain consists of five core entities:

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

┌─────────────┐     1:1     ┌──────────────┐
│    User     │◄───────────│ AgentProfile │
└─────────────┘             └──────────────┘
```

| Entity | Role | Key Relationships |
|--------|------|------------------|
| Lead | The central record representing a customer order opportunity | Belongs to one assigned User; has many LeadCycles |
| LeadCycle | One attempt to work a lead; tracks start/close times and outcomes | Belongs to Lead; belongs to assigned User; has many LeadLogs |
| LeadLog | Immutable event record for every action on a lead | Belongs to LeadCycle |
| User (Agent) | The sales agent who works the lead | Has one AgentProfile; has many LeadCycles |
| AgentProfile | Stores capacity, skills, and distribution preferences | Belongs to one User |

### 3.2 Status Dimensions

| Dimension | Enum | Values |
|-----------|------|--------|
| Lead Status | `LeadStatus` | NEW → CALLING → SALE / REORDER / DELIVERED / RETURNED / NO_ANSWER / REJECT / CALLBACK / CANCELLED / ARCHIVED |
| Pool Status | `PoolStatus` | AVAILABLE → ASSIGNED → COOLDOWN → EXHAUSTED |
| Sales Status | `SalesStatus` | NEW → QA_PENDING → APPROVED → REJECTED |
| Cycle Status | string | ACTIVE → CLOSED |

### 3.3 Existing Services

| Service | Responsibility |
|---------|---------------|
| `LeadDistributionService` | Equal-split and custom-split assignment; fetches available agents |
| `LeadPoolService` | Manages pool status transitions; filters available leads |
| `LeadRecyclingService` | Processes dispositions, cooldown expiry, callback expiry, and lead revival |
| `LeadAuditService` | Immutable audit log for all lead events |
| `LeadImportService` | Imports leads from CSV or XLSX files |

### 3.4 Current Distribution Flow

```
┌──────────────┐    ┌──────────────┐    ┌─────────────────┐    ┌──────────────┐
│ Lead Import  │───▶│  Lead Pool   │───▶│   Supervisor    │───▶│   Agents     │
│ / API / Form │    │ (AVAILABLE)  │    │ Selects agents  │    │ (ASSIGNED)   │
└──────────────┘    └──────────────┘    │ & Distributes   │    └──────────────┘
                                        └─────────────────┘
```

The flow is **linear and entirely manual**. Every assignment requires a supervisor to log in, select leads, select agents, and trigger distribution.

### 3.5 Known Problems

| Problem | Impact |
|---------|--------|
| Manual trigger only | Supervisor must initiate every distribution; leads sit unassigned during off-hours |
| No capacity guard | Agents can be overloaded beyond their `max_active_cycles` setting |
| No skill matching | Leads are randomly assigned regardless of agent expertise or product knowledge |
| No priority weighting | Top performers and new agents receive identical share sizes |
| No region affinity | Geographic mismatches result in longer call resolution times |
| No real-time notifications | Agents must manually refresh the system to discover new assignments |

---

## 4. Proposed Architecture (To-Be)

### 4.1 Design Principles

| Principle | Description |
|-----------|-------------|
| **Fairness** | Equal opportunity baseline with performance-weighted rewards on top |
| **Capacity Guard** | Never exceed an agent's `max_active_cycles` or `max_daily_leads` limits |
| **Skill Match** | Route product-specific leads to agents with matching expertise |
| **Proximity** | Prefer agents with regional knowledge for geographic leads |
| **Transparency** | Every assignment decision is recorded in the audit log with a reason |
| **Autonomy** | Supervisors can override any automated decision with tracked justification |

### 4.2 Distribution Engine Architecture

```
┌─────────────────────────────────────────────────────────────────────────────┐
│                         LEAD DISTRIBUTION ENGINE                            │
├─────────────────────────────────────────────────────────────────────────────┤
│                                                                             │
│  ┌─────────────────┐   ┌──────────────────┐   ┌─────────────────────────┐  │
│  │  Rule Engine    │   │  Scoring System  │   │  Capacity Manager       │  │
│  │                 │   │                  │   │                         │  │
│  │  • Round-robin  │   │  • Performance   │   │  • max_active_cycles    │  │
│  │  • Weighted     │   │  • Conversion    │   │  • concurrent_lead_cap  │  │
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
└─────────────────────────────────────────────────────────────────────────────┘
```

### 4.3 New Domain Components

Six new or extended components form the Distribution Engine:

| Component | Type | Purpose |
|-----------|------|---------|
| `DistributionRule` | Model | Defines a routing strategy with filters, weights, and priority order |
| `DistributionQueue` | Model | Persists pending assignments; enables retry with exponential backoff |
| `AgentWorkload` | Materialized view / cache | Real-time snapshot of active leads, daily counts, and availability |
| `DistributionEngine` | Service | Orchestrates scoring, filtering, and assignment decisions |
| `AgentWorkloadCache` | Helper | Keeps workload snapshot fresh via event-driven invalidation |
| `AutoDistributeLeads` | Background job | Processes the distribution queue every 60 seconds via cron |

### 4.4 AgentProfile Extensions

The existing `AgentProfile` model gains these fields to support intelligent distribution:

| Field | Type | Description |
|-------|------|-------------|
| `distribution_weight` | decimal (0.5–2.0) | Multiplier applied to agent's base score; default 1.0 |
| `auto_assign_enabled` | boolean | Opt-in flag; only auto-distributes to agents who have enabled this |
| `shift_start` / `shift_end` | time | Working hours window; leads are not auto-assigned outside this range |
| `max_daily_leads` | integer | Hard daily cap on total leads assigned; blocks further assignment when reached |
| `concurrent_lead_cap` | integer | Per-agent override for `max_active_cycles` |
| `preferred_lead_sources` | array | e.g. `['facebook', 'organic']` — bias routing toward preferred sources |
| `excluded_regions` | array | Regions the agent has opted out of (e.g. due to language barriers) |
| `category_skills` | array | Array of **category IDs** from the Product Catalog; used as fallback when no exact product skill match exists |
| `product_skills` | array | Array of **product IDs/SKUs** from the Product Catalog; used by Skill Match strategy. Not product names — always references the source of truth. |

```php
// AgentProfile model additions
class AgentProfile extends Model
{
    protected $fillable = [
        // ... existing fields ...
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
```

---

## 5. Distribution Strategies

### 5.1 Strategy Overview

| Strategy | Description | Best For |
|----------|-------------|----------|
| **Round Robin** | Circular queue across all eligible agents regardless of performance | Default fallback; fair rotation when no other signals are available |
| **Weighted** | Score = performance × availability × priority multiplier | Rewarding top performers; incentivizing consistent agents |
| **Skill Match** | Routes `lead.product_id` (resolved via Product Catalog) to agents whose `product_skills` array contains that product ID/SKU | Product-specialized teams; high-value SKUs needing expert handling |
| **Territory** | Routes `lead.city/region` to agents whose `regions` array contains that area | COD-heavy provinces; agents with local dialect advantage |
| **Hybrid** *(Recommended)* | Weighted combination of performance, availability, skill, region, load, and recency | Default production strategy; balances all signals simultaneously |
| **Supervisor Override** | Manual assignment with a required reason field, fully audited | VIP leads, escalations, special customer handling |

### 5.2 Hybrid Scoring Formula

The Hybrid strategy computes a score for each eligible agent per lead. The agent with the highest score receives the assignment:

```
Score(agent, lead) =
    w_perf  × normalize(performance_score)
  + w_avail × availability_factor(agent)
  + w_skill × skill_match(agent.skills, lead.product)
  + w_reg   × region_match(agent.regions, lead.city)
  + w_load  × (1 - load_factor(agent))
  + w_time  × time_since_last_assignment(agent)

where:
    availability_factor = 0   if agent is off-shift or on break
                        = 0.5 if agent.max_cycles_reached
                        = 1.0 otherwise

    load_factor         = active_leads / max_active_cycles
                          (result between 0 and 1)

    skill_match         = 1.0 if lead.product_id (from Product Catalog) ∈ agent.product_skills
                        = 0.5 if lead.category_id ∈ agent.category_skills
                        = 0.0 no match

    region_match        = 1.0 if lead.province or lead.city ∈ agent.regions
                        = 0.0 otherwise

    time_since_last     = hours since last assignment, capped at 24h
```

### 5.3 Default Weight Configuration

Weights are configurable per `DistributionRule`. Recommended starting defaults:

| Weight Key | Default | Rationale |
|-----------|---------|-----------|
| `w_perf` — Performance | 0.30 | Rewards agents with strong conversion history |
| `w_avail` — Availability | 0.25 | Prevents assignment to unavailable or overloaded agents |
| `w_skill` — Skill Match | 0.20 | Prioritizes product expertise |
| `w_reg` — Region Match | 0.15 | Leverages local knowledge |
| `w_load` — Load Balance | 0.05 | Gentle load-balancing nudge |
| `w_time` — Recency | 0.05 | Ensures no agent goes too long without a lead |

> All weights must sum to **1.0**. Adjust per rule as team performance data accumulates.

---

## 6. Proposed System Flow

### 6.1 End-to-End Lead Lifecycle

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
                          │ (Priority order) │
                          └────────┬─────────┘
                                   │
                          ┌────────▼─────────┐
                          │ Filter Eligible  │
                          │     Agents       │
                          │ (capacity/skill) │
                          └────────┬─────────┘
                                   │
                          ┌────────▼─────────┐
                          │  Score & Rank    │
                          │     Agents       │
                          └────────┬─────────┘
                                   │
              ┌────────────────────┼────────────────────┐
              │                    │                    │
     ┌────────▼────────┐   ┌──────▼──────┐   ┌────────▼────────┐
     │  Auto-Assign    │   │    Queue    │   │   Supervisor    │
     │  (Top scorer)   │   │   (FIFO)   │   │   Override      │
     └────────┬────────┘   └──────┬──────┘   └────────┬────────┘
              │                   │                    │
              └───────────────────┼────────────────────┘
                                  │
                          ┌───────▼────────┐
                          │ Create Cycle   │
                          │  + Audit Log   │
                          └───────┬────────┘
                                  │
              ┌───────────────────┼───────────────────┐
              │                   │                   │
     ┌────────▼────────┐  ┌──────▼──────┐  ┌────────▼────────┐
     │ WebSocket Push  │  │  In-App     │  │  AgentWorkload  │
     │   to Agent      │  │   Badge     │  │    Updated      │
     └─────────────────┘  └─────────────┘  └─────────────────┘
```

### 6.2 Step-by-Step Breakdown

| Step | Action | Actor / System |
|------|--------|---------------|
| 1 | Lead enters via Import (XLSX/CSV), API, or web form | `LeadImportService` / API |
| 2 | Quality score computed; lead enriched with product category tag | Enrichment hook on `LeadCreated` event |
| 3 | `DistributionEngine` evaluates active rules in priority order | `DistributionEngine` |
| 4 | Eligible agents filtered by: capacity, shift, `auto_assign_enabled`, `excluded_regions` | `CapacityManager` + `AgentAvailability` helper |
| 5 | Remaining agents scored via Hybrid formula; ranked list produced | `DistributionEngine.scoreAgents()` |
| 6a | Auto-assign: top-scoring eligible agent receives the lead immediately | `AutoDistributeLeads` job |
| 6b | Queue: lead added to `DistributionQueue` with `status = pending` (if no eligible agent now) | `DistributionQueue` |
| 6c | Supervisor override: manual assignment with required reason field | Supervisor via UI |
| 7 | `LeadCycle` created; `AgentWorkload` cache updated; audit log written | `LeadDistributionService` |
| 8 | WebSocket push sent to assigned agent; in-app badge incremented | `NotificationService` |
| 9 | Agent works lead; logs disposition; cycle closes with outcome | Agent via UI |
| 10 | `LeadRecyclingService` processes outcome: cooldown, callback queue, or archive | `LeadRecyclingService` |

### 6.3 Queue Retry Schedule

When no eligible agent is available at assignment time, the lead enters the `DistributionQueue` and is retried on the following schedule:

| Attempt | Delay | Action on Failure |
|---------|-------|------------------|
| 1st retry | 60 seconds (next cron tick) | Re-score all agents; assign if eligible agent found |
| 2nd retry | 5 minutes | Widen eligibility criteria (relax region matching) |
| 3rd retry | 30 minutes | Alert supervisor via in-app notification |
| 4th retry | 2 hours | Escalate to supervisor queue for manual assignment |
| Final | 24 hours | Mark as `failed`; supervisor must manually review |

---

## 7. New Data Model Additions

### 7.1 DistributionRule

```php
class DistributionRule extends Model
{
    protected $fillable = [
        'name',
        'strategy',           // 'round_robin' | 'weighted' | 'skill_match' | 'territory' | 'hybrid'
        'priority',           // int — rule precedence; lower = evaluated first
        'filters',            // json — { product_skills: [], regions: [], sources: [] }
        'weight_formula',     // json — { w_perf: 0.30, w_avail: 0.25, ... }
        'is_active',          // bool — soft toggle
        'supervisor_id',      // nullable FK — who created it
    ];
}
```

| Column | Type | Description |
|--------|------|-------------|
| `id` | bigint PK | Auto-increment primary key |
| `name` | string | Human-readable rule name |
| `strategy` | enum | `round_robin` \| `weighted` \| `skill_match` \| `territory` \| `hybrid` |
| `priority` | integer | Lower number = higher precedence (evaluated first) |
| `filters` | json | `{ product_skills: [], regions: [], sources: [] }` |
| `weight_formula` | json | `{ w_perf: 0.30, w_avail: 0.25, w_skill: 0.20, w_reg: 0.15, w_load: 0.05, w_time: 0.05 }` |
| `is_active` | boolean | Soft toggle; inactive rules are ignored |
| `supervisor_id` | bigint FK nullable | User who created the rule |

### 7.2 DistributionQueue

```php
class DistributionQueue extends Model
{
    protected $fillable = [
        'lead_id',
        'rule_id',
        'status',               // 'pending' | 'assigned' | 'failed' | 'cancelled'
        'assigned_agent_id',
        'score_snapshot',       // json — agent scores at assignment time
        'attempt_count',
        'processed_at',
    ];
}
```

| Column | Type | Description |
|--------|------|-------------|
| `id` | bigint PK | Auto-increment |
| `lead_id` | bigint FK | The lead awaiting assignment |
| `rule_id` | bigint FK nullable | Rule used to generate this queue entry |
| `status` | enum | `pending` \| `assigned` \| `failed` \| `cancelled` |
| `assigned_agent_id` | bigint FK nullable | Populated once assignment is made |
| `score_snapshot` | json | Agent scores at the moment of assignment for auditability |
| `attempt_count` | integer | Number of retry attempts so far |
| `processed_at` | timestamp nullable | When the assignment was completed |

### 7.3 AgentWorkload (Cache Table / Materialized View)

```php
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

| Column | Type | Description |
|--------|------|-------------|
| `agent_id` | bigint FK PK | One row per agent; upserted on every assignment event |
| `active_leads_count` | integer | Current open cycles; compared against `max_active_cycles` |
| `today_assigned_count` | integer | Total assigned today; compared against `max_daily_leads` |
| `today_converted_count` | integer | Conversions today; feeds performance score |
| `last_assigned_at` | timestamp | Used by `w_time` recency factor |
| `next_available_at` | timestamp nullable | Earliest time agent is eligible (shift start or break end) |

### 7.4 Key Files — Current vs. New

| Current | Status | New / Change |
|---------|--------|-------------|
| `LeadDistributionService.php` | Extend | Add `distributeAuto()`, `scoreAgents()`, `applyRule()` |
| `LeadPoolService.php` | Minor update | Add `enqueue()`, `dequeue()` |
| `AgentProfile.php` | Extend | Add all distribution fields listed in §4.4 |
| `Lead.php` | Minor update | Add `quality_score` default; scope `unassigned()` |
| — | **New** | `DistributionRule.php` model |
| — | **New** | `DistributionQueue.php` model |
| — | **New** | `DistributionEngine.php` service |
| — | **New** | `AgentWorkloadCache.php` helper |
| — | **New** | `AutoDistributeLeads.php` job |
| — | **New** | `LeadAssigned.php` event + listener |
| `resources/js/pages/Leads/` | Extend | Add distribution UI tab |
| `resources/js/pages/Agents/Index.tsx` | Extend | Add capacity indicator |

---

## 8. API Surface

### 8.1 Distribution Endpoints

| Method | Endpoint | Description | Auth |
|--------|----------|-------------|------|
| POST | `/api/leads/distribute` | Trigger auto-distribution batch | supervisor |
| POST | `/api/leads/{lead}/assign` | Manual assignment with required reason field | supervisor |
| POST | `/api/leads/{lead}/reassign` | Reassign to a different agent with reason | supervisor |
| GET | `/api/distribution/rules` | List all active distribution rules | supervisor |
| POST | `/api/distribution/rules` | Create a new distribution rule | supervisor |
| PUT | `/api/distribution/rules/{rule}` | Update an existing rule | supervisor |
| GET | `/api/agents/{agent}/workload` | Current load stats for a specific agent | supervisor |
| GET | `/api/distribution/queue` | List pending, processing, and failed queue entries | supervisor |
| POST | `/api/distribution/queue/process` | Manually trigger queue processing | supervisor |

### 8.2 WebSocket Events

| Event | Direction | Payload |
|-------|-----------|---------|
| `lead.assigned` | Server → Agent | `{ lead_id, customer_name, product, province, city, priority }` |
| `agent.capacity.warning` | Server → Agent | `{ active_count, max_count, remaining }` |
| `queue.updated` | Server → Supervisor | `{ pending_count, processing_count, failed_count }` |
| `lead.recycled` | Server → Supervisor | `{ lead_id, reason, next_available_at }` |

> **🔒 Security Note:** No PII (customer name, phone, or address) is transmitted over WebSocket. All real-time payloads use only `lead_id` plus metadata. Agents retrieve full lead details via authenticated REST after receiving the push notification.

---

## 9. Implementation Roadmap

### Phase 1 — Foundation (Week 1)

| Task | Type | Owner |
|------|------|-------|
| Add `AgentProfile` fields: `distribution_weight`, `auto_assign_enabled`, `shift_start`, `shift_end`, `max_daily_leads`, `concurrent_lead_cap`, `preferred_lead_sources`, `excluded_regions` | Migration + Model | Backend |
| Create `DistributionRule` migration, model, and strategy enum | Migration + Model | Backend |
| Create `DistributionQueue` migration and model | Migration + Model | Backend |
| Create `AgentWorkload` migration (cache table or materialized view) | Migration | Backend |
| Add `Lead.quality_score` field with scoring hook on import | Model + Hook | Backend |
| Phone number normalization in `LeadImportService` (scientific notation → PH mobile format) | Service Update | Backend |

### Phase 2 — Core Engine (Week 2)

| Task | Type | Owner |
|------|------|-------|
| Build `DistributionEngine` service class with `scoreAgents()`, `filterEligible()`, `applyRule()` | Service | Backend |
| Implement `RoundRobin`, `Weighted`, `SkillMatch`, `Territory`, and `Hybrid` strategy classes | Strategy Classes | Backend |
| Implement `CapacityManager` — enforce `max_active_cycles` and `max_daily_leads` | Helper | Backend |
| Build `AgentAvailability` helper — shift check, break status, excluded regions | Helper | Backend |
| Supervisor UI: `DistributionRule` CRUD with visual weight formula editor | Frontend | Frontend |
| Supervisor UI: Manual assignment override modal with reason field | Frontend | Frontend |

### Phase 3 — Automation (Week 3)

| Task | Type | Owner |
|------|------|-------|
| Cron job: `AutoDistributeLeads` processes the queue every 60 seconds | Job | Backend |
| Event listener: trigger distribution on `LeadCreated` event | Event | Backend |
| Queue worker: process distribution jobs in background (exponential backoff on retry) | Worker | Backend |
| WebSocket integration: push `lead.assigned` and `capacity.warning` events to agents | Realtime | Backend + Frontend |
| In-app badge: increment unread count on agent dashboard on new assignment | Frontend | Frontend |

### Phase 4 — Monitoring & Tuning (Week 4)

| Task | Type | Owner |
|------|------|-------|
| Distribution analytics dashboard: time-to-assign, utilization, queue depth | Frontend | Frontend |
| Agent performance correlation report: link conversion rates to distribution weights | Report | Backend + Frontend |
| A/B testing framework: compare Hybrid vs. Round Robin on conversion rate | Backend | Backend |
| Alerting: notify supervisor when agent approaches capacity or queue backlog > 50 | Alerts | Backend |
| Weekly rebalancing report: flag agents with skewed distribution weight vs. results | Report | Backend |

---

## 10. UI Components

| Component | Purpose | Used By |
|-----------|---------|---------|
| `LeadDistributionPanel` | Supervisor view: rules list, manual assign button, queue status summary | Supervisor |
| `AgentCapacityIndicator` | Progress bar on each agent card showing active leads vs. max | Supervisor, Agent |
| `DistributionRuleForm` | Create / edit rules with visual weight sliders and filter builder | Supervisor |
| `LeadQueueMonitor` | Real-time dashboard: pending, processing, and failed queue counts | Supervisor |
| `AssignmentHistory` | Audit trail showing who received what lead, when, and why | Supervisor |
| `AgentPerformanceCard` | Conversion rate, response time, and quality score that feed distribution weight | Supervisor |

---

## 11. Risk Mitigation

| Risk | Likelihood | Impact | Mitigation |
|------|-----------|--------|-----------|
| Agent overload | Medium | High | Hard cap via `max_active_cycles` and `max_daily_leads`; `CapacityManager` blocks over-assignment at the service layer |
| Uneven distribution bias | Low | Medium | Weekly rebalancing report; automatic weight review recommended after 30 days of data |
| System downtime during distribution | Low | High | Queue persists to DB; jobs retry with exponential backoff; no leads are lost on service restart |
| Supervisor resistance to automation | Medium | Medium | Gradual rollout: auto-distribute queue-only first, not live leads; supervisor override always available |
| PII exposure via WebSocket | Low | High | No PII in real-time payload; only `lead_id` and metadata transmitted over WebSocket |
| Phone normalization failure at import | Medium | High | Validation step in `LeadImportService`; reject rows with unresolvable numbers and surface error to uploader |

---

## 12. Success Metrics

| Metric | Target | How Measured |
|--------|--------|-------------|
| Average time-to-assign | < 5 minutes from lead creation | Timestamp diff: `Lead.created_at` → `LeadCycle.created_at` |
| Agent utilization | 70–90% | `active_leads_count ÷ max_active_cycles` per agent |
| Conversion rate lift (Hybrid vs. Round Robin) | +10% | A/B test: compare SALE dispositions across strategy cohorts |
| Supervisor distribution time saved | -80% vs. baseline | Measure weekly hours spent on manual distribution tasks |
| Queue backlog at any time | < 50 leads | Monitor `DistributionQueue WHERE status = 'pending'` |
| Failed assignment rate | < 2% | `DistributionQueue WHERE status = 'failed'` ÷ total leads imported |

---

*Document Version 1.0 · Last Updated: June 7, 2026 · WarehouseOps*
*Confidential — Internal Use Only*
