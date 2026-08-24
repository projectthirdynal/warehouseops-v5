# WarehouseOps Telesales UI Integration

This package applies the approved Telesales dashboard visual direction to the supplied WarehouseOps Telesales source set.

## What changed

### New Telesales shell

Added:

- `resources/js/layouts/TelesalesLayout.tsx`

This creates a dedicated Telesales department UI with:

- separate sidebar/navigation
- mobile navigation drawer
- Telesales/WarehouseOps branding
- page title header
- user menu
- link back to main WarehouseOps
- role-aware navigation

All supplied pages under `resources/js/pages/Telesales/` now use this layout instead of the global `AppLayout`.

### New dashboard

Added:

- `app/Http/Controllers/TelesalesDashboardController.php`
- `resources/js/pages/Telesales/Dashboard.tsx`

The dashboard reads real WarehouseOps data for:

- eligible/available lead inventory
- assigned leads
- contacted leads
- telesales orders
- revenue
- conversion rate
- assigned/contacted/order trend
- brand distribution
- business-region distribution
- recent pool requests
- top-performing agents

Dashboard route:

```text
GET /telesales
```

Supported periods:

```text
/telesales?range=7d
/telesales?range=30d
/telesales?range=90d
```

### New reusable UI components

Added under `resources/js/components/telesales/`:

- `KpiCard.tsx`
- `TrendChart.tsx`
- `DonutBreakdown.tsx`
- `SectionCard.tsx`
- `StatusPill.tsx`

No new JavaScript chart package is required. The dashboard trend uses SVG and the donut charts use CSS conic gradients.

### Main WarehouseOps navigation

The supplied `AppLayout.tsx` was changed so the Telesales feature is exposed as one separate department entry:

```text
Telesales → /telesales
```

The individual Telesales pages are no longer mixed into the generic Leads submenu.

### Route correction included

`/telesales/pool-requests/eligible/count` was moved before the dynamic `/telesales/pool-requests/{poolRequest}` route so `eligible/count` cannot be swallowed by route-model binding.

## Files added

```text
app/Http/Controllers/TelesalesDashboardController.php
resources/js/layouts/TelesalesLayout.tsx
resources/js/pages/Telesales/Dashboard.tsx
resources/js/components/telesales/KpiCard.tsx
resources/js/components/telesales/TrendChart.tsx
resources/js/components/telesales/DonutBreakdown.tsx
resources/js/components/telesales/SectionCard.tsx
resources/js/components/telesales/StatusPill.tsx
README_TELESALES_UI_INTEGRATION.md
```

## Files modified

```text
routes/web.php
resources/js/layouts/AppLayout.tsx
resources/js/pages/Telesales/Import.tsx
resources/js/pages/Telesales/LeadInventory/Index.tsx
resources/js/pages/Telesales/LeadPools/Index.tsx
resources/js/pages/Telesales/LeadPools/Show.tsx
resources/js/pages/Telesales/PoolApprovals/Index.tsx
resources/js/pages/Telesales/PoolApprovals/Show.tsx
resources/js/pages/Telesales/PoolRequests/Create.tsx
resources/js/pages/Telesales/PoolRequests/Index.tsx
resources/js/pages/Telesales/PoolRequests/Show.tsx
resources/js/pages/Telesales/Promos/Create.tsx
resources/js/pages/Telesales/Promos/Index.tsx
```

## Apply to the actual WarehouseOps repository

Back up or commit the current repository first.

From the actual WarehouseOps repository root:

```bash
cd /opt/warehouseops

git status
```

Copy the generated files over the corresponding paths, or apply the included patch after reviewing it.

Then run the normal project checks, for example:

```bash
php artisan route:list --path=telesales
php artisan test
npm run build
```

If your application runs inside Docker, run these through the project containers/normal deployment process instead of assuming host-installed dependencies.

## Expected routes after integration

```text
/telesales
/telesales/inventory
/telesales/pool-requests
/telesales/pool-approvals
/telesales/pools
/telesales/promos
/telesales/import
```

Existing external operational screens linked from the Telesales navigation remain in their original WarehouseOps routes, such as:

```text
/sales
/orders
/crm/contacts
/agents/governance
/distribution/analytics
/reports
```

## Important deployment note

The uploaded ZIP contains the Telesales-related source subset, not the entire WarehouseOps repository with dependencies and every referenced model/service/controller. Because of that, PHP syntax was validated for the newly added controller and routes, and the TypeScript files were parser-checked, but a full `npm run build` and full Laravel test suite cannot be executed against this extracted subset alone.

Run the final build/test commands in the real WarehouseOps repository before production deployment.
