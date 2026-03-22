# Lead Pool Management System - Design Specification

**Date:** 2026-03-22
**Status:** Approved
**Author:** Claude (AI Assistant)
**Stakeholder:** IT Admin

---

## 1. Overview

### 1.1 Problem Statement

The current lead distribution system uses spreadsheets, which presents several critical issues:

- **Security vulnerability:** Spreadsheets are easily copied, shared, and leaked
- **Fraud risk:** Agents can steal customer phone numbers for personal use
- **No audit trail:** Impossible to track who accessed what data and when
- **Manual overhead:** Supervisors manually manage distribution via spreadsheets
- **No verification:** Cannot verify if agents actually made calls

### 1.2 Solution

Build a Lead Pool Management System integrated into WarehouseOps that:

- Automatically creates leads from delivered waybills
- Enables supervisor-controlled distribution to 75 agents
- Implements click-to-call (phone numbers never visible to agents)
- Provides full audit trail and fraud detection
- Automates lead recycling based on configurable outcome rules

### 1.3 Success Criteria

- Zero phone number visibility to agents (click-to-call only)
- 100% audit coverage of all lead interactions
- Automated recycling reduces manual supervisor work by 80%
- Fraud detection flags catch suspicious patterns within 30 minutes

---

## 2. Architecture

### 2.1 System Components

```
┌─────────────────────────────────────────────────────────────────┐
│                      LEAD POOL MANAGEMENT                       │
├─────────────────────────────────────────────────────────────────┤
│                                                                 │
│  ┌──────────────┐    ┌──────────────┐    ┌──────────────┐      │
│  │   SOURCES    │    │  LEAD POOL   │    │   AGENTS     │      │
│  ├──────────────┤    ├──────────────┤    ├──────────────┤      │
│  │ • Delivered  │───▶│ • Available  │───▶│ • My Leads   │      │
│  │   Waybills   │    │ • Cooldown   │    │ • Click-Call │      │
│  │ • CSV Import │    │ • Exhausted  │    │ • Outcomes   │      │
│  └──────────────┘    └──────────────┘    └──────────────┘      │
│         │                   │                   │               │
│         ▼                   ▼                   ▼               │
│  ┌─────────────────────────────────────────────────────┐       │
│  │              SUPERVISOR DASHBOARD                    │       │
│  ├─────────────────────────────────────────────────────┤       │
│  │ • Distribute leads    • Monitor agents              │       │
│  │ • Set recycling rules • View audit trail            │       │
│  │ • Override outcomes   • Real-time stats             │       │
│  └─────────────────────────────────────────────────────┘       │
│                                                                 │
│  ┌─────────────────────────────────────────────────────┐       │
│  │              RECYCLING ENGINE (Background)           │       │
│  │ • Auto-move leads from cooldown → available         │       │
│  │ • Apply outcome-based rules                         │       │
│  │ • Mark exhausted leads                              │       │
│  └─────────────────────────────────────────────────────┘       │
└─────────────────────────────────────────────────────────────────┘
```

### 2.2 User Roles

| Role | Permissions |
|------|-------------|
| **Agent** | View assigned leads, initiate calls, set outcomes, add remarks |
| **Supervisor** | All agent permissions + distribute leads, view all agents, override outcomes, view audit trail |
| **Admin** | All supervisor permissions + configure recycling rules, manage agents, export data |

---

## 3. Lead Sources

### 3.1 Automatic: Delivered Waybills

When a waybill status changes to `DELIVERED`:

1. System checks if customer exists (by phone number)
2. If not, creates Customer record
3. Checks if lead exists for this customer
4. If not, creates Lead in `AVAILABLE` pool status
5. Updates lead with latest product info from waybill

### 3.2 Manual: CSV Import

Supervisors can upload CSV files with lead data:

- Required fields: `name`, `phone`
- Optional fields: `city`, `region`, `product_interest`, `source`
- System deduplicates by phone number
- Duplicate phones update existing lead data
- All imports logged with supervisor ID and timestamp

---

## 4. Lead Pool States

### 4.1 State Machine

```
┌───────────┐     Supervisor      ┌───────────┐     Agent works    ┌───────────┐
│ AVAILABLE │────distributes────▶│  ASSIGNED │────────────────────▶│  CLOSED   │
└───────────┘                     └───────────┘                     └───────────┘
      ▲                                 │                                 │
      │                                 │ No outcome                      │
      │         ┌───────────┐           │ after X days                    │
      └─────────│  COOLDOWN │◀──────────┴─────────────────────────────────┘
   cooldown     └───────────┘        (based on outcome rules)
   expires
```

### 4.2 State Definitions

| State | Description |
|-------|-------------|
| `AVAILABLE` | In pool, ready for distribution |
| `ASSIGNED` | Assigned to an agent, actively being worked |
| `COOLDOWN` | Waiting period before re-entering pool |
| `EXHAUSTED` | Max cycles reached, no longer distributable |

---

## 5. Distribution System

### 5.1 Distribution Flow

1. Supervisor accesses Lead Distribution interface
2. Filters available leads by: source, region, product
3. Selects agents to receive leads
4. Chooses distribution method:
   - **Equal split:** System divides leads evenly
   - **Custom:** Supervisor specifies count per agent
5. Confirms distribution
6. System assigns leads and logs action

### 5.2 Distribution Rules

- Leads only distributed to agents with `is_available = true`
- System respects `max_active_cycles` per agent
- Previously assigned agents excluded from redistribution of same lead
- Distribution creates new `lead_cycle` record

---

## 6. Agent Interface

### 6.1 My Leads View

Agents see their assigned leads with:

- Customer name
- City/region
- Previous product purchased
- Previous order amount
- Delivery date
- Call history
- Callback schedules
- Their remarks/notes

**NOT visible to agents:**
- Phone number
- Full address
- Other agents' leads

### 6.2 Click-to-Call

1. Agent clicks "CALL" button
2. System logs: `agent_id`, `lead_id`, `timestamp`, `action=CALL_INITIATED`
3. API returns `sip:` protocol link
4. Browser opens MicroSIP with the number
5. Agent never sees the actual phone number

### 6.3 Outcome Selection

After each call, agent must select outcome:

| Outcome | Description | Requires |
|---------|-------------|----------|
| `NO_ANSWER` | Could not reach customer | - |
| `CALLBACK` | Customer requested callback | Date + Time |
| `INTERESTED` | Warm lead, follow-up needed | - |
| `ORDERED` | Successful sale | Link to create waybill |
| `NOT_INTERESTED` | Customer declined | - |
| `WRONG_NUMBER` | Invalid contact | - |

All outcomes allow optional remarks field.

---

## 7. Recycling Engine

### 7.1 Outcome-Based Rules

Default configuration (admin-adjustable):

| Outcome | Cooldown | Max Cycles | Then Action |
|---------|----------|------------|-------------|
| `NO_ANSWER` | 24 hours | 5 | Mark exhausted |
| `CALLBACK` | As scheduled | Unlimited | Agent keeps lead |
| `INTERESTED` | 48 hours | 3 | Recycle to different agent |
| `NOT_INTERESTED` | 30 days | 2 | Mark exhausted |
| `WRONG_NUMBER` | — | 1 | Mark exhausted immediately |
| `ORDERED` | 60 days | Unlimited | Recycle for upsell |

### 7.2 Background Processing

Scheduled job runs every 15 minutes:

1. Query leads where `pool_status = COOLDOWN` AND `available_at <= NOW()`
2. For each lead:
   - Check cycle count against max_cycles rule
   - If under max: move to `AVAILABLE`
   - If at max: move to `EXHAUSTED`
3. Log all state transitions

### 7.3 Supervisor Overrides

Supervisors can:
- Manually recycle any lead (bypass cooldown)
- Mark any lead as exhausted
- Revive exhausted leads back to pool
- Change outcome on any lead

All overrides logged with supervisor ID and reason.

---

## 8. Security

### 8.1 Phone Number Protection

| Layer | Protection |
|-------|------------|
| Database | Phone stored normally (required for calling) |
| API | Phone excluded from all agent-facing responses |
| Frontend | Never rendered in HTML |
| Click-to-call | `sip:` link generated server-side only |
| Exports | Agents cannot export any data |

### 8.2 Audit Trail

Every action logged:

```
lead_audit_log:
├─ LEAD_CREATED      | source, waybill_id
├─ DISTRIBUTED       | supervisor_id, agent_id
├─ CALL_INITIATED    | agent_id, timestamp
├─ CALL_ENDED        | agent_id, estimated_duration
├─ OUTCOME_SET       | agent_id, outcome, remarks
├─ MOVED_TO_COOLDOWN | cooldown_hours
├─ COOLDOWN_EXPIRED  | new_status
├─ SUPERVISOR_OVERRIDE | supervisor_id, action, reason
└─ ...
```

### 8.3 Fraud Detection

Automatic flags triggered by:

| Pattern | Flag Type | Action |
|---------|-----------|--------|
| 10+ "No Answer" in < 30 min | `SUSPICIOUS_VELOCITY` | Alert supervisor |
| Outcomes without call initiation | `NO_CALL_INITIATED` | Alert supervisor |
| Same outcome changed multiple times | `OUTCOME_TAMPERING` | Alert supervisor |
| Lead viewed but never called (24hr) | `LEAD_HOARDING` | Alert supervisor |

---

## 9. Supervisor Dashboard

### 9.1 Overview Metrics

- Available leads count
- Assigned leads count (active)
- Cooldown leads count
- Sales today/this week/this month
- Conversion rate

### 9.2 Agent Performance Table

| Column | Description |
|--------|-------------|
| Agent Name | Agent identifier |
| Active Leads | Currently assigned count |
| Called Today | Calls initiated today |
| Sold Today | Orders created today |
| No Answer | No answer outcomes today |
| Conversion % | Sales / Calls ratio |
| Status | Online/Idle/Offline |

### 9.3 Real-Time Activity Feed

Live stream of:
- Calls initiated
- Outcomes recorded
- Sales completed
- Fraud flags raised

### 9.4 Quick Actions

- Distribute leads (modal)
- Import leads (CSV upload)
- Review fraud flags
- Override lead outcomes

---

## 10. Database Schema

### 10.1 Table Modifications

```sql
-- Add to leads table
ALTER TABLE leads ADD COLUMN pool_status
    ENUM('AVAILABLE','ASSIGNED','COOLDOWN','EXHAUSTED')
    DEFAULT 'AVAILABLE';
ALTER TABLE leads ADD COLUMN cooldown_until TIMESTAMP NULL;

-- Add to lead_cycles table
ALTER TABLE lead_cycles ADD COLUMN call_initiated_at TIMESTAMP NULL;
ALTER TABLE lead_cycles ADD COLUMN call_count INT DEFAULT 0;
```

### 10.2 New Tables

```sql
-- Recycling rules (configurable by admin)
CREATE TABLE recycling_rules (
    id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    outcome VARCHAR(50) NOT NULL,
    cooldown_hours INT NOT NULL DEFAULT 24,
    max_cycles INT NOT NULL DEFAULT 3,
    next_action ENUM('RECYCLE','EXHAUST') NOT NULL DEFAULT 'RECYCLE',
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY (outcome)
);

-- Fraud flags
CREATE TABLE fraud_flags (
    id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    agent_id BIGINT UNSIGNED NOT NULL,
    lead_id BIGINT UNSIGNED NULL,
    flag_type VARCHAR(50) NOT NULL,
    severity ENUM('WARNING','CRITICAL') DEFAULT 'WARNING',
    details JSON,
    is_reviewed BOOLEAN DEFAULT FALSE,
    reviewed_by BIGINT UNSIGNED NULL,
    reviewed_at TIMESTAMP NULL,
    resolution_notes TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (agent_id) REFERENCES users(id),
    FOREIGN KEY (lead_id) REFERENCES leads(id),
    FOREIGN KEY (reviewed_by) REFERENCES users(id),
    INDEX (agent_id, is_reviewed),
    INDEX (flag_type, is_reviewed)
);

-- Lead pool audit log (append-only)
CREATE TABLE lead_pool_audit (
    id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    lead_id BIGINT UNSIGNED NOT NULL,
    lead_cycle_id BIGINT UNSIGNED NULL,
    user_id BIGINT UNSIGNED NULL,
    action VARCHAR(50) NOT NULL,
    old_value VARCHAR(255) NULL,
    new_value VARCHAR(255) NULL,
    metadata JSON NULL,
    ip_address VARCHAR(45) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (lead_id) REFERENCES leads(id),
    FOREIGN KEY (lead_cycle_id) REFERENCES lead_cycles(id),
    FOREIGN KEY (user_id) REFERENCES users(id),
    INDEX (lead_id, created_at),
    INDEX (user_id, created_at),
    INDEX (action, created_at)
);
```

---

## 11. API Endpoints

### 11.1 Agent Endpoints

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/api/leads/my-leads` | Get assigned leads (no phone) |
| GET | `/api/leads/{id}` | Get single lead detail |
| POST | `/api/leads/{id}/call` | Initiate call, returns sip: link |
| POST | `/api/leads/{id}/outcome` | Record call outcome |
| GET | `/api/leads/callbacks` | Get scheduled callbacks |

### 11.2 Supervisor Endpoints

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/api/leads/pool` | List available leads with filters |
| POST | `/api/leads/distribute` | Distribute leads to agents |
| POST | `/api/leads/import` | Import leads from CSV |
| GET | `/api/leads/{id}/audit` | Get lead audit trail |
| POST | `/api/leads/{id}/override` | Override outcome or status |
| GET | `/api/agents/performance` | Get agent performance stats |
| GET | `/api/dashboard/supervisor` | Dashboard metrics |
| GET | `/api/dashboard/activity` | Real-time activity feed |
| GET | `/api/fraud-flags` | List fraud flags |
| POST | `/api/fraud-flags/{id}/review` | Mark flag as reviewed |

### 11.3 Admin Endpoints

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/api/recycling-rules` | List recycling rules |
| PUT | `/api/recycling-rules/{id}` | Update recycling rule |
| GET | `/api/leads/export` | Export leads (admin only) |

---

## 12. Backend Services

| Service | Responsibility |
|---------|----------------|
| `LeadPoolService` | Manage pool states, query available leads |
| `LeadDistributionService` | Handle distribution logic, create cycles |
| `LeadRecyclingService` | Apply outcome rules, move between states |
| `CallTrackingService` | Log call attempts, generate sip: links |
| `FraudDetectionService` | Monitor patterns, create flags |
| `LeadAuditService` | Log all actions to audit table |
| `LeadImportService` | Parse CSV, deduplicate, create leads |

---

## 13. Frontend Components

### 13.1 Agent Views

- `AgentLeadList` - List of assigned leads
- `LeadCard` - Individual lead display
- `CallButton` - Click-to-call trigger
- `OutcomeModal` - Outcome selection form
- `CallbackScheduler` - Date/time picker for callbacks

### 13.2 Supervisor Views

- `SupervisorDashboard` - Main dashboard
- `LeadPoolTable` - Available leads with filters
- `DistributionModal` - Lead distribution form
- `AgentPerformanceTable` - Agent stats grid
- `ActivityFeed` - Real-time activity stream
- `FraudFlagsList` - Pending flags for review
- `LeadAuditTimeline` - Audit trail viewer

### 13.3 Admin Views

- `RecyclingRulesEditor` - Configure outcome rules
- `LeadExportTool` - Export functionality

---

## 14. Integration Points

### 14.1 MicroSIP Integration

Click-to-call uses `sip:` protocol:

```javascript
// Frontend: Agent clicks call
async function initiateCall(leadId) {
  const response = await fetch(`/api/leads/${leadId}/call`, { method: 'POST' });
  const { sipLink } = await response.json();
  window.location.href = sipLink; // Opens MicroSIP
}
```

```php
// Backend: Generate sip link
public function initiateCall(Lead $lead): string
{
    $this->auditService->log($lead, 'CALL_INITIATED');
    return 'sip:' . $lead->phone;
}
```

### 14.2 Waybill Integration

When waybill delivered, trigger lead creation:

```php
// In WaybillStatusObserver or event listener
public function onDelivered(Waybill $waybill): void
{
    $this->leadPoolService->createFromWaybill($waybill);
}
```

### 14.3 Order Creation

When agent marks `ORDERED`, link to waybill creation:

```php
// Outcome sets lead.customer_id, redirects to waybill form
public function recordSale(Lead $lead, array $data): void
{
    $this->auditService->log($lead, 'OUTCOME_SET', ['outcome' => 'ORDERED']);
    // Frontend redirects to: /waybills/create?lead_id={$lead->id}
}
```

---

## 15. Background Jobs

| Job | Schedule | Purpose |
|-----|----------|---------|
| `ProcessCooldownLeads` | Every 15 min | Move expired cooldowns to available |
| `DetectFraudPatterns` | Every 30 min | Analyze agent behavior, create flags |
| `ExpireUnworkedLeads` | Daily at midnight | Handle leads assigned but never called |
| `GenerateDailyReport` | Daily at 6 AM | Email supervisor with daily stats |

---

## 16. Testing Strategy

### 16.1 Unit Tests

- `LeadPoolServiceTest` - Pool state transitions
- `LeadDistributionServiceTest` - Distribution logic
- `LeadRecyclingServiceTest` - Rule application
- `FraudDetectionServiceTest` - Pattern detection

### 16.2 Integration Tests

- Waybill delivery → Lead creation flow
- Full distribution → Call → Outcome → Recycle flow
- CSV import with duplicates

### 16.3 Security Tests

- Verify phone number not in any agent API response
- Verify agents cannot access other agents' leads
- Verify audit trail completeness

---

## 17. Rollout Plan

### Phase 1: Core Infrastructure (Week 1)
- Database migrations
- Core services (Pool, Distribution, Audit)
- Basic API endpoints

### Phase 2: Agent Interface (Week 2)
- My Leads view
- Click-to-call integration
- Outcome recording

### Phase 3: Supervisor Dashboard (Week 3)
- Distribution interface
- Performance monitoring
- Activity feed

### Phase 4: Automation (Week 4)
- Recycling engine
- Fraud detection
- Background jobs
- CSV import

---

## 18. Open Questions

1. **Call duration tracking:** MicroSIP doesn't report back to the system. Should we add a "Call ended" button for agents to click, or estimate based on time between call initiation and outcome submission?

2. **Callback notifications:** Should agents receive browser/email notifications for scheduled callbacks?

3. **Lead priority:** Should some leads (e.g., higher previous order value) be distributed first or marked as priority?

---

## Appendix A: Outcome Codes

| Code | Label | Recyclable | Default Cooldown |
|------|-------|------------|------------------|
| `NO_ANSWER` | No Answer | Yes | 24 hours |
| `CALLBACK` | Callback Requested | No (agent keeps) | As scheduled |
| `INTERESTED` | Interested | Yes | 48 hours |
| `ORDERED` | Ordered/Sold | Yes | 60 days |
| `NOT_INTERESTED` | Not Interested | Yes | 30 days |
| `WRONG_NUMBER` | Wrong Number | No | Exhausted |

---

## Appendix B: Fraud Flag Types

| Code | Severity | Trigger |
|------|----------|---------|
| `SUSPICIOUS_VELOCITY` | Warning | 10+ same outcomes in 30 min |
| `NO_CALL_INITIATED` | Critical | Outcome without call log |
| `OUTCOME_TAMPERING` | Critical | Multiple outcome changes |
| `LEAD_HOARDING` | Warning | No call in 24 hours |

---

*End of Specification*
