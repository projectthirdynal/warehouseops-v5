# Enterprise Inventory Management System (EIMS) — Design Spec
**Date:** 2026-04-22  
**Project:** WarehouseOps v5  
**Stack:** Laravel 11 + React 18 + TypeScript + PostgreSQL + Redis  

---

## 1. Overview

A centralized, real-time inventory system covering sellable goods, consumable supplies, and company assets — fully integrated with QuickBooks Online for automated financial recording. Built inside the existing DDD structure of WarehouseOps v5 as four new domain layers added incrementally.

**Objectives:**
- Real-time stock visibility across locations
- Full asset lifecycle and depreciation tracking
- Automated QuickBooks Online sync for every financial event
- Audit-ready append-only movement logs
- Formal procurement workflow (PR → PO → GRN)
- Scalable: single warehouse now, multi-warehouse ready

---

## 2. Build Phases

| Phase | Focus | Depends On |
|-------|-------|-----------|
| 1 | Core Inventory — catalog, stock, movements, locations | Nothing (foundation) |
| 2 | Procurement — PR, PO, GRN, suppliers, approval rules | Phase 1 |
| 3 | Finance — QuickBooks sync, COGS, COD, commissions | Phase 2 |
| 4 | Assets + Analytics — depreciation, reports, forecasting | Phase 3 |

Each phase is independently deployable and delivers value on its own.

---

## 3. User Roles & Permissions

Extends the existing `users.role` string field with four new values:

| Role | New? | Access Scope |
|------|------|-------------|
| `superadmin` | existing | Full control |
| `admin` | existing | Full control |
| `inventory_manager` | **new** | Stock control, adjustments, audit sessions, warehouse config |
| `warehouse_staff` | **new** | Stock movements, GRN receiving, location transfers |
| `procurement_officer` | **new** | PR/PO management, supplier management, receiving |
| `finance_officer` | **new** | QBO sync, COD settlements, commissions, COGS reports |
| `auditor` | **new** | Read-only access to all inventory and finance data + reports |
| `teamleader` | existing | Lead pool management (unchanged) |
| `agent` | existing | Agent portal only (unchanged) |
| `checker` | existing | Unchanged |
| `encoder` | existing | Unchanged |

---

## 4. Architecture

### 4.1 Domain Structure

Four new domains added to `app/Domain/`:

```
app/Domain/Inventory/     — stock, locations, movements, adjustments, audits
app/Domain/Procurement/   — suppliers, PR, PO, GRN, approval rules
app/Domain/Asset/         — asset registry, maintenance, depreciation
app/Domain/Finance/       — (existing, significantly expanded)
                            QBO connection, sync queue, COGS, settlements
```

### 4.2 Item Type Separation

Three distinct item types — separate tables, not a single polymorphic table:

| Type | Table | Description |
|------|-------|-------------|
| Sellable Goods | `products` (existing, extended) | What agents sell via COD |
| Consumable Supplies | `supplies` (new) | Packaging, office materials |
| Company Assets | `assets` (new) | Equipment, devices, furniture |

Sellable goods have: variants, selling price, QA workflow, COGS tracking.  
Supplies have: no selling price, no variants, simple stock in/out.  
Assets have: depreciation, maintenance schedule, assigned user, book value.

### 4.3 Stock Location Model

Every stock record and movement is location-aware from day one:

```
warehouses → warehouse_locations → product_stocks / supply_stocks
```

Single warehouse now, but adding a second warehouse requires only a new row — no schema change.

### 4.4 Financial Event Flow

```
Inventory Event          → EIMS Record           → QBO Sync (async)
────────────────────────────────────────────────────────────────────
GRN confirmed            → inventory_movement     → Bill (against PO)
Stock adjustment (write) → stock_adjustment       → Inventory Adjustment
Sale delivered (COGS)    → cogs_entry             → Journal Entry
COD settlement received  → cod_settlement         → Deposit
Commission paid          → agent_commission       → Expense
Asset purchased (via PO) → asset record           → Fixed Asset
Monthly depreciation     → asset_depreciation     → Journal Entry
```

All QBO pushes are **async via Redis queue** — never blocks the UI. Failed syncs are retried 3× with exponential backoff, then surface in the Finance dashboard for manual retry.

---

## 5. Phase 1 — Core Inventory

### 5.1 Database Changes

**Extend existing `products` table:**
```sql
barcode          VARCHAR nullable
qr_code          VARCHAR nullable  
uom_id           FK → units_of_measure nullable
min_stock_level  INTEGER default 0
max_stock_level  INTEGER nullable
expiry_tracking  BOOLEAN default false
```

**Extend existing `product_stocks` table:**
```sql
warehouse_id     FK → warehouses
location_id      FK → warehouse_locations nullable
-- NOTE: available_stock is NOT a generated column (see fix #3 below)
-- Exposed as an Eloquent accessor: current_stock - reserved_stock
```

**Extend existing `inventory_movements` table:**
```sql
warehouse_id     FK → warehouses nullable
location_id      FK → warehouse_locations nullable
to_location_id   FK → warehouse_locations nullable (for transfers)
batch_number     VARCHAR nullable
expiry_date      DATE nullable
approved_by      FK → users nullable
approved_at      TIMESTAMP nullable
```

**New tables:**

```sql
-- Reference data
units_of_measure (id, name, abbreviation)   -- pieces, boxes, kg, liters
warehouses (id, name, code, address, is_active)
warehouse_locations (id, warehouse_id, code, name, type[BIN/SHELF/ZONE], capacity, is_active)

-- Supplies (consumables)
supplies (id, sku, name, category, uom_id, cost_price, min_stock_level,
          reorder_point, description, is_active, timestamps)
supply_stocks (id, supply_id, warehouse_id, location_id,
               current_stock, reserved_stock, reorder_point, timestamps)
supply_movements (id, supply_id, type, quantity, warehouse_id, location_id,
                  to_location_id, reference_type, reference_id,
                  batch_number, notes, performed_by, approved_by, timestamps)

-- Formal adjustments
stock_adjustments (id, product_id[nullable], supply_id[nullable], variant_id[nullable],
                   warehouse_id, location_id, reason_code, reason_notes,
                   quantity_before, quantity_after, variance,
                   status[PENDING/APPROVED/REJECTED], submitted_by,
                   approved_by, approved_at, timestamps)

-- Physical count
stock_audit_sessions (id, warehouse_id, name, status[OPEN/COUNTING/FINALIZED],
                      started_by, finalized_by, started_at, finalized_at, notes)
stock_audit_items (id, session_id, product_id[nullable], supply_id[nullable],
                   variant_id[nullable], location_id, system_qty, counted_qty,
                   variance, status[PENDING/COUNTED/APPROVED], notes)

-- FIFO cost lot tracking (fix #1)
-- Created on every GRN confirmation; consumed FIFO on delivery
stock_cost_lots (
    id                 BIGSERIAL PRIMARY KEY,
    product_id         BIGINT NOT NULL REFERENCES products(id),
    variant_id         BIGINT NULL REFERENCES product_variants(id),
    warehouse_id       BIGINT NOT NULL REFERENCES warehouses(id),
    grn_item_id        BIGINT NOT NULL REFERENCES receiving_report_items(id),
    quantity_received  DECIMAL(12,4) NOT NULL,
    quantity_remaining DECIMAL(12,4) NOT NULL,  -- decremented on each sale
    unit_cost          DECIMAL(12,4) NOT NULL,
    currency_code      CHAR(3) NOT NULL DEFAULT 'PHP',
    exchange_rate      DECIMAL(14,6) NOT NULL DEFAULT 1.0,
    received_at        TIMESTAMP NOT NULL,
    expiry_date        DATE NULL,
    batch_number       VARCHAR NULL,
    timestamps
    -- INDEX on (product_id, variant_id, warehouse_id, received_at) WHERE quantity_remaining > 0
)

-- Reservation tracking (fix #4) — replaces bare counter-only approach
-- product_stocks.reserved_stock remains as a fast-read cache, kept in sync with this table
stock_reservations (
    id               BIGSERIAL PRIMARY KEY,
    product_id       BIGINT NOT NULL REFERENCES products(id),
    variant_id       BIGINT NULL REFERENCES product_variants(id),
    warehouse_id     BIGINT NOT NULL REFERENCES warehouses(id),
    quantity         INTEGER NOT NULL,
    reference_type   VARCHAR NOT NULL,  -- 'order', 'lead', 'cart'
    reference_id     BIGINT NOT NULL,
    reserved_by      BIGINT REFERENCES users(id),
    reserved_at      TIMESTAMP NOT NULL,
    expires_at       TIMESTAMP NOT NULL,
    status           VARCHAR NOT NULL,  -- ACTIVE, CONSUMED, RELEASED, EXPIRED
    released_at      TIMESTAMP NULL,
    released_reason  VARCHAR NULL,
    timestamps
    -- INDEX on (status, expires_at) WHERE status = 'ACTIVE'
)

-- Reference tables (fix #7 — multi-currency readiness)
currencies (code CHAR(3) PK, name, symbol, decimal_places, is_active)
exchange_rates (id, from_currency, to_currency, rate DECIMAL(14,6),
                rate_date DATE, source[manual/bsp/openexchangerates],
                UNIQUE(from_currency, to_currency, rate_date))
```

### 5.2 Service Layer

**`App\Domain\Inventory\Services\StockService`**
- `stockIn(product, quantity, location, reference, batch, expiry, unitCost, currency)` — validates, writes movement, creates `stock_cost_lots` row, updates stock; uses `SELECT FOR UPDATE` on stock row
- `stockOut(product, quantity, location, reference)` — uses `SELECT FOR UPDATE`; blocks if insufficient available stock
- `transfer(product, quantity, fromLocation, toLocation)` — atomic DB transaction: out + in
- `reserve(product, referenceType, referenceId, quantity, expiresAt)` — atomic conditional UPDATE: `SET reserved_stock = reserved_stock + qty WHERE (current_stock - reserved_stock) >= qty`; checks affected rows; creates `stock_reservations` row; throws `InsufficientStockException` if 0 rows affected
- `release(stockReservation)` — marks reservation RELEASED, decrements `reserved_stock` atomically
- `adjust(stockAdjustment)` — uses `SELECT FOR UPDATE`; applies approved adjustments, writes ADJUSTMENT movement

**Concurrency rules (fix #2):**
- `reserve()` uses atomic conditional UPDATE (Option B) — lock-free, high throughput, safe under concurrency
- `stockOut()` and `adjust()` use `SELECT FOR UPDATE` (Option A) — lower frequency, complex logic requires explicit lock
- Every method that reads-then-writes MUST be inside a `DB::transaction()`
- `available_stock` is never read from a column — always computed as `current_stock - reserved_stock` at query time or via Eloquent accessor (fix #3)

**`App\Domain\Finance\Services\CogsService`** (fix #1 + #6)
- `record(product, variant, quantity, waybillId)` — consumes `stock_cost_lots` FIFO (ordered by `received_at ASC`; FEFO for expiry-tracked items ordered by `expiry_date ASC`); uses `SELECT FOR UPDATE` on lot rows; writes one `cogs_entries` row per lot touched; locks COGS method in `finance_settings` on first call

**`App\Jobs\ReleaseExpiredReservationsJob`** (fix #4)
- Runs every 5 minutes via Laravel scheduler
- Finds `stock_reservations` with `status = ACTIVE AND expires_at < NOW()`
- Transitions each to EXPIRED, decrements `product_stocks.reserved_stock` atomically
- Expiry policy: 30 min for cart, 24 hours for confirmed-unpaid orders (configurable in `finance_settings`)

**`App\Domain\Inventory\Services\AuditService`**
- `openSession(warehouse)` — creates session, snapshots current system quantities
- `recordCount(session, item, counted_qty)` — records physical count
- `computeVariances(session)` — compares counted vs system
- `finalizeSession(session)` — applies all variances as ADJUSTMENT movements

**Business rules enforced in StockService (not in controllers):**
- Stock cannot go negative — throws `InsufficientStockException`
- `inventory_movements` is append-only — no updates, no deletes ever
- Adjustments over configurable variance threshold require approval before applying
- All writes are wrapped in DB transactions

### 5.3 UI Pages

| Page | Role Access | Description |
|------|------------|-------------|
| `Inventory/Dashboard` | all inventory roles | Stock value, low stock, recent movements |
| `Products/Index` | all | Catalog with live stock levels |
| `Products/Create` & `Show` | inventory_manager | Product form, movement history |
| `Supplies/Index`, `Create`, `Show` | inventory_manager | Supply catalog and stock |
| `Inventory/Movements` | all inventory roles | Filterable, exportable movement log |
| `Inventory/Adjustments` | inventory_manager | Submit and approve adjustments |
| `Inventory/AuditSessions` | inventory_manager | Physical count workflow |
| `Warehouses/Index` | superadmin, admin | Warehouse and location setup |

---

## 6. Phase 2 — Procurement

### 6.1 Database

```sql
suppliers (id, name, code, contact_person, email, phone, address,
           payment_terms, lead_time_days, is_active, qbo_vendor_id, timestamps)

purchase_requests (id, pr_number[PR-YYYYMMDD-XXXX], requested_by, department,
                   reason, priority[LOW/NORMAL/URGENT], needed_by_date,
                   status[DRAFT/SUBMITTED/APPROVED/CONVERTED/REJECTED/CANCELLED],
                   approved_by, approved_at, rejected_reason, timestamps)

purchase_request_items (id, pr_id, product_id[null], supply_id[null],
                        variant_id[null], uom_id, quantity_requested,
                        unit_price_estimate, notes)

purchase_orders (id, po_number[PO-YYYYMMDD-XXXX], pr_id[nullable], supplier_id,
                 warehouse_id, payment_terms, expected_delivery_date,
                 status[DRAFT/SENT/PARTIALLY_RECEIVED/RECEIVED/CANCELLED],
                 currency_code CHAR(3) DEFAULT 'PHP',    -- fix #7
                 exchange_rate DECIMAL(14,6) DEFAULT 1.0, -- fix #7
                 exchange_rate_date DATE NULL,             -- fix #7
                 subtotal, tax_amount, total_amount,
                 approved_by, approved_at, qbo_po_id, notes, timestamps)

purchase_order_items (id, po_id, product_id[null], supply_id[null],
                      variant_id[null], uom_id, quantity_ordered,
                      quantity_received, unit_price, tax_rate, line_total)

receiving_reports (id, grn_number[GRN-YYYYMMDD-XXXX], po_id, warehouse_id,
                   location_id, received_by, received_at,
                   exchange_rate DECIMAL(14,6) DEFAULT 1.0, -- fix #7: rate locked at receipt
                   exchange_rate_date DATE NULL,              -- fix #7
                   status[DRAFT/CONFIRMED], notes, discrepancy_notes, timestamps)

receiving_report_items (id, grn_id, po_item_id, quantity_received,
                        quantity_rejected, rejection_reason,
                        condition[GOOD/DAMAGED/EXPIRED],
                        batch_number, expiry_date)

approval_rules (id, module[PR/PO], role_required, min_amount,
                max_amount, is_active, timestamps)
```

### 6.2 Workflow

```
Staff creates PR (DRAFT) → submits (SUBMITTED)
    ↓
approval_rules check:
    amount ≤ threshold → auto-APPROVED
    amount > threshold → notify approver role → APPROVED or REJECTED
    ↓
Procurement Officer converts approved PR → PO (DRAFT)
    selects supplier, sets prices, sends to supplier (SENT)
    ↓
Warehouse Staff creates GRN against open PO:
    records quantity received per line
    marks condition (GOOD/DAMAGED/EXPIRED)
    confirms GRN → StockService::stockIn() fires
    PO → PARTIALLY_RECEIVED or RECEIVED
    ↓
Finance Officer sees confirmed GRN → triggers QBO Bill (Phase 3)
```

### 6.3 UI Pages

| Page | Role Access |
|------|------------|
| `Procurement/Requests` | all staff + procurement_officer (create), admin (approve) |
| `Procurement/Orders` | procurement_officer, admin |
| `Procurement/Receiving` | warehouse_staff, procurement_officer |
| `Suppliers/Index` & `Show` | procurement_officer, admin |
| `Settings/ApprovalRules` | superadmin, admin |

---

## 7. Phase 3 — Finance & QuickBooks Online

### 7.1 QuickBooks OAuth Setup

- Admin connects once via OAuth 2.0 at `/settings/quickbooks/connect`
- Tokens stored encrypted in `qbo_connections` table
- Auto-refresh 10 minutes before expiry via scheduled job
- Sandbox and Production environments supported
- Connection status visible on Finance dashboard

### 7.2 Database

```sql
qbo_connections (id, realm_id, access_token[encrypted], refresh_token[encrypted],
                 expires_at, environment[SANDBOX/PRODUCTION],
                 connected_by, connected_at, timestamps)

qbo_sync_queue (id, entity_type, entity_id, operation[CREATE/UPDATE/DELETE],
                idempotency_key UUID NOT NULL DEFAULT gen_random_uuid(), -- fix #5: unique per queue row, never changes across retries
                status[PENDING/SYNCED/FAILED], qbo_id, payload[json],
                error_message, attempts, synced_at, timestamps
                UNIQUE INDEX on idempotency_key)

qbo_account_mappings (id, mapping_key, qbo_account_id, qbo_account_name,
                      mapped_by, timestamps)
  -- mapping_keys: inventory_asset, cogs, accounts_payable,
  --               shipping_expense, commission_expense, revenue

cogs_entries (id, product_id, variant_id, waybill_id[null], order_id[null],
              cost_lot_id[null REFERENCES stock_cost_lots],  -- fix #1: lot traceability
              method[FIFO/WEIGHTED_AVG], quantity, unit_cost, total_cost,
              currency_code CHAR(3) DEFAULT 'PHP',            -- fix #7
              exchange_rate DECIMAL(14,6) DEFAULT 1.0,        -- fix #7
              recorded_at, synced_to_qbo_at)

-- Finance configuration + COGS method lock (fix #6)
finance_settings (id, key VARCHAR UNIQUE, value JSONB,
                  locked_at TIMESTAMP NULL, locked_by FK users NULL,
                  locked_trigger_reference VARCHAR NULL, timestamps)
-- Seeded rows:
--   ('cogs_method',              '{"method": "FIFO"}')
--   ('default_currency',         '{"code": "PHP"}')
--   ('fiscal_year_start_month',  '{"month": 1}')
--   ('reservation_expiry_cart',  '{"minutes": 30}')
--   ('reservation_expiry_order', '{"hours": 24}')
```

### 7.3 Sync Map

| EIMS Event | QBO Entity | Trigger |
|-----------|-----------|---------|
| Supplier created | Vendor | Real-time |
| Product created | Inventory Item | Real-time |
| PO approved | Purchase Order | On approval |
| GRN confirmed | Bill | On GRN confirmation |
| Stock write-off adjustment | Inventory Adjustment | On approval |
| Sale delivered (COGS) | Journal Entry (COGS dr, Inventory cr) | On waybill DELIVERED |
| COD settlement received | Bank Deposit | On manual recording |
| Commission paid | Expense | On mark-paid |
| Asset purchased | Fixed Asset | On asset creation from GRN |
| Monthly depreciation | Journal Entry | Scheduled 1st of month |

### 7.4 COGS Method

Default: **FIFO** — on delivery, `CogsService` queries `stock_cost_lots` ordered by `received_at ASC` (or `expiry_date ASC` for expiry-tracked items, i.e. FEFO), walks lots decrementing `quantity_remaining` with `SELECT FOR UPDATE` until the sale quantity is consumed, writes one `cogs_entries` row per lot with `cost_lot_id` for full traceability.

Weighted average: maintained in a `product_cost_averages` table (running avg per product/variant/warehouse), updated on each GRN — simpler but less granular.

**Method lock (fix #6):** Configured in `finance_settings.key = 'cogs_method'`. Lock activates on the first `cogs_entries` insert — `CogsService::record()` checks `locked_at`, sets it if null, then proceeds. Switching method after lock requires superadmin with explicit override reason written to an audit log. Cannot switch mid-fiscal-year without restatement.

### 7.5 Sync Reliability

- Every syncable event dispatches `QboSyncJob` to Redis queue — UI never waits
- Retry: 3 attempts with exponential backoff (1min, 5min, 15min)
- After 3 failures: status → FAILED, surfaces in Finance/QuickBooks page
- Finance Officer can manually retry individual items or trigger full reconciliation
- Nightly `QboReconciliationJob`: cross-checks EIMS totals vs QBO, flags discrepancies

**Idempotency (fix #5):** Every `QboSyncJob` passes `qbo_sync_queue.idempotency_key` as the `RequestId` query parameter on every QBO write call. The key is generated once at queue-row creation and never changes across retries. QBO returns the same resource on repeat calls with the same `RequestId` — duplicate Bills/Journal Entries are impossible. For the rare QBO endpoints that don't support `RequestId`, `QboSyncJob` checks for an existing `qbo_id` on the queue row before POSTing.

### 7.6 UI Pages

| Page | Role Access |
|------|------------|
| `Finance/Dashboard` | finance_officer, admin |
| `Finance/QuickBooks` | finance_officer, admin (sync status, retry) |
| `Finance/AccountMappings` | admin only |
| `Finance/CodSettlements` | finance_officer |
| `Finance/Commissions` | finance_officer, admin |
| `Finance/CostOfGoods` | finance_officer, auditor |

---

## 8. Phase 4 — Asset Management & Analytics

### 8.1 Asset Database

```sql
asset_categories (id, name, depreciation_method[STRAIGHT_LINE/DECLINING_BALANCE],
                  useful_life_months, salvage_value_percent)

assets (id, asset_number[AST-XXXX], name, description, category_id,
        assigned_to[FK users], warehouse_id, location_id,
        status[ACTIVE/UNDER_REPAIR/DISPOSED/LOST],
        purchase_date, purchase_cost, supplier_id, po_id[null],
        currency_code CHAR(3) DEFAULT 'PHP',     -- fix #7
        exchange_rate DECIMAL(14,6) DEFAULT 1.0, -- fix #7
        warranty_expiry, expected_useful_life_months,
        current_book_value, salvage_value,
        barcode, qr_code, serial_number,
        qbo_asset_id, timestamps, soft_deletes)

asset_movements (id, asset_id, from_location_id, to_location_id,
                 from_user_id, to_user_id, moved_by, reason, moved_at)

asset_maintenance (id, asset_id, type[SCHEDULED/REPAIR/INSPECTION],
                   scheduled_at, completed_at, cost,
                   performed_by, vendor, notes)

asset_depreciations (id, asset_id, period[YYYY-MM],
                     depreciation_amount, book_value_before,
                     book_value_after, posted_to_qbo_at, timestamps)
```

### 8.2 Depreciation Job

Runs on the 1st of every month via Laravel scheduler:
1. Fetches all `ACTIVE` assets with remaining useful life
2. Calculates depreciation per method (straight-line or declining balance)
3. Writes `asset_depreciations` record
4. Updates `assets.current_book_value`
5. Dispatches `QboSyncJob` for journal entry (Depreciation Expense dr, Accumulated Depreciation cr)

### 8.3 Reports

**Inventory:**
- Stock Valuation Report (stock × cost per location)
- Movement Report (by date range, product, warehouse, type)
- Low Stock Report (below reorder point)
- Dead Stock Report (no movement in configurable days)
- Expiry Report (expiring within 30/60/90 days)

**Procurement:**
- Purchase Order Summary (by supplier, period, status)
- GRN Discrepancy Report (ordered vs received variances)
- Supplier Performance (lead times, fill rates, rejection rates)

**Finance:**
- COGS by Product / Period
- Inventory Valuation vs QBO reconciliation
- COD Settlement Tracker
- Commission Payroll Report
- P&L Summary (revenue - COGS - commissions - shipping costs)

**Assets:**
- Asset Register (full list with book values)
- Depreciation Schedule (projected per asset per month)
- Assets Under Repair / Due for Maintenance

**Forecasting (basic):**
- Reorder Forecast: avg daily consumption × supplier lead time
- Stock-out Risk: products projected to hit zero within 30 days

### 8.4 Analytics Dashboard

Main landing for inventory roles:
- Total inventory value at cost (live)
- Low stock count with drill-down list
- Pending PRs and POs awaiting action (count + list)
- Recent movements feed (last 20)
- COD pending settlement amount
- QBO sync health (last sync time, failed count)
- Asset maintenance due this month

---

## 9. Integration Points with Existing System

| Existing Feature | EIMS Integration |
|-----------------|-----------------|
| Waybill DELIVERED | Triggers COGS entry → QBO journal |
| Waybill RETURNED | Reverses COGS, triggers supply return movement |
| Agent Lead → ORDERED | Reserves stock via StockService::reserve() |
| Order CANCELLED | Releases reservation via StockService::release() |
| Agent Commission (existing) | Phase 3 syncs approved commissions to QBO as Expense |
| COD Settlement (existing) | Phase 3 syncs received settlements to QBO as Bank Deposit |
| GenerateLeadsFromUpload job | Reads delivered waybills — triggers COGS on bulk import |

---

## 10. Key Non-Functional Requirements

- **Stock writes are transactional** — DB transaction wraps every StockService call
- **inventory_movements is append-only** — no UPDATE or DELETE ever
- **QBO sync never blocks UI** — always async via Redis queue
- **Stock cannot go negative** — hard block in StockService via atomic conditional UPDATE (reserve) and SELECT FOR UPDATE (out/adjust)
- **No oversell under concurrency** — reservation uses atomic conditional UPDATE; affected-rows=0 throws InsufficientStockException (fix #2)
- **available_stock is computed, not stored** — Eloquent accessor `current_stock - reserved_stock`; never a DB generated column (fix #3)
- **Reservations are traceable and expire** — backed by `stock_reservations` table; `ReleaseExpiredReservationsJob` runs every 5 min (fix #4)
- **FIFO lot tracking** — `stock_cost_lots` records every received batch; CogsService consumes with SELECT FOR UPDATE (fix #1)
- **QBO idempotency** — every write passes `idempotency_key` as QBO RequestId; retries never create duplicates (fix #5)
- **COGS method locked once transactions exist** — `finance_settings` table with `locked_at`; enforced in CogsService::record() (fix #6)
- **Multi-currency schema-ready** — `currency_code` + `exchange_rate` on all procurement, COGS, and asset tables; default PHP/1.0 (fix #7)
- **Audit trail on everything** — movements, adjustments, approvals, lot consumption all timestamped with actor
- **Multi-warehouse ready** — warehouse_id on every stock record from Phase 1 day one

## 11. Deferred (Phase 5+)

- Full multi-currency UI — currency selector on PO, live exchange rate fetch from BSP/OpenExchangeRates, foreign-currency Bill posting to QBO
- RFID integration for asset tracking
- Advanced demand forecasting (ML-based)
- Mobile warehouse app (barcode scan stock movements)
