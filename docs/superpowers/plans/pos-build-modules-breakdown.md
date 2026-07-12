# POS Build Modules Breakdown

A phased production plan for the WarehouseOps POS/Shop module, ordered from Phase 1 (Critical) to Phase 4 (Low). Each feature area is broken into independent numbered lists.

---

## POS Core Schema

Database foundation for Facebook identities, conversations, messages, order items, remarks, address mapping, and courier exports.

### Customer Identities

Phase 1 — Critical

- 1. Create `customers` table with phone normalization, deduplication key, and risk scoring.
- 2. Add customer merge/deduplication logic by normalized phone number.
- 3. Seed blacklist and risk-level rules for fraud prevention.
- 4. Link customer records to leads and orders via `customer_id`.

Phase 2 — High

- 1. Add customer total orders, successful orders, and success rate counters.
- 2. Add last order date and last ordered page tracking.
- 3. Implement customer search API by name, phone, or Facebook name.

Phase 3 — Medium

- 1. Add customer address history and default address selection.
- 2. Add customer tags/notes for agent context.
- 3. Add customer revenue totals and average order value.

Phase 4 — Low

- 1. Add customer activity timeline (calls, messages, orders).
- 2. Add customer preference fields (preferred courier, payment method).
- 3. Export customer list to CSV.

### Conversations

Phase 1 — Critical

- 1. Create `conversations` table with Facebook Page ID, PSID, status, and assigned agent.
- 2. Create `messages` table with sender type, content, attachments, and timestamps.
- 3. Implement webhook ingestion for Messenger messages and Page comments.
- 4. Add conversation status workflow: `new`, `assigned`, `resolved`, `archived`.

Phase 2 — High

- 1. Add unread message count and last message preview.
- 2. Add conversation assignment and reassignment to agents.
- 3. Add conversation priority and flagging.
- 4. Add real-time message polling or websocket updates.

Phase 3 — Medium

- 1. Add conversation categorization/tagging.
- 2. Add bulk resolve/archive actions.
- 3. Add conversation snooze/reminder functionality.
- 4. Add conversation merge for duplicate threads.

Phase 4 — Low

- 1. Add conversation analytics (response time, resolution time).
- 2. Add conversation sentiment indicators.
- 3. Add conversation export for compliance.

### Messages

Phase 1 — Critical

- 1. Store incoming/outgoing message text with sender metadata.
- 2. Store attachment URLs and types (image, voice, file).
- 3. Add message timestamp and seen/read status.
- 4. Persist message send errors and retry state.

Phase 2 — High

- 1. Add quick reply button handling.
- 2. Add message templates and variable substitution.
- 3. Add message typing indicator support.
- 4. Add message history pagination.

Phase 3 — Medium

- 1. Add message reaction support.
- 2. Add message search within a conversation.
- 3. Add message draft auto-save.
- 4. Add message moderation/fraud flagging.

Phase 4 — Low

- 1. Add message translation helpers.
- 2. Add scheduled message queue.
- 3. Add message broadcast from conversation.

### Export Batches

Phase 1 — Critical

- 1. Create `export_batches` table with courier type, status, and file path.
- 2. Create `export_batch_items` table linking orders to batches.
- 3. Generate courier-ready CSV for J&T Express.
- 4. Generate courier-ready CSV for Flash Express.

Phase 2 — High

- 1. Add export batch status workflow: `pending`, `processing`, `ready`, `downloaded`, `archived`.
- 2. Add batch download endpoint with secure filename.
- 3. Add batch item validation before export (address, phone, COD amount).
- 4. Add batch retry/rebuild for failed rows.

Phase 3 — Medium

- 1. Add batch grouping by courier or region.
- 2. Add batch notes and operator tracking.
- 3. Add batch preview before download.
- 4. Add batch archival and cleanup.

Phase 4 — Low

- 1. Add batch analytics (orders per batch, success rate).
- 2. Add batch email notification when ready.
- 3. Add multi-courier combined export.

---

## Multi-page Inbox

Central inbox for Messenger messages and Page comments across connected selling Pages.

### Page Filters

Phase 1 — Critical

- 1. Add page filter dropdown listing connected Facebook Pages.
- 2. Persist selected page filter in URL or session.
- 3. Fetch conversations scoped to the selected page.
- 4. Add "All Pages" option for supervisor view.

Phase 2 — High

- 1. Add page-level unread badge.
- 2. Add page search by name or page ID.
- 3. Add page status indicator (connected/disconnected).
- 4. Add page subscription health check.

Phase 3 — Medium

- 1. Add favorite/bookmark pages for agents.
- 2. Add page-level assignment rules.
- 3. Add page comment moderation queue.
- 4. Add page-level canned response defaults.

Phase 4 — Low

- 1. Add page analytics (conversations, response time).
- 2. Add page comparison dashboard.
- 3. Add page connection management UI.

### Agent Filters

Phase 1 — Critical

- 1. Add filter for assigned agent.
- 2. Add filter for unassigned conversations.
- 3. Add filter for conversations assigned to current user.
- 4. Add role-based filter visibility (agent sees own, supervisor sees all).

Phase 2 — High

- 1. Add agent online/away status.
- 2. Add agent workload indicator.
- 3. Add bulk assignment to agent.
- 4. Add auto-assignment round-robin for new conversations.

Phase 3 — Medium

- 1. Add agent skill-based routing.
- 2. Add agent performance snapshot in inbox.
- 3. Add conversation reassignment history.
- 4. Add agent queue limits and overflow rules.

Phase 4 — Low

- 1. Add agent shift scheduling.
- 2. Add agent idle alert.
- 3. Add agent workload balancing reports.

### Status Workflow

Phase 1 — Critical

- 1. Define statuses: `new`, `assigned`, `awaiting_customer`, `resolved`, `archived`.
- 2. Allow status change from conversation list and detail.
- 3. Add status filter in inbox.
- 4. Enforce status transitions by role.

Phase 2 — High

- 1. Add status change timestamps and audit trail.
- 2. Add SLA timer per status.
- 3. Add automatic status rules (e.g., resolve after inactivity).
- 4. Add status-based notifications.

Phase 3 — Medium

- 1. Add custom status labels per page.
- 2. Add status color coding and badges.
- 3. Add status-based bulk actions.
- 4. Add status analytics and funnel view.

Phase 4 — Low

- 1. Add status reminders and escalations.
- 2. Add status-based automation rules.
- 3. Add status export for QA.

### Conversation Detail

Phase 1 — Critical

- 1. Display message thread with sender identification.
- 2. Add reply input with text and template support.
- 3. Show customer profile sidebar (name, phone, address, risk level).
- 4. Show order history for the customer.

Phase 2 — High

- 1. Add attachment preview and download.
- 2. Add conversation action buttons: create order, block, transfer.
- 3. Add remark/notes section per conversation.
- 4. Add real-time message updates.

Phase 3 — Medium

- 1. Add conversation metadata (source, page, timestamp).
- 2. Add customer address quick-select for order creation.
- 3. Add conversation activity log.
- 4. Add message search within detail.

Phase 4 — Low

- 1. Add conversation print/export.
- 2. Add customer sentiment indicators.
- 3. Add conversation voice memo playback.

---

## Order Desk

Create structured orders from conversations with products, COD amount, remarks, and customer details.

### Multi-item Cart

Phase 1 — Critical

- 1. Allow adding multiple products to a single order.
- 2. Allow quantity adjustment per line item.
- 3. Compute line totals and order total automatically.
- 4. Persist order items in `shop_order_items` table.

Phase 2 — High

- 1. Add product variant selection (size, color, etc.).
- 2. Add stock availability check during cart build.
- 3. Add discount per line or whole order.
- 4. Add cart preview and edit before save.

Phase 3 — Medium

- 1. Add cart-level shipping fee calculation.
- 2. Add cart-level tax calculation.
- 3. Add cart save as draft.
- 4. Add cart duplicate warnings.

Phase 4 — Low

- 1. Add cart templates for common bundles.
- 2. Add cart import from CSV.
- 3. Add cart recommendations.

### Conversation to Order

Phase 1 — Critical

- 1. Create order directly from conversation detail.
- 2. Pre-fill customer details from conversation.
- 3. Link order to conversation ID.
- 4. Generate order number and set status to `confirmed`.

Phase 2 — High

- 1. Add order review step before creation.
- 2. Add COD amount capture and validation.
- 3. Add courier pre-selection from page defaults.
- 4. Add order confirmation message back to customer.

Phase 3 — Medium

- 1. Add duplicate order detection for same customer.
- 2. Add order cancellation from conversation.
- 3. Add order edit after creation.
- 4. Add order status sync back to conversation.

Phase 4 — Low

- 1. Add order follow-up reminders.
- 2. Add automated order confirmation via Messenger.
- 3. Add order split (partial delivery).

### Customer Profile

Phase 1 — Critical

- 1. Show customer name, phone, and normalized phone in order desk.
- 2. Show customer risk level and blacklist status.
- 3. Allow quick customer creation if not found.
- 4. Persist customer link on order.

Phase 2 — High

- 1. Show customer order history.
- 2. Show customer success rate and return rate.
- 3. Allow customer phone update during order creation.
- 4. Add customer address quick-select.

Phase 3 — Medium

- 1. Add customer note/remarks in order desk.
- 2. Add customer tag management.
- 3. Add customer merge suggestions.
- 4. Add customer communication preferences.

Phase 4 — Low

- 1. Add customer lifetime value display.
- 2. Add customer segmentation badges.
- 3. Add customer profile export.

### Agent Remarks

Phase 1 — Critical

- 1. Add `remarks` field to orders table.
- 2. Allow agent to add remarks during order creation.
- 3. Display remarks on order detail and list.
- 4. Store remark author and timestamp.

Phase 2 — High

- 1. Add multiple remark entries per order.
- 2. Add remark visibility rules (internal vs customer-visible).
- 3. Add remark templates for common scenarios.
- 4. Add remark edit/delete for recent entries.

Phase 3 — Medium

- 1. Add remark threading/replies.
- 2. Add remark mentions for supervisors.
- 3. Add remark search across orders.
- 4. Add remark notifications.

Phase 4 — Low

- 1. Add remark pinning.
- 2. Add remark categories/tags.
- 3. Add remark export.

---

## Encoder & Export

Validate addresses, map regions, and export courier-ready sheets for J&T, Flash, and other COD couriers.

### Address Correction

Phase 1 — Critical

- 1. Add address mapping table for region, province, city, barangay.
- 2. Validate address fields against mapping table.
- 3. Flag orders with missing or invalid address parts.
- 4. Allow agent to correct address before export.

Phase 2 — High

- 1. Add address autocomplete from mapped data.
- 2. Add common misspelling correction.
- 3. Add landmark and nearest landmark field.
- 4. Add address validation report before export.

Phase 3 — Medium

- 1. Add address confidence score.
- 2. Add address correction history.
- 3. Add bulk address update from CSV.
- 4. Add address geocoding fallback.

Phase 4 — Low

- 1. Add address suggestion from previous orders.
- 2. Add address formatting by courier.
- 3. Add address validation analytics.

### Bulk Selection

Phase 1 — Critical

- 1. Add checkbox selection on order list.
- 2. Add select-all/none controls.
- 3. Add bulk action: export to batch.
- 4. Filter orders by status, courier, and date.

Phase 2 — High

- 1. Add bulk status update.
- 2. Add bulk assignment to encoder.
- 3. Add bulk print labels.
- 4. Add bulk COD amount verification.

Phase 3 — Medium

- 1. Add bulk pagination-aware selection.
- 2. Add bulk duplicate detection.
- 3. Add bulk hold/release.
- 4. Add bulk tag update.

Phase 4 — Low

- 1. Add bulk split by region.
- 2. Add bulk reschedule delivery.
- 3. Add bulk archive.

### Courier Batches

Phase 1 — Critical

- 1. Create batch from selected orders.
- 2. Validate batch items for required courier fields.
- 3. Generate courier-specific CSV format.
- 4. Store batch file in storage and database.

Phase 2 — High

- 1. Add batch status workflow and tracking.
- 2. Add batch download with timestamped filename.
- 3. Add batch item-level error log.
- 4. Add batch rebuild after corrections.

Phase 3 — Medium

- 1. Add batch grouping by courier.
- 2. Add batch preview before download.
- 3. Add batch notes and operator.
- 4. Add batch archival policy.

Phase 4 — Low

- 1. Add batch sharing/link expiry.
- 2. Add batch email notification.
- 3. Add batch comparison with previous batches.

### Courier CSV Validation

Phase 1 — Critical

- 1. Define CSV schema per courier (J&T, Flash).
- 2. Validate required columns before export.
- 3. Validate phone number format.
- 4. Validate COD amount and total consistency.

Phase 2 — High

- 1. Add row-level validation errors.
- 2. Add CSV preview before final download.
- 3. Add courier-specific address format validation.
- 4. Add weight and dimension validation if required.

Phase 3 — Medium

- 1. Add validation rule configuration per courier.
- 2. Add historical validation error analytics.
- 3. Add automatic correction suggestions.
- 4. Add CSV encoding check.

Phase 4 — Low

- 1. Add custom CSV template builder.
- 2. Add validation test mode.
- 3. Add CSV upload verification.

---

## Reports & Automation

Operational reporting, duplicate checks, saved reply templates, and customer profile updates.

### Sales Dashboard

Phase 1 — Critical

- 1. Display daily/weekly/monthly order count.
- 2. Display revenue totals by period.
- 3. Display order status breakdown.
- 4. Display top products by quantity sold.

Phase 2 — High

- 1. Add sales trends chart.
- 2. Add revenue by page/source.
- 3. Add revenue by payment method.
- 4. Add agent sales leaderboard.

Phase 3 — Medium

- 1. Add cohort/retention metrics.
- 2. Add average order value.
- 3. Add return/refund rate.
- 4. Add exportable sales report.

Phase 4 — Low

- 1. Add predictive sales insights.
- 2. Add custom dashboard widgets.
- 3. Add scheduled sales reports.

### Duplicate Warnings

Phase 1 — Critical

- 1. Detect duplicate orders by customer phone + product within time window.
- 2. Show warning during order creation.
- 3. Detect duplicate conversations by PSID.
- 4. Add manual merge for duplicate customer records.

Phase 2 — High

- 1. Add fuzzy duplicate detection (name, address similarity).
- 2. Add duplicate order review queue.
- 3. Add duplicate detection configuration rules.
- 4. Add duplicate analytics dashboard.

Phase 3 — Medium

- 1. Add auto-merge suggestions.
- 2. Add duplicate family grouping.
- 3. Add duplicate notification to supervisors.
- 4. Add duplicate audit log.

Phase 4 — Low

- 1. Add duplicate detection across pages.
- 2. Add duplicate export.
- 3. Add duplicate ML-based scoring.

### Reply Template Library

Phase 1 — Critical

- 1. Create `reply_templates` table with title, content, and shortcut.
- 2. Add CRUD UI for templates.
- 3. Insert template into message reply input.
- 4. Add variable support (customer name, order number, etc.).

Phase 2 — High

- 1. Add template categorization by page or intent.
- 2. Add template search and favorites.
- 3. Add role-based template access.
- 4. Add template usage analytics.

Phase 3 — Medium

- 1. Add template sharing across pages.
- 2. Add template version history.
- 3. Add template approval workflow.
- 4. Add template performance metrics.

Phase 4 — Low

- 1. Add AI-assisted template suggestions.
- 2. Add template A/B testing.
- 3. Add multi-language templates.

### Customer Profile Edits

Phase 1 — Critical

- 1. Allow editing customer name and phone.
- 2. Allow editing customer address fields.
- 3. Update normalized phone on change.
- 4. Update customer linked orders.

Phase 2 — High

- 1. Add customer blacklist/unblacklist action.
- 2. Add customer risk level override.
- 3. Add customer address history.
- 4. Add customer merge UI.

Phase 3 — Medium

- 1. Add customer tags and notes.
- 2. Add customer communication preferences.
- 3. Add customer profile change audit.
- 4. Add customer profile image upload.

Phase 4 — Low

- 1. Add customer profile export.
- 2. Add customer profile duplicate suggestions.
- 3. Add customer profile activity feed.
