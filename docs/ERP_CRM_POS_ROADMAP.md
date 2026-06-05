# TECS WarehouseOps — ERP/CRM/POS Expansion Roadmap

**Source of Truth:** Dolibarr 23.0.3  
**Target System:** WarehouseOps v5 (Laravel 11 + React/Inertia + PostgreSQL)  
**Date:** June 2026  
**Goal:** Transform WarehouseOps from a courier-ops platform into a full ERP/CRM/POS system using Dolibarr as the functional reference.

---

## Current State Audit

### ✅ Already Implemented (Strong)
| Module | Tables / Controllers | Status |
|--------|---------------------|--------|
| Waybill & Courier Management | `waybills`, `waybill_tracking_history`, `uploads` | Production-ready |
| Lead Pool / Distribution | `leads`, `lead_cycles`, `lead_pool_audit`, `recycling_rules`, `fraud_flags` | Production-ready |
| Agent Portal & Call Center | `agent_profiles`, `users` (role=agent) | Production-ready |
| SMS Marketing | `sms_campaigns`, `sms_logs`, `sms_sequences`, `sms_templates` | Production-ready |
| Products (basic) | `products`, `product_variants`, `product_warehouses` | Partial |
| Orders (basic) | `orders` | Partial |
| Procurement | `purchase_requests`, `purchase_orders`, `receiving_reports`, `suppliers` | Partial |
| Inventory | `stock_movements`, `warehouses`, `warehouse_locations` | Partial |
| Finance (COD/Commission) | `agent_commissions`, `cod_settlements`, `financial_transactions` | Partial |
| Shop / Meta Integration | `shop_orders`, `shop_conversations` | Partial |
| Claims & Returns | `claims`, `return_receipts` | Partial |
| Tickets | `tickets` | Basic |
| Reports | `ReportController` | Basic |
| Settings | `system_settings` | Basic |
| RBAC | `permissions`, `role_permissions` | Basic |

---

## Gap Analysis by ERP/CRM/POS Domain

---

### PHASE 1 — Foundation (Weeks 1–3)
> These unlock everything else. No other phase should start without these.

---

#### 1.1 Third-Party / Contact Management (CRM Core)
**Dolibarr reference:** `htdocs/societe/`, `htdocs/contact/`  
**Current state:** `Customer` model exists but is minimal (phone + basic info only)

**What to build:**
- `third_parties` table — companies and individuals (customers, vendors, partners)
- `contacts` table — persons linked to a third-party (multiple contacts per company)
- `addresses` table — multiple addresses per third-party (billing, shipping, branch)
- `third_party_notes` table — private/public notes with timestamps
- Fields to match Dolibarr: `name`, `alias`, `type` (customer/supplier/prospect/partner), `status`, `email`, `phone`, `website`, `tax_id`, `vat_number`, `country`, `currency`, `payment_terms`, `credit_limit`, `tags`

**UI Pages:**
- `/crm/contacts` — list with search, filter by type/status, tags
- `/crm/contacts/{id}` — profile card with tab: Overview | Orders | Invoices | Notes | Documents | Activity

**Migrations needed:**
```
2026_06_10_000001_create_third_parties_table.php
2026_06_10_000002_create_contacts_table.php
2026_06_10_000003_create_addresses_table.php
2026_06_10_000004_migrate_customers_to_third_parties.php
```

---

#### 1.2 Invoicing & Billing (ERP Core #1)
**Dolibarr reference:** `htdocs/compta/facture/`, `htdocs/fourn/facture/`  
**Current state:** No invoices. COD settlements exist but are not proper invoices.

**What to build:**
- `invoices` table — customer invoices (draft → validated → sent → paid → cancelled)
- `invoice_lines` table — line items (product, qty, unit price, tax, discount, total)
- `invoice_payments` table — partial/full payments against an invoice
- `supplier_invoices` table — AP invoices from suppliers
- `supplier_invoice_lines` table
- `invoice_sequences` table — auto-numbering (INV-2026-0001)

**Key fields per Dolibarr standard:**
- `ref` (invoice number), `type` (standard/credit_note/deposit), `status`, `date_invoice`, `date_due`
- `subtotal`, `tax_amount`, `total_amount`, `amount_paid`, `amount_due`
- Link to `third_party_id`, `order_id` (optional), `project_id` (optional)

**UI Pages:**
- `/finance/invoices` — list with aging column (current/30/60/90+ days)
- `/finance/invoices/create` — create with line items, auto-calculate taxes
- `/finance/invoices/{id}` — view with payment history, PDF download
- `/finance/invoices/{id}/payment` — record payment modal
- `/finance/supplier-invoices` — AP list

**Migrations needed:**
```
2026_06_10_000005_create_invoices_table.php
2026_06_10_000006_create_invoice_lines_table.php
2026_06_10_000007_create_invoice_payments_table.php
2026_06_10_000008_create_supplier_invoices_table.php
```

---

#### 1.3 Quotations / Sales Proposals
**Dolibarr reference:** `htdocs/comm/propal/`  
**Current state:** None

**What to build:**
- `quotations` table — sales quotes with expiry dates (draft → sent → accepted → billed → cancelled)
- `quotation_lines` table — line items (same structure as invoice lines)
- Convert-to-order and convert-to-invoice actions
- PDF generation

**Migrations needed:**
```
2026_06_10_000009_create_quotations_table.php
```

---

### PHASE 2 — Operations (Weeks 4–6)
> Strengthens the warehouse/delivery operations layer.

---

#### 2.1 Shipments / Delivery Notes
**Dolibarr reference:** `htdocs/expedition/`  
**Current state:** Waybills exist but no formal delivery note/picking list workflow

**What to build:**
- `shipments` table — outbound delivery notes linked to orders/invoices
- `shipment_lines` table — items being shipped, qty dispatched vs ordered
- `picking_lists` — printable picking list per warehouse
- Status flow: `draft → validated → shipped → delivered / returned`
- Link to existing `waybills` table (a shipment generates a waybill)

**UI Pages:**
- `/warehouse/shipments` — list
- `/warehouse/shipments/{id}` — picking list view, mark as shipped

---

#### 2.2 Inventory: Full Movements Ledger
**Dolibarr reference:** `htdocs/product/stock/`  
**Current state:** Basic `stock_movements` table exists

**What to build:**
- `inventory_movements` — formal ledger (IN/OUT/TRANSFER/ADJUSTMENT/RETURN) with reasons
- `inventory_valuations` — periodic snapshots of stock value (FIFO / average cost)
- `stock_alerts` — low-stock threshold alerts per product/warehouse
- `lot_numbers` / `serial_numbers` — traceability (Dolibarr `htdocs/product/stock/massstockmove.php`)

**UI Pages:**
- `/inventory/movements` — full ledger with filters
- `/inventory/valuation` — current stock value report
- `/inventory/alerts` — products below threshold

---

#### 2.3 Supplier Management (Complete AP)
**Dolibarr reference:** `htdocs/fourn/`  
**Current state:** `suppliers` table exists but linked only to procurement

**What to build:**
- Merge `suppliers` into `third_parties` (type=supplier) — Phase 1.1 foundation
- `supplier_price_lists` — per-supplier pricing with validity dates
- `supplier_contacts` — contacts per supplier
- Supplier statement view — all POs, receipts, invoices in one view

---

#### 2.4 POS (Point of Sale)
**Dolibarr reference:** `htdocs/takepos/`  
**Current state:** `shop_pos_tables` migration exists, partial `Shop` pages

**What to build:**
- `pos_sessions` table — open/close cash sessions with float tracking
- `pos_sales` table — POS transactions (items, payments, discounts)
- `pos_sale_lines` table — line items
- `pos_payment_methods` — cash/card/GCash/transfer per sale
- `pos_cash_movements` — cash in/out logs per session
- Cashier UI: barcode scan → add to cart → payment screen → receipt print
- End-of-day report: sales summary, cash reconciliation

**UI Pages:**
- `/pos` — full-screen cashier POS
- `/pos/sessions` — session history
- `/pos/reports` — daily sales breakdown

---

### PHASE 3 — CRM Depth (Weeks 7–9)

---

#### 3.1 Projects & Tasks
**Dolibarr reference:** `htdocs/projet/`  
**Current state:** None

**What to build:**
- `projects` table — name, status, budget, dates, third_party link
- `tasks` table — assignee, hours estimated/logged, status, parent_task
- `time_logs` table — per-user time entries on tasks
- Kanban view and Gantt view (basic)

---

#### 3.2 Contracts / Service Agreements
**Dolibarr reference:** `htdocs/contrat/`  
**Current state:** None

**What to build:**
- `contracts` table — signed agreements with start/end dates, recurring billing flag
- `contract_lines` table — services/products in contract
- Auto-generate recurring invoices from active contracts

---

#### 3.3 CRM Pipeline (Opportunities)
**Dolibarr reference:** `htdocs/comm/` (opportunities)  
**Current state:** Lead pool exists but is COD-sales-specific

**What to build:**
- `opportunities` table — pipeline stages (prospect → qualified → proposal → negotiation → won/lost)
- Probability percentage, expected value, close date
- Link to `third_parties`, `quotations`, `orders`
- Pipeline Kanban board

---

### PHASE 4 — HR & Assets (Weeks 10–12)

---

#### 4.1 HR / Employee Records
**Dolibarr reference:** `htdocs/hrm/`  
**Current state:** `users` table has role/agent info only

**What to build:**
- `employees` table — linked to `users`, employment details, salary, department
- `departments` table
- `leave_requests` table — with approval workflow
- `expense_reports` table — employee expense claims with receipt upload

---

#### 4.2 Asset Management
**Dolibarr reference:** `htdocs/asset/`

**What to build:**
- `assets` table — company equipment, serial numbers, purchase date, value
- `asset_depreciations` — scheduled depreciation entries
- Assign assets to employees/locations

---

### PHASE 5 — Accounting (Week 13+)

---

#### 5.1 Full Accounting / General Ledger
**Dolibarr reference:** `htdocs/accountancy/`, `htdocs/compta/`  
**Current state:** `financial_transactions` table is partial

**What to build:**
- `chart_of_accounts` — account codes (assets, liabilities, equity, revenue, expenses)
- `journal_entries` table — double-entry bookkeeping
- `journal_entry_lines` — debit/credit lines
- Auto-post journal entries from: invoices, payments, POS sales, expense reports
- Trial balance, P&L, Balance Sheet reports

---

#### 5.2 Tax Management
**Dolibarr reference:** `htdocs/compta/tva/`

**What to build:**
- `tax_rates` table — VAT/GST rates per product category and region
- `tax_declarations` table — periodic tax filing records
- Tax report by period

---

## Implementation Standards (Dolibarr-Inspired)

### Database Conventions
```
- All tables use snake_case
- Every table has: id (bigint PK), created_at, updated_at
- Soft deletes (deleted_at) on all core entities
- Reference numbers use sequences: INV-YYYY-NNNN, QUO-YYYY-NNNN, etc.
- Status fields use UPPERCASE strings (matches existing waybill convention)
- Foreign keys always constrained with nullOnDelete or cascadeOnDelete
- Indexes on all FK columns + status columns + date columns
```

### API Conventions
```
- All routes under /api/* with sanctum auth
- RESTful: GET /resource, POST /resource, PUT /resource/{id}, DELETE /resource/{id}
- Pagination: ?page=1&per_page=25
- Filters: ?status=PAID&from=2026-01-01&to=2026-12-31
- Search: ?q=keyword
```

### Frontend Conventions
```
- Inertia.js pages under resources/js/pages/{Module}/{Action}.tsx
- shadcn/ui components only
- Table components: TanStack Table (already in use)
- Forms: react-hook-form + zod validation
- Currency: always display in PHP (₱) with 2 decimal places
- Dates: always display in Asia/Manila timezone
```

### Role Access Matrix (to add to existing RBAC)

| Module | superadmin | admin | supervisor | finance | accounting | warehouse | agent |
|--------|-----------|-------|-----------|---------|-----------|----------|-------|
| Invoices | ✅ | ✅ | view | ✅ | ✅ | ❌ | ❌ |
| Quotations | ✅ | ✅ | ✅ | view | view | ❌ | ❌ |
| CRM Contacts | ✅ | ✅ | ✅ | view | view | ❌ | own |
| POS | ✅ | ✅ | ✅ | view | view | ✅ | ❌ |
| Projects | ✅ | ✅ | ✅ | ❌ | ❌ | view | own |
| HR | ✅ | ✅ | view | ❌ | ✅ | ❌ | own |
| Accounting | ✅ | view | ❌ | view | ✅ | ❌ | ❌ |

---

## Prioritized Backlog

### Sprint 1 (Now) — Third-Party Management
- [ ] `third_parties` migration + model
- [ ] `contacts` migration + model
- [ ] `addresses` migration + model
- [ ] Migrate existing `customers` → `third_parties`
- [ ] `ThirdPartyController` (index, show, create, update, delete)
- [ ] `/crm/contacts` page (list + search + filter)
- [ ] `/crm/contacts/{id}` page (profile with tabs)
- [ ] Update `orders`, `invoices`, `suppliers` to FK → `third_parties`

### Sprint 2 — Invoicing
- [ ] `invoices` + `invoice_lines` + `invoice_payments` migrations
- [ ] `InvoiceController` with status transitions
- [ ] Invoice list page with aging
- [ ] Invoice create/edit page with line item builder
- [ ] Invoice detail page with payment recording
- [ ] PDF invoice generation (DomPDF — already in composer)
- [ ] `supplier_invoices` + `SupplierInvoiceController`

### Sprint 3 — Quotations + POS
- [ ] `quotations` + `quotation_lines` migrations
- [ ] `QuotationController`
- [ ] Quotation → Order → Invoice conversion flow
- [ ] `pos_sessions` + `pos_sales` + `pos_sale_lines` migrations
- [ ] Full-screen POS cashier page
- [ ] End-of-day POS report

### Sprint 4 — Shipments + Inventory Depth
- [ ] `shipments` + `shipment_lines` migrations
- [ ] Shipment → Waybill link
- [ ] Picking list print view
- [ ] `inventory_movements` ledger upgrade
- [ ] Stock valuation report
- [ ] Low-stock alerts

### Sprint 5 — CRM Pipeline + Projects
- [ ] `opportunities` table + Kanban board
- [ ] `projects` + `tasks` + `time_logs`
- [ ] Project dashboard with task list
- [ ] Gantt chart (basic)

### Sprint 6 — HR + Contracts
- [ ] `employees` + `departments`
- [ ] `leave_requests` with approval
- [ ] `expense_reports` with receipt upload
- [ ] `contracts` + auto-recurring invoices

### Sprint 7 — Accounting
- [ ] `chart_of_accounts`
- [ ] `journal_entries` double-entry
- [ ] Auto-post from invoices/payments/POS
- [ ] Trial balance, P&L, Balance Sheet

---

## File Locations for New Modules

```
app/
  Http/Controllers/
    Crm/ThirdPartyController.php
    Crm/ContactController.php
    Finance/InvoiceController.php
    Finance/SupplierInvoiceController.php
    Finance/QuotationController.php
    Pos/PosSessionController.php
    Pos/PosSaleController.php
    Warehouse/ShipmentController.php
    Hr/EmployeeController.php
    Hr/LeaveRequestController.php
    Projects/ProjectController.php
    Projects/TaskController.php
    Accounting/JournalController.php
  Models/
    ThirdParty.php
    Contact.php
    Address.php
    Invoice.php
    InvoiceLine.php
    InvoicePayment.php
    SupplierInvoice.php
    Quotation.php
    QuotationLine.php
    PosSession.php
    PosSale.php
    Shipment.php
    Employee.php
    Project.php
    Task.php
    JournalEntry.php

resources/js/pages/
  Crm/
    Contacts/Index.tsx
    Contacts/Show.tsx
    Contacts/Create.tsx
  Finance/
    Invoices/Index.tsx
    Invoices/Create.tsx
    Invoices/Show.tsx
    Quotes/Index.tsx
    Quotes/Create.tsx
  Pos/
    Index.tsx          ← full-screen cashier
    Sessions/Index.tsx
  Warehouse/
    Shipments/Index.tsx
    Shipments/Show.tsx
  Hr/
    Employees/Index.tsx
    Leave/Index.tsx
  Projects/
    Index.tsx
    Show.tsx
  Accounting/
    Journal/Index.tsx
    Reports/TrialBalance.tsx
    Reports/ProfitLoss.tsx
```

---

## Notes on Dolibarr Differences

Dolibarr is PHP/procedural with its own UI. **Do NOT copy Dolibarr code** — use it only as a **functional reference** for:
1. What fields each entity should have
2. What status workflows are standard
3. What relationships exist between modules
4. What reports/views are expected

All implementation must follow WarehouseOps conventions:
- Laravel Eloquent models (not raw queries)
- React/Inertia frontend (not Blade/PHP templates)
- shadcn/ui components (not Dolibarr's Eldy theme)
- PostgreSQL with proper indexes (not MySQL-only queries)
- Queue jobs for async operations (not synchronous PHP scripts)

---

*Last updated: June 2026 | Next review: after Sprint 1 completion*
