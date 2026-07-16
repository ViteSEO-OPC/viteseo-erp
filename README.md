# Vite SEO Systems

Unified internal platform for Vite SEO Digital Advertising OPC — **three systems behind ONE login**:

| Module | Product | URL | Backend | Frontend |
|---|---|---|---|---|
| HRIS | **TeamOra** | `/teamora` | `backend/app/Modules/Hr` | `frontend/src/modules/hr` + `src/app/teamora` |
| CRM | **Zerem** | `/zerem` | `backend/app/Modules/Crm` | `frontend/src/modules/crm` + `src/app/zerem` |
| PMS | **Sprintly** | `/sprintly` | `backend/app/Modules/Projects` | `frontend/src/modules/projects` + `src/app/sprintly` |

After login you land on the **launcher** (`/`) — cards for the systems your role can access.

**Stack:** Laravel 13 API (PHP 8.4) + Next.js 16 (React 19) frontend + MySQL/MariaDB · Sanctum token auth · spatie per-system roles · one shared `users` table.

---

## Requirements
- **PHP 8.4** + Composer
- **Node.js 20+** + npm
- **MySQL 8.4** or **MariaDB**

## Quick start (run it locally — same app as the live site)

```bash
git clone https://github.com/Juliusnowel/vitaeseo-platform.git
cd vitaeseo-platform

# ── Backend (terminal 1) ──
cd backend
composer install
cp .env.example .env
php artisan key:generate
#  → edit .env: set DB_CONNECTION=mysql + DB_DATABASE / DB_USERNAME / DB_PASSWORD
#    (create the database first, e.g.  CREATE DATABASE viteseo_platform;)
php artisan migrate --seed        # creates tables + roles + demo data
php artisan serve                 # http://localhost:8000

# ── Frontend (terminal 2) ──
cd ../frontend
npm install
echo "NEXT_PUBLIC_API_BASE_URL=http://localhost:8000/api/v1" > .env.local
npm run dev                       # http://localhost:3000
```

Open **http://localhost:3000** → you'll see the full platform (login → launcher → the modules), exactly like `system.viteseo.ph`, with local seeded data.

> **Demo logins:** the seeders create test accounts (default password `password`). Check `backend/database/seeders/` + each module's seeders for the emails.
> **Always use `php artisan` on PHP 8.4.** If `php -v` shows an older version, use `php8.4 artisan ...`.

**Optional services** (only for specific features):
- Queue worker (CRM mail / jobs): `php artisan queue:work`
- Realtime (Sprintly live tasks): needs a Reverb server — off by default (`BROADCAST_CONNECTION=log`); the app runs fine without it.

---

## Docs
- **[docs/CONTRIBUTING.md](docs/CONTRIBUTING.md)** — how to work in this monorepo (module ownership, bringing your old work in, branch → PR → review). **Read this first.**
- **[docs/api/](docs/api/)** — API endpoint reference per module (auth, HRIS, CRM, PMS).

## The golden rules (short version)
- **Laravel is the ONLY backend.** No business logic/DB/auth in Next.js.
- **Prefix your tables** — `hr_*` / `crm_*` / `pm_*`. Shared tables (`users`, `roles`, `permissions`) have **no** prefix.
- **Namespace permissions** — `hr.*` / `crm.*` / `projects.*`.
- **One shared login** (Sanctum) + one `users` table — never build your own auth.
- **Work only in your module.** Touching shared `Core` / `User` / the seeder needs CTO approval.
