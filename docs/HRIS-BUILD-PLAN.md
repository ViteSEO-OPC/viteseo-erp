---
type: build-plan
title: HRIS — Detailed Build Plan (feature-by-feature: data model, endpoints, logic, pages)
owner: devops@viteseo.ph
pairs_with: HRIS-Step-by-Step-Execution.md
references: [CRM-PM-HRIS - Unified Tech Stack & POD Build Standards.md, POD 1 (HR INFO SYSTEM) (Employee View) Features.md, POD 1 (HR INFO SYSTEM) (HR View) Features.md]
status: living document
---

# HRIS — Detailed Build Plan

> **Purpose:** the bridge between the **feature docs** (the "what/UI") and the **step-by-step runbook** (the "how to build one slice"). The feature docs deliberately don't specify tables, endpoints, or logic — **that's decided here**, following `CRM-PM-HRIS - Unified Tech Stack & POD Build Standards.md`.
>
> **How to use it:** build slices **top to bottom** (Section 5). Each slice is built with the **Phase 7 loop** from `HRIS-Step-by-Step-Execution.md`: backend `migration → model → request → resource → service → controller → route`, then frontend `types → api fn → hook → page`. This doc gives you the *specifics* to plug into that loop for every feature.

---

## 1. Scope tiers

### 🟢 Tier 1 — the MVP (build all of this)
Auth + role portals · App shell · Departments · Employees (+ Profile) · Leave (request + approve + balances) · **Attendance** · **Reimbursement** · **Dashboards (employee + HR)**.

### 🟢 Tier 1 (extended — promoted from Tier 2 on 2026-07-03; see §5.9)
**Payroll & Payslips** (Slice 9) · **Recruitment** (Slice 10) · **Reports** (Slice 11). Build after Slice 8.

### 🔴 Tier 2 — still deferred / mocked
Payroll inquiry + Telegram · Profile change-request approval workflow · Benefits · Global search · Real-time notifications.

> **Phase 2 (2026-07-08) — see §14.** A second pass from the final-revisions brief adds the grouped drop-down sidebar, a Requests hub (Document/Payroll/Concern), a Company config hub (Rules/Holidays/FAQ/Benefits), Office Expenses, expanded Reports, and audit logging — and **promotes several Tier-2 items above** (payroll inquiry, change-request approval, Benefits) into built Phase-2 slices (12–25). Build Phase 1 (Slices 1–11) first.

> For anything in Tier 2 that a screen references (e.g. "Next Payday" card, a payslip list), **show a static/mock version** so the UI looks complete without building the deep logic.

---

## 1.5 Figma design reference (the UI source of truth)

**File:** `https://www.figma.com/design/5lX7DgqGbutmi9QfJryBRg/Pod-1---HRIS` · **fileKey:** `5lX7DgqGbutmi9QfJryBRg` · view access is sufficient.

### Screen map (frame → node ID → what it drives)

| Figma frame | Node ID | Drives (slice / page) |
|---|---|---|
| `hr-Dashboard` | `325:10447` | Slice 8 — HR dashboard (`/hr`) 🟢 hero — **redesigned 2026-07-07 (was `63:4`, now deleted); see the Slice-8 "UI revision" note** |
| `hr-Employees` | `197:6324` | Slice 4 — directory (`/hr/employees`) |
| `hr-Employees-Employee-Personal Info` | `163:535` | Slice 4 — record: Personal tab |
| `hr-Employees-Employee-Job Info` | `166:715` | Slice 4 — record: Job tab |
| `hr-Employees-Employee-Compensation` | `166:1119` | Slice 4 — record: Compensation tab |
| `hr-Employees-Employee-Documents` | `166:1306` | Slice 4 — record: Documents tab (mock list) |
| `hr-Time & Leave` | `170:3279` | Slice 5 — HR leave queue (`/hr/time-leave`) 🟢 hero |
| `hr-Reimbursement` | `170:3656` | Slice 7 — HR claims (`/hr/reimbursement`) |
| `emp-Overview` | `170:4263` | Slices 6+8 — employee home w/ time-clock panel 🟢 hero |
| `emp-Time Off` | `170:4776` | Slice 5 — employee leave (`/time-off`) 🟢 hero |
| `emp-Claims` | `170:4471` | Slice 7 — employee reimbursement (`/claims`) |
| `Pop-ups` | `163:534` | shared modals/dialogs |
| `hr-Payroll` `170:3451` · `hr-Reports` `170:4004` (+detail pages: Headcount & Movement `204:7062`, Attrition & Tenure `204:7370`, Leave & Absence `204:7510`, Payroll & Cost `204:7650`, Recruitment Funnel `204:7790`, Diversity & Pay Equity `204:7930`) · `hr-Recruitment` `76:4` (+modals `170:5088`, `187:5859`) · `hr-Benefits` `170:3844` · `emp-Payslips` `170:4130` · `emp-Benefits` `170:4591` | — | **Tier 2** — build as static/stub pages only if time remains |

**Screens with NO Figma design** (build with clean shadcn defaults + the tokens): the **login page**, a standalone employee **attendance history** page (the design puts time tracking on `emp-Overview` — that's sufficient for MVP), an HR **attendance table** (reuse the DataTable kit), and the employee **Profile** page (reuse the record-tab layout from `163:535`).

### Design system (extracted from the hero frames — verify exact values during token setup)

- **Brand:** "CRIS.io", teal bolt logo. **Dark sidebar** (near-black `#0B1212`-ish) with white text, **teal active state**, red count badges on nav items, user card (avatar + name + role) pinned at the bottom.
- **Primary/accent:** teal/cyan (≈`#2AD4C8`) — active nav, buttons, progress bars, links, highlighted stat card (light-teal bg + teal border).
- **Main area:** very light gray bg; **white cards**, ~12px radius (`rounded-xl`), subtle border, small uppercase gray labels.
- **Status badges:** green = Approved/positive · amber/orange = Pending · red = Rejected/negative (pill-shaped).
- **Time-clock panel** (`emp-Overview`): large **dark teal/navy card** with a digital clock display, status dot, buttons: teal *Time in*, outline *Start lunch/Start break*, red *Time out*; right side = today's stats + this-week mini table.
- **Tables:** uppercase gray column headers, rows with bold title + gray subtitle, right-aligned status pills.
- **Top bar:** white, global search left (placeholder for MVP), bell + red count badge right. (The design's "HR View / Employee View" toggle = our role-based routing; a real toggle is unnecessary.)
- **Charts** (`hr-Dashboard` headcount bars): simple enough to build with **plain flex divs** — no chart library needed (avoids the §0.5 CTO-approval requirement for the MVP).

### Figma fetch protocol (for the AI during the build — keeps token cost low)

1. **Once, at UI-kit time (Slice 2.5):** call `get_variable_defs`/`get_design_context` on ONE small representative frame (or eyedrop the screenshot) to pin exact color/font tokens into `globals.css`.
2. **Per screen, right before building it:** `get_screenshot(nodeId)` (≈1–2k tokens) → build from the screenshot + the token/kit components. Only call `get_design_context` on a frame if the screenshot is ambiguous (it's ~10–50k tokens on big frames — use sparingly, on sub-frames like `main-content` rather than whole screens).
3. **Never bulk-fetch all screens up front.** One frame at a time, in slice order.
4. Translate measurements via the **Figma→Tailwind table** in `HRIS-Frontend-Styling-and-Architecture.md` §5 (spacing = px÷4, Auto Layout ≈ flex, arbitrary values for one-offs).
5. **Re-sync check at build start (1 min):** if the designer changed the file since 2026-07-02, re-pull the frame list (`get_metadata` on page `0:1`, extract top-level frame names+IDs from the saved output with a script — do NOT read the raw dump into context) and diff against the table above: update renamed/recreated IDs, add newly designed screens. Node IDs survive content edits — only deleted/recreated frames get new IDs, and a stale ID fails loudly on fetch (then just re-map it).

---

## 2. Data model (all Tier 1 tables)

Rules from the standard (§6): `hr_` prefix on all HR tables; shared tables (`users`, `roles`) unprefixed; `id` bigint PK; always `created_at`/`updated_at`; `deleted_at` (soft delete) on HR records; money `decimal(12,2)`; FKs named `{singular}_id`; dates stored UTC.

**Core / shared (exist already):** `users` (login identity), `roles`+`permissions` (spatie). Roles: `employee`, `hr_officer`, `admin`.

| Table | Columns (beyond id/timestamps/softDeletes) | Foreign keys |
|---|---|---|
| `hr_departments` | `name`, `code` (unique), `is_active` (bool) | — |
| `hr_employees` | `employee_no` (unique), `job_title`, `employment_status` (string), `hire_date` (date), `phone`, `address`, `emergency_contact_name`, `emergency_contact_phone`, `bank_name`, `bank_account`, `base_salary` decimal(12,2) | `user_id`→users · `department_id`→hr_departments · `manager_id`→hr_employees (nullable, self) |
| `hr_leave_types` | `name`, `code` (unique), `days_per_year` decimal(5,1), `color` | — |
| `hr_leave_balances` | `year` (int), `entitled_days` decimal(5,1), `used_days` decimal(5,1) default 0 | `employee_id`→hr_employees · `leave_type_id`→hr_leave_types |
| `hr_leave_requests` | `start_date`, `end_date`, `is_half_day` (bool), `half_day_period` (string nullable: AM/PM), `days` decimal(5,1), `reason` (text), `status` (string: pending/approved/rejected), `reviewed_at` (nullable) | `employee_id`→hr_employees · `leave_type_id`→hr_leave_types · `reviewed_by`→users (nullable) |
| `hr_attendance` | `work_date` (date — **computed in Asia/Manila**, see §5.5), `time_in` (datetime nullable), `time_out` (datetime nullable), `lunch_started_at` (datetime nullable), `break_started_at` (datetime nullable), `lunch_minutes` (int default 0), `break_minutes` (int default 0), `hours_worked` decimal(5,2) default 0, `status` (string), `note` (nullable) | `employee_id`→hr_employees · unique(`employee_id`,`work_date`) |
| `hr_reimbursements` | `category` (string), `amount` decimal(12,2), `expense_date` (date), `description` (text), `receipt_path` (nullable), `status` (string: pending/approved/rejected/paid), `reviewed_at` (nullable) | `employee_id`→hr_employees · `reviewed_by`→users (nullable) |

**The one relationship to keep in your head:** `users` ↔ `hr_employees` is **1-to-1** via `user_id`. Login/roles live on `users`; all HR data hangs off `hr_employees`. "Is this person HR?" = the `hr_officer` **role**, not a column.

**Phase-E tables (extended slices — full columns in §5.9):** `hr_payroll_runs` + `hr_payslips` (Slice 9, both FK to `hr_employees`; payslip stores computed gross/deductions/net) · `hr_requisitions` + `hr_candidates` (Slice 10; a hired candidate gets `hired_employee_id`→`hr_employees` via `EmployeeService`). Reports (Slice 11) add **no** tables. Same rules apply: `hr_` prefix, soft-deletes, money `decimal(12,2)`.

---

## 3. Endpoint map

Auth is **Core/shared** (unprefixed by module). Everything else is under `/api/v1/hr`. All protected by `auth:sanctum`; HR-only actions also check the `hr_officer` role.

| Resource | Endpoints |
|---|---|
| **Auth (Core)** | `POST /api/v1/auth/login` · `POST /api/v1/auth/logout` · `GET /api/v1/auth/me` |
| **Departments** | `GET/POST /hr/departments` · `PUT/DELETE /hr/departments/{id}` |
| **Employees** | `GET/POST /hr/employees` · `GET/PUT /hr/employees/{id}` · `GET /hr/me/employee` |
| **Leave** | `GET /hr/leave-types` · `GET /hr/me/leave-balances` · `GET/POST /hr/leave-requests` · `POST /hr/leave-requests/{id}/approve` · `POST /hr/leave-requests/{id}/reject` |
| **Attendance** | `GET /hr/me/attendance` · `POST /hr/attendance/clock-in` · `POST /hr/attendance/clock-out` · `POST /hr/attendance/break` · `GET /hr/attendance` (HR) |
| **Reimbursement** | `GET/POST /hr/reimbursements` · `POST /hr/reimbursements/{id}/approve` · `/reject` · `/mark-paid` |
| **Dashboards** | `GET /hr/me/dashboard` (employee) · `GET /hr/dashboard/stats` (HR) |

(`/me/...` = "the logged-in employee's own data." The service resolves `Auth::user()->employee` and scopes to it.)

---

## 4. Build order (each = one Phase-7 vertical slice)

**Phase A — Foundation:** 1) Auth & roles → 2) App shell.
**Phase B — Core entities:** 3) Departments → 4) Employees (+ Profile).
**Phase C — Workflows:** 5) Leave → 6) Attendance → 7) Reimbursement.
**Phase D — Aggregation:** 8) Dashboards (reads of everything above).
**Phase E — Extended (promoted from Tier 2, see §5.9):** 9) Payroll & Payslips → 10) Recruitment → 11) Reports.

Build strictly in this order — each slice depends on the ones before it. (Phase E depends on Employees/Reimbursement/Leave/Attendance being done: payroll reads salary + reimbursements, recruitment hires via `EmployeeService`, reports aggregate everything.)

---

## 5. Detailed per-feature specs

Each slice below lists: **Backend** (tables, model relations, endpoints, validation, logic/automations, permissions) and **Frontend** (routes, components, api/hooks/types, UX rules). Reference the standard for envelope/naming.

---

### Slice 1 — Auth & roles  *(Foundation)*

**Goal:** one login for everyone; the token works across the app; the frontend routes users to the Employee or HR portal based on their role.

**Backend**
- **Controller:** `AuthController` (Core — put in `app/Http/Controllers/AuthController.php`; auth is shared, not HR-specific). Routes go in the base `routes/api.php` under a `/api/v1` prefix.
- **Endpoints & logic:**
  - `POST /auth/login` — validate `email`+`password`; `Auth::attempt`; on success `->createToken('web')->plainTextToken`; return `{ token, user, roles }`.
  - `POST /auth/logout` — `$request->user()->currentAccessToken()->delete()`.
  - `GET /auth/me` — return current user + roles (frontend uses this to choose the portal + gate nav).
- **Seeder:** `RolesAndPermissionsSeeder` (Core) — create roles `employee`, `hr_officer`, `admin`; create permissions `hr.<resource>.<action>` for every resource below; assign HR permissions to `hr_officer`. Also seed one demo HR user + a few employee users for testing.
- **Validation:** `email` required/email; `password` required.
- **Permissions:** none to call login; `me`/`logout` require `auth:sanctum`.

**Frontend**
- **Routes:** `src/app/(auth)/login/page.tsx` (route group `(auth)` = no sidebar). On success: save token to `localStorage` (the Axios interceptor already attaches it), call `/auth/me`, then redirect — `hr_officer` → `/hr`, else → the employee home.
- **Core:** `src/core/auth.ts` (or `use-auth.ts`) — a small hook/context exposing `user`, `roles`, `login()`, `logout()`, `hasRole()`. A `RequireAuth` wrapper redirects to `/login` if no token.
- **Types:** `AuthUser`, `LoginResponse`.
- **UX:** show a toast on bad credentials; disable submit while pending (React Hook Form + Zod).

**Done when:** you can log in as an HR user and an employee user and land in different portals; protected pages bounce to `/login` without a token.

---

### Slice 2 — App shell  *(Foundation)*

**Goal:** the persistent frame (sidebar + top bar + toasts) every page renders inside, with role-based navigation.

**Frontend only** (no backend) — **Figma:** sidebar/topbar visible on every frame; use `hr-Dashboard 325:10447` and `emp-Overview 170:4263` as the reference. **Brand note (2026-07-07):** the redesign renames the shell brand from **"CRIS.io" → "teamora"** (top-left logo, lightning glyph). Update the sidebar brand block accordingly when the shell is revisited.
- **Layouts:** `src/app/hr/layout.tsx` and `src/app/(employee)/layout.tsx`, each wrapping pages in a shared `<AppShell>` with the correct nav set.
- **Components (`src/core/components/` or `src/components/`):**
  - `Sidebar` — **dark** (near-black) panel per the design: brand block ("CRIS.io" + teal logo) top; section label ("WORKSPACE" / "MY SPACE"); nav links with icons, **teal active state**, optional red count badges; **user card pinned at bottom** (avatar, name, role). Nav per design — Employee: Overview, Time Off, Payslips*, Claims, Benefits*. HR: Dashboard, Recruitment*, Employees, Time & Leave, Payroll*, Reimbursement, Benefits*, Reports*. (*= Tier 2 → link to a stub page so the sidebar matches the design without building the feature.)
  - `TopBar` — white bar: search input left (placeholder for MVP), notification bell + red count badge right, logout in the user menu.
  - `Toaster` — shadcn sonner; a `toast()` helper fired on submit/approve/etc.
- **UX:** active-link highlighting; nav chosen by `hasRole()`.

**Done when:** both portals render the dark sidebar + topbar matching the Figma, stub pages exist for Tier-2 nav items, and a test toast fires.

#### 🔄 App Shell — UI revision (2026-07-07, sidebar `325:10624` + toast `163:534`)

> **Scope:** UI-only corrections to already-built shell components — no logic change. Colors reference existing `globals.css` tokens (all values below already exist there). Not yet applied — documentation only.

**1. Sidebar nav-link color — white, not grey.**
- **Current:** inactive nav links render in `--sidebar-foreground` (`#a0aec0`, muted grey).
- **Design (`325:10624`):** inactive nav links are **white**. Switch inactive link text/icon to `--sidebar-accent-foreground` (`#ffffff`).
- **Keep muted** (`#a0aec0`) only for the **"WORKSPACE" section label** and the **user-card role subtitle** ("HR Manager") — those stay grey in the design.

**2. Active nav item — dark-teal pill + cyan accent bar.**
- The active item is a **filled rounded pill** (dark-teal, ≈ `--dark-teal #19414b`) with **white** icon + label, **inset from the sidebar edges (a margin on both sides, incl. the right)** so the pill floats rather than spanning full width.
- A **bright cyan accent bar** (`--brand` / `--sidebar-primary` = `#4fdbd7`) sits on the **LEFT edge** of the pill as the active indicator — **confirmed left, as designed** (user decision 2026-07-07).
- Tokens already present: `--sidebar-primary #4fdbd7`, `--sidebar-primary-foreground #19414b`, `--dark-teal #19414b`, `--sidebar-accent-foreground #ffffff`.
- Red count badges (`Time & Leave` = 2, `Reimbursement` = 2) unchanged — `--danger #f34444`.

**3. Toast — two variants (Approved / Declined), not all-green.** (`Toaster` in §2; components in `Pop-ups 163:534`.)
- Both variants share the **same container**: light-teal fill (`--brand-subtle #e4faf9`) + **teal border** (`--brand #4fdbd7`), rounded, with a grey **×** dismiss on the right. Only the **icon badge** and **title color** change per variant:
  - **Approved:** green rounded-square icon badge (`--success #22c554`) with a white check; title **"Approved"** in `--success`; body in `--muted-foreground` (e.g. "The request was approved and the requester notified.").
  - **Declined:** red rounded-square icon badge (`--danger #f34444`) with a white ×; title **"Declined"** in `--danger`; body in `--muted-foreground` (e.g. "The requester has been notified by your note.").
- **Action:** add a **success/approved** and a **danger/declined** toast variant (map to sonner's success/error or a custom render) so approve fires the green toast and reject/decline fires the red one — replacing the current single green style. Info/neutral actions (clock-in, "Request submitted") can keep a neutral/brand style.

---

### Slice 2.5 — Design tokens + UI kit  *(Foundation — build ONCE, every page reuses)*

**Goal:** pin the design system into code so every later page is fast assembly instead of styling from scratch. **This is the key speed trick for the 8-hour build.**

**Step 1 — tokens (`src/app/globals.css`, Tailwind v4 `@theme`):** extract from Figma (fetch protocol §1.5): brand teal (≈`#2AD4C8`), dark sidebar bg (≈`#0B1212`), dark card navy/teal, page bg light-gray, border gray, status colors (green/amber/red), font family, base radius (`rounded-xl` cards). Define as tokens (`--color-brand`, `--color-sidebar`, …) so ALL components reference tokens, never raw hexes.

**Step 2 — kit components (`src/core/components/` — shared by both portals):**
| Component | What it is (per the Figma) | Used by |
|---|---|---|
| `PageHeader` | h1 title + gray date/subtitle line | every page |
| `StatCard` | white rounded-xl card: uppercase gray label, big bold value, small sub-note; optional `highlight` variant (light-teal bg + teal border) | dashboards, leave balances |
| `DataTable` | shadcn Table styled: uppercase gray headers, bold-title+gray-subtitle cells, right-aligned actions | every list screen |
| `StatusBadge` | pill: `approved/paid`→green, `pending`→amber, `rejected`→red, `in_progress`→blue | every list screen |
| `ProgressBar` | teal fill on light track (leave balance cards) | dashboards |
| `FormDialog` | shadcn Dialog wrapping an RHF+Zod form w/ submit/cancel | every create form |
| `TimeClockPanel` | the dark hero card: status dot + label, digital clock, action buttons (teal/outline/red), today's stats + week mini-table on the right | employee Overview |
| `EmptyState` / `StubPage` | friendly placeholder for Tier-2 nav stubs | payslips, reports, etc. |

**Done when:** a scratch page rendering all kit components side-by-side visually matches the two hero screenshots (colors, radius, badges, dark panel).

---

### Slice 3 — Departments  *(Core entity — the warm-up)*

**Goal:** manage the department list (used as a dropdown when creating employees). This is the exact example in the runbook's Phase 7 — follow it verbatim.

**Backend**
- **Table:** `hr_departments` (see §2). **Model:** `Department` (`$table='hr_departments'`, `$fillable=['name','code','is_active']`, cast `is_active`→bool, `SoftDeletes`).
- **Endpoints:** `apiResource` (index/store/show/update/destroy) at `/hr/departments`.
- **Validation:** `name` required/max:255; `code` required/max:50/unique:hr_departments,code; `is_active` boolean.
- **Permissions:** `hr.departments.view` (all HR) · `.create/.update/.delete` (hr_officer). Read may be open to any authenticated user (employees need the list for dropdowns).

**Frontend** — **Figma:** no dedicated screen; assemble from the kit (`PageHeader` + `DataTable` + `FormDialog`).
- **Route:** `src/app/hr/departments/page.tsx` — table (shadcn) + "Add Department" dialog form.
- **Module files:** `modules/hr/types/department.ts`, `modules/hr/api/departments.ts`, `modules/hr/hooks/use-departments.ts`.

**Done when:** HR can create/list/edit departments; the `curl` + UI both return the standard `{data:[…]}`.

---

### Slice 4 — Employees (+ employee Profile)  *(Core entity — the backbone)*

**Goal:** HR manages employee records; each employee can view their own profile. Most later features attach to `hr_employees`.

**Backend**
- **Table:** `hr_employees` (see §2). **Model `Employee`:** relations — `belongsTo(User)`, `belongsTo(Department)`, `belongsTo(Employee,'manager_id')` (manager), `hasMany(LeaveRequest)`, `hasMany(Attendance)`, `hasMany(Reimbursement)`. Add a helper on `User`: `employee()` = `hasOne(Employee)`.
- **Endpoints:** `GET/POST /hr/employees`, `GET/PUT /hr/employees/{id}` (HR); `GET /hr/me/employee` (self).
- **Validation (StoreEmployeeRequest):** `name`+`email` (for the user account), `department_id` exists, `job_title` required, `hire_date` date, `base_salary` numeric/min:0, `employment_status` in a fixed list.
- **Service logic / automations (the important part):** `EmployeeService::create()` in a DB transaction: (1) create a `User` (name/email, a default/random password, assign the `employee` role); (2) create the `hr_employees` row linked to that user; (3) create `hr_leave_balances` rows for the current year for every `hr_leave_type` (so leave works immediately). This is the "one shared users table" rule in action — an employee **is** a user plus an HR record.
- **Permissions:** `hr.employees.view/create/update`. `/me/employee` only needs auth (returns the caller's own record).

**Frontend** — **Figma:** directory `197:6324`; record tabs `163:535` (Personal), `166:715` (Job), `166:1119` (Compensation), `166:1306` (Documents).
- **HR routes:** `src/app/hr/employees/page.tsx` (directory table: Name, Department, Job Title, Status, Manager + search/filter) and `src/app/hr/employees/[id]/page.tsx` (record with sub-tabs **Personal / Job / Compensation / Documents**; Documents = mock/list for MVP).
- **Employee route:** `src/app/(employee)/profile/page.tsx` — reads `/hr/me/employee`; **MVP: view-only** (the "change request approval" workflow is Tier 2 — for MVP either read-only or edits save directly).
- **Module files:** `types/employee.ts`, `api/employees.ts`, `hooks/use-employees.ts` + `use-my-employee.ts`, `components/EmployeeTable.tsx`, `EmployeeForm.tsx`, `EmployeeRecordTabs.tsx`.

**Done when:** HR creates an employee (which also creates their login + leave balances), sees them in the directory, opens their record; that employee logs in and sees their own profile.

---

### Slice 5 — Leave  *(Hero workflow)*

**Goal:** employee files leave with a **live balance check**; HR approves/declines; approval **auto-updates the balance**.

**Backend**
- **Tables:** `hr_leave_types` (seeded: Annual, Sick, Emergency, Bereavement, SIL with `days_per_year` + colors), `hr_leave_balances`, `hr_leave_requests`. **Models** with relations to `Employee`/`LeaveType`.
- **Seeder:** `LeaveTypesSeeder`.
- **Endpoints:** `GET /hr/leave-types` · `GET /hr/me/leave-balances` · `GET /hr/leave-requests` (HR: all; employee: own — service scopes by role) · `POST /hr/leave-requests` (employee files) · `POST /hr/leave-requests/{id}/approve` · `/reject` (HR).
- **Validation (StoreLeaveRequest):** `leave_type_id` exists; `start_date`/`end_date` dates, end ≥ start; `is_half_day` boolean; `reason` required. **Server-side balance rule:** compute `days` from the range (half-day = 0.5); reject with `422` if `days` > remaining balance (`entitled_days - used_days`). *Never trust the frontend's check.*
- **Service logic / automations:**
  - `create()` — compute `days`, verify balance, save as `pending`.
  - `approve()` — in a transaction: set `status=approved`, `reviewed_by`, `reviewed_at`, and **increment `used_days`** on the matching balance row.
  - `reject()` — set `status=rejected` (+ reviewer fields); balance untouched.
- **Permissions:** `hr.leave_requests.view/create` (employee can create + view own) · `hr.leave_requests.approve` (hr_officer).

**Frontend** — **Figma:** employee `emp-Time Off 170:4776` 🟢 hero; HR queue `hr-Time & Leave 170:3279` 🟢 hero; request modal in `Pop-ups 163:534`.
- **Employee route:** `src/app/(employee)/time-off/page.tsx` — the leave form (type dropdown, date range, half-day toggle, reason) + **live balance check** (fetch `/me/leave-balances`, compute requested days as the user picks dates, disable submit + warn if over) + personal request history table.
- **HR route:** `src/app/hr/time-leave/page.tsx` — requests queue table with Approve/Decline buttons + a detail modal (employee, type, dates, days, reason, balance, status).
- **Module files:** `types/leave.ts`, `api/leave.ts`, `hooks/use-leave-types.ts` + `use-leave-balances.ts` + `use-leave-requests.ts` (+ approve/reject mutations that **invalidate** the balances + requests queries so the UI refreshes automatically).
- **UX:** toast on submit ("Request submitted") and on approve/decline; the employee's balance card updates after HR approves (thanks to query invalidation).

**Done when:** employee files leave (blocked if over balance), it appears in HR's queue, HR approves, and the employee's remaining balance drops — end to end.

---

### Slice 6 — Attendance

**Goal:** employees clock in/out (with lunch/break); HR sees an attendance table.

**Backend**
- **Table:** `hr_attendance` (unique per employee per `work_date`).
- **Endpoints:** `POST /hr/attendance/clock-in` · `POST /hr/attendance/clock-out` · `POST /hr/attendance/break` (start/end lunch or break) · `GET /hr/me/attendance` (employee history) · `GET /hr/attendance` (HR, all employees; filterable by date).
- **Service logic:** `clockIn()` — resolve **today in Asia/Manila** → create/find that `work_date` row, set `time_in` (reject if already set); `status` = `late` if past the configured start (default 09:00 Manila), else `present`. `break('lunch'|'break')` — toggle: if `*_started_at` is null set it (started); else add `now - *_started_at` to the minutes counter and null it (ended). `clockOut()` — reject if a lunch/break is still open (or auto-close it), set `time_out`, compute `hours_worked = (time_out − time_in − lunch − break)` in hours. `GET /hr/me/attendance?today=1` returns today's row **plus a derived `state`** (`clocked_out` / `clocked_in` / `on_lunch` / `on_break`) so the panel knows which buttons to show.
- **Permissions:** `hr.attendance.view` (HR all; employee own). Clock actions: employee on own record only.

**Frontend** — **Figma:** the `TimeClockPanel` on `emp-Overview 170:4263` 🟢 hero. No standalone attendance screens in the design — the panel IS the employee attendance UI for MVP; the HR table assembles from the kit.
- **Employee:** the **`TimeClockPanel`** (kit component) on the home dashboard (Slice 8) — live clock, status dot, Time In / Start Lunch / Start Break / Time Out buttons, today's summary (in/out/hours/lunch/break), and the week mini-table. A separate `/attendance` history page is optional (kit `DataTable`; calendar view = nice-to-have only).
- **HR:** `src/app/hr/attendance/page.tsx` — kit `DataTable` + severity color tags (green/yellow/orange/red) for tardiness/absence.
- **Module files:** `types/attendance.ts`, `api/attendance.ts`, `hooks/use-attendance.ts` + clock mutations.

**Done when:** an employee can clock in and out and see today's hours; HR sees the day's attendance rows.

---

### Slice 7 — Reimbursement

**Goal:** employee files an expense claim (with receipt); HR approves/rejects/marks paid.

**Backend**
- **Table:** `hr_reimbursements`.
- **Endpoints:** `GET/POST /hr/reimbursements` (employee files + sees own; HR sees all) · `POST /hr/reimbursements/{id}/approve` · `/reject` · `/mark-paid`.
- **Validation:** `category` in a fixed list; `amount` numeric/min:0 (PHP); `expense_date` date; `description` required; `receipt` file (image/pdf, max size) — store via Laravel `Storage`, save the path in `receipt_path`. (If time is tight, accept a filename string and defer real uploads.)
- **Service logic:** status transitions `pending → approved → paid` or `pending → rejected` (+ reviewer fields). ("Rolls into next payroll" is Tier 2 — for MVP `mark-paid` is a manual status flip.)
- **Permissions:** `hr.reimbursements.view/create` (employee own) · `.approve` (hr_officer).

**Frontend** — **Figma:** employee `emp-Claims 170:4471`; HR `hr-Reimbursement 170:3656`.
- **Employee:** `src/app/(employee)/claims/page.tsx` — claim form (category, amount, date, description, receipt upload) + "My Claims" list with status tags.
- **HR:** `src/app/hr/reimbursement/page.tsx` — claims table with Approve / Reject / Mark Paid + detail view (description, receipt, notes).
- **Module files:** `types/reimbursement.ts`, `api/reimbursements.ts`, `hooks/use-reimbursements.ts` + action mutations.

**Done when:** employee files a claim, HR approves and marks it paid, and both sides' lists reflect the status.

---

### Slice 8 — Dashboards  *(Aggregation — reads of everything above)*

**Goal:** the two home screens. Mostly aggregate reads; little new logic.

**Backend**
- **Endpoints:** `GET /hr/me/dashboard` (employee: today's attendance summary, leave balances, recent requests; "next payday" = **static/mock** since payroll is Tier 2) · `GET /hr/dashboard/stats` (HR: `headcount` = employee count, `on_leave_today` = approved leave covering today, `open_roles` = 0/mock, `next_payroll_date` = static; `pending_approvals` = pending leave + reimbursements; `recent_activity` = latest status changes).
- **Service:** simple aggregate queries. No new tables.
- **Permissions:** employee dashboard = auth; HR stats = `hr_officer`.

**Frontend** — **Figma:** `emp-Overview 170:4263` 🟢 hero; `hr-Dashboard 325:10447` 🟢 hero.
- **Employee home:** `src/app/(employee)/page.tsx` — greeting ("Good morning, {name} 👋" + date), the **`TimeClockPanel`** (Slice 6), leave balance `StatCard`s with `ProgressBar` (Slice 5), Next Payday card (mock "Jul 15"), My Requests `DataTable` with `StatusBadge`s.
- **HR dashboard:** `src/app/hr/page.tsx` — **see the "UI revision (2026-07-07)" note below; the description here is the ORIGINAL `63:4` layout, now superseded.** ~~four `StatCard`s (first one `highlight` variant per design), the headcount/attrition bar chart built with plain flex divs, pending-approvals list with inline ✓/✗, recent-activity feed.~~
- **Module files:** `types/dashboard.ts`, `api/dashboard.ts`, `hooks/use-dashboard.ts`.

**Done when:** both dashboards render real numbers from the data created in Slices 4–7 (with clearly-mocked payroll bits).

#### 🔄 HR Dashboard — UI revision (2026-07-07, `hr-Dashboard 325:10447`)

> **Scope:** UI/layout revision only — the user's instruction is **"no logic change, UI only."** No backend/endpoint/schema changes are approved in this note. Where the new design shows data the current `GET /hr/dashboard/stats` does **not** provide, the ⚠️ flags below record the gap for a later decision (mock for now, or open a follow-up backend slice) — **do not silently invent endpoints.** Not yet applied — documentation only.

The redesigned frame retitles the page **"Employee Check-in Dashboard"** and splits it into two stacked sections:

**A) Top — "Employee Check-in Dashboard" (attendance-centric, NEW):**
- **Header row:** title + a **date picker** ("Mon, July 6, 2026"), and top-right **"Grace period"** and **"Refresh"** buttons.
- **8 stat tiles** in a 4×2 grid, pastel-tinted by state (reuse `StatCard` with color variants): `Checked in` (13, green) · `Not checked in` (2, red) · `On leave` (0, blue) · `Early arrivals` (1, green) · `Late arrivals` (5, amber) · `On time` (7, blue) · `Total employees` (15, gray) · `Absent` (1, pink).
- **"LEAVE TYPES" legend:** a row of colored pill chips — Vacation, Sick, Emergency, Maternity/paternity, Bereavement, Unpaid, Official business.
- **Filter bar:** "Search employee" input + "All status" dropdown + "On time" dropdown.
- **Employee check-in table** — columns: **EMPLOYEE** (avatar + name), **DEPT**, **STATUS** (`Checked in` / `On leave - Sick` badge), **FIRST IN** (e.g. 08:05), **LAST OUT**, **TIMING** (`On time` / `Late 1:40s` badge). Sample rows: Maya Villanueva · Trisha Alonzo · Paolo Okafor (on leave) · Elena Reyes (late).
  - ⚠️ **Data gap:** this per-employee, per-day check-in roster (first-in / last-out / on-time vs late / grace period) is **not** in the current dashboard endpoint. It overlaps the `hr-Attendance 242:1252` frame (also newly designed). Decide: (a) mock it for the UI pass, or (b) later back it with an attendance-roster read. **Not building either now.**
- ⚠️ **"Grace period"** button implies a configurable lateness threshold — no such setting exists in the backend today. Flag only.

**B) Bottom — "Summary & Analytics" (this is essentially the ORIGINAL dashboard, relocated):**
- **Four `StatCard`s:** `HEADCOUNT` (15) · `ON LEAVE TODAY` (0) · `OPEN ROLES` (3) · `NEXT PAYROLL` (Jul 15, "in 9 days"). → maps to the existing `GET /hr/dashboard/stats` fields (`headcount`, `on_leave_today`, `open_roles`, `next_payroll_date`). No logic change.
- **Line chart** "Attendance and punctuality — last 30 days" with two series: **On-time rate** (teal) and **Late rate** (red), endpoints 54% / 38%, x-axis Jun 8 → Today.
  - ⚠️ **Chart-type change:** the old spec was a *bar* chart (headcount/attrition) doable with plain flex divs. This is a **two-series line/trend chart** — plain flex divs won't render it cleanly. Options: (a) inline SVG polyline (no dependency, no §0.5 approval), or (b) a chart lib (needs §0.5 CTO approval). Recommend **inline SVG** for the UI pass. Data can be mock (matches the old "mock 9-month history" allowance).
- **Pending approvals** card: rows with a type tag (`Leave - VL`, `Time fix`, `Expense`), name + detail, "Pending {n}h/d", and **Approve / Decline** buttons. → extends the existing `pending_approvals` (leave + reimbursement); the **"Time fix"** type is new (attendance-correction request) and has **no backend** — ⚠️ mock or flag.
- **Recent activity** feed: icon + text + timestamp (e.g. "Marco Tan started in Ops", "HR approved sick leave for Dennis Uy", "Check-in corrected for Diana Lee"). → maps to existing `recent_activity`.

**Net for the UI-only pass (when approved to build):** re-layout `src/app/hr/page.tsx` into the two sections; the **B) Summary & Analytics** block is a near-direct reuse of the current dashboard data (only the chart becomes inline-SVG). The **A) Check-in** block is mostly **new UI on mock data** until a decision is made on backing it — nothing in section A is wired to a real endpoint in this pass.

---

## 5.9 Extended slices — Tier-2 features promoted to Tier-1 (added 2026-07-03)

> Payslips, Run Payroll, Reports, and Recruitment were **promoted from Tier 2 to Tier 1** on build day. They follow the same Phase-7 loop and standards as Slices 1–8. Build them **after** Slice 8. Two new libraries are proposed below (PDF generation, optional drag-and-drop) — each has a **no-dependency MVP fallback** so nothing blocks on §0.5 CTO approval.

---

### Slice 9 — Payroll & Payslips  *(Extended — the "wow" engine)*

**Goal:** HR runs payroll for a cycle; the system computes gross → PH statutory deductions → net for every employee and generates itemized payslips; each employee views/downloads their payslip. Approved reimbursements roll into the run.

**Figma:** HR `hr-Payroll 170:3451`; employee `emp-Payslips 170:4130`.

**Backend**
- **Tables:**
  - `hr_payroll_runs` — `cycle_start` (date), `cycle_end` (date), `frequency` (string, default `semi_monthly`), `status` (`processing`/`completed`), `total_gross`/`total_deductions`/`total_net` decimal(14,2), `employee_count` (int), `run_at` (datetime nullable), `run_by`→users (nullable). softDeletes.
  - `hr_payslips` — `payroll_run_id`→hr_payroll_runs, `employee_id`→hr_employees, `base_salary`, `reimbursements`, `gross`, `sss`, `philhealth`, `pagibig`, `withholding_tax`, `total_deductions`, `net_pay` (all decimal(12,2)), `status` (default `paid`). `unique(payroll_run_id, employee_id)`. softDeletes.
- **Models:** `PayrollRun` (hasMany payslips), `Payslip` (belongsTo run, employee); `Employee::payslips()`.
- **`PayrollService`** — the important logic:
  - **PH deduction formulas (SIMPLIFIED, monthly-equivalent — document as approximate, NOT exact BIR/SSS tables):**
    - `sss = round(min(base, 30000) * 0.045, 2)`
    - `philhealth = round(clamp(base, 10000, 100000) * 0.025, 2)`
    - `pagibig = round(min(base * 0.02, 200), 2)`
    - `taxable = gross − (sss + philhealth + pagibig)`
    - `withholding_tax` = TRAIN monthly graduated table: ≤20,833→0; ≤33,332→20%×(t−20,833); ≤66,666→2,500+25%×(t−33,333); ≤166,666→10,833.33+30%×(t−66,667); ≤666,666→40,833.33+32%×(t−166,667); else 200,833.33+35%×(t−666,667).
  - `run(cycleStart, cycleEnd, reviewer)` — DB **transaction**: create run (`processing`); for each **active** employee → `base = base_salary`; `reimb = Σ approved reimbursements`; `gross = base + reimb`; compute deductions; `net = gross − total_deductions`; create payslip (`paid`); **mark those reimbursements `paid`** (the reimbursement→payroll automation). Accumulate totals; set run `completed` + totals + `run_at`/`run_by`. **Guard:** block a second completed run for the same cycle.
  - reads: `listRuns()`, `runWithBreakdown(run)`, `myPayslips(employee)`, `payslip(employee, id)` (ownership), `ytd(employee, year)` (Σ payslips this year).
- **Endpoints:** `GET /hr/payroll/runs` · `POST /hr/payroll/run` · `GET /hr/payroll/runs/{run}` (HR); `GET /hr/me/payslips` · `GET /hr/me/payslips/{payslip}` (employee, own) · `GET /hr/me/payslips/{payslip}/pdf`.
- **Validation:** `cycle_start`/`cycle_end` dates, end ≥ start.
- **Permissions:** `hr.payroll.run`/`.view` (hr_officer); `hr.payslips.view` (employee, own).
- **PDF:** MVP = a **print-styled HTML payslip route** (`window.print()`, no dep); production upgrade = **DomPDF** (`barryvdh/laravel-dompdf`) — needs §0.5 approval.

**Frontend**
- **HR:** `src/app/hr/payroll/page.tsx` — cycle header, **"Run payroll for this cycle"** button (confirm dialog), amber automation banner, 4 `StatCard`s (total gross / deductions / net / status), **Payroll Breakdown** `DataTable` (employee, dept, gross, deductions [red], net, Paid badge). Run → invalidate → cards + table fill.
- **Employee:** `src/app/(employee)/payslips/page.tsx` — **History** list (left) + selected **payslip detail** (right): EARNINGS (base + reimbursement), DEDUCTIONS (SSS/PhilHealth/Pag-IBIG/WHT, red), **Net pay** (teal), YTD box, **Download PDF**.
- **Module files:** `types/payroll.ts`, `api/payroll.ts`, `hooks/use-payroll.ts` (+ run mutation) & `use-payslips.ts`.
- **UX:** toast on run; the run mutation **invalidates** payroll runs + reimbursements (claims flip to Paid) + the employee payslips query.

**Seeder:** `PayrollDemoSeeder` — 2–3 completed runs for prior cycles so history/payslips exist (Maya has payslips per the design).

**Done when:** HR clicks Run payroll → breakdown fills with gross/deductions/net per employee and approved reimbursements flip to **Paid**; the employee opens **Payslips** → sees history + an itemized payslip + can download/print.

---

### Slice 10 — Recruitment  *(Extended — requisitions + candidate kanban)*

**Goal:** HR posts requisitions and moves candidates through a hiring pipeline; **marking a candidate Hired auto-creates a draft employee record** (the handoff into Slice 4).

**Figma:** `hr-Recruitment 76:4`; modals `170:5088`, `187:5859`.

**Backend**
- **Tables:**
  - `hr_requisitions` — `title`, `department_id`→hr_departments, `hiring_manager_id`→hr_employees (nullable), `openings` (int default 1), `stage` (`draft`/`active`/`offer_out`/`closed`), `opened_at` (date). softDeletes.
  - `hr_candidates` — `requisition_id`→hr_requisitions, `name`, `email` (nullable), `source` (string: referral/linkedin/jobboard/direct/agency), `experience_years` (int nullable), `stage` (`applied`/`screening`/`interview`/`offer`/`hired`/`rejected`), `rating` decimal(2,1) nullable, `notes` (text nullable), `hired_employee_id`→hr_employees (nullable). softDeletes.
- **Models:** `Requisition` (hasMany candidates; belongsTo department, manager), `Candidate` (belongsTo requisition).
- **`RecruitmentService`:** requisition + candidate CRUD; `moveStage(candidate, stage)`; **`hire(candidate)`** — DB transaction: create employee via **`EmployeeService::create`** (name/email from candidate, department + job_title from the requisition, `employment_status='probationary'`, `hire_date=today`, base_salary 0), set candidate `stage='hired'` + `hired_employee_id`, decrement/close the requisition when filled.
- **Endpoints:** `GET/POST /hr/requisitions` · `GET/PUT /hr/requisitions/{req}` · `GET /hr/candidates?requisition=` · `POST /hr/candidates` · `POST /hr/candidates/{cand}/move` · `POST /hr/candidates/{cand}/hire`.
- **Permissions:** `hr.recruitment.view`/`.manage` (hr_officer).

**Frontend** — **Figma:** `hr-Recruitment 76:4`.
- **Route:** `src/app/hr/recruitment/page.tsx` — header (open-reqs + pipeline counts), teal **handoff banner**, **Open Requisitions** `DataTable` (role, dept, hiring mgr, openings, stage badge, opened), **Pipeline kanban** for the selected requisition (5 columns applied→hired) with candidate cards (avatar, name, exp, source, stage tags, rating), **New Requisition** + **Add Candidate** dialogs, **"Mark hired"** on offer-stage cards.
- **Kanban MVP:** move a candidate via a stage `<select>` or ◀ ▶ buttons on each card (no DnD lib). Optional upgrade: `@dnd-kit` (needs §0.5 approval).
- **Module files:** `types/recruitment.ts`, `api/recruitment.ts`, `hooks/use-recruitment.ts` (+ move/hire mutations invalidating candidates **and** employees).
- **UX:** "Mark hired" → toast "Draft employee created"; the new hire appears in the **Employees** directory.

**Seeder:** `RecruitmentDemoSeeder` — ~4 requisitions (mixed stages) + ~10 candidates across stages for the first requisition (Applied 3 / Screening 2 / Interview 2 / Offer 1, per the design).

**Done when:** HR creates a requisition, adds candidates, moves them across the kanban; marking an **Offer** candidate **Hired** creates a draft employee visible in the Employees directory.

---

### Slice 11 — Reports  *(Extended — 6 analytics)*

**Goal:** an analytics hub — 6 report cards, each opening a detail page. Real aggregates where the data exists; clearly-labelled mock where it doesn't.

**Figma:** index `hr-Reports 170:4004`; detail pages `204:7062` (Headcount & Movement), `204:7370` (Attrition & Tenure), `204:7510` (Leave & Absence), `204:7650` (Payroll & Cost), `204:7790` (Recruitment Funnel), `204:7930` (Diversity & Pay Equity).

**Backend**
- **No new tables** — aggregate reads over existing data. `ReportService` with one method per report returning a small JSON (series + summary). **Endpoint:** `GET /hr/reports/{key}`, `key ∈ {headcount, attrition, leave, payroll, recruitment, diversity}`.
  - `headcount` **(real)** — count by department; joiners by `hire_date` month (last 9); total headcount.
  - `attrition` **(partial)** — tenure buckets from `hire_date`; attrition rate **mock** (no termination data).
  - `leave` **(real)** — utilization (`used/entitled` by type); absences from attendance status.
  - `payroll` **(real)** — from `hr_payroll_runs`: gross/net/deductions per run, cost-per-head, run history.
  - `recruitment` **(real)** — candidate counts by stage (funnel); open requisitions.
  - `diversity` **(mock)** — representation / pay-gap placeholder (no demographic data in schema).
- **Permissions:** `hr.reports.view` (hr_officer).

**Frontend** — **Figma:** index `170:4004` + detail nodes above.
- **Route:** `src/app/hr/reports/page.tsx` — 6-card grid (icon, title, description) linking to detail; **"Custom report"** = stub.
- **Detail:** `src/app/hr/reports/[key]/page.tsx` — summary `StatCard`s + **plain-flex-div charts** (per §1.5, no chart lib) + tables. Label mock sections explicitly.
- **Module files:** `types/reports.ts`, `api/reports.ts`, `hooks/use-report.ts`.

**Done when:** the Reports index shows 6 cards; each detail renders (real numbers where data exists, clearly-labelled mock for the diversity/attrition gaps).

---

## 5.5 Deep mechanics — how it all connects (cross-slice rules the AI must follow)

These are the implicit decisions that produce silent bugs if left to guesswork. They override any ambiguity above.

### (a) One end-to-end trace: what happens when Maya presses "Time in"
This trace is the template for understanding EVERY action in the app:
1. **Button** (`TimeClockPanel`) calls the TanStack **mutation** → the api fn → `apiClient.post("/hr/attendance/clock-in")`. Axios attaches her Bearer token automatically (interceptor).
2. **Laravel:** `auth:sanctum` resolves the token → `$request->user()` = Maya's `users` row → service resolves `$user->employee` (her `hr_employees` row via `user_id`). **The frontend never sends employee_id — the server derives identity from the token.** (Rule: any "my X" endpoint works this way; never trust a client-sent ID for ownership.)
3. Service computes today's date **in Asia/Manila**, upserts her `hr_attendance` row for that `work_date`, sets `time_in = now()` (stored UTC), sets `status`.
4. Response returns the updated row (Resource, `{data: ...}` envelope).
5. **Frontend:** the mutation's `onSuccess` fires `toast("Clocked in")` and **invalidates** the `["hr","attendance","today"]` query → TanStack refetches → the panel re-renders: dot turns green, "Time out" appears, today's IN time fills in. **No manual state juggling — invalidation IS the update mechanism.** (Rule: every mutation lists the query keys it invalidates; that's how approving leave updates the balance card, how filing a claim appears in HR's queue, etc.)

### (b) Time & timezone (the classic silent bug)
- **Store** all datetimes in UTC (standard). **Compute day-boundaries and display in `Asia/Manila`** — 7:00 AM Manila is 23:00 UTC *yesterday*, so `work_date`, "today's row", lateness, and leave dates MUST be derived with `now('Asia/Manila')`, never from raw UTC. Add `config('app.timezone_local') = 'Asia/Manila'` (or a constant) and use it in services.
- The **live ticking clock** in `TimeClockPanel` is client-side (`setInterval`, 1s). "Hours so far while clocked in" = `now − time_in` computed client-side; the authoritative `hours_worked` is computed server-side at clock-out.

### (c) Leave-days math (define it, don't let the AI guess)
`days` = count of **weekdays (Mon–Fri)** between `start_date` and `end_date` **inclusive**; half-day = 0.5 (requires start=end). Same-day request = 1.0. Weekends don't consume balance. (Holidays are Tier 2 — ignore for MVP.) The **frontend preview** of days uses the same rule for the live check, but the **server recomputes and enforces** it — the 422 from the server is the real gate.

### (d) Auth/session mechanics
- `GET /auth/me` returns `{ user: {id,name,email}, roles: [...], employee: {id, first_name, job_title, department} }` — one call gives the greeting, role routing, and employee context. Frontend caches it in the auth hook.
- **On app load/refresh:** if a token exists in localStorage, fetch `/me` before rendering portal chrome; show a tiny loading state (prevents flashing the wrong portal). No token or `/me` fails → redirect `/login`.
- **Global 401 handling:** add an Axios **response** interceptor — any 401 clears the token and redirects to `/login` (expired/revoked token). One place, covers every screen.
- **Backend route protection:** HR-only route groups get spatie middleware (`role:hr_officer`) *in addition to* `auth:sanctum`. UI hiding is cosmetic; the middleware is the security.

### (e) Plumbing gotchas (each costs 20 confused minutes if forgotten)
- **CORS:** first browser login will fail if `config/cors.php` doesn't allow `http://localhost:3000`. Verify during Slice 1 (`install:api` usually handles it; check `supports_credentials` not needed for Bearer tokens).
- **Receipt uploads:** send as `multipart/form-data`; run `php artisan storage:link` once so stored receipts are viewable; validate `mimes:jpg,jpeg,png,pdf|max:5120`.
- **Reset command during the build:** `php artisan migrate:fresh --seed` = wipe + rebuild + reseed. Use it freely before the demo; never in production.
- **Peso display:** format client-side with `Intl.NumberFormat('en-PH', {style:'currency', currency:'PHP'})`; API sends raw decimals.
- **Pagination:** backend paginates (envelope `meta`/`links`); MVP frontend may simply render page 1 (seed data is small) — a "load more" is optional polish.
- **Eager-load relations** in list endpoints (`with(['employee.user','leaveType'])`) so tables show names without N+1 queries.

---

## 6. Deferred / mocked (Tier 2) — how to fake them so the UI looks complete

| Feature | MVP mock |
|---|---|
| ~~Payslips / Run Payroll~~ | **Now built — Slice 9 (§5.9).** Real PH deductions (simplified), payslip PDF via print. |
| ~~Reports (6)~~ | **Now built — Slice 11 (§5.9).** Real aggregates where data exists; diversity/attrition labelled mock. |
| ~~Recruitment (kanban)~~ | **Now built — Slice 10 (§5.9).** Requisitions + candidate kanban + hire→draft-employee handoff. |
| Next Payday card (employee dashboard) | Still a hard-coded date on the Slice-8 home (until a run exists to read). |
| Payroll inquiry / Telegram | **Built in Phase 2 — Slice 20 (§14.7)** as an in-app queue (Telegram still out of scope). |
| Profile change-request approval | **Built in Phase 2 — Slice 19 (§14.7)** (change-requests queue with Old/New/approve→write). |
| Benefits / documents / global search / live notifications | Static/placeholder. |

---

## 7. Standards checklist (apply to every slice)

- Tables `hr_*`, plural, snake_case; shared tables unprefixed. FKs `{singular}_id`.
- API under `/api/v1/hr/<plural>`; standard envelope `{ "data": ... }` + `meta`/`links` for lists; errors as `{ message, errors }`.
- Validation in **Form Requests**; logic in **Services**; controllers **thin**; JSON via **Resources**.
- Permissions named `hr.<resource>.<action>`; protect routes with `auth:sanctum` + role/permission checks.
- Money `decimal(12,2)`; dates ISO 8601 UTC; soft-delete HR records.
- Frontend: shared Axios client + TanStack Query + shadcn/Tailwind; **no business logic in Next.js**; new libs (e.g. a chart lib) need CTO approval (§0.5).
- Never read another module's tables; never build a second users table.

---

## 8. Demo data seeder (a demo with 2 empty tables looks dead — this makes it look alive)

One `DemoDataSeeder` (plus the roles/permissions + leave-types seeders), runnable via `php artisan db:seed`. Grow it as slices land — each slice's "done" includes seeding its demo rows.

- **Fixed logins (memorize these):** `hr@viteseo.ph` / `password` (role `hr_officer`, employee record "Dana Reyes — HR Manager") and `employee@viteseo.ph` / `password` (role `employee`, record "Maya Villanueva — Product Manager" — matches the Figma).
- **5 departments:** Engineering, Product, HR, Sales, Finance.
- **~10 employees** across departments with realistic PH names, job titles, hire dates, salaries; each = user + employee row + current-year leave balances (auto via `EmployeeService::create`).
- **Leave:** balances partially used (e.g. Maya: Annual 3/15 used, Sick 1/10 — matches the Figma cards); ~6 leave requests in mixed states — **at least 2 pending** (so the HR queue + dashboard approvals list have live rows to approve during the demo).
- **Attendance:** the current week for all employees (varied clock-ins, one `late`), so the HR table and weekly mini-table have data. Leave "today" for the demo employee **un-clocked** (so you can clock in live).
- **Reimbursements:** ~5 in mixed states (pending "₱2,400 — Client meeting meals" for Maya per the Figma, one approved, one paid, one rejected).
- **Mock constants** (payroll deferred): next payday "Jul 15", "Semi-monthly · BPI •••• 4471", HR stat "Next Payroll Jul 15".

---

## 9. Demo script (build to THIS path; polish exactly these screens)

1. **Login as HR** → dashboard: live stat cards, chart, **pending approvals with real rows**, activity feed. *(hero)*
2. **Employees** → directory → open a record, click through Personal/Job/Compensation tabs.
3. **Logout → login as employee (Maya)** → Overview: greeting, **clock in live** (status flips, toast), balances, My Requests. *(hero)*
4. **Time Off** → file a leave request; show the **live balance check** (try to over-request → blocked; then valid → submitted + toast). *(hero)*
5. **Claims** → file a reimbursement with a receipt.
6. **Re-login as HR** → Time & Leave queue: the new request is there → open modal → **Approve** (toast). *(hero)*
7. **Reimbursement** → approve → mark paid.
8. **Back as employee** → balance decremented, request shows *Approved*, claim shows *Paid*. **The full headless round-trip, live.**

Anything NOT on this path gets functional-but-plain treatment. Pixel-polish only the 🟢 hero screens.

---

## 10. Slice sizing & sequencing (relative effort over the ~1-week build)

> **Note (2026-07-07):** the timeline is now **~1 week for a full, production-quality HRIS (≥90% complete)**, not a single <8h sitting. The per-slice figures below are kept as **relative effort/sequencing guidance** — they tell you which slices are heavy (Payroll) and which are light (Departments) and in what order to build, **not** a countdown clock. With the freed time, invest in what the hackathon framing cut: validation, edge-cases, error states, ownership/auth checks, data-integrity guards, and the `docs/SECURITY-AUDIT.md` fixes. The "cut line" below is now a **fallback for genuine time pressure**, not the default discipline.

| # | Work | Budget | Running |
|---|---|---|---|
| 1 | Auth & roles (backend + login page + portal routing) | 45m | 0:45 |
| 2+2.5 | App shell + tokens + UI kit (fetch Figma tokens/screens here) | 75m | 2:00 |
| 3 | Departments (warm-up slice) | 20m | 2:20 |
| 4 | Employees + profile (+ seeder foundation) | 60m | 3:20 |
| 5 | Leave end-to-end 🟢 | 75m | 4:35 |
| 6 | Attendance (clock panel + HR table) | 45m | 5:20 |
| 7 | Reimbursement | 45m | 6:05 |
| 8 | Dashboards (both) 🟢 | 60m | 7:05 |
| — | Seeder top-up, demo dry-run, fixes | 40m | 7:45 |

**Extended slices (promoted from Tier 2 on 2026-07-03 — §5.9), added after the ~7:45 core:**

| # | Work | Budget | Running |
|---|---|---|---|
| 9 | Payroll & Payslips (run engine + PH deductions + payslip/PDF) | 90m | 9:15 |
| 10 | Recruitment (requisitions + candidate kanban + hire handoff) | 90m | 10:45 |
| 11 | Reports (6 analytics: index + detail pages) | 75m | 12:00 |

> **Reality check:** the extended slices roughly **double** the build (~7:45 → ~12:00). They depend on Slices 4–8, so keep the original order — finish the core demo path first, then add Phase E. Payroll is the heaviest (the deduction engine); Reports is the most paddable (some panels are mock). If time runs short, the same cut-line discipline applies: ship each slice's backend + happy path, defer polish.

**Cut line (drop in this order if behind):** ① employee record Documents tab → static list · ② HR attendance page (keep clock panel only) · ③ reimbursement receipt upload → text field · ④ HR chart → static numbers row · ⑤ reimbursement HR detail view → table-only actions. **Never cut:** auth, shell, employees, the leave flow, the two hero dashboards.

---

## 11. AI execution protocol (how to run this plan tomorrow)

**Per slice, in Section 4 order:**
1. Read the slice spec (Section 5) + the standards checklist (Section 7).
2. **Backend first:** migration → model → request → resource → service → controller → route (Phase-7 loop). Verify with `curl` + a tinker token — JSON envelope correct, validation rejects bad input.
3. Add the slice's demo rows to the seeder; run it.
4. **Frontend:** fetch the slice's Figma frame per the §1.5 protocol (screenshot first; design-context only if ambiguous). Assemble the page from kit components; wire via types → api fn → hook.
5. Verify in the browser (both roles where relevant). TanStack mutations must **invalidate** the affected queries so lists/balances refresh.
6. `git add -A && git commit -m "feat(hr): <slice>"` — commit per slice, so a broken slice never takes down a working one.

**Standing rules:** Laravel-only logic (never in Next.js) · tokens not raw hexes · kit components not ad-hoc styling · screens without Figma = clean shadcn defaults · don't polish off-script screens · when a slice overruns its budget, apply the cut line and move on — a complete demo path beats a perfect half-app.

**Prerequisite before Slice 1:** Phase 6 of the runbook (both servers running: `php artisan serve` :8000 + `npm run dev` :3000).

---

## 12. Operator guide — what YOU do on build day

Your job is **verifier and decision-maker**, not typist. The loop:

1. **Kick off:** open the project in Claude Code and say: *"Read `docs/HRIS-BUILD-PLAN.md` and execute it. Start with the Phase 6 prerequisite, then Slice 1. Follow §11's protocol; stop after each slice for my check."*
2. **While a slice builds:** approve permission prompts; keep an eye on the two server terminals (or let the AI run them in the background).
3. **When the AI says a slice is done (~every 20–75 min):** do a **30-second browser check yourself** — actually click the thing (log in, press the button, see the toast). If good → say **"continue"**. If broken → paste what you saw; don't let it advance on a broken foundation. *(Catching a bad Slice 1 at 9:30 costs 10 minutes; discovering it at 14:00 costs the demo.)*
4. **Watch the clock (§10):** if a slice blows past its budget, say *"apply the cut line and move on."* You own that call, not the AI.
5. **Every slice ends in a git commit** — if something goes sideways, `git checkout .` returns to the last good slice.
6. **Last 40 min:** run the demo script (§9) start to finish yourself, fix only what breaks on that path.

**If the session dies/resets:** start a new one and say *"continue the HRIS build from the plan"* — memory + git history + this doc carry the state. `git log --oneline` shows exactly which slice was last completed.

---

## 13. Pre-merge / pre-deploy checklist (platform merge — deliberate merge-day work, NOT part of the module build)

> **Context:** the merged platform lives at **app.viteseo.ph** — root `/` is a **launcher** (icons for CRM/PM/HR, filtered by role/permission), the CTO's unified login replaces ours, and **each module has its own design/layout** (route-group `layout.tsx` each; "one design system" = shared tokens/stack, not one shared shell). None of the items below should be done during the hackathon build — they're deliberate merge-day relocations, all cheap because pages are thin and logic lives behind the API.

**Frontend relocations (folder renames, not rewrites):**
1. **Move employee HR pages off root URLs.** The `(employee)` route group serves `/`, `/time-off`, `/claims`, `/payslips`, `/benefits` — but at merge, `/` belongs to the launcher and `/time-off` is an HR concept squatting on a global URL. Move them under the HR namespace (e.g. `/hr/me/*`). Cost: rename the folder + ~5 hrefs in `src/core/nav.ts` + redirect targets (login page, `RequireAuth` in `auth-context`, the 401 interceptor in `api-client.ts`). The page components themselves don't change — they only call the API.
2. **Move the dark "CRIS.io" shell out of core.** `AppShell`/`Sidebar`/`TopBar` in `src/core/components/` are HR's design, not the platform's. Relocate to `src/modules/hr/components/` so other pods don't mistake them for shared kit. What stays in `src/core/`: tokens, `Toaster`, and generic kit (`PageHeader`, `DataTable`, `StatusBadge`, …) **only if** pods share the design system — **CTO decision, confirm before merge.**
3. **Swap the throwaway login page** (`src/app/(auth)/login/`) + its `hr_officer → /hr else /` redirect for the CTO's unified login + launcher. The token contract (Sanctum bearer in localStorage + shared Axios interceptor + `/auth/me` for roles) is the real interface and already matches — the swap is drop-in.
4. **(Strictness, optional)** `hrNav` in `src/core/nav.ts` is HR-module data living in core; on merge, each module should register its own nav. 5-minute move to `src/modules/hr/`.

**Backend items:**
5. **(Convention, CTO call)** Shared auth (`AuthController`, `LoginRequest`, `UserResource`, `RolesAndPermissionsSeeder`) sits in Laravel's default folders (`app/Http/...`), matching the existing scaffold — not a literal `app/Core/` module as §2 of the standards doc sketches. If the CTO wants strict `app/Core/`, relocate then (would also move `app/Models/User.php`).
6. **(Strictness, optional)** `hr.*` permissions are created in the Core `RolesAndPermissionsSeeder`; standards §4 says each module registers its permissions in its own seeder. Split out an HR-module seeder at merge.
7. **Deploy config:** `config/cors.php` allows only `http://localhost:3000` — add the production origin(s) (e.g. `https://app.viteseo.ph`). Same for `.env` URLs (`APP_URL`, frontend `NEXT_PUBLIC_API_BASE_URL`).

**Must NOT change at merge (already merge-shaped — the expensive contracts):** one `users` table · Sanctum token flow · `hr_*` table prefixes · `/api/v1/hr/*` endpoints · `{data}` envelope · `src/app/hr/*` route group · `src/modules/hr/` feature code. URLs are the cheapest thing to change; DB/auth/API contracts are the expensive ones — and those are already correct.

---

# 14. Phase 2 — Revisions & Additions (added 2026-07-08)

> **What this is.** Phase 1 (Slices 1–11 above) shipped the core HRIS. Phase 2 is the **second pass from the final-revisions brief** (`Team POD 1 (HR INFO SYSTEM).pdf`, 2026-07-08): a **navigation restructure** (flat sidebar → 7 grouped/drop-down items), **revisions to existing slices** (Recruitment, Employees, Attendance, Payroll, Leave-type allotment), and a set of **net-new modules** (Requests hub with Document/Payroll/Concern queues, Company config hub with Rules/Holidays/FAQ, Office Expenses, expanded Reports, audit logging). It is built with the **same Phase-7 vertical-slice loop and the same standards** (§7) as Phase 1 — nothing here changes the merge contracts (`hr_*` prefix, `/api/v1/hr/*`, `{data}` envelope, one `users` table).
>
> **Status of this section: GUIDE, not frozen spec.** The Phase-2 designs are **not done in Figma yet** and several inputs are pending (see §14.1). Where the brief is silent on a table/endpoint/rule, this section **proposes a convention-following default** (that is the build plan's job — see the doc's purpose note) and tags it **`PROVISIONAL`** or **`CONFIRM`**. The user will supplement missing detail. **Anti-hallucination still applies:** do not invent *beyond* what is proposed here; if a `PROVISIONAL` choice materially affects data shape and you're unsure, **ask before migrating.**

## 14.0 How the AI should read & execute Phase 2

1. **Read this whole section first**, then build **Slices 12→25 in order** (§14.6) — dependencies flow downward exactly like Phase 1.
2. **Per slice:** same loop as §11 — backend `migration → model → request → resource → service → controller → route`, verify with `curl`, seed demo rows, then frontend `types → api fn → hook → page`, verify in browser (both roles), `git commit`, **STOP for the user's check.**
3. **No Figma yet → build clean shadcn defaults using the existing tokens & kit** (§2.5 components: `PageHeader`, `StatCard`, `DataTable`, `StatusBadge`, `FormDialog`, `EmptyState`). Do **not** call the Figma MCP for Phase-2 screens until the user says a frame exists and gives its node ID. Re-use Phase-1 patterns (the leave queue `hr-Time & Leave` and reimbursement queue are the visual template for every new request/approval queue).
4. **Respect the `PROVISIONAL`/`CONFIRM`/⚠️/🔗 tags.** `CONFIRM` = ask the user before building. ⚠️ = a data/logic gap to flag. 🔗 = depends on a pending open-item in §14.1.
5. **Every Phase-2 mutation still declares the query keys it invalidates** (§5.5a) — that is how queues refresh after approve/reject.

## 14.1 Open dependencies (blockers — from the brief's "Open Action Items")

Build around these; **mock the input and label it** until provided. None should block starting a slice — they block *finalizing* it.

| # | Pending input | Owner | Blocks / affects |
|---|---|---|---|
| 1 | Applicant **email template** (screening→interview) | Miss Chris | Recruitment v2 interview-scheduling email (Slice 18) — build the trigger + a placeholder template |
| 2 | The real **list of 201 employees** + scanned 201 docs | Miss Chris | Employees directory volume + Documents tab (seed placeholder rows meanwhile) |
| 3 | **SSS contribution table** (official brackets) | Miss Chris | Payroll v2 deductions (Slice 22) — keep the §5.9 *simplified* formula as fallback, make it table-driven so swapping in real brackets is data-only |
| 4 | **Health & Safety report** requirements | Miss Chris | Reports v2 H&S report (Slice 25) — build the queue feed from Concerns; leave metric layout mock |
| 5 | **Monthly & annual report formats** | Miss Chris | Reports v2 export formats (Slice 25) |
| 6 | **Design blueprint** (HR + employee screens) | Sir Adrian | Every Phase-2 screen's pixel layout — until then, shadcn defaults + tokens |
| 7 | **Country-based holiday module** evaluation (data source, per-year/per-country sets, refresh) | Sir Adrian | Holiday List (Slice 15) — proposal uses **Nager.Date** (free, no key); confirm before wiring a paid source |

## 14.2 Navigation restructure — the new 7-group sidebar IA

The flat sidebar (`src/core/nav.ts`, `hrNav`) becomes **7 top-level items**: **Dashboard, Attendance, Reports** are standalone links; **People, Finance, Request, Company** are **collapsible drop-down groups**. User card stays pinned at the bottom (already built). The **Company** group is **HR/Admin-only** (gate by role, not just cosmetic hiding — §5.5d).

**Route map** (current → Phase-2). URL moves are cheap (page components only call the API); folder renames + `nav.ts` edits, like the merge relocations in §13.

| Sidebar item | Type | Sub-item | Route (Phase-2) | Was | Slice |
|---|---|---|---|---|---|
| **Dashboard** | link | — | `/hr` | same | (Phase-1, §5.8 revision) |
| **People** | group | Recruitment | `/hr/people/recruitment` | `/hr/recruitment` | 18 (revise) |
| | | Employees | `/hr/people/employees` | `/hr/employees` | 17 (revise) |
| **Attendance** | link | — | `/hr/attendance` | same | 16 (revise) |
| **Finance** | group | Payroll | `/hr/finance/payroll` | `/hr/payroll` | 22 (revise) |
| | | Office Expenses | `/hr/finance/office-expenses` | 🆕 | 23 |
| **Reports** | link | — | `/hr/reports` | same | 25 (expand) |
| **Request** | group | Leave | `/hr/requests/leave` | `/hr/time-leave` (leave half) | 12 (move) |
| | | Document | `/hr/requests/document` | 🆕 | 19 |
| | | Payroll | `/hr/requests/payroll` | 🆕 | 20 |
| | | Concern | `/hr/requests/concern` | 🆕 | 21 |
| **Company** | group (HR/Admin) | Benefits | `/hr/company/benefits` | `/hr/benefits` (stub) | 13 |
| | | Departments | `/hr/company/departments` | `/hr/departments` | 13 (move) |
| | | Leave Type | `/hr/company/leave-types` | 🆕 | 13 |
| | | Rules | `/hr/company/rules` | 🆕 | 14 |
| | | Holiday List | `/hr/company/holidays` | 🆕 | 15 |
| | | FAQ | `/hr/company/faq` | 🆕 | 24 |

- **Reimbursement relocation:** the brief says "reimbursement claims live under Payroll since approved claims roll into the payroll run." **`PROVISIONAL`:** keep the existing HR reimbursement queue but surface it **under Finance** — either as a tab inside `/hr/finance/payroll` or a link `/hr/finance/payroll/claims`. The *employee* claim page (`/claims`) is unchanged. **CONFIRM** whether HR wants it as a Payroll tab vs its own Finance sub-item.
- **`Sidebar` component** (`src/core/components/sidebar.tsx`) needs a **collapsible group** primitive (shadcn `Collapsible`/accordion): a group header (icon + label + chevron) that expands to indented sub-links; active sub-link keeps the teal-pill/cyan-bar active style from the §2 shell revision. Preserve red count badges (now on **group headers**: sum of the group's pending counts, e.g. Request badge = leave + document + payroll + concern pending).
- **Employee-side nav** gains counterparts (Slice-by-slice): **My Requests** (document/change/payslip/inquiry), **My Concerns** (with meeting invites), **FAQ** (read-only), **Payslips** already exists. Keep the employee sidebar flat (fewer items).
- **Roles note:** the brief frames HR as one of HR/CRM/PM modules with **HR the default view**; sidebar visibility follows role. That is the §13 platform-merge launcher story — **do not build CRM/PM nav now**; just keep nav role-filtered.
- **Pending / not-placed (do NOT add to sidebar yet):** Meeting Scheduling as a standalone item, Password Manager. (FAQ *is* placed under Company; the brief's "pending" mention of FAQ is superseded by §9.6 which specs it — build it, Slice 24.) **CONFIRM** if unsure.

## 14.3 Cross-cutting mechanics (new in Phase 2 — apply everywhere)

These override per-slice ambiguity, exactly like §5.5.

**(a) Centralized Approve/Reject validators.** Every approval flow (leave, claims, change requests, payslip/document requests, candidate "Hired", concerns→resolve, office-expense approval) uses **two shared frontend primitives** + a **uniform backend contract**:
- **Approve** → a shared confirm dialog: *"Are you sure? Y/N."* No free-text needed.
- **Reject/Decline** → a shared dialog with an **optional reason** textarea.
- **Build once:** `src/core/components/ConfirmDialog.tsx` (+ a `useApproveReject` hook) reused by every queue. Reject posts `{ reason?: string }`; the backend stores it in a **`review_note`** column (add it wherever missing — reimbursements/leave should get `review_note` if absent) and the **Declined toast** (§2 shell revision) says "notified by your note." This replaces per-page ad-hoc confirms.

**(b) Universal status/process on every table (the brief's ALL-CAPS NOTE).** *Every* record-bearing table in the system carries an explicit **process/status lifecycle** column and shows a `StatusBadge`. Standardize the vocabulary:
- **Approval queues:** `pending → approved → (paid)` or `pending → rejected`.
- **Fulfilment queues (document/inquiry/concern):** `pending → in_progress/under_review → done/fulfilled/resolved` (+ `rejected` where it applies).
- Reuse the existing `StatusBadge` mapping and **extend it** for the new states (`in_progress`→blue, `under_review`→blue, `fulfilled`/`resolved`/`done`→green, `draft`→gray, `published`→green, `archived`→gray). One badge component, one status column convention, everywhere.

**(c) Audit logging — who / what / when (feeds Company change-logs AND the Audit Trail report).** The brief repeatedly requires "changes are logged (who, what, when)" for Company edits and a full **Audit Trail** report. Build **one** append-only mechanism:
- **Table `hr_audit_logs`** (append-only, **no soft-delete**): `user_id`→users, `action` (e.g. `created`/`updated`/`deleted`/`approved`/`rejected`/`login`/`exported`), `auditable_type` + `auditable_id` (morph target), `before` (json nullable), `after` (json nullable), `ip_address`, `created_at`. Index `(auditable_type, auditable_id)` and `(user_id, created_at)`.
- **How to write it:** a small `AuditService::log(...)` called from services on mutating actions **or** a Laravel model `booted()` observer on the audited models (prefer the observer for CRUD; call the service explicitly for approvals/logins/exports). Do **not** scatter logging in controllers.
- **Consumed by:** Company config screens (show a "last edited by X on Y" line + change history) and the **Audit Trail report** (Slice 25). **Access:** admins + HR leadership only.

**(d) Global search (top bar).** The placeholder search becomes real: `GET /hr/search?q=` returns grouped hits across **employees, requests, documents, people, FAQ** (`{ employees:[], requests:[], faq:[], ... }`, capped per group). **PROVISIONAL:** simple `LIKE`/`whereFullText` per module, HR-scoped; no search engine. Frontend = a top-bar command palette (shadcn `Command`) hitting a debounced query. Low priority — can land last.

**(e) Notifications (expanded triggers).** The existing pending-derived bell (`NotificationService::forHr()`) gains sources: **new document/payslip/inquiry requests, new concerns, attendance violations (high priority), pending field time-ins** (geofenced doc). Employee side gets: **request status changes, meeting invitations, concern status changes.** Keep the derive-from-status approach where possible (no new table); add a lightweight `hr_notifications` table only if a "mark read" state is required — **CONFIRM.** Telegram for payroll inquiry is **out of scope** (in-app only; §14 Slice 20 flags it).

**(f) Role-based work schedules & the violation engine (shared by Attendance + Rules).** Lateness/absence is **not** a single company cutoff — it references **each employee's role schedule** (or per-employee override). Both Attendance (Slice 16) and Rules (Slice 14) read the same `hr_work_schedules` + `hr_attendance_policies`. The engine that turns raw attendance into **color-coded violations** lives in one service (`ViolationService`) and is documented in Slice 16.

**(g) Anonymity handling (Concerns).** For anonymous concerns, the submitter's identity **must never appear in any HR-facing resource**. Store `employee_id` server-side (needed to route meeting invites back to the submitter's own view) but **omit it from the HR Resource** when `is_anonymous=true`, and key the concern by a public **`reference_no`**. Enforce in the **Resource layer + a policy**, not just the UI. (Details in Slice 21.)

## 14.4 Data-model additions & revisions

Same rules as §2 (`hr_` prefix, `id` PK, timestamps, `deleted_at` soft-delete except append-only logs, money `decimal(12,2)`, FKs `{singular}_id`, UTC). **All `PROVISIONAL` — confirm before migrating if a shape looks off.**

**New tables (by area):**

| Area | Table | Key columns (beyond id/timestamps/softDeletes) | FKs |
|---|---|---|---|
| Requests | `hr_document_requests` | `type` (payroll/leave/attendance/coe/other), `reason` (text), `status` (pending/in_progress/fulfilled/rejected), `fulfilled_path` (nullable), `review_note` (nullable), `reviewed_at` | `employee_id` · `reviewed_by`→users |
| Requests | `hr_change_requests` | `field` (phone/address/bank_account/…), `old_value`, `new_value`, `status` (pending/approved/rejected), `review_note`, `reviewed_at` | `employee_id` · `reviewed_by`→users |
| Requests | `hr_payslip_requests` | `reason` (text, **required**), `status` (pending/approved/rejected/fulfilled), `review_note`, `reviewed_at` | `employee_id` · `payslip_id`→hr_payslips (nullable) · `reviewed_by`→users |
| Requests | `hr_payroll_inquiries` | `message` (text), `priority` (default high), `status` (pending/in_progress/done), `resolution_note` (nullable), `resolved_at` (nullable) | `employee_id` · `payslip_id` (nullable) · `resolved_by`→users |
| Concerns | `hr_concerns` | `reference_no` (unique), `category` (conduct/harassment/safety/policy/other), `description` (text), `is_anonymous` (bool), `status` (pending/under_review/resolved), `submitted_at`, `resolved_at` (nullable) | `employee_id` (**stored, hidden when anonymous**) |
| Concerns | `hr_meetings` | `scheduled_at` (datetime), `location` (string/link), `agenda` (text nullable), `status` (scheduled/accepted/declined/completed/cancelled), `invite_response_at` (nullable) | `concern_id` (nullable) · `employee_id` (nullable for anon) · `scheduled_by`→users |
| Finance | `hr_office_expenses` | `category`, `amount` decimal(12,2), `expense_date` (date), `description` (text), `vendor` (nullable), `receipt_path` (nullable), `status` (recorded/approved/rejected), `review_note` (nullable) | `recorded_by`→users · `reviewed_by`→users (nullable) |
| Company | `hr_leave_type_allotments` | `employment_status` (regular/probationary/contract/part_time), `days_per_year` decimal(5,1); unique(`leave_type_id`,`employment_status`) — **DECIDED 2026-07-08: keyed on employment status, not job role** (see Slice 13) | `leave_type_id`→hr_leave_types |
| Company | `hr_contribution_settings` | `type` (sss/philhealth/pagibig), `config` (json — rate or bracket rows), `is_active` | — |
| Company | `hr_employee_benefits` | `contribution_type` (sss/philhealth/pagibig/…), `enrolled` (bool) — the per-employee **checklist** (not all employees share contributions) | `employee_id` |
| Company | `hr_work_schedules` | `name`, `applies_to` (role name or `default`), `workdays` (json e.g. `[1,2,3,4,5]`), `start_time` (time), `end_time` (time), `grace_minutes` (int) | — |
| Company | `hr_attendance_policies` | `consecutive_lates_before_absence` (default 3→4th=absent), `monthly_late_reset` (bool), `awol_days_threshold`, `task_format` (json — e.g. English/caps rule), other handbook thresholds the violation engine reads | — (singleton-ish; seed one row) |
| Company | `hr_holiday_sets` | `country_code`, `year` (int), `source` (nager/manual), `refreshed_at` (nullable) | — |
| Company | `hr_holidays` | `name`, `date`, `type` (regular/special/special_working), `is_active` | `holiday_set_id`→hr_holiday_sets |
| Company | `hr_faqs` | `question`, `answer` (text), `category`, `tags` (json), `status` (draft/published/archived), `is_pinned` (bool), `view_count` (int default 0) | `updated_by`→users |
| Company | `hr_faq_events` *(optional — insights)* | `term` (nullable), `faq_id` (nullable), `kind` (search/view) | `faq_id`→hr_faqs (nullable) · `employee_id` (nullable) |
| Attendance | `hr_violations` | `type` (missed_time_in/late/ua/awol), `severity` (green/yellow/orange/red), `work_date` (date), `details` (nullable), `resolved` (bool default 0) | `employee_id` |
| People | `hr_salary_histories` | `base_salary` decimal(12,2), `effective_date` (date), `reason` (nullable) | `employee_id` · `changed_by`→users |
| Cross-cutting | `hr_audit_logs` *(append-only, no softDeletes)* | `action`, `auditable_type`, `auditable_id`, `before` (json), `after` (json), `ip_address` | `user_id`→users |
| Reports | `hr_report_exports` | `report_key`, `params` (json), `path` (nullable), `generated_at` | `generated_by`→users |

**Revisions to existing tables:**
- `hr_employees` — add `onboarding_status` (nullable: `pending`/`complete`; set `pending` on recruitment-hire, HR flips to `complete` in Job Info), `work_schedule_id`→`hr_work_schedules` (nullable; default from role), plus optional per-employee override columns `custom_workdays` (json nullable) / `custom_start_time` / `custom_end_time` / `custom_grace_minutes` (the **Work Details** overrides). `job_title`/`department` already present.
- `hr_leave_requests`, `hr_reimbursements` — ensure a **`review_note`** column exists (add if missing) for the centralized reject-reason (§14.3a).
- `hr_attendance` — add the geofence/field columns **only as specified in `docs/HRIS-Geofenced-Attendance-Plan.md`** (lat/lng/accuracy, selfie path, `source` office/field, `approval_status` for field time-ins). Do not re-derive them here — that doc is the source of truth for Attendance's location features.
- `hr_leave_balances` — unchanged shape; **populated from `hr_leave_type_allotments` by employment status** now (Slice 13) instead of flat `leave_type.days_per_year`, and freely editable per-person after (the override layer).

## 14.5 Endpoint additions

All under `/api/v1/hr`, `auth:sanctum` + role gates (Company/Reports/Concern = `hr_officer`/`admin`; `/me/*` = auth, self-scoped). Envelope `{data}` + `meta` for lists.

| Module | Endpoints |
|---|---|
| Document requests | `GET/POST /hr/document-requests` · `POST /hr/document-requests/{id}/fulfill` · `/reject` · employee: `GET/POST /hr/me/document-requests` |
| Change requests | `GET /hr/change-requests` · `POST /hr/change-requests/{id}/approve` · `/reject` · employee: `GET/POST /hr/me/change-requests` (approve writes new_value onto the employee record in a txn) |
| Payslip requests | `GET /hr/payslip-requests` · `POST /hr/payslip-requests/{id}/approve` · `/reject` · employee: `POST /hr/me/payslip-requests` |
| Payroll inquiry | `GET /hr/payroll-inquiries` · `POST /hr/payroll-inquiries/{id}/progress` · `/resolve` (optional note) · employee: `POST /hr/me/payroll-inquiries` (the "Request Inquiry" button on a payslip) |
| Concerns | `GET /hr/concerns` (HR/Admin) · `POST /hr/concerns/{id}/status` (under_review/resolved) · employee: `GET /hr/me/concerns` · `POST /hr/me/concerns` (named or anonymous) |
| Meetings | `POST /hr/concerns/{id}/meetings` (schedule) · `GET /hr/me/meetings` · `POST /hr/me/meetings/{id}/respond` (accept/decline — anon-safe) |
| Office expenses | `GET/POST /hr/office-expenses` · `POST /hr/office-expenses/{id}/approve` · `/reject` |
| Leave-type allotments | `GET/POST /hr/leave-type-allotments` · `PUT /hr/leave-type-allotments/{id}` |
| Benefits/contributions | `GET/PUT /hr/contribution-settings` · `GET/PUT /hr/employees/{id}/benefits` (checklist) |
| Work schedules | `GET/POST /hr/work-schedules` · `PUT/DELETE /hr/work-schedules/{id}` · `PUT /hr/employees/{id}/schedule` (per-employee override) |
| Attendance policy / Rules | `GET/PUT /hr/attendance-policy` · `GET /hr/violations` (+ `?employee=`) · `POST /hr/violations/{id}/resolve` |
| Holidays | `GET /hr/holiday-sets` · `POST /hr/holiday-sets` (country+year → auto-load) · `POST /hr/holiday-sets/{id}/refresh` · `GET/POST/PUT/DELETE /hr/holidays` |
| FAQ | `GET/POST /hr/faqs` · `PUT/DELETE /hr/faqs/{id}` · `POST /hr/faqs/{id}/publish` · `/archive` · `/pin` · employee: `GET /hr/faqs` (published only) · `POST /hr/faqs/{id}/view` |
| Reports v2 | `GET /hr/reports/{key}` extended keys `+ {compensation, health_safety, audit}` · `GET /hr/reports/{key}/export` (logs to `hr_report_exports`) |
| Salary history | `GET /hr/employees/{id}/salary-history` (write happens inside `EmployeeService` when `base_salary` changes) |
| Search | `GET /hr/search?q=` |

## 14.6 Phase-2 build order & sizing

Dependency-ordered; each = one Phase-7 slice. Sizing is **relative effort** (per §10's note — not a clock).

| # | Slice | Depends on | Weight | Notes |
|---|---|---|---|---|
| 12 | **Nav restructure + centralized validators + status convention** | shell (§2) | M | Foundation for every Phase-2 screen |
| 13 | **Company hub I: Departments move + Benefits config + Leave-type per-role allotment** | 12 | M | Also seeds `hr_contribution_settings` used by Payroll v2 |
| 14 | **Rules: work schedules per role + attendance policy + task format** | 13 | M | Feeds the violation engine |
| 15 | **Holiday List (country-based, Nager.Date, per-year sets, refresh)** 🔗#7 | 13 | M | Also refines leave-days math (skip holidays) |
| 16 | **Attendance v2: 8 summary cards + violation engine + HR corrections + off-site geo/selfie** | 14, 15 | L | Geo/selfie per `HRIS-Geofenced-Attendance-Plan.md` |
| 17 | **Employees v2: Work Details tab + salary history + onboarding status** | 14 | M | |
| 18 | **Recruitment v2: stage flows + OJT + interview email + onboarding handoff** 🔗#1 | 17 | L | Revises Slice 10 |
| 19 | **Requests: Document requests + Change requests** | 12 | M | Change-request promotes the Tier-2 approval workflow |
| 20 | **Requests: Payslip requests + Payroll inquiry** | 9 (payroll), 12 | S–M | Telegram out of scope |
| 21 | **Concerns + Meeting Scheduling (anonymity)** | 12, notifications | L | Highest-care slice — privacy |
| 22 | **Payroll v2: attendance-connected computation + editable contributions + privacy toggle + benefits checklist** 🔗#3 | 13, 16 | L | Revises Slice 9 |
| 23 | **Office Expenses** | 12 | S | |
| 24 | **FAQ / Knowledge Base** | 12 | M | |
| 25 | **Audit logging (cross-cutting) + Reports v2 (Compensation, Health & Safety, Audit Trail, report history)** 🔗#4,#5 | most of the above | L | Aggregation pass |

> Global search (§14.3d) and notification expansion (§14.3e) are **woven in as small tasks**, not standalone slices — land them opportunistically (search can be last).

## 14.7 Per-feature slices

Each lists the delta over Phase 1. Standards (§7), envelope, timezone (§5.5b), leave-days (§5.5c), and the mutation→invalidation pattern (§5.5a) all still apply and are not repeated.

---

### Slice 12 — Navigation restructure + centralized validators + status convention  *(Foundation)*

**Goal:** the 7-group drop-down sidebar (§14.2) and the two shared approval primitives (§14.3a) that every later queue reuses. Mostly frontend; the only backend touch is adding `review_note` where missing.

**Backend**
- Add a `review_note` (nullable text) column to any approval table lacking it (`hr_leave_requests`, `hr_reimbursements`) via a migration; accept an optional `reason` in the existing reject requests and persist it. No new endpoints.

**Frontend**
- **`nav.ts`:** replace the flat `hrNav` with a **grouped structure** (`NavGroup { label, icon, children: NavItem[] }` | `NavItem`), matching the §14.2 route map. Move page folders to the new routes (redirect old URLs). Keep the employee nav flat.
- **`Sidebar`:** add a collapsible-group primitive (shadcn `Collapsible`); active sub-link keeps the teal-pill/cyan-bar style (§2 revision); group-header badges = sum of children pending counts.
- **`ConfirmDialog` + `useApproveReject`** (`src/core/components/`): the shared "Are you sure? Y/N" approve dialog and the "optional reason" reject dialog; wire the Approved/Declined toast variants (§2 revision). **Refactor the existing leave + reimbursement queues to use them** (proves the primitive before the new queues depend on it).
- **`StatusBadge`:** extend the status→color map (§14.3b).

**Done when:** the HR sidebar shows all 7 items with working drop-downs and role-gated Company group; approving/declining a leave request goes through the shared dialogs and fires the correct toast; old URLs redirect.

---

### Slice 13 — Company hub I: Departments + Benefits + Leave-type allotment  *(Company config)*

**Goal:** stand up the Company section shell and its first three editable configs. Everything here is **HR/Admin-only** and **audit-logged** (§14.3c — you can defer the audit wiring to Slice 25 but add the observer hooks now if cheap).

**Backend**
- **Departments:** move under `/hr/company/departments` conceptually (route/nav only — the API path `/hr/departments` **stays** for merge stability; only the *frontend* route moves). Add **archive** (soft-delete already exists) + rename (update exists).
- **Benefits/contributions:** `hr_contribution_settings` (seed SSS placeholder brackets 🔗#3, PhilHealth `{rate:0.025}`, Pag-IBIG `{rate:0.02, cap:200}`). `hr_employee_benefits` checklist. Endpoints §14.5. `ContributionService` exposes `for($employee)` returning the effective rates/brackets **Payroll v2 will consume** (Slice 22).
- **Leave-type allotment (DECIDED 2026-07-08 — two layers):**
  - **Layer 1 — defaults by employment status.** `hr_leave_type_allotments` keys on `employment_status` (regular/probationary/contract/part_time), NOT job role — the auth roles are too coarse (everyone is `employee`) and employment type is the axis where PH leave credits actually differ. **Revise `EmployeeService::create`** to seed `hr_leave_balances` from the matching-status allotment (fallback to `leave_type.days_per_year`). Changes apply **going forward only** — editing a default never rewrites existing balances.
  - **Layer 2 — per-person override (the source of truth).** `hr_leave_balances` is already per-employee, so HR can edit any individual's `entitled_days`, add a leave type (create a balance row), or remove one — endpoints `GET/PUT /hr/employees/{id}/leave-balances` + `DELETE /hr/leave-balances/{id}`. This is what makes credits fully dynamic ("not all regulars/managers are the same"). *(A formal job-position/role list is deferred to Slice 14, where schedules genuinely need it — leave doesn't.)*

**Frontend** — no Figma; kit-assembled.
- `/hr/company/benefits` (contribution rates editor + per-employee checklist), `/hr/company/departments` (existing table, +archive), `/hr/company/leave-types` (leave types with color + per-role allotment editor).

**Done when:** HR edits SSS/PhilHealth/Pag-IBIG values and per-role leave allotments; a newly created employee receives balances from their role's allotment.

---

### Slice 14 — Rules: work schedules + attendance policy + task format  *(Company config)*

**Goal:** the role-based schedules and handbook thresholds the **violation engine** (Slice 16) reads. (§14.3f)

> **DECIDED 2026-07-08 (BUILT):** no formal positions/job-title table is needed. Schedules are **named entities** (`hr_work_schedules`, e.g. "Office", "Field") that employees are **explicitly assigned** to via `hr_employees.work_schedule_id` (null → the `is_default` schedule), with **per-employee overrides** (`custom_workdays`/`custom_start_time`/`custom_end_time`/`custom_grace_minutes`). This is the same group-default + per-person-override pattern as leave (Slice 13) and maps cleanly to "field vs office staff." `ScheduleService::effectiveFor()` resolves **employee override → assigned schedule → default**. Leave keys on employment status; schedules key on assigned named schedule — different axes, as intended. Deleting the default schedule is blocked (422).

**Backend**
- `hr_work_schedules` (per role: workdays, start/end, grace) + seed a `default` office schedule (09:00–18:00, Mon–Fri, grace 15m — **CONFIRM** real windows). `hr_attendance_policies` singleton (consecutive-lates→absence = 3→4th; monthly late reset; AWOL thresholds; `task_format` rule). Endpoints §14.5. `PUT /hr/employees/{id}/schedule` for per-employee overrides (the Work Details write path, surfaced in Slice 17).
- **`ScheduleService::effectiveFor($employee, $date)`** — returns the employee's effective schedule (per-employee override → role schedule → default). This is the single source both Attendance lateness and the dashboard "grace period" read.

**Frontend**
- `/hr/company/rules` — schedules-per-role editor (table + form), attendance-policy form (thresholds), task-format rule (English/caps toggle + preview). Plain kit.

**Done when:** HR defines a role schedule and edits the lateness threshold; `ScheduleService::effectiveFor` resolves override→role→default correctly (verify via tinker/curl).

---

### Slice 15 — Holiday List (country-based)  *(Company config)* 🔗#7

> **BUILT 2026-07-08.** `hr_holiday_sets` (country + year, unique) + `hr_holidays` (name, date, **nullable** `type` regular/special_non_working/special_working/**null**, source, `needs_review`). `HolidayService::createSet(country, year)` loads dates from **Nager.Date** (free, no key; nationwide `global:true` only), `refresh()` re-pulls **deduping by date so it never duplicates or overwrites HR edits/manual additions**, plus full holiday CRUD. **Type-handling decision (verified against the live Nager API):** Nager returns only `["Public"]` for PH — it does **not** carry the PH Regular/Special/Special-Working classification, and the Official Gazette has no clean API (403s scraping). So we classify ourselves but **only where 100% certain — no guessing** (final policy per user 2026-07-08): a PH holiday whose Nager English `name` matches the legally-fixed Regular list (`PH_REGULAR_KEYWORDS` — precise tokens: "christmas day" *not* "christmas" so "Christmas Eve" isn't caught; "ramadhan"/"sacrifice" catch Eid'l Fitr/Adha; "day of valor", "national heroes day", etc.) → `regular`. **Everything else — unrecognised PH holidays AND all non-PH holidays — is left `type = null` (Unclassified) + `needs_review = true` for HR to set manually.** We never auto-assign Special vs Special-Working. **Leave-days math excludes only *classified* non-working days** (`LeaveService::computeDays` → `HolidayService::nonWorkingDatesBetween` filters `whereIn type [regular, special_non_working]`; null and special-working still count as work days until HR classifies). Verified against live Nager PH 2025/2026: all 12 legal Regular Holidays auto-set correctly (incl. both Eids), and Chinese New Year / Black Saturday / elections / Ninoy Aquino / All Saints' (+Eve) / Immaculate Conception / **Christmas Eve** / Last Day of Year all left blank — idempotent refresh confirmed. **Frontend:** `/hr/company/holidays` — country+year "Load holidays", set chips (multiple countries side by side), the holiday table with type badges, an unclassified day shown as a dashed **"Set type"** button (opens the edit dialog) + a `needs_review` ⚠️ + weekend markers, add/edit/remove (the add/edit dialog forces an explicit type choice — "— Select type —" placeholder, submit disabled until picked), "Refresh for this year", delete set, plus an "N unclassified — set the type" banner. Seeder ships a hardcoded PH 2026 set mirroring the classifier (10 regular auto-set, 6 unclassified) — no network at seed time. **Deferred:** the employee leave-form's live day-preview still counts weekday-only (server is authoritative and subtracts holidays); paid holiday-source (Calendarific etc.) not wired — Nager only.

**Goal:** per-country, per-year holiday sets with an auto-load + yearly-refresh action; feeds leave-days math and attendance.

**Backend**
- `hr_holiday_sets` + `hr_holidays`. **`HolidayService`:** `createSet(country, year)` pulls from **Nager.Date** (`https://date.nager.at/api/v3/PublicHolidays/{year}/{country}` — free, no key, ~120 countries) → maps to `type` (regular/special/special_working) and stores; `refresh(set)` re-pulls; manual add/edit/remove. Multiple sets coexist (PH + others side by side). **PROVISIONAL** source = Nager.Date; **CONFIRM** before wiring paid Calendarific/Abstract.
- **Refine leave-days math (§5.5c):** `days` still excludes weekends; now **also exclude active holidays** in the applicable set. Keep the frontend preview + server enforcement split.
- ⚠️ **External call:** wrap the Nager fetch in a try/catch with a clear error + a manual-entry fallback (don't hard-fail the page if the API is down). Cache per (country, year).

**Frontend**
- `/hr/company/holidays` — country + year selector → "Load holidays" (auto-populates), the Jan–Dec list (regular holidays, weekends excluded, type labels), add/edit/remove, **"Refresh for this year"** button, multiple sets shown side by side.

**Done when:** HR loads PH 2026 holidays from the API, edits one, adds a second country's set; a leave request spanning a holiday consumes one fewer day.

---

### Slice 16 — Attendance v2: summary cards + violations + corrections + off-site  *(Revision of Slice 6)*

> **BUILT 2026-07-08 (core) — off-site geo/selfie DEFERRED.** Delivered: schedule-based lateness (`clockIn` now reads `ScheduleService::effectiveFor`), the 8-card summary (`GET /hr/attendance/summary`), the enriched roster (dept, first-in/last-out, timing badge, on-leave type, worst violation flag), the **`ViolationService`** engine (`hr_violations`; `GET /hr/violations`, `/recompute`, `/{id}/resolve`) with the green/yellow/orange/red legend + late-escalation + consecutive-UA/AWOL, and HR corrections (existing `attendance/adjust`, now re-syncs flags). The **off-site geofence + field selfie flow is NOT built** — it's a distinct feature with its own spec (`docs/HRIS-Geofenced-Attendance-Plan.md`), needs browser geolocation/HTTPS + uploads + a pending-review queue, and should be its own slice. Two demo-data fixes shipped with it: the seeder now stores clock-ins as **UTC** (was Manila wall-clock → +8h skew) and aligned to the 09:00 default schedule; violations only count **explicit `absent` rows** (a missing historical row is untracked, not an absence).

**Goal:** the full attendance surface from the brief §5 — day-summary cards, the editable roster, role-schedule-based lateness, the **color-coded violation engine**, and the off-site geo/selfie path.

**Backend**
- **8 summary counts** for a given day (`GET /hr/attendance/summary?date=`): `checked_in`, `not_checked_in`, `on_leave`, `early_arrivals`, `late_arrivals`, `on_time`, `total_employees`, `absent` — the same tiles already scoped for the HR dashboard (§5.8 revision A); **this endpoint backs both** the dashboard check-in block and the Attendance page. Lateness/early/on-time computed against `ScheduleService::effectiveFor` (Slice 14), not a fixed 09:00.
- **HR corrections:** `PUT /hr/attendance/{id}` — edit time-in/out/status/note (attendance is the source of truth; if an employee on approved leave still worked, HR flips status to `present`). Audit-logged.
- **Violation engine — `ViolationService`:** turns attendance + policy into `hr_violations` rows with **severity color** per the brief's legend:
  - Green = 1st missed/late in-or-out; 1st–2nd tardiness in a month.
  - Yellow = 2nd missed/late; 3rd tardiness; 1-day UA (1st offense).
  - Orange = 3 consecutive lates; 4th tardiness; 1-day UA (2nd); 2 consecutive days UA (1st).
  - Red = 2 consecutive days UA (2nd); 3+ days AWOL; no report by day 4.
  - **Late→absence rule:** lates tallied per **calendar month**; after **3 consecutive lates the 4th counts as an absence.** Run as a scheduled/daily recompute (or on-read for the roster). Notifies HR (§14.3e, high priority).
- **Off-site geo/selfie:** implement **exactly** per `docs/HRIS-Geofenced-Attendance-Plan.md` (office geofence gate in `clockIn()`, field path = selfie+location+reason → pending HR review, approve/reject copies the reimbursement state machine). Recorded time reflects **original submission time even if approved later** (brief §5). Do not redesign — that doc is authoritative.

**Frontend**
- `/hr/attendance` — 8 pastel summary tiles (reuse `StatCard` color variants), a Leave-Types legend chip row, filter bar (search + status + timing), and the **check-in roster `DataTable`** (employee, dept, status, first-in, last-out, timing badge + a **violation flag** cell — click reveals the violation type). Inline edit for corrections. Pending field time-ins get an approve/reject sub-queue.
- Employee `TimeClockPanel` gains the **Field Time-In** path (geo permission + selfie) per the geofenced doc.

**Done when:** the roster shows correct per-employee first-in/last-out/timing against role schedules; a 4th consecutive late flips to absence; a red flag appears for AWOL; an off-site selfie time-in lands in HR's review queue and, once approved, keeps its original timestamp.

---

### Slice 17 — Employees v2: Work Details + salary history + onboarding  *(Revision of Slice 4)*

> **BUILT 2026-07-08.** `hr_salary_histories` (append-only; `EmployeeService` writes a row on hire and on every base-salary change, with `changed_by`/reason) + `GET /hr/employees/{id}/salary-history`. `onboarding_status` column (`pending`/`complete`/null); recruitment sets `pending` in Slice 18, HR clears it via `POST /hr/employees/{id}/activate` or the update form. Frontend: a new **Work Details** record tab (reuses the Slice-14 per-employee schedule editor, extracted to a shared `EmployeeScheduleEditor` — read-only on the employee's own profile), **Compensation** now shows the salary-history table, and an **Onboarding** badge on the directory card + Job tab with a "Mark complete" action. Work Details + salary history are hidden on the employee's read-only profile (they read HR-only endpoints). Demo: Grace seeded `onboarding=pending`; Maya has a multi-row salary history.

**Goal:** the two new record sub-tabs/behaviors from the brief §4.2.

**Backend**
- **Work Details:** per-employee schedule + leave overrides — `PUT /hr/employees/{id}/schedule` (Slice 14) + surface the override fields. Example: an employee scheduled to work Saturdays → `custom_workdays=[1,2,3,4,5,6]`.
- **Salary history:** `hr_salary_histories`; **`EmployeeService` writes a history row whenever `base_salary` changes** (with `changed_by`, `effective_date`). Compensation tab reads it.
- **Onboarding status:** `onboarding_status` column; recruitment-hire sets `pending` (Slice 18); HR flips to `complete`/sets `employment_status=active` from the Job tab.

**Frontend**
- Add **Work Details** and enrich **Compensation** (pay + **salary history** table) sub-tabs on `/hr/people/employees/[id]`. Onboarding badge on the directory + Job tab. Directory columns unchanged (Name, Dept, Job Title, Status, Manager).

**Done when:** HR overrides one employee's schedule to include Saturday; changing a salary appends a history row shown on the Compensation tab; a new hire shows an "Onboarding · Pending" badge until HR activates.

---

### Slice 18 — Recruitment v2: stage flows + OJT + interview email + handoff  *(Revision of Slice 10)*

> **BUILT 2026-07-08.** Requisitions gained a **`pipeline`** (standard/ojt); stage flows are **config-driven** in `RecruitmentService::STAGE_FLOWS` (standard: Resume Screening → Initial Online Interview → Final/Technical Interview → Assessment; OJT: Online Interview → MOA Review → Assignment of Roles) exposed per-requisition via `RequisitionResource.stages`, and the kanban renders its columns from that (horizontal-scroll board). `moveStage` validates the target stage belongs to the requisition's pipeline (422 otherwise). **Interview email** — `POST /hr/candidates/{id}/send-interview-email` sends an `InterviewInvitationMail` (placeholder template 🔗#1; MAIL_MAILER=log writes it to the log) and marks `interview_email_sent_at`; the card shows a "Send interview email" button on interview stages. **Hired handoff** — `hire()` now stamps the draft employee `onboarding_status='pending'` (Slice 17 badge/activate). Candidate cards gained `expected_salary`/`location` (+ `feedback` column). Seeder: 4 standard reqs + 1 OJT req with candidates spread across each flow. **Deviations:** the brief's "single filter across ALL applicants" is **not** built — the board stays per-requisition (a flat all-applicants view is a bigger restructure; flag for later). Website intake (Vite SEO site) remains out of scope. Scorecards JSON deferred (only `feedback` text added).

**Goal:** align the pipeline to the brief's real stages, add the OJT variant, the interview-scheduling email, and the onboarding-tag handoff.

**Backend**
- **Stages (`CONFIRM` naming with Miss Chris):** default flow `Resume Screening → Initial Online Interview → Final/Technical Interview (on-site) → Assessment → Hired/Rejected`; **OJT flow** `Online Interview → MOA Review → Assignment of Roles`. Make the stage list **config-driven per pipeline type** (a `pipeline` enum on the requisition: `standard`/`ojt`) rather than a hard-coded enum, so stage naming can change without a migration. ⚠️ Adjust `hr_candidates.stage` accordingly.
- **Interview email 🔗#1:** moving a candidate to an interview stage exposes a **"Send interview email"** action → `POST /hr/candidates/{id}/send-interview-email` using a stored template (placeholder until Miss Chris provides it) via Laravel Mail (`Mailable`). Log the send.
- **Hired handoff (revise `hire()`):** the created draft employee now carries **`onboarding_status=pending`** + `employment_status=probationary` (also for part-timers); HR activates in Job Info (Slice 17). Applicant cards carry resume/source/feedback/scorecards/stage-history/expected-salary/location fields — add the missing columns to `hr_candidates` (**PROVISIONAL:** `expected_salary` decimal, `location`, `feedback`/`scorecards` json).
- **Website intake** (Vite SEO site → pipeline): **out of scope now** (brief marks "to be followed") — flag only.

**Frontend**
- `/hr/people/recruitment` — single applicant filter covering **all** applicants (not per role); kanban per pipeline type; "Send interview email" button on the interview stage; "Mark hired" → validator → draft employee with onboarding tag.

**Done when:** a standard and an OJT requisition each show their own stage columns; moving to interview reveals the email action; hiring creates an onboarding-pending draft employee.

---

### Slice 19 — Requests: Document + Change requests  *(New)*

> **BUILT 2026-07-08.** `hr_document_requests` (employee requests payroll/leave/attendance/CoE; HR fulfils [optional file] or rejects) + `hr_change_requests` (phone/address/bank; **approve writes new_value onto the employee record in a txn + audit-logs before/after** — verified: an employee's phone change flowed through to their record and logged `{phone:old}→{phone:new}`). This **promotes the Tier-2 change-request workflow** (§6). **Audit infrastructure introduced here** (cross-cutting §14.3c): `hr_audit_logs` + `AuditService::log()`, called from change/document approvals — Slice 25 builds the Audit Trail *report* on top. Endpoints per §14.5 (`/hr/document-requests` + `/hr/change-requests` for HR; `/hr/me/*` for employees). Notifications extended: pending doc/change requests hit the HR bell + the `/hr/requests/document` nav badge; decisions notify the employee. **Frontend:** HR `/hr/requests/document` with **Documents / Profile-changes tabs** (queues + ReviewDialog fulfil/approve/reject); employee **`/requests`** ("My Requests") hub with both request forms + status lists (+ a new "Requests" employee nav item). **Deferred:** the fulfil **file-upload UI** (backend accepts a file; HR fulfils with an optional note for now) and putting the change form on the profile page (it lives on `/requests` instead).

**Goal:** two of the four Request queues. Employees submit; HR fulfills/approves through the centralized validators (§14.3a).

**Backend**
- **Document requests** (`hr_document_requests`): employee requests a payroll/leave/attendance/CoE record; HR **fulfills** (attaches a file from the 201 file or generates one) or rejects. Status `pending → in_progress → fulfilled | rejected`.
- **Change requests** (`hr_change_requests`): profile field changes (phone/address/bank_account) with **Old/New/Date/Status**; **approve writes `new_value` onto the employee record in a transaction** + audit-logs before/after (this **promotes the Tier-2 "profile change-request approval" workflow** noted in §6). Approver = HR (brief: "Miss Chris").

**Frontend**
- HR: `/hr/requests/document`, `/hr/requests/change` (or a tab within document) — queues with detail modal + fulfill/approve/reject.
- Employee: request-a-document form + a "Request profile change" flow on the profile page (replaces the view-only note in Slice 4) → **My Requests** list with statuses.

**Done when:** an employee requests a phone-number change; HR approves; the employee record updates and the change is audit-logged with old→new values.

---

### Slice 20 — Requests: Payslip requests + Payroll inquiry  *(New)*

> **BUILT 2026-07-08.** `hr_payslip_requests` (employee requests a payslip copy **with a required reason**; HR approve/reject) + `hr_payroll_inquiries` (**priority High**; `pending → in_progress → done` with an optional **resolution note** and both request + resolution timestamps for turnaround). Verified the full inquiry lifecycle (Start → Done+note, `resolved_at` set) and payslip-request approval. Approvals/resolutions are **audit-logged** (Slice-19 `AuditService`). Notifications extended: pending payslip requests + open inquiries hit the HR bell + the `/hr/requests/payroll` nav badge; decisions notify the employee. **Frontend:** HR `/hr/requests/payroll` with **Payslip-requests / Inquiries tabs** (approve/reject via ReviewDialog; inquiries get Start + a Done-with-optional-note dialog + turnaround display); employee **`/payslips`** detail gained **"Request copy"** + **"Request inquiry"** buttons (both tied to the viewed payslip), and the **`/requests`** hub gained read-only tracking lists for both. **Telegram follow-up remains out of scope** (in-app only, per the brief). Also derived-fixed a pre-existing `setState`-in-effect on `/payslips` (auto-select).

**Goal:** the payroll-related request queues (brief §7.3). Depends on Payroll (Slice 9).

**Backend**
- **Payslip requests** (`hr_payslip_requests`): employee requests a payslip **with a required reason**; HR approves/rejects (validators). On approve → mark fulfilled / make the payslip available.
- **Payroll inquiry** (`hr_payroll_inquiries`, **priority High**): a **"Request Inquiry"** button on the employee payslip view creates an inquiry that notifies HR **in-app** (Telegram **out of scope** — flag only, brief mentions it as future). Queue: employee name, date, status `pending → in_progress → done`, a **Done** action with an optional resolution note, **timestamps on request and resolution** for turnaround/audit.

**Frontend**
- HR: `/hr/requests/payroll` — two sub-queues (payslip requests / inquiries) with the status flows above.
- Employee: "Request payslip" (with reason) + "Request Inquiry" button on `/payslips`.

**Done when:** an employee raises an inquiry from a payslip; it appears in HR's high-priority queue; HR moves it to In Progress then Done with a note; both timestamps are recorded.

---

### Slice 21 — Concerns + Meeting Scheduling  *(New — privacy-critical)*

> **BUILT 2026-07-08.** `hr_concerns` (reference_no, category, `is_anonymous`, `pending → under_review → resolved`) + `hr_meetings`. **Anonymity is enforced in `ConcernResource`** (§14.3g), not the UI: for an anonymous concern the `employee` block is **never emitted** on any endpoint, and `MeetingResource` carries **no** identity at all. `employee_id` is still stored so invites can route back. **Verified the guarantee end-to-end:** HR sees `employee=null` for the anonymous concern; HR schedules a meeting **by reference number**; the anonymous submitter finds the invite on My Meetings (routed via `concern.employee_id`, never exposed) and **accepts**; after acceptance HR still sees no identity — only status `accepted`. Named concerns show identity; a named meeting carries `employee_id`, an anonymous one leaves it null. Safety-category concerns carry `is_safety` for the **Slice-25 Health & Safety** feed. Status changes + scheduling are **audit-logged**. Notifications: open concerns → HR bell + `/hr/requests/concern` badge (anon shows "Anonymous concern", never a name); employee gets meeting-invite + concern-resolved notes → `/concerns`. **Frontend:** HR `/hr/requests/concern` (queue + detail modal with status transitions + schedule-meeting dialog; anon rows show an "Anonymous" chip) and employee **`/concerns`** ("My Concerns" — submit named/anonymous, track status, accept/decline meeting invites; new "Concerns" employee nav item). Added an optional `onRowClick` to the shared `DataTable` + a `formatDateTime` helper.

**Goal:** the concerns queue with **anonymity handling** and concern-linked meeting scheduling (brief §7.4). HR/Admin-only. Read §14.3g before building.

**Backend**
- `hr_concerns` (+ unique `reference_no`, `is_anonymous`, category, `status pending → under_review → resolved`). Employee submits **named or anonymous**; HR is notified on submit (bell). **Anonymity enforcement:** the HR-facing `ConcernResource` **omits `employee_id` and any identity** when `is_anonymous=true`; a policy blocks any endpoint from leaking it. Server still stores `employee_id` (to route meeting invites and power the submitter's "My Concerns").
- **Meetings** (`hr_meetings`): from a concern, HR schedules a meeting (date/time, location/link, agenda). **Named** → set directly with the employee (appears in their view + bell). **Anonymous** → the invite attaches to the **concern's `reference_no`**; the anonymous submitter sees it on **My Concerns** and can **accept without revealing identity** or decline. `POST /hr/me/meetings/{id}/respond` must work without exposing who responded for anon concerns.
- **Feeds Health & Safety:** `category=safety` concerns roll up into the H&S report (Slice 25).

**Frontend**
- HR: `/hr/requests/concern` — queue (category, date, status, Anonymous/Named badge), detail (identity hidden for anon), status transitions, **"Schedule meeting"** action.
- Employee: **My Concerns** (submit named/anonymous; see status; see & respond to meeting invites).

**Done when:** an anonymous concern shows **no submitter identity anywhere** on the HR side; HR schedules a meeting against its reference number; the anonymous submitter sees and accepts the invite from My Concerns without being unmasked; a safety concern appears in the H&S feed.

---

### Slice 22 — Payroll v2: attendance-connected + editable contributions + privacy toggle  *(Revision of Slice 9)* 🔗#3

> **BUILT 2026-07-08.** `PayrollService` is now **attendance-connected**: `run()` counts explicit `absent` days in the cycle from `hr_attendance` and deducts them at the daily rate (`base/22`) — verified (Carlo's 2 absences → base 52000 → 47272.72). **Contributions are config- + checklist-driven**: `computePayslip` pulls each employee's enrolled contributions from `ContributionService::effectiveFor` (Slice 13) at the Company→Benefits rates — verified opting an employee out of Pag-IBIG zeroes it on their payslip. New `payslipFor()` helper shared by the run + the demo seeder. **Mark as Done** per payslip (`POST /hr/payslips/{id}/mark-done` → status `done`, shown per breakdown row). Payslip exposes **`processed_by`** (the HR who ran it) for the **signature block** on the printable employee payslip (HR name + employee signature lines). **Privacy toggle** on the HR payroll page masks every amount (StatCards, breakdown, history) banking-app style. Withholding tax stays the simplified TRAIN table; SSS remains the placeholder rate 🔗#3. **Deferred (CONFIRM §14.2):** the **reimbursement-claims tab** relocation into the Payroll page — reimbursement stays its own Finance nav child for now; fold it in once the user confirms the tab-vs-standalone preference.

**Goal:** upgrade the §5.9 payroll engine to the brief §6.1 — computation driven by **actual attendance**, contribution formulas pulled from **Company > Benefits** config, a **privacy toggle**, per-row **Mark as Done**, and payslip export with signature space.

**Backend**
- **Attendance-connected computation:** `PayrollService::run` now derives worked days / absences / half-days from `hr_attendance` over the cycle (source of truth), computing **daily and half-day rates** and **deducting absences** — replacing flat base pay. Keep the cycle guard.
- **Editable contributions:** deductions read from `ContributionService::for($employee)` (Slice 13) + the per-employee **benefits checklist** (so not all employees get all contributions). SSS from the table 🔗#3 (fallback = the §5.9 simplified formula); PhilHealth 2.5%; Pag-IBIG 2%. Withholding tax still future/optional.
- **Payslip:** breakdown of deductions **and benefits**; exportable by the employee; includes **HR name + a signature space** (extend the print/PDF template). Per-employee **Mark as Done** on the run table.

**Frontend**
- `/hr/finance/payroll` — the run engine + **privacy toggle** (an eye icon that masks amounts on screen, banking-app style) + Mark-as-Done per row + the reimbursement-claims tab (§14.2 relocation).
- Employee `/payslips` — breakdown incl. benefits + signature space + export.

**Done when:** running payroll deducts real absences from attendance and pulls each employee's enrolled contributions; the privacy toggle masks all amounts; a payslip exports with HR name + signature space.

---

### Slice 23 — Office Expenses  *(New)*

> **BUILT 2026-07-08.** `hr_office_expenses` (category, amount, date, description, vendor, receipt, status `recorded → approved | rejected`, recorder/reviewer). Full CRUD + approve/reject (reject via ReviewDialog), receipt upload reusing the reimbursement `Storage` pattern, audit-logged. Verified record→approve. **Frontend:** `/hr/finance/office-expenses` — summary tiles (approved spend / entries / awaiting) + table (category, vendor, ₱ amount, date, receipt link, status) + record/edit dialog (with receipt file) + approve/reject/delete row actions.

**Goal:** track company/office spending (brief §6.2) under Finance.

**Backend**
- `hr_office_expenses` (category, amount, date, description, vendor, receipt, status `recorded → approved | rejected`). CRUD + approve/reject (validators). Receipt upload reuses the reimbursement `Storage` pattern (§5.5e). Audit-logged.

**Frontend**
- `/hr/finance/office-expenses` — table (category, amount ₱, date, vendor, status) + add/edit dialog + approve/reject. Peso formatting per §5.5e.

**Done when:** HR records an office expense with a receipt and approves it; it appears with the correct status and formats as ₱.

---

### Slice 24 — FAQ / Knowledge Base  *(New)*

> **BUILT 2026-07-08.** `hr_faqs` (question, answer, category, tags json, status `draft/published/archived`, `is_pinned`, `view_count`, `updated_by`). CRUD + publish/archive/pin; `GET /hr/faqs` is **role-scoped** (HR sees all incl. drafts, employees see published only, pinned first) with a `?q=` search over question/answer/category/tags; `POST /hr/faqs/{id}/view` increments the count (published only). Verified role-scoping, search, and view-count increment (42→43). **Frontend:** HR `/hr/company/faq` (table + form dialog + publish/archive/pin/edit/delete) and employee **`/faq`** (search + category chips + expandable accordion, pinned first, registers a view on first open; new "FAQ" employee nav item). **Insights auto-suggest-draft deferred** (optional per §14.7) — only `view_count` is tracked.

**Goal:** the self-service FAQ library (brief §9.6) under Company; HR authors, employees read.

**Backend**
- `hr_faqs` (question, answer, category, tags, status `draft/published/archived`, `is_pinned`, `view_count`, `updated_by`). CRUD + publish/archive/pin. Employee endpoint returns **published only**; `POST /faqs/{id}/view` increments `view_count`. Search matches question/tags/category. **Insights (optional):** `hr_faq_events` for most-searched/most-viewed; auto-suggest a draft when a payroll-inquiry topic repeats N times — **mark optional/PROVISIONAL.**

**Frontend**
- HR `/hr/company/faq` — manage entries (create/edit/categorize/publish/archive/pin), show last-updated + editor. Employee `/faq` — read-only, searchable by category/tags, pinned first.

**Done when:** HR publishes a categorized, pinned FAQ; an employee searches and reads it (read-only); the view count increments.

---

### Slice 25 — Audit logging + Reports v2  *(Aggregation pass)* 🔗#4,#5

> **BUILT 2026-07-08.** **Audit logging** finalized: `hr_audit_logs` (built Slice 19) now also captures **logins** (a `Login` event listener in `HrServiceProvider` → `auth.login`) alongside the change/document/payslip/inquiry/concern/office-expense writes — verified a login lands in the trail. **Reports v2:** the index is now the **8-card set** (Headcount & Movement, Recruitment Funnel, Absence & Leaves, **Compensation & Benefits** [new], Attrition & Tenure, Diversity & Pay Equity, **Health & Safety** [new], **Audit Trail** [new]) — the old standalone "Payroll & Cost" card is folded into Compensation (its endpoint still resolves). New `ReportService` methods: `compensation` (gross-to-net + **real benefits coverage** from `hr_employee_benefits`), `health_safety` (**safety concerns, fully anonymized — no submitter identity ever emitted**, verified no leak), `audit` (who/what/when + `?action`/`?from`/`?to` filters). Fixed the recruitment funnel to group by the actual (pipeline-specific) stages. **Report history/export:** `hr_report_exports` + `POST /hr/reports/{key}/export` logs each export; every report carries its export history (shown on the detail page). **Frontend:** detail page gained an **Export** button (logs + `window.print()`), an **export-history** list, and a **restricted** lock badge on the Audit Trail. **Deferred/flagged:** audit before/after values are **stored** but the trail table shows action+target only (not the JSON diff); access is gated by `role:hr_officer` (this MVP has no distinct "HR leadership" role — noted in the report); H&S exact metrics 🔗#4 + report export formats 🔗#5 pending Miss Chris.

**Goal:** wire the cross-cutting audit log (§14.3c) and expand Reports from 6 to the brief's 8 + report history.

**Backend**
- **Audit logging:** finalize `hr_audit_logs` writes across mutating services + model observers (created/updated/deleted, approvals, logins, exports, before/after). Access = admin/HR-leadership.
- **Reports v2** — add report keys to `ReportService`/`GET /hr/reports/{key}`:
  - `compensation` **(real)** — gross-to-net, cost-per-head, benefits coverage, payroll-run history (from `hr_payroll_runs`/`hr_payslips`).
  - `health_safety` **(feed-real, layout `CONFIRM` 🔗#4)** — safety-category concerns/incidents from Slice 21; anonymized.
  - `audit` **(real)** — the Audit Trail: who/what/when, before/after, filterable by user/action/module/date; **admin/HR-leadership only.**
  - Existing `headcount/attrition/leave/payroll/recruitment/diversity` unchanged; add **movement** (joiners/leavers/transfers) to headcount.
  - **Report history:** every export logs to `hr_report_exports` (`GET /hr/reports/{key}/export`) — a "report history" list per report (brief §8 note). Formats 🔗#5.
- **Access control:** Diversity & Pay Equity + Audit Trail restricted to HR leadership/admin (policy, not just UI).

**Frontend**
- `/hr/reports` — 8 cards now; each detail page adds its panels (plain-flex charts per §1.5 / inline-SVG where a trend line is needed, per §5.8's chart note) + a **report-history** table + **Export** action. Label mock sections explicitly.

**Done when:** the audit log records a real before/after for an approval and a login; Reports shows 8 report types incl. Compensation, Health & Safety (fed by safety concerns), and an access-restricted Audit Trail with export history.

---

> **Phase-2 completion check:** the sidebar matches the brief's 7-group IA; every table carries an explicit status/process column with a badge; approvals everywhere go through the centralized Y/N + optional-reason validators; the four Request queues, the Company config hub, Office Expenses, and the expanded Reports all work end-to-end on seeded demo data; anonymity holds on the Concerns path; and every Company edit + approval is captured in the audit log.

> **STATUS 2026-07-08 — Phase 2 core complete.** Slices **12–25 BUILT** (each verified: `tsc`/ESLint/PHP-lint clean, `migrate:fresh --seed` clean, endpoints curl-tested, pages render). **NOT built: the Slice 16 off-site geofence/selfie** flow (its own `HRIS-Geofenced-Attendance-Plan.md`). Cross-cutting **global search** (§14.3d) and **broader model-observer audit coverage** remain light (login + approvals are logged). Open per-slice deferrals are flagged in each slice's BUILT note (reimbursement-under-payroll tab, FAQ insights, audit before/after display, holiday live-preview, H&S/report formats pending Miss Chris).
