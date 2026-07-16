# Payroll Audit & Remediation Plan

> Audit of the payroll feature — **HR side** (run engine, schedule math, contributions, schema, run UI, audit logging) **and employee side** (payslips, Next Payday). Findings are ordered by priority. Work them **one at a time, top-down**; check the box and note the commit when each is done.
>
> **2026-07-14 pass** added P1-8 (audit logging of runs — ✅ done), P1-9 (employee Next Payday), and P2-6…P2-9 (HR past-run drill-down, HR export & payslip print, reimbursement reconciliation, mark-disbursed cache). See the updated *Suggested execution order*.
>
> Key files: `backend/app/Modules/Hr/Services/PayrollService.php`, `PayScheduleService.php`,
> `ContributionService.php`, `Http/Controllers/PayrollController.php`, `Http/Requests/RunPayrollRequest.php`,
> `frontend/src/app/hr/finance/payroll/page.tsx`, `frontend/src/app/(employee)/payslips/page.tsx`,
> `frontend/src/app/(employee)/page.tsx`, `frontend/src/modules/hr/hooks/use-payroll.ts`.

---

## Priority legend

| Tier | Meaning |
|---|---|
| **P0** | Real bug — produces wrong data or loses work. Fix before any demo. |
| **P1** | Real-world correctness gap — configured data is ignored or pay is wrong. |
| **P2** | Missing capability / scope decision — flag with user, may defer. |

---

## P0 — Real bugs (fix first)

### [x] P0-1 · Re-running a period wipes HR's "Mark as done" progress ✅ DONE (2026-07-10)
> Fixed: re-run now captures prior per-employee statuses and recomputes via `updateOrCreate`, so `done` marks survive; stale payslips for employees who left the group are pruned. Verified in tinker (status preserved, no orphans).
**Where:** `PayrollService::run()` — `PayrollService.php:210` (`$run->payslips()->forceDelete()`), recreated with `'status' => 'paid'` at `:250`.
**Problem:** Re-run hard-deletes all payslips and recreates them as `paid`, discarding every `done` mark HR set via `markDone()`. The UI actively invites re-running ("Re-run / refresh with the latest salaries, attendance and reimbursements"), so this is a routine data-loss path.
**Failure scenario:** Run → mark 20/50 done → edit a salary → re-run → all 20 "done" marks gone, no warning.
**Recommended action:**
- On re-run, preserve per-employee `status` by keying on `employee_id` (capture old statuses before delete, re-apply after recompute), **or**
- Switch re-run to an in-place `updateOrCreate` per payslip (update figures, keep `status`), **or**
- Block re-run once any payslip in the run is `done` and require an explicit "reopen".
- Add a line to the confirm dialog stating what happens to already-done payslips.

### [x] P0-2 · No uniqueness guard → duplicate runs for the same period ✅ DONE (2026-07-10)
> Fixed: unique index `hr_payroll_runs_period_unique` on `(cycle_start, cycle_end, frequency)` + `lockForUpdate()` on the reuse lookup + catch `UniqueConstraintViolationException` on the create race. Verified the DB rejects a duplicate insert.
**Where:** migration `2026_07_03_000008_create_hr_payroll_runs_table.php:11` (no unique index); `run()` does `first()`-then-`create()` with no lock (`PayrollService.php:204-219`).
**Problem:** Two concurrent runs (two officers, or a double-click that beats `runMut.isPending`) both see "no existing run" and both create one → duplicate run + duplicate payslip set + doubled history/totals.
**Recommended action:**
- Add a unique index on `hr_payroll_runs (cycle_start, cycle_end, frequency)` (partial/scoped to non-soft-deleted).
- Use `lockForUpdate()` on the existing-run lookup, or `updateOrCreate`, inside the transaction.
- Handle the unique-violation as a friendly 409/422 in the controller.

### [x] P0-3 · Payslips are auto-visible; the request→approve flow gates nothing ✅ DONE (2026-07-10)
> **Implemented & verified over HTTP.** Payslips now stay private until an approved payslip
> request unlocks them. Steps 1–4 all complete:
> - **Backend gate:** `showPayslip` 403s a locked payslip; `myPayslips` returns a no-amounts
>   summary (`PayslipSummaryResource`) with derived `request_status` + `unlocked`
>   (`PayrollService::myPayslips` / `isUnlocked`). Unlock is derived from an approved request — no migration.
> - **Request hardening:** `payslip_id` required + must be the caller's own; duplicate pending/approved
>   request blocked (`StorePayslipRequestRequest`).
> - **Frontend:** `(employee)/payslips` shows a locked history (periods only) with per-row
>   Request / Pending / View; detail + Download PDF + YTD render only when unlocked.
> - **Seed:** `RequestsDemoSeeder` now approves one older payslip so the demo has a viewable one.
> - **Verified:** locked → 403; unlocked → 200 w/ figures; request→HR approve→unlock works;
>   duplicate/ownership/missing-id all 422. Frontend `tsc --noEmit` clean.
> **Reframed 2026-07-10 per product decision:** employees should NOT automatically see their
> payslip after a payroll run. They must file a **payslip request**; HR approves it, and only
> then can the employee view/download that payslip. The request→approve flow already exists —
> it just isn't wired to actually gate access.

**Current state (verified read-through):**
- `GET /me/payslips` (`PayrollController::myPayslips`) returns **all** of the caller's payslips with full amounts. **← the leak.**
- `GET /me/payslips/{payslip}` (`showPayslip`) returns one own payslip + YTD; ownership is the *only* check.
- Employee page `frontend/src/app/(employee)/payslips/page.tsx` lists every payslip, shows the full breakdown + YTD, and offers **Download PDF** (`window.print()`) — all with no approval.
- `POST /me/payslip-requests` (`PayslipRequestService::create`) records a request: required `reason`, optional `payslip_id` (nullable FK → `hr_payslips`), `status='pending'`.
- HR `approve()` / `reject()` only flip `status` + write an audit log (`PayslipRequestService.php:37-66`). **Approval currently unlocks nothing** — the payslip was always visible.
- So today the whole request flow is a value-add ("official copy for a bank loan"), not an access gate.

**Problem:** The intended gate doesn't exist. Payslip content and the PDF are freely reachable the moment payroll is computed, regardless of any request.

**Recommended direction (to be confirmed / dictated step by step):**
- Restrict the `me/payslips` endpoints so an employee can only view/download a payslip **once an approved `PayslipRequest` exists for it** (join on `payslip_id`, `status='approved'`, own `employee_id`).
- Give the employee page two states: (a) a list of *periods that exist* (cycle dates only, no amounts) with a "Request" action, and (b) full breakdown + download only for payslips with an approved request.
- Make the request able to reference a period even when the employee can't see the payslip yet (today the UI picks `payslip_id` from a list it can already see — that assumption breaks once the list is hidden).

**Decisions (answered 2026-07-10):**
1. **List visibility:** ✅ Periods only — employee sees the list of pay periods that exist (cycle dates + status), **no amounts**, until approved.
2. **Request target:** ✅ Pick a specific period — request is tied to a specific `payslip_id` (FK already exists); HR approves that exact one.
3. **Access model:** ✅ Permanent unlock — approval unlocks that payslip for the employee forever (no expiry, no re-request).
4. **PDF + YTD:** ✅ Gate everything together — view, Download PDF, and YTD all unlock on approval; nothing about the payslip is visible before.
6. **Run-immutability:** collapses into this gate — with HR-approved access, a re-run rewriting figures is a minor concern; original completed-run locking deferred/dropped.

> **"Unlocked" is derived, not stored:** a payslip is unlocked ⇔ an approved `PayslipRequest`
> exists for `(employee_id, payslip_id)`. No new column/migration needed — permanent unlock =
> the approved request persists.

**Implementation plan (do step by step, commit each):**
- **Step 1 — Backend access gate:**
  - Add a way to resolve an employee's unlocked payslip IDs (approved requests).
  - `showPayslip` → 403 unless that payslip is unlocked for the caller.
  - Split resources: a **summary list resource** for `myPayslips` (cycle dates, status, `request_status`, `unlocked` — **no amounts**); keep the full `PayslipResource` for the gated detail.
- **Step 2 — Backend request hardening:**
  - `payslip_id` becomes **required** and must belong to the caller (own payslip).
  - Block a duplicate request when one is already `pending` or `approved` for that payslip.
- **Step 3 — Frontend `(employee)/payslips` page:**
  - History list shows periods + status + per-row action: **Request** (none) / **Pending** / **View** (approved).
  - Detail pane, Download PDF, and YTD render only for an unlocked payslip; locked → "Request to view" CTA.
  - Update types/hooks (`use-payroll`, `types/payroll`).
- **Step 4 — Demo seed + verify:**
  - Seed one approved request so the demo shows an unlocked payslip (rest locked).
  - Verify end-to-end (locked list → request → HR approve → unlocked view/PDF/YTD).

---

## P1 — Real-world correctness gaps

### [x] P1-1 · Allowances are configured but never paid ✅ DONE (2026-07-10)
> Fixed: monthly allowances (food + transport + load) are spread per period and added to gross
> as a non-taxable de-minimis line (excluded from the taxable base). New `allowances` column on
> `hr_payslips`, surfaced in `PayslipResource` + the employee payslip Earnings section. Verified:
> gross rises by the per-period allowance, withholding tax unchanged, net rises by the same.
> Not absence-prorated (fixed benefit) — noted in code.
**Where:** `hr_employees.food_allowance / transportation_allowance / load_allowance` (`2026_07_09_000060_add_compensation_details.php`); gross only uses `basePay + reimb` (`PayrollService.php:98`).
**Problem:** Employees with allowances are underpaid vs. what their profile shows. HR enters numbers that do nothing.
**Recommended action:** Fold allowances into gross with correct tax treatment (de-minimis / non-taxable portions separated from taxable), add them as payslip line items, **or** remove them from the comp UI if out of scope. **Decision needed:** which allowances are taxable.

### [x] P1-2 · Per-employee statutory amounts are dead fields ✅ DONE (2026-07-10)
> Fixed: a per-employee `sss_contribution` / `philhealth_contribution` / `pagibig_contribution`
> now overrides the company-config computation — but only for contributions the employee is
> enrolled in (enrollment still gates; the override just sets the amount). New
> `monthlyContribution()` helper in `PayrollService`. Verified: override 999 → payslip sss 499.5
> (×timing), null override → config-computed 675.
**Where:** `hr_employees.sss_contribution / pagibig_contribution / philhealth_contribution` (seeded), but payroll recomputes from company-wide `ContributionService::effectiveFor()` and never reads them.
**Problem:** HR overriding an employee's SSS on their profile has zero effect on the payslip — violates the group-default + per-person-override pattern used elsewhere (see leave-credits design).
**Recommended action:** Make per-employee amounts an **override** of the company config when present (fall back to computed rate otherwise), mirroring the leave-credits override pattern. **Decision needed:** confirm override semantics.

### [x] P1-3 · Absence model is too narrow — several pay leaks ✅ DONE 2026-07-10 (A/B/C/E done; D deferred)
**Where:** proration counted only `status='absent'` and divided by a global Mon–Fri count.
**Problems:** missing record = free pay · unpaid leave not deducted · tardiness/undertime/half-day ignored.

**Existing building blocks (already built, now partly wired in):** per-day `present/late/absent`,
lateness vs effective schedule + grace, net `hours_worked`, the roster day-classifier in
`AttendanceService::allForDate`, per-employee `ScheduleService::effectiveFor`, and the
`ViolationService` lates→absence policy. One data-model gap: **no paid/unpaid flag on leave types** (no LWOP).

- **[x] Step A — real unpaid-day count.** New `AttendanceService::unpaidDaysFor()` mirrors the roster
  classifier: over the employee's scheduled workdays, an explicit `absent` row OR a past workday with
  no clock-in and no approved leave = unpaid; approved (paid) leave / present / late / rest / future = paid.
  Payroll now calls it instead of the naive `absent` count. **Fixes the missing-record leak.**
- **[x] Step B — per-employee daily-rate divisor.** New `AttendanceService::scheduledWorkdaysFor()`
  replaces the global `PayScheduleService::workingDays()` so the daily rate matches the employee's own
  schedule. `PayrollService::run()` computes both per employee inside the loop.
  **Verified:** 11 sched days, 2 unpaid → base drops exactly 2×dailyRate; leave-covered missing day not charged.
- **[x] Step C — tardiness → unpaid** ✅ DONE (2026-07-10). Added a dedicated, configurable
  **payroll tardiness policy** on `hr_attendance_policies` (`late_deduction_mode` `none`|`per_lates`,
  `lates_per_deduction` N), editable in **Company → Rules** ("Tardiness pay deduction"). Kept SEPARATE
  from the violation-flag thresholds so HR tunes the colour ladder and the pay penalty independently;
  default `none` (no behaviour change until HR opts in). `unpaidDaysFor()` adds `floor(lates / N)` days;
  a late day itself stays paid (no double-count). Verified: none→0, N=3→floor(10/3)=3, N=5→2; endpoint
  round-trips + 422 on bad mode; frontend `tsc` clean.
- **[deferred] Step D — undertime from `hours_worked`** — deferred by decision (least standard, most edge cases).
- **[x] Step E — unpaid leave (LWOP) + half-day** ✅ DONE (2026-07-10). Added `is_paid` to
  `hr_leave_types` (default true) + an **"Unpaid Leave (LWOP)"** seeded type; editable via the leave-type
  dialog (Paid-leave checkbox) with a Paid/Unpaid column in Company → Leave Type. `unpaidDaysFor()` now
  returns a **float**: an approved leave day is deducted only if its type is unpaid — a full day, or
  **0.5 for an approved half-day**; paid leave still deducts nothing. `payslipFor()` takes a float
  `unpaidDays`. **Verified end-to-end:** paid leave→0, unpaid full→1 day (base −1×dailyRate), unpaid
  half→0.5 (base −0.5×dailyRate); endpoint round-trips `is_paid`; `tsc` clean. (One-leave-per-day is a
  domain invariant; overlapping approved leaves aren't expected.)
  - **Follow-up fix (same day):** unpaid leave was **un-requestable** — `LeaveService::create` gated every
    request on a credit balance, and LWOP has none (blocked with "exceeds your remaining balance of 0").
    Now an **unpaid** type skips the balance requirement on create and never touches `used_days` on approve
    (it's deducted from pay, not credits); paid leave is unchanged. Frontend `LeaveRequestForm` drops the
    balance gate for unpaid types and shows "Unpaid leave · N day(s) will be deducted from your pay". So an
    employee requests a half-day unpaid via **Request Leave → Unpaid Leave (LWOP) + half-day**. Verified:
    unpaid half-day creates (days=0.5) & approves without consuming credits; paid over-balance still 422.

- **[x] Policy change — half-days are a SEPARATE, always-unpaid request** ✅ DONE (2026-07-10).
  Per product decision: **no paid half-days** (you can't consume half a leave credit). So half-day was
  removed from the leave flow (leave = full days only; `LeaveService`/`StoreLeaveRequest`/`LeaveRequestForm`
  updated) and reborn as its own entity **`hr_half_day_requests`** (model, migration, service, request,
  resource, controller, routes) with an approval flow mirroring leave. Employee files it (date + AM/PM +
  reason) on the Time Off page; HR approves/rejects in a new section on `/hr/requests/leave`; notifications
  + nav badge wired. Payroll: `unpaidDaysFor()` deducts **0.5 per approved half-day request** (AM+PM on one
  day → 1.0); unpaid *leave* is now full-day only (1.0). **Verified end-to-end (HTTP + payroll):** file→pending
  (no deduction), approve→0.5, AM+PM→1.0, duplicate→422, leave half-day ignored (days=1), HR queue + notifs
  + employee list all correct; `tsc` clean; demo seed adds one pending half-day.

**P1-3 net result:** payroll proration now reads the real per-employee attendance/leave picture —
absences, missing records, tardiness (policy-driven), full-day unpaid leave, and separate unpaid
half-day requests. Only undertime (D) is deferred.

### [x] P1-4 · Net pay can go negative; full monthly deductions on a prorated paycheck ✅ DONE (2026-07-10)
> Fixed with the PH-correct split: **statutory contributions stay on the full monthly salary** (SSS/PhilHealth/
> Pag-IBIG are bracket-based on the monthly salary credit) while **withholding tax now tracks compensation
> actually earned** — computed on `taxableMonthly = basePay × periodsPerYear ÷ 12`, which equals the full salary
> when there are no unpaid days (so normal periods are unchanged) and scales down only when pay is reduced (no
> over-withholding a low-earning period). `net_pay` is **floored at 0** — never a negative payslip. Uses TRAIN
> graduated rates on (comp − mandatory contributions), reimbursements + de-minimis allowances excluded.
> **Verified:** normal period tax unchanged (15687.5); heavy absence → tax scales down / 0 below threshold;
> contributions constant; net ≥ 0 in all cases; seeders clean.
**Where:** base prorated at `PayrollService.php:51`; contributions/tax computed on full monthly `contribBase` (`:82-84`, `:93`); `net_pay` unclamped (`:110`).
**Problem:** Heavily-absent employee gets a tiny base but full statutory hit → negative payslip is issuable.
**Recommended action:** Compute statutory deductions/tax on actual periodic pay (or apply thresholds), floor `net_pay` at 0, and carry/track any shortfall. **Decision needed:** deduction basis (full salary vs actual pay).

### [x] P1-5 · `run` accepts arbitrary/unaligned cycle dates ✅ DONE (2026-07-10)
> Fixed: `RunPayrollRequest::withValidator` now rejects (422) any cycle whose `(start,end)` isn't a real
> period for the frequency (new `PayScheduleService::isAlignedPeriod`) and any period that hasn't started
> yet (future). Verified: aligned past period→201, misaligned dates→422, 2027 future→422.
**Where:** `RunPayrollRequest.php:24` only checks `cycle_end >= cycle_start`.
**Problem:** API will compute runs for arbitrary, future, or non-period-aligned date ranges (UI sends good dates, but the endpoint is the real boundary).
**Recommended action:** Validate server-side that `(cycle_start, cycle_end)` matches a real period for `frequency` (via `PayScheduleService`), and reject future periods. Return 422 with a clear message.

### [x] P1-6 · Misleading status vocabulary ✅ DONE (2026-07-10)
> Fixed: payslips are born **`computed`** (was `paid`) and become **`disbursed`** (was `done`) when HR marks
> them paid out — reads forward, matches the "computation only, HR disburses" model. Updated `PayrollService`
> (`markDone`→disbursed, run default→computed), `PayrollDemoSeeder`, the HR payroll page ("Mark as disbursed" /
> "Disbursed"), and `StatusBadge` (computed=blue/in-flight, disbursed=green). Re-run still preserves a
> disbursed payslip. `tsc` clean; seeded statuses = disbursed.
**Where:** payslips born `'paid'` (`PayrollService.php:250`) while the UI banner says "no money moves"; `markDone` sets `'done'`.
**Problem:** `paid → done` reads backwards and contradicts the "computation only" model.
**Recommended action:** Rename lifecycle to `computed → reviewed → disbursed` (or similar); update seeder, resource, UI badges. Pairs with P0-3.

### [x] P1-7 · Changing an employee's `pay_frequency` after a run → double pay ✅ DONE (2026-07-10)
> Fixed: `run()` skips any employee who already has a payslip in ANOTHER run whose period overlaps this cycle
> (the pay_frequency-change case), and the run's cleanup drops such skipped payslips so a re-run leaves no
> orphan/double-pay. Verified: monthly Jun→included; overlapping semi Jun16-30→skipped; non-overlapping
> Jul→included.
**Where:** employees grouped by effective frequency at run time (`PayrollService.php:225`).
**Problem:** Run semi-monthly → switch someone to monthly → run monthly for overlapping span → paid twice for overlapping days; old payslip orphaned.
**Recommended action:** Detect overlapping *paid* periods per employee and block/warn; or snapshot each employee's frequency onto the payslip and prevent overlapping paid coverage.

### [x] P1-8 · Payroll runs weren't audit-logged — no record of who ran a cycle ✅ DONE (2026-07-14)
> Fixed: `PayrollService::run()` now writes an `AuditService` entry — action `created` on a first run, `updated` on a re-run — capturing the runner, cycle, employee count and net (e.g. *"Ran Monthly payroll for 2026-07-01 – 2026-07-31 — 12 employee(s), net ₱…"*), attached to the `PayrollRun`. Verified in tinker (new `Payroll` audit row, correct action/user/description); surfaces in the Audit Trail report.
**Where:** `PayrollService::run()` — no `AuditService` call; `AuditService` was injected into other services but not payroll.
**Problem:** Running/re-running a pay cycle is the single most consequential HR action, yet nothing recorded *who* did it or *when*. Only downstream payroll *inquiries* / *payslip requests* were logged, never the run itself — an audit-trail hole.
**Also this session:** HR **Run History** moved from a bottom page section to a header **"Show run history"** button + modal on `/hr/finance/payroll` (cosmetic; unrelated to the audit gap).

### [x] P1-9 · Employee "Next Payday" card is hard-coded ✅ DONE (2026-07-14)
> Fixed: the card now shows the employee's **real** next pay date for their own pay group. New employee-accessible endpoint `GET /hr/me/next-payday` (the HR `payroll/settings` route is HR-only, so it couldn't be reused) resolves the caller's frequency from the token (`employee->pay_frequency` ?: company default) and computes the date via new `PayScheduleService::nextPayDate()` — today's period if its pay date hasn't passed, else the next period's. Frontend: `useMyNextPayday` hook → the card shows the date + the frequency label; the fake bank string is gone. Verified in tinker (semi-monthly → 2026-07-15, rolls forward once passed); route registered; `tsc` clean.
**Where:** `frontend/src/app/(employee)/page.tsx` — was a literal `<StatCard label="Next Payday" value="Jul 15" subNote="Semi-monthly · BPI ••••4471" />`.
**Problem:** Every employee saw the same fake date + fake bank account, regardless of the real pay schedule. Inconsistent with the HR dashboard's **"Next Payroll"** stat, which is already real (`DashboardService::nextPayrollDate()`).
**Note:** couldn't reuse `usePayrollSettings().current_period.pay_date` — that endpoint is `role:hr_officer`-gated; added the employee-safe `me/next-payday` instead (also correct per-employee, not company-wide). Supersedes the §6 "Next Payday" mock note in the build plan.

---

## P2 — Missing capabilities / scope decisions (flag, may defer)

### [x] P2-1 · No 13th-month pay ✅ DONE (2026-07-14)
> **Rule implemented:** PH 13th-month = total **basic** salary earned in the calendar year ÷ 12. Reads the `base_salary` already stored on each payslip (attendance-adjusted basic pay, excludes allowances/reimbursements) — a live computation over existing data, **no new table**.
>
> **What was built:**
> - **Backend**
>   - `PayrollService::thirteenthMonth($year)` — per-employee list (name, department, periods paid, YTD basic, 13th-month). Payslip-driven, so it includes anyone paid that year even if since separated.
>   - `PayrollService::ytd()` now also returns `thirteenth_month` (the individual's accrual), folded into the existing YTD payload.
>   - `GET /hr/payroll/thirteenth-month?year=` — HR-only endpoint (defaults to current year), `{ data: [...], meta: { year } }`.
> - **Employee side** — a **"13th-Month Pay (accrued)"** callout under the payslip YTD box (`(employee)/payslips`), with a "finalizes at year-end" note; prints with the payslip.
> - **HR side** — a **"13th-month"** button in the `/hr/finance/payroll` header opens a **modal**: per-employee table (periods paid, YTD basic, 13th-month) + a **total accrued** strip. Honors the existing privacy mask (eye toggle) and shows a friendly empty state before any runs exist.
> - **Reuse:** modal mirrors the Run History one; shared `DataTable`; money masking via the page's `peso()` helper.
>
> **Verified:** tinker (14 employees; e.g. YTD basic 105,454.56 → 8,787.88; employee YTD payload carries `thirteenth_month`), route registered, `tsc --noEmit` clean.
Legally mandated in PH. **Was:** nothing accrued or computed it.
**Suggested approach (existing system):** We already store per-run **`base_salary`** on `hr_payslips` — the attendance-adjusted *basic* pay, excluding allowances/reimbursements, which is exactly the PH "basic salary earned" basis — and `PayrollService::ytd()` already sums a year's payslips. So this is a **computed read over existing data, not a new run type**: add `thirteenthMonthFor(Employee, year)` = `Σ payslip.base_salary for the year ÷ 12` (auto-handles mid-year hires and unpaid days since it sums what was actually earned). Surface it (a) as a read-only figure in the employee payslip **YTD** box, and (b) an HR **"13th-Month"** view under Payroll (per-employee list, generated Nov–Dec) reusing the `DataTable` + the existing privacy toggle. No schema change.

### [~] P2-2 · No overtime / holiday pay / night differential — **holiday premium DONE (2026-07-14); OT/night-diff deferred**
> **Scope built: holiday premium** (the cheapest cut, per the suggested approach — the holiday calendar already feeds payroll). **OT + night differential remain deferred** — they need captured/approved OT hours and an OT-request flow that doesn't exist yet.
>
> **What was built:**
> - **New payslip line `holiday_premium`** (migration `2026_07_14_000002`, `hr_payslips.holiday_premium` decimal(12,2); added to `Payslip` fillable/casts + `PayslipResource`). Folded into **gross** in `computePayslip()`.
> - **Pricing** (`PayrollService::holidayPremiumFor`): for each holiday in the cycle where the employee has a **present/late** attendance record, add `dailyRate ×` the statutory premium **on top of** the normal day — **regular +100%**, **special_non_working +30%**, `special_working` = normal day (0%). Uses new `HolidayService::typedHolidaysBetween()` (date→type, home country) + new `AttendanceService::workedDatesBetween()`. Holiday map fetched once per run.
> - **Surfaced:** employee payslip **Earnings** row + the DomPDF template (P2-4); a **Holiday Premium column** (+ TOTAL) in the run CSV (P2-7).
> - **Simplification (documented in code):** premium adds to gross/net but is **not** fed back into the withholding base (matches the rest of the simplified engine); flat daily-rate basis, no OT/night-diff stacking. SSS/tax tables unchanged.
>
> **Verified (tinker):** regular holiday worked → premium = daily rate (₱1,956.52, +100%); special_non_working → ×0.30 (₱586.96); not-worked → 0; CSV shows the new column; re-run after removing the holiday → premium back to 0. `tsc` clean.
>
> **Still deferred (own slice when required):** overtime pay, night differential — need an OT-hours capture/approval flow first.
**Was:** no premium pay of any kind; proration was deduction-only.
**Suggested approach (existing system):** Phase it, cheapest first. **Holiday premium is the low-hanging fruit** — the calendar already exists and is already consumed by payroll: `HolidayService::nonWorkingDatesBetween($start,$end)` + `hr_holidays` (which carries the holiday **type**). Step 1 (small): when an employee has an `hr_attendance` record on a holiday date, add a premium **gross line** at the statutory multiplier (regular holiday 2.0×, special non-working 1.3× the daily rate we already compute per employee). **OT + night differential need approved hours** — `hr_attendance.hours_worked` + clock times exist, but there's no OT-request/approval concept, so defer those until an OT-approval flow lands. Keep all of it **additive in `computePayslip()`** as new gross line items (mirrors how allowances were added in P1-1). Start holiday-only; treat OT/night-diff as a later slice.

### [x] P2-3 · No employer-share tracking ✅ DONE (2026-07-14)
> **What was built:**
> - **Config (existing JSON, no schema for rates):** added `employer_rate` (+ `employer_cap` for Pag-IBIG) alongside the employee `rate` in `hr_contribution_settings` — SSS **9.5%** (ee 4.5%), PhilHealth **2.5%** (50/50), Pag-IBIG **2%** capped ₱200. Seeded in `ContributionSettingSeeder`; migration `2026_07_14_000003` **backfills** existing installs.
> - **Compute:** `PayrollService::monthlyEmployerContribution()` + a generalized `contribution($config,$base,$rateKey,$capKey)` (shared salary floor/cap, side-specific rate/cap). Same enrolment + timing factor as the employee side. **Per-employee employee-side overrides do NOT affect the employer share** (statutory, config-driven) — documented.
> - **Store:** new `hr_payslips.employer_sss / employer_philhealth / employer_pagibig` (migration `2026_07_14_000003`; `Payslip` fillable/casts). **Not** added to `total_deductions` — **net pay is unaffected**.
> - **Surface:** Payroll & Cost report gained an **"Employer share (YTD)"** stat + a **"Government Remittance (YTD)"** table (Agency · Employee · Employer · Total, incl. WHT→BIR) — `ReportService::payroll()` + a new `remittance` table rendered on the report page. Run **CSV** gained Employer SSS/PhilHealth/Pag-IBIG columns + totals.
>
> **Verified (deterministic + live):** on a ₱20k base — SSS ee 900 / er 1,900 (ratio 2.111), PhilHealth ee 500 == er 500, Pag-IBIG ee 200 == er 200 (capped); `total_deductions` = employee side only (net unaffected); report remittance table + CSV columns populate; `tsc` clean.
>
> **Note:** employer rates are statutory and edited via the config JSON; a dedicated employer-rate field in the Company → Benefits UI wasn't added (rates rarely change; the config already accepts it). SSS remains the placeholder rate 🔗#3.
**Was:** only the employee contribution was computed; no employer figure for remittance.
**Suggested approach (existing system):** Contribution config is **already editable JSON per type** in `hr_contribution_settings` (Company → Benefits), read via `ContributionService::effectiveFor()`. Add an `employer_rate` (and any `employer_cap`) key **alongside** the existing employee `rate` in that same JSON, and compute the employer amount in parallel in `PayrollService::contribution()` — **config-driven, no schema change**, exactly like the employee side. Keep it **off** net pay (it's not an employee deduction); surface employer totals in the **payroll report**, which already aggregates per run (`ReportService::payroll()`), so remittance totals come "for free" from existing plumbing.

### [x] P2-4 · No payslip PDF / bank-file export ✅ DONE (2026-07-14)
> **Decision (user, 2026-07-14): dependencies allowed** → added **`barryvdh/laravel-dompdf` ^3.1** (the upgrade the build plan §0.5/§5.9 had reserved for CTO approval — now approved).
>
> **What was built:**
> - **Real payslip PDF (server-side DomPDF).** New Blade template `hr::payslip` (`app/Modules/Hr/Resources/views/payslip.blade.php`) — itemized earnings/deductions, net, YTD + **13th-month** box, and the HR/employee **signature block**. Rendered by `PayrollService::payslipPdf()`. Money is printed as `PHP …` (DomPDF's DejaVu Sans lacks the ₱ glyph). The Hr module now registers a `hr::` view namespace (`HrServiceProvider::loadViewsFrom`).
> - **Employee endpoint** `GET /hr/me/payslips/{payslip}/pdf` — the route Slice 9 reserved; **same gate as `showPayslip`** (owned AND unlocked by an approved request). The employee page's "Download PDF" now downloads this real file instead of `window.print()`.
> - **Bank/disbursement file** `GET /hr/payroll/runs/{run}/export` → **CSV** (one row per employee: basic→net with each statutory deduction) + a **TOTAL row that doubles as the remittance summary** (SSS/PhilHealth/Pag-IBIG/WHT column totals). Audit-logged (`exported`/`Payroll`). See P2-7 for the UI.
>
> **Verified (HTTP, real logins):** employee unlocked → `200 application/pdf`; locked → `403`; HR-only PDF as employee → `403`; CSV → `200 text/csv` with `Content-Disposition` filename; `%PDF` magic confirmed. `tsc` clean; `fputcsv` deprecation cleared (explicit escape arg).
**Was:** "Download PDF" was `window.print()` (no real file), and there was no disbursement file for "HR disburses manually."
**Suggested approach (existing system):** Two separate deliverables. **(a) Real payslip PDF** — the print template already exists (`(employee)/payslips` `.print-area` + signature block); the upgrade is **DomPDF** (`barryvdh/laravel-dompdf`, needs §0.5 approval) rendering the *same* layout server-side at `GET /hr/me/payslips/{id}/pdf` (Slice 9 already reserved this route in §5.9). **(b) Bank/disbursement file** — a per-run **CSV** (employee, net_pay, bank ref) built from the run's payslips; reuse the Reports **export-logging pattern** (`ReportService::logExport` + the `reports/{key}/export` route) so every export is audit-logged. Overlaps **P2-7** — build them together.

### [x] P2-5 · Terminated/resigned employees would still be paid ✅ DONE (2026-07-14)
> **What was built:** `PayrollService::run()`'s employee query now filters to **active** employees only — `->whereNull('separation_type')` (skips voluntary/involuntary separations) plus a not-yet-hired guard `hire_date <= cycle_end` (a mid-cycle hire is still included and prorated; only someone hired *after* the period is excluded). Soft-deleted employees were already excluded. One query change, no schema/UI work.
> **Verified:** tinker — separating a monthly employee drops the guarded count 1→0, rollback restores it; a mid-cycle `hire_date` stays included; `php -l` clean.
**Was:** `run()` had no active-employee filter — latent today (everyone active), but would pay separated staff the moment offboarding lands.
**Suggested approach (existing system):** A **`separation_type`** column now exists on `hr_employees` (migration `2026_07_13_000003`; **null = active**, `voluntary`|`involuntary` = separated — added for attrition reporting), so the guard is concrete and one line: add **`->whereNull('separation_type')`** to the employee query in `run()` (records are already soft-deleted too, so that path is covered). Do it **now** — it's forward-safe and costs nothing while every employee is active. Optional hardening: also skip anyone whose `hire_date` falls after the cycle end.

### [x] P2-6 · HR can't inspect a past run's breakdown ✅ DONE (2026-07-14)
> **What was built:** a master→detail drill-down on `/hr/finance/payroll`. Run History rows are now **clickable** (`DataTable onRowClick`) and open a **Run breakdown** dialog for that run: a header line (cycle · schedule · employee count · when/who ran it), three total StatCards (gross / deductions / net), and the full **per-employee breakdown table** — reusing the existing breakdown `columns` (incl. per-row Mark-as-disbursed) and honoring the privacy mask. Closing the breakdown returns to the history list.
> **Reuse (backend untouched):** fetches via the existing `usePayrollRun(id)` → `GET /hr/payroll/runs/{run}` → `PayrollRunResource.breakdown` — the same data path the current period's breakdown already used, now pointed at any historical run. Pure UI wiring.
> **Verified:** `tsc --noEmit` clean; drill-down reuses the already-working current-run data path.
**Where:** `frontend/src/app/hr/finance/payroll/page.tsx` — the Run History modal previously showed only per-run totals; `detail` (`usePayrollRun`) was fetched only for the *current* period's run.
**Was:** HR couldn't see who got paid what in a prior cycle — no drill-down from history.

### [x] P2-7 · HR-side export & per-employee payslip print ✅ DONE (2026-07-14) — built with P2-4
> **What was built (HR UI on `/hr/finance/payroll`):**
> - **Run CSV export** — an **"Export CSV"** button on both the current-period breakdown header and the past-run breakdown dialog (P2-6), hitting `GET /hr/payroll/runs/{run}/export`. The file is the bank/disbursement file + remittance totals (see P2-4).
> - **Per-employee payslip PDF** — a **download (FileDown) action** on every breakdown row → `GET /hr/payslips/{payslip}/pdf` (HR-only), the same DomPDF template employees get, named `payslip-<employee>-<cycle>.pdf`. Solves "HR can't open/print an individual payslip."
> - Downloads use a shared `downloadFile()` blob helper (keeps the Sanctum auth header; reads the filename from `Content-Disposition`).
>
> **Verified (HTTP):** HR CSV `200 text/csv` (filename `payroll-2026-07-01-to-2026-07-31.csv`); HR payslip PDF `200 application/pdf` (`payslip-paolo-mendoza-2026-07-01.pdf`); `tsc` clean.
**Was:** the only print/export was the *employee's own* payslip; HR had no run export and couldn't open/print an individual employee's payslip.

### [x] P2-8 · Reimbursement→payroll reconciliation is manual ✅ DONE (2026-07-14)
> **Decision (user, 2026-07-14): auto-flip to `paid` on run.** Restores the build-plan §5.9 "reimbursement→payroll automation."
>
> **What was built:**
> - **New link column** `hr_reimbursements.payroll_run_id` (nullable FK → `hr_payroll_runs`, `nullOnDelete`; migration `2026_07_14_000001`) — records which run paid a claim. Added to the `Reimbursement` fillable.
> - **`PayrollService::run()`** now folds in claims that are **still `approved`** OR **already linked to this run** (`->where(status=approved OR payroll_run_id=run.id)`), then **auto-flips each folded claim to `paid` + links it to the run** (reviewer = the runner, idempotent on re-run). One summary audit line per run: *"Auto-paid N reimbursement(s) folded into <freq> payroll … ₱total"*.
> - **Why the link column:** it keeps a re-run **consistent** (a flipped claim is re-found via `payroll_run_id` and stays folded) and makes cross-run **double-pay impossible** (a claim paid by run A is neither `approved` nor linked to run B, so B ignores it). No frontend change — `paid` is an existing reimbursement status.
>
> **Verified (tinker):** approved claim → after run `status=paid`, `payroll_run_id=<run>`; **re-run** → still paid, still linked, exactly one claim linked (no double-add). Manual `markPaid` for claims outside any cycle is unaffected.
**Was:** `run()` folded approved reimbursements into gross but left them `approved` — re-includable on every re-run, and HR had to mark them paid separately with no link between the two. (Reimbursement-tab relocation CONFIRM in build-plan §14.2 is a separate, still-open UI question.)
**Suggested approach (existing system):** `ReimbursementService` already has the `approved → paid` transition (`markPaid()`, audit-logged), so the "auto-flip" option is just calling it inside `run()`'s transaction for the claims folded into a *completed* run — no new state. To also stop re-inclusion on a re-run, add `->where('status', 'approved')` (already there) **and** skip claims already `paid` so a recompute doesn't re-add a disbursed claim. Recommend **flip-on-completed-run** (single source of truth, reuses the existing paid state) over a separate manual step.

### [x] P2-9 · "Mark as disbursed" doesn't refresh Run History totals ✅ DONE (2026-07-14)
> **What was built:** `useMarkPayslipDone.onSuccess` now invalidates **both** the open run detail (`["hr","payroll","run"]`) **and** the runs list (`RUNS_KEY`), so Run History reflects the new disbursed status/totals immediately — no manual refetch. One-line cache fix. **Verified:** `tsc --noEmit` clean.
**Where:** `frontend/src/modules/hr/hooks/use-payroll.ts`.
**Was:** `useMarkPayslipDone` invalidated the run *detail* only, so after disbursing a payslip the Run History list could show stale status/totals until a refetch (cosmetic, low impact).

---

## Suggested execution order

**All P0 + P1-1…P1-9 are done (P1-1…P1-7 on 2026-07-10; P1-8, P1-9 on 2026-07-14).** Remaining open work, do-first order:

1. ~~**P1-9** — real employee "Next Payday".~~ ✅ **DONE (2026-07-14).**
2. ~~**P2-6** — viewable past-run breakdown for HR.~~ ✅ **DONE (2026-07-14).**
3. ~~**P2-5** — active-employee guard in `run()`.~~ ✅ **DONE (2026-07-14).**
4. ~~**P2-8** — reimbursement→payroll reconciliation (auto-flip on run).~~ ✅ **DONE (2026-07-14).**
5. ~~**P2-9** — invalidate the runs list on mark-disbursed.~~ ✅ **DONE (2026-07-14).**
6. ~~**P2-7 / P2-4** — HR run CSV export + per-employee payslip PDF + real employee payslip PDF (DomPDF).~~ ✅ **DONE (2026-07-14).**
7. ~~**P2-1** — 13th-month pay.~~ ✅ **DONE.** · **P2-2** — holiday premium ✅ **DONE (2026-07-14)**; OT/night-diff still deferred (need an OT-approval flow). · ~~**P2-3** — employer-share remittance.~~ ✅ **DONE (2026-07-14).**

**Only remaining open item in the whole audit:** P2-2's **overtime + night differential** (deferred — need an OT-hours capture/approval flow first).

## Decisions (answered 2026-07-10)
- **P0-1:** ✅ Preserve status in-place — re-run updates amounts via `updateOrCreate`, keeps each payslip's status (a `done` payslip stays done).
- **P1-1:** ✅ Allowances added to gross as **non-taxable de-minimis** (in gross/net, excluded from the taxable base).
- **P1-2:** ✅ Per-employee statutory amounts are a **hard override** — use the profile amount if set, else compute from company config.

## Decisions still needed
- **P2-8:** reimbursement→payroll — auto-flip approved claims to `paid` on run, or exclude already-paid claims from the fold-in?
- **P2-1 / P2-2 / P2-3 / P2-4 / P2-7:** 13th month, premium pay, employer-share remittance, and payslip/run export (employee PDF + HR CSV) — in scope for this build?
