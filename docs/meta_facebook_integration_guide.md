# Building and Fully Integrating a Software System with Meta’s Facebook Platform

## 1. Purpose and Scope

A full Meta integration is more than adding a **“Log in with Facebook”** button.

A production system may need to:

- Authenticate business users
- Discover Facebook Pages they can manage
- Obtain Page-level permissions and access tokens
- Receive messages, comments, leads, or other events
- Reply through Messenger or manage Page content
- Store customer, conversation, order, and operational data
- Maintain permission, privacy, security, and data-deletion compliance
- Pass Meta App Review and any required business verification
- Remain compatible with Graph API version changes

For a CRM or POS system, the safest architecture is:

```text
Facebook User
    ↓
Meta OAuth Authorization
    ↓
Your Backend
    ↓
Graph API and Messenger Platform
    ↓
Page Access Tokens
    ↓
Webhooks
    ↓
Inbox, CRM, POS, Inventory, Reporting
```

The backend must control:

- Token exchange
- API communication
- Webhook processing
- Data access
- Permission checks
- Security controls

> **Warning:** Never place privileged tokens or the Meta App Secret in browser code.

---

# 2. Define the Integration Before Creating the App

## 2.1 Document the Exact Use Cases

Before requesting permissions, define every function the system will perform.

Example use cases:

1. Connect a Facebook account
2. Retrieve Pages managed by the account
3. Allow the user to select Pages
4. Receive Messenger conversations
5. Reply to customers
6. Read and reply to Page comments
7. Create customer profiles
8. Convert conversations into orders
9. Associate orders with Pages, brands, campaigns, or agents
10. Disconnect Pages and delete imported data

This matters because Meta evaluates permissions based on actual implemented functions.

Requesting permissions for future or unfinished features creates unnecessary review risk.

## 2.2 Create a Permission-to-Feature Matrix

| Application Feature                        | Likely Permission or Product       |
| ------------------------------------------ | ---------------------------------- |
| Show Pages managed by a user               | `pages_show_list`                  |
| Read Page information and engagement       | `pages_read_engagement`            |
| Read visitor posts or user-created content | `pages_read_user_content`          |
| Reply to or manage comments                | `pages_manage_engagement`          |
| Publish Page posts                         | `pages_manage_posts`               |
| Subscribe a Page to webhooks               | `pages_manage_metadata`            |
| Receive and send Messenger messages        | `pages_messaging`                  |
| Read Page lead forms                       | `leads_retrieval`                  |
| Read advertising information               | `ads_read`                         |
| Manage advertising objects                 | Relevant Marketing API permissions |
| Manage business-owned assets               | Potentially `business_management`  |

> **Caveat:** Permission names and requirements can change. Confirm each permission against the current Meta documentation before implementation.

## 2.3 Apply Least Privilege

Request only the minimum permissions necessary for the feature the user is activating.

### Bad Approach

```text
Request all Page, Ads, Business, Instagram, and Messaging permissions
during the first login.
```

### Better Approach

```text
Page Connection:
- pages_show_list
- pages_read_engagement

Messenger Activation:
- pages_messaging
- pages_manage_metadata

Comment Management:
- pages_read_user_content
- pages_manage_engagement
```

Incremental authorization improves user trust and makes App Review easier.

---

# 3. Create and Configure the Meta Application

## 3.1 Create the App

In the Meta Developer Portal:

1. Create a Meta developer account
2. Create a new application
3. Select the app type or business use case that matches the integration
4. Add the necessary products:
   - Facebook Login for Business
   - Messenger
   - Webhooks
   - Marketing API
   - Instagram Graph API
5. Record the following:
   - App ID
   - App Secret
   - App Mode
   - Assigned Business Portfolio
   - Graph API Version

> **Warning:** Do not commit the App Secret to Git, frontend configuration files, mobile apps, screenshots, or issue trackers.

## 3.2 Complete the Basic App Settings

Configure:

- Application name
- Application domain
- Contact email
- App icon
- Privacy Policy URL
- Terms of Service URL
- User data-deletion URL or callback
- Data Protection Officer information, when applicable
- Business Portfolio association
- Namespace, where required
- Allowed domains
- Valid OAuth redirect URIs

The privacy policy must clearly explain:

- What data is collected
- Why it is collected
- How it is used
- How long it is stored
- How users can request deletion

## 3.3 Development Mode Versus Live Mode

During development, access is generally limited to users assigned application roles:

- Administrator
- Developer
- Tester
- Test user

A development-mode test succeeding does not prove that public users can connect.

Production use may require:

- Live Mode
- Advanced Access
- App Review approval
- Business verification
- Data Use Checkup
- Additional compliance reviews

## 3.4 Maintain Separate Environments

Recommended setup:

```text
Development Meta App
- Localhost and developer redirect URIs
- Test Pages
- Test users
- No real customer data

Staging Meta App
- Staging domain
- Reviewer-accessible environment
- Controlled Page and business assets

Production Meta App
- Production domains only
- Approved permissions
- Restricted administrators
- Monitored credentials
```

> **Warning:** Do not mix production customer tokens with development databases.

---

# 4. Authentication and Authorization

## 4.1 Use OAuth Authorization

Use Meta’s supported Facebook Login or Facebook Login for Business flow.

Never ask users to provide their Facebook email address and password directly.

Typical server-side authorization flow:

```text
1. User clicks “Connect Facebook”
2. Application generates a random state value
3. Browser redirects to Meta authorization
4. User authenticates with Meta
5. User reviews requested permissions
6. Meta redirects to the approved callback URL
7. Backend verifies the state value
8. Backend exchanges the code for an access token
9. Backend validates the token
10. Backend retrieves authorized Pages
```

## 4.2 Protect the Authorization Request

The request should contain:

- App ID
- Exact approved redirect URI
- Requested scopes
- Random `state` value
- Correct response type
- Product-specific configuration ID, when required

The `state` value must be:

- Cryptographically random
- Bound to the initiating session
- Short-lived
- Single-use
- Validated exactly on callback

Reject callbacks with:

- Missing state
- Expired state
- Mismatched state
- Reused state

## 4.3 Lock Down Redirect URIs

### Bad

```text
https://example.com/*
https://example.com/oauth?redirect=https://attacker.example
```

### Better

```text
https://app.example.com/integrations/meta/callback
```

Do not allow a user-controlled URL to determine the final redirect destination.

## 4.4 Validate Tokens Before Use

Confirm:

- App ID matches your application
- User or business ID is expected
- Required scopes were granted
- Token is still valid
- Token is not expired
- Data-access expiration is acceptable
- User still has access to the Page
- Page tasks support the requested operation

Tokens can become invalid before their expected expiration because of:

- Password changes
- Security checks
- Removed permissions
- Business asset changes
- Page access changes
- Account restrictions
- Failed compliance requirements

## 4.5 Handle Declined Permissions

Users may:

- Decline all permissions
- Approve only some permissions
- Exclude certain Pages
- Remove access later
- Lose their Page role
- Disconnect the business integration

The application must support partial authorization.

Example status:

```text
Facebook Account: Connected
Pages Available: 3 of 7
Messenger Access: Missing for 1 Page
Reconnect Required: 2 Pages
```

Do not label the entire integration as healthy when required scopes are missing.

## 4.6 Page Access Tokens

Store for each connected Page:

```text
facebook_page_id
page_name
connection_owner_id
page_access_token_encrypted
granted_permissions
page_tasks
token_status
connected_at
last_validated_at
last_successful_sync_at
last_error_code
```

> **Warning:** Never return Page access tokens to the frontend.

## 4.7 Reauthorization and Reconnection

Provide a reconnect flow for:

- Expired tokens
- Invalid tokens
- Revoked permissions
- Removed Page access
- Changed Business Portfolio access
- Failed annual compliance checks
- App configuration changes

A reconnect process should update the existing connection instead of creating duplicate Page records.

---

# 5. API Usage

## 5.1 Use Versioned Graph API Endpoints

Use explicit API versions:

```http
GET https://graph.facebook.com/vXX.X/me
```

Do not rely on unversioned endpoints.

Maintain:

- Current production API version
- Planned upgrade version
- Upgrade deadline
- Changelog review owner
- Regression test checklist

## 5.2 Request Only Required Fields

### Bad

```http
GET /PAGE_ID
```

### Better

```http
GET /PAGE_ID?fields=id,name,picture
```

Requesting fewer fields:

- Reduces data exposure
- Reduces payload size
- Improves performance
- Simplifies retention
- Makes schema changes easier to control

## 5.3 Treat API Responses as Untrusted Input

Validate:

- Data types
- Required fields
- Object IDs
- Array lengths
- Cursor values
- URLs
- Enum values
- Timestamps
- Character encoding
- Maximum field lengths

Do not assume a field will always be returned.

## 5.4 Pagination

Many Graph API endpoints return paginated results.

Your system must:

1. Process the current page
2. Read the paging cursor
3. Continue while a next cursor exists
4. Stop safely on repeated cursors
5. Apply maximum-page protections
6. Persist checkpoints for large jobs

Never assume the first API response contains every:

- Page
- Conversation
- Message
- Comment
- Lead
- Post

## 5.5 Idempotency

Create idempotency rules for every imported object.

```text
Meta Message:
Unique by Meta message ID

Webhook Delivery:
Unique by event identifier or stable event fingerprint

Facebook Page:
Unique by tenant ID + Facebook Page ID

Customer:
Unique by tenant ID + Page ID + Page-scoped user ID

Lead:
Unique by tenant ID + Meta lead ID
```

Without idempotency, webhook retries and resynchronization can create duplicate records.

---

# 6. Rate Limits and Request Management

## 6.1 Do Not Hard-Code One Universal Limit

Meta rate limits vary by:

- Product
- Application
- User
- Page
- Business
- Endpoint
- CPU usage
- Total processing time
- Call volume

Do not assume:

```text
Every Facebook app gets exactly 200 calls per hour.
```

That is too simplistic.

## 6.2 Monitor Usage Headers

Track:

- Call count percentage
- CPU time percentage
- Total time percentage
- Throttling errors
- Product-specific usage
- Requests by endpoint
- Requests by tenant
- Requests by Page

Create alerts before usage reaches critical levels.

## 6.3 Use Exponential Backoff

Example:

```text
Attempt 1: Short delay
Attempt 2: Longer delay
Attempt 3: Longer delay with jitter
Further attempts: Capped exponential delay
```

Formula:

```text
delay = min(maxDelay, baseDelay × 2^attempt) + randomJitter
```

### Retryable Failures

- Temporary network failure
- HTTP 429
- Some HTTP 5xx responses
- Temporary Meta service errors
- Timeout before confirmed completion

### Usually Non-Retryable

- Invalid access token
- Missing permission
- Unsupported API version
- Invalid object ID
- Page access revoked
- Policy restriction
- Malformed request

## 6.4 Cache Stable Data

Cache relatively stable Page data:

- Page name
- Profile image reference
- Assigned brand
- Page configuration
- Product mapping

Do not repeatedly call Meta for data already stored and still valid.

## 6.5 Queue Noninteractive Work

Use background workers for:

- Page synchronization
- Historical conversation imports
- Lead imports
- Comment synchronization
- Insights retrieval
- Retry processing

The user-facing request should not wait for a large synchronization job.

## 6.6 Control Concurrency

Apply limits per:

- Tenant
- Page
- Token
- Endpoint
- Queue
- Application

One customer must not consume the entire API and worker capacity.

---

# 7. Webhooks and Real-Time Updates

## 7.1 Use Webhooks Instead of Aggressive Polling

General flow:

```text
Meta
  ↓
Public HTTPS Webhook Endpoint
  ↓
Verification and Signature Checks
  ↓
Fast Acknowledgment
  ↓
Queue
  ↓
Worker Processing
  ↓
Database
  ↓
WebSocket or Server-Sent Events
  ↓
User Dashboard
```

## 7.2 Webhook Verification

During configuration, Meta sends a verification request.

Your server must:

1. Read the requested mode
2. Compare the supplied verification token
3. Return the challenge only when the token matches
4. Reject invalid requests

> **Warning:** The webhook verify token is not the App Secret.

Use a separate high-entropy secret.

## 7.3 Verify Request Authenticity

For webhook deliveries:

- Read the raw request body
- Verify Meta’s signature
- Use the documented algorithm
- Use constant-time comparison
- Reject malformed signatures
- Avoid logging secrets or unnecessary personal data

Do not parse and reserialize the payload before validating the signature.

## 7.4 Acknowledge Quickly

The webhook endpoint should:

1. Authenticate the request
2. Perform lightweight validation
3. Persist or enqueue the event
4. Return success quickly

Do not perform the following inside the webhook request:

- AI processing
- Courier booking
- Large database queries
- Media downloads
- Customer merging
- Bulk Graph API requests

## 7.5 Expect Retries and Duplicates

Design for:

- Duplicate events
- Delayed events
- Out-of-order events
- Missing optional fields
- Retry bursts
- Temporary queue outages

Suggested webhook event fields:

```text
event_id_or_fingerprint
object_type
object_id
event_time
received_at
processing_status
retry_count
raw_payload_encrypted_or_redacted
last_error
```

## 7.6 Reconciliation

Webhooks should not be the only source of truth.

Run periodic reconciliation jobs to detect:

- Missed messages
- Changed Page access
- Missed lead events
- Deleted or modified objects
- Broken webhook subscriptions

Webhooks provide speed. API synchronization provides recovery.

---

# 8. Messenger-Specific Rules

## 8.1 Enforce the Messaging Window

A CRM must track the active messaging window and permitted message types.

Store:

```text
last_user_interaction_at
standard_window_expires_at
current_message_eligibility
allowed_message_type
```

Do not let agents bypass Meta’s messaging restrictions.

## 8.2 Show Clear Composer States

Inside the allowed window:

```text
Reply Allowed
Standard messaging window ends in 3h 12m
```

Outside the allowed window:

```text
Normal reply unavailable
Select an eligible approved message method
```

## 8.3 Track Message State

Track independently:

- Created
- Queued
- Submitted to Meta
- Accepted
- Delivered
- Read
- Failed
- Blocked by policy
- Retrying

Do not display **Sent** merely because the agent clicked the send button.

## 8.4 Respect User Controls

The system must:

- Stop automated messages when required
- Record opt-out signals
- Prevent repeated contact with opted-out users
- Avoid deceptive engagement
- Restrict promotional automation

> **Warning:** A broadcast feature without policy enforcement is a direct Page restriction risk.

---

# 9. Data Handling and Privacy

## 9.1 Create a Data Inventory

For every Meta-derived field, record:

- Field name
- Data source
- Business purpose
- Permission used
- Database location
- Encryption status
- Retention period
- Access roles
- Deletion method
- Subprocessor sharing

Example:

| Data                | Purpose                               | Retention                                   |
| ------------------- | ------------------------------------- | ------------------------------------------- |
| Facebook Page ID    | Maintain Page connection              | Until disconnect plus required audit period |
| Page-scoped user ID | Match Messenger conversation          | While customer relationship remains active  |
| Message content     | Customer support and order processing | Defined operational period                  |
| Page access token   | Authorized API access                 | Until invalid, disconnected, or replaced    |
| Profile name        | Display conversation identity         | While needed for service                    |

## 9.2 Data Minimization

Do not collect:

- Unused profile fields
- Full API payloads indefinitely
- Personal information unrelated to the service
- Facebook login credentials
- Data for speculative future analytics

More stored data means:

- Greater breach impact
- More legal exposure
- More deletion complexity
- More security work

## 9.3 Privacy Policy Requirements

The Privacy Policy should explain:

- Business identity
- Data collected from Meta
- Data collected directly from users
- Processing purposes
- How data is used
- Service providers and subprocessors
- Storage and retention
- Security measures
- User rights
- Correction and deletion procedures
- International transfers
- Contact details
- Policy update process

## 9.4 User Data Deletion

Provide:

- Deletion request page or callback
- Ownership verification
- Trackable request ID
- Defined deletion workflow
- Deletion from primary systems
- Cache deletion or expiry
- Backup handling
- Subprocessor deletion
- Completion status

## 9.5 Disconnection Behavior

When a user disconnects Meta:

1. Disable new API requests
2. Stop webhook subscriptions where appropriate
3. Revoke or invalidate credentials where supported
4. Mark Pages disconnected
5. Remove stored tokens
6. Apply the retention policy
7. Delete data no longer required
8. Keep only legally necessary records
9. Remove disconnected assets from active UI lists

Do not continue processing disconnected Page data.

## 9.6 Data Use Checkup

Meta may require recurring certification that the application still uses approved permissions and data correctly.

Assign:

- An internal owner
- A deadline tracker
- Calendar reminders
- Required supporting documentation

Missing compliance actions can disable API access.

## 9.7 Applicable Privacy Laws

Meta compliance does not replace legal compliance.

Depending on the business location and customer base, assess:

- Philippines Data Privacy Act
- GDPR
- UK GDPR
- CCPA/CPRA
- Consumer protection rules
- Electronic marketing rules
- Other local laws

> **Warning:** Meta approval does not automatically mean the application is legally compliant.

---

# 10. Application Security

## 10.1 Secret Management

Protect:

- Meta App Secret
- Access tokens
- Encryption keys
- Webhook verification token
- Database credentials
- Session signing keys

Store secrets in:

- Cloud secret manager
- Key management service
- Encrypted deployment secret store

Rotate secrets after suspected exposure.

## 10.2 Token Encryption

Recommended pattern:

```text
Application Database Stores Ciphertext
        ↓
Encryption Key Stored in KMS
        ↓
Only Integration Service Can Decrypt
        ↓
All Access Is Logged
```

Do not hash access tokens because the original token is required for API calls.

Use encryption.

## 10.3 Tenant Isolation

For a multi-company CRM or POS system, every record must be bound to a tenant.

Example fields:

```text
tenant_id
business_id
page_id
connection_id
```

### Bad

```sql
SELECT * FROM conversations WHERE id = ?;
```

### Better

```sql
SELECT *
FROM conversations
WHERE id = ?
AND tenant_id = ?;
```

Frontend filtering is not security.

## 10.4 Role-Based Access Control

Suggested roles:

- Owner
- Administrator
- Integration Administrator
- Manager
- Supervisor
- Agent
- Warehouse Staff
- Finance Staff
- Read-Only Auditor

Restrict:

- Page connection
- Token management
- Customer export
- Broadcast creation
- Data deletion
- User management
- Audit logs
- Financial reporting

## 10.5 Audit Logging

Log:

```text
Page Connected
Page Disconnected
Permission Changed
Token Replaced
Customer Exported
Customer Deleted
Message Sent
Broadcast Created
User Role Changed
Webhook Subscription Changed
Application Setting Modified
```

Include:

- Actor
- Tenant
- Action
- Target
- Timestamp
- Request ID
- Session or IP identifier
- Success or failure
- Safe metadata

Do not log:

- Complete access tokens
- Passwords
- Payment credentials
- Unnecessary message contents

## 10.6 Session Security

Use:

- Secure HTTP-only cookies
- SameSite controls
- CSRF protection
- Session rotation after login
- Idle timeouts for privileged users
- Multi-factor authentication
- Device and session revocation
- Strong password hashing

## 10.7 Prevent Common Web Vulnerabilities

Protect against:

- SQL injection
- Cross-site scripting
- Cross-site request forgery
- Server-side request forgery
- Insecure direct object references
- Mass assignment
- File upload attacks
- Path traversal
- Open redirects
- Broken access control
- Vulnerable dependencies

Treat customer messages and Page content as untrusted input.

---

# 11. User Interaction and UI Guidelines

## 11.1 Explain Permissions Before Redirecting

Example UI:

```text
Connect Facebook Pages

This allows the system to:
- Show Pages you manage
- Receive messages from selected Pages
- Let authorized agents reply
- Link conversations to customer orders

You can choose which Pages to activate and disconnect them later.
```

## 11.2 Page Selection Flow

After login:

1. Show eligible Pages
2. Display connection status
3. Show missing permissions
4. Let the user choose Pages
5. Explain enabled features
6. Confirm activation

Example:

```text
Page A — Ready
Page B — Missing Messenger permission
Page C — Already connected to this workspace
Page D — Connected to another workspace
```

## 11.3 Permission Status UI

Show separate states:

```text
Facebook Account: Connected
Page Access: Active
Messenger: Active
Comments: Not Enabled
Webhook: Active
Token Health: Valid
Last Sync: 2 Minutes Ago
```

A single **Connected** badge is too vague.

## 11.4 Actionable Error Messages

### Bad

```text
Facebook error.
```

### Better

```text
The Page connection no longer has Messenger permission.

Reconnect Facebook and approve Messenger access.
No messages were deleted.
```

Keep raw error codes in an expandable diagnostics panel.

## 11.5 Avoid Deceptive UI

Do not:

- Preselect optional sharing choices
- Hide disconnect controls
- Mislabel marketing consent
- Claim delivery before confirmation
- Use confusing buttons to obtain more permissions
- Automatically activate all Pages without user awareness

## 11.6 Accessibility

Use:

- Keyboard navigation
- Proper labels
- Sufficient contrast
- Visible focus states
- Screen reader status messages
- Text labels for icons
- Non-color status indicators

---

# 12. Meta App Review

## 12.1 Request Advanced Access Only When Ready

Before submission, confirm:

- The feature is fully implemented
- The reviewer can reach the feature
- Test credentials work
- A test Page exists
- Required assets are available
- The requested permission is demonstrated
- Privacy and deletion URLs work
- No internal VPN is required
- Reviewer steps are exact

## 12.2 Prepare One Demonstration Per Permission

For each permission, provide:

- Business justification
- Exact feature location
- Step-by-step instructions
- Screen recording
- Test credentials
- Expected result
- Why a lower-level permission is insufficient

Example for `pages_messaging`:

```text
1. Sign in using the reviewer account.
2. Open Settings → Integrations → Facebook.
3. Connect the provided test Page.
4. Send a Messenger message from the provided customer account.
5. Open the application Inbox.
6. Confirm the incoming message appears.
7. Reply from the application.
8. Confirm the reply appears in Messenger.
```

## 12.3 Reviewer Recording Checklist

Show:

- Application identity
- Meta login
- Permission prompt
- Page selection
- Feature using the permission
- Real resulting data or action
- Disconnect or deletion controls

Do not submit a marketing video instead of a functional walkthrough.

## 12.4 Common Rejection Causes

- Permission not demonstrated
- Reviewer cannot log in
- Feature is unfinished
- Test Page is unavailable
- Unnecessary permissions requested
- Privacy Policy does not match actual processing
- Deletion URL fails
- Screencast skips important steps
- Reviewer instructions use internal terminology
- App requires an inaccessible environment
- Actual behavior differs from submitted use case

---

# 13. Error Handling

## 13.1 Normalize Meta Errors

Suggested internal error format:

```text
provider
http_status
provider_error_code
provider_error_subcode
error_type
user_message
internal_message
retryable
requires_reauth
requires_admin
request_id
occurred_at
```

Do not spread raw Graph API error handling across unrelated controllers.

## 13.2 Error Categories

### Authentication Errors

Examples:

- Invalid token
- Expired token
- Token issued to another app
- Missing scope

Action:

```text
Disable affected operations
Mark connection degraded
Prompt an authorized administrator to reconnect
```

### Authorization Errors

Examples:

- User no longer manages the Page
- Page task is insufficient
- Business asset removed
- Permission revoked

Action:

```text
Recheck Page permissions
Disable only the affected Page or feature
Preserve unrelated connections
```

### Rate Limiting

Action:

```text
Pause queue
Apply exponential backoff
Reduce concurrency
Monitor usage
Alert on sustained throttling
```

### Validation Errors

Examples:

- Invalid field
- Unsupported parameter
- Invalid object ID

Action:

```text
Do not retry unchanged request
Log sanitized request details
Correct code or input
```

### Temporary Platform Failure

Action:

```text
Retry with bounded backoff
Move to dead-letter queue after maximum attempts
Show delayed status instead of false failure
```

### Policy Restriction

Action:

```text
Stop the prohibited action
Do not attempt bypasses
Show an administrator-facing explanation
Use the proper Meta support or review process
```

## 13.3 Correlation IDs

Assign one request ID across:

- User action
- API request
- Webhook event
- Queue job
- Database transaction

This allows end-to-end troubleshooting.

## 13.4 Dead-Letter Queues

Failed asynchronous jobs should move to a dead-letter queue.

Include:

- Original job
- Sanitized error
- Retry history
- Connection ID
- Page ID
- Tenant ID
- Resolution action

Do not retry permission errors forever.

---

# 14. Testing Strategy

## 14.1 Unit Tests

Test:

- OAuth state generation
- OAuth state validation
- Token encryption
- Scope comparison
- Webhook signature verification
- Rate-limit backoff
- Error classification
- Tenant authorization
- Messaging-window calculation
- Idempotency keys

## 14.2 Integration Tests

Test:

- Login success
- Login cancellation
- Partial permission approval
- Page discovery
- Page selection
- Token validation
- Webhook verification
- Incoming message
- Duplicate webhook
- Out-of-order webhook
- Reply success
- Permission revocation
- Page disconnection

## 14.3 Security Tests

Test:

- Cross-tenant record access
- Forged webhook signatures
- OAuth state mismatch
- Open redirects
- Token exposure in logs
- Unauthorized export
- Role escalation
- Malicious HTML in messages
- Replay attempts

## 14.4 Failure Drills

Simulate:

- Meta outage
- Database outage
- Queue outage
- Expired tokens
- Rate limiting
- Webhook retry storm
- Secret rotation
- API version retirement
- Accidental permission revocation

A system is not production-ready just because the happy path works.

---

# 15. Monitoring and Operations

## 15.1 Operational Metrics

Track:

```text
API Requests by Endpoint
API Success Rate
API Latency
Rate-Limit Percentage
Authentication Failures
Invalid Token Count
Webhook Delivery Volume
Webhook Processing Delay
Duplicate Webhook Count
Queue Depth
Dead-Letter Jobs
Message Send Failure Rate
Page Connection Health
Last Successful Page Sync
```

## 15.2 Business-Facing Health States

Recommended states:

```text
Healthy
Degraded
Reconnection Required
Permission Missing
Webhook Inactive
Rate Limited
Restricted
Disconnected
```

## 15.3 Alerts

Alert on:

- Rapid increase in invalid tokens
- Webhook endpoint failures
- Queue backlog
- Sustained rate limiting
- High message-send failure rate
- API version retirement deadline
- Overdue privacy requests
- Data Use Checkup deadline
- App Review action required
- Business verification action required

---

# 16. Recommended Implementation Sequence

## Phase 1: Foundation

1. Create the Meta app
2. Configure domains, privacy policy, deletion flow, and redirect URIs
3. Implement application authentication
4. Implement OAuth state handling
5. Exchange and encrypt tokens
6. Retrieve eligible Pages
7. Build Page selection UI
8. Save Page connections
9. Build connection health checks

## Phase 2: Webhooks and Inbox

1. Create a public HTTPS webhook endpoint
2. Implement verification
3. Implement signature validation
4. Add a queue
5. Add idempotency
6. Subscribe selected Pages
7. Store incoming messages
8. Build a unified inbox
9. Add manual replies
10. Track message status
11. Enforce messaging rules

## Phase 3: CRM and POS

1. Create Page-scoped customer identities
2. Add verified-phone-based customer merging
3. Build the order form beside the conversation
4. Map Pages to brands, products, warehouses, and agents
5. Add order, payment, fulfillment, and shipping statuses
6. Add inventory reservation
7. Add audit logs
8. Add reporting

## Phase 4: Review and Production

1. Request only required Advanced Access
2. Complete business verification where needed
3. Prepare reviewer credentials and assets
4. Record permission-specific demonstrations
5. Submit App Review
6. Resolve review findings
7. Enable production monitoring
8. Establish API version ownership
9. Schedule recurring compliance work

---

# 17. Production Readiness Checklist

## Meta Configuration

- [ ] Correct app type and products selected
- [ ] Production domain configured
- [ ] Exact HTTPS redirect URI configured
- [ ] Privacy Policy published
- [ ] Terms of Service published
- [ ] Data deletion process working
- [ ] Business verification completed where required
- [ ] Required permissions approved
- [ ] Production Graph API version pinned

## Authentication

- [ ] OAuth state validated
- [ ] Tokens validated after exchange
- [ ] Tokens encrypted at rest
- [ ] App Secret stored outside source code
- [ ] Partial permissions supported
- [ ] Reconnection flow implemented
- [ ] Disconnect flow implemented

## API

- [ ] Explicit fields requested
- [ ] Pagination handled
- [ ] Rate-limit usage monitored
- [ ] Backoff and jitter implemented
- [ ] Retryable errors classified
- [ ] API calls logged safely
- [ ] API version upgrade tests available

## Webhooks

- [ ] Verification token protected
- [ ] Signatures verified using raw body
- [ ] Fast acknowledgment implemented
- [ ] Events queued
- [ ] Duplicate events handled
- [ ] Out-of-order events tolerated
- [ ] Reconciliation job implemented

## Privacy and Security

- [ ] Data inventory completed
- [ ] Retention schedule documented
- [ ] Deletion requests tracked
- [ ] Tenant isolation tested
- [ ] Role-based access control implemented
- [ ] Audit logs enabled
- [ ] Secret rotation documented
- [ ] Backups protected
- [ ] Data export restricted
- [ ] Incident response process documented

## Messenger

- [ ] Messaging rules enforced
- [ ] Message states tracked accurately
- [ ] Policy failures shown clearly
- [ ] Opt-outs respected
- [ ] Bulk messaging restricted
- [ ] Agents cannot bypass messaging rules

---

# 18. Major Warnings

1. **Never collect Facebook passwords.** Use Meta OAuth.

2. **Never expose access tokens in frontend code.** A Page token can allow high-impact actions.

3. **Do not request every permission at once.** Excessive permissions increase review and security risk.

4. **Do not assume development approval means public access.** App role users and public users are different.

5. **Do not assume webhook delivery is exactly once.** Build idempotent processing.

6. **Do not retry authorization or policy errors endlessly.** These require user or administrator action.

7. **Do not create unrestricted Messenger broadcasting.** Enforce Meta’s messaging rules.

8. **Do not store Meta data indefinitely without a purpose.** Apply retention and deletion rules.

9. **Do not use Page names as stable identifiers.** Use Meta object IDs.

10. **Do not treat Page-scoped user IDs as globally identical.** Customer identity merging requires a separate verified identifier.

11. **Do not ignore recurring Meta compliance requirements.** Missing compliance work can disable API access.

12. **Do not build against an API version without an upgrade plan.** Meta changes versions, fields, and permissions regularly.

---

# 19. Conclusion

A compliant Meta integration requires four systems working together:

```text
Authorization
+ API and Webhook Reliability
+ Privacy and Security Controls
+ Meta Policy Governance
```

The cleanest first production milestone is:

```text
Connect Facebook
→ Retrieve Eligible Pages
→ Let the User Select Pages
→ Subscribe Approved Pages to Webhooks
→ Receive Messenger Messages
→ Allow Compliant Manual Replies
→ Create Customer and Order Records
```

Do not start with:

- Full automation
- Bulk messaging
- Ads management
- Instagram
- AI features
- Courier integration
- Full POS functionality

Build these foundations first:

1. Authorization
2. Token security
3. Webhook reliability
4. Messaging compliance
5. Tenant isolation
6. Audit logging
7. Data deletion

Every later feature depends on these foundations.
