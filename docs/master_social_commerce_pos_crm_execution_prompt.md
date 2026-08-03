# MASTER EXECUTION PROMPT

## Build and Complete a Full Social-Commerce POS/CRM Platform with Facebook Meta Integration

---

# 1. ROLE

Act as a combined:

- Senior Software Architect
- Senior Laravel Backend Engineer
- Senior Frontend Engineer
- Database Architect
- DevOps Engineer
- Meta Platform Integration Specialist
- Security Engineer
- QA Automation Engineer
- Product Manager for Social-Commerce Operations

Your task is to inspect the existing system, preserve valid working modules, refactor weak or unsafe implementation, and complete the full platform into a production-ready social-commerce POS/CRM system.

Do not only provide recommendations. Execute architecture changes, backend and frontend implementation, database migrations, security fixes, Meta integration fixes, workflow configuration, automated tests, performance optimization, monitoring, documentation, and deployment preparation.

Do not claim completion without code evidence, test evidence, and verified acceptance criteria.

---

# 2. SYSTEM VISION

Build a multi-tenant social-commerce platform that combines:

```text
Facebook Page Integration
+ Multi-Page Messaging Inbox
+ Customer CRM
+ POS and Order Management
+ Product and Inventory Management
+ Courier Management
+ Agent and Team Operations
+ Automation Rules
+ Analytics and Reporting
+ Business and Shop Configuration
```

The finished platform must support businesses that sell through Facebook Pages and Messenger, allowing agents to receive inquiries, identify customers, create orders, track inventory, process shipments, monitor performance, and manage multiple Pages from one system.

---

# 3. CURRENT SYSTEM CONTEXT

The existing system already contains partial implementations for:

- Shop dashboard
- Facebook Page connector
- Facebook OAuth
- Webhook verification
- Multi-page inbox
- Conversations and messages
- Customer identities
- Phone detection
- Customer profiles
- Customer merging
- Addresses
- Customer notes and timelines
- Order creation
- POS checkout
- Inventory deduction
- Courier exports
- Work queues
- Agent assignment
- Reporting
- Automation placeholders
- Dashboard widgets
- Sales analytics

Known or likely code areas include:

```text
FacebookConnectorService.php
MetaWebhookController.php
CustomerIdentityService.php
CustomerMergeService.php
ShopController.php
DashboardController.php
SalesDashboardController.php
SalesDashboardService.php
FacebookAccount.php
FacebookPage.php
Conversation.php
Message.php
Customer.php
Order.php
Product.php
InventoryMovement.php
```

Before changing code:

1. Search the entire codebase.
2. Identify current routes, services, jobs, listeners, controllers, models, policies, migrations, tests, and frontend components.
3. Build a dependency map.
4. Identify duplicate logic.
5. Identify hardcoded values.
6. Identify missing tenant scoping.
7. Identify security-sensitive paths.
8. Identify existing features that can be retained.

---

# 4. CORE IMPLEMENTATION RULES

1. Inspect before modifying.
2. Preserve working behavior when safe.
3. Refactor only when necessary.
4. Use modular services instead of oversized controllers.
5. Use policies and permissions for every sensitive action.
6. Use database transactions for inventory, orders, payments, customer merging, and refunds.
7. Use queue workers for webhooks, courier sync, notifications, reports, AI tasks, and large imports.
8. Never expose secrets or tokens to the frontend.
9. Never log access tokens, passwords, private keys, or full sensitive payloads.
10. Enforce tenant isolation at backend query level.
11. Add migrations for schema changes.
12. Add automated tests for every critical workflow.
13. Do not create placeholder endpoints that pretend features are complete.
14. Do not hardcode Pages, brands, products, couriers, warehouses, roles, or business rules.
15. All configurable business behavior must come from settings or rules.
16. All system status labels must have clear definitions.
17. Do not allow UI controls to bypass backend authorization.
18. Do not mark production readiness while any Critical or High issue remains.

---

# 5. TARGET PLATFORM ARCHITECTURE

```text
Application
├── Authentication
├── Tenancy
├── Business Settings
├── Shop Management
├── Meta Integration
├── Inbox
├── CRM
├── Orders
├── POS
├── Products
├── Inventory
├── Couriers
├── Automation
├── Reports
├── Notifications
├── Audit
├── Security
└── Monitoring
```

Recommended logical service boundaries:

```text
MetaOAuthService
MetaApiClient
MetaWebhookService
MetaTokenHealthService
MetaSubscriptionService
MessengerEligibilityService
ConversationService
CustomerIdentityService
CustomerMergeService
OrderService
POSCheckoutService
InventoryService
InventoryReservationService
CourierService
AutomationEngine
AssignmentEngine
MetricsService
AuditService
NotificationService
```

Avoid placing unrelated business logic inside controllers.

---

# 6. MULTI-TENANT DATA MODEL

The system must support multiple businesses and shops.

```text
Tenant / Business
    └── Shops
         └── Facebook Pages
         └── Teams
         └── Agents
         └── Products
         └── Warehouses
         └── Orders
         └── Customers
```

Most operational tables must include:

```text
tenant_id
shop_id
```

Page-related records should also include:

```text
facebook_page_id
```

Team-related records should include:

```text
team_id
```

Every backend query must validate tenant ownership.

Bad:

```php
Conversation::findOrFail($id);
```

Correct:

```php
Conversation::query()
    ->where('tenant_id', $currentTenantId)
    ->whereKey($id)
    ->firstOrFail();
```

Add composite indexes for frequent tenant-scoped queries.

---

# 7. PHASE 1 — SECURITY, TENANCY, AND PLATFORM FOUNDATION

## 7.1 Tenant Isolation

Implement:

- Tenant resolution middleware
- Tenant-aware policies
- Tenant-aware query scopes
- Cross-tenant authorization tests
- Tenant-aware cache keys
- Tenant-aware queues
- Tenant-aware file exports
- Tenant-aware WebSocket/SSE channels

Acceptance criteria:

- No user can access another tenant’s conversations, customers, orders, Pages, exports, products, inventory, or reports.
- Cross-tenant ID guessing returns `404` or `403`.
- Automated security tests cover every major resource.

## 7.2 Role-Based Access Control

Create configurable roles:

```text
Owner
Administrator
Integration Administrator
Manager
Supervisor
Agent
Encoder
Warehouse Staff
Courier Coordinator
Finance Staff
Analyst
Read-Only Auditor
```

Permissions should include:

```text
connect_facebook
disconnect_facebook
manage_pages
view_inbox
reply_messages
transfer_conversations
create_orders
edit_orders
cancel_orders
approve_discounts
manage_inventory
process_returns
process_refunds
manage_couriers
view_reports
export_data
manage_users
manage_settings
view_audit_logs
manage_automations
```

Acceptance criteria:

- Every protected action checks authorization.
- UI hides unauthorized actions.
- Backend still blocks direct unauthorized requests.
- Role changes are audited.

## 7.3 Audit Logging

Audit at minimum:

```text
User Login
Facebook Account Connected
Facebook Page Connected
Facebook Page Disconnected
Token Refreshed
Permission Changed
Webhook Subscription Changed
Message Sent
Message Failed
Conversation Assigned
Conversation Transferred
Conversation Resolved
Customer Linked
Customer Merged
Customer Updated
Order Created
Order Updated
Order Cancelled
Stock Reserved
Stock Deducted
Stock Returned
Refund Issued
Courier Booked
Settings Changed
Automation Rule Changed
User Role Changed
Data Exported
Customer Data Deleted
```

Audit records should include:

```text
tenant_id
actor_id
action
target_type
target_id
request_id
metadata
ip_address
user_agent
created_at
```

Never store full tokens or secrets in audit metadata.

---

# 8. PHASE 2 — META FACEBOOK INTEGRATION

## 8.1 OAuth Security

Replace weak state handling.

The OAuth state must be:

- Cryptographically random
- At least 256 bits
- Stored server-side
- Bound to session and user
- Short-lived
- Required
- Single-use
- Invalidated after callback

Reject missing, invalid, expired, replayed, or wrong-session states.

Add automated tests for all cases.

## 8.2 Token Storage

Encrypt:

```text
Facebook user access tokens
Facebook Page access tokens
Instagram access tokens
System user tokens
Refresh tokens
Webhook secrets
```

Use encrypted model casts or a dedicated encryption service.

Token fields must be hidden from JSON serialization, API resources, logs, error pages, and debug output.

Add a migration or controlled reconnect for existing plaintext tokens.

## 8.3 Token Lifecycle

Store:

```text
token_expires_at
data_access_expires_at
last_validated_at
last_validation_error
connection_status
reconnect_required_at
```

Connection states:

```text
ACTIVE
EXPIRING
EXPIRED
REVOKED
PERMISSION_MISSING
WEBHOOK_INACTIVE
RECONNECT_REQUIRED
DISCONNECTED
```

Implement scheduled token-health checks.

The UI must show account connection, Page connection, Messenger permission, webhook status, token health, last sync, last error, and reconnect action.

## 8.4 Page Discovery and Configuration

After successful OAuth:

1. Retrieve eligible Pages.
2. Display Pages to the user.
3. Let the user choose which Pages to activate.
4. Store selected Pages.
5. Subscribe selected Pages to webhooks.
6. Load Page permissions and task capabilities.
7. Map each Page to shop, brand, team, warehouse, courier, and products.

Each Page configuration should include:

```text
Page Name
Page ID
Brand
Shop
Team
Assigned Agents
Allowed Products
Default Price List
Default Warehouse
Default Courier
Auto-Assignment Rule
Saved Reply Group
Business Hours
Timezone
Language
Order Source
Token Status
Webhook Status
Last Sync
```

## 8.5 Webhook Security

Webhook requests must:

1. Use the raw request body.
2. Validate Meta signature.
3. Reject missing signatures.
4. Reject invalid signatures.
5. Never process invalid payloads.
6. Log only sanitized metadata.
7. Return HTTP `403` for forged requests.

## 8.6 Queue-Based Webhook Processing

```text
Webhook Request
    ↓
Signature Verification
    ↓
Event Normalization
    ↓
Store Event
    ↓
Dispatch Queue Job
    ↓
Return HTTP 200
    ↓
Worker Processes Event
```

Webhook event states:

```text
RECEIVED
QUEUED
PROCESSING
PROCESSED
FAILED
REJECTED
DEAD_LETTER
```

Move customer matching, sentiment analysis, phone detection, message persistence, conversation updates, AI processing, media download, automation, courier action, and external APIs out of the HTTP request.

## 8.7 Webhook Idempotency

Use provider event IDs whenever possible.

Fallback event fingerprints must use canonical stable serialization.

Add a unique constraint on:

```text
tenant_id + event_key
```

Duplicate events must not create duplicate messages, conversations, customers, orders, leads, reactions, or status updates.

## 8.8 Webhook Subscription Fields

Subscribe only to fields actually supported by the implementation:

```text
messages
messaging_postbacks
message_deliveries
message_reads
message_reactions
messaging_referrals
feed
```

Use `messaging_handovers` and `standby` only when handover is implemented.

## 8.9 Proper Disconnect

Disconnect flow:

1. Authorize local user.
2. Unsubscribe the Page from the Meta app.
3. Revoke permissions when requested.
4. Stop event processing.
5. Remove local tokens.
6. Mark the Page disconnected.
7. Write an audit log.
8. Apply retention rules.
9. Retry temporary Meta failures.

Use `DISCONNECT_PENDING` when Meta-side cleanup temporarily fails.

---

# 9. PHASE 3 — MULTI-PAGE INBOX

## 9.1 Inbox Structure

Build inbox filters:

```text
All Conversations
Assigned to Me
Unassigned
New
Open
Waiting for Customer
Waiting for Payment
Order Created
For Follow-Up
Resolved
Spam
Blocked
```

Filters should also support Page, brand, team, agent, tag, date range, unread, SLA breach, phone detected, customer matched, and order linked.

## 9.2 Conversation Status Workflow

Use explicit states:

```text
NEW
OPEN
ASSIGNED
WAITING_CUSTOMER
WAITING_PAYMENT
ORDER_CREATED
FOLLOW_UP
RESOLVED
SPAM
BLOCKED
```

Do not automatically resolve conversations when an order is created.

Create a state transition service that validates allowed transitions.

## 9.3 Agent Assignment

Support manual assignment, manual transfer, round robin, least active conversations, least workload, Page-based assignment, team-based assignment, skill-based assignment, shift-based assignment, and priority assignment.

Track:

```text
assigned_agent_id
assigned_team_id
assigned_at
transferred_by
transfer_reason
assignment_method
```

## 9.4 Messenger Composer

Composer must show reply eligibility, response window remaining, Page used for reply, agent identity, message status, saved replies, attachments, and internal notes.

Message statuses:

```text
CREATED
QUEUED
SUBMITTED
ACCEPTED
DELIVERED
READ
FAILED
BLOCKED_POLICY
RETRYING
```

Never display “Sent” merely because the button was clicked.

## 9.5 Messenger Policy Enforcement

Create `MessengerEligibilityService`.

It must determine whether a normal response can be sent, when the response window expires, which methods are allowed, and why sending is blocked.

Block normal replies outside the allowed response window.

## 9.6 Saved Replies and Suggestions

Support global, shop, Page, team, agent, tag-based, and workflow-based templates.

Each template should include:

```text
name
category
content
language
page_scope
team_scope
variables
status
usage_count
```

AI suggestions must never send automatically without explicit approval unless a compliant automation is specifically configured.

## 9.7 Conversation SLA

Track:

```text
first_response_due_at
first_responded_at
resolution_due_at
resolved_at
sla_status
breach_reason
```

Show first-response timer, resolution timer, breach alerts, and supervisor escalation.

---

# 10. PHASE 4 — CUSTOMER CRM

## 10.1 Customer Profile

Each customer should support:

```text
Full Name
Verified Phone
Alternate Phones
Email
Facebook Identities
Addresses
Barangay
City
Province
Region
Landmark
Tags
Risk Level
Customer Segment
Lifecycle Stage
Order History
Delivered Orders
Cancelled Orders
Returns
Lifetime Value
Average Order Value
Last Contact
Last Purchase
Notes
Timeline
```

## 10.2 Customer Identity

Facebook identity must be scoped by:

```text
provider
provider_user_id
facebook_page_id
tenant_id
```

Do not assume the same PSID across Pages.

## 10.3 Phone Detection and Matching

```text
Phone Detected in Message
    ↓
Normalize Phone
    ↓
Search Tenant Customers
    ↓
Show Potential Matches
    ↓
Agent Verifies
    ↓
Link or Create Customer
```

Do not auto-merge solely based on extracted text.

## 10.4 Customer Data Confidence

Use source priority:

```text
Verified Agent-Confirmed Value
Verified Customer Value
Successful Order Value
Previous Saved Value
New Message Extraction
Fallback Placeholder
```

Do not overwrite verified fields with weak unverified input.

## 10.5 Customer Merge

Customer merge must provide preview, conflict detection, field selection, order reassignment, conversation reassignment, address merge, identity merge, note merge, audit trail, and rollback strategy.

The merge must run inside a database transaction.

## 10.6 Segmentation

Add configurable segments:

```text
New
Regular
Repeat Buyer
VIP
At Risk
Inactive
High Return Rate
High Cancellation Rate
Blacklisted
```

Lifecycle stages:

```text
Lead
Engaged
Customer
Repeat Customer
Inactive
Churned
```

---

# 11. PHASE 5 — PRODUCT, PRICING, AND INVENTORY

## 11.1 Product Catalog

Support products, variants, SKUs, bundles, categories, brands, images, unit cost, selling price, status, weight, dimensions, and barcode.

## 11.2 Page-Specific Product Mapping

Each Facebook Page may have allowed products, default product, Page-specific price, Page-specific bundle, Page-specific promo, and Page-specific stock source.

Do not assume every Page sells every product.

## 11.3 Price Lists

Support default retail, wholesale, Page-specific, campaign-specific, agent-specific, promo, and bundle pricing.

Price priority must be deterministic.

## 11.4 Inventory Model

Track:

```text
available
reserved
committed
damaged
returned_pending_inspection
```

Inventory movement types:

```text
STOCK_IN
RESERVE
RELEASE_RESERVATION
COMMIT
SALE
RETURN_PENDING
RETURN_APPROVED
DAMAGE
ADJUSTMENT
TRANSFER
```

## 11.5 Inventory Reservation

```text
Draft Order → Optional Reservation
Confirmed Order → Reserve
Packed Order → Commit
Cancelled Order → Release Reservation
Returned Order → Inspect Before Restock
```

Use database locking with `lockForUpdate()` and prevent overselling under concurrent requests.

## 11.6 Stock Audit Trail

Every movement must include:

```text
tenant_id
warehouse_id
product_id
variant_id
movement_type
quantity
reference_type
reference_id
idempotency_key
performed_by
created_at
```

No stock movement may use `referenceId: 0`.

## 11.7 Warehouses

Support main, branch, Page-specific, virtual, return, and damaged stock warehouses.

---

# 12. PHASE 6 — POS AND ORDER MANAGEMENT

## 12.1 POS Checkout

POS must support multi-item cart, quantity editing, discount, shipping fee, tax, partial payment, payment method, customer selection, address selection, courier selection, hold transaction, resume transaction, void transaction, receipt, and invoice.

## 12.2 Order Status Separation

Use separate fields:

```text
order_status
payment_status
fulfillment_status
shipping_status
```

Suggested values:

### Order

```text
DRAFT
PENDING_CONFIRMATION
CONFIRMED
CANCELLED
COMPLETED
```

### Payment

```text
UNPAID
PARTIALLY_PAID
PAID
REFUNDED
PARTIALLY_REFUNDED
```

### Fulfillment

```text
UNFULFILLED
RESERVED
PACKING
PACKED
FULFILLED
RETURNED
```

### Shipping

```text
NOT_BOOKED
BOOKED
PICKED_UP
IN_TRANSIT
DELIVERED
FAILED_DELIVERY
RETURN_TO_SENDER
```

## 12.3 Order Transaction Flow

```text
Begin Transaction
    ↓
Create Draft Order
    ↓
Create Order Items
    ↓
Lock Inventory
    ↓
Validate Stock
    ↓
Reserve or Deduct Stock
    ↓
Create Stock Movements
    ↓
Calculate Totals
    ↓
Confirm Order
    ↓
Commit
```

Rollback everything if any step fails.

## 12.4 Order Number Generation

Replace count-based generation.

Use:

```text
ORD-YYYYMMDD-{atomic-sequence}
```

or ULID-based identifiers.

Add a unique database constraint and bounded retry.

## 12.5 Duplicate Order Detection

Detect based on configurable combinations of same phone, customer, address, product, Page, time window, and total.

Duplicate detection should warn, not silently block, unless a business rule says otherwise.

## 12.6 Returns and Refunds

Support return request, return reason, item inspection, restock decision, refund amount, refund method, partial refund, full refund, courier return tracking, and audit trail.

Inventory must only be restored after approved inspection.

## 12.7 Receipts and Invoices

Create receipt number, invoice number, printable HTML/PDF, business details, customer details, line items, discount, shipping, tax, payment status, order source, and agent.

---

# 13. PHASE 7 — COURIER MANAGEMENT

## 13.1 Courier Configuration

Each courier should include:

```text
Courier Name
API Base URL
Credentials
Pickup Address
COD Support
Service Types
Coverage Areas
Shipping Rules
Tracking Webhook
Retry Policy
Export Format
Status Mapping
```

## 13.2 Courier Booking

```text
Order Ready
    ↓
Validate Address
    ↓
Validate Courier Coverage
    ↓
Create Shipment
    ↓
Store Tracking Number
    ↓
Update Shipping Status
```

## 13.3 Courier Tracking Sync

Use webhooks when available and scheduled sync when needed. Apply idempotent status updates, retry with backoff, and dead-letter handling.

Normalize courier statuses into internal statuses.

## 13.4 Courier Export

Support configurable export formats for J&T, Flash, LBC, Ninja Van, and other CSV/XLSX formats.

Do not hardcode one courier’s columns inside controllers.

---

# 14. PHASE 8 — BUSINESS, SHOP, AND PAGE SETTINGS

## 14.1 Business Settings

Include business name, legal name, logo, timezone, currency, language, date format, contact details, tax information, data retention, default roles, and security policies.

## 14.2 Shop Settings

Include shop name, shop code, default warehouse, default courier, default payment method, order number format, receipt prefix, invoice prefix, operating hours, default language, order rules, and duplicate rules.

## 14.3 Facebook Page Settings

Include Page identity, brand, team, agents, allowed products, price list, warehouse, courier, business hours, saved reply group, auto-assignment rule, message rules, order source, webhook health, token health, and last sync.

## 14.4 Order Settings

Include required fields, default statuses, discount limits, free-shipping rules, COD limits, cancellation reasons, return reasons, duplicate detection rules, and address validation rules.

## 14.5 UI Customization

Support logo, accent color, theme, dashboard widget layout, visible widgets, inbox columns, order form fields, custom tags, and custom status labels.

Do not allow customization to weaken security or Meta policy enforcement.

---

# 15. PHASE 9 — AUTOMATION ENGINE

## 15.1 Rule Engine

Each rule should include:

```text
Trigger
Conditions
Actions
Priority
Status
Execution Limit
Cooldown
Created By
Last Run
Failure Count
```

## 15.2 Supported Triggers

```text
New Conversation
New Message
Phone Detected
Customer Matched
Conversation Unassigned
Order Created
Order Confirmed
Order Cancelled
Payment Received
Shipment Updated
Failed Delivery
Low Stock
Token Expiring
Webhook Failed
SLA Breach
```

## 15.3 Supported Conditions

```text
Page
Brand
Team
Agent
Customer Segment
Risk Level
Product
Order Value
Message Contains
Phone Detected
Time of Day
Day of Week
Order Status
Courier
```

## 15.4 Supported Actions

```text
Assign Agent
Assign Team
Add Tag
Send Saved Reply
Create Follow-Up
Notify Supervisor
Set Conversation Status
Create Draft Order
Update Customer Segment
Trigger Courier Booking
Create Alert
Pause Page Automation
```

Every action must be auditable.

## 15.5 Safety Rules

Automation must never bypass Messenger policy, send prohibited messages, merge customers without safe proof, change verified data without audit, deduct stock without transaction protection, or delete records without authorization.

---

# 16. PHASE 10 — DASHBOARD AND ANALYTICS

## 16.1 Operational Dashboard

Top cards:

```text
Connected Pages
Open Conversations
Orders Today
For Encoding
Failed Syncs
Low Stock
```

Main sections:

```text
Live Inbox Queue
Orders by Status
Agent Workload
Page Health
Sales Trend
Conversion by Page
Top Products
Courier Status
Critical Alerts
```

Remove development-only “Build Modules” cards from the production dashboard.

## 16.2 Metric Definitions

Use exact definitions:

```text
Gross Order Value
Confirmed Sales
Shipped Sales
Delivered Revenue
Collected Revenue
Refunded Amount
Net Revenue
Outstanding Receivables
```

Do not label different formulas as generic `Revenue`.

## 16.3 Dashboard Performance

Do not load all widgets synchronously.

```text
Initial Layout
    ↓
Lightweight Summary
    ↓
Lazy-Load Individual Widgets
    ↓
Cache Expensive Queries
```

Add per-widget loading, per-widget errors, date range filters, Page filters, team filters, agent filters, and product filters.

## 16.4 Real-Time Updates

Use WebSocket or SSE for new messages, new orders, order status changes, agent assignment, low stock, Page connection health, and courier updates.

Every channel must be authenticated and tenant-scoped.

## 16.5 Reports

Create report areas for sales, orders, conversations, agents, Pages, products, inventory, courier, customers, and Meta integration health.

Support CSV export, XLSX export, scheduled reports, date ranges, filters, and saved report templates.

---

# 17. PHASE 11 — NOTIFICATIONS AND ALERTS

Notify appropriate users about:

```text
Token Expiring
Page Disconnected
Webhook Failure
Message Send Failure
SLA Breach
Unassigned Conversation
Order Failure
Low Stock
Courier Booking Failure
Failed Delivery
Refund Request
Security Event
```

Support in-app notifications, email, optional Slack or Telegram integrations, and notification preferences.

---

# 18. PHASE 12 — SECURITY CONTROLS

Implement secure HTTP-only cookies, SameSite protection, CSRF protection, session rotation, MFA for administrators, rate limiting, IP and device logs, secret management, encrypted tokens, input validation, output escaping, file upload validation, Content Security Policy, dependency scanning, and audit logging.

Protect against:

```text
SQL Injection
XSS
CSRF
SSRF
IDOR
Mass Assignment
Open Redirects
Path Traversal
Replay Attacks
Webhook Forgery
Tenant Data Leakage
```

---

# 19. PHASE 13 — TESTING

## 19.1 Unit Tests

Test OAuth state, token encryption, webhook signature, event fingerprinting, messaging eligibility, phone normalization, customer matching, inventory calculation, order totals, metric definitions, and role checks.

## 19.2 Feature Tests

Test OAuth callback, Page connection, Page disconnection, webhook processing, duplicate events, message reply, conversation assignment, customer linking, order creation, POS checkout, inventory reservation, cancellation, return, refund, courier booking, settings update, and automation execution.

## 19.3 Security Tests

Test cross-tenant access, forged webhooks, missing OAuth state, replayed OAuth state, unauthorized exports, unauthorized disconnect, token leakage, stored XSS, role escalation, and IDOR.

## 19.4 Concurrency Tests

Test concurrent checkout, stock reservation, order number generation, duplicate webhook delivery, duplicate courier callback, and simultaneous customer merge.

## 19.5 Performance Tests

Measure inbox load time, dashboard initial load, widget query time, webhook acknowledgment time, queue delay, checkout time, customer search time, and large export time.

---

# 20. PHASE 14 — MONITORING

Track:

```text
Meta API Requests
Meta API Errors
Meta API Latency
Rate-Limit Usage
Invalid Tokens
Webhook Signature Failures
Webhook Queue Delay
Webhook Processing Failure
Dead-Letter Events
Message Send Failure
Policy-Blocked Messages
Page Health
Dashboard Query Time
Inventory Transaction Failure
Courier Sync Failure
```

Add correlation IDs across HTTP requests, Meta API requests, webhook events, queue jobs, database transactions, and audit logs.

---

# 21. PHASE 15 — DOCUMENTATION

Create:

```text
docs/architecture.md
docs/tenancy.md
docs/roles-and-permissions.md
docs/meta-integration.md
docs/meta-app-review.md
docs/meta-webhooks.md
docs/meta-token-lifecycle.md
docs/messenger-policy.md
docs/inbox-workflow.md
docs/customer-identity.md
docs/order-lifecycle.md
docs/inventory-lifecycle.md
docs/courier-integration.md
docs/automation-engine.md
docs/dashboard-metrics.md
docs/data-deletion.md
docs/security.md
docs/deployment.md
docs/rollback.md
docs/incident-response.md
```

---

# 22. REQUIRED DATABASE REVIEW

Review and add migrations where needed for:

```text
tenants
shops
teams
roles
permissions
user_roles
facebook_accounts
facebook_pages
facebook_page_settings
meta_webhook_events
conversations
messages
customers
customer_identities
customer_addresses
customer_segments
products
product_variants
page_products
price_lists
warehouses
inventory_balances
inventory_movements
orders
order_items
payments
refunds
returns
shipments
couriers
courier_configs
automation_rules
automation_runs
notifications
audit_logs
deletion_requests
dashboard_widgets
saved_reports
```

Add foreign keys, unique indexes, tenant composite indexes, status indexes, date indexes, and idempotency keys.

---

# 23. REQUIRED ENVIRONMENT CONFIGURATION

Document and validate:

```env
APP_ENV=
APP_URL=
QUEUE_CONNECTION=
CACHE_STORE=
SESSION_DRIVER=
BROADCAST_CONNECTION=

META_APP_ID=
META_APP_SECRET=
META_GRAPH_API_VERSION=
META_OAUTH_REDIRECT_URI=
META_WEBHOOK_VERIFY_TOKEN=
META_WEBHOOK_URL=
META_HTTP_TIMEOUT=
META_MAX_RETRIES=
META_RETRY_BASE_DELAY_MS=
META_RETRY_MAX_DELAY_MS=

DATABASE_URL=
REDIS_URL=
MAIL_MAILER=
FILESYSTEM_DISK=
```

No secrets may be stored in source control.

---

# 24. DEPLOYMENT CHECKLIST

Before deployment:

1. Run migrations.
2. Run unit tests.
3. Run feature tests.
4. Run security tests.
5. Run static analysis.
6. Run code formatting.
7. Validate environment variables.
8. Verify queue workers.
9. Verify scheduler.
10. Verify WebSocket/SSE server.
11. Verify Meta OAuth callback.
12. Verify webhook endpoint.
13. Verify secret storage.
14. Verify backups.
15. Verify monitoring.
16. Verify rollback plan.
17. Verify App Review assets.
18. Verify deletion endpoint.
19. Verify tenant isolation.
20. Verify critical alerts.

---

# 25. REQUIRED EXECUTION ORDER

Execute in this exact order:

```text
1. Codebase Audit
2. Tenant Isolation
3. Roles and Permissions
4. OAuth Security
5. Token Encryption
6. Webhook Security
7. Queue-Based Webhooks
8. Token Lifecycle
9. Meta Disconnect
10. Page Configuration
11. Inbox Workflow
12. Messenger Eligibility
13. Customer Matching
14. Order Transaction Integrity
15. Inventory Reservation
16. POS Completion
17. Courier Integration
18. Business and Shop Settings
19. Automation Engine
20. Dashboard Refactor
21. Reports
22. Monitoring
23. Testing
24. Documentation
25. Deployment Readiness
```

Do not skip ahead while Critical issues remain.

---

# 26. DEFINITION OF DONE

## Security

- [ ] Tenant isolation is enforced
- [ ] RBAC is enforced
- [ ] OAuth state is secure
- [ ] Tokens are encrypted
- [ ] Invalid webhooks are rejected
- [ ] Secrets are not logged
- [ ] Sensitive actions are audited

## Meta Integration

- [ ] Pages connect correctly
- [ ] Pages can be configured
- [ ] Webhooks process asynchronously
- [ ] Duplicate events are prevented
- [ ] Tokens are monitored
- [ ] Messenger policy is enforced
- [ ] Delivery and read receipts work
- [ ] Disconnect works on Meta and locally
- [ ] Data deletion workflow exists

## Inbox and CRM

- [ ] Conversation workflow is correct
- [ ] Agent assignment works
- [ ] Customer matching is deliberate
- [ ] Customer data is protected
- [ ] Customer merge is safe
- [ ] SLA tracking works
- [ ] Saved replies work

## POS and Orders

- [ ] POS totals are consistent
- [ ] Order statuses are separated
- [ ] Inventory reservations work
- [ ] Stock cannot oversell
- [ ] Stock movements reference real records
- [ ] Returns and refunds are auditable
- [ ] Receipts and invoices work

## Configuration

- [ ] Business settings work
- [ ] Shop settings work
- [ ] Page settings work
- [ ] Order settings work
- [ ] Courier settings work
- [ ] UI customization works
- [ ] Hardcoded business values are removed

## Automation

- [ ] Rule engine works
- [ ] Rules are auditable
- [ ] Unsafe actions are blocked
- [ ] Failures retry safely

## Dashboard and Reports

- [ ] Operational dashboard is useful
- [ ] Development cards are removed
- [ ] Metrics are clearly defined
- [ ] Heavy widgets lazy-load
- [ ] Reports support filters and exports
- [ ] Real-time updates are tenant-safe

## Quality

- [ ] Automated tests pass
- [ ] Critical and High issues are resolved
- [ ] Documentation is complete
- [ ] Monitoring is active
- [ ] Deployment and rollback are documented

---

# 27. REQUIRED FINAL IMPLEMENTATION REPORT

After execution, return:

```markdown
# Final Implementation Report

## Executive Summary

## Architecture Changes

## Files Changed

## Database Migrations

## Security Fixes

## Meta Integration Fixes

## Inbox and CRM Changes

## POS and Order Changes

## Product and Inventory Changes

## Courier Changes

## Settings and Configuration

## Automation Engine

## Dashboard and Reports

## Tests Added

## Performance Results

## Commands Run

## Remaining Risks

## Blocked Items

## Production Readiness

READY / NOT READY

## Final Checklist

- [ ] Critical issues resolved
- [ ] High issues resolved
- [ ] Tests passing
- [ ] Migrations safe
- [ ] Documentation complete
- [ ] Monitoring enabled
```

Do not claim `READY` if any Critical or High issue remains.

---

# 28. FINAL INSTRUCTION

Start by producing a codebase audit table:

```text
Module
Existing Files
Current Behavior
Risk
Required Change
Migration Needed
Tests Needed
Dependencies
Priority
```

Then execute the implementation phase by phase.

Do not add random dashboard cards.

Do not build new automation before securing Meta integration.

Do not expand features before tenant isolation, permissions, webhook reliability, token security, and inventory integrity are fixed.

The immediate priority is:

```text
1. Tenant Isolation
2. Roles and Permissions
3. OAuth Security
4. Token Encryption
5. Webhook Rejection and Queues
6. Token Lifecycle
7. Page Configuration
8. Inbox Workflow
9. Customer Identity
10. Order and Inventory Integrity
```
