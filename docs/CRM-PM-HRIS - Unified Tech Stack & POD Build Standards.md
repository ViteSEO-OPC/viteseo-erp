---
type: handover
title: CRM / PM / HRIS — Unified Tech Stack & POD Build Standards
tags: [team, handover, engineering, standards, laravel, nextjs, react, erp, pods]
---

# CRM / PM / HRIS — Unified Tech Stack & POD Build Standards

> **Purpose:** One technical direction so the three PODs (CRM, Project Management, HRIS) can build **independently** but produce systems that **merge into one unified platform** later — with **no major rewrites**.
>
> **Read this before writing any code.** If a rule here conflicts with something you want to do, ask the CTO first. Do **not** improvise the architecture.

**Document Owner:** Julius Santiago / CTO
**Audience:** All PODs (CRM, PM, HRIS), DevOps Engineers
**Last updated:** 2026-06-29
**Status:** Approved direction (planning → build)

---

## 0. The Locked Stack (non-negotiable)

| Layer | Technology | Notes |
|---|---|---|
| **Backend / API** | **Laravel (PHP 8.2+)** | The **ONLY** backend. All business logic, data, auth here. API-first. |
| **Web frontend** | **Next.js (React) + TypeScript** | **Frontend only.** One app; each system is a module. |
| **HTTP client** | **Axios** | One configured instance (auth interceptor) shared by all modules. |
| **Styling / UI** | **Tailwind CSS + shadcn/ui** | One design system for all three modules. |
| **Database** | **MariaDB / MySQL** | One database, shared across modules. Laravel `mysql` driver. |
| **Auth** | **Laravel Sanctum** (token-based) | One login for all systems. |
| **Roles/Permissions** | **spatie/laravel-permission** | Shared across all modules. |
| **Server state** | **TanStack Query** (React Query) | All API data fetching. |
| **Mobile (later)** | **React Native + Expo** | Reuses the **same Laravel API**. Not built now. |
| **Proxy / DNS** | **Cloudflare** | In front of the new subdomain. |
| **Analytics** | **PostHog** | Shared event conventions. |
| **Hosting** | New subdomain, e.g. `apps.viteseo.ph` | Next.js needs a Node runtime (or `next export` static). |

> ⚠️ **THE most important rule — read twice:**
> **Next.js is FRONTEND ONLY. Laravel is the ONLY backend.**
> Do **not** put business logic, database access, or auth in Next.js **API routes / server actions / server components**. Next.js renders UI and calls the **Laravel API via Axios** — nothing more. Putting logic in Next.js creates a *second backend* and breaks the whole unification.

> **Do NOT** swap core layers for another stack (no Django, Express as the API, Firebase, Supabase, Vue, Angular, or WordPress for these systems). The point is one shared foundation.

---

## 0.5 Flexibility Policy — "Can we add more?"

**Yes — but within the rails.**

- ✅ **Allowed (with CTO approval):** extra **libraries/tools** that don't change the architecture — e.g. a data grid (TanStack Table/AG Grid), charting (Recharts), date lib (date-fns), form lib (React Hook Form + Zod), file uploads, etc. Propose it, get a quick OK, use it consistently across modules.
- ❌ **Not allowed:** anything that changes the **architecture or contracts** — a second backend, a different auth, a different database, a different API response shape, a different frontend framework, or a different language. These are fixed for everyone.

Rule of thumb: **Tools = flexible. Architecture, API, auth, DB = fixed.**

---

## 0.6 Pinned Versions (use these EXACT versions — all PODs identical)

> Verified current + stable + security-patched as of **2026-06-29**. Pick the **latest patch** of each line at install time, then **commit the lockfiles** so every POD installs the same versions. Run `npm audit` + `composer audit` before merging.

| Tool | Version to use | Notes |
|---|---|---|
| **PHP** | **8.4** | Production-recommended, supported to 2028. Laravel 13 needs 8.3+. (8.5 newest, optional.) |
| **Laravel** | **13.x** | Latest stable (released Mar 2026). |
| **Laravel Sanctum** | **^4.0** (latest compatible w/ L13) | Token auth. |
| **spatie/laravel-permission** | **^6.0** (latest compatible w/ L13) | Roles/permissions. |
| **Node.js** | **24 LTS** | Active LTS. Next 16 needs ≥20. (22 LTS also fine.) |
| **Next.js** | **16.2.x** (latest patch) | LTS; ships React 19.2; Turbopack default. |
| **React / React DOM** | **19.2** | Comes with Next 16. |
| **TypeScript** | **^5** (latest 5.x) | |
| **Tailwind CSS** | **v4.3** (latest 4.x) | v4 = CSS-first config. |
| **shadcn/ui** | latest via CLI | Works with React 19 + Tailwind v4. |
| **Axios** | **≥ 1.18.1** | ⚠️ See security note. **NEVER** 1.14.1 or 0.30.4. |
| **TanStack Query** | **^5** | Server state. |
| **React Hook Form** | **^7** | Forms. |
| **Zod** | **^3** | Validation (v4 optional). |
| **MariaDB** | **12.3 LTS** | Or MySQL 8.4 LTS. |

### ⚠️ Dependency Security Hygiene (mandatory)

- **Commit lockfiles** (`composer.lock`, `package-lock.json`) → everyone installs the *exact* same versions. No "works on my machine."
- **Axios supply-chain attack (Mar 30, 2026):** versions **1.14.1** and **0.30.4** were compromised with malware (a RAT). **Use ≥ 1.18.1.** If anyone *ever* installed 1.14.1 / 0.30.4 → **rotate all secrets/keys immediately** and reinstall clean. (≥1.18.1 also patches the critical CVE-2026-40175.)
- **Audit before every merge:** run `npm audit` and `composer audit`; resolve high/critical issues before the PR to `develop`.
- **Never blindly auto-update.** Pin versions, read changelogs, update deliberately. Use Dependabot/Renovate to *propose* updates — not auto-merge them.
- **No secrets in the repo.** `.env` only; never commit keys/tokens.

---

## 1. Recommended Unified Architecture

We build a **modular monolith**, not three separate apps:

```
                         Cloudflare  (apps.viteseo.ph)
                                │
              ┌─────────────────┴──────────────────┐
              │   Next.js app (React) — FRONTEND   │   ← UI only, no logic
              │   /crm   /projects   /hr  modules  │
              └─────────────────┬──────────────────┘
                                │  Axios → HTTPS / JSON  (Bearer token)
              ┌─────────────────┴──────────────────┐
              │     Laravel API (the only backend) │
              │  Modules: Core · CRM · PM · HR     │
              │  Sanctum auth · spatie roles       │
              └─────────────────┬──────────────────┘
                                │
                       ┌────────┴────────┐
                       │  MariaDB (one)  │   ← shared DB, prefixed tables
                       └─────────────────┘
   (later) React Native + Expo mobile app → same Laravel API
```

**Key idea:** One Laravel API + one Next.js app + one DB. CRM, PM, and HRIS are **modules inside** them. Because they share auth, users, roles, and the database from day one, "merging" later is **already done**.

---

## 2. Backend Approach — Laravel (one app, modules)

- **One Laravel project.** Each system is a **module** under `app/Modules/`.
- A **Core module** holds shared things: `User`, auth, roles, companies/teams, base classes.
- Each module owns: Models, Controllers, Requests (validation), Resources (API output), Policies, Services, Migrations, Routes.

```
app/
  Core/            # shared: User, Role, Company, base classes, traits
  Modules/
    Crm/
    Projects/
    Hr/
```

> **Rule:** Business logic lives in **Service classes**, not controllers. Controllers stay thin (validate → call service → return resource). And again: **this is the only backend — Next.js does not hold logic.**

---

## 3. Shared Auth, Users, Roles, Permissions, Models

This is the heart of unification. **All three modules share ONE of each:**

- **Users:** one `users` table. **Do NOT** create `crm_users`, `hr_employees_login`, etc. A CRM agent, an employee, and an HR admin are all rows in the **same** `users` table.
- **Authentication:** **Laravel Sanctum** (token). One login; the token works across all modules. In Next.js, store the token client-side and attach it via the shared Axios interceptor (or an httpOnly cookie via a thin Next.js proxy — UI concern only).
- **Roles & Permissions:** **spatie/laravel-permission**, namespaced per module but stored centrally:
  - Format: `module.resource.action` → `crm.contacts.view`, `pm.tasks.create`, `hr.employees.delete`.
  - Roles can span modules (`admin`, `manager`, `hr_officer`, `sales_agent`).
- **Shared core models** every module may reference: `User`, `Company`/`Team`, `Role`, `Permission`.
- **Cross-module references** use foreign keys to shared tables (e.g. a CRM contact's `owner_id` → `users.id`).

> **Golden rule:** A shared concept (user, company, department) lives **once** in Core — never duplicated per module.

---

## 4. Module Structure (so everything merges cleanly)

Every backend module follows the **same internal shape**:

```
app/Modules/Crm/
  Models/            Contact.php, Lead.php, Pipeline.php
  Http/
    Controllers/     ContactController.php
    Requests/        StoreContactRequest.php
    Resources/       ContactResource.php
  Services/          ContactService.php
  Policies/          ContactPolicy.php
  Routes/            api.php        # mounted at /api/v1/crm
  Database/
    Migrations/      2026_..._create_crm_contacts_table.php
    Seeders/
```

- Each module registers its **routes** under its own prefix (`/api/v1/crm`, `/api/v1/projects`, `/api/v1/hr`).
- Each module registers its **permissions** in a seeder.
- **No module imports another module's internals.** Need cross-module data? Call a **public Service** — never the other module's DB tables directly.

---

## 5. API Standards (every POD must follow)

- **Base path & version:** `/api/v1/...` (version always present).
- **Module prefix:** `/api/v1/{module}/{resource}` → `/api/v1/crm/contacts`, `/api/v1/projects/tasks`, `/api/v1/hr/employees`.
- **Resources are plural, snake_case:** `contacts`, `leave_requests`, `project_tasks`.
- **HTTP verbs:** `GET` read, `POST` create, `PUT/PATCH` update, `DELETE` remove.
- **Status codes:** 200, 201, 204, 401, 403, 404, 422.
- **Auth:** protected routes require `Authorization: Bearer <token>` (Sanctum).
- **Responses use Laravel API Resources** with a consistent envelope:

```json
// single
{ "data": { "id": 1, "name": "Acme Inc", "owner_id": 12 } }

// collection (paginated)
{
  "data": [ { "id": 1, "name": "Acme Inc" } ],
  "meta": { "current_page": 1, "per_page": 20, "total": 57 },
  "links": { "next": "/api/v1/crm/contacts?page=2" }
}
```

- **Errors are consistent:**

```json
{ "message": "The given data was invalid.", "errors": { "email": ["The email field is required."] } }
```

- **Query conventions:** pagination `?page=2&per_page=20`; filter `?filter[status]=open`; sort `?sort=-created_at`; relations `?include=owner,notes`.
- **Validation** always via Form Request classes.
- **Dates** in ISO 8601 UTC.
- **The Next.js frontend only consumes this API** — it never re-implements it.

---

## 6. Database Naming & Schema Rules

- **Engine:** MariaDB/MySQL, **InnoDB**, **utf8mb4**.
- **Migrations only.** Never edit the DB by hand. Every schema change = a committed migration.
- **Tables:** `snake_case`, **plural**, **prefixed by module**:
  - CRM → `crm_*` (`crm_contacts`, `crm_leads`, `crm_pipelines`)
  - Projects → `pm_*` (`pm_projects`, `pm_tasks`)
  - HR → `hr_*` (`hr_employees`, `hr_leave_requests`)
  - **Shared/Core tables = NO prefix:** `users`, `companies`, `roles`, `permissions`, `teams`.
- **Columns:** `snake_case`. PK `id` (bigint auto-increment). Always `created_at`, `updated_at`. Add `deleted_at` (soft deletes) where needed.
- **Foreign keys:** `{singular}_id` (`user_id`, `contact_id`, `project_id`) with real FK constraints.
- **Money:** `decimal(12,2)` (never float). **Booleans:** `boolean`. **Status:** string + validation, documented.
- **Public-facing IDs** (optional): add a `uuid` column for records exposed in URLs; keep `id` internal.

> **Why prefixes:** all three modules live in one database with **zero collisions**; the merge needs no renaming.

---

## 7. Frontend / UI Standards (Next.js)

- **One Next.js app (App Router), TypeScript.** Each system is a **route group / feature folder**, not a separate app.
- **Frontend ONLY** — no business logic, DB, or auth logic in Next.js (no logic in `app/api/`, server actions, or server components beyond rendering/fetching from the Laravel API).
- **One design system:** **Tailwind CSS + shadcn/ui** for all three. Don't mix UI kits per module.
- **Shared everything:** one **Axios** instance (with the Sanctum auth interceptor), one auth context/hook, one layout shell, shared TypeScript types, shared design tokens.
- **Server state:** **TanStack Query** for all API data — no hand-rolled fetch/loading per page.
- **Forms:** one approach (React Hook Form + Zod) across all modules.
- **Routing:** module routes nested under one app → `/crm/*`, `/projects/*`, `/hr/*`.

```
src/
  app/
    (auth)/login/         # one login for all modules
    crm/   ...            # CRM pages (route group)
    projects/ ...
    hr/    ...
  core/                   # axios client, auth, hooks, ui (shadcn), types, tokens
  modules/
    crm/                  # CRM components, hooks, api calls
    projects/
    hr/
```

> If you use any Next.js route handler (`app/api/`), it may only act as a **thin proxy** to Laravel (e.g. attach an httpOnly cookie). **No business logic there.**

---

## 8. Repository / Folder Structure — **MONOREPO**

**One repo holds both layers** (`backend/` Laravel + `frontend/` Next.js). PODs work inside their own module folders.

```
viteseo-platform/                 ← ONE git repo (monorepo)
├── backend/                      ← Laravel (the only backend)
│   ├── app/
│   │   ├── Core/                 ← shared: User, Role, Company, base classes
│   │   └── Modules/
│   │       ├── Hr/               ← Pod 1
│   │       ├── Crm/              ← Pod 2
│   │       └── Projects/         ← Pod 3
│   ├── database/migrations/      ← shared + per-module (prefixed)
│   ├── routes/api.php            ← mounts module routes under /api/v1/*
│   └── composer.json
├── frontend/                     ← Next.js (frontend only)
│   ├── src/
│   │   ├── app/                  ← router, login, route groups /crm /projects /hr
│   │   ├── core/                 ← axios client, auth, ui (shadcn), types, tokens
│   │   └── modules/{crm,projects,hr}/
│   └── package.json
├── docs/                         ← API contracts + this standards doc
├── .github/workflows/            ← CI for both layers
└── README.md
```

- **Why monorepo:** one source of truth; a single PR can change the API **and** its UI together (atomic features); shared docs/CI; easier coordination across PODs.
- **Keep it simple — do NOT add a JS monorepo tool** (Turborepo/Nx) for this. They are JS-only and won't manage the PHP/Laravel side. Each layer keeps its own dependency manager: **`composer` in `backend/`**, **`npm` in `frontend/`**.
- **Branching:** each POD works on a feature branch per module → PR → reviewed → merge to `develop`.
- **One shared `develop` branch** → integrate continuously, not at the end.
- **CI on every PR (two jobs in one pipeline):** backend = Pint + PHPStan + tests; frontend = ESLint + Prettier + `tsc` + tests. Run `composer audit` + `npm audit` here too.
- **Lockfiles committed:** `backend/composer.lock` and `frontend/package-lock.json`.

---

## 9. Integration Rules (merge without rewrites)

1. **One database, one auth, one API** from day one — nothing to "connect" later.
2. **Cross-module data via foreign keys to Core** (everything points to `users.id`, `companies.id`).
3. **No module reads another module's tables directly.** Use a **public Service**, not `DB::table('pm_projects')`.
4. **Same conventions everywhere** (API envelope, naming, validation, errors). Reviewers reject deviations.
5. **Shared permissions namespace** so a unified admin manages all modules.
6. **One design system** so the merged UI looks like one product.
7. **One Axios client + one auth flow** on the frontend — every module uses it.
8. **Continuous integration to `develop`** — merge small and often.

---

## 10. ❌ Do NOT Do (prevents hallucinated / incompatible builds)

- ❌ Do **not** put backend logic in **Next.js** (API routes, server actions, server components). **Laravel is the only backend.**
- ❌ Do **not** use another backend stack — no Django, Express API, Firebase, Supabase.
- ❌ Do **not** use another frontend framework — **Next.js/React only** (no Vue, Angular, plain SPA mix).
- ❌ Do **not** build your own auth or a separate users table. Use **shared Sanctum + `users`**.
- ❌ Do **not** create a separate database or separate app per system. One DB, one API, one frontend.
- ❌ Do **not** invent your own API response shape. Use the **standard envelope** (Section 5).
- ❌ Do **not** query another module's tables. Use relationships/services.
- ❌ Do **not** skip migrations or edit the DB by hand.
- ❌ Do **not** skip the module/table **prefix** (`crm_`, `pm_`, `hr_`).
- ❌ Do **not** put business logic in controllers. Use **Services**.
- ❌ Do **not** use a different UI kit or design tokens per module.
- ❌ Do **not** build a separate login screen per system.
- ❌ Do **not** add a new library/tool without CTO approval (see Section 0.5).
- ❌ Do **not** commit secrets. Use `.env`.
- ❌ When unsure about architecture → **ask the CTO**, do not guess.

---

## 11. POD Instruction Brief (give this to each POD before they start)

> **Your POD owns ONE module** (CRM, Projects, or HR) inside a shared platform. Build only your module, but follow the shared rules so it merges with the others automatically.

**Your stack (fixed):** Laravel API (backend) + Next.js/React + TypeScript + Axios + Tailwind + shadcn/ui (frontend) + MariaDB + Sanctum + spatie roles.

**Remember:** Next.js is **frontend only**. All logic, data, and auth live in **Laravel**.

**Your scope:**
- Backend: `app/Modules/{YourModule}/` — Models, Controllers, Requests, Resources, Services, Policies, Migrations, Routes.
- Frontend: `src/modules/{yourmodule}/` + your route group in `src/app/{yourmodule}/`.
- Your API routes: `/api/v1/{yourmodule}/...`.

**You MUST:**
- Use the shared `users` table, Sanctum auth, spatie permissions (`{module}.{resource}.{action}`).
- Prefix all your tables (`crm_` / `pm_` / `hr_`).
- Follow the API envelope, naming, validation (Form Requests), and error format.
- Use the shared Axios client, TanStack Query, and design system (Tailwind + shadcn/ui).
- Write migrations for every schema change; commit; PR to `develop`.

**You MUST NOT:**
- Change the stack, add a second backend in Next.js, build your own auth/users/DB, or invent your own API/response/UI conventions (see Section 10).
- Add libraries without CTO approval.

**Definition of done for your module:**
- Core flow works end-to-end against the shared Laravel API and auth.
- Tables prefixed; permissions seeded; API follows the standard.
- UI uses the shared design system; data via the shared Axios client + TanStack Query.
- Passes lint + review on `develop`.

**When in doubt → ask the CTO. Do not improvise the architecture.**

---

## Related
- [[Assistant Project Manager - Weekly Execution Guide - Mini Hackathon Prep]]
- [[AI Tool Decision Guide]] · [[Decision Protocol]]
- ERP reference: `erp.viteseo.ph` (Frappe/React/MariaDB — separate system; integrate via API only if needed)
