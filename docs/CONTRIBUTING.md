# Contributing — Vite SEO Systems monorepo

This is the **single source of truth** for all three systems. The old separate repos
(HRIS / CRM / PMS) are **frozen** — everyone works here now.

## 🏠 The model: one repo, three rooms
- **The repo** = one house — ONE Laravel backend + ONE Next.js frontend (not three separate apps).
- **Your module** = your room. Inside it you work freely.
- **Shared spaces** (login, `User`, nav, seeder) = house rules — don't change them without the CTO.

| Pod | Module | Backend folder | Frontend folder |
|---|---|---|---|
| Pod 1 | **HR (TeamOra)** | `backend/app/Modules/Hr/` | `frontend/src/modules/hr/` + `frontend/src/app/teamora/` |
| Pod 2 | **CRM (Zerem)** | `backend/app/Modules/Crm/` | `frontend/src/modules/crm/` + `frontend/src/app/zerem/` |
| Pod 3 | **Projects (Sprintly)** | `backend/app/Modules/Projects/` | `frontend/src/modules/projects/` + `frontend/src/app/sprintly/` |

---

## ⚠️ Bringing your OLD work into this repo (one-time catch-up)

Your old repo was merged in as a **snapshot** — but it was **adapted to the new structure**
(module folders, prefixed tables, namespaced permissions, product-name routes, shared login).
So you **cannot just `git pull` or merge your old repo here** — the shape is different.

**If you changed things in your old repo AFTER the snapshot, port them in manually — once:**
1. In your **old repo**, find what you changed since the merge (`git log` / `git diff`).
2. In **this repo**, open your module folder (table above).
3. **Re-apply each change here**, adapting to the new structure:
   - Tables are now **prefixed** (`crm_*` / `pm_*`) — update migrations/models accordingly.
   - Your routes live under **`/zerem`** or **`/sprintly`** (browser) — API stays `/api/v1/{crm|projects}`.
   - Permissions are **namespaced** (`crm.*` / `projects.*`).
   - Auth is **shared** — no own login / users table.
4. Commit on a branch → open a PR (below).

**Not sure how a change maps? Ask the CTO** — don't guess.
**Going forward: only this repo.** Never touch the old ones again.

---

## 🌿 The workflow (every change)
1. `git clone` this repo, then `git pull` before starting (stay current with `develop`/`main`).
2. Create a **feature branch**: `git checkout -b crm/lead-import` (prefix with your module).
3. **Work only inside your module folder.** Don't edit other modules or shared `Core`.
4. **Run it locally + test** (see the README quick start).
5. `git push` your branch → open a **Pull Request** → the CTO/lead **reviews** → merge.
6. Keep PRs **small and frequent** — easier to review, fewer conflicts.

**Do NOT push directly to `main`.** Small reversible fixes still go through a branch + PR.

---

## 🚫 Shared / Core — ask the CTO before touching
These are shared by all three systems; a mistake here breaks everyone:
- `backend/app/Models/User.php` · `backend/app/Http/Controllers/Controller.php`
- `backend/database/seeders/RolesAndPermissionsSeeder.php` · the shared `/api/v1/auth/*`
- `frontend/src/core/*` (api-client, auth, layout, ui) · `frontend/src/core/nav.ts`

---

## 📏 Conventions (must follow)
- **Laravel is the ONLY backend** — no logic/DB/auth in Next.js.
- **Prefix tables:** `hr_*` / `crm_*` / `pm_*`. Shared (`users`, `roles`, `permissions`) = no prefix.
- **Namespace permissions:** `hr.*` / `crm.*` / `projects.*`.
- **API:** `/api/v1/{module}/...`, envelope `{ data, meta, links }`, errors `{ message, errors }`.
- **Auth:** one shared Sanctum login + one `users` table.
- **Roles (per-system):** `super_admin`, `hr_officer`, `hr_employee`, `crm_admin`, `crm_bd`,
  `pm_apm`, `pm_member`. Access to a module = holding that module's permissions.
- **Frontend:** use the shared `src/core` api-client + auth; your pages live under `/{teamora|zerem|sprintly}/*`.
- **Migrations for every schema change** (never edit the DB by hand). `php artisan` on **PHP 8.4**.

## ✅ Definition of done (your module)
- Works end-to-end against the shared login + shared DB.
- Tables prefixed; permissions namespaced + seeded.
- Follows the API envelope + conventions.
- Frontend uses the shared design system + api-client.
- Passes review before merging.

**When in doubt → ask the CTO. Don't improvise the architecture.**
