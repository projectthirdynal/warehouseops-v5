# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Build & Development Commands

```bash
# Frontend
npm run dev          # Vite dev server (HMR)
npm run build        # TypeScript compile + Vite production build
npm run lint         # ESLint (strict, zero warnings allowed)
npm run format       # Prettier (resources/js/**/*.{ts,tsx})

# Backend
php artisan serve    # Local dev server
php artisan migrate  # Run database migrations
php artisan queue:work  # Process queued jobs (or use Horizon)

# Testing
composer test        # Run Pest test suite (php artisan test)

# Static Analysis & Formatting
composer analyse     # Larastan (PHPStan for Laravel)
composer format      # Laravel Pint code formatter
```

## Architecture

**Stack**: Laravel 11 + Inertia.js + React 18 + TypeScript + Tailwind CSS + PostgreSQL + Redis

### Domain-Driven Design

Business logic lives in `app/Domain/` with six bounded contexts:

| Domain | Purpose |
|--------|---------|
| `Lead` | Lead lifecycle: pool status, cycles, distribution, recycling, outcomes |
| `Waybill` | Courier shipment tracking, status transitions, batch imports |
| `Agent` | Agent profiles, product skills, performance metrics |
| `Customer` | Customer records (deduplicated by phone), risk scoring, blacklisting |
| `Courier` | J&T Express / Flash courier integrations |
| `Notification` | Telegram operational alerts |

Each domain may contain: `Models/`, `Enums/`, `Actions/`, `Repositories/`.

### Key Enums

- **`PoolStatus`**: AVAILABLE, ASSIGNED, COOLDOWN, EXHAUSTED — controls lead pool lifecycle
- **`LeadOutcome`**: NO_ANSWER, CALLBACK, INTERESTED, ORDERED, NOT_INTERESTED, WRONG_NUMBER
- **`LeadStatus`**: NEW, CALLING, NO_ANSWER, REJECT, CALLBACK, SALE, REORDER, DELIVERED, RETURNED, CANCELLED, ARCHIVED
- **`SalesStatus`**: NEW → CONTACTED → AGENT_CONFIRMED → QA_PENDING → QA_APPROVED → OPS_APPROVED → WAYBILL_CREATED

### Service Layer (`app/Services/`)

Core business logic — controllers are thin and delegate here:

- **LeadDistributionService** — distributes leads to agents (supervisor batch + agent self-pull via `distributeCustom()`)
- **LeadRecyclingService** — processes outcomes, applies `RecyclingRule` configs (cooldown/exhaust/recycle)
- **LeadPoolService** — pool statistics and availability queries
- **LeadImportService** — bulk CSV lead import with customer deduplication and blacklist checking
- **FraudDetectionService** — pattern detection for suspicious agent behavior
- **LeadAuditService** — append-only audit log for all lead state changes
- **CallTrackingService** — SIP call initiation and attempt tracking
- **SmsService / SmsSequenceService** — SMS campaigns and automated multi-step sequences

### Queue Jobs (`app/Jobs/`)

All implement `ShouldQueue` on Redis:

- **GenerateLeadsFromUpload** — creates leads from delivered waybills after bulk import (critical: `Waybill::upsert()` bypasses Eloquent observers)
- **CreateLeadFromWaybill** — single waybill-to-lead conversion
- **ProcessCooldownLeads** — expires cooldown timers, returns leads to pool (runs every 15 min)
- **DetectFraudPatterns** — async fraud analysis (runs every 30 min)
- **ProcessSequenceStep** — sends next SMS in an automated sequence

### Observer Caveat

`WaybillObserver::updated()` fires on individual Eloquent saves but **not** on `Waybill::upsert()` (bulk imports via `JntWaybillFastImport`). The `GenerateLeadsFromUpload` job compensates for this.

### Dual Model Pattern

Some domains have two model files:
- `App\Domain\Lead\Models\Lead` — full domain model with scopes (e.g., `available()`), relationships, business methods
- `App\Models\Lead` — simpler Eloquent model for basic queries

The domain model is the primary one used in controllers and services.

## Frontend

### UI Framework

shadcn/ui components (Radix UI primitives) in `resources/js/components/ui/`. Icons from `lucide-react`. Charts from `recharts`. Forms use React Hook Form + Zod validation.

### TypeScript Path Aliases

`@/` maps to `resources/js/` — use `@/components/`, `@/pages/`, `@/layouts/`, `@/hooks/`, `@/lib/`, `@/types/`.

### Layouts

- **`AppLayout.tsx`** — admin/supervisor layout with full navigation sidebar. Filters nav items by role.
- **`AgentLayout.tsx`** — dedicated agent portal layout. Only shows My Leads, Tickets, Settings.

### Route Structure

Routes split by role in `routes/web.php`:
- `auth` middleware only — agent self-service portal (`/agent/*`, `/api/agent/*`)
- `auth` + `role:supervisor,admin,superadmin` — all admin routes (`/`, `/waybills/*`, `/leads/*`, etc.)

Agent role values: `superadmin | admin | teamleader | agent | checker | encoder` (see `User.role` in `types/index.ts`).

### Key Frontend Types

Defined in `resources/js/types/index.ts` and `types/lead-pool.ts`. The `AgentLead` interface is the main type for the agent portal; `Lead` and `Waybill` are the admin-facing types.

## Deployment

**Production**: Docker containers (app, nginx, redis) at `/opt/warehouseops/`.

**CI/CD**: GitHub Actions self-hosted runner deploys on push to `main` — `npm ci && npm run build → rsync → composer install --no-dev → migrate → cache rebuild → horizon:terminate`.

**Manual deploy** (development server at 192.168.0.14):
```bash
npm run build
rsync -az --checksum public/build/ it-admin@192.168.0.14:/home/it-admin/actions-runner/_work/warehouseops-v5/warehouseops-v5/public/build/
rsync -az --checksum resources/js/ it-admin@192.168.0.14:...resources/js/
rsync -az --checksum app/ it-admin@192.168.0.14:...app/
rsync -az --checksum routes/ it-admin@192.168.0.14:...routes/
```

## Testing

Pest PHP with Laravel plugin. Tests in `tests/Unit/` and `tests/Feature/`. Uses SQLite in-memory for test database (`phpunit.xml`).

Run a single test:
```bash
php artisan test --filter=TestClassName
# or
./vendor/bin/pest tests/Feature/SomeTest.php
```
