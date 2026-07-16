---
type: ai-build-guide
title: HRIS — AI Build Guide & Context (POD: HRIS)
tags: [hris, ai-context, laravel, nextjs, react, standards, hackathon]
owner: devops@viteseo.ph
based_on: "CRM-PM-HRIS — Unified Tech Stack & POD Build Standards (Julius Santiago / CTO, 2026-06-29)"
status: living document — feature list to be appended later
---

# HRIS — AI Build Guide & Context

> **What this file is:** The single source of truth you (the AI) load at the start of **every** HRIS prompting/build session. It encodes the **company-locked architecture** so that anything generated here is compatible with the CRM and PM modules and merges into one platform later **with no rewrites**.
>
> **How to use it:** Keep this in context for all HRIS work. If a request conflicts with a rule here, **stop and flag it** rather than improvising. When the HRIS **feature list** doc arrives, it will be appended/linked here and read alongside this guide.
>
> **Golden sentence to never forget:** *Laravel is the ONLY backend. Next.js is FRONTEND ONLY. HRIS is a module — not a standalone app.*

---

## 🗓️ Build Context — production-quality HRIS in ~1 week (READ FIRST, every session)

**This is a ~1-week build of a full working HRIS**, targeting **90–100% feature-complete and production-quality** — correct, validated, and secure, *not* a throwaway demo. Work in vertical slices, but each slice should be **done right**, not just happy-path. (Superseded the earlier "<8h hackathon MVP" framing on 2026-07-07.)

**What this means for you (the AI) — behave accordingly:**

1. **Optimize for correct, complete slices.** Deliver the full Phase-7 vertical slice (migration → model → request → resource → service → controller → route → api fn → hook → page) **and** its input validation, edge-cases, and non-happy-path error states. "Working" means it holds up to bad input and the second user, not just the demo click-path.
2. **Ship the whole slice in one response.** Generate all the files for a feature together, paste-ready. Don't dribble file-by-file, and don't pause for optional confirmations — keep moving, then stop for the per-slice check.
3. **Do NOT invent unrequested features or scope.** Scope discipline still applies: "production-quality" means doing the *requested* feature well, not adding new features, abstractions, or infra. **§12 (Recommendations) still needs the user's explicit go-ahead** before you build any of it.
4. **In scope now (previously deferred):** input validation & edge-cases, non-happy-path error states, authorization/ownership checks, data-integrity guards (DB transactions + `lockForUpdate` wherever money or balances move), and the fixes from `docs/SECURITY-AUDIT.md`. **Still lean-defer:** heavy analytics instrumentation (PostHog), exhaustive test suites (smoke + key-path tests suffice), and gold-plating beyond the design.
5. **When scope is ambiguous, pick the option that is correct and secure** — not merely the smallest. Flag any non-trivial assumption in one line and keep going.
6. **The locked rails are non-negotiable.** The stack (§1), API contract (§5), shared auth/users (§4), and `hr_*` DB rules (§6) hold 100% — they're what let this merge with CRM/PM later with no rewrite.

> 🔜 **Feature list / revisions arrive in a SEPARATE doc.** Until a feature is specified there or requested by the user, **do not invent HRIS features.** Each specified feature becomes one Phase-7 vertical slice, built to the production-quality bar above.

---

## 0. Role & Mission (read first, every session)

You are assisting the **HRIS POD** in building the **Human Resources module** of a **modular monolith** platform shared with CRM and Project Management.

- You build **only the HR module**, but strictly follow shared conventions so it integrates automatically with the other modules.
- The platform shares **one** of each from day one: one database, one `users` table, one auth (Sanctum), one permission system (spatie), one API contract, one Next.js app, one design system.
- Your deliverables live in fixed locations:
  - Backend: `backend/app/Modules/Hr/`
  - Frontend: `frontend/src/modules/hr/` + route group `frontend/src/app/hr/`
  - API namespace: `/api/v1/hr/...`
  - DB tables: prefixed `hr_*`
  - Permissions: `hr.{resource}.{action}`

**When architecture is unclear → ask (the human/CTO). Do not guess or invent structure.**

---

## 1. The Locked Stack (non-negotiable)

| Layer | Technology | Rule |
|---|---|---|
| Backend / API | **Laravel (PHP)** | The ONLY backend. All business logic, data, auth. API-first. |
| Web frontend | **Next.js (React) + TypeScript** | Frontend ONLY. HRIS is a module/route group. |
| HTTP client | **Axios** | One shared configured instance with the auth interceptor. |
| Styling / UI | **Tailwind CSS + shadcn/ui** | One shared design system. No other UI kit. |
| Database | **MySQL (InnoDB, utf8mb4)** | One shared DB, `hr_*` prefixed tables. (Standard allows MariaDB too; this POD uses MySQL.) |
| Auth | **Laravel Sanctum** (token) | One login for all modules. No custom auth. |
| Roles/Permissions | **spatie/laravel-permission** | `hr.{resource}.{action}`. |
| Server state | **TanStack Query (React Query)** | ALL API data fetching. No hand-rolled fetch. |
| Forms | **React Hook Form + Zod** | One approach everywhere. |
| Mobile (later) | React Native + Expo | Reuses the SAME Laravel API. Not built now. |
| Proxy / DNS | Cloudflare | In front of `apps.viteseo.ph`. |
| Analytics | PostHog | Shared event conventions. |

> ❌ **Never swap core layers.** No Django, no Express-as-API, no Firebase/Supabase, no Vue/Angular, no WordPress. **Tools are flexible; architecture, API, auth, DB are fixed.**

### 1.1 Pinned Versions (use these EXACT lines — identical across all PODs)

| Tool | Version | Notes |
|---|---|---|
| PHP | **8.4** | Supported to 2028. Laravel 13 needs 8.3+. |
| Laravel | **13.x** | Latest stable. |
| Laravel Sanctum | **^4.0** | Token auth. |
| spatie/laravel-permission | **^6.0** | Roles/permissions. |
| Node.js | **24 LTS** | (22 LTS also fine.) |
| Next.js | **16.2.x** | App Router; Turbopack default; ships React 19.2. |
| React / React DOM | **19.2** | |
| TypeScript | **^5** | |
| Tailwind CSS | **v4.3** | v4 = CSS-first config. |
| shadcn/ui | latest via CLI | Works with React 19 + Tailwind v4. |
| Axios | **≥ 1.18.1** | ⚠️ NEVER 1.14.1 or 0.30.4 (compromised — malware/RAT). |
| TanStack Query | **^5** | |
| React Hook Form | **^7** | |
| Zod | **^3** | (v4 optional.) |
| MySQL | **8.4 LTS** | This POD's database. (Standard also allows MariaDB 12.3 LTS.) Laravel `mysql` driver either way. |

**Security hygiene (mandatory):** commit lockfiles (`composer.lock`, `package-lock.json`); run `npm audit` + `composer audit` before any merge; never commit secrets (`.env` only); pin versions, never blind auto-update.

---

## 2. Architecture in One Picture

```
Cloudflare (apps.viteseo.ph)
        │
  Next.js app (React) — FRONTEND ONLY   ← UI only, no logic
  route groups: /crm  /projects  /hr
        │  Axios → HTTPS / JSON  (Bearer token)
  Laravel API — the ONLY backend
  Modules: Core · Crm · Projects · Hr
  Sanctum auth · spatie roles
        │
  MySQL (one shared DB, prefixed tables)
```

One Laravel API + one Next.js app + one DB. HRIS = a module inside them. Because auth, users, roles, and DB are shared from day one, the "merge" is already done.

---

## 3. Backend — Laravel module layout (HR)

One Laravel project. HR is a module under `app/Modules/Hr/`. A **Core** module (owned centrally) holds shared `User`, `Role`, `Company`/`Team`, base classes — **you reference Core, you do not duplicate it.**

```
backend/app/Modules/Hr/
  Models/            Employee.php, LeaveRequest.php, Department.php ...
  Http/
    Controllers/     EmployeeController.php     (THIN: validate → service → resource)
    Requests/        StoreEmployeeRequest.php   (all validation lives here)
    Resources/       EmployeeResource.php       (all API output shape)
  Services/          EmployeeService.php         (ALL business logic lives here)
  Policies/          EmployeePolicy.php
  Routes/            api.php                     (mounted at /api/v1/hr)
  Database/
    Migrations/      2026_..._create_hr_employees_table.php
    Seeders/         HrPermissionsSeeder.php     (register hr.* permissions)
```

**Backend rules:**
- **Business logic → Service classes.** Controllers stay thin. Validation → Form Requests. Output → API Resources.
- **No cross-module table access.** Need CRM/PM data? Call that module's **public Service** — never `DB::table('pm_projects')` or another module's models.
- Every schema change = a **committed migration**. Never edit the DB by hand.
- Register HR permissions in a seeder.

---

## 4. Shared Auth, Users, Roles (the heart of unification)

- **Users:** ONE `users` table. **Never** create `hr_employees_login` or a separate auth table. An HR admin, a manager, and an employee are all rows in `users`. An `hr_employees` record **links to** `users` via `user_id` (FK) — it holds HR profile data, not credentials.
- **Auth:** Laravel Sanctum tokens. One login works across all modules. Frontend stores the token and attaches it via the shared Axios interceptor (or httpOnly cookie via a thin Next proxy — UI concern only).
- **Permissions:** spatie, namespaced `hr.{resource}.{action}` → e.g. `hr.employees.view`, `hr.leave_requests.approve`, `hr.payroll.process`. Roles can span modules (`admin`, `manager`, `hr_officer`).
- **Cross-module references** are FKs to shared Core tables (`users.id`, `companies.id`).

> Golden rule: a shared concept (user, company, department-if-shared) lives **once** in Core — never duplicated in HR.

---

## 5. API Standards (every endpoint must follow)

- **Base + version:** `/api/v1/...` — version always present.
- **Module + resource:** `/api/v1/hr/{resource}` → `/api/v1/hr/employees`, `/api/v1/hr/leave_requests`, `/api/v1/hr/attendances`.
- **Resources plural, snake_case:** `employees`, `leave_requests`, `payroll_runs`.
- **Verbs:** GET read · POST create · PUT/PATCH update · DELETE remove.
- **Status codes:** 200, 201, 204, 401, 403, 404, 422.
- **Auth header:** `Authorization: Bearer <token>` on protected routes.
- **Response envelope (via Laravel API Resources):**

```json
// single
{ "data": { "id": 1, "user_id": 12, "job_title": "Engineer" } }

// collection (paginated)
{
  "data": [ { "id": 1, "user_id": 12 } ],
  "meta": { "current_page": 1, "per_page": 20, "total": 57 },
  "links": { "next": "/api/v1/hr/employees?page=2" }
}
```

- **Error shape (consistent):**

```json
{ "message": "The given data was invalid.", "errors": { "email": ["The email field is required."] } }
```

- **Query conventions:** pagination `?page=2&per_page=20`; filter `?filter[status]=approved`; sort `?sort=-created_at`; relations `?include=user,department`.
- **Dates:** ISO 8601 UTC.
- The Next.js frontend **only consumes** this API — never re-implements it.

---

## 6. Database Naming & Schema Rules

- **Engine:** MySQL, InnoDB, utf8mb4.
- **Tables:** snake_case, plural, **`hr_` prefixed** → `hr_employees`, `hr_leave_requests`, `hr_departments`, `hr_attendances`, `hr_payroll_runs`.
- **Shared/Core tables have NO prefix:** `users`, `companies`, `roles`, `permissions`, `teams`. HR references these, does not recreate them.
- **Columns:** snake_case. PK `id` (bigint auto-increment). Always `created_at`, `updated_at`. Add `deleted_at` (soft deletes) where records shouldn't be hard-deleted (employees, leave records, payroll — almost always in HR).
- **Foreign keys:** `{singular}_id` with real FK constraints → `user_id` → `users.id`, `department_id` → `hr_departments.id`, `employee_id` → `hr_employees.id`.
- **Money:** `decimal(12,2)` — never float (critical for payroll/salary). **Booleans:** `boolean`. **Status:** string + validation, documented enum values.
- **Public-facing IDs:** add a `uuid` column for records exposed in URLs; keep `id` internal.

> Why the prefix: all three modules share one DB with zero collisions, so the merge needs no renaming.

---

## 7. Frontend — Next.js (HR module)

- **One Next.js app (App Router), TypeScript.** HR is a **route group / feature folder**, not a separate app.
- **File extensions (mandatory):** TypeScript only — **`.tsx` for any file containing JSX/React markup** (pages, components), **`.ts` for pure logic** (api functions, hooks, types, the Axios client). **Never `.jsx` or `.js`** in frontend source. Rule: returns JSX → `.tsx`; otherwise → `.ts`.
- **Frontend ONLY** — no business logic, DB, or auth logic in Next.js. No logic in `app/api/`, server actions, or server components beyond rendering/fetching from Laravel.
- **Design system:** Tailwind + shadcn/ui only.
- **Shared everything:** one Axios instance (with Sanctum interceptor), one auth context/hook, one layout shell, shared TS types, shared design tokens.
- **Server state:** TanStack Query for all API data — no hand-rolled fetch/loading per page.
- **Forms:** React Hook Form + Zod everywhere.

```
frontend/src/
  app/
    (auth)/login/          # one shared login — DO NOT build an HR-only login
    hr/                    # HR pages (route group)  → /hr/employees, /hr/leave ...
  core/                    # axios client, auth, hooks, ui (shadcn), types, tokens (SHARED — reuse, don't fork)
  modules/
    hr/                    # HR components, hooks, api-call functions, HR-specific types
```

> If you ever add a Next.js route handler (`app/api/`), it may only be a **thin proxy** to Laravel (e.g. set an httpOnly cookie). No business logic there.

---

## 8. Repository — MONOREPO

One git repo holds both layers. You work inside HR folders.

```
viteseo-platform/            ← ONE monorepo
├── backend/                 ← Laravel (only backend)   → composer
│   └── app/{Core, Modules/Hr}
├── frontend/                ← Next.js (frontend only)   → npm
│   └── src/{app, core, modules/hr}
├── docs/                    ← API contracts + standards + this guide
├── .github/workflows/       ← CI for both layers
└── README.md
```

- Each layer keeps its own dependency manager (`composer` in `backend/`, `npm` in `frontend/`). **Do NOT** add Turborepo/Nx.
- **Branching:** feature branch per piece of work → PR → review → merge to shared `develop`. Integrate small and often.
- **CI on every PR:** backend = Pint + PHPStan + tests; frontend = ESLint + Prettier + `tsc` + tests; plus `composer audit` + `npm audit`.
- Commit lockfiles.

---

## 9. ❌ Do NOT (guardrails against incompatible builds)

- ❌ Put backend logic in Next.js (API routes, server actions, server components). Laravel only.
- ❌ Use another backend/frontend stack (Django, Express API, Firebase, Supabase, Vue, Angular).
- ❌ Build custom auth or a separate users table. Use shared Sanctum + `users`.
- ❌ Create a separate DB or separate app for HR. One DB, one API, one frontend.
- ❌ Invent your own API response shape. Use the standard envelope (§5).
- ❌ Query another module's tables. Use its public Service.
- ❌ Skip migrations or edit the DB by hand.
- ❌ Skip the `hr_` table prefix.
- ❌ Put business logic in controllers. Use Services.
- ❌ Use a different UI kit / design tokens.
- ❌ Build a separate HR login screen.
- ❌ Add a new library/tool without CTO approval.
- ❌ Commit secrets. Use `.env`.
- ❌ Float for money. Use `decimal(12,2)`.

---

## 10. Definition of Done (per HRIS feature)

- Core flow works end-to-end against the shared Laravel API + shared auth.
- Tables `hr_`-prefixed; permissions seeded (`hr.*`); soft deletes where needed.
- API follows the standard envelope, naming, validation (Form Requests), error format.
- Business logic in Services; controllers thin; output via API Resources.
- UI uses shared Tailwind + shadcn/ui; data via shared Axios + TanStack Query; forms via RHF + Zod.
- Passes lint/typecheck/tests + review on `develop`.

---

## 11. How to Prompt Me (workflow tips for the human)

To keep every generation on-spec, start feature prompts with a short frame, e.g.:

> "Following the HRIS-AI-Build-Guide (Laravel-only backend, Next frontend-only, `hr_` tables, `/api/v1/hr`, `hr.*` permissions, standard envelope), build **[feature]**. Give me: migration(s), Model(s), Form Request(s), Resource(s), Service, Controller, route registration, spatie permissions, then the Next.js `modules/hr` API functions + TanStack Query hooks + shadcn pages."

Ask me to produce **backend and frontend together** for a feature (atomic vertical slice) so the contract and UI always match.

---

## 12. Recommendations (my additions — confirm with CTO before treating as law)

> ⚠️ **PENDING — DO NOT IMPLEMENT YET.** Nothing in this section is decided. These are *proposals only*, awaiting a decision (from the human/CTO). **Do not apply, scaffold, or generate code based on any item below until it is explicitly confirmed.** When an item is approved, move it out of this section into the relevant rule above.

These stay strictly within the locked rails; none change architecture, API, auth, or DB contracts.

1. **Build vertical slices, not layers.** Ship one HR resource fully (migration → service → API → hook → page) before starting the next. Easier to review, always integrable.
2. **HR needs an audit trail.** HR data is sensitive (salaries, leave, discipline). Recommend an `hr_audit_logs` table (or a shared Core audit if Core provides one — check first) capturing who changed what, when. Propose to CTO whether audit lives in Core (preferred, cross-module) or HR.
3. **Employee ≠ User.** Model `hr_employees` as an HR profile that **references** `users.id` (employment data, job title, department, hire date, salary), keeping credentials/identity in Core `users`. This is the cleanest fit for the "one users table" rule.
4. **Money & dates discipline.** All salary/allowance/deduction fields `decimal(12,2)`; all dates ISO 8601 UTC in the API; do date-only fields (hire date, leave date) as `date`.
5. **Soft-delete HR records by default.** Employees, leave requests, and payroll should almost never be hard-deleted — add `deleted_at`.
6. **Approval workflows are first-class in HR.** Leave, overtime, and similar follow request → pending → approved/rejected. Recommend a consistent `status` string + `approved_by` (`users.id`) + `approved_at` pattern reused across HR resources for a uniform UI.
7. **PostHog events:** define HR event names up front (`hr_leave_requested`, `hr_leave_approved`, etc.) following the shared convention so analytics is consistent from day one.
8. **Seed demo data** (departments, a few employees, leave types) for the hackathon demo — via Seeders, never hand-inserted.
9. **Permission granularity:** define `hr.*` permissions per resource+action early and seed them, so role-based UI gating works from the first feature.
10. **Make it auto-load across ALL your AI tools, not just Claude Code.** `CLAUDE.md` is auto-read only by Claude Code. Other tools look for their own file. To avoid maintaining duplicates, keep the real content in **one master file** and make the rest thin pointers to it:
    - **Master file:** `AGENTS.md` at the repo root (cross-tool emerging standard; read natively by OpenCode CLI and others). Put the full guide there.
    - **`CLAUDE.md`** (Claude Code) → one line: `Read and follow AGENTS.md in this directory.`
    - **Cursor** → `.cursor/rules/hris.mdc` (or legacy `.cursorrules`) → same one-line pointer to `AGENTS.md`.
    - **GitHub Copilot** → `.github/copilot-instructions.md` → same pointer.
    - **Windsurf** → `.windsurfrules` → same pointer.
    - Alternative on Unix: make the pointer files **symlinks** to `AGENTS.md` (e.g. `ln -s AGENTS.md CLAUDE.md`) so they're always identical. (Symlinks may not survive on some Windows setups or certain tool sandboxes — a one-line pointer is the safest, most portable option.)
    - Net effect: edit `AGENTS.md` once; Claude Code, OpenCode, Cursor, Copilot, and Windsurf all pick up the same context.

---

## 13. Open Items / To Be Filled

- ⏳ **HRIS Feature List** — the separate doc you're waiting on. When it arrives, read it alongside this guide; I'll map each feature to: resource name(s), `hr_*` table(s), `/api/v1/hr/*` endpoints, `hr.*` permissions, Service methods, and the Next.js pages/hooks. Add a link here: `[[HRIS Feature List]]`.
- ⏳ Confirm with CTO: audit-trail location (Core vs HR), any shared `departments`/`companies` structure already owned by Core (don't duplicate), and approval-workflow conventions.

---

## Source & Related
- Derived from: **CRM-PM-HRIS — Unified Tech Stack & POD Build Standards** (Julius Santiago / CTO, last updated 2026-06-29, status: Approved direction).
- Related: `[[Assistant Project Manager - Weekly Execution Guide - Mini Hackathon Prep]]`, `[[AI Tool Decision Guide]]`, `[[Decision Protocol]]`.
- ERP reference (separate system, integrate via API only if needed): `erp.viteseo.ph` (Frappe/React/MariaDB).
