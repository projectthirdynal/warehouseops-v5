# WarehouseOps Telesales & Lead Distribution System — Complete Implementation Prompt

## Role

Act as a **senior Laravel 11 / Inertia.js / React 18 / TypeScript / PostgreSQL systems architect and implementation engineer**.

Your task is to redesign and complete the existing **WarehouseOps Telesales & Lead Distribution module** so that the Telesales department can operate entirely inside WarehouseOps without depending on Google Sheets for lead pooling, distribution, agent tracking, follow-ups, sales tracking, or reporting.

This is an **existing production system**. Do not rebuild functionality that already exists. First inspect the current implementation, identify reusable services/models/controllers/jobs, then extend the system with the missing approval, pooling, eligibility, telesales UI, permissions, and reporting layers.

---

# 1. Existing System Context

Application:

- System: `WarehouseOps`
- Path: `/opt/warehouseops/`
- Host: `192.168.0.15`
- Hostname: `dc1`
- Backend: Laravel 11
- Frontend: Inertia.js + React 18 + TypeScript
- Database: PostgreSQL
- Queue/cache: Redis
- Runtime: Docker
- Relevant containers:
  - `warehouseops-app`
  - `warehouseops-nginx`
  - `warehouseops-redis`
  - `warehouseops-scheduler`
  - `warehouseops-horizon`

WarehouseOps is separate from WorkflowPro / Auto-Encode. Do not introduce code-level coupling between the two systems.

The existing codebase uses Domain-Driven Design.

Relevant bounded contexts already include:

- `Lead`
- `Customer`
- `Waybill`
- `Courier`
- `Notification`
- `Shop`

The current Lead domain already supports much of the required low-level functionality.

---

# 2. Existing Lead Architecture That Must Be Reused

## Current Lead Pool State

Existing `PoolStatus` lifecycle:

```text
AVAILABLE
    ↓
ASSIGNED
    ↓
COOLDOWN
    ↓
AVAILABLE

or

ASSIGNED
    ↓
EXHAUSTED
```

Do not replace this state machine with the new approval workflow.

The approval workflow must be implemented as a **separate layer**.

---

## Existing Lead Cycles

Each assignment opens a `LeadCycle`.

Existing cycle information includes:

- `cycle_number`
- `call_count`
- `last_call_at`
- `outcome`
- `opened_at`
- `closed_at`

Existing outcomes include:

```text
NO_ANSWER
CALLBACK
INTERESTED
ORDERED
NOT_INTERESTED
WRONG_NUMBER
```

Reuse these outcomes unless there is a proven business requirement for an additional state.

Do not create a competing duplicate outcome system.

---

## Existing Lead Sources

The application already recognizes sources such as:

```text
WAYBILL
XLSX_IMPORT
TELESALES_IMPORT
MANUAL
FACEBOOK
SHOP
WEB
PHONE
REFERRAL
WALK_IN
DELIVERED_WAYBILL
```

The primary production source today is delivered waybill data.

---

## Current Production State

At the time of analysis, production contained approximately:

- 276,485 leads
- 275,835 AVAILABLE
- 650 ASSIGNED
- 650 lead cycles
- 0 recorded call attempts
- 0 completed outcomes
- 1 order created
- 50 users with `agent` role
- 74 agent profiles
- 0 configured distribution rules
- 6 recycling rules
- more than 1.1M waybill rows

All current production leads were generated from `DELIVERED_WAYBILL`.

This means the system has a large usable dataset but the telesales workflow is effectively dormant.

Do not enable or depend on predictive/ML allocation until meaningful operational outcome data exists.

---

# 3. Existing Services That Must Be Preserved Where Appropriate

Before writing new services, inspect and reuse the existing implementations.

Important existing areas include:

### Lead ingestion

- `GenerateLeadsFromUpload`
- `TelesalesLeadImportService`
- `TelesalesLeadImportController`
- `LeadImportService`

### Distribution

- `DistributionEngine`
- `LeadDistributionService`
- `AutoDistributeLeads`
- `DistributionQueue`
- `CapacityManager`
- `RuleConditionEvaluator`
- `StrategyResolver`

Existing strategies include:

```text
ROUND_ROBIN
WEIGHTED
SKILL_MATCH
TERRITORY
HYBRID
PREDICTIVE
```

### Agent lead workflow

- `AgentLeadController`
- agent self-pull endpoint
- manual supervisor distribution

### Recycling

- `LeadRecyclingService`
- `ProcessCooldownLeads`
- existing `RecyclingRule` configuration

### Calling

- `CallTrackingService`
- MicroSIP `sip:<phone>` link generation

### Order fulfillment

- `OrderFulfillmentService::createFromLead`

### Supporting systems

- `LeadScoringService`
- `FraudDetectionService`
- `BurnoutPredictionService`
- `WorkloadBalancingService`
- `LeadAuditService`

Do not rewrite these unless inspection proves that changes are necessary.

---

# 4. Core Business Goal

Build a complete **Telesales Department workspace** inside WarehouseOps.

The Telesales module must be operationally separate from Warehouse and other company modules while still using the same application, database, authentication system, customers, waybills, products, orders, and fulfillment pipeline.

The final business flow must be:

```text
WAYBILL / CUSTOMER DATA
        ↓
LEAD ELIGIBILITY
        ↓
LEAD INVENTORY
        ↓
POOL REQUEST
        ↓
ADMIN APPROVAL
        ↓
APPROVED LEAD POOL
        ↓
DISTRIBUTION
        ↓
AGENT WORK QUEUE
        ↓
CALL / CONTACT RESULT
        ↓
FOLLOW-UP / RECYCLE / ORDER
        ↓
ORDER FULFILLMENT
        ↓
TELESALES ANALYTICS
```

Google Sheets must no longer be required for the normal telesales operation.

---

# 5. Critical Architectural Rule

## Do not rebuild the lead engine.

The existing system already contains:

- lead records
- customer deduplication
- assignment
- agent capacity
- lead cycles
- outcomes
- recycling
- order fulfillment
- distribution strategies
- audit logging

The missing core capability is the controlled layer:

```text
Lead Inventory
→ Pool Request
→ Admin Approval
→ Approved Pool
→ Controlled Distribution
```

Build that layer on top of the existing system.

---

# 6. Separate Telesales Application Section

Create a dedicated Telesales workspace under:

```text
/telesales
```

The Telesales module should have its own navigation shell or strongly separated navigation group.

Recommended navigation:

```text
TELESALES

Dashboard

LEADS
├── My Leads
├── Lead Inventory
├── Pool Requests
├── Lead Pools
└── Follow-ups

SALES
├── Sales
├── Orders
└── Customers

TEAM
├── Agents
├── Teams
└── Performance

ANALYTICS
├── Lead Performance
├── Agent Performance
├── Team Performance
├── Brand Performance
├── Region Performance
├── Lead Age Performance
└── Pool Performance

ADMIN
├── Pool Approvals
├── Brands
├── Regions
├── Distribution Rules
├── Recycling Rules
├── Lead Eligibility
├── Agent Management
├── Audit Logs
└── Telesales Settings
```

Do not create a second application or duplicate authentication stack merely for visual separation.

---

# 7. Lead Eligibility Rules

## Primary business rule

Only qualified waybill/customer records within the configured maximum age can be pooled for telesales.

Default rule:

```text
Maximum eligible waybill age = 60 days
```

This value must be configurable by an administrator.

Example setting:

```text
Maximum Waybill Lead Age
[ 60 ] days
```

Do not hard-code `60` throughout the codebase.

---

## Correct date source

Do not determine age using `lead.created_at`.

Use the actual relevant waybill/order business date.

Preferred logic:

```text
waybill.delivered_at >= today - configured_max_age
```

If `delivered_at` is not reliable or not available for all records, inspect the schema and use the most appropriate actual transaction/delivery date.

Document the chosen date field.

---

## Eligibility should also reject, when applicable:

- invalid phone numbers
- exhausted leads
- leads explicitly blacklisted
- customers on Do Not Call status
- leads already locked into another active approved pool
- active assignments that cannot legally be reassigned
- rows outside the requested brand/product scope
- rows outside requested geography
- rows outside requested age range

Do not silently delete rejected records.

They simply must not appear as eligible inventory.

---

# 8. Phone Normalization / Deduplication

Normalize phone values before eligibility and duplicate checking.

These should resolve to the same customer identity:

```text
09171234567
+639171234567
639171234567
```

Reuse existing customer deduplication logic if already implemented.

Do not introduce a second incompatible phone normalization implementation.

---

# 9. Lead Inventory

Create a Lead Inventory screen for supervisors/admins.

Purpose:

Show the actual number of eligible leads before someone requests a pool.

Filters should support:

- Brand
- Product
- Business region
- Philippine region
- Province
- City / Municipality
- Barangay where practical
- Lead age range
- Source
- Pool status where useful
- Customer/order history where useful

Minimum initial filters:

```text
Brand
Region
Lead Age
```

---

## Inventory counters

Example:

| Brand        | Region      | 0–7 Days | 8–30 Days | 31–60 Days | Total |
| ------------ | ----------- | -------: | --------: | ---------: | ----: |
| Black Garlic | North Luzon |    1,827 |     4,210 |      2,391 | 8,428 |
| Barley       | North Luzon |      921 |     2,181 |      1,082 | 4,184 |
| EyeCare      | NCR         |    1,101 |     3,284 |      1,820 | 6,205 |

The inventory count must be calculated from the same eligibility query used during final pool reservation.

Do not implement one query for display and another logically different query for pool creation.

---

# 10. Philippine Region Model

Support both business grouping and actual geographic hierarchy.

Recommended structure:

```text
Island Group
├── Luzon
├── Visayas
└── Mindanao
```

Business group:

```text
North Luzon
South Luzon
NCR
Visayas
Mindanao
```

Geographic hierarchy:

```text
Region
→ Province
→ City / Municipality
→ Barangay
```

A pool request should eventually support selections such as:

```text
Black Garlic
North Luzon
2,000 leads
```

or:

```text
Black Garlic
Bulacan
500 leads
```

or:

```text
Black Garlic
Malolos
150 leads
```

Do not hard-code brand-specific region conditions inside controllers.

Create reusable geography filtering.

---

# 11. Brand and Product Configuration

Brands available for telesales pooling must be configurable.

Example:

```text
Black Garlic       ACTIVE
Barley             ACTIVE
EyeCare            ACTIVE
Turmeric Patch     ACTIVE
VoltGuard          DISABLED
```

At minimum support:

- active/inactive
- brand ID
- display name
- linked products

Future-friendly optional fields:

- allowed regions
- allowed teams
- maximum pool size
- default distribution method
- minimum lead age
- maximum lead age
- priority

Use the existing brand/product source of truth where possible.

Do not create duplicate product master data unless WarehouseOps currently lacks the required relationship.

---

# 12. Lead Pool Request

Create a dedicated aggregate/model:

```text
LeadPoolRequest
```

Suggested database table:

```text
lead_pool_requests
```

Suggested fields:

```text
id
request_number
requested_by
team_id

brand_id
product_id nullable

region_scope_type nullable
region_scope_id/value nullable

lead_age_from
lead_age_to

requested_quantity
available_quantity_at_request

distribution_method

status

approved_quantity nullable

approved_by nullable
approved_at nullable

rejected_by nullable
rejected_at nullable
rejection_reason nullable

notes nullable

created_at
updated_at
```

Adapt field types and naming to existing conventions.

---

## Pool request statuses

Use a dedicated enum/value object.

Suggested values:

```text
DRAFT
PENDING_APPROVAL
APPROVED
REJECTED
CANCELLED
PARTIALLY_DISTRIBUTED
DISTRIBUTED
```

Do not add these values to the existing Lead `PoolStatus`.

They represent request workflow, not individual lead lifecycle.

---

# 13. Create Pool Request UI

Recommended form:

```text
CREATE LEAD POOL

Brand
[ Black Garlic Coffee ▼ ]

Product
[ All Products ▼ ]

Region
[ North Luzon ▼ ]

Province
[ All ▼ ]

Lead Age
[ 0 ] to [ 60 ] days

Available Leads
8,428

Requested Quantity
[ 2,000 ]

Team
[ Telesales Team 1 ▼ ]

Distribution Method
[ Equal ▼ ]

Notes
[ __________________________ ]

[ SUBMIT FOR APPROVAL ]
```

After submission:

```text
LP-20260821-0184
PENDING APPROVAL
```

The requester must not be able to distribute the pool before admin approval.

---

# 14. Admin Approval Workflow

Create:

```text
/telesales/admin/pool-approvals
```

Suggested table:

| Request | Brand | Region | Age | Requested | Available | Team | Requested By | Status |
| ------- | ----- | ------ | --- | --------: | --------: | ---- | ------------ | ------ |

Actions:

```text
APPROVE
MODIFY & APPROVE
REJECT
```

Admin must be able to adjust the approved quantity without modifying the original requested quantity.

Store both.

Example:

```text
Requested Quantity: 2,000
Approved Quantity: 1,500
```

Record who approved/rejected and when.

Audit-log the full action.

---

# 15. Recalculate Before Approval

The number of available leads may change between request time and approval time.

Example:

```text
At request:
Available = 8,428

At approval:
Available = 7,992

Requested = 2,000
```

The system must recalculate live eligibility before reservation.

Show the admin:

```text
Currently Available: 7,992
Requested: 2,000
Can Fulfill: YES
```

If requested quantity is no longer available:

- prevent blind over-allocation
- allow modified approval
- clearly show the shortfall

---

# 16. Do Not Reserve Leads at Request Time

Submitting a request must not lock the actual lead rows.

Correct sequence:

```text
REQUEST CREATED
    ↓
Store filters + requested quantity + availability snapshot
    ↓
PENDING APPROVAL
    ↓
ADMIN APPROVES
    ↓
START DB TRANSACTION
    ↓
RECALCULATE ELIGIBLE INVENTORY
    ↓
LOCK / RESERVE SELECTED LEADS
    ↓
CREATE APPROVED POOL
    ↓
CREATE POOL MEMBERS
    ↓
COMMIT
```

This prevents unapproved requests from unnecessarily locking customer data.

---

# 17. Concurrency / Race-Condition Requirements

This part is mandatory.

Two admins or supervisors may attempt overlapping operations at the same time.

The implementation must prevent the same lead from being reserved into multiple active pools.

Use PostgreSQL-safe transactional locking.

Inspect existing race-condition handling inside `LeadDistributionService` and follow the established codebase pattern.

Possible approaches include:

- transaction
- `SELECT ... FOR UPDATE`
- atomic update with qualifying conditions
- unique partial constraints where suitable
- idempotency keys where suitable

Do not rely on a UI check alone.

After locking rows, revalidate eligibility before creating membership.

---

# 18. Lead Pool

Create:

```text
LeadPool
```

Suggested table:

```text
lead_pools
```

Suggested fields:

```text
id
pool_number
pool_request_id

brand_id
product_id nullable

team_id

lead_age_from
lead_age_to

region_scope_type nullable
region_scope_id/value nullable

approved_quantity
distribution_method

status

created_by
approved_by

created_at
activated_at nullable
completed_at nullable
cancelled_at nullable
```

Possible pool statuses:

```text
READY
ACTIVE
PARTIALLY_DISTRIBUTED
FULLY_DISTRIBUTED
COMPLETED
CANCELLED
```

Avoid unnecessary status duplication.

Choose the smallest state machine that clearly describes pool-level behavior.

---

# 19. Lead Pool Membership

Create a dedicated membership table:

```text
lead_pool_members
```

Suggested fields:

```text
id
lead_pool_id
lead_id

status

added_at
assigned_at nullable
removed_at nullable
removal_reason nullable

created_at
updated_at
```

The pool membership must answer:

> Exactly which leads belonged to this approved pool?

That history must remain auditable even after assignment.

---

# 20. Prevent Duplicate Pool Membership

Enforce the rule that one lead cannot simultaneously belong to multiple incompatible active pools.

Do not rely entirely on application code.

Where possible add database-level protection.

Be careful that historical/completed pools should remain reportable.

Design the constraint around active membership, not all historical membership forever.

---

# 21. Change Auto Distribution Behavior

Current behavior allows the scheduled `AutoDistributeLeads` process to distribute generic AVAILABLE leads.

That is no longer acceptable for the controlled telesales workflow.

Change it so telesales assignment can only consume leads from an approved/active pool.

Desired flow:

```text
AVAILABLE LEAD
    +
ACTIVE APPROVED POOL MEMBERSHIP
    ↓
DISTRIBUTION QUEUE
    ↓
DistributionEngine
    ↓
AGENT
```

Do not delete the existing distribution engine.

Change the candidate source feeding the engine.

---

# 22. Keep DistributionEngine as the Agent Selector

Conceptually separate:

```text
POOL = WHAT leads may be distributed
```

from:

```text
DistributionRule = WHO should receive them
```

This separation must be preserved in code and UI.

Example:

```text
POOL
Black Garlic
North Luzon
2,000 leads
```

Then:

```text
DISTRIBUTION
Telesales Team 1
10 active agents
Equal distribution
```

---

# 23. Distribution Methods

For Version 1, prioritize reliable, understandable methods:

```text
EQUAL
MANUAL_QUANTITY
ROUND_ROBIN
```

Existing advanced strategies may remain available internally:

```text
WEIGHTED
SKILL_MATCH
TERRITORY
HYBRID
PREDICTIVE
```

Do not make predictive allocation the default yet.

There is insufficient real call/outcome history to validate it.

---

# 24. Equal Distribution

Example:

```text
Pool: 2,000 leads
Active agents: 10
```

Result:

```text
Agent 1 = 200
Agent 2 = 200
Agent 3 = 200
...
Agent 10 = 200
```

Eligibility/capacity checks must still apply.

If an agent cannot accept their theoretical share, redistribute the remainder safely or surface the undistributed quantity.

Do not silently exceed agent capacity.

---

# 25. Manual Quantity Distribution

Supervisor can specify:

```text
Agent A = 300
Agent B = 250
Agent C = 150
Agent D = 300
```

Validation:

- sum cannot exceed remaining pool quantity
- agent must belong to the permitted team
- agent must be active/eligible
- agent capacity must be respected
- assignment must be transactional

---

# 26. Agent Self-Pull Must Be Restricted

The existing agent self-pull endpoint currently allows an agent to request `N` leads from the general pool.

Modify this behavior.

New rule:

```text
Agent requests N leads
    ↓
Find active approved pools assigned to agent's team
    ↓
Select only unassigned members from those pools
    ↓
Apply capacity limits
    ↓
Assign
```

An agent must never self-pull arbitrary leads from the company's entire available inventory.

If no approved pool is available:

```text
No approved leads are currently available for your team.
```

Do not silently fall back to the global general pool.

---

# 27. Agent Workspace

The agent UI must be intentionally simple.

Agents do not need a spreadsheet replacement with hundreds of visible rows and columns.

Primary page:

```text
/telesales/my-leads
```

Summary:

```text
Assigned
Contacted
Remaining
Callbacks
Interested
Orders
Conversion Rate
```

Main work queue should prioritize the next actionable lead.

---

## Lead card

Example:

```text
Maria Santos

0917 XXX XXXX

Brand:
Black Garlic

Location:
Malolos, Bulacan

Previous Orders:
2

Last Purchase:
July 29, 2026

Lead Age:
23 days

Pool:
POOL-20260821-001

[ CALL ]
```

Keep customer information limited to what the agent actually needs.

---

# 28. Agent Lead Actions

Minimum actions:

```text
CALL
NO ANSWER
CALLBACK
INTERESTED
NOT INTERESTED
WRONG NUMBER
ORDERED
```

Reuse the current `LeadOutcome` flow.

Do not invent another disconnected status column simply for frontend convenience.

---

# 29. Calling

Reuse `CallTrackingService` and the current ownership/security check.

Existing concept:

```text
lead assigned_to == authenticated user
AND
active cycle exists
```

Then generate:

```text
sip:<phone>
```

and increment call tracking.

Do not broaden agent access to leads they do not own.

If future FreeSWITCH integration is desired, keep that outside this phase unless specifically required.

---

# 30. Follow-Ups

Create a first-class Follow-ups screen.

Recommended sections:

```text
Due Today
Overdue
Upcoming
Completed
```

Callback leads must remain owned correctly.

A callback should not be recycled into the general pool merely because the cooldown processor runs.

Respect existing `CALLBACK` semantics.

---

# 31. Existing Recycling Rules

Preserve and expose current rules through the admin interface.

Current analyzed behavior:

```text
NO_ANSWER
Cooldown: 24h
Max cycles: 5
Action: recycle

CALLBACK
Stays active

INTERESTED
Cooldown: 48h
Max cycles: 3

NOT_INTERESTED
Cooldown: 30 days
Max cycles: 2
Final action: exhaust

WRONG_NUMBER
Max cycles: 1
Final action: exhaust

ORDERED
Does not return to normal active calling flow
Order fulfillment is triggered
```

Verify the actual production configuration before changing behavior.

---

# 32. Lead Recycling / Reassignment

Add supervisor controls to reclaim appropriate leads.

Example:

```text
Assigned
    ↓
No meaningful activity for configured period
    ↓
Eligible for supervisor reclaim
```

Do not automatically reclaim leads that have:

- a valid scheduled callback
- an open order
- an active protected interaction
- Do Not Call state
- exhausted state

Record all reassignments in the audit log.

---

# 33. Sales Integration

When an agent records:

```text
ORDERED
```

continue using the existing order fulfillment path:

```text
Lead
↓
LeadCycle outcome = ORDERED
↓
OrderFulfillmentService::createFromLead
↓
WarehouseOps order/fulfillment
```

Do not build a second standalone telesales order database unless a real gap is discovered.

---

# 34. Sales Attribution

Every telesales order should preserve enough information to attribute it back to:

- lead
- pool
- pool request
- agent
- team
- brand
- product
- region
- lead age at assignment/conversion
- source
- assignment date
- order/conversion date

This is required for accurate reporting.

Avoid deriving historical lead age from mutable present-day values.

Persist snapshots where necessary for reporting.

---

# 35. Telesales Dashboard

Create a dedicated manager dashboard.

Primary funnel:

```text
ELIGIBLE
→ POOLED
→ ASSIGNED
→ CALLED
→ CONTACTED
→ INTERESTED
→ ORDERED
```

Primary cards:

```text
Eligible Leads
Pending Pool Requests
Approved Pool Leads
Assigned Leads
Calls Today
Contacted Today
Contact Rate
Interested
Orders
Conversion Rate
Revenue
Leads Per Order
```

---

# 36. Analytics Dimensions

Support breakdown by:

```text
Agent
Team
Brand
Product
Region
Province
Lead Age
Pool
Pool Request
Lead Source
Date Range
```

Do not calculate conversion rate from inconsistent denominators.

Clearly define metrics.

Recommended examples:

```text
Contact Rate
= contacted leads / attempted unique leads

Lead-to-Order Conversion
= orders / assigned unique leads

Contact-to-Order Conversion
= orders / contacted unique leads
```

Document metric definitions in the UI or code.

---

# 37. Lead Age Analytics

This is a key management report.

Suggested buckets:

```text
0–7 days
8–14 days
15–30 days
31–45 days
46–60 days
```

Example output:

| Lead Age   | Assigned | Contacted | Orders | Conversion |
| ---------- | -------: | --------: | -----: | ---------: |
| 0–7 Days   |    3,000 |     2,410 |    350 |     11.67% |
| 8–14 Days  |    2,500 |     1,910 |    220 |      8.80% |
| 15–30 Days |    4,000 |     2,800 |    250 |      6.25% |
| 31–45 Days |    2,000 |     1,210 |     76 |      3.80% |
| 46–60 Days |    1,500 |       790 |     30 |      2.00% |

This report must use actual historical lead age at relevant workflow points.

---

# 38. Pool Performance Report

Each pool should have a detail page.

Example:

```text
POOL-20260821-001

Brand:
Black Garlic

Region:
North Luzon

Lead Age:
0–60 days

Approved:
2,000

Assigned:
2,000

Called:
1,632

Contacted:
1,201

Interested:
302

Ordered:
142

Remaining:
368

Lead-to-Order Conversion:
7.10%
```

Also show:

- per-agent performance
- outcome distribution
- daily progress
- age bucket breakdown
- region breakdown where useful
- revenue

---

# 39. Permissions / RBAC

Create strict telesales permissions.

Do not rely only on hiding frontend buttons.

Enforce authorization server-side.

---

## System Admin

Can:

- access all telesales screens
- manage telesales configuration
- approve/reject pools
- manage users/teams
- view/export reports
- change eligibility rules
- manage distribution and recycling settings

---

## Telesales Admin

Can:

- approve/reject pool requests
- create pool requests
- manage telesales teams
- manage agents where permitted
- manage approved pools
- view all telesales leads
- view telesales sales
- view telesales reports
- configure permitted telesales settings

---

## Supervisor

Can:

- create pool requests
- view request status
- distribute approved pools
- view own teams
- view own team agents
- view own team leads
- view team performance
- reassign/reclaim permitted leads
- manage follow-up workflow where allowed

Cannot approve their own pool request unless explicitly permitted by business configuration.

Default behavior should require separate admin approval.

---

## Agent

Can:

- view only their own assigned leads
- request leads only from approved pools for their team
- call their own leads
- update outcomes for their own leads
- schedule callbacks
- create/trigger orders from their own leads
- view their own performance

Agents must not be able to:

- browse all company leads
- export the company lead database
- view another agent's assigned leads
- approve pools
- create arbitrary approved pools
- change original waybill data
- bypass capacity rules
- assign leads directly to themselves outside approved pool rules

---

# 40. Audit Logging

Use the existing `LeadAuditService` or established audit infrastructure.

Audit at minimum:

```text
POOL_REQUEST_CREATED
POOL_REQUEST_SUBMITTED
POOL_REQUEST_APPROVED
POOL_REQUEST_MODIFIED_AND_APPROVED
POOL_REQUEST_REJECTED
POOL_CREATED
LEAD_ADDED_TO_POOL
POOL_DISTRIBUTION_STARTED
LEAD_ASSIGNED
LEAD_REASSIGNED
LEAD_RECLAIMED
CALL_INITIATED
OUTCOME_RECORDED
CALLBACK_SCHEDULED
ORDER_CREATED
POOL_COMPLETED
POOL_CANCELLED
SETTING_CHANGED
```

Audit metadata should include:

- actor
- timestamp
- entity
- old values when appropriate
- new values when appropriate
- related pool/request/team/agent IDs

Do not log sensitive information unnecessarily.

---

# 41. Activity Timeline

Provide a readable activity history on lead and pool detail screens.

Example:

```text
4:01 PM
Supervisor A requested 2,000 Black Garlic leads.

4:04 PM
Admin B approved 2,000 leads.

4:05 PM
POOL-20260821-001 was created.

4:06 PM
200 leads were assigned to Agent 03.

4:16 PM
Agent 03 initiated a call to LEAD-3821.

4:18 PM
Outcome changed to CALLBACK.

4:22 PM
Agent 08 converted LEAD-8871 to ORDERED.
```

---

# 42. Telesales Settings

Create a settings area for operational rules.

Recommended configurable values:

```text
Maximum eligible waybill age
Default lead age range
Default pool distribution method
Maximum pool request quantity
Allow supervisor self-approval
Agent max active leads
Agent daily assignment cap
Inactive assignment reclaim threshold
Default lead age buckets
Default business region groups
Auto-distribution enabled/disabled
Agent self-pull enabled/disabled
```

Do not scatter operational constants across controllers/jobs.

---

# 43. Scheduled Job Changes

Inspect current scheduler entries.

Important current behavior includes:

- `AutoDistributeLeads` every minute
- `ProcessCooldownLeads` every 15 minutes
- fraud detection every 30 minutes
- lead rescoring hourly
- predictive retraining daily

Change `AutoDistributeLeads` so it cannot consume arbitrary unapproved AVAILABLE leads.

Do not break cooldown/recycling behavior.

Do not remove scheduled jobs without documenting why.

---

# 44. Predictive / ML Distribution

The codebase already contains predictive assignment and ML-oriented scoring.

Do not make this the primary production strategy now.

Reason:

Current operational data has virtually no actual call/outcome history.

Recommended rollout:

```text
Phase 1
Equal
Manual Quantity
Round Robin

Phase 2
Skill / Territory / Weighted

Phase 3
Predictive, only after sufficient clean historical data exists
```

Before enabling predictive assignment:

- collect meaningful call outcomes
- measure baseline strategies
- train on clean historical data
- validate conversion lift
- create safe fallback behavior
- allow feature flag disablement

---

# 45. Existing Distribution Rules Gap

Production analysis showed:

```text
configured distribution rules = 0
```

Therefore the engine currently uses fallback round-robin.

Do not mistake this for a code bug.

After the pool approval layer is implemented, create proper admin configuration for distribution rules.

But keep pool selection separate from agent selection.

---

# 46. Existing XLSX Import Concern

Inspect the telesales import path before relying on it.

There is a known code/documentation contradiction:

- `TelesalesLeadImportService` reportedly references `PhpOffice\PhpSpreadsheet`
- project documentation reportedly says PhpSpreadsheet is not installed and `fast-excel` should be used

Verify installed Composer dependencies.

If the XLSX path is broken:

- fix it using the project's supported library
- do not add conflicting spreadsheet stacks without justification
- preserve CSV import support

This is secondary to the core pool workflow.

---

# 47. Existing Security Concern

Inspect `SmsService`.

A previous analysis identified a hardcoded API key fallback in source.

If still present:

- remove hardcoded secret fallback
- require environment/config-based secret
- fail clearly when missing
- rotate the exposed secret outside code if needed

Do not expose secret values in logs, UI, tests, or commits.

This is not the core telesales feature, but should be fixed if encountered while touching the module.

---

# 48. UI / UX Rules

The system is replacing spreadsheets.

Do not make the new interface look like a spreadsheet unless a dense table is genuinely needed.

Prioritize:

- clear filters
- clear ownership
- clear statuses
- clear counters
- fast agent actions
- low-click workflows
- mobile/desktop usability where practical
- persistent filters where useful
- pagination/virtualization for large result sets
- no loading of hundreds of thousands of leads into browser memory

---

# 49. Performance Requirements

Production contains hundreds of thousands of leads and more than one million waybills.

All queries must be designed for that scale.

Review/add indexes for:

- lead pool status
- lead assignment
- normalized phone
- customer ID
- brand/product relation
- relevant waybill delivery date
- geography fields
- lead source
- pool request status
- pool status
- pool membership
- team
- agent
- callback date
- outcome
- commonly combined inventory filters

Use `EXPLAIN ANALYZE` for expensive inventory and reporting queries where necessary.

Avoid N+1 queries.

Use pagination and aggregation.

Do not run full-table PHP filtering for inventory.

---

# 50. Recommended Domain Services

Do not put all workflow logic into controllers.

Consider services/actions such as:

```text
LeadEligibilityService
LeadInventoryService
LeadPoolRequestService
ApproveLeadPoolRequest
LeadPoolReservationService
LeadPoolDistributionService
LeadPoolAvailabilityService
LeadPoolReclaimService
TelesalesMetricsService
TelesalesAuthorizationService
```

Names may be adapted to existing architecture.

Keep controllers thin.

---

# 51. Database Integrity

Use database constraints where appropriate.

Examples:

- positive requested quantity
- positive approved quantity
- approved quantity cannot be logically invalid
- valid foreign keys
- unique request numbers
- unique pool numbers
- appropriate uniqueness for active pool membership
- index approval/request status
- prevent orphaned pool membership
- prevent invalid agent/team relationships where the schema supports it

Do not trust frontend validation alone.

---

# 52. API / Route Design

Use the existing Inertia/Laravel conventions.

Potential routes:

```text
GET    /telesales
GET    /telesales/leads/inventory

GET    /telesales/pool-requests
GET    /telesales/pool-requests/create
POST   /telesales/pool-requests
GET    /telesales/pool-requests/{request}
POST   /telesales/pool-requests/{request}/submit
POST   /telesales/pool-requests/{request}/cancel

GET    /telesales/admin/pool-approvals
POST   /telesales/admin/pool-approvals/{request}/approve
POST   /telesales/admin/pool-approvals/{request}/reject

GET    /telesales/pools
GET    /telesales/pools/{pool}
POST   /telesales/pools/{pool}/distribute
POST   /telesales/pools/{pool}/reclaim

GET    /telesales/my-leads
POST   /api/telesales/my-leads/request
POST   /api/telesales/leads/{lead}/call
POST   /api/telesales/leads/{lead}/outcome

GET    /telesales/follow-ups
GET    /telesales/analytics/*
```

Do not blindly implement these exact paths if equivalent routes already exist.

First inspect current route conventions and preserve compatibility.

---

# 53. Backward Compatibility

The current production system already has:

- existing lead records
- existing assignments
- existing cycles
- existing recycling
- imports
- existing URLs/controllers

Do not break production behavior unnecessarily.

For legacy generic lead distribution:

- determine whether it is still used outside Telesales
- isolate the new approval enforcement to the Telesales flow if necessary
- do not globally disable another department's valid workflow without evidence

Document compatibility decisions.

---

# 54. Migration Strategy

Migrations must be safe for production.

Requirements:

- additive first
- avoid destructive schema changes in initial rollout
- nullable columns during transition where appropriate
- backfill in controlled jobs if needed
- indexes added with PostgreSQL production impact in mind
- no migration that loops through hundreds of thousands of rows in PHP synchronously

If large backfills are needed:

- use batched jobs
- make them resumable
- make them idempotent

---

# 55. Feature Flags

Consider feature flags for:

```text
TELESALES_POOL_APPROVAL_ENABLED
TELESALES_APPROVED_POOL_DISTRIBUTION_ONLY
TELESALES_AGENT_SELF_PULL_ENABLED
TELESALES_PREDICTIVE_DISTRIBUTION_ENABLED
```

Use the project's existing feature/config system if available.

This allows controlled rollout.

---

# 56. Testing Requirements

Add automated tests before considering the work complete.

At minimum cover:

## Eligibility

- lead inside 60-day limit is eligible
- lead outside configured limit is not eligible
- configured limit change is respected
- exhausted lead excluded
- blacklisted/DNC excluded where applicable
- invalid phone excluded where applicable
- geography filter works
- brand filter works
- age range works

## Pool request

- supervisor can create request
- agent cannot create request
- request captures availability snapshot
- request cannot distribute before approval
- request can be rejected
- request can be cancelled under permitted states

## Approval

- authorized admin can approve
- unauthorized user cannot approve
- admin can approve lower quantity
- live availability recalculated
- insufficient inventory handled cleanly

## Concurrency

- same lead cannot enter two active approved pools
- simultaneous approval does not double-reserve
- simultaneous assignment does not double-assign
- retries are idempotent

## Distribution

- only approved pool members can be distributed
- equal distribution respects capacity
- manual distribution validates totals
- agent cannot receive leads for another team unless allowed
- no arbitrary global fallback for telesales self-pull

## Agent

- agent sees own leads only
- agent cannot update another agent's lead
- call action requires active assignment/cycle
- outcomes transition correctly
- callback preserved
- ORDERED triggers fulfillment

## Recycling

- NO_ANSWER behavior preserved
- CALLBACK stays active
- WRONG_NUMBER exhausts according to rule
- reclaim does not steal protected callbacks

## Reporting

- pool counts remain consistent
- conversion metrics use documented denominator
- lead-age buckets are correct
- orders attribute to pool/agent/team correctly

---

# 57. Audit Tests

Verify that sensitive workflow events create audit records.

Test:

- request submission
- approval
- rejection
- pool creation
- assignment
- reassignment
- outcome
- order
- configuration changes

---

# 58. Authorization Tests

Create policy/permission tests.

Never rely only on page visibility.

Test direct HTTP/API attempts from unauthorized roles.

---

# 59. Data Validation

Validate:

- requested quantity > 0
- age start <= age end
- age end <= configured maximum unless admin override is explicitly designed
- brand active for telesales
- team exists and is active
- selected agents belong to permitted team
- distribution quantity <= available pool members
- callback date is valid
- pool action is allowed from current state

---

# 60. Observability

Add structured logs/metrics for:

- pool request created
- pool approval time
- reservation duration
- number of selected leads
- reservation shortfall
- distribution failures
- capacity rejections
- assignment counts
- self-pull failures
- order conversions

Avoid logging full phone numbers or unnecessary customer PII.

---

# 61. Error Handling

Provide clear business errors.

Examples:

```text
Requested quantity exceeds currently eligible inventory.

This pool is not approved for distribution.

No approved leads are available for your team.

This lead has already been assigned.

This lead is no longer eligible.

Agent capacity has been reached.

This request has already been processed.
```

Avoid generic 500 responses for expected business conflicts.

---

# 62. Idempotency

Approval and distribution actions must be safe against double-clicks/retries.

Example:

If an approve request is submitted twice, the second request must not create a second pool.

Use:

- state checks
- unique linkage between approved request and pool
- transaction
- idempotent action design

---

# 63. Google Sheets Removal Goal

The new system must replace these spreadsheet activities:

```text
checking available lead counts
filtering leads by brand
filtering leads by region
filtering leads by age
requesting quantities
admin approval
manual agent assignment
tracking assigned leads
tracking call outcomes
tracking callbacks
tracking orders
tracking agent performance
tracking pool performance
daily telesales reporting
```

CSV/XLSX export may remain for management/reporting.

Exports must not be required for normal operation.

---

# 64. Implementation Phases

## Phase 0 — Code Audit and Baseline

Before changing behavior:

1. Inspect existing domain structure.
2. Inspect current migrations/models.
3. Inspect lead generation.
4. Inspect waybill date fields.
5. Inspect brand/product relationship.
6. Inspect geography fields.
7. Inspect current agent/team model.
8. Inspect current permissions.
9. Inspect existing distribution routes.
10. Inspect scheduler.
11. Run existing tests.
12. Record baseline behavior.

Do not start by creating duplicate models that already exist.

---

## Phase 1 — Lead Eligibility Foundation

Implement:

- telesales settings
- configurable max waybill age
- eligibility service
- normalized eligibility query
- lead inventory counters
- region/brand/age filters
- indexes

Acceptance:

Supervisor can query:

```text
Brand + Region + Lead Age
```

and receive a reliable available lead count.

---

## Phase 2 — Pool Request and Approval

Implement:

- `lead_pool_requests`
- request enum/state
- request UI
- approval UI
- rejection
- modified approval
- permissions
- audit events

Acceptance:

No request can proceed to distribution without authorized approval.

---

## Phase 3 — Pool Reservation

Implement:

- `lead_pools`
- `lead_pool_members`
- transaction-safe reservation
- live availability recheck
- duplicate protection
- pool detail page

Acceptance:

Two concurrent approvals cannot reserve the same lead into conflicting active pools.

---

## Phase 4 — Controlled Distribution

Modify:

- auto-distribution
- manual distribution
- agent self-pull

Require approved pool membership.

Implement:

- equal distribution
- manual quantity
- round robin where useful
- capacity validation
- team validation

Acceptance:

Telesales agents cannot receive leads that are outside an approved pool assigned to their workflow/team.

---

## Phase 5 — Agent Workspace

Implement:

- My Leads
- next actionable lead
- call action
- outcome selection
- callbacks
- ownership enforcement
- compact personal metrics

Acceptance:

An agent can complete their daily workflow without opening Google Sheets.

---

## Phase 6 — Sales / Fulfillment

Verify and complete:

```text
ORDERED
→ createFromLead
→ existing fulfillment
```

Add attribution to:

- pool
- agent
- team
- brand
- region
- lead age

Acceptance:

Management can trace an order back to the exact lead and pool.

---

## Phase 7 — Dashboard and Analytics

Implement:

- telesales dashboard
- funnel
- agent/team performance
- brand performance
- region performance
- pool performance
- lead-age analysis

Acceptance:

Management can answer:

```text
Which team converts best?
Which agent converts best?
Which brand converts best?
Which region converts best?
Which lead age converts best?
Which pool performs best?
```

without spreadsheet processing.

---

## Phase 8 — Operational Hardening

Complete:

- race-condition tests
- permission tests
- query optimization
- audit coverage
- metrics
- feature flags
- migration safety
- rollback plan

---

## Phase 9 — Advanced Distribution Later

Only after enough clean production outcome data exists:

- weighted
- skill-based
- territory
- hybrid
- predictive

Run controlled comparisons.

Do not activate ML merely because the code exists.

---

# 65. Definition of Done

The project is not complete until all of the following are true.

## Lead inventory

- [ ] Eligible lead inventory is visible inside WarehouseOps.
- [ ] Inventory filters by brand.
- [ ] Inventory filters by region.
- [ ] Inventory filters by configurable age.
- [ ] Default maximum lead age is 60 days.
- [ ] Counts use the same eligibility logic as pool reservation.

## Pool requests

- [ ] Supervisor can create a request.
- [ ] Requested quantity is recorded.
- [ ] Availability snapshot is recorded.
- [ ] Request requires admin approval.
- [ ] Admin can reject.
- [ ] Admin can modify approved quantity.
- [ ] Approval is audit logged.

## Pool reservation

- [ ] Approval creates a real pool.
- [ ] Exact pool membership is stored.
- [ ] Concurrent requests cannot double-pool leads.
- [ ] Reservation is transactional.
- [ ] Historical membership remains reportable.

## Distribution

- [ ] Only approved pool leads enter telesales distribution.
- [ ] Equal distribution works.
- [ ] Manual quantity works.
- [ ] Round robin works where configured.
- [ ] Capacity limits are enforced.
- [ ] Team restrictions are enforced.
- [ ] Agent self-pull only uses approved pools.

## Agent workflow

- [ ] Agent sees own leads only.
- [ ] Agent can call assigned lead.
- [ ] Call attempt is recorded.
- [ ] Agent can set outcome.
- [ ] Agent can schedule callbacks.
- [ ] Follow-up queue exists.
- [ ] Agent can create/trigger an order through existing fulfillment.
- [ ] Google Sheets is not required.

## Reporting

- [ ] Telesales dashboard exists.
- [ ] Agent report exists.
- [ ] Team report exists.
- [ ] Brand report exists.
- [ ] Region report exists.
- [ ] Pool report exists.
- [ ] Lead-age report exists.
- [ ] Sales attribution is traceable.

## Security

- [ ] RBAC enforced server-side.
- [ ] Agents cannot browse company-wide lead inventory.
- [ ] Agents cannot export all leads.
- [ ] Approval cannot be bypassed.
- [ ] Audit trail is complete.
- [ ] Secrets are not committed to source.

## Reliability

- [ ] Tests pass.
- [ ] No known N+1 regressions.
- [ ] Inventory queries perform acceptably at production scale.
- [ ] Double approval is idempotent.
- [ ] Double assignment is prevented.
- [ ] Scheduled jobs cannot bypass pool approval.

---

# 66. Non-Goals for Initial Release

Do not allow these to distract from the core implementation:

- replacing WarehouseOps
- building another separate authentication system
- rewriting the entire Lead domain
- replacing the existing fulfillment system
- full FreeSWITCH server-side telephony integration
- predictive ML as the default allocator
- rebuilding every existing import path
- adding unnecessary AI features
- creating spreadsheet-like interfaces just because users previously used Sheets

---

# 67. Implementation Style

Follow these rules while coding:

1. Inspect before modifying.
2. Reuse existing domain services.
3. Keep controllers thin.
4. Put business rules in services/actions/domain objects.
5. Use enums/value objects for state where the project already follows that style.
6. Use transactions for approval, reservation, and assignment.
7. Protect critical invariants in PostgreSQL where practical.
8. Avoid N+1 queries.
9. Use efficient filtered queries for hundreds of thousands of leads.
10. Add indexes based on actual access patterns.
11. Maintain auditability.
12. Maintain backward compatibility.
13. Add tests for every business-critical workflow.
14. Do not hide broken behavior behind fallbacks.
15. Do not silently swallow exceptions.
16. Do not hard-code business rules that belong in settings.
17. Do not create duplicate sources of truth for brands, products, customers, waybills, or orders.
18. Do not expose customer data beyond what each role requires.

---

# 68. Required Work Method

Execute the project systematically.

For each phase:

### Step 1 — Inspect

Report:

- relevant existing files
- existing models/services/routes
- data structures
- reusable code
- conflicts with the requested architecture

### Step 2 — Plan

State:

- exact files to modify
- migrations to add
- services/actions to add
- routes/pages/components to add
- tests to add

### Step 3 — Implement

Make the changes.

Do not stop after producing pseudocode.

### Step 4 — Test

Run:

- relevant unit tests
- feature tests
- existing regression tests
- static/type checks
- frontend build where applicable

### Step 5 — Verify

Check:

- DB integrity
- permissions
- concurrency behavior
- query efficiency
- scheduler behavior
- UI flow

### Step 6 — Report

After each phase provide:

```text
Completed
Files changed
Migrations added
Tests added
Tests passed
Known limitations
Next phase
```

---

# 69. Do Not Blindly Trust This Specification Over Existing Code

If the existing implementation differs from an assumption in this prompt:

1. inspect the actual code and schema;
2. preserve working production behavior;
3. explain the conflict;
4. adapt the implementation cleanly;
5. do not create duplicate systems merely to match naming in this document.

The business requirements are authoritative.

Specific suggested class names/table names are architectural guidance and may be adapted to existing project conventions.

---

# 70. Final Target Architecture

```text
                 WAREHOUSEOPS
                      │
          ┌───────────┴───────────┐
          │                       │
   EXISTING OPERATIONS       TELESALES MODULE
                                  │
                          LEAD ELIGIBILITY
                                  │
                          LEAD INVENTORY
                                  │
                           POOL REQUEST
                                  │
                           ADMIN APPROVAL
                                  │
                           APPROVED POOL
                                  │
                            DISTRIBUTION
                                  │
                    ┌─────────────┼─────────────┐
                    │             │             │
                 AGENT A       AGENT B       AGENT C
                    │             │             │
                    └─────────────┼─────────────┘
                                  │
                           CALL / OUTCOME
                                  │
                  ┌───────────────┼──────────────┐
                  │               │              │
              CALLBACK        NO SALE         ORDERED
                  │                              │
              FOLLOW-UP                    EXISTING ORDER
                                               │
                                           FULFILLMENT
                                               │
                                            WAREHOUSE
                                               │
                                              │
                           TELESALES ANALYTICS
```

---

# 71. Final Instruction

Start with **Phase 0 — Code Audit and Baseline**.

Do not immediately rewrite the distribution system.

First map the existing implementation against this specification and clearly identify:

1. what already exists and can be reused;
2. what is missing;
3. what current behavior conflicts with approved-pool distribution;
4. what database changes are required;
5. what scheduler/jobs must change;
6. what authorization changes are required;
7. what frontend routes/pages must be created;
8. what risks exist for the current 276k+ lead dataset;
9. how the rollout can be done without breaking production.

Then execute the implementation phase by phase until the Definition of Done is satisfied.
