# HR / Payroll owner sign-off — staging → production release

> **Technical validation is NOT business approval.** Engineering has checked that the code builds, that the tests
> pass and that the migrations apply cleanly to a copy of production. Engineering has **not** decided, and cannot
> decide, whether the new payroll behaviour is correct for Vite SEO's policies, contracts or the law. That is the
> HR/payroll owner's call, and **production stays on hold until this form is signed "APPROVE"**.
> Engineering makes no policy decision here. Some intern rules are **settings** the owner configures (item 3);
> others are **built into the code** (items 4a–4f) — those the owner must explicitly accept or reject, because
> changing them needs a code change, not a setting.

## What this release changes in HR (from production `f70ae1aa`)
- **Intern / OJT payroll (new).** An employee whose `employment_status` is **`ojt`** is paid an **intern allowance**
  priced by *Company → Intern Allowance* settings (per day / per hour / fixed monthly; late, undertime, absence,
  half-day, holiday, rest-day and suspension rules — every one a setting). Interns get **no statutory
  contributions (SSS, PhilHealth, Pag-IBIG) and no withholding tax**, no minimum-wage check, and no loan/benefit
  deductions; attendance deductions still apply. The payslip shows an "intern" basis.
- **Intern → staff conversion.** Converting an intern sets a conversion date; the first salaried slip prices days
  before that date as intern allowance and days after as salary.
- **Recruitment:** OJT fields on candidates and requisitions (required hours, allowance).
- **Employees:** OJT required hours, intern allowance fields, "other ID" types/numbers.
- **Attendance/DTR:** dynamic day rules moved to the attendance page; "late by" in DTR export/UI; middle initial display.
- **Reports/other:** CSV export design, office-expense month filter, leave/request type tabs with calendar filter.
- **New prompts that say "re-run the period"** (day rules, work suspensions, repricing dialogs, *Recompute this period*).
- 7 HR migrations add columns/tables only (no existing payroll/payslip value is changed by the migration — verified
  on a copy of production: `hr_employees`, `hr_payslips`, `hr_candidates`, `hr_requisitions` original columns identical).

Production facts at the time of writing (2026-10-01, read-only): 14 active employees (10 regular, 4 probationary,
**0 ojt**); payroll runs 1–9 all `completed`; intern allowance settings: none yet.

## Checklist (owner ticks each line or writes an exception)

| # | Item | How to check (staging/demo or production read-only) | ✔ / Exception |
|---|---|---|---|
| 1 | **Payroll run 3 (2026-08-01 → 08-15) must NEVER be re-run or "recomputed".** Its stored policy snapshot has `allowance_timing = "every"`; a re-run injects ≈ **₱5,750.15 of phantom allowance**. This release adds more "re-run the period" prompts — the team must know to ignore them for run 3 (and any disbursed run). | Read and acknowledge; brief payroll staff. | |
| 2 | Re-running any **disbursed** run is a no-op for disbursed slips by design; corrections go through amendments, not re-runs. | Acknowledge. | |
| 3 | **Intern allowance policy**: method, rates, proration, late/undertime/absence/half-day rules, holiday/rest-day/suspension rules match the company's intern policy and agreements. | Configure in staging/demo *Company → Intern Allowance*; use the preview; compare with 2–3 hand-calculated examples. | |
| 4a | BUILT-IN: interns pay **no SSS / PhilHealth / Pag-IBIG** and **no withholding tax** on the intern allowance (also on an intern's separation pay). | Owner/accountant accepts or rejects. | |
| 4b | BUILT-IN: interns get **no 13th-month pay** (always 0 on an intern slip). | Accept / reject. | |
| 4c | BUILT-IN: company **allowance programmes** (e.g. rice, transport) and **reimbursements** still apply to interns. | Accept / reject. | |
| 4d | BUILT-IN: **loan and benefit deductions stop** for anyone whose status becomes `ojt` (nothing is deducted from an intern allowance except attendance). Check no current loan holder would be switched. | Accept / reject. | |
| 4e | BUILT-IN: an intern's **leave never draws leave credits** (filed for the record only); which leave types interns may file is an Intern Allowance setting. | Accept / reject. | |
| 4f | BUILT-IN: no minimum-wage check for interns (they are paid an allowance, not a wage). | Accept / reject. | |
| 5 | **Intern setup prerequisites**: before anyone is set to `ojt` in production, the Intern Allowance settings are configured (otherwise the intern's allowance prices with no policy). | Owner confirms the go-live order. | |
| 6 | **Who becomes `ojt`**: setting `employment_status = ojt` switches that person from salary to allowance at the next payroll. Confirm no current employee should be (today: 0). | HR list review. | |
| 7 | **Conversion** intern → staff: effective-date behaviour (split slip) is acceptable. | Try on staging/demo with a test intern. | |
| 8 | **Existing payslips unchanged**: payslips of runs 1–9 look the same after the release (amounts, lines, PDFs). | After deploy, open 3 existing payslips (incl. one from run 5 and run 9) and compare with the pre-deploy PDF/export. | |
| 9 | **Current employees' next payroll**: the next regular run for salaried staff computes as before (no intern effects, same SSS basis behaviour as documented). | Dry/preview the next cycle on staging/demo with production-like data, or compare the first production run after deploy before disbursing. | |
| 10 | **New screens/flows** are understood: Intern Allowance settings, OJT fields in recruitment/employee forms, conversion, other IDs, day rules, DTR changes, report exports. | Walk-through on staging/demo. | |
| 11 | **Leave balances / accrual** behaviour unchanged for salaried staff (accrual still runs on its schedule; status changes take effect only when accrual runs). | Acknowledge. | |
| 12 | **Rollback implications understood**: rolling the release back restores the database to the moment before the deploy — **any HR data entered after go-live (intern settings, OJT fields, payslips of a run made on the new release) is lost** unless exported first. | Acknowledge. | |
| 13 | Known open policy item (not changed by this release): SSS earnings basis lacks the `min(earnings, basic)` cap — tracked separately. | Acknowledge. | |

## Sign-off

| Field | Value |
|---|---|
| Reviewer name | |
| Role | HR / Payroll owner |
| Date | |
| Environment reviewed (staging / demo / both) | |
| Items checked (list #) | |
| Exceptions (item # + note) | |
| Decision | ☐ **APPROVE** — release may proceed  ☐ **REJECT** — production stays on hold |
| Conditions (if any) | |
| Comments | |
| Signature | |

Engineering counter-sign (technical validation only, NOT business approval): name / date / commit SHA deployed.
