# WarehouseOps-v5 Enhancement Roadmap

Phased breakdown of enhancements across the platform.

Stack: Laravel 11 + Inertia.js + React + TypeScript + PostgreSQL + Docker

---

## Support Tickets Module

Only `index` and `store` exist. No view, reply, resolve, or assign.

### Phase 1 — Critical

- 1. Ticket Detail View — `GET /tickets/{ticket}`, `show()` method, `Tickets/Show.tsx` with thread.
- 2. Ticket Reply System — `POST /tickets/{ticket}/comments`, `TicketComment` model + migration, reply form.
- 3. Ticket Status Workflow — OPEN → IN_PROGRESS → RESOLVED → CLOSED, `PATCH /tickets/{ticket}/status`.
- 4. Ticket Assignment — `PATCH /tickets/{ticket}/assign`, assignee dropdown, "Assigned to me" filter.

### Phase 2 — High

- 1. Categories & Priorities Management — Admin-configurable lists, CRUD, color coding.
- 2. SLA Tracking — `due_at` field, countdown badge, overdue highlighting, breach alerts.
- 3. Search & Advanced Filters — Full-text, filter by status/priority/category/assignee/date.
- 4. Bulk Actions — Bulk assign, close, priority change.

### Phase 3 — Medium

- 1. Email Notifications — Alert on new ticket, reply, status change, assignment.
- 2. Internal Notes — Private notes with `is_internal` flag.
- 3. Canned Responses — Pre-written templates for common types.

### Phase 4 — Low

- 1. Satisfaction Survey — Post-resolution 1-5 star rating.
- 2. Analytics Dashboard — Resolution time, category breakdown, SLA compliance.
- 3. Export to CSV — Export filtered list.

---

## Dashboard & Analytics

### Phase 1 — Critical

- 1. Role-Based Widgets — Different defaults per role (agent: leads, finance: revenue, warehouse: stock).
- 2. Real-Time Stats — Auto-refresh every 30s, manual refresh, "last updated" timestamp.
- 3. Quick Actions — Role-based shortcut buttons (create ticket, new order, import waybills).

### Phase 2 — High

- 1. Customizable Layout — Drag-and-drop widgets via existing `DashboardWidgetConfig`.
- 2. Alerts Widget — Low stock, SLA breaches, failed imports, undelivered waybills.
- 3. Revenue Summary Widget — Today/week/month, top products, conversion trend.

### Phase 3 — Medium

- 1. Operations Heatmap — Hourly activity across days of week.
- 2. Agent Leaderboard Widget — Top 5 by sales today with rank change.

### Phase 4 — Low

- 1. Weather Widget — For delivery planning.
- 2. Birthday/Anniversary Widget — Staff celebrations.

---

## Shop / POS / Facebook Commerce

### Phase 1 — Critical

- 1. Message Polling Optimization — SSE or long-polling to reduce API calls.
- 2. Auto-Assignment Engine — Round-robin, skill-based, workload-based rules.
- 3. Order-Courier Status Sync — Waybill status change auto-updates order, notifies customer.
- 4. POS Checkout Performance — Cache product list and customer lookup.

### Phase 2 — High

- 1. Sentiment Analysis — Auto-detect negative sentiment, flag for review. Column exists.
- 2. Multi-Page Unified Inbox — Aggregate across all pages with unified filtering.
- 3. Checkout Dup Prevention — Real-time dup detection during checkout.
- 4. Conversation SLA — First-response time, resolution time, breach alerts.
- 5. Broadcast Enhancement — Targeted by segment, A/B test content.

### Phase 3 — Medium

- 1. AI Product Recommendations — Collaborative filtering for `recommendProducts`.
- 2. Conversation Merge Preview — Visual preview before confirming.
- 3. Reports Enhancement — Funnel, response time, peak hours, retention.
- 4. Cart Template Sharing — Share across agents with role access.

### Phase 4 — Low

- 1. Rich Media Templates — Buttons, cards, carousel for products.
- 2. Archive Compression — Cold storage for >90 day conversations.
- 3. Gamification — Badges, streaks, milestones.

---

## Waybills & Courier Management

### Phase 1 — Critical

- 1. Courier Tracking Sync — Scheduled job (15 min) pulling updates from all courier APIs.
- 2. Import Error Recovery — Retry failed rows without re-uploading.
- 3. Claim Auto-Creation — Auto-generate claim draft when waybill RETURNED.

### Phase 2 — High

- 1. Rate Comparison — Fetch rates from all couriers before creating waybill.
- 2. Batch Dispatch — Bulk dispatch to courier API with per-waybill errors.
- 3. Delivery Proof — Store photos/signatures from courier callbacks.
- 4. SLA Dashboard — Beyond-SLA per courier with UI (route exists, no UI).

### Phase 3 — Medium

- 1. Geolocation Map — In-transit waybills with last-known location.
- 2. Return Workflow — Scan → receipt → inventory → finance notification.
- 3. Courier Analytics — On-time rate, avg transit, return rate.

### Phase 4 — Low

- 1. QR Code Generation — QR with tracking and destination.
- 2. Mock Courier API — Built-in mock for testing.

---

## Leads & Distribution Engine

### Phase 1 — Critical

- 1. Lead Scoring — Auto-score by source, demographics, history. Prioritize distribution.
- 2. Rule Engine Enhancement — Condition-based rules (region, product, score) with priority.
- 3. Recycling Automation — Scheduled job auto-recycling after cooldown per `RecyclingRules`.

### Phase 2 — High

- 1. Import Validation & Dedup — Pre-import dup check, preview before commit.
- 2. Distribution Analytics — Fairness metrics, imbalance alerts, rebalancing.
- 3. Lifecycle Tracking — Full audit trail import → assign → call → sale/recycle.
- 4. Predictive Assignment — ML-based best-agent prediction.

### Phase 3 — Medium

- 1. Source Analytics — Conversion rate, CPA, ROI per source.
- 2. Pool Capacity Alerts — Notify when low or overstocked with unassigned.
- 3. Telesales Enhancement — Excel support, field mapping UI, validation.

### Phase 4 — Low

- 1. Batch Operations — Bulk reassign, recycle, archive from UI.
- 2. Quality ML Model — Auto-scoring from historical conversion data.

---

## Agent Management & Self-Service

### Phase 1 — Critical

- 1. Performance Dashboard — Real-time calls, conversion, handle time in monitoring.
- 2. Portal Enhancement — Earnings, history, leaderboard in agent portal.
- 3. Availability Auto-Detection — Auto-unavailable when idle beyond threshold.

### Phase 2 — High

- 1. Shift Enforcement — Assign leads only during shift hours. Fields exist.
- 2. Skill Matrix UI — Visual editor in profile, used by distribution.
- 3. Gamification — Badges, streaks, daily goals in portal.

### Phase 3 — Medium

- 1. Coaching Notes — Supervisor-only performance notes.
- 2. Workload Balancing — Auto-pause overloaded agents, redistribute.

### Phase 4 — Low

- 1. Mobile PWA — Offline lead access, push notifications.
- 2. Burnout Prediction — ML model from activity patterns.

---

## Inventory & Warehouse Management

### Phase 1 — Critical

- 1. Real-Time Stock Dashboard — Current stock with low-stock alerts and reorder triggers.
- 2. Movement Audit Trail — Complete history per item with before/after, reason, user.
- 3. Multi-Warehouse Transfer — Stock transfers with approval workflow.

### Phase 2 — High

- 1. Reorder Point Alerts — Email/in-app notification below reorder point.
- 2. Inventory Valuation — FIFO, LIFO, weighted average, exportable.
- 3. Dead Stock Automation — Scheduled scan, auto-flag with aging buckets.
- 4. Asset Depreciation — Scheduled monthly posting with journal entries.

### Phase 3 — Medium

- 1. Barcode Labels — Generate and print from inventory UI.
- 2. Adjustment Bulk Import — CSV with validation and preview.
- 3. Warehouse Map — Visual layout with bin locations and occupancy.

### Phase 4 — Low

- 1. Demand Forecasting — From historical usage and seasonality.
- 2. Cycle Count Module — Scheduled tasks with variance reporting.

---

## Finance & Accounting

### Phase 1 — Critical

- 1. Commission Automation — Scheduled calculation per rules, approval before payout.
- 2. COD Reconciliation — Auto-match remittances against delivered waybills.
- 3. Payment Gateway — GCash, bank transfer with auto-reconciliation.

### Phase 2 — High

- 1. QuickBooks Sync — Real-time invoices, payments, bills. Error retry queue.
- 2. Three-Way Match — PO → receiving → supplier invoice. Flag mismatches.
- 3. COGS Real-Time — Auto-calculate on delivery, daily summary, variance alerts.
- 4. Dashboard Enhancement — Cash flow, P&L, balance sheet, revenue trends.

### Phase 3 — Medium

- 1. Multi-Currency — Conversion for international suppliers.
- 2. Budget vs Actual — Department budgets with variance alerts.
- 3. Tax Compliance — BIR-compliant VAT, withholding tax reports.

### Phase 4 — Low

- 1. Forecasting — Revenue, expense, cash flow projections.
- 2. Audit Trail — Immutable hash-chained financial log.

---

## Procurement & Supply Chain

### Phase 1 — Critical

- 1. PO Approval Workflow — Multi-level (requester → supervisor → finance) with delegation.
- 2. Receiving Quality Check — Record damaged/short quantities, auto-claim generation.
- 3. Supplier Performance — On-time delivery rate, quality score, dispute history.

### Phase 2 — High

- 1. Auto-Generate PR from Low Stock — Create purchase requests from reorder triggers.
- 2. PO Template Library — Pre-configured templates for recurring purchases.
- 3. Supplier Portal — Suppliers view POs, submit invoices, update delivery status.

### Phase 3 — Medium

- 1. Procurement Analytics — Spend by supplier/category, PO cycle time, savings.
- 2. Contract Management — Store supplier contracts with expiry alerts.
- 3. RFQ Module — Request for quotation to multiple suppliers, compare responses.

### Phase 4 — Low

- 1. P-Card Tracking — Track procurement card transactions, reconcile with POs.
- 2. Supplier Diversity — Track and report diversity metrics.

---

## Customer Profile & CRM

### Phase 1 — Critical

- 1. Customer 360 View — Consolidate all touchpoints (orders, conversations, leads, SMS, tickets) in unified timeline.
- 2. Customer Merge Rework — Re-implement Phase 4 L3 activity feed (reverted). Add pre-merge impact analysis.
- 3. Duplicate Suggestions — Implement Phase 4 L2 (pending). Real-time fuzzy match with confidence scores.

### Phase 2 — High

- 1. Customer Segmentation — Auto-segment by RFM (recency, frequency, monetary), region, behavior.
- 2. Customer Lifetime Value — Calculate and display CLV from order history and retention.
- 3. CRM Contact Sync — Sync customer contacts with Google/Microsoft via integrations.

### Phase 3 — Medium

- 1. Customer Journey Mapping — Visual funnel from first contact → conversation → order → delivery → repeat.
- 2. Customer Feedback Collection — Post-delivery feedback form via Messenger or SMS.
- 3. Customer Risk Score Enhancement — ML-based risk scoring from order patterns, return rate, payment history.

### Phase 4 — Low

- 1. Customer Loyalty Program — Points, tiers, rewards based on order history.
- 2. Customer Birthday Automation — Auto-send birthday greeting via Messenger or SMS.

---

## SMS & Communications

### Phase 1 — Critical

- 1. SMS Gateway Provider Integration — Connect to actual Philippine SMS gateway (e.g., Semaphore, iTextMo). Currently only UI exists.
- 2. SMS Sequence Enrollment Automation — Scheduled job processing sequence steps, sending messages at configured intervals.
- 3. SMS Delivery Status Tracking — Webhook endpoint for delivery receipts from SMS gateway, update `SmsLog` status.

### Phase 2 — High

- 1. SMS Template Enhancement — Variable support like reply templates ({customer_name}, {order_number}, etc.).
- 2. SMS Campaign Analytics — Delivery rate, open rate (via link tracking), response rate, opt-out rate.
- 3. SMS Opt-Out Management — Auto-detect opt-out keywords (STOP, UNSUBSCRIBE), enforce do-not-send list.

### Phase 3 — Medium

- 1. SMS A/B Testing — Test different message content, measure response rate.
- 2. SMS Scheduling Optimization — Send at optimal time per recipient based on past engagement.
- 3. SMS Cost Tracking — Track SMS costs per campaign, per agent, with budget alerts.

### Phase 4 — Low

- 1. SMS Rich Media (MMS) — Support image and link previews for product promotions.
- 2. SMS Chatbot — Auto-respond to common customer queries via SMS keywords.

---

## Settings & Integrations

### Phase 1 — Critical

- 1. Integration OAuth Flows — Implement actual OAuth connect flows for Google Workspace and Microsoft 365 (currently just status toggle).
- 2. Slack Webhook Activation — Wire Slack webhook URL to send real notifications for critical events (new ticket, SLA breach, low stock).
- 3. Webhook Event Dispatcher — Implement outbound webhook for system events (order created, waybill delivered, lead converted) with configurable endpoints.

### Phase 2 — High

- 1. Audit Log Viewer UI — `ActivityLog` model exists and is written to, but no UI to view/filter/export logs.
- 2. Email Template Builder — Visual editor for email notification templates with variables.
- 3. System Backup Configuration — Automated database backup settings with retention policy and restore testing.

### Phase 3 — Medium

- 1. Two-Factor Authentication — TOTP-based 2FA for admin/superadmin roles.
- 2. API Key Management — Generate/revoke API keys for external integrations, with scope permissions.
- 3. Webhook Retry Policy — Configurable retry attempts and backoff for failed webhook deliveries.

### Phase 4 — Low

- 1. Custom Field Builder — Admin-defined custom fields for orders, customers, leads.
- 2. Localization (i18n) — Full Filipino (Tagalog, Cebuano, Ilonggo) translation of UI strings.

---

## Admin & Security

### Phase 1 — Critical

- 1. Role Permission Audit — Review all routes and ensure every route has correct role middleware. Document permission matrix.
- 2. User Session Management — View active sessions, force logout, session timeout configuration.
- 3. Password Policy Enforcement — Minimum length, complexity, expiry, history prevention. Currently only `Password::min(8)`.

### Phase 2 — High

- 1. Module Access Control Enhancement — `UserModuleAccess` model exists but needs UI for admins to configure per-user module visibility.
- 2. Login Attempt Monitoring — Track failed login attempts, auto-lock after threshold, IP-based rate limiting.
- 3. Data Export Permissions — Restrict CSV/Excel exports by role, log all exports for audit.

### Phase 3 — Medium

- 1. IP Whitelist — Restrict admin access to configured IP addresses.
- 2. Security Dashboard — Failed logins, locked accounts, permission changes, export activity.
- 3. GDPR/Data Privacy Compliance — Data deletion requests, data portability, consent management for Philippine Data Privacy Act.

### Phase 4 — Low

- 1. Single Sign-On (SSO) — SAML/OIDC integration for enterprise authentication.
- 2. Security Scanning Automation — Automated dependency vulnerability scanning in CI/CD.

---

## Reports & Exports

### Phase 1 — Critical

- 1. Report Builder UI — Visual report builder with date range, filters, column selection, and export to CSV/PDF.
- 2. Scheduled Report Delivery — Auto-generate and email reports on schedule (daily, weekly, monthly). `ScheduledSalesReport` model exists for sales; generalize to all report types.
- 3. Operations Report — Consolidated daily/weekly operations report: waybills dispatched/delivered/returned, leads converted, orders processed, stock movements.

### Phase 2 — High

- 1. Custom Dashboard Reports — Save filtered views of any dashboard page as a named report with shareable link.
- 2. Report Versioning — Track report configuration changes, revert to previous versions.
- 3. Cross-Module Reports — Reports combining data across modules (e.g., sales by warehouse, agent performance by courier).

### Phase 3 — Medium

- 1. Report Embedding — Embed reports in external systems via iframe with secure token.
- 2. Report Annotations — Add notes/comments to report data points for context.
- 3. Report Comparison — Side-by-side comparison of two reporting periods.

### Phase 4 — Low

- 1. Natural Language Report Queries — AI-powered "show me sales last month by agent" → generates report.
- 2. Report White-Label — Custom branding (logo, colors) on exported PDFs.

---

## Notifications System

### Phase 1 — Critical

- 1. Real-Time In-App Notifications — WebSocket/SSE push for instant notification delivery. Currently only polling API exists.
- 2. Notification Preferences — Per-user settings for which events trigger notifications (tickets, assignments, mentions, SLA breaches).
- 3. Notification Center UI — Dropdown panel with unread count, mark as read, filter by type, clear all.

### Phase 2 — High

- 1. Push Notifications — Browser push API for critical alerts when app is in background.
- 2. Email Notification Templates — HTML email templates for each notification type with branding.
- 3. Notification Digest — Daily/weekly email summary of notifications for users who opt out of real-time.

### Phase 3 — Medium

- 1. Notification Grouping — Group similar notifications ("5 new tickets assigned") to reduce noise.
- 2. Notification Snooze — Temporarily mute notifications for a configurable period.
- 3. Mobile Push Notifications — PWA push notification support for mobile devices.

### Phase 4 — Low

- 1. Notification Analytics — Open rate, click-through rate, dismiss rate per notification type.
- 2. Smart Notification Routing — AI-based priority scoring, suppress low-priority notifications during peak hours.

---

## Scanner & Hardware Integration

### Phase 1 — Critical

- 1. Scanner Mode Enhancement — Add "receive_return" mode for processing returned items. Mode exists in settings but not fully implemented.
- 2. Batch Scan Optimization — Improve batch scan performance for large waybill counts, with progress indicator.
- 3. Scanner Audio Feedback — Configurable success/error sounds using Web Audio API. Settings exist, need frontend implementation.

### Phase 2 — High

- 1. Label Printer Integration — Connect to network/USB label printers, generate and print waybill labels directly from scanner UI.
- 2. Scanner Keyboard Wedge Mode — Support hardware barcode scanners that act as keyboard input, with configurable prefix/suffix detection.
- 3. Scan History & Undo — Keep scan session history with ability to undo last scan, export session log.

### Phase 3 — Medium

- 1. Mobile Scanner Support — Camera-based barcode scanning for mobile devices using device camera API.
- 2. Scan Validation Rules — Configurable validation rules per scan mode (format check, existence check, status check).
- 3. Scanner Statistics — Scan count, error rate, average scan time per user, per mode.

### Phase 4 — Low

- 1. Voice-Guided Scanning — Text-to-speech feedback for hands-free operation in warehouse.
- 2. Scanner Calibration — Auto-detect scanner type and configure optimal input timing.

---

## Duplicate Detection & Data Quality

### Phase 1 — Critical

- 1. Real-Time Duplicate Prevention — Check for duplicates at order creation time (not just post-creation review). Block or warn before submission.
- 2. Duplicate Detection Performance — Optimize fuzzy matching queries with proper indexing. Current scan may be slow on large datasets.
- 3. Auto-Merge Confidence Threshold — Configurable confidence threshold for auto-merge, with manual review for borderline cases.

### Phase 2 — High

- 1. Duplicate Detection Scheduling — Scheduled job running duplicate scans during off-peak hours, with email summary of findings.
- 2. Cross-System Duplicate Sync — Sync duplicate status across orders, customers, and conversations. Resolving in one should update others.
- 3. ML Model Retraining Pipeline — Automated retraining of duplicate ML model with new labeled data, with model performance tracking.

### Phase 3 — Medium

- 1. Duplicate Resolution Workflow — Guided merge wizard with field-by-field selection, preview, and undo capability.
- 2. Data Quality Dashboard — Overall data quality score, duplicate count trend, merge rate, false positive rate.
- 3. Duplicate Alert Subscriptions — Subscribe to duplicate alerts for specific pages, regions, or customer segments.

### Phase 4 — Low

- 1. Duplicate Prevention API — Public API endpoint for external systems to check duplicates before submitting orders.
- 2. Duplicate Pattern Analysis — Identify common duplicate patterns (same address, similar name) for proactive prevention rules.

---

## Reply Template Library

### Phase 1 — Critical

- 1. Template Search Enhancement — Full-text search across title, content, and shortcut. Add search-by-variable capability.
- 2. Template Performance Analytics UI — Display usage trends, conversion impact, A/B test results in a dedicated dashboard.
- 3. Template Approval Workflow UI — Visual approval queue for pending templates, with diff view and one-click approve/reject.

### Phase 2 — High

- 1. Template Versioning Enhancement — Diff viewer between versions, side-by-side comparison, selective restore.
- 2. Template Sharing Enhancement — Share templates across teams, with permission levels (view, use, edit).
- 3. Template AI Suggestions Enhancement — Context-aware suggestions using conversation history, customer profile, and order data.

### Phase 3 — Medium

- 1. Template Category Auto-Tagging — AI-based category/intent auto-detection from template content.
- 2. Template Multi-Language Sync — Link translated templates, update one → flag others for re-translation.
- 3. Template Usage Limits — Per-template daily usage limits to prevent overuse of automated responses.

### Phase 4 — Low

- 1. Template Marketplace — Share and discover templates across organizations (if multi-tenant).
- 2. Template Sentiment Matching — Match template tone to customer sentiment (angry → empathetic, happy → cheerful).

---

## Sales Dashboard

### Phase 1 — Critical

- 1. Dashboard Export Enhancement — Export individual widgets as PDF/image, not just CSV. Add print-friendly layout.
- 2. Real-Time Sales Tracking — Auto-refresh sales numbers every 30s during peak hours, with live order feed.
- 3. Sales Target Management — Set daily/weekly/monthly targets per agent, team, and page. Track progress with visual gauges.

### Phase 2 — High

- 1. Predictive Insights Enhancement — Improve ML predictions with more features (seasonality, promotions, weather impact).
- 2. Custom Widget Builder — User-defined widgets with custom metrics, filters, and chart types.
- 3. Sales Funnel Visualization — Visual funnel: leads → contacted → interested → ordered → delivered → repeat.

### Phase 3 — Medium

- 1. Sales by Geography — Map visualization of sales by region/city/barangay.
- 2. Product Affinity Analysis — "Customers who bought X also bought Y" recommendations.
- 3. Sales Alert Configuration — Custom alerts (sales drop >20%, conversion rate <5%, target behind by >2 days).

### Phase 4 — Low

- 1. Sales Benchmarking — Compare performance against historical averages and industry benchmarks.
- 2. Sales Gamification Dashboard — Team competitions, progress bars, achievement badges.

---

## Address Correction & Encoder

### Phase 1 — Critical

- 1. Address Validation Enhancement — Implement Phase 1 C3-C4 (pending): flag orders with missing/invalid address parts, validate completeness before submission.
- 2. Address Autocomplete Enhancement — Implement Phase 2 (pending): autocomplete, misspelling correction, landmark-based lookup, validation report.
- 3. Bulk Address Update Performance — Optimize bulk address update for large order batches, with progress tracking.

### Phase 2 — High

- 1. Address Standardization — Auto-standardize to Philippine address format (Region, Province, City/Municipality, Barangay, Street, Landmark).
- 2. Courier-Specific Address Format — Auto-format address per courier requirements (e.g., J&T needs specific field order).
- 3. Address Geocoding Batch Processing — Batch geocode addresses using Google Maps API, with caching to reduce API calls.

### Phase 3 — Medium

- 1. Address Change Impact Analysis — Show which orders/waybills will be affected before applying bulk address changes.
- 2. Address Quality Score — Per-order address completeness score (0-100), with color-coded indicators.
- 3. Address Correction Analytics — Common error types, correction rate, agent accuracy, courier rejection rate by address issue.

### Phase 4 — Low

- 1. Address Voice Input — Voice-to-text for address entry on mobile devices.
- 2. Address Photo Recognition — OCR from delivery photos to auto-extract and correct addresses.

---

## Meta Compliance & Webhooks

### Phase 1 — Critical

- 1. Webhook Signature Verification — Verify Meta webhook signatures using app secret. Currently may not be validating.
- 2. Webhook Retry & Dead Letter Queue — Store failed webhook payloads for manual replay, with exponential backoff retry.
- 3. Meta App Review Readiness — Ensure all required privacy policy, data deletion, and terms URLs are accessible and compliant.

### Phase 2 — High

- 1. Webhook Rate Limit Handler — Handle Meta API rate limits gracefully, queue outgoing messages when limit approached.
- 2. Page Subscription Health Monitor — Dashboard showing subscription status, last webhook received, API health per page.
- 3. Data Deletion Request Automation — Auto-process data deletion requests within 30 days, with verification and audit trail.

### Phase 3 — Medium

- 1. Webhook Event Filtering — Configurable event types per page (messages, comments, reactions, etc.).
- 2. Meta API Version Management — Track Meta API version deprecation, auto-update Graph API calls.
- 3. Multi-App Support — Support multiple Meta apps for different Facebook pages or environments.

### Phase 4 — Low

- 1. Webhook Simulation Enhancement — Enhanced webhook simulator for testing all event types without real Meta traffic.
- 2. Meta Insights Integration — Pull page-level insights (response time, messaging conversations, page engagement).

---

## Performance & Infrastructure

### Phase 1 — Critical

- 1. Database Query Optimization — Audit N+1 queries across all controllers, add eager loading, add missing indexes. `ShopController` at 328KB is likely culprit.
- 2. Frontend Bundle Size Optimization — Code-split large pages (Encoder.tsx 207KB, Conversation.tsx 149KB, CreateOrder.tsx 127KB). Lazy-load with React.lazy.
- 3. API Response Caching — Redis cache for frequently accessed, rarely changing data (product list, courier list, warehouse list).

### Phase 2 — High

- 1. Queue Worker Configuration — Move heavy operations (imports, exports, bulk operations, email sending) to Redis queue workers.
- 2. Database Connection Pooling — Configure PgBouncer for connection pooling, especially for API-heavy endpoints.
- 3. CDN for Static Assets — Serve JS/CSS/images via CDN for faster page loads, especially for remote agents.

### Phase 3 — Medium

- 1. Elasticsearch Integration — Full-text search for conversations, orders, customers, leads. Replace LIKE queries.
- 2. Read Replica Configuration — Route read-heavy queries (reports, analytics, dashboards) to a PostgreSQL read replica.
- 3. API Rate Limiting — Per-endpoint rate limiting for API routes to prevent abuse and ensure fair resource allocation.

### Phase 4 — Low

- 1. Kubernetes Migration — Container orchestration for auto-scaling, rolling updates, and high availability.
- 2. APM Integration — Application Performance Monitoring (New Relic, Datadog) for proactive bottleneck detection.
- 3. Multi-Region Deployment — Active-active deployment across regions for disaster recovery.
