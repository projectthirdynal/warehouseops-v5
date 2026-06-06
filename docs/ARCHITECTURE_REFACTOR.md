# Architecture Refactor — Admin & Finance Domains

## Objective
Centralize user management in the Admin section and extract deep domain services from controllers, starting with Finance (Invoice/SupplierInvoice).

---

## Phases Completed

### Phase 1 — Extract Finance Domain Services

| Step | What Changed |
|------|-------------|
| 1.1 | Created `InvoiceCalculator` — pure functions for line totals + invoice recalculation |
| 1.2 | Delegated `InvoiceController` (`storeLine`, `destroyLine`, `storeLines`, `recalculate`) to `InvoiceCalculator` |
| 1.3 | Created `SupplierInvoiceCalculator` — derives subtotal/tax from TTC total |
| 1.4 | Delegated `SupplierInvoiceController::store()` to `SupplierInvoiceCalculator` |
| 1.5 | Added 10 unit tests (5 for InvoiceCalculator, 5 for SupplierInvoiceCalculator) |

**Seams created:**
- `InvoiceCalculator::calculateLineTotals(float $qty, float $unitPrice, float $discountPct = 0, float $taxRate = 0): array`
- `InvoiceCalculator::createLine(Invoice $invoice, array $data): InvoiceLine`
- `InvoiceCalculator::recalculateInvoice(Invoice $invoice): void`
- `SupplierInvoiceCalculator::deriveFromTotal(float $totalAmount, float $taxRate = 0): array`

---

### Phase 2 — Extract Admin Dashboard Sub-Components

| Before | After |
|--------|-------|
| `Dashboard.tsx` (629 lines) | `Dashboard.tsx` (~230 lines) + 3 sub-components |
| | `components/UserTable.tsx` — search, filters, toggle, role change, delete |
| | `components/PermissionMatrix.tsx` — role selector, permission checkboxes, save |
| | `components/ActivityFeed.tsx` — activity log list |

---

### Phase 3 — Clean Orphaned SettingsController Methods

**Deleted dead code:**
- `storeUser()`, `updateUser()`, `toggleUser()`, `deleteUser()`, `resetUserPassword()`
- `updateRolePermissions()`

**SettingsController reduced from 209 lines to 110 lines.**
It now only handles personal settings: profile, appearance, password, system settings.

---

## Bugs Found During Review

| # | File | Issue | Severity |
|---|------|-------|----------|
| 1 | `InvoiceController.php` | `storePayment()` missing `updated_by`, doesn't call `recalculateInvoice()` | Medium |
| 2 | `SupplierInvoiceController.php` | `store()` missing `updated_by` | Low |
| 3 | `SettingsController.php` | `updateSystemSettings()` validates but never persists | Medium |
| 4 | `Admin/components/*.tsx` | Constants & interfaces duplicated across files | Low |
| 5 | `PermissionMatrix.tsx` | Stale props pattern (`useState` from prop) | Low |
| 6 | `UserTable.tsx` | Filter state lost on every Inertia action | Medium |
| 7 | `InvoiceCalculator.php` | `subtotal` computed but not persisted in `createLine()` | Low |
| 8 | `AdminController.php` | Password validator mismatch with UI placeholder | Low |
| 9 | `UserTable.tsx` | Delete has no loading guard or double-click protection | Low |

---

## Files Created / Modified

### New Files
```
app/Services/Finance/InvoiceCalculator.php
app/Services/Finance/SupplierInvoiceCalculator.php
tests/Unit/Services/Finance/InvoiceCalculatorTest.php
tests/Unit/Services/Finance/SupplierInvoiceCalculatorTest.php
resources/js/pages/Admin/components/UserTable.tsx
resources/js/pages/Admin/components/PermissionMatrix.tsx
resources/js/pages/Admin/components/ActivityFeed.tsx
```

### Modified Files
```
app/Http/Controllers/Finance/InvoiceController.php
app/Http/Controllers/Finance/SupplierInvoiceController.php
app/Http/Controllers/SettingsController.php
resources/js/pages/Admin/Dashboard.tsx
resources/js/pages/Settings/Index.tsx
resources/js/pages/Agents/Index.tsx
routes/web.php
```

---

## Skills Used

| Skill | Used For |
|-------|----------|
| `improve-codebase-architecture` | Find deepening opportunities (domain services, component seams) |
| `review` | Standards + Spec review after each phase |
| `tdd` | Red-green-refactor cycle for InvoiceCalculator unit tests |
| `to-prd` | (Available) Convert architecture decisions into PRD |
| `to-issues` | (Available) Break refactor plan into tracker issues |
| `triage` | (Available) Manage incoming bugs during refactor |
| `grill-with-docs` | (Available) Stress-test plan against existing codebase |
