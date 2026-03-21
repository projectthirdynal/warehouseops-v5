<div align="center">

# 📦 WarehouseOps v5

**Enterprise Warehouse Operations & Logistics Management System**

[![PHP](https://img.shields.io/badge/PHP-8.2+-777BB4?style=flat-square&logo=php&logoColor=white)](https://php.net)
[![Laravel](https://img.shields.io/badge/Laravel-11.x-FF2D20?style=flat-square&logo=laravel&logoColor=white)](https://laravel.com)
[![React](https://img.shields.io/badge/React-18-61DAFB?style=flat-square&logo=react&logoColor=black)](https://react.dev)
[![TypeScript](https://img.shields.io/badge/TypeScript-5.x-3178C6?style=flat-square&logo=typescript&logoColor=white)](https://typescriptlang.org)
[![PostgreSQL](https://img.shields.io/badge/PostgreSQL-16-336791?style=flat-square&logo=postgresql&logoColor=white)](https://postgresql.org)
[![Redis](https://img.shields.io/badge/Redis-7-DC382D?style=flat-square&logo=redis&logoColor=white)](https://redis.io)
[![License](https://img.shields.io/badge/License-Proprietary-red?style=flat-square)](#)

A full-stack web application for managing warehouse operations — waybill tracking, lead lifecycle management, courier imports, agent performance monitoring, and QC workflows — all in a single, cohesive platform.

</div>

---

## ✨ Features

### 🚚 Waybill & Courier Management
- **Bulk Excel import** for J&T Express and Flash courier manifest files
- Automatic waybill deduplication — new rows update existing records
- Downloadable import templates with pre-styled headers
- Full waybill lifecycle tracking: `PENDING → DISPATCHED → IN_TRANSIT → OUT_FOR_DELIVERY → DELIVERED / RETURNED`
- Retry failed imports without re-uploading the original file

### 📊 Operations Dashboard
- Live KPIs — total waybills, in-transit, delivered today, returns today
- Lead performance metrics — new leads, sales today, conversion rate
- Real-time activity feed (deliveries, lead assignments, QC approvals)
- Agents-online indicator based on last login timestamp

### 👥 Lead & CRM
- Lead ingestion, assignment to agents, and full status lifecycle
- QC approval workflow — QA_PENDING → QA_APPROVED / QA_REJECTED
- Recycling pool for unqualified or expired leads
- Customer linkage per lead

### 🤝 Agent Governance & Monitoring
- Agent profile management with role-based access
- Monitoring dashboard — agent activity, leads worked, and performance
- Governance panel for admin supervision

### 🔐 Authentication & RBAC
- Session-based authentication with Laravel Sanctum
- Role-based middleware powered by **Spatie Laravel Permission**
- Supports role segregation: `admin`, `supervisor`, `agent`, etc.

### ⚙️ Infrastructure
- **Inertia.js** bridge for a seamless Laravel + React SPA experience
- **Laravel Horizon** for Redis queue monitoring
- **Ziggy** for type-safe route generation in TypeScript
- **Telegram notifications** for operational alerts
- Docker-ready with Nginx, Redis, and Mailpit (dev mail catcher)

---

## 🏗️ Tech Stack

| Layer | Technology |
|---|---|
| **Backend** | Laravel 11, PHP 8.2+ |
| **Frontend** | React 18, TypeScript 5, Inertia.js |
| **Styling** | Tailwind CSS 3, Radix UI, shadcn/ui |
| **Database** | PostgreSQL 16 |
| **Cache / Queue / Session** | Redis 7 (via Predis) |
| **Queue Monitor** | Laravel Horizon |
| **Excel Import** | Maatwebsite/Laravel-Excel (PhpSpreadsheet) |
| **RBAC** | Spatie Laravel Permission |
| **Build Tool** | Vite 5 |
| **Testing** | Pest PHP 2, PHPUnit 10 |
| **Static Analysis** | Larastan (PHPStan) |
| **Code Style** | Laravel Pint, ESLint, Prettier |

---

## 📁 Project Structure

```
warehouseops-v5/
├── app/
│   ├── Domain/              # Domain-layer logic (Lead, Waybill)
│   ├── Http/
│   │   ├── Controllers/     # Thin HTTP controllers
│   │   └── Middleware/
│   ├── Imports/             # Maatwebsite Excel importers (JntWaybillImport)
│   ├── Models/              # Eloquent models
│   └── Providers/
├── database/
│   ├── migrations/          # Schema migrations
│   ├── seeders/
│   └── factories/
├── resources/
│   ├── js/
│   │   ├── components/      # Reusable React/shadcn UI components
│   │   ├── layouts/         # App shell layouts
│   │   ├── pages/           # Inertia page components
│   │   └── types/           # TypeScript type definitions
│   └── views/               # Blade root template
├── routes/
│   ├── web.php              # All Inertia-powered routes
│   └── api.php
├── docker/                  # Nginx config, PHP config, Dockerfiles
├── docker-compose.yml       # Development stack
└── docker-compose.prod.yml  # Production stack
```

---

## 🗺️ Application Routes

| Route | Description |
|---|---|
| `/` | Operations dashboard with live KPIs |
| `/scanner` | Barcode scanner interface |
| `/waybills` | Waybill list and search |
| `/waybills/import` | Bulk Excel import (J&T, Flash) |
| `/leads` | Lead list with CRM features |
| `/qc` | QC queue — QA_PENDING leads |
| `/recycling/pool` | Recycled / disqualified leads |
| `/monitoring/dashboard` | Agent performance monitoring |
| `/agents/governance` | Agent management & governance |
| `/tickets` | Support ticket management |
| `/settings` | System settings |

---

## 🚀 Getting Started

### Prerequisites

- PHP 8.2+
- Composer 2.x
- Node.js 20+ & npm
- PostgreSQL 16
- Redis 7

### Local Setup (Without Docker)

```bash
# 1. Clone the repository
git clone <repo-url>
cd warehouseops-v5

# 2. Install PHP dependencies
composer install

# 3. Install JS dependencies
npm install

# 4. Configure environment
cp .env.example .env
php artisan key:generate

# 5. Edit .env with your DB credentials, then run migrations
php artisan migrate

# 6. Build frontend assets
npm run build

# 7. Start the local development server
php artisan serve

# 8. (Optional) Run the queue worker
php artisan queue:work
```

> Visit `http://localhost:8000` to access the application.

---

### Local Setup (Docker)

The project ships with a ready-to-use `docker-compose.yml` that includes **Nginx**, **Redis**, and **Mailpit**.

> **Note:** PostgreSQL is expected to run on the host machine. The app container connects to it via `host.docker.internal`.

```bash
# 1. Copy and configure environment
cp .env.example .env
# Edit .env — set DB_HOST=host.docker.internal and your credentials

# 2. Build and start containers
docker compose up -d --build

# 3. Run migrations inside the container
docker exec -it warehouseops-app php artisan migrate

# 4. Build frontend assets (run on host, not in container)
npm install && npm run build
```

| Service | URL |
|---|---|
| Application | http://localhost:8088 |
| Mailpit (UI) | http://localhost:8025 |
| Redis | localhost:6380 |

---

## ⚙️ Environment Variables

Key variables in `.env` (see `.env.example` for full list):

| Variable | Description |
|---|---|
| `DB_CONNECTION` | `pgsql` (PostgreSQL) |
| `QUEUE_CONNECTION` | `redis` |
| `CACHE_STORE` | `redis` |
| `SESSION_DRIVER` | `redis` |
| `TELEGRAM_BOT_TOKEN` | Bot token for operational alerts |
| `TELEGRAM_CHAT_ID` | Target chat/channel ID |
| `JNT_API_KEY` | J&T Express API key |
| `JNT_API_SECRET` | J&T Express API secret |
| `JNT_WEBHOOK_SECRET` | J&T webhook validation secret |

---

## 📥 Waybill Import

The system supports bulk waybill import from courier-exported Excel files.

1. Navigate to **Waybills → Import**
2. Download the template for your courier (J&T or Flash)
3. Fill in your waybill data matching the template columns
4. Upload the `.xlsx` or `.csv` file and select the courier
5. Review the import results — success count, updated count, and row-level errors
6. Failed imports can be retried without re-uploading

**Supported Couriers:**
- ✅ **J&T Express** — fully implemented
- 🔜 **Flash** — in development

---

## 🧪 Testing & Code Quality

```bash
# Run tests
php artisan test
# or
composer test

# Static analysis
composer analyse

# Code formatting
composer format          # PHP (Laravel Pint)
npm run format           # TypeScript/TSX (Prettier)
npm run lint             # ESLint
```

---

## 🐳 Production Deployment

A separate `docker-compose.prod.yml` is available for production use:

```bash
docker compose -f docker-compose.prod.yml up -d --build
```

Use `deploy.sh` for scripted deployment:

```bash
chmod +x deploy.sh
./deploy.sh
```

---

## 📋 Data Models

| Model | Key Fields |
|---|---|
| `Waybill` | `waybill_number`, `status`, `courier_provider`, `cod_amount`, `lead_id`, `upload_id` |
| `Lead` | `status`, `sales_status`, `assigned_to`, `customer_id` |
| `LeadCycle` | Tracks each cycle/attempt on a lead |
| `Upload` | `type`, `status`, `total_rows`, `success_rows`, `error_rows`, `errors` |
| `User` | `role`, `is_active`, `last_login_at` |
| `AgentProfile` | Agent-specific profile and metadata |
| `Customer` | Customer contact details |
| `WaybillTrackingHistory` | Append-only tracking event log per waybill |

### Waybill Statuses

```
PENDING → DISPATCHED → IN_TRANSIT → OUT_FOR_DELIVERY → DELIVERED
                                                      ↘ RETURNED
                                   AT_WAREHOUSE
                                   PICKED_UP
```

---

## 📬 Notifications

Operational alerts are dispatched via **Telegram**. Configure `TELEGRAM_BOT_TOKEN` and `TELEGRAM_CHAT_ID` in `.env` to enable them.

---

## 🤝 Contributing

This is a proprietary internal system. Contact the repository owner for access and contribution guidelines.

---

## 📄 License

Proprietary — All rights reserved. © Thirdynal / WarehouseOps.
