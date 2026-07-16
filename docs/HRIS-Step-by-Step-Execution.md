---
type: execution-runbook
title: HRIS — Step-by-Step Execution Runbook (spoon-fed, with the "why")
tags: [hris, setup, laravel, nextjs, headless, runbook, hackathon]
owner: devops@viteseo.ph
pairs_with: HRIS-AI-Build-Guide.md
database: MySQL 8.4 LTS
status: living document
---

# HRIS — Step-by-Step Execution Runbook

> **Read `HRIS-AI-Build-Guide.md` first** — that's the *rules*. This file is the *do-this-then-that*, **with a plain-English explanation of why** at every step, written for someone who has built **traditional Laravel (via Laragon)** and **React (via Create React App)** but has **never built a headless / decoupled system** (separate backend + frontend).
>
> **Start at Part 1 (the concept).** Don't skip it — it's the thing that's confusing you, and once it clicks the rest is just typing.
> Then do the phases **in order**. Each ends with a ✅ **Checkpoint**. 🧠 **AI-help** boxes give you a paste-ready prompt when stuck.
> 🖥️ Commands assume **Ubuntu/Debian Linux** (your current machine) and a terminal in your home directory.
>
> 🗓️ **Production-quality build (~1 week, target ≥90% complete).** This aims for a **full working HRIS — correct, validated, and secure**, not a demo. Build each Phase-7 vertical slice *done right*: validation, edge-cases, non-happy-path error states, and ownership/auth checks are all in scope. Don't invent unrequested features, and keep the locked rails 100%. The setup phases below are one-time; the real work is repeating the **Phase 7** slice per feature. (Full behavior rules: see the **"Build Context — production-quality HRIS in ~1 week"** block at the top of `HRIS-AI-Build-Guide.md`.)

---

# PART 1 — Understand what we're building (the mental model)

You're confused because this is a **different shape of app** than you've built before. Let's fix that first. Nothing to type here — just read.

## 1.1 The old way you know vs the new way

**What you did before (traditional Laravel + Laragon):**
Laravel did *everything* in one app — it received the browser request, ran the logic, queried MySQL, and **rendered the HTML itself with Blade**, then sent a finished web page back. One app, one server, one thing to run. Laragon was just a convenient Windows bundle that gave you Apache/Nginx + PHP + MySQL + nice `.test` URLs so you didn't have to install those pieces yourself.

**What you did before (React via Create React App):**
A standalone UI running in the browser. `npm start` ran a dev server; you built components; but the data usually came from some separate API.

**What we're building now (headless / decoupled / "API-first"):**
We **split the one app into two separate apps** that run at the same time and talk over HTTP:

```
   ┌─────────────────────────────┐         ┌──────────────────────────────┐
   │   FRONTEND  (Next.js/React)  │  HTTP   │   BACKEND  (Laravel API)     │
   │   "the dining room"          │ ──────► │   "the kitchen"              │
   │   ALL the UI. Runs in the    │  JSON   │   NO HTML. Just logic + DB.  │
   │   browser. Shows pages,      │ ◄────── │   Returns raw JSON data.     │
   │   buttons, forms.            │         │   Talks to MySQL.            │
   │   Port :3000                 │         │   Port :8000                 │
   └─────────────────────────────┘         └──────────────┬───────────────┘
                                                           │
                                                    ┌──────┴──────┐
                                                    │   MySQL DB  │
                                                    └─────────────┘
```

- **Laravel becomes "headless":** it no longer produces web pages (no Blade). It's now a pure **API** — it takes a request, runs the logic, hits MySQL, and answers with **JSON data only** (e.g. `{ "data": [ { "id": 1, "name": "HR" } ] }`). Think "the kitchen": it cooks, it doesn't serve the table.
- **Next.js is the entire UI ("the dining room"):** every page, button, and form lives here. When it needs data, it **asks the Laravel API** over HTTP using **Axios**, receives JSON, and renders it into React components.

## 1.2 Why split them? (this is the whole point of the project)

1. **One backend serves many frontends.** The same Laravel API can feed the web app now and a React Native mobile app later — no rewrite.
2. **The three PODs merge automatically.** CRM, PM, and HRIS all call the *same* API with the *same* auth and *same* database. Because they share one backend from day one, "merging" is already done. (That's literally why the CTO mandated this shape.)
3. **Clean separation.** Frontend people work on UI; backend people work on logic; they meet at the API contract. That's why the #1 rule is *no business logic in Next.js* — the moment you put logic in the frontend, you've secretly created a second backend and broken the merge.

## 1.3 Next.js vs the Create React App you know

Next.js is the **modern successor to Create React App** (CRA is deprecated). Same React you know, but:
- **File-based routing:** instead of installing react-router, a file at `src/app/hr/departments/page.tsx` *automatically* becomes the URL `/hr/departments`. Folders = routes.
- **`npm run dev`** is the new `npm start`.
- We use the **App Router** (the current Next.js system). We deliberately **do NOT** use its server-side data features (server actions, `app/api/` logic) for business logic — that's Laravel's job. In this project Next.js is "CRA-but-with-file-routing that only fetches from Laravel."

## 1.4 Why TWO servers run at once (and that's normal)

During development you'll have **two terminals open**:
- **Terminal A:** Laravel API on `http://localhost:8000`
- **Terminal B:** Next.js UI on `http://localhost:3000`

You open **:3000** in your browser (the UI). That UI makes background calls to **:8000** (the API). They're two separate programs — this is expected and correct for headless. In production they sit behind Cloudflare on `apps.viteseo.ph`, but locally they're just two ports.

## 1.5 One real request, start to finish (keep this picture in your head)

When you open the Departments page, here's the full round trip — this single flow is *the entire architecture in miniature*:

1. Browser loads `http://localhost:3000/hr/departments` → Next.js sends the page's React/JS.
2. The React component mounts; a **TanStack Query** hook fires to get data.
3. **Axios** sends `GET http://localhost:8000/api/v1/hr/departments` with an `Authorization: Bearer <token>` header.
4. **Laravel** matches the route → **Controller** (thin) → **Service** (logic) → **MySQL** → wraps the rows in a JSON envelope `{ "data": [...] }`.
5. Axios receives that JSON; React renders the list on screen.

Every feature you build is just a new copy of this loop. Phase 7 builds it once, end to end.

## 1.6 The tools we install and what each is *for*

| Tool | Plain-English job | In Laragon terms |
|---|---|---|
| **PHP 8.4** | Runs Laravel. | Laragon bundled this for you; now we install it directly. |
| **Composer** | PHP's package manager (installs Laravel & its libraries). | Same Composer you used inside Laragon. |
| **MySQL 8.4** | The database — stores all data. | Laragon bundled MySQL; now we install/run it ourselves. |
| **Node.js 24 + npm** | Runs the Next.js frontend and installs JS packages. | Like the Node you used for Create React App. |
| **Laravel Sanctum** | Issues login **tokens**; protects API routes. | New — a headless API needs token auth instead of session-cookie Blade login. |
| **spatie/permission** | Roles & permissions (`hr.employees.view`, …). | Same idea as before, shared across modules. |
| **Axios** | The frontend's phone line to the Laravel API. | The thing that replaces Blade — data comes over HTTP now. |
| **TanStack Query** | Manages fetching/caching/loading state for API data. | New — so you don't hand-write `useEffect + fetch + loading` everywhere. |
| **Tailwind + shadcn/ui** | Styling + ready-made accessible components. | The shared design system for all 3 modules. |

> ⚠️ **About Laragon:** Laragon is **Windows-only**, and you're on **Linux** now, so we don't use it. Phase 0 installs the same underlying pieces (PHP, MySQL, Composer) directly. If you ever go back to a Windows machine, Laragon would still work for the Laravel half — but the *architecture* (headless, two apps, JSON API) is identical regardless of Laragon.

Now you understand the shape. Let's build it.

---

> ⚠️ **Before Phase 0 — one decision:** Is the CTO/Core team giving you a **base monorepo** (with `Core`, auth, and the module skeleton already done)?
> - **YES →** clone it, install its dependencies, skip Phases 1–5, jump to **Phase 6** (run it) then **Phase 7** (build HR features) inside it.
> - **NO / solo hackathon →** do every phase to stand up your own copy, keeping the structure identical to the guide so it merges later.

---

# PART 2 — Setup

## Phase 0 — Install the toolchain (one-time)

**Why this phase:** on Linux there's no Laragon bundle, so we install each piece Laravel and Next.js need. Do them in order; verify at the end.

### 0.1 Git — *version control; also how you'll clone/PR later*
```bash
sudo apt update
sudo apt install -y git
git --version
```

### 0.2 PHP 8.4 + extensions — *the language Laravel runs on*
The `ppa:ondrej/php` repo has current PHP builds for Ubuntu. The extensions are the specific PHP features Laravel needs (mysql driver, mbstring for strings, xml, curl for HTTP, etc.).
```bash
sudo add-apt-repository -y ppa:ondrej/php
sudo apt update
sudo apt install -y php8.4 php8.4-cli php8.4-common php8.4-mysql \
  php8.4-xml php8.4-curl php8.4-mbstring php8.4-zip php8.4-bcmath php8.4-gd php8.4-intl
php -v          # must show PHP 8.4.x
```

### 0.3 Composer — *installs Laravel and its packages (like npm, but for PHP)*
```bash
cd ~
curl -sS https://getcomposer.org/installer -o composer-setup.php
sudo php composer-setup.php --install-dir=/usr/local/bin --filename=composer
rm composer-setup.php
composer --version
```

### 0.4 MySQL 8.4 — *the shared database all three modules will use*
> ⚠️ **Important (learned the hard way):** plain `sudo apt install mysql-server` installs MySQL **8.0**, not the **8.4** the standard requires — the distro's default repo only carries 8.0. To get 8.4 LTS you must add Oracle's official MySQL APT repo.
> - **Linux Mint wrinkle:** Oracle's repo only recognizes *Ubuntu* codenames, so pin it to **`noble`** (Mint 22.x = Ubuntu 24.04 noble), not Mint's own codename (e.g. `zena`).
> - **Key wrinkle:** use the **2025** GPG key. The older 2023 key expired Oct 2025 and triggers an `EXPKEYSIG ... is not signed` error.

**Add Oracle's MySQL 8.4 repo (renewed key + pinned to `noble`), then install:**
```bash
# 1. Install the current (2025) MySQL signing key
sudo mkdir -p /etc/apt/keyrings
wget -qO- https://repo.mysql.com/RPM-GPG-KEY-mysql-2025 | sudo gpg --dearmor -o /etc/apt/keyrings/mysql.gpg

# 2. Add the 8.4 LTS repo, pinned to Ubuntu 'noble' (works on Ubuntu AND Linux Mint)
echo "deb [signed-by=/etc/apt/keyrings/mysql.gpg] https://repo.mysql.com/apt/ubuntu noble mysql-8.4-lts mysql-tools" | sudo tee /etc/apt/sources.list.d/mysql.list

# 3. Install (interactive: set a root password + choose "Strong Password Encryption")
sudo apt-get update
sudo apt-get install -y mysql-server
sudo systemctl enable --now mysql
mysql --version                        # must show 8.4.x
```
> If you already installed MySQL 8.0 by mistake, remove it first: `sudo apt-get purge -y 'mysql-server*' 'mysql-client*' mysql-common && sudo apt-get autoremove -y && sudo rm -rf /var/lib/mysql /etc/mysql`, then do steps 1–3 above.

**Why a separate DB user + database:** we never develop as the all-powerful `root`. We make one database (`viteseo_platform`) shared by all modules, and one limited user (`viteseo`) that Laravel logs in as. `utf8mb4` is the charset that supports every character incl. emojis — the standard requires it.

Log in and create them:
```bash
sudo mysql
```
Paste this (change the password), then `EXIT;`:
```sql
CREATE DATABASE viteseo_platform CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'viteseo'@'localhost' IDENTIFIED BY 'change_me_dev_password';
GRANT ALL PRIVILEGES ON viteseo_platform.* TO 'viteseo'@'localhost';
FLUSH PRIVILEGES;
EXIT;
```

### 0.5 Node.js 24 LTS + npm — *runs Next.js and installs frontend packages*
We use **nvm** (Node Version Manager) so you can pin Node 24 exactly as the standard requires, and switch versions later without breaking your system.
```bash
curl -o- https://raw.githubusercontent.com/nvm-sh/nvm/v0.40.1/install.sh | bash
# close & reopen the terminal (so nvm loads), OR: source ~/.bashrc
nvm install 24
nvm use 24
nvm alias default 24     # make Node 24 the default in every new terminal
node -v                  # v24.x
npm -v
```

### 0.6 TablePlus — *a GUI to view/edit your database (your phpMyAdmin replacement)*
**Why:** on Linux there's no bundled phpMyAdmin (that came with Laragon). TablePlus is a desktop app that lets you browse tables, run SQL, and eyeball data — the visual sanity check you're used to. Laravel talks to MySQL through code; this is just for *you* to look.

Install (Debian/Ubuntu apt repo):
```bash
wget -qO - https://deb.tableplus.com/apt.tableplus.com.gpg.key | gpg --dearmor \
  | sudo tee /usr/share/keyrings/tableplus-archive-keyring.gpg > /dev/null
sudo add-apt-repository "deb [signed-by=/usr/share/keyrings/tableplus-archive-keyring.gpg] https://deb.tableplus.com/debian/22 tableplus main"
sudo apt update
sudo apt install -y tableplus
```
> If the repo step errors on your Ubuntu version, grab the current instructions/`.deb` from **https://tableplus.com/linux** — same result.

**Connect to your DB** (open TablePlus → *Create a new connection* → **MySQL**), using the exact values from `backend/.env` (Phase 0.4 / Phase 2.2):

| Field | Value |
|---|---|
| Host | `127.0.0.1` |
| Port | `3306` |
| User | `viteseo` |
| Password | *(the one you set in Phase 0.4)* |
| Database | `viteseo_platform` |

Click **Test** → **Connect**. You'll see tables appear here as your migrations run (empty until Phase 2's `php artisan migrate`). This is your window into the DB whenever you want to confirm a table or row exists.

### ✅ Checkpoint 0
Every command below should print a version (no "command not found"):
```bash
git --version && php -v && composer --version && mysql --version && node -v && npm -v
```

> 🧠 **If any install fails:** *"I'm on Ubuntu Linux setting up PHP 8.4, Composer, MySQL 8.4, and Node 24 for a Laravel 13 + Next.js 16 headless app. Running `<command>` gave: `<paste error>`. How do I fix it?"*

---

## Phase 1 — Create the monorepo skeleton

**Why a monorepo:** the standard puts the Laravel backend and Next.js frontend in **one git repo** (folders `backend/` and `frontend/`). Reason: a single feature usually needs both an API change and a UI change — keeping them together means one commit/PR does both, and everyone shares the same docs and history.

> ✅ **You've already done most of this phase.** You renamed `~/Downloads/HRIS` → `~/viteseo-platform-hris` and moved the guides into `docs/` — which is exactly what the original `mkdir` + `cp` commands did. So the folder and `docs/` already exist; you only need to initialize git in it.
```bash
cd ~/viteseo-platform-hris
git init
```

**Why a `.gitignore`:** some folders are huge/machine-specific or secret and must **never** be committed — `vendor/` and `node_modules/` (re-installable dependencies), `.env`/`.env.local` (secrets like DB passwords), and build caches.
```bash
printf '%s\n' \
  '/backend/vendor' '/backend/.env' '/backend/storage/*.key' \
  '/frontend/node_modules' '/frontend/.next' '/frontend/.env.local' \
  '.DS_Store' > .gitignore
```

**Target layout:**
```
viteseo-platform-hris/
├── backend/     ← Laravel (the ONLY backend / the "kitchen")   — Phase 2 & 3
├── frontend/    ← Next.js (frontend only / the "dining room")  — Phase 4 & 5
├── docs/        ← the two guides
└── .gitignore
```

### ✅ Checkpoint 1
`ls` shows `docs/` + `.gitignore`; `git status` works.

---

## Phase 2 — Backend: Laravel + Sanctum + spatie + MySQL

**Why this phase:** stand up the "kitchen" — the Laravel API — and connect it to MySQL. This is the closest to what you already know, just without Blade views.

### 2.1 Create the Laravel 13 app into `backend/`
**Why `composer create-project`:** it downloads a fresh Laravel into the `backend/` folder (this is what Laragon's "quick create" did behind the scenes).
```bash
cd ~/viteseo-platform-hris
composer create-project laravel/laravel:^13.0 backend
cd backend
```

### 2.2 Point Laravel at MySQL
**Why `.env`:** Laravel reads database credentials (and all secrets) from the `.env` file — never hard-coded, never committed. Open `backend/.env` and set:
```dotenv
APP_NAME="Viteseo Platform"
APP_URL=http://localhost:8000

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=viteseo_platform
DB_USERNAME=viteseo
DB_PASSWORD=change_me_dev_password
```
**Why run migrations now:** *migrations* are version-controlled PHP files that build your DB tables (instead of editing the DB by hand). Running them now both creates Laravel's default tables **and** proves the DB connection works.
```bash
php artisan migrate
```

### 2.3 Install API auth (Sanctum) — token-based
**Why Sanctum / why tokens:** traditional Laravel logged users in with a session cookie tied to a Blade page. A headless API has no pages — so instead, when a user logs in, Laravel hands back a **token** (a long secret string). The frontend stores it and sends it on every request (`Authorization: Bearer <token>`) to prove who it is. `install:api` sets all this up and creates a `routes/api.php` file.
```bash
php artisan install:api
php artisan migrate        # creates the personal_access_tokens table
```
**Why edit User:** the `HasApiTokens` trait gives the User model the ability to *create* those tokens. Open `backend/app/Models/User.php`:
```php
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;
}
```

### 2.4 Install roles/permissions (spatie)
**Why:** HR has sensitive actions (approve leave, process payroll). spatie lets us define permissions like `hr.leave_requests.approve` and attach them to roles — shared across all modules so one admin screen governs everything.
```bash
composer require spatie/laravel-permission:^6.0
php artisan vendor:publish --provider="Spatie\Permission\PermissionServiceProvider"
php artisan migrate
```
Add the `HasRoles` trait to `User`:
```php
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable
{
    use HasApiTokens, HasRoles, HasFactory, Notifiable;
}
```

### 2.5 Security audit — *the standard requires this before every merge*
```bash
composer audit
```

### ✅ Checkpoint 2
```bash
php artisan migrate:status     # lists users, sanctum, permission tables
php artisan about              # environment summary, no errors
```

> 🧠 **Stuck?** *"Laravel 13, MySQL, `install:api` + spatie/laravel-permission ^6. `<command>` errored: `<paste>`. .env DB is MySQL `viteseo_platform`. Fix?"*

---

## Phase 3 — Wire the HR module structure

**Why modules:** the standard keeps CRM, PM, and HR as **modules inside one Laravel app** (folders under `app/Modules/`), not three separate apps. That's what makes them share users/auth/DB and merge cleanly. Laravel doesn't create these folders for you, so we add them plus a small "service provider" that tells Laravel to load our module's routes and migrations.

### 3.1 Create the HR folders
```bash
cd ~/viteseo-platform-hris/backend
mkdir -p app/Modules/Hr/{Models,Services,Policies,Providers,Routes}
mkdir -p app/Modules/Hr/Http/{Controllers,Requests,Resources}
mkdir -p app/Modules/Hr/Database/{Migrations,Seeders}
```

**This is your backend home — what each folder is *for*:**
```
app/Modules/Hr/
  Models/            ← Eloquent classes = one per hr_* table (talks to the DB)
  Http/Controllers/  ← THIN entry points (validate → call service → return resource)
  Http/Requests/     ← validation rules live here (Form Requests), NOT in controllers
  Http/Resources/    ← shapes the JSON the API returns (the { "data": ... } envelope)
  Services/          ← ALL business logic lives here (the actual "cooking")
  Policies/          ← "is this user allowed to do this?" rules
  Routes/api.php     ← your list of endpoints (mounted at /api/v1/hr)
  Database/Migrations/ ← table definitions (schema changes as code)
  Database/Seeders/    ← permissions + demo data
  Providers/HrServiceProvider.php ← glue that plugs all the above into Laravel
```
**Why this separation (thin controller / service / request / resource):** so logic is testable and reusable, controllers stay readable, and CRM/PM/HR all look identical — reviewers can jump between modules instantly. It's the standard's hard rule.

### 3.2 Create the module's route file
**Why:** this is the menu of HR endpoints. Routes behind `auth:sanctum` require a valid token (the middleware that enforces "must be logged in").
Create `app/Modules/Hr/Routes/api.php`:
```php
<?php

use Illuminate\Support\Facades\Route;

// Public HR routes (rare) go here.

// Protected HR routes — a valid Sanctum token is required.
Route::middleware('auth:sanctum')->group(function () {
    // Endpoints get registered here in Phase 7, e.g.:
    // Route::apiResource('departments', DepartmentController::class);
});
```

### 3.3 Create the service provider that mounts the module
**Why:** a *service provider* is Laravel's "startup hook." This one says: "load HR's routes under the `/api/v1/hr` URL prefix, and auto-discover HR's migrations." Without it, Laravel wouldn't know your module folder exists.
Create `app/Modules/Hr/Providers/HrServiceProvider.php`:
```php
<?php

namespace App\Modules\Hr\Providers;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class HrServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        // Every HR route is automatically prefixed with /api/v1/hr
        Route::prefix('api/v1/hr')
            ->middleware('api')
            ->group(__DIR__ . '/../Routes/api.php');

        // Auto-load HR migrations from inside the module.
        $this->loadMigrationsFrom(__DIR__ . '/../Database/Migrations');
    }
}
```

### 3.4 Register the provider
**Why:** Laravel only runs providers listed here. Open `backend/bootstrap/providers.php` and add the HR line:
```php
return [
    App\Providers\AppServiceProvider::class,
    App\Modules\Hr\Providers\HrServiceProvider::class,   // ← add this line
];
```

### ✅ Checkpoint 3
```bash
php artisan route:list --path=api/v1/hr
```
Runs without error (empty list is fine — routes come in Phase 7). If you get "class not found," run `composer dump-autoload`.

---

## Phase 4 — Frontend: Next.js + Tailwind + shadcn + libraries

**Why this phase:** build the "dining room" — the Next.js app that holds all UI and talks to Laravel. This replaces the Create React App you used to make.

### 4.1 Create the Next.js 16 app into `frontend/`
**Why these answers:** TypeScript (typed, safer JS — the standard requires it); Tailwind (the shared styling system); `src/` dir + App Router (the structure the standard mandates); the `@/*` import alias lets you write `@/core/...` instead of long `../../..` paths.

> **File-extension rule (mandatory, TypeScript only):** use **`.tsx`** for any file with JSX/React markup (pages, components) and **`.ts`** for pure logic (api functions, hooks, types, the Axios client). **Never `.jsx` or `.js`.** Quick test: *does the file return JSX?* → `.tsx`; otherwise → `.ts`. (You'll see this applied in Phase 7: `page.tsx` and `query-provider.tsx` have markup; `api-client.ts`, `use-departments.ts`, `department.ts` don't.)
```bash
cd ~/viteseo-platform-hris
npx create-next-app@latest frontend
```
Answer:
- TypeScript → **Yes**
- ESLint → **Yes**
- Tailwind CSS → **Yes**
- `src/` directory → **Yes**
- App Router → **Yes**
- Turbopack → **Yes**
- Import alias `@/*` → **Yes**
```bash
cd frontend
```

### 4.2 Install the locked frontend libraries
**Why each:** `axios` = calls the Laravel API; `@tanstack/react-query` = handles fetching/caching/loading so you don't hand-roll it; `react-hook-form` + `zod` + `@hookform/resolvers` = build and validate forms consistently.
```bash
npm install axios @tanstack/react-query react-hook-form zod @hookform/resolvers
```

### 4.3 Initialize shadcn/ui
**Why:** shadcn gives you accessible, good-looking components (buttons, tables, inputs) that you own and can restyle — the shared design system for all modules.
```bash
npx shadcn@latest init          # accept defaults
npx shadcn@latest add button table input
```

### 4.4 Point the frontend at the Laravel API
**Why:** the frontend needs to know *where* the API is. Anything prefixed `NEXT_PUBLIC_` is readable in the browser. Create `frontend/.env.local`:
```dotenv
NEXT_PUBLIC_API_BASE_URL=http://localhost:8000/api/v1
```

### 4.5 Audit
```bash
npm audit
```

### ✅ Checkpoint 4
```bash
npm run dev
```
Open http://localhost:3000 — the starter page loads. `Ctrl+C` to stop.

> 🧠 **Stuck?** *"Next.js 16 App Router + TS + Tailwind v4 + shadcn. `<command>` failed: `<paste>`. Fix?"*

---

## Phase 5 — Shared frontend "core" (build once, every module reuses)

**Why:** the standard says all modules share **one** Axios client, **one** auth setup, **one** data-fetching provider. We build these in `src/core/` now so HR (and later CRM/PM) just import them. Building them once is what keeps the merged app consistent.

**Frontend home:**
```
frontend/src/
  app/
    (auth)/login/        ← ONE shared login (built later)
    hr/                  ← HR pages (file = route) → /hr/departments, /hr/employees ...
    layout.tsx           ← wraps the whole app with the data provider (5.3)
  core/                  ← SHARED: axios client, providers, hooks, types (reuse, don't fork)
  modules/
    hr/                  ← HR-specific components, api calls, hooks, types
```

Create folders:
```bash
cd ~/viteseo-platform-hris/frontend
mkdir -p src/core
mkdir -p src/modules/hr/{api,hooks,components,types}
mkdir -p "src/app/hr"
```

### 5.1 The shared Axios client (with the token interceptor)
**Why an "interceptor":** instead of manually attaching the login token to every single request, we configure Axios *once* to automatically add `Authorization: Bearer <token>` to all calls. `baseURL` means we can write `apiClient.get("/hr/departments")` and it expands to the full `http://localhost:8000/api/v1/hr/departments`.
Create `src/core/api-client.ts`:
```ts
import axios from "axios";

export const apiClient = axios.create({
  baseURL: process.env.NEXT_PUBLIC_API_BASE_URL, // http://localhost:8000/api/v1
  headers: { Accept: "application/json" },
});

// Automatically attach the Sanctum token (saved at login) to every request.
apiClient.interceptors.request.use((config) => {
  if (typeof window !== "undefined") {
    const token = localStorage.getItem("token");
    if (token) config.headers.Authorization = `Bearer ${token}`;
  }
  return config;
});
```

### 5.2 The TanStack Query provider
**Why:** TanStack Query needs one shared "client" wrapping your app so any component can fetch, cache, and re-use API data. This is the thing that saves you from writing `useState + useEffect + fetch + loading + error` on every page.
Create `src/core/query-provider.tsx`:
```tsx
"use client";

import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { useState } from "react";

export function QueryProvider({ children }: { children: React.ReactNode }) {
  const [client] = useState(() => new QueryClient());
  return <QueryClientProvider client={client}>{children}</QueryClientProvider>;
}
```

### 5.3 Wrap the app with the provider
**Why:** the provider must sit above every page for hooks to work. `layout.tsx` is the shell that wraps all routes. Edit `src/app/layout.tsx` and wrap `{children}` inside `<body>`:
```tsx
import { QueryProvider } from "@/core/query-provider";

// ...inside <body>...
        <QueryProvider>{children}</QueryProvider>
```

### ✅ Checkpoint 5
`npm run dev` boots with no TypeScript errors; http://localhost:3000 loads.

---

## Phase 6 — Run the whole stack (two terminals)

**Why two terminals:** remember Part 1.4 — the API and the UI are two separate programs. You run both and leave them running while you code.

**Terminal A — backend (the kitchen):**
```bash
cd ~/viteseo-platform-hris/backend
php artisan serve        # → http://localhost:8000
```
**Terminal B — frontend (the dining room):**
```bash
cd ~/viteseo-platform-hris/frontend
npm run dev              # → http://localhost:3000
```

### ✅ Checkpoint 6
- http://localhost:8000 → Laravel welcome.
- http://localhost:3000 → Next.js page.
- Commit the baseline:
```bash
cd ~/viteseo-platform-hris
git add -A
git commit -m "chore: scaffold headless monorepo (Laravel API + Next.js) with HR module skeleton"
```

---

# PART 3 — Actual development

## Phase 7 — Your FIRST feature, end-to-end ("vertical slice")

**Why we build one whole feature top-to-bottom before anything else:** a *vertical slice* means DB → API → UI for **one** resource, finished and working, before starting the next. It's the safest way to learn a headless app because you see the full round trip (Part 1.5) actually work, and you get a **template you copy for every future feature**.

We'll build **Departments** (`hr_departments`) — it's self-contained (doesn't depend on users yet), so it's the cleanest first example.

> **Mental map before we start:** we'll create, in order, the 7 backend pieces (table → model → validation → output shape → logic → controller → route), then the 3 frontend pieces (types+api → hook → page). That's exactly the request loop from Part 1.5, built once.

### BACKEND — all inside `app/Modules/Hr/`

**Step 1 — Migration** *(defines the `hr_departments` table as code)*
`app/Modules/Hr/Database/Migrations/2026_07_01_000000_create_hr_departments_table.php`
```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('hr_departments', function (Blueprint $table) {
            $table->id();                       // bigint primary key
            $table->string('name');
            $table->string('code')->unique();
            $table->boolean('is_active')->default(true);
            $table->timestamps();               // created_at + updated_at (required by standard)
            $table->softDeletes();              // deleted_at — HR records are rarely truly deleted
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_departments');
    }
};
```
Run it (creates the table in MySQL):
```bash
php artisan migrate
```
> Note the `hr_` prefix — mandatory so HR/CRM/PM tables never collide in the shared DB.

**Step 2 — Model** *(the PHP object that reads/writes that table)*
`app/Modules/Hr/Models/Department.php`
```php
<?php

namespace App\Modules\Hr\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Department extends Model
{
    use SoftDeletes;

    protected $table = 'hr_departments';               // point at our prefixed table
    protected $fillable = ['name', 'code', 'is_active']; // fields allowed to be mass-assigned
    protected $casts = ['is_active' => 'boolean'];       // return true/false, not 1/0
}
```

**Step 3 — Form Request** *(validation — keeps rules out of the controller)*
`app/Modules/Hr/Http/Requests/StoreDepartmentRequest.php`
```php
<?php

namespace App\Modules\Hr\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreDepartmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // later: check a permission like hr.departments.create
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'code' => ['required', 'string', 'max:50', 'unique:hr_departments,code'],
            'is_active' => ['boolean'],
        ];
    }
}
```
> If validation fails, Laravel automatically returns the standard `422` error envelope — you write nothing extra.

**Step 4 — API Resource** *(the exact JSON shape sent back — the `{ "data": ... }` envelope)*
`app/Modules/Hr/Http/Resources/DepartmentResource.php`
```php
<?php

namespace App\Modules\Hr\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class DepartmentResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'code' => $this->code,
            'is_active' => $this->is_active,
            'created_at' => $this->created_at,
        ];
    }
}
```

**Step 5 — Service** *(ALL business logic lives here — the actual work)*
`app/Modules/Hr/Services/DepartmentService.php`
```php
<?php

namespace App\Modules\Hr\Services;

use App\Modules\Hr\Models\Department;

class DepartmentService
{
    public function list()
    {
        return Department::query()->latest()->paginate(20); // paginated → standard meta/links
    }

    public function create(array $data): Department
    {
        return Department::create($data);
    }
}
```

**Step 6 — Controller** *(THIN: validate → call service → return resource; no logic)*
`app/Modules/Hr/Http/Controllers/DepartmentController.php`
```php
<?php

namespace App\Modules\Hr\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Hr\Http\Requests\StoreDepartmentRequest;
use App\Modules\Hr\Http\Resources\DepartmentResource;
use App\Modules\Hr\Services\DepartmentService;

class DepartmentController extends Controller
{
    public function __construct(private DepartmentService $service) {}

    public function index()
    {
        return DepartmentResource::collection($this->service->list());
    }

    public function store(StoreDepartmentRequest $request)
    {
        $department = $this->service->create($request->validated());
        return new DepartmentResource($department);
    }
}
```
> See how little the controller does? That's the point — logic is in the Service, validation in the Request, output in the Resource.

**Step 7 — Register the route** *(exposes the endpoints)*
Edit `app/Modules/Hr/Routes/api.php` — add the import and the routes inside the `auth:sanctum` group:
```php
use App\Modules\Hr\Http\Controllers\DepartmentController;

Route::middleware('auth:sanctum')->group(function () {
    Route::get('departments', [DepartmentController::class, 'index']);
    Route::post('departments', [DepartmentController::class, 'store']);
});
```
Verify:
```bash
php artisan route:list --path=api/v1/hr
# → GET|POST  api/v1/hr/departments
```

**Test the API before any login page exists** *(so you know the backend works on its own):*
`auth:sanctum` blocks anonymous calls, so mint a temporary token in tinker:
```bash
php artisan tinker
```
```php
$u = App\Models\User::factory()->create();
$u->createToken('dev')->plainTextToken;   // copy this long string, then type: exit
```
Create one department and list them (paste your token):
```bash
TOKEN="paste-token-here"
curl -X POST http://localhost:8000/api/v1/hr/departments \
  -H "Authorization: Bearer $TOKEN" -H "Content-Type: application/json" \
  -d '{"name":"Human Resources","code":"HR"}'

curl -H "Authorization: Bearer $TOKEN" http://localhost:8000/api/v1/hr/departments
```
You should get back `{"data":[{"id":1,"name":"Human Resources",...}], "meta":{...}}`. **That's your Laravel API working headlessly** — no UI involved yet.

### FRONTEND — all inside `src/modules/hr/` and `src/app/hr/`

**Step 8 — Types + API function** *(TypeScript shape + the Axios call)*
`src/modules/hr/types/department.ts`
```ts
export type Department = {
  id: number;
  name: string;
  code: string;
  is_active: boolean;
  created_at: string;
};
```
`src/modules/hr/api/departments.ts`
```ts
import { apiClient } from "@/core/api-client";
import type { Department } from "../types/department";

export async function getDepartments(): Promise<Department[]> {
  const res = await apiClient.get("/hr/departments"); // baseURL already includes /api/v1
  return res.data.data;                               // unwrap the { data: [...] } envelope
}
```

**Step 9 — TanStack Query hook** *(handles loading/caching for you)*
`src/modules/hr/hooks/use-departments.ts`
```ts
import { useQuery } from "@tanstack/react-query";
import { getDepartments } from "../api/departments";

export function useDepartments() {
  return useQuery({ queryKey: ["hr", "departments"], queryFn: getDepartments });
}
```

**Step 10 — Page** *(the screen; file path = the URL `/hr/departments`)*
`src/app/hr/departments/page.tsx`
```tsx
"use client"; // needed because we use hooks/state in the browser

import { useDepartments } from "@/modules/hr/hooks/use-departments";

export default function DepartmentsPage() {
  const { data, isLoading, error } = useDepartments();

  if (isLoading) return <p className="p-6">Loading…</p>;
  if (error) return <p className="p-6 text-red-600">Failed to load departments.</p>;

  return (
    <div className="p-6">
      <h1 className="text-2xl font-semibold mb-4">Departments</h1>
      <ul className="space-y-2">
        {data?.map((d) => (
          <li key={d.id} className="rounded border p-3">
            {d.name} <span className="text-gray-500">({d.code})</span>
          </li>
        ))}
      </ul>
    </div>
  );
}
```

> **One catch for testing the UI:** the API needs a token, and the interceptor reads it from `localStorage`. Until the login page exists, put your tinker token in the browser: open http://localhost:3000, press F12 → Console, run `localStorage.setItem("token","paste-token-here")`, then visit http://localhost:3000/hr/departments. (The proper login screen replaces this step later.)

### ✅ Checkpoint 7 — you just did the full round trip
- `php artisan route:list --path=api/v1/hr` shows the routes.
- The `curl` calls returned JSON.
- http://localhost:3000/hr/departments shows the department you created.
- Commit:
```bash
cd ~/viteseo-platform-hris
git add -A
git commit -m "feat(hr): departments vertical slice (migration→service→api→ui)"
```

**🎉 That loop — table → model → request → resource → service → controller → route → api fn → hook → page — is EVERY feature.** From here you just repeat it with new names.

---

## Phase 8 — "Where does X go?" quick-reference

| You're adding… | Backend file (under `app/Modules/Hr/`) | Frontend file (under `src/`) |
|---|---|---|
| A new table | `Database/Migrations/xxxx_create_hr_<name>.php` | — |
| A data model | `Models/<Name>.php` | `modules/hr/types/<name>.ts` |
| Validation rules | `Http/Requests/Store<Name>Request.php` | (Zod schema in the form component) |
| API output shape | `Http/Resources/<Name>Resource.php` | — |
| Business logic | `Services/<Name>Service.php` | — |
| Endpoint wiring | `Http/Controllers/<Name>Controller.php` + `Routes/api.php` | `modules/hr/api/<name>.ts` |
| Authorization | `Policies/<Name>Policy.php` | (role checks in UI) |
| Permissions list | `Database/Seeders/HrPermissionsSeeder.php` (`hr.<resource>.<action>`) | — |
| Data-fetching hook | — | `modules/hr/hooks/use-<name>.ts` |
| A screen/page | — | `app/hr/<name>/page.tsx` |
| A reusable UI piece | — | `modules/hr/components/<Thing>.tsx` |

**Naming reminders:** tables `hr_*` plural snake_case · endpoints `/api/v1/hr/<plural_snake>` · permissions `hr.<resource>.<action>` · money `decimal(12,2)` · soft-delete HR records · dates ISO 8601 UTC.

---

## Phase 9 — Daily git workflow

**Why:** merge small and often into a shared `develop` branch so the three PODs integrate continuously (not in a painful big-bang at the end).
```bash
git checkout -b develop          # once, the shared integration branch
git checkout -b feat/hr-<thing>  # one branch per feature
# ...build the vertical slice (Phase 7 pattern)...
composer audit && npm audit      # run in backend/ and frontend/
git add -A && git commit -m "feat(hr): <thing>"
git push -u origin feat/hr-<thing>   # once a remote exists → PR → review → merge to develop
```
- Commit lockfiles (`composer.lock`, `package-lock.json`) so everyone installs identical versions.

---

## Appendix A — Ready-to-paste AI prompts

- **Build a feature:**
  *"Following `docs/HRIS-AI-Build-Guide.md` (headless: Laravel-only backend, Next frontend-only, `hr_` tables, `/api/v1/hr`, `hr.*` permissions, standard JSON envelope, logic in Services) and the Phase 7 pattern in `docs/HRIS-Step-by-Step-Execution.md`, build the **`<feature>`** resource as a full vertical slice: migration, model, Form Request, Resource, Service, thin Controller, route registration — then `modules/hr` types + api functions + TanStack Query hook + a shadcn page under `app/hr/`. Explain each file briefly."*

- **Debug:**
  *"HRIS headless monorepo (Laravel 13 API + Next 16, MySQL). Doing `<step>` I got: `<paste full error>`. Relevant file: `<paste>`. What's wrong and the fix?"*

- **Explain a concept I don't get:**
  *"In our headless HRIS, explain in beginner terms what `<Sanctum tokens / TanStack Query / API Resource / service provider>` does and why we need it, using our Departments example."*

- **Review before merge:**
  *"Review this HR feature against `docs/HRIS-AI-Build-Guide.md`: `hr_` prefix, standard envelope, logic in Service not controller, validation in Form Request, no cross-module table access, soft deletes, decimal money."*

---

## Appendix B — Common gotchas (headless-specific)

- **404 on `/api/v1/hr/...`** → provider not registered (3.4) or route not added (Step 7). Re-run `php artisan route:list --path=api/v1/hr`; try `composer dump-autoload`.
- **401 Unauthorized** → no/invalid token. Routes are behind `auth:sanctum`. Use a tinker token (Step 7).
- **CORS error in the browser console** → the browser blocks :3000 → :8000 calls unless Laravel allows it. `install:api` usually sets `config/cors.php` for API paths; if blocked, ensure it allows origin `http://localhost:3000`.
- **UI loads but no data** → open F12 → Network tab, click the failing request: is it 401 (token), 404 (URL/route), or CORS? That tells you which side to fix. This split-brain debugging is normal for headless — always check which of the two apps is failing.
- **`Class App\Modules\Hr\... not found`** → `composer dump-autoload` in `backend/`.
- **Migration didn't run** → it must sit in `app/Modules/Hr/Database/Migrations/` (auto-loaded by the provider); re-run `php artisan migrate`.
- **Frontend can't read env var** → it must start with `NEXT_PUBLIC_`, and you must restart `npm run dev` after editing `.env.local`.
- **Changed something, page won't update** → for Next.js, save triggers hot reload; for Laravel route/provider changes, they apply on the next request (no restart needed, but `php artisan optimize:clear` if caching acts up).

---

## Related
- Rules & conventions: `HRIS-AI-Build-Guide.md`
- Feature list (incoming): `[[HRIS Feature List]]` — each feature becomes one Phase-7-style vertical slice.
