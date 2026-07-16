# Feature / System Evaluation Checklist — Status

**Date:** July 14, 2026
**Project:** Viteseo Platform HRIS
**Method:** Each item verified against the current codebase (backend services, Form Requests, routes; frontend hooks/components). Items tied to a known security-audit finding are cross-referenced.

**Legend:** ✅ Full · 🟡 Partial · ❌ Missing / Not built

**Tally:** ✅ Full **18** · 🟡 Partial **11** · ❌ Missing **3**  (of 32)

---

## Attendance

| Status | Item | Evidence / gap |
|---|---|---|
| ❌ | **Time-in restrictions** | No geofence, selfie, Wi-Fi/IP, or workstation logic in `AttendanceService` or the migrations (a *plan* doc exists, not an implementation). "Time-out from any device" holds only because no restriction exists. |
| 🟡 | **Task checklist gates Time Out** | Tasks captured + stored at clock-out; a `task_format` policy (require English / uniform case) exists in Rules. The "disabled until a task" gate is UI-side; recurring-task pre-fill not evident. |
| ✅ | **Half-day handling** | Standalone, always-unpaid entry that affects pay only; explicitly does **not** touch leave credits. |
| ✅ | **Late & violation rules** | Monthly tally, "3 consecutive lates" threshold, Green→Yellow→Orange→Red tiers evaluated against each employee's schedule (`ViolationService`), with HR/employee notifications. |

## Payroll

| Status | Item | Evidence / gap |
|---|---|---|
| ✅ | **One-click Run Payroll** | Attendance-connected; pulls approved reimbursements + statutory deductions; semi-monthly runs; gross/deductions; next-payday. |
| ✅ | **Payslip generation** | Per-employee PDF, itemized, YTD, signature block, **Mark as Done** endpoint. |
| ✅ | **Payroll privacy toggle** | Present on the payroll page. |
| 🟡 | **System-managed statutory formulas** | Gov contributions are locked/read-only in Company → Benefits, not HR-editable, apply to all. **But** SSS uses a simplified placeholder rate, not the full official bracket table — the "matches official references" clause is only partial. |
| ✅ | **Attendance drives payroll** | Absences deducted at daily rate from actual records; follows real time even when approved leave is on the calendar. |

## Leave

| Status | Item | Evidence / gap |
|---|---|---|
| ✅ | **Live balance checks** | Checked at filing, auto-updated on approval. |
| ✅ | **Per-role allotments & overrides** | Allotment defaults by employment status + per-employee override; forward-only; credit restored when HR marks Present. |

## Reimbursements

| Status | Item | Evidence / gap |
|---|---|---|
| 🟡 | **Claim filing rules** | Category/amount/date/description/receipt captured, but the **15-day / 30-day deadline is not enforced**, and receipt is **optional** (not "no receipt, no reimbursement"). |
| ✅ | **Approval queue → payroll roll-up** | Status through Paid; approved claims roll into the next run; employee notified. |

## Recruitment

| Status | Item | Evidence / gap |
|---|---|---|
| ✅ | **Kanban recruitment pipeline** | Standard (Screening → Online → Final → Assessment → Hired) + separate OJT flow. |
| 🟡 | **Auto-email on stage move** | Stage emails exist and send, but via a **manual button per stage**, not **auto** on Screening→Interview. |
| ✅ | **Hired validator creates draft employee** | Validates openings; creates draft tagged Onboarding-Pending, prefilled; stays Pending until HR activates. |
| ❌ | **Website application intake** | No public / viteseo.ph intake route. |

## Requests

| Status | Item | Evidence / gap |
|---|---|---|
| ✅ | **Consolidated Requests area (4 queues)** | Leave, Document, Payroll, Concern all present with Approve/Reject. |
| ✅ | **HR approver routing for sensitive actions** | Payslip requests (reason), inquiries, and profile change requests (old/new/date/status) route through HR. |
| ✅ | **Centralized validators** | Approve confirms; Reject captures an optional reason shown to the employee. |

## Concerns

| Status | Item | Evidence / gap |
|---|---|---|
| ✅ | **Concerns channel end-to-end** | Categories, Named/Anonymous, reference numbers, Pending → Under Review → Resolved. |
| 🟡 | **Anonymity protection** | UI hides the submitter and meetings attach to the reference number, **but the Audit Trail de-anonymizes anonymous submitters** (logs their name + IP) — **security finding #19**. Not fully protected. |
| ✅ | **Safety routing** | Safety concerns feed the exportable Health & Safety report. |

## Configuration / Reports / Search / Finance

| Status | Item | Evidence / gap |
|---|---|---|
| ✅ | **Nothing hardcoded** | Benefits/contributions, Departments, Leave Types, Rules, Holidays (per-year refresh), FAQ all HR-editable and audit-logged. |
| 🟡 | **Eight standard reports** | All eight generate with department/date filters + export + history — **but** ours is "Diversity" (gender representation only), **no Pay Equity**. |
| 🟡 | **Restricted report access** | Enforced in navigation/UI, **but not on direct URL** — any HR officer can hit the Audit/Diversity report endpoints — **security finding #23**. |
| 🟡 | **Global search & notifications** | Bell badge + toasts ✅; search covers **people, departments, requisitions** but **not requests, documents, or FAQ/KB**. |
| ✅ | **Office Expenses (Finance tab)** | Present. |

## Security & Privacy

| Status | Item | Evidence / gap |
|---|---|---|
| ✅ | **Sanctum authentication & RBAC** | Token required (401); Spatie RBAC; role-based sidebar; Company area HR-only. Caveat: HR-role granting isn't admin-gated (**finding #21**). |
| 🟡 | **Read-only, tamper-evident Audit Trail** | Logged, timestamped, read-only (no edit endpoint) ✅; **but failed logins aren't recorded** and before/after values are rarely populated. |
| 🟡 | **Data privacy (RA 10173)** | Payslip/profile scoped to owner + HR ✅; **but resumes & employee documents sit on public storage, reachable without auth** (**finding #20**). |
| 🟡 | **Input validation & dependency hygiene** | Zod (frontend) + Laravel Form Requests (server) + file type/size validation ✅; **but no `npm audit` / `composer audit` gate in CI** — high/critical findings aren't blocked before merge. |

---

## Priority gaps to close

1. **Not built (3):** Wi-Fi/geo/selfie time-in restrictions; website application intake; (recurring-task pre-fill + hard time-out gate to complete the task checklist item).
2. **Security partials that also fail an acceptance clause (highest value — each breaks a control *and* a checklist item):**
   - Concern anonymity leak via Audit Trail — **finding #19**.
   - Unprotected public file storage (resumes, employee docs) — **finding #20**.
   - Restricted-report URLs not enforced server-side — **finding #23**.
3. **Feature partials:** enforce the 15/30-day + mandatory-receipt reimbursement rules; auto-send stage email on move; broaden global search to requests/documents/FAQ; add Pay Equity to the Diversity report; log failed logins + before/after in the audit trail; complete the official SSS bracket table.

_See `docs/SECURITY-AUDIT.md` (Follow-up Audit, July 14) for the referenced findings #19–#24._
