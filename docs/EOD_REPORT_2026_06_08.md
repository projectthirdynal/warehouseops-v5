# End of Day Report
**June 08, 2026** | IN: 8:00 AM · OUT: 5:11 PM | WarehouseOps System

---

## Done Today

### 1. Supply Duplicate SKU / Name Error Handling
- Added `unique:supplies,sku` and `unique:supplies,name` validation in `SupplyController` for both create and update paths
- Added inline error display in `resources/js/pages/Inventory/Supplies/Index.tsx` for `sku`, `name`, and `cost_price` fields
- Products already had this pattern — supplies were the gap; now consistent across both

### 2. Inventory Dashboard — Low Stock Materials Not Recognized
- `InventoryDashboardController::index()` was only querying product low stock, ignoring supplies entirely
- Rewrote the controller to pass all props the frontend `Dashboard.tsx` expected:
  - `supply_low_stock` — low stock supply detail rows
  - `recent_supply_movements` — last 20 supply movements
  - `expiring_lots` — lots expiring within 30 days
  - `movement_trend` + `supply_movement_trend` — 30-day daily bar chart data
  - `warehouse_stock_summary` — per-warehouse product count and stock value
  - `expiring_soon` + `pending_adjustments` — stats KPI fields

### 3. Dashboard Code Review — 4 Bugs Fixed
**Commit:** `3e3a0b7`

| # | Bug | Fix |
|---|---|---|
| 1 | `GREATEST()` is PostgreSQL-only — crashes SQLite CI | Replaced with ANSI `CASE WHEN ss.current_stock - ss.reserved_stock > 0 THEN ... ELSE 0 END` |
| 2 | Supply `STOCK_OUT` trend condition fragile — relied on negative qty sign assumption | Hardened to `type = 'STOCK_OUT' THEN ABS(quantity)` regardless of sign |
| 3 | `product_units` in warehouse summary counted stock rows, not distinct products | Changed to `COUNT(DISTINCT ps.product_id)` |
| 4 | Scan error toast hardcoded `"Product not found"` for all scan errors including 500s and auth failures | Now uses actual server `message` field |

*2 bugs confirmed non-issues after investigation (app timezone is UTC so `DATE()` is correct; `available_stock` accessor serialises correctly via `$appends`). 1 noted as intentional design (all-time pending count).*

### 4. Inventory Movements Always Empty — Root Cause Fix
**Commit:** `f00be06`

**Root cause:** `InventoryService` had **no `warehouseId` parameter on any of its 7 methods**. Every `InventoryMovement` record created from the product page (`adjustStock`, `stockIn`, `stockOut`, initial stock on creation) stored `warehouse_id = NULL`. `getOrCreateStock()` also lacked `warehouse_id` in the `firstOrCreate()` lookup key, silently creating a duplicate NULL-warehouse `ProductStock` row that diverged from the real warehouse rows used by Procurement/GRN.

**Files changed:**

- `app/Domain/Product/Services/InventoryService.php`
  - Added `?int $warehouseId = null` to all 7 methods: `stockIn`, `stockOut`, `reserve`, `release`, `confirmReservation`, `returnStock`, `adjustStock`
  - Each method resolves `NULL` via new `defaultWarehouseId()` helper (static-cached query on `Warehouse.is_default`)
  - `warehouse_id` now written to every `InventoryMovement::create()` call
  - `getOrCreateStock()` now includes `warehouse_id` in the `firstOrCreate()` lookup key — eliminates split NULL/real warehouse stock rows
  - Added `Warehouse` import

- `app/Http/Controllers/ProductController.php`
  - Added `Warehouse` import
  - `store()` — passes default warehouse ID when creating initial stock
  - `show()` — eager-loads `warehouse` relation on movements; passes `warehouses` list to view
  - `adjustStock()` — validates optional `warehouse_id` from request and threads it through to all `InventoryService` calls

- `resources/js/pages/Products/Show.tsx`
  - Added `Warehouse` interface and `warehouses` prop
  - Stock form now tracks `warehouse_id` (pre-selected to `is_default` warehouse)
  - Added warehouse `<select>` dropdown — user chooses target warehouse before posting a stock adjustment

- `app/Http/Controllers/InventoryDashboardController.php`
  - Added `Carbon` import
  - `movements()`: `?type=all` normalised to `null` (prevents `WHERE type = 'all'` returning zero rows)
  - `movements()`: `?stock=low` filter now handled — scopes to movements on low-stock products
  - `movements()`: `from`/`to` date filters now use `Carbon::startOfDay()` / `Carbon::endOfDay()` instead of raw string concat

---

## Commits Pushed

| Hash | Message |
|---|---|
| `3e3a0b7` | `fix: inventory dashboard review bugs (4 of 7 actionable)` |
| `f00be06` | `fix: inventory movements always empty - missing warehouse_id on all movement records` |

---

## Task List Status

### WarehouseOps Inventory (Today's Work)

| Task | Status |
|---|---|
| Supply duplicate SKU/name error handling | ✅ Done |
| Dashboard low stock materials recognition | ✅ Done |
| Dashboard code review — 4 bugs fixed | ✅ Done |
| Inventory movements empty — root cause fixed | ✅ Done |
| Products/Show warehouse selector | ✅ Done |

### Lead Distribution Refactor (Original Day Plan — Carry Over)

| Issue | Status |
|---|---|
| ISS 001 — AgentWorkload daily cap never resets | ⏳ Carry over |
| ISS 002 — False success count in autoDistribute | ⏳ Carry over |
| ISS 003 — Workload leak on cooldown / available transitions | ⏳ Carry over |
| ISS 004 — Recycling pool OR query ungrouped | ⏳ Carry over |
| ISS 005 — XLSX duplicate check misses XLSX_IMPORT source | ⏳ Carry over |
| ISS 009–011 — Enum cleanup, ASSIGNED guard, canRecycle() fix | ⏳ Carry over |
| Phase 3 — Architecture (N+1, cache key, transaction audit) | ⏳ Carry over |
| Phase 4 — Sprint 3 UI (Bulk Action Bar, Inline Editing, SkeletonTable, useToast) | ⏳ Carry over |

---

## Blockers

None.

---

## Carry Over to Tomorrow

### Priority 1 — Lead Distribution Critical Fixes
`app/Models/AgentWorkload.php`, `app/Http/Controllers/DistributionController.php`, `app/Services/LeadPoolService.php`, `app/Http/Controllers/LeadController.php`, `app/Services/TelesalesLeadImportService.php`

ISS 001–005 as defined in original task list.

### Priority 2 — Lead Distribution Code Quality
ISS 009, ISS 010, ISS 011, enum cleanup across stats queries.

### Priority 3 — Sprint 3 UI
- Bulk Action Bar — LeadPool table
- Inline Editing — LeadPool index (notes, product_name, city)
- SkeletonTable — Waybills, Inventory, Admin data tables
- useToast wired to CRUD — LeadPool operations first

### Backlog / Medium Issues
- ISS 014 — XLSX column hardcode
- ISS 015 — Uncached stats
- ISS 016 — Slot loss on race condition
- Auto Encode parsing fix
- HTTPS caching fix
- Inventory validation (carry over from previous sessions)
