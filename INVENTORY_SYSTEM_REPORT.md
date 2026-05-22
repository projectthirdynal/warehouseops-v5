# WarehouseOps — Inventory System Feature & Change Report
**Date:** May 20, 2026  
**Prepared by:** Cascade (AI Engineering Assistant)  
**Scope:** Full UI/UX Refactor + New Adjustment Reporting Module  
**Environment:** Production — `https://warehouseops.thirdynals.org`  
**Stack:** Laravel 11 · Inertia.js · React · TailwindCSS · shadcn/ui · Lucide Icons

---

## EXECUTIVE SUMMARY

Today's work delivered a complete refactor of the Inventory Management module across three existing pages and the creation of one new reporting page. All changes have been built and deployed to production.

| Page | Route | Change Type | Build Status |
|---|---|---|---|
| Inventory Dashboard | `/inventory` | Full refactor | ✅ Deployed |
| Inventory Movements | `/inventory/movements` | Full refactor | ✅ Deployed |
| Stock Adjustments | `/inventory/adjustments` | Full refactor | ✅ Deployed |
| Adjustment Report | `/inventory/adjustments/report` | **New page** | ✅ Deployed |
| CSV Export | `/inventory/adjustments/report/download` | **New endpoint** | ✅ Deployed |

---

## SECTION 1 — INVENTORY DASHBOARD (`/inventory`)

### Purpose
Command-center overview of all inventory health. Read-only. No user input needed. Auto-refreshes on page load.

### Who Uses It
Warehouse staff, supervisors, admins — anyone who needs a real-time inventory snapshot.

### Layout & Flow

```
┌─────────────────────────────────────────────────────────────┐
│  ALERT BAR  ← red/orange, only shown when critical issues   │
├─────────────────────────────────────────────────────────────┤
│  Header                    [Adjustments] [New PR] [New PO]  │
├──────────┬──────────┬──────────┬──────────────────────────┤
│ Products │ Materials│Warehouses│      Stock Value          │
├─────────────────────────────────────────────────────────────┤
│              Stock Health Bar (Red | Amber | Green)         │
├──────────┬──────────┬──────────┬────────────────────────── ┤
│ Low Stock│Low Matls │Pending   │  Expiring Soon (30 days)  │
│ Products │          │Adj.      │                           │
├──────────┴──────────┴──────────┴───────────────────────────┤
│   Pending PR Approvals      │    Open Purchase Orders      │
├─────────────────────────────┬───────────────────────────── ┤
│  30-Day Movement Chart (3/5)│  Recent Activity Feed  (2/5) │
├─────────────────────────────┴───────────────────────────── ┤
│  Low Stock Products Table   │  Expiring Lots / Movements   │
├─────────────────────────────────────────────────────────────┤
│              Stock by Warehouse Table                       │
├─────────────────────────────────────────────────────────────┤
│              Low Stock Materials Table                      │
├─────────────────────────────────────────────────────────────┤
│  Quick Actions: [New Adj] [New PR] [New PO] [Movements]    │
└─────────────────────────────────────────────────────────────┘
```

### Features — What Changed

#### 🆕 Critical Alert Bar
- Sticky red/orange banner at the very top
- Triggers when: any product is **out of stock** OR **pending adjustments** exist
- Out-of-stock alert → links to `/inventory/movements?stock=low`
- Pending adjustment alert → links to `/inventory/adjustments?status=PENDING`
- Completely hidden when all counts are zero — zero visual noise

#### ✏️ KPI Cards (4 cards)
- Left-border color accent: Blue · Purple · Emerald · Green
- Icon per metric (Package, Box, Warehouse, TrendingUp)
- Numbers formatted with `toLocaleString()` and `tabular-nums`
- Metrics: Active Products · Active Materials · Warehouses · Total Stock Value (currency)

#### 🆕 Stock Health Bar
- Segmented horizontal bar split into three colors:
  - 🔴 **Red** = out-of-stock items (available ≤ 0)
  - 🟡 **Amber** = low stock items (above zero but at/below reorder point)
  - 🟢 **Green** = healthy items (above reorder point)
- Legend row below the bar showing exact counts per category
- Link to full low-stock list

#### ✏️ Alert Cards (4 + 2)
- Tonal clickable cards — each card is a direct link to its filtered page
- Row 1 (4 cards): Low Stock Products · Low Stock Materials · Pending Adjustments · Expiring Soon
- Row 2 (2 cards): Pending PR Approvals · Open Purchase Orders
- Orange badge counter on the Adjustments header button when pending > 0 (pulsing)

#### 🆕 30-Day Movement Chart
- Inline column chart, one bar per day over the last 30 days
- 🟢 Green bars = Stock In | 🔴 Red bars = Stock Out
- Running totals shown in legend: `"+1,250 in" / "-430 out"`
- Hover tooltip per day showing exact date + in/out values

#### ✏️ Recent Activity Feed
- 8 most recent movements
- Colored dot + type pill + signed quantity per entry
- Monospace quantity: `+100` green, `-50` red
- "All →" link to full Movements page

#### ✏️ Low Stock Products Table
- Red row tint = out of stock (available ≤ 0)
- Amber row tint = low but above zero
- Clickable SKU links to product detail page
- Columns: Product (SKU + Name) · Location · Available · Reorder Point

#### 🆕 Expiring Lots Table
- Shows up when lots expiring within 30 days exist
- Red rows = 7 days or fewer remaining
- Days-left countdown per row (e.g. `"3d left"`)
- Falls back to Recent Movements table when no expiring lots

#### 🆕 Stock by Warehouse Table
- One row per active warehouse
- Columns: Warehouse · Code · Product Units · Stock Value (currency, emerald)

#### 🆕 Low Stock Materials Table
- Purple-tinted rows for supply/raw material items below reorder point
- Columns: Material (SKU + Name) · Warehouse · Available · Reorder Point
- Only shown when supply low stock items exist

#### 🆕 Quick Actions Footer
- Persistent action bar at the very bottom of the page
- ⚡ Zap icon prefix
- Buttons: New Adjustment · New PR · New PO · View Movements · Materials

---

## SECTION 2 — INVENTORY MOVEMENTS (`/inventory/movements`)

### Purpose
A complete, immutable ledger of every stock movement in the system. Every stock change — whether from an order, adjustment, transfer, return, or reservation — creates a row here. Rows are **never edited or deleted**.

### Who Uses It
Warehouse staff for verification; supervisors for auditing; admins for investigations.

### Layout & Flow

```
┌─────────────────────────────────────────────────────────────┐
│  Header: "Inventory Movements"  · 1,234 records             │
├─────────────────────────────────────────────────────────────┤
│  [Search product/SKU] [Type ▾] [From date] [To date]       │
│                              [Apply] [Clear]                │
├─────────────────────────────────────────────────────────────┤
│  Active filter pills: Type: Stock In ✕  Date: 05/01→05/20 ✕│
├─────────────────────────────────────────────────────────────┤
│  Table: When │ Type │ Product │ Warehouse │ Loc │ Qty │ ... │
│  (50 rows per page)                                         │
├─────────────────────────────────────────────────────────────┤
│  Showing 1–50 of 1,234    [←] [1][2][3]...[7][→]          │
└─────────────────────────────────────────────────────────────┘
```

### Features — What Changed

#### 🆕 Search Input
- Searches on product name and SKU simultaneously
- Magnifying glass icon prefix inside input
- **Enter key** triggers search (keyboard-accessible)
- Minimum width `180px`, flex-grows to fill available space

#### 🆕 Active Filter Pills
- Row of removable chips appears below filter bar only when filters are active
- Shows exactly which filters are set: `"Type: Stock In"`, `"Search: widget"`, `"Date: 05/01 → 05/20"`
- Each chip has an individual ✕ button to remove just that filter
- Entire row is hidden when no filters are active

#### ✏️ Record Count
- Page subtitle always shows live total: `"Append-only ledger — 1,234 records"`
- Updates reactively as filters change

#### ✏️ Type Badges
Human-readable labels replace raw enum values. Color-coded:

| Badge Color | Movement Type |
|---|---|
| 🟢 Emerald | Stock In |
| 🔴 Red | Stock Out |
| 🟡 Yellow | Adjustment |
| 🔵 Blue | Return |
| 🟣 Purple | Reservation |
| 🔷 Indigo | Release |

#### ✏️ Product Cell
- Two-line display: monospace blue SKU on top · product name below
- Renders `—` gracefully when product is null

#### ✏️ Quantity Column
- Signed and color-coded: `+100` in emerald · `-50` in red
- `tabular-nums` for alignment across rows
- `font-bold` for visual weight

#### 🆕 Empty State
- Centered icon (`ArrowUpDown`) + heading + subtext
- Context-aware message: if filters active → `"Try adjusting your filters or date range"`
- "Clear all filters" link-button shown when filters are active

#### ✏️ Pagination
- Prev ← / Next → icon buttons (disabled at limits)
- Sliding window: shows max 7 page buttons, centered around current page
- `"Showing X–Y of Z"` range label on the left side

---

## SECTION 3 — STOCK ADJUSTMENTS (`/inventory/adjustments`)

### Purpose
A managed workflow for correcting stock levels. Warehouse staff submit adjustments; authorized users (supervisors/admins) approve or reject them. **Stock levels only change on approval** — never on submission.

### Who Uses It
- **Submitter** (warehouse staff): Creates adjustment after physical count
- **Approver** (supervisor/admin): Reviews and approves or rejects
- **Viewer** (finance/reporting): Reads for audit trail

### Approval Workflow

```
[1] Warehouse staff submits adjustment
         │
         ▼
    Status = PENDING
    Stock level UNCHANGED
         │
         ▼
[2] Supervisor/Admin reviews
         │
    ┌────┴────┐
    ▼         ▼
 APPROVE    REJECT
    │         │
    ▼         ▼
Stock        No stock
updated      change
immediately  Notes
             appended
```

### Layout & Flow

```
┌─────────────────────────────────────────────────────────────┐
│  Header: "Stock Adjustments"  [Report] [New Adjustment]     │
├──────────────┬──────────────┬─────────────────────────────── ┤
│   PENDING    │   APPROVED   │         REJECTED               │
│  (orange)    │   (emerald)  │          (gray)                │
├─────────────────────────────────────────────────────────────┤
│  [Status ▾] [Warehouse ▾] [From date] [To date] [Clear]    │
├─────────────────────────────────────────────────────────────┤
│  ⚠ 3 adjustments pending approval  [Show pending →]         │
├─────────────────────────────────────────────────────────────┤
│  Table: Item │ Warehouse │ Reason │ Before │ After │ Var ... │
│  (25 rows per page, PENDING rows have orange tint)          │
├─────────────────────────────────────────────────────────────┤
│  Showing 1–25 of 47     [←][1][2]...[→]                    │
└─────────────────────────────────────────────────────────────┘
```

### Dialogs

**New Adjustment Dialog:**
- Item Type toggle (Finished Product / Raw Material Supply)
- Item selector (with SKU prefix in dropdown)
- Warehouse selector
- New Physical Quantity field
- Reason Code dropdown (9 codes)
- Notes textarea
- Amber notice: `"Stock levels only change upon approval"`
- Submit button shows `"Submitting…"` while processing

**Approve Confirmation Dialog:**
- Warning copy: `"This will immediately update stock levels. Cannot be undone."`
- Cancel + `"Yes, Approve"` button (emerald, with loading state)

**Reject Dialog:**
- Optional reason textarea with example placeholder
- Rejection notes are appended to the adjustment's existing notes

### Features — What Changed

#### ✏️ Stat Cards (3 cards)
- Left-border accent: Orange = Pending · Emerald = Approved · Gray = Rejected
- Large `tabular-nums` count per status
- All-time counts (not filtered by date)

#### 🆕 Pending Alert Banner
- Orange banner between filter bar and table
- Exact pending count: `"3 adjustments pending approval"`
- "Show pending" button applies `?status=PENDING` filter instantly
- Auto-hides when a status filter is already active

#### 🆕 Report Button
- `BarChart3` icon outline button in page header
- Links to `/inventory/adjustments/report` (today's report, no params needed)

#### 🆕 Variance Column with Direction Icons
| Icon | Meaning |
|---|---|
| `TrendingUp` (emerald) | Stock was increased |
| `TrendingDown` (red) | Stock was decreased |
| `Minus` (gray) | No change (variance = 0) |

Signed value: `+15` or `-8` beside the icon.

#### ✏️ Status Badge
- Dot + text pill for each status
- PENDING dot **pulses** (CSS animation) to draw attention
- Human-readable: `"Pending"` / `"Approved"` / `"Rejected"`

#### 🆕 Pending Row Tinting
- Rows with `status = PENDING` get a subtle `bg-orange-50/50` background
- Makes unreviewed rows immediately visible when scrolling a long list

#### ✏️ Approve/Reject Actions
- Approve → opens confirmation modal (replaces `window.confirm()`)
- Reject → opens dialog with reason textarea
- Both preserve scroll position after action completes

#### 🆕 Empty State
- Icon + "No adjustments found" message
- Filter-aware: `"Try clearing your filters"` vs `"Create a new adjustment"` CTA button

#### ✏️ Reason Code Display
- Pill badge with spaces instead of underscores: `"Cycle Count"` not `"CYCLE_COUNT"`
- Truncated notes shown below the badge (max 180px)

---

## SECTION 4 — ADJUSTMENT REPORT (`/inventory/adjustments/report`) — NEW

### Purpose
A dedicated analytics and reporting page for stock adjustments. Designed for end-of-day review, audit, management reporting, and compliance. Defaults to **today** — no parameters needed.

### Who Uses It
- **Supervisors** — review daily adjustment activity before shift close
- **Admins/Managers** — audit trail, pattern detection, approval oversight
- **Finance** — variance reporting for period-end reconciliation

### URL & Parameters

| URL | Result |
|---|---|
| `/inventory/adjustments/report` | Today's report (default) |
| `/inventory/adjustments/report?from=2026-05-01&to=2026-05-20` | Date range report |
| `/inventory/adjustments/report?status=APPROVED` | Approved only |
| `/inventory/adjustments/report?warehouse_id=3` | Single warehouse |
| `/inventory/adjustments/report?reason_code=DAMAGE` | Single reason code |
| `/inventory/adjustments/report/download` | CSV download (same filters) |

### Layout & Flow

```
┌─────────────────────────────────────────────────────────────┐
│  ⚠ PENDING BANNER  (if pending adjustments exist)           │
├─────────────────────────────────────────────────────────────┤
│  ← Adjustment Report  [Today]    [Today btn][CSV][All Adj]  │
│  Date: 2026-05-20  · 47 total records                       │
├─────────────────────────────────────────────────────────────┤
│  [From date] [To date] [Status ▾] [Warehouse ▾] [Reason ▾] │
├──────┬──────┬──────┬──────┬──────────────┬─────────────── ─┤
│Total │Pending│Apprvd│Rejectd│  +Units Added│  −Units Deducted│
├─────────────────────────────────────────────────────────────┤
│  Variance Direction Bar  (Green | Red | Gray)               │
├──────────────────────────────┬──────────────────────────────┤
│  By Reason Breakdown (3/5)   │  Hourly Activity Chart (2/5) │
│  - bar per reason code       │  - 24 columns, midnight→11pm │
│  - approved/pending/rejected │  (falls back to submitter    │
│  - net variance per code     │   list for date ranges)      │
├──────────────────────────────┬──────────────────────────────┤
│  By Warehouse Table          │  By Submitter Table          │
├─────────────────────────────────────────────────────────────┤
│  ⚠ PENDING ITEMS  (only if pending exist in period)         │
│  orange-tinted table of all unreviewed adjustments          │
│                                        [Manage →]           │
├─────────────────────────────────────────────────────────────┤
│  ⚡ HIGH-IMPACT ADJUSTMENTS  (top 20 by variance size)       │
│  approved only, sorted by ABS(variance) descending          │
├─────────────────────────────────────────────────────────────┤
│  FULL DRILL-DOWN TABLE                        [Export CSV]  │
│  (paginated, 50/page, all adjustments in period)            │
├─────────────────────────────────────────────────────────────┤
│  Showing 1–50 of 47     [←][1][2]...[→]                    │
└─────────────────────────────────────────────────────────────┘
```

### Data Sections — Explained

#### KPI Tiles (6 tiles, left-border accent)
| Tile | Accent | What It Shows |
|---|---|---|
| Total | Gray | All adjustments in the period |
| Pending | Orange (pulsing) | Awaiting approval |
| Approved | Green | Completed and stock updated |
| Rejected | Red | Declined, no stock change |
| Units Added | Emerald | Sum of positive variance (approved only) |
| Units Deducted | Rose | Sum of absolute negative variance (approved only) |

#### Variance Direction Bar
- Segmented horizontal bar (full width)
- Green proportion = `positive_count / total`
- Red proportion = `negative_count / total`
- Gray remainder = `zero_count`
- Legend: `"Stock added: 12 · Stock reduced: 8 · No change: 3"`
- Far right: `"Total units moved (approved): 2,340"`

#### By Reason Code Breakdown
- One row per reason code used in the period, sorted by frequency
- Horizontal mini-bar relative to the most-used code
- Per-row stats: Count · ✓ Approved · ⏳ Pending · ✗ Rejected · Net Variance
- Net variance colored green (positive) or red (negative)

#### Hourly Activity Chart (single-day view)
- 24-column bar chart from 12 AM to 11 PM
- Dark blue = approved in that hour · Light blue = pending/other
- Hover tooltip: `"14:00 — 5 total, 3 approved"`
- **Multi-day view:** replaces chart with Submitter Summary list

#### By Warehouse Table
| Column | Description |
|---|---|
| Warehouse | Name + code |
| Total | All adjustments |
| Approved | Approved count |
| +Added | Sum of positive variance (approved) |
| −Deducted | Sum of negative variance absolute (approved) |

#### By Submitter Table
| Column | Description |
|---|---|
| Staff | Submitter name |
| Submitted | Total submitted |
| Approved | How many of theirs got approved |
| Rejected | How many were rejected |
| Pending | Outstanding — highlighted orange when > 0 |

#### Pending Items Panel
- Only rendered when pending adjustments exist in the period
- Orange-tinted table rows
- Shows: Item · Warehouse · Reason · Before · After · Variance · Submitted by · Time
- "Manage →" button links to `/inventory/adjustments?status=PENDING`

#### High-Impact Adjustments
- Top 20 approved adjustments by `ABS(variance)` — the biggest stock changes
- Columns: Item · Warehouse · Reason + Notes · Before · After · Variance · Submitted by · Approved by · Approved at
- Useful for quickly identifying unusual or significant corrections

#### Full Drill-Down Table
- Every adjustment in the period, paginated 50/page
- Item type badge: Blue = Product · Purple = Supply
- Full column set with status badge, variance icon, and all metadata
- Second "Export CSV" button at the top-right of this section

### CSV Export
**URL:** `/inventory/adjustments/report/download?from=2026-05-20&to=2026-05-20`  
**Filename:** `adjustment_report_20260520_20260520.csv`

**Columns (15):**
```
ID | SKU | Item | Type | Warehouse | Reason Code | Qty Before |
Qty After | Variance | Status | Submitted By | Approved By |
Created At | Approved At | Notes
```

---

## SECTION 5 — BACKEND CHANGES

### `app/Http/Controllers/StockAdjustmentController.php`

#### New Method: `report(Request $request)`
**Logic:**  
Builds a reusable `$base` closure over the `stock_adjustments` table with LEFT JOINs to `products`, `supplies`, `warehouses`, `users` (submitter), `users` (approver). Applies date range + optional status/warehouse/reason filters.  

Runs 8 queries from the base:

| Query | Purpose |
|---|---|
| `$summary` | Aggregate KPIs via `selectRaw` with `CASE WHEN` |
| `$byReason` | GROUP BY `reason_code` |
| `$byWarehouse` | GROUP BY warehouse |
| `$bySubmitter` | GROUP BY submitter user |
| `$byHour` | GROUP BY `EXTRACT(HOUR)` — single-day only, else empty collection |
| `$topImpact` | APPROVED only, ORDER BY `ABS(variance) DESC`, LIMIT 20 |
| `$pendingRows` | WHERE `status = 'PENDING'`, all columns |
| `$rows` | Full table, paginated 50/page |

Returns `Inertia::render('Inventory/AdjustmentReport', [...])`.

#### New Method: `downloadReport(Request $request): StreamedResponse`
- Runs same query without pagination
- `response()->streamDownload()` with CSV fputcsv
- Returns with `Content-Type: text/csv` header

#### New Imports Added
```php
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\StreamedResponse;
```

---

### `routes/web.php`

Added inside `Route::prefix('adjustments')` group:

```php
Route::get('/report',          [StockAdjustmentController::class, 'report'])
    ->name('report');
Route::get('/report/download', [StockAdjustmentController::class, 'downloadReport'])
    ->name('report.download');
```

Both inherit the parent group middleware: `auth` + `role:superadmin,admin,supervisor,warehouse`

---

## SECTION 6 — FRONTEND FILES

| File | Action | Description |
|---|---|---|
| `resources/js/pages/Inventory/Dashboard.tsx` | Modified | Full layout refactor |
| `resources/js/pages/Inventory/Movements.tsx` | Modified | Search, pills, pagination |
| `resources/js/pages/Inventory/StockAdjustments.tsx` | Modified | Approval UX, report link |
| `resources/js/pages/Inventory/AdjustmentReport.tsx` | **Created** | New reporting page |

---

## SECTION 7 — REASON CODE REFERENCE

| Code | Label | When to Use |
|---|---|---|
| `CYCLE_COUNT` | Cycle Count | Scheduled partial count variance |
| `PHYSICAL_COUNT` | Physical Count | Full warehouse physical count result |
| `DAMAGE` | Damage | Items physically damaged or broken |
| `EXPIRED` | Expired | Items past their expiry date |
| `THEFT` | Theft | Missing items, suspected theft/shrinkage |
| `SYSTEM_ERROR` | System Error | Correcting a data entry or system mistake |
| `RETURN_TO_STOCK` | Return to Stock | Customer return being restocked |
| `TRANSFER` | Transfer | Inter-warehouse correction |
| `OTHER` | Other | Anything not covered above (notes required) |

---

## SECTION 8 — ROLE ACCESS MATRIX

| Role | Dashboard | Movements | Adjustments | Report | Submit | Approve |
|---|---|---|---|---|---|---|
| `superadmin` | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ |
| `admin` | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ |
| `supervisor` | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ |
| `warehouse` | ✅ | ✅ | ✅ | ✅ | ✅ | ❌ |
| `finance` | ✅ (read) | ✅ (read) | ❌ | ❌ | ❌ | ❌ |
| `accounting` | ✅ (read) | ✅ (read) | ❌ | ❌ | ❌ | ❌ |
| `agent` | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ |

---

## SECTION 9 — DAILY USAGE GUIDE

### Start of Shift
1. Go to **Inventory Dashboard** (`/inventory`)
2. Check the **Critical Alert Bar** — act on any out-of-stock or pending adjustment alerts
3. Review the **Stock Health Bar** — note the out-of-stock and low-stock counts
4. Check **Expiring Soon** count — if > 0, click through to review lots

### During Operations
- After receiving stock → verify movement appears in **Movements** (`/inventory/movements`)
- After physical count variance → submit via **New Adjustment** on the Adjustments page
- Use search + type filter on Movements to verify any specific transaction

### Approving Adjustments (Supervisors)
1. Go to **Stock Adjustments** (`/inventory/adjustments`)
2. Click **"Show pending"** in the orange banner — or filter by `Status = Pending`
3. Review each row: check Item, Warehouse, Reason, Before/After quantities
4. Click ✅ to approve (stock updates immediately) or ✗ to reject (add reason)
5. After actioning all pending items — check the stat cards show 0 Pending

### End of Shift / Daily Review
1. Go to **Stock Adjustments** → click **"Report"** button (top-right)
2. Report auto-loads for **today**
3. Review the 6 KPI tiles: Total · Pending · Approved · Rejected · Units Added · Units Deducted
4. Scan the **Variance Direction Bar** — see proportion of positive vs negative adjustments
5. Review **By Reason** breakdown — identify if any unusual reason codes spike today
6. Review **Hourly Activity Chart** — see which hours had the most adjustment activity
7. Check **Pending Items** section — any pending items shown here need action before close
8. Review **High-Impact Adjustments** — verify the largest stock changes are legitimate
9. Click **Export CSV** to download today's full adjustment log for records

---

## SECTION 10 — BUILD & DEPLOYMENT LOG

```
Date: May 20, 2026
Build tool: Vite + TypeScript
Build time: ~9–10 seconds
Build result: ✅ 0 errors, 0 warnings

New bundles produced:
  AdjustmentReport-BOlLCwfm.js   24.32 kB │ gzip: 5.51 kB
  StockAdjustments-Dm_17FN0.js   16.17 kB │ gzip: 4.60 kB
  Dashboard-bi7vKZ14.js          20.48 kB │ gzip: 4.73 kB

Deploy steps:
  1. rsync source files → remote /opt/warehouseops/
  2. Copy files into runner workspace
  3. npm run build (tsc + vite build)
  4. rsync public/build/ → container public/build/
  5. Write PHP files into container via docker exec
  6. php artisan optimize (config + routes + views cached)

Production URL: https://warehouseops.thirdynals.org
Report URL:     https://warehouseops.thirdynals.org/inventory/adjustments/report
```

---

*End of Report — WarehouseOps Inventory System, May 20, 2026*
