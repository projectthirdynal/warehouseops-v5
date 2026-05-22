# Shop Customer Profile Sync Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Sync Facebook customer name, detected phone number, and provided address into auto-created Shop POS/CRM orders.

**Architecture:** Keep syncing in `MetaConversationIngestor`, because that is the boundary where Facebook webhooks become conversations, customers, and orders. Add a small Facebook profile lookup method to `FacebookConnectorService` and make the ingestor gracefully fall back when Meta API access is unavailable.

**Tech Stack:** Laravel, Eloquent, Laravel HTTP client, PHPUnit, Meta Graph API.

---

### Task 1: Add Failing Feature Test

**Files:**
- Create: `tests/Feature/Shop/MetaConversationIngestorTest.php`

- [ ] **Step 1: Write the failing test**

Create a feature test that fakes Meta Graph profile lookup, ingests a Messenger text containing phone and address, and asserts the order/customer/identity names are synced.

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Feature/Shop/MetaConversationIngestorTest.php`
Expected before implementation: FAIL because no profile lookup is called and order receiver name remains `Facebook Customer`.

### Task 2: Add Facebook Profile Lookup

**Files:**
- Modify: `app/Domain/Shop/Services/FacebookConnectorService.php`

- [ ] **Step 1: Add `fetchMessengerProfile(FacebookPage $page, string $psid): ?array`**

Use the Page access token to GET `/{psid}?fields=first_name,last_name,name,profile_pic`.
Return `null` when the page token is missing or the request fails.

- [ ] **Step 2: Run syntax check**

Run: `php -l app/Domain/Shop/Services/FacebookConnectorService.php`
Expected: no syntax errors.

### Task 3: Wire Profile Into Order Sync

**Files:**
- Modify: `app/Domain/Shop/Services/MetaConversationIngestor.php`
- Modify: `app/Domain/Shop/Services/CustomerIdentityService.php`

- [ ] **Step 1: Inject `FacebookConnectorService` into `MetaConversationIngestor`**

Fetch profile before upserting identity.

- [ ] **Step 2: Persist profile name**

Save display name and profile picture on `CustomerIdentity`, save Facebook name on `Customer`, and use that as the order receiver name when the customer has no stronger manual name.

- [ ] **Step 3: Preserve existing order safety**

If an active auto-created order still has `Facebook Customer`, update it with the newly fetched Facebook name.

- [ ] **Step 4: Run feature test**

Run: `php artisan test tests/Feature/Shop/MetaConversationIngestorTest.php`
Expected: PASS.

### Task 4: Deploy And Smoke Test

**Files:**
- Deploy modified PHP files to `/opt/warehouseops`.

- [ ] **Step 1: Copy files to production**

Use `scp` for modified PHP files.

- [ ] **Step 2: Lint inside container**

Run: `docker exec warehouseops-app php -l ...`
Expected: no syntax errors.

- [ ] **Step 3: Restart app container**

Run: `cd /opt/warehouseops && docker compose restart app`
Expected: container restarts.

- [ ] **Step 4: Run production smoke test**

Simulate webhook ingestion in a rolled-back transaction and assert `receiver_name`, `receiver_phone`, and `receiver_address`.

- [ ] **Step 5: Check app/nginx logs**

Run: `docker compose logs --tail=120 app nginx | grep -iE "error|exception|fatal|parse" || true`
Expected: no new runtime errors.
