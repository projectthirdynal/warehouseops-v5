<div align="center">

# TECS WarehouseOps v5

**Thirdynal E-Commerce & Courier System**

[![PHP](https://img.shields.io/badge/PHP-8.2+-777BB4?style=flat-square&logo=php&logoColor=white)](https://php.net)
[![Laravel](https://img.shields.io/badge/Laravel-11.x-FF2D20?style=flat-square&logo=laravel&logoColor=white)](https://laravel.com)
[![React](https://img.shields.io/badge/React-18-61DAFB?style=flat-square&logo=react&logoColor=black)](https://react.dev)
[![TypeScript](https://img.shields.io/badge/TypeScript-5.x-3178C6?style=flat-square&logo=typescript&logoColor=white)](https://typescriptlang.org)
[![PostgreSQL](https://img.shields.io/badge/PostgreSQL-16-336791?style=flat-square&logo=postgresql&logoColor=white)](https://postgresql.org)
[![Redis](https://img.shields.io/badge/Redis-7-DC382D?style=flat-square&logo=redis&logoColor=white)](https://redis.io)
[![Electron](https://img.shields.io/badge/Desktop-Electron_29-47848F?style=flat-square&logo=electron&logoColor=white)](https://electronjs.org)
[![License](https://img.shields.io/badge/License-Proprietary-red?style=flat-square)](#)

End-to-end warehouse operations platform — multi-courier parcel management, lead generation & distribution, agent call center, SMS marketing, and fulfillment automation.

</div>

---

## Features

### Waybill & Courier Management
- **Multi-courier API integration** — Flash Express and J&T Express (order creation, tracking, webhooks)
- Bulk Excel import with streaming reader (handles 100k+ rows)
- Real-time status sync via courier webhooks + scheduled polling (every 15 min)
- Full lifecycle tracking: `PENDING > DISPATCHED > IN_TRANSIT > OUT_FOR_DELIVERY > DELIVERED / RETURNED`
- Waybill tracking history with raw courier data logging
- Barcode scanner for batch dispatch operations

### Lead Pool & Distribution
- Automatic lead generation from delivered waybills
- Customer deduplication by phone number with risk scoring (LOW/MEDIUM/HIGH/BLACKLISTED)
- Lead pool lifecycle: `AVAILABLE > ASSIGNED > COOLDOWN > EXHAUSTED`
- Supervisor bulk distribution UI
- CSV lead import with phone normalization and blacklist checking
- Product-skill-based lead matching (agents pull leads matching their specialization)

### Agent Self-Service Portal
- Dedicated agent layout — agents only see their own portal sections
- Self-pull leads with product-skill filtering + fallback to general pool
- Call initiation (SIP) and attempt tracking
- Outcome recording: NO_ANSWER, CALLBACK, INTERESTED, ORDERED, NOT_INTERESTED, WRONG_NUMBER
- Callback scheduling with due-today reminders
- Customer order history lookup (scoped to agent's assigned leads only)

### Lead Recycling & Automation
- Configurable recycling rules engine — per-outcome behavior (cooldown, exhaust, recycle)
- Cooldown timers — leads auto-return to pool (`ProcessCooldownLeads` job, every 15 min)
- Fraud detection — suspicious velocity, no-call outcomes, lead hoarding (`DetectFraudPatterns` job, every 30 min)
- Append-only audit trail for all lead state transitions
- QC/QA approval workflow: QA_PENDING > QA_APPROVED / QA_REJECTED

### SMS System
- Campaign management with template variables ({name}, {waybill}, {amount}, etc.)
- Bulk send with rate-limited batching via SkySMS API
- Automated multi-step sequences triggered by waybill status events
- Template library — create, reuse, delete
- Full send log with delivery status tracking

### Agent Governance & Monitoring
- Agent profile management with product skills, quotas, regions
- Real-time performance monitoring dashboard
- Fraud flags with severity levels (WARNING/CRITICAL)
- Role-based access: `superadmin`, `admin`, `teamleader`, `agent`, `checker`, `encoder`

### Desktop App
- Electron 29 admin app (Windows NSIS + Linux AppImage/deb)
- Same UI stack as web (React + Tailwind + shadcn/ui)
- OTA auto-updates via GitHub Releases
- Auto-builds on `desktop-v*` tags

---

## Tech Stack

| Layer | Technology |
|---|---|
| **Backend** | Laravel 11, PHP 8.2+ |
| **Frontend** | React 18, TypeScript 5, Inertia.js |
| **Styling** | Tailwind CSS 3, Radix UI, shadcn/ui |
| **Database** | PostgreSQL 16 |
| **Cache / Queue / Session** | Redis 7 (Predis) |
| **Queue Monitor** | Laravel Horizon |
| **Excel Import** | Rap2h/Fast-Excel (streaming), Maatwebsite/Laravel-Excel |
| **RBAC** | Spatie Laravel Permission |
| **Build Tool** | Vite 5 |
| **Desktop** | Electron 29, electron-builder |
| **Testing** | Pest PHP 2, PHPUnit 10 |
| **Static Analysis** | Larastan (PHPStan) |
| **Code Style** | Laravel Pint, ESLint, Prettier |

---

## Architecture

```
app/
├── Domain/                    # Domain-Driven Design
│   ├── Agent/                 # Agent profiles, performance
│   ├── Courier/               # Flash Express + J&T Express API integration
│   │   ├── Contracts/         # CourierServiceInterface
│   │   ├── DTOs/              # CreateOrderDTO, TrackingResultDTO, WebhookPayloadDTO
│   │   ├── Events/            # TrackingStatusUpdated
│   │   ├── Http/              # WebhookController, ProviderController
│   │   ├── Jobs/              # SyncTrackingStatusJob
│   │   ├── Listeners/         # TriggerSmsOnStatusChange
│   │   ├── Models/            # CourierProvider, CourierApiLog
│   │   ├── Services/          # FlashExpressService, JntExpressService, StatusMapper
│   │   └── StatusMaps/        # Per-courier status code mappings
│   ├── Customer/              # Customer profiles, risk scoring, blacklisting
│   ├── Lead/                  # Lead lifecycle, pool, cycles, recycling
│   ├── Notification/          # Telegram operational alerts
│   └── Waybill/               # Waybill model, status enum, tracking history
├── Services/                  # Business logic (9 services)
├── Jobs/                      # Queued jobs (lead gen, cooldown, fraud, SMS, tracking sync)
├── Observers/                 # WaybillObserver
└── Imports/                   # JntWaybillFastImport, JntWaybillImport

resources/js/
├── layouts/
│   ├── AppLayout.tsx          # Admin/supervisor (role-filtered nav)
│   └── AgentLayout.tsx        # Agent-only portal
├── pages/                     # 15 page modules
│   ├── Dashboard/             # KPI dashboard
│   ├── Waybills/              # List, detail, import
│   ├── Leads/                 # Lead list, detail
│   ├── LeadPool/              # Distribution, import, agent performance
│   ├── AgentLeads/            # Agent self-service portal
│   ├── Couriers/              # Courier management + API logs
│   ├── Sms/                   # Campaigns, sequences, templates, logs
│   ├── QC/                    # QA review queue
│   ├── Recycling/             # Lead pool recovery
│   ├── Monitoring/            # Agent performance
│   ├── Agents/                # Agent governance
│   ├── Scanner/               # Barcode scanner
│   ├── Settings/              # Profile, appearance, password
│   └── Tickets/               # Support tickets
└── components/
    ├── ui/                    # shadcn/ui components
    └── leads/                 # CallButton, OutcomeModal, CustomerHistoryModal
```

---

## Routes

| Group | Middleware | Prefix | Purpose |
|---|---|---|---|
| Public | `guest` | `/login` | Authentication |
| Agent | `auth` | `/agent/*` | Agent self-service portal |
| Agent API | `auth` | `/api/agent/*` | Agent AJAX calls (leads, calls, outcomes) |
| Admin | `auth` + `role:supervisor,admin,superadmin` | `/` | All admin routes |
| Webhooks | none (signature verified) | `/api/webhooks/courier/{courier}` | Courier status push |

---

## Getting Started

### Prerequisites

- PHP 8.2+, Composer 2.x
- Node.js 20+, npm
- PostgreSQL 16
- Redis 7

### Local Setup

```bash
git clone https://github.com/projectthirdynal/warehouseops-v5.git
cd warehouseops-v5

composer install
npm install

cp .env.example .env
php artisan key:generate

# Edit .env with your DB credentials
php artisan migrate

npm run build
php artisan serve
php artisan queue:work   # in a separate terminal
```

### Docker Setup

```bash
cp .env.example .env
# Edit .env — set DB_HOST=host.docker.internal

docker compose up -d --build
docker exec -it warehouseops-app php artisan migrate
npm install && npm run build
```

| Service | URL |
|---|---|
| Application | http://localhost:8088 |
| Mailpit | http://localhost:8025 |
| Redis | localhost:6380 |

---

## Environment Variables

```env
# Database
DB_CONNECTION=pgsql

# Cache / Queue
REDIS_HOST=redis
CACHE_STORE=redis
QUEUE_CONNECTION=redis
SESSION_DRIVER=redis

# Courier: Flash Express
FLASH_API_URL=https://open-api.flashexpress.com
FLASH_MCH_ID=
FLASH_SECRET_KEY=

# Courier: J&T Express
JNT_API_URL=https://openapi.jtexpress.ph/api
JNT_API_KEY=
JNT_API_SECRET=
JNT_WEBHOOK_SECRET=

# SMS
SKYSMS_API_URL=https://skysms.skyio.site/api/v1/sms
SKYSMS_API_KEY=

# Telegram Alerts
TELEGRAM_BOT_TOKEN=
TELEGRAM_CHAT_ID=
```

---

## Development

```bash
npm run dev              # Vite dev server (HMR)
npm run build            # Production build
npm run lint             # ESLint
npm run format           # Prettier

php artisan test         # Run Pest tests
composer analyse         # Larastan static analysis
composer format          # Laravel Pint
```

---

## Deployment

**CI/CD:** GitHub Actions self-hosted runner deploys on push to `main`.

Pipeline: `npm ci` > `npm run build` > rsync to `/opt/warehouseops/` > `composer install --no-dev` > `php artisan migrate --force` > cache rebuild > Horizon graceful restart > health check.

---

## License

Proprietary — All rights reserved. TECS / Thirdynal.
