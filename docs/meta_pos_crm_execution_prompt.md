# Execution Prompt: Harden and Complete Facebook Meta Integration + POS/CRM Infrastructure

## Role

Act as a **senior backend architect, Laravel engineer, security reviewer, and Meta Platform integration specialist**.

Your task is to inspect the existing codebase, implement the required fixes, and verify that the system is safe, reliable, compliant, and production-ready.

Do not only provide recommendations. Execute the required code changes, migrations, tests, refactors, configuration updates, and documentation.

---

# 1. Primary Objective

Fix and complete the current Facebook Meta integration and related POS/CRM infrastructure based on the existing evaluation findings.

The required outcome is:

```text
Secure OAuth
+ Encrypted Token Storage
+ Reliable Meta Webhooks
+ Queue-Based Event Processing
+ Messenger Policy Enforcement
+ Correct Token Lifecycle Handling
+ Reliable POS Inventory Transactions
+ Consistent Analytics
+ Production-Ready Tests and Monitoring
```

The Meta integration must not be considered complete until all Critical and High priority issues are resolved and verified with automated tests.

---

# 2. Working Rules

Follow these rules during execution:

1. Inspect the current implementation before changing code.
2. Preserve existing working behavior unless it is unsafe or incorrect.
3. Do not make speculative large rewrites without proving they are necessary.
4. Keep changes modular and testable.
5. Use database transactions for operations that affect orders, inventory, payments, or identity merging.
6. Use queues for webhook and other noninteractive background work.
7. Never expose access tokens, secrets, or private customer data in logs.
8. Never bypass Meta messaging, permission, or privacy restrictions.
9. Add migrations instead of editing production database structures manually.
10. Add automated tests for every Critical, High, and Medium fix.
11. Document every behavior change.
12. Return a final implementation report with completed, partially completed, and blocked items.
13. Do not mark an item complete without evidence from code, tests, or runtime verification.

---

# 3. Current System Context

The existing system includes:

- Laravel backend
- Facebook OAuth connection
- Facebook account and Page storage
- Meta webhook controller
- Messenger message sending
- Customer identity and phone matching
- CRM profiles, addresses, notes, risk levels, timelines, and merging
- POS checkout
- Order management
- Inventory deduction
- Dashboard and sales analytics
- Redis-based caching
- PostgreSQL-specific analytics queries

Known code locations include:

```text
FacebookConnectorService.php
ShopController.php
MetaWebhookController.php
CustomerIdentityService.php
FacebookAccount.php
FacebookPage.php
Order.php
SalesDashboardController.php
SalesDashboardService.php
```

Search the codebase for exact classes, methods, routes, jobs, migrations, and tests before making changes.

---

# 4. Required Execution Order

Execute work in this order:

```text
Phase 1: Critical Security and Reliability
Phase 2: Meta Compliance and Messaging
Phase 3: POS and Order Data Integrity
Phase 4: Dashboard and Performance
Phase 5: Testing, Monitoring, and Documentation
```

Do not begin lower-priority feature work while Critical or High issues remain unresolved.

---

# 5. Phase 1 — Critical Security and Reliability

## 5.1 Reject Invalid Webhook Signatures

### Problem

The system calculates whether the Meta webhook signature is valid but still processes invalid events.

### Required Implementation

Update the webhook receiver so that:

1. The raw request body is used for HMAC validation.
2. The expected signature is calculated using the Meta App Secret.
3. Signature comparison uses `hash_equals`.
4. Missing or invalid signatures return HTTP `403`.
5. Invalid events are never sent to the ingestor or queue.
6. Secret values and full access tokens are never logged.
7. A sanitized security log entry is created.

### Required Behavior

```php
if (! $signatureValid) {
    Log::warning('Rejected invalid Meta webhook signature', [
        'request_id' => $requestId,
        'ip' => $request->ip(),
    ]);

    return response()->json([
        'error' => 'Invalid webhook signature',
    ], 403);
}
```

### Acceptance Criteria

- Forged webhook payloads are rejected.
- Valid signed payloads are accepted.
- Invalid payloads create no conversation, customer, message, order, or automation records.
- Automated tests cover valid, invalid, and missing signatures.

---

## 5.2 Move Webhook Processing to a Queue

### Problem

Webhook events are processed synchronously.

### Required Architecture

```text
Meta Webhook
    ↓
Verify Signature
    ↓
Normalize Event
    ↓
Store Event
    ↓
Dispatch Queue Job
    ↓
Return HTTP 200
    ↓
Worker Processes Event
```

### Required Implementation

Create or update:

```text
MetaWebhookEvent model
ProcessMetaWebhookEvent job
Webhook event repository or service
Failed-event handling
Queue configuration
```

The controller must only:

1. Verify the request.
2. Parse lightweight metadata.
3. Store the event.
4. Dispatch a job.
5. Return success immediately.

Move all of the following out of the HTTP request:

- Customer resolution
- Sentiment analysis
- Phone detection
- Message persistence
- Conversation updates
- Automation triggers
- External API requests
- Media downloads

### Event Statuses

Use explicit statuses:

```text
RECEIVED
QUEUED
PROCESSING
PROCESSED
FAILED
REJECTED
DEAD_LETTER
```

### Acceptance Criteria

- Controller response does not wait for business processing.
- Webhook event is processed by a queue worker.
- Duplicate events do not create duplicate records.
- Failed jobs retry with bounded attempts.
- Exhausted jobs are marked dead-letter.
- Automated tests confirm asynchronous dispatch.

---

## 5.3 Implement Stable Webhook Idempotency

### Problem

The fallback event hash may vary because object-key ordering is not stable.

### Required Implementation

Use Meta-provided identifiers whenever available:

```text
mid
message_id
leadgen_id
post_id
comment_id
sender_id
recipient_id
event timestamp
```

When no stable provider identifier exists:

1. Recursively sort associative keys.
2. Preserve indexed array order.
3. Serialize with stable JSON flags.
4. Hash the resulting canonical payload.
5. Include event type and Page ID in the fingerprint.

Example:

```php
$eventKey = hash('sha256', implode('|', [
    $pageId,
    $eventType,
    $canonicalPayload,
]));
```

Add a unique database constraint for the event key.

### Acceptance Criteria

- Replayed identical events map to the same event key.
- Duplicate events are ignored safely.
- Different logical events do not collide in normal operation.
- Concurrency tests confirm only one event record is created.

---

## 5.4 Replace OAuth State Handling

### Problem

The current implementation uses Laravel’s session CSRF token as OAuth state, and state validation is optional.

### Required Implementation

Create a dedicated OAuth state service.

The state must be:

- Generated using `random_bytes`
- At least 256 bits
- Stored server-side
- Bound to the authenticated user and session
- Short-lived
- Required in the callback
- Single-use
- Invalidated after validation

Suggested implementation:

```php
$state = bin2hex(random_bytes(32));

session([
    'facebook_oauth_state' => hash('sha256', $state),
    'facebook_oauth_state_expires_at' => now()->addMinutes(10),
]);
```

Validate:

```php
abort_unless($request->filled('state'), 403, 'Missing OAuth state.');

abort_unless(
    now()->lessThanOrEqualTo(session('facebook_oauth_state_expires_at'))
    && hash_equals(
        session('facebook_oauth_state'),
        hash('sha256', $request->string('state')->toString())
    ),
    403,
    'OAuth state mismatch.'
);
```

After validation:

```php
session()->forget([
    'facebook_oauth_state',
    'facebook_oauth_state_expires_at',
]);
```

### Acceptance Criteria

- Missing state is rejected.
- Invalid state is rejected.
- Expired state is rejected.
- Reused state is rejected.
- Valid state succeeds once.
- Automated tests cover every case.

---

## 5.5 Encrypt All Meta Access Tokens

### Problem

The user-level Facebook token is stored in plaintext.

### Required Implementation

Update `FacebookAccount` so sensitive fields use encrypted casts.

Example:

```php
protected $casts = [
    'access_token' => 'encrypted',
    'token_expires_at' => 'datetime',
    'data_access_expires_at' => 'datetime',
];
```

Review all token-bearing models and fields:

```text
FacebookAccount.access_token
FacebookPage.page_access_token
Instagram access tokens
Refresh tokens
System-user tokens
Webhook-related secrets
```

Add a migration or secure transition strategy for existing plaintext values.

Possible approaches:

1. Read existing plaintext values and rewrite them through the encrypted model cast.
2. Mark existing connections for reconnect.
3. Invalidate compromised or unknown-format tokens.

Do not double-encrypt existing encrypted values.

### Acceptance Criteria

- Database values are unreadable ciphertext.
- Tokens decrypt correctly through the model.
- Tokens are never included in model serialization.
- Tokens are hidden from logs and debug output.
- Existing records are migrated safely or marked for reconnect.

---

## 5.6 Add Token Lifecycle Management

### Required Data

Add or use:

```text
token_expires_at
data_access_expires_at
last_validated_at
last_validation_error
connection_status
reconnect_required_at
```

### Connection Statuses

```text
ACTIVE
EXPIRING
EXPIRED
REVOKED
PERMISSION_MISSING
RECONNECT_REQUIRED
DISCONNECTED
```

### Required Behavior

1. Store token expiry data during OAuth callback.
2. Validate tokens after connection.
3. Schedule recurring token-health checks.
4. Mark connections degraded before expiry.
5. Stop operations using invalid tokens.
6. Show reconnect instructions in the UI.
7. Update existing records rather than creating duplicate connections.

### Acceptance Criteria

- Expired tokens are detected.
- Revoked tokens are detected.
- Missing permissions are detected separately.
- UI and API expose connection health without exposing tokens.
- Scheduled validation is implemented and tested.

---

## 5.7 Implement Proper Meta Disconnect

### Required Flow

When disconnecting a Page:

1. Authorize the requesting local user.
2. Attempt to unsubscribe the Page from the app.
3. Attempt to revoke permissions when the user requests full Facebook disconnection.
4. Stop webhook processing for the Page.
5. Remove or null encrypted tokens.
6. Mark connection as disconnected.
7. Record an audit log.
8. Preserve only data allowed by the retention policy.

Possible Meta call:

```http
DELETE /{page-id}/subscribed_apps
```

Handle partial failure:

```text
Meta unsubscribe failed
Local connection marked DISCONNECT_PENDING
Retry job scheduled
Administrator informed
```

### Acceptance Criteria

- Local-only disconnect is no longer the sole behavior.
- Meta-side unsubscribe is attempted and logged.
- Page stops receiving new webhook processing.
- Disconnect action is auditable.
- Retry behavior exists for temporary failures.

---

# 6. Phase 2 — Meta Compliance and Messaging

## 6.1 Enforce the Messenger Response Window

### Problem

Every outbound message uses `messaging_type: RESPONSE` without checking eligibility.

### Required Data

Store or calculate:

```text
last_customer_message_at
standard_window_expires_at
message_eligibility
last_outbound_failure_code
```

### Required Behavior

Before sending:

1. Confirm the Page connection is active.
2. Confirm `pages_messaging` permission exists.
3. Confirm the conversation is eligible for normal response.
4. Reject disallowed sends before calling Meta.
5. Return a clear user-facing reason.
6. Record policy-related failures separately from technical failures.

Example domain service:

```text
MessengerEligibilityService
- canSendResponse()
- standardWindowExpiresAt()
- allowedMessageMethods()
- reason()
```

### UI Behavior

```text
Reply Allowed
Response window ends in 2h 41m
```

or:

```text
Normal Reply Unavailable
The standard response window has expired.
```

### Acceptance Criteria

- Out-of-window normal replies are blocked locally.
- Eligible replies still work.
- Message composer displays current eligibility.
- Automated tests cover inside-window, edge-time, and expired cases.

---

## 6.2 Add Message Delivery and Read Tracking

### Required Webhook Fields

Subscribe only when supported and used:

```text
messages
messaging_postbacks
message_deliveries
message_reads
message_reactions
messaging_referrals
feed
```

Add handover fields only when the handover protocol is actually implemented:

```text
messaging_handovers
standby
```

### Message Statuses

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

### Acceptance Criteria

- Outbound messages store Meta message IDs.
- Delivery webhook updates message status.
- Read webhook updates message status.
- Duplicate receipts are idempotent.
- UI does not show `sent` when the message is only queued.

---

## 6.3 Add API Rate-Limit and Retry Handling

### Required Implementation

Create a centralized Meta API client.

Responsibilities:

```text
Authentication headers
Versioned Graph API URLs
Timeouts
Retry classification
Exponential backoff
Random jitter
Usage-header capture
Sanitized logging
Correlation IDs
```

### Retryable

```text
HTTP 429
Selected HTTP 5xx
Connection timeouts
Temporary platform errors
```

### Non-Retryable

```text
Invalid token
Missing permission
Invalid object
Invalid parameter
Policy restriction
Unsupported API version
```

### Backoff

```text
delay = min(maxDelay, baseDelay × 2^attempt) + randomJitter
```

Add per-Page or per-token concurrency control.

### Acceptance Criteria

- 429 responses are retried safely.
- Authorization errors are not retried forever.
- Rate-limit usage headers are recorded.
- Sustained throttling produces an alert or degraded status.
- Automated tests mock rate-limit and temporary-failure responses.

---

## 6.4 Add Human Agent Identity Support

Where supported by the application design:

- Record the local agent responsible for each outbound message.
- Display the agent identity in the CRM.
- Support Meta persona or supported agent-identification mechanisms only when configured correctly.
- Do not fake a human identity.
- Do not claim Meta supports a field without checking the current Graph API version.

### Acceptance Criteria

- Every manual message has an internal agent ID.
- Audit logs identify who initiated the message.
- Unsupported persona features fail safely or remain disabled.

---

## 6.5 Implement Meta Data Deletion Workflow

### Required Components

```text
Public deletion request endpoint
Meta data-deletion callback or instructions page
DeletionRequest model
Deletion processing job
Deletion status endpoint
Audit trail
Retention policy
```

### Required Flow

1. Receive deletion request.
2. Verify authenticity or ownership.
3. Generate confirmation code.
4. Mark request pending.
5. Identify Meta-derived records.
6. Delete or anonymize records according to policy.
7. Remove active tokens and subscriptions when applicable.
8. Process caches and search indexes.
9. Handle backups according to documented retention.
10. Mark request complete.
11. Return a status URL or confirmation.

### Acceptance Criteria

- Deletion flow is publicly accessible.
- Request status can be tracked.
- Meta-derived customer data can be located.
- Deletion does not break unrelated tenant data.
- Tests verify isolation and completion.

---

# 7. Phase 3 — POS and Order Data Integrity

## 7.1 Fix Stock Movement Reference IDs

### Problem

Stock is deducted using `referenceId: 0`.

### Required Transaction Flow

```text
Begin Transaction
    ↓
Create Draft Order
    ↓
Lock Inventory Rows
    ↓
Validate Stock
    ↓
Reserve or Deduct Stock Using Real Order ID
    ↓
Create Stock Movement Records
    ↓
Confirm Order
    ↓
Commit
```

Use:

```php
lockForUpdate()
```

for relevant inventory rows.

### Acceptance Criteria

- Every stock movement references a real order.
- No stock movement uses `referenceId: 0`.
- Concurrent checkout cannot oversell the same stock.
- Failed checkout rolls back order and inventory changes.
- Tests simulate concurrent requests.

---

## 7.2 Add Inventory Reservation

Create separate inventory values or movements for:

```text
available
reserved
committed
damaged
returned_pending_inspection
```

Suggested behavior:

```text
Draft order → optional reservation
Confirmed order → reserve or commit
Packed order → finalize deduction
Cancelled order → release reservation
Returned order → inspection before restock
```

Define one consistent inventory lifecycle and apply it across:

- POS checkout
- Manual order creation
- Messenger order creation
- Split orders
- Returns
- Cancellations

### Acceptance Criteria

- Inventory lifecycle is documented.
- Reservation release is idempotent.
- Cancellation does not double-return stock.
- Order splits preserve stock accuracy.

---

## 7.3 Fix Order Number Race Condition

Replace:

```text
Count today’s orders + 1
```

Use one of:

- Database sequence
- Locked daily counter table
- ULID/UUID plus readable prefix
- Unique constraint with bounded retry

Recommended readable format:

```text
ORD-YYYYMMDD-{atomic-sequence}
```

Add a unique database index.

### Acceptance Criteria

- Concurrent order creation cannot generate duplicates.
- Order number remains readable.
- Tests create multiple orders concurrently.

---

## 7.4 Stop Auto-Resolving Conversations on Order Creation

### Required Behavior

Order creation must not automatically mean support is complete.

Use conversation states such as:

```text
ACTIVE
ORDER_CREATED
AWAITING_CUSTOMER
AWAITING_PAYMENT
AWAITING_CONFIRMATION
RESOLVED
```

Define explicit transition rules.

Example:

```text
Message Received → ACTIVE
Order Created → ORDER_CREATED
Waiting for Customer Details → AWAITING_CUSTOMER
Issue Completed → RESOLVED
```

### Acceptance Criteria

- Creating an order does not automatically resolve the conversation.
- Existing resolved conversations are not reopened unnecessarily.
- Status transitions are covered by tests.
- Agents can explicitly resolve conversations.

---

## 7.5 Prevent Customer Data Overwrites

### Problem

Returning customers may have verified fields overwritten by new unverified input.

### Required Rules

Use field confidence or source priority.

Example priority:

```text
Verified agent-confirmed value
Verified customer profile value
Previous successful order value
New unverified message extraction
Fallback placeholder
```

Do not overwrite:

- Verified name
- Verified phone
- Verified canonical address
- Manually corrected address

without an explicit user action or stronger source.

### Acceptance Criteria

- Existing verified data survives repeat orders.
- New conflicting data is stored as a candidate or new address.
- Agent can choose whether to update the canonical profile.
- Audit logs record profile changes.

---

## 7.6 Fix Phone Query Scope

Wrap the phone conditions:

```php
Customer::query()
    ->where(function ($query) use ($normalized, $phone) {
        $query
            ->where('normalized_phone', $normalized)
            ->orWhere('phone', $phone);
    });
```

Add tenant scoping where required.

### Acceptance Criteria

- Phone lookup cannot escape tenant or additional query filters.
- Tests cover compound queries and cross-tenant isolation.

---

## 7.7 Add Verified PSID-to-Customer Linking

Create an agent workflow:

```text
Phone detected in conversation
    ↓
Potential customer matches shown
    ↓
Agent selects or creates customer
    ↓
Agent confirms identity link
    ↓
CustomerIdentity record updated
```

Do not automatically merge customers based solely on an unverified phone extracted from text.

### Acceptance Criteria

- Agent sees identity-link suggestions.
- Conflicting matches are clearly shown.
- Link action is auditable.
- Customer merge remains a separate deliberate action.

---

## 7.8 Complete POS Features

Implement or clearly scope:

```text
Shipping fee
Tax rate
Tax amount
Receipt number
Printable receipt
Invoice generation
Refund processing
Return processing
Hold transaction
Resume transaction
Void transaction
Payment status
Partial payment
```

Do not add fake placeholder endpoints. Implement complete transaction rules.

### Acceptance Criteria

- POS and manual order totals use the same calculation service.
- Receipt numbers are unique.
- Refunds and returns create auditable records.
- Inventory adjusts correctly.
- Held transactions do not deduct committed stock prematurely.

---

# 8. Phase 4 — Dashboard and Performance

## 8.1 Unify Metric Definitions

Create a central metric-definition document and service.

Required distinct metrics:

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

Do not use one generic `revenue` label for different formulas.

### Acceptance Criteria

- Every dashboard widget displays its exact metric definition.
- Invoice-based and order-based values are clearly separated.
- Shared calculations use a centralized service.
- Tests verify formulas.

---

## 8.2 Lazy-Load Dashboard Widgets

Change the dashboard so the initial request loads only:

- Layout
- User configuration
- Lightweight summary
- Widget placeholders

Load expensive widgets through separate endpoints.

Add:

- Per-widget loading state
- Per-widget error state
- Date range parameters
- Cancellation of stale requests
- Permission checks per endpoint

### Acceptance Criteria

- Initial page no longer executes all 12 heavy queries.
- Individual widgets can fail without breaking the dashboard.
- Date range is supported consistently.
- Performance measurements are recorded before and after.

---

## 8.3 Cache Expensive Analytics

Cache:

```text
Cohort retention
Predictive insights
Moving averages
Product rankings
Agent leaderboards
Monthly summaries
Anomaly detection
```

Use cache keys that include:

```text
tenant
date range
timezone
filters
metric version
```

Do not directly depend on `Cache::getRedis()` in controllers.

Create a cache abstraction or service.

### Acceptance Criteria

- Cache driver can change without controller changes.
- Cache invalidation is defined.
- Expensive query count decreases.
- Tests work with non-Redis cache drivers.

---

## 8.4 Handle Database Portability

Identify PostgreSQL-specific SQL such as:

```sql
DATE_TRUNC()
```

Choose one approach:

1. Declare PostgreSQL as an explicit system requirement.
2. Add database-driver-specific query strategies.
3. Use ORM/database-agnostic date grouping where practical.

Do not pretend the code is database-agnostic if it is not.

### Acceptance Criteria

- Supported database engines are documented.
- Test environment matches production or compatibility is implemented.
- Unsupported engines fail clearly.

---

## 8.5 Add Real-Time Dashboard Updates

Use WebSocket or Server-Sent Events for selected events:

```text
New order
Order status update
New message
Agent assignment
Inventory warning
Connection health change
```

Do not stream every analytic calculation in real time.

### Acceptance Criteria

- Real-time updates are authenticated and tenant-scoped.
- Unauthorized users cannot subscribe to another tenant.
- Reconnect and missed-event recovery are implemented.

---

# 9. Phase 5 — Testing, Monitoring, and Documentation

## 9.1 Required Automated Tests

Create:

### Unit Tests

```text
OAuth state generation and validation
Token encryption casts
Webhook signature verification
Canonical event hashing
Retry classification
Messaging window eligibility
Metric calculations
Phone normalization and scoped lookup
```

### Feature Tests

```text
Facebook OAuth callback
Missing OAuth state
Expired OAuth state
Webhook valid signature
Webhook invalid signature
Webhook job dispatch
Duplicate event handling
Message send eligibility
Meta disconnect
Customer data deletion
POS checkout transaction
Stock rollback
Order number uniqueness
Conversation status transition
```

### Queue Tests

```text
Successful webhook processing
Retryable webhook failure
Dead-letter transition
Duplicate job execution
Idempotent message import
```

### Security Tests

```text
Cross-tenant access
Forged webhook
Token exposure in API responses
Unauthorized Page disconnect
Unauthorized customer export
Mass-assignment protection
Stored XSS from message content
```

---

## 9.2 Monitoring

Add metrics for:

```text
Meta API request count
Meta API error rate
Meta API latency
Rate-limit usage
Invalid token count
Webhook events received
Webhook signature failures
Webhook queue delay
Webhook processing failures
Dead-letter events
Message send failures
Messages blocked by policy
Page connection health
Token expiry warnings
Dashboard query time
Inventory transaction failures
```

Add correlation IDs across:

```text
HTTP request
Meta API request
Webhook event
Queue job
Database transaction
Audit log
```

---

## 9.3 Audit Logging

Audit:

```text
Facebook connection created
Facebook connection refreshed
Facebook Page connected
Facebook Page disconnected
Permissions changed
Token invalidated
Message sent
Message blocked by policy
Customer identity linked
Customer merged
Order created
Stock reserved
Stock deducted
Refund issued
Customer data deleted
User role changed
```

Do not store secrets in audit metadata.

---

## 9.4 Documentation

Create or update:

```text
docs/meta-integration.md
docs/meta-app-review.md
docs/meta-webhooks.md
docs/meta-token-lifecycle.md
docs/messenger-policy-enforcement.md
docs/data-deletion.md
docs/pos-inventory-lifecycle.md
docs/dashboard-metric-definitions.md
docs/incident-response.md
```

Include:

- Environment variables
- Queue worker requirements
- Scheduler requirements
- Webhook URL setup
- OAuth redirect setup
- Required Meta permissions
- Reconnect process
- Disconnect process
- Failure recovery
- Deployment checklist
- Rollback procedure

---

# 10. Database Changes

Review and add migrations as needed.

Potential additions:

```text
facebook_accounts
- token_expires_at
- data_access_expires_at
- last_validated_at
- last_validation_error
- connection_status
- reconnect_required_at

facebook_pages
- subscription_status
- last_webhook_at
- last_sync_at
- connection_status

meta_webhook_events
- event_key
- page_id
- event_type
- payload
- signature_valid
- status
- retry_count
- processed_at
- last_error

messages
- provider_message_id
- send_status
- delivered_at
- read_at
- failure_code
- failure_message
- agent_id

conversations
- last_customer_message_at
- response_window_expires_at

inventory_movements
- reference_type
- reference_id
- movement_type
- quantity
- idempotency_key

deletion_requests
- confirmation_code
- status
- requested_at
- completed_at
- failure_reason
```

Add required:

- Foreign keys
- Unique indexes
- Tenant-aware composite indexes
- Status indexes
- Date indexes
- Idempotency constraints

---

# 11. Configuration and Environment Variables

Review and document:

```env
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
QUEUE_CONNECTION=
CACHE_STORE=
```

Rules:

- No secrets in source control.
- No secrets in frontend bundles.
- Validate required environment variables during boot or deployment.
- Separate development, staging, and production values.

---

# 12. Deployment Requirements

Before deployment:

1. Run all migrations.
2. Run automated tests.
3. Run static analysis.
4. Run code formatting.
5. Verify queue workers.
6. Verify scheduler.
7. Verify webhook HTTPS endpoint.
8. Verify Meta callback URL.
9. Verify environment secrets.
10. Verify database backups.
11. Verify rollback migration safety.
12. Verify monitoring and alerts.
13. Verify App Review configuration.
14. Verify data deletion endpoint.
15. Verify connection health checks.

Suggested commands should match the existing project stack.

Example only:

```bash
php artisan test
php artisan migrate --force
php artisan queue:restart
php artisan config:cache
php artisan route:cache
```

Do not run destructive production commands without an explicit safe deployment process.

---

# 13. Definition of Done

The project is complete only when:

## Critical

- [ ] Invalid webhook signatures are rejected
- [ ] Webhook processing is queued
- [ ] Duplicate webhook processing is prevented
- [ ] OAuth state is random, required, expiring, and single-use
- [ ] All Meta tokens are encrypted
- [ ] Failed webhook jobs retry and dead-letter safely

## Meta Compliance

- [ ] Token expiry and revocation are handled
- [ ] Messenger response eligibility is enforced
- [ ] Delivery and read statuses are processed
- [ ] Rate limiting and backoff are implemented
- [ ] Meta-side disconnect is implemented
- [ ] Data deletion workflow is implemented
- [ ] Connection health is visible

## POS and Orders

- [ ] Stock movements reference real order IDs
- [ ] Inventory operations use transactions and locking
- [ ] Order number race condition is fixed
- [ ] Order creation no longer auto-resolves conversations
- [ ] Existing verified customer data is protected
- [ ] Refund and return flows are auditable

## Dashboard

- [ ] Revenue definitions are consistent
- [ ] Heavy widgets lazy-load
- [ ] Expensive analytics are cached
- [ ] Database compatibility is documented
- [ ] Real-time events are tenant-safe

## Quality

- [ ] Automated tests pass
- [ ] No secrets appear in logs or responses
- [ ] Documentation is complete
- [ ] Monitoring is enabled
- [ ] Deployment and rollback steps are documented

---

# 14. Required Final Output

After implementation, provide a final report in this structure:

```markdown
# Implementation Report

## Summary

Brief description of completed work.

## Files Changed

- path/to/file.php
- path/to/migration.php

## Database Migrations

- Migration name
- Schema change
- Rollback behavior

## Security Fixes

- Fix
- Evidence
- Test

## Meta Integration Fixes

- Fix
- Evidence
- Test

## POS and CRM Fixes

- Fix
- Evidence
- Test

## Dashboard Improvements

- Fix
- Evidence
- Performance result

## Tests Added

- Test file
- Scenarios covered

## Commands Run

- Command
- Result

## Remaining Risks

- Risk
- Reason
- Recommended next action

## Blocked Items

- Item
- Blocking dependency

## Production Readiness

READY / NOT READY

## Final Checklist

- [ ] Critical issues resolved
- [ ] High issues resolved
- [ ] Tests passing
- [ ] Migrations safe
- [ ] Documentation complete
```

Do not claim `READY` while any Critical or High issue remains unresolved.

---

# 15. Final Instruction

Begin by auditing the exact current code paths and mapping each finding to:

```text
Existing file
Existing method
Risk
Required change
Migration needed
Test needed
Dependencies
```

Then execute the phases in order.

Do not skip security work to add new features.

The immediate priority is:

```text
1. Reject invalid webhook signatures
2. Queue webhook processing
3. Fix OAuth state
4. Encrypt tokens
5. Add token lifecycle handling
6. Enforce Messenger eligibility
7. Fix inventory transaction integrity
```

Only after those are complete should dashboard and optional CRM enhancements be implemented.
