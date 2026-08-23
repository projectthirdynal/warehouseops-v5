# WarehouseOps Telesales & Lead Distribution — User Guide

## Overview

The Telesales system manages the full lifecycle of leads from waybill delivery to agent assignment to sales outcome. This guide covers all newly implemented features and how to use them properly.

---

## System Architecture

```
Waybills (DELIVERED)
    │
    ▼
Lead Eligibility Engine (Phase B)
    │  Filters: brand, region, province, age, source
    │  Rules: max 60 days, not blacklisted, not DNC, not exhausted
    ▼
Lead Inventory (visible to admins)
    │
    ▼
Pool Request (Phase C — supervisor creates)
    │  "I need 500 Black Garlic leads in NCR, 0-30 days old"
    ▼
Pool Approval (Phase C — admin approves/rejects)
    │  Admin sees live availability, can adjust quantity
    ▼
Lead Pool Created (leads reserved via row-level locking)
    │  Pool members = individual leads locked into this pool
    ▼
Agent Self-Pull (Phase C — restricted to pool members only)
    │  Agent can only pull from approved pools matching their skills
    ▼
Lead Cycle (call → outcome → callback/sold/rejected)
```

---

## Phase B: Lead Eligibility & Inventory

### What It Does

The eligibility engine determines which leads are available for telesales based on:

| Rule                              | Default | Configurable Via                                |
| --------------------------------- | ------- | ----------------------------------------------- |
| Waybill must be DELIVERED         | Yes     | —                                               |
| Waybill age ≤ 60 days             | 60 days | `SystemSetting: telesales_max_waybill_age_days` |
| Customer not blacklisted          | Yes     | Customer record                                 |
| Customer not on Do-Not-Call list  | Yes     | Customer record                                 |
| Lead not exhausted (cycles < max) | Yes     | Lead record                                     |
| Lead pool_status = AVAILABLE      | Yes     | Lead record                                     |

### Important: The 60-Day Rule

**Leads with waybills older than 60 days are NOT eligible.** This is by design — old leads have lower conversion rates. If you need to access older leads:

```bash
# Change the max age to 90 days (via tinker)
php artisan tinker --execute='App\Models\SystemSetting::set("telesales_max_waybill_age_days", 90, "telesales", "int");'
```

### Lead Inventory Page

**URL:** `/telesales/inventory`
**Access:** Admin, Superadmin, Supervisor

Shows a breakdown of all eligible leads by:

- Brand
- Business Region
- Province
- Age Range
- Source

Use this to check availability **before** creating a pool request.

---

## Phase C: Pool Requests, Approvals & Reservations

### Step 1: Create a Pool Request

**URL:** `/telesales/pool-requests/create`
**Access:** Admin, Superadmin, Supervisor, Team Leader

1. Select a **Brand** (required) — only configured brands appear
2. Optionally filter by Product, Region, Province, City, Age Range
3. The **live availability counter** updates as you change filters (debounced 400ms)
4. Enter the **Requested Quantity** (1–50,000)
5. Choose **Distribution Method**:
   - `equal` — split equally among agents
   - `manual_quantity` — admin assigns specific quantities per agent
   - `round_robin` — distribute one-by-one in rotation
6. Click **Submit for Approval**

The system snapshots the current available count at submission time for audit purposes.

### Step 2: Admin Approval

**URL:** `/telesales/pool-approvals`
**Access:** Admin, Superadmin only

The approval queue shows all pending requests with:

- Requested quantity
- Available count **at request time** (snapshot)
- **Currently available** count (live recalculation)
- **Can Fulfill?** YES/NO indicator

#### Approving a Request

1. Click the eye icon to review the request
2. The approval page shows:
   - Side-by-side comparison: at-request vs. current availability
   - Adjustable **Approved Quantity** (can approve less than requested)
3. Click **Approve & Reserve** — this:
   - Opens a database transaction
   - Locks candidate lead rows (`SELECT ... FOR UPDATE`)
   - Creates a **Lead Pool** with a unique pool number (e.g., `POOL-20260821-0001`)
   - Creates **Pool Members** (one per reserved lead)
   - All pool members start with `PENDING` status
4. You're redirected to the new pool's detail page

#### Rejecting a Request

1. Click **Reject** on the approval page
2. Enter a **Rejection Reason** (required)
3. The requester is notified via the request status

### Step 3: View Lead Pools

**URL:** `/telesales/pools`
**Access:** Admin, Superadmin, Supervisor, Team Leader

Shows all approved pools with:

- Pool number, brand, region
- Reserved / Distributed / Remaining counts
- Status: `READY` → `ACTIVE` → `PARTIALLY_DISTRIBUTED` → `FULLY_DISTRIBUTED` → `COMPLETED`

Click a pool to see its **members** (individual leads) with:

- Lead name, phone, product, city
- Member status: PENDING, ASSIGNED, REMOVED, SKIPPED
- Added/assigned timestamps

### Step 4: Agent Self-Pull (Restricted)

**URL:** `/agent/leads`
**Access:** Agent role only

**IMPORTANT CHANGE:** Agents can no longer pull arbitrary leads from the global pool. They can **only** pull from pending members of **approved, active pools** that match their product skills.

#### How Agent Pull Works

1. Agent clicks "Request Leads" on their portal
2. System finds pending pool members from active pools
3. Filters by the agent's `product_skills` (from their AgentProfile)
4. Respects the agent's `max_active_cycles` limit (default: 10)
5. Assigns leads and marks pool members as `ASSIGNED`
6. Pool's `distributed_quantity` increments
7. Pool status updates to `PARTIALLY_DISTRIBUTED`

#### Why an Agent Can't Pull Leads

| Reason                                      | How to Fix                                                |
| ------------------------------------------- | --------------------------------------------------------- |
| No approved pools exist                     | Admin must approve a pool request first                   |
| Pool has no PENDING members                 | All leads already distributed — create a new pool request |
| Agent's product skills don't match any pool | Update agent's `product_skills` in AgentProfile           |
| Agent at max_active_cycles (10)             | Agent must close/sell existing leads first                |
| Agent not marked `is_available`             | Toggle availability on the agent portal                   |

---

## Test Accounts

| Role        | Email                 | Password    | Portal                    |
| ----------- | --------------------- | ----------- | ------------------------- |
| Super Admin | `admin@test.dev`      | `Test1234!` | All pages                 |
| Supervisor  | `supervisor@test.dev` | `Test1234!` | Pool Requests, Lead Pools |
| Agent       | `agent@test.dev`      | `Test1234!` | `/agent/leads`            |

**Agent Profile:**

- Product Skills: `["Black Garlic", "Barley"]`
- Max Active Cycles: 10
- Is Available: true

---

## Complete Walkthrough: From Request to Distribution

### As Admin (one-person testing)

1. **Log in** as `admin@test.dev`
2. Go to **Lead Inventory** (`/telesales/inventory`) — check available leads
3. Go to **Pool Requests** (`/telesales/pool-requests`) → click **New Pool Request**
4. Select brand "Black Garlic", set quantity to 5, click **Submit for Approval**
5. Go to **Pool Approvals** (`/telesales/pool-approvals`) — your request appears
6. Click the eye icon → review → click **Approve & Reserve**
7. You're redirected to the new **Lead Pool** page — see 5 reserved members
8. Log out, log in as `agent@test.dev`
9. On the **My Leads** page, click **Request Leads**
10. The agent pulls from the approved pool — leads appear on their portal
11. Log back in as admin → go to the pool → see `distributed_quantity` increased

### Key Points

- **Leads are only reserved during approval**, not at request time
- **The availability counter is live** — it recalculates before approval to prevent over-promising
- **Row-level locking** (PostgreSQL `SELECT ... FOR UPDATE`) prevents concurrent approvals from double-reserving the same lead
- **Partial unique index** on `lead_pool_members(lead_id) WHERE status IN ('PENDING','ASSIGNED')` enforces that a lead can only be in one active pool at a time
- **Agents cannot bypass the pool** — the self-pull endpoint only queries `LeadPoolMember` records, not the global lead pool

---

## Page Reference

| Page                    | URL                               | Roles                          |
| ----------------------- | --------------------------------- | ------------------------------ |
| Lead Inventory          | `/telesales/inventory`            | Admin, Supervisor              |
| Pool Requests List      | `/telesales/pool-requests`        | Admin, Supervisor, Team Leader |
| Create Pool Request     | `/telesales/pool-requests/create` | Admin, Supervisor, Team Leader |
| Pool Request Detail     | `/telesales/pool-requests/{id}`   | Admin, Supervisor, Team Leader |
| Pool Approval Queue     | `/telesales/pool-approvals`       | Admin only                     |
| Pool Approval Detail    | `/telesales/pool-approvals/{id}`  | Admin only                     |
| Lead Pools List         | `/telesales/pools`                | Admin, Supervisor, Team Leader |
| Lead Pool Detail        | `/telesales/pools/{id}`           | Admin, Supervisor, Team Leader |
| Agent Portal (My Leads) | `/agent/leads`                    | Agent                          |
| Agent Dashboard         | `/agent/dashboard`                | Agent                          |

---

## API Endpoints

| Method | URL                                       | Purpose                              |
| ------ | ----------------------------------------- | ------------------------------------ |
| GET    | `/telesales/inventory/count`              | Get eligible lead count with filters |
| GET    | `/telesales/pool-requests/eligible/count` | Live count for pool request form     |
| POST   | `/telesales/pool-requests`                | Create pool request                  |
| POST   | `/telesales/pool-requests/{id}/cancel`    | Cancel a request                     |
| POST   | `/telesales/pool-approvals/{id}/approve`  | Approve & reserve leads              |
| POST   | `/telesales/pool-approvals/{id}/reject`   | Reject a request                     |
| POST   | `/telesales/pools/{id}/cancel`            | Cancel an active pool                |
| POST   | `/agent/leads/request`                    | Agent self-pull from approved pools  |

---

## Troubleshooting

### "No leads available in the pool right now"

**Cause:** No approved pools have pending members matching the agent's product skills.

**Fix:**

1. Log in as admin
2. Create a pool request for the agent's product (e.g., "Black Garlic")
3. Approve the request
4. Agent can now pull leads

### "You already have N active leads. Finish some before requesting more."

**Cause:** Agent has reached their `max_active_cycles` limit (default 10).

**Fix:**

- Agent must close existing leads (mark as sold, rejected, or callback)
- Or increase the agent's `max_active_cycles` in their AgentProfile

### "Insufficient eligible leads"

**Cause:** The live availability check found fewer eligible leads than the approved quantity.

**Fix:**

- Reduce the approved quantity on the approval page
- Or import more leads / wait for more waybills to be delivered

### Old leads (waybills > 60 days) not showing

**Cause:** The 60-day max age rule filters them out.

**Fix:**

```bash
php artisan tinker --execute='App\Models\SystemSetting::set("telesales_max_waybill_age_days", 90, "telesales", "int");'
```

### Leads assigned but not visible on agent portal

**Fixed:** This was a bug where `AgentLeadResource::collection()` wrapped leads in a `data` key, but the frontend expected a flat array. Fixed by calling `->resolve()` on the resource collection.
