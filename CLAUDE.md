# Viteseo HRIS — Project Instructions

Headless modular monolith: **Laravel 13 API** (`backend/`, port 8000) + **Next.js 16 frontend** (`frontend/`, port 3000) + one MySQL 8.4 DB (`viteseo_platform`). HR is a module (`app/Modules/Hr/`) inside a shared platform (CRM/PM/HR merge later). **~1-week production-quality build** — target a full working HRIS (≥90% complete). Correctness, validation, edge-cases, non-happy-path error states, and security are all in scope; still follow the plan and don't invent unrequested features.

## Source-of-truth documents — read the right one, never guess

| Question | Read this FIRST |
|---|---|
| What to build, in what order, tables/endpoints/logic per feature, Figma node IDs, seeder, walkthrough script, slice sizing/sequencing | `docs/HRIS-BUILD-PLAN.md` ← **the execution source of truth** |
| Cross-cutting mechanics: timezone rule, leave-days math, auth/session flow, mutation→invalidation pattern, upload/CORS gotchas | `docs/HRIS-BUILD-PLAN.md` **§5.5** |
| Architecture rules, naming, API envelope, what is forbidden | `docs/CRM-PM-HRIS - Unified Tech Stack & POD Build Standards.md` |
| How to build one vertical slice (the migration→…→page loop), env setup, common errors | `docs/HRIS-Step-by-Step-Execution.md` (Phase 7 + Appendix B) |
| What a screen contains / UX behavior | `docs/POD 1 ... (Employee View) Features.md` / `(HR View) Features.md` |
| Figma→Tailwind translation, custom CSS escape hatches, component composition | `docs/HRIS-Frontend-Styling-and-Architecture.md` |
| Folder purpose ("where does X go?") | `PROJECT-STRUCTURE.md` (root) |

**Anti-hallucination rule:** if a detail isn't specified in those docs, check `HRIS-BUILD-PLAN.md` §5.5; if still ambiguous, **ask the user — do not invent** schemas, endpoints, or conventions.

## Hard rules (violations break the platform merge — never deviate)

- **Laravel is the ONLY backend.** No business logic, DB access, or auth in Next.js (no server actions / `app/api` logic).
- **HR tables are prefixed `hr_`**, plural snake_case. Shared tables (`users`, `roles`, `permissions`) have NO prefix. Never create another users table.
- **HR endpoints:** `/api/v1/hr/<plural_snake>`; auth endpoints are shared at `/api/v1/auth/*`. All behind `auth:sanctum`; HR-only actions also check `role:hr_officer`.
- **Response envelope:** `{ "data": ... }` (+ `meta`/`links` for paginated lists) via API Resources; errors `{ message, errors }`. Never invent a different shape.
- **Code placement:** logic in `Services/`, validation in Form `Requests/`, output in `Resources/`, controllers THIN. HR backend code lives in `backend/app/Modules/Hr/`; HR frontend code in `frontend/src/modules/hr/` + pages in `frontend/src/app/`. Shared frontend core in `frontend/src/core/`.
- **Permissions** named `hr.<resource>.<action>`. **Money** `decimal(12,2)` (display via `Intl.NumberFormat en-PH`). **Datetimes stored UTC, day-boundaries computed in Asia/Manila** (see plan §5.5b). Soft-delete HR records.
- **Frontend stack is fixed:** shared Axios client (`src/core/api-client.ts`) + TanStack Query + shadcn/Tailwind + RHF+Zod. Use the UI-kit components and design tokens — no raw hexes, no ad-hoc styling, no new libraries without approval.
- **Identity from the token:** "my X" endpoints resolve the employee from `$request->user()->employee` — never trust a client-sent employee_id for ownership.

## Working conventions

- `php artisan`/`composer` run in `backend/`; `npm` runs in `frontend/`. ("Command not found" usually = wrong folder.)
- Servers: `php artisan serve` (:8000) + `npm run dev` (:3000), two terminals.
- DB reset: `php artisan migrate:fresh --seed`. Demo logins: `hr@viteseo.ph` / `employee@viteseo.ph` (password `password`).
- **Per-slice protocol (plan §11):** backend → verify with `curl` → seed → fetch the slice's Figma frame (screenshot first, node IDs in plan §1.5, never bulk-fetch) → assemble UI from the kit → verify in browser → `git commit` — then STOP for the user's check before the next slice.
- Figma file key: `5lX7DgqGbutmi9QfJryBRg`. Screens without a Figma frame = clean shadcn defaults using the tokens.
- TypeScript only: `.tsx` for JSX files, `.ts` for logic — never `.js`/`.jsx`.
- Next.js 16 note: heed `frontend/AGENTS.md` — verify unfamiliar Next APIs against `frontend/node_modules/next/dist/docs/` instead of assuming.
