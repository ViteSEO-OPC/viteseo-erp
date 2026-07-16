# HRIS API — `/api/v1/hr/*`

HR module (POD 1). Mounted at `/api/v1/hr` by `HrServiceProvider`; all routes are
behind `auth:sanctum`. Source: `backend/app/Modules/Hr/Routes/api.php` +
`app/Modules/Hr/Http/{Controllers,Requests,Resources}`.

**Two access tiers:**
- **Self-service** (any authenticated user): the `me/*` routes and the file-your-own
  routes below. Identity resolves from `$request->user()->employee`; a client-sent
  `employee_id` is never trusted for ownership. Aligns with the `hr_employee` role.
- **Management** (`role:hr_officer|super_admin`): everything inside the guarded group
  — directory, payroll runs, approvals, company config, reports, etc.

Guard column below: **Any** = any authenticated user; **HR** = `hr_officer|super_admin`.

---

## Departments

| Method | Path | Guard | Purpose |
|---|---|---|---|
| GET | `/departments` | Any | List departments (employees need it for dropdowns). |
| GET | `/departments/{department}` | Any | Show one department. |
| POST | `/departments` | HR | Create a department. Fields: `name`, `description?` (`StoreDepartmentRequest`). |
| PUT/PATCH | `/departments/{department}` | HR | Update a department (`UpdateDepartmentRequest`). |
| DELETE | `/departments/{department}` | HR | Soft-delete a department. |
| POST | `/departments/{department}/archive` | HR | Archive a department. |
| POST | `/departments/{department}/restore` | HR | Restore an archived department. |

## My Employee Record & Directory

| Method | Path | Guard | Purpose |
|---|---|---|---|
| GET | `/me/employee` | Any | The caller's own employee record (identity from token). |
| GET | `/me/documents` | Any | The caller's own HR-uploaded documents. |
| GET | `/employees` | HR | Employee directory (paginated). |
| POST | `/employees` | HR | Create an employee. Fields incl. `first_name`, `last_name`, `email` (unique), `department_id`, `job_title`, `employment_status`, `hire_date`, `base_salary`, + profile fields (`StoreEmployeeRequest`). |
| GET | `/employees/{employee}` | HR | Show one employee. |
| PUT/PATCH | `/employees/{employee}` | HR | Update an employee (`UpdateEmployeeRequest`). |
| GET | `/employees/{employee}/salary-history` | HR | Salary change history for the employee. |
| POST | `/employees/{employee}/activate` | HR | Activate the employee's user account. |
| POST | `/employees/{employee}/hr-role` | HR | Grant/revoke HR-officer access for the employee's user (`SetHrRoleRequest`). |
| GET | `/employees/{employee}/documents` | HR | List the employee's documents. |
| POST | `/employees/{employee}/documents` | HR | Upload a document (`StoreEmployeeDocumentRequest`). |
| DELETE | `/employees/{employee}/documents/{document}` | HR | Delete a document. |

## Attendance

| Method | Path | Guard | Purpose |
|---|---|---|---|
| GET | `/me/attendance` | Any | The caller's own attendance log. |
| POST | `/attendance/clock-in` | Any | Clock in (caller). |
| POST | `/attendance/clock-out` | Any | Clock out (caller). |
| POST | `/attendance/break` | Any | Start/end a break (caller). |
| GET | `/attendance` | HR | Attendance roster (all employees). |
| GET | `/attendance/summary` | HR | Aggregated attendance summary. |
| POST | `/attendance/adjust` | HR | Manual adjustment. Fields: `employee_id`, `work_date`, `status` (present/late/absent), `time_in?`, `time_out?`, `note?` (`AdjustAttendanceRequest`). |
| GET | `/daily-overrides` | HR | Per-date grace-period override (query by date). |
| PUT/PATCH | `/daily-overrides` | HR | Upsert a daily grace-period override (`UpsertDailyOverrideRequest`). |
| GET | `/attendance-policy` | HR | Show the attendance policy (grace, thresholds). |
| PUT/PATCH | `/attendance-policy` | HR | Update the attendance policy (`UpdateAttendancePolicyRequest`). |

## Violations (attendance flags — Slice 16)

| Method | Path | Guard | Purpose |
|---|---|---|---|
| GET | `/violations` | HR | List attendance violation flags. |
| POST | `/violations/recompute` | HR | Recompute violations across the roster. |
| POST | `/violations/{violation}/resolve` | HR | Resolve/clear a violation. |

## Leave

| Method | Path | Guard | Purpose |
|---|---|---|---|
| GET | `/leave-types` | Any | List leave types (for the file-leave form). |
| GET | `/me/leave-balances` | Any | The caller's own leave balances. |
| GET | `/leave-requests` | Any | Leave requests — service scopes by role (employee sees own, HR sees all). |
| POST | `/leave-requests` | Any | File a leave request. Fields: `leave_type_id`, `start_date`, `end_date` (≥ start), `reason` (`StoreLeaveRequest`). |
| POST | `/leave-requests/{leaveRequest}/approve` | HR | Approve a leave request. |
| POST | `/leave-requests/{leaveRequest}/reject` | HR | Decline a leave request. |

## Half-Day Requests (always unpaid, separate from leave)

| Method | Path | Guard | Purpose |
|---|---|---|---|
| GET | `/half-day-requests` | Any | Half-day requests (scoped by role). |
| POST | `/half-day-requests` | Any | File a half-day request. Fields: `work_date`, `period` (AM/PM), `reason` (`StoreHalfDayRequestRequest`). |
| POST | `/half-day-requests/{halfDayRequest}/approve` | HR | Approve. |
| POST | `/half-day-requests/{halfDayRequest}/reject` | HR | Decline. |

## Leave Configuration (company → leave)

| Method | Path | Guard | Purpose |
|---|---|---|---|
| POST | `/leave-types` | HR | Create a leave type (`StoreLeaveTypeRequest`). |
| PUT/PATCH | `/leave-types/{leaveType}` | HR | Update a leave type (`UpdateLeaveTypeRequest`). |
| DELETE | `/leave-types/{leaveType}` | HR | Delete a leave type. |
| GET | `/leave-type-allotments` | HR | List default allotments per leave type. |
| POST | `/leave-type-allotments` | HR | Create an allotment default (`StoreLeaveTypeAllotmentRequest`). |
| PUT/PATCH | `/leave-type-allotments/{leaveTypeAllotment}` | HR | Update an allotment default. |
| GET | `/employees/{employee}/leave-balances` | HR | Per-employee leave balances. |
| PUT | `/employees/{employee}/leave-balances` | HR | Upsert a per-employee balance. |
| DELETE | `/leave-balances/{leaveBalance}` | HR | Delete a per-employee balance. |

## Reimbursements

| Method | Path | Guard | Purpose |
|---|---|---|---|
| GET | `/reimbursements` | Any | Reimbursements (caller sees own; HR sees all). |
| POST | `/reimbursements` | Any | File a reimbursement. Fields: `category`, `amount`, `expense_date`, `description`, `receipt?` (jpg/png/pdf ≤5MB) (`StoreReimbursementRequest`). |
| POST | `/reimbursements/{reimbursement}/approve` | HR | Approve. |
| POST | `/reimbursements/{reimbursement}/reject` | HR | Reject. |
| POST | `/reimbursements/{reimbursement}/mark-paid` | HR | Mark as paid. |

## Payroll (HR management)

| Method | Path | Guard | Purpose |
|---|---|---|---|
| GET | `/payroll/settings` | HR | Payroll settings. |
| PUT | `/payroll/settings` | HR | Update payroll settings (`UpdatePayrollSettingsRequest`). |
| GET | `/payroll/runs` | HR | List payroll runs. |
| GET | `/payroll/thirteenth-month` | HR | 13th-month computation. |
| POST | `/payroll/run` | HR | Execute a payroll run. Fields: `frequency`, `cycle_start`, `cycle_end` (≥ start) (`RunPayrollRequest`). |
| GET | `/payroll/runs/{payrollRun}` | HR | Show a payroll run (its payslips). |
| GET | `/payroll/runs/{payrollRun}/export` | HR | Export a payroll run. |
| POST | `/payslips/{payslip}/mark-done` | HR | Mark a payslip finalized. |
| GET | `/payslips/{payslip}/pdf` | HR | Render a payslip PDF (any employee). |

## My Payslips & Payday (self-service)

| Method | Path | Guard | Purpose |
|---|---|---|---|
| GET | `/me/next-payday` | Any | The caller's next payday. |
| GET | `/me/payslips` | Any | The caller's own payslips. |
| GET | `/me/payslips/{payslip}` | Any | Show one of the caller's payslips. |
| GET | `/me/payslips/{payslip}/pdf` | Any | PDF of the caller's own payslip. |

## Requests → Documents

| Method | Path | Guard | Purpose |
|---|---|---|---|
| GET | `/me/document-requests` | Any | The caller's own document requests. |
| POST | `/me/document-requests` | Any | File a document request. Fields: `employee_document_id?` (own doc), `type` (required unless doc-linked), `other_detail?` (required if type=other), `reason?` (`StoreDocumentRequestRequest`). |
| GET | `/document-requests` | HR | Document-request queue. |
| POST | `/document-requests/{documentRequest}/fulfill` | HR | Fulfill a document request. |
| POST | `/document-requests/{documentRequest}/reject` | HR | Reject a document request. |

## Requests → Profile Changes

| Method | Path | Guard | Purpose |
|---|---|---|---|
| GET | `/me/change-requests` | Any | The caller's own profile-change requests. |
| POST | `/me/change-requests` | Any | Request field changes. Fields: `changes[]` (each `{field, new_value}`, 1–28) (`StoreChangeRequestRequest`). |
| GET | `/change-requests` | HR | Change-request queue. |
| POST | `/change-requests/{changeRequest}/approve` | HR | Approve a change request. |
| POST | `/change-requests/{changeRequest}/reject` | HR | Reject a change request. |

## Requests → Payslip Requests

| Method | Path | Guard | Purpose |
|---|---|---|---|
| GET | `/me/payslip-requests` | Any | The caller's own payslip requests. |
| POST | `/me/payslip-requests` | Any | Request a payslip. Fields: `reason`, `payslip_id` (must be own) (`StorePayslipRequestRequest`). |
| GET | `/payslip-requests` | HR | Payslip-request queue. |
| POST | `/payslip-requests/{payslipRequest}/approve` | HR | Approve. |
| POST | `/payslip-requests/{payslipRequest}/reject` | HR | Reject. |

## Requests → Payroll Inquiries

| Method | Path | Guard | Purpose |
|---|---|---|---|
| GET | `/me/payroll-inquiries` | Any | The caller's own payroll inquiries. |
| POST | `/me/payroll-inquiries` | Any | Raise a payroll inquiry. Fields: `subject`, `topic`, `message`, `payslip_id?` (own), `attachment?` (≤5MB) (`StorePayrollInquiryRequest`). |
| GET | `/payroll-inquiries` | HR | Inquiry queue. |
| POST | `/payroll-inquiries/{payrollInquiry}/progress` | HR | Move an inquiry forward (in progress). |
| POST | `/payroll-inquiries/{payrollInquiry}/resolve` | HR | Resolve an inquiry. |

## Concerns (anonymity enforced server-side)

| Method | Path | Guard | Purpose |
|---|---|---|---|
| GET | `/me/concerns` | Any | The caller's own concerns. |
| POST | `/me/concerns` | Any | Raise a concern. Fields: `category`, `description`, `is_anonymous?` (`StoreConcernRequest`). |
| GET | `/concerns` | HR | Concern queue (identity masked in `ConcernResource` when anonymous). |
| POST | `/concerns/{concern}/status` | HR | Update a concern's status. |
| POST | `/concerns/{concern}/meetings` | HR | Schedule a meeting about a concern. |

## Meetings (invites to the caller)

| Method | Path | Guard | Purpose |
|---|---|---|---|
| GET | `/me/meetings` | Any | The caller's own meeting invites. |
| POST | `/me/meetings/{meeting}/respond` | Any | Respond to a meeting invite. |
| POST | `/employees/{employee}/meetings` | HR | Schedule a meeting with an employee. |

## Benefits

| Method | Path | Guard | Purpose |
|---|---|---|---|
| GET | `/me/benefit-programs` | Any | The caller's enrolled benefit programs. |
| GET | `/benefit-programs` | HR | Benefit-programs catalogue. |
| POST | `/benefit-programs` | HR | Create a benefit program (`StoreBenefitProgramRequest`). |
| POST | `/benefit-programs/assign` | HR | Assign a program to employees (`AssignBenefitsRequest`). |
| PUT/PATCH | `/benefit-programs/{benefitProgram}` | HR | Update a program (`UpdateBenefitProgramRequest`). |
| DELETE | `/benefit-programs/{benefitProgram}` | HR | Delete a program. |
| GET | `/contribution-settings` | HR | Statutory contribution settings. |
| PUT/PATCH | `/contribution-settings/{contributionSetting}` | HR | Update a contribution setting. |
| GET | `/employees/{employee}/benefits` | HR | Per-employee benefits checklist. |
| PUT/PATCH | `/employees/{employee}/benefits` | HR | Update per-employee benefits. |

## Work Schedules & Rules

| Method | Path | Guard | Purpose |
|---|---|---|---|
| GET | `/work-schedules` | HR | List work schedules. |
| POST | `/work-schedules` | HR | Create a work schedule (`StoreWorkScheduleRequest`). |
| PUT/PATCH | `/work-schedules/{workSchedule}` | HR | Update a work schedule. |
| DELETE | `/work-schedules/{workSchedule}` | HR | Delete a work schedule. |
| GET | `/employees/{employee}/schedule` | HR | Employee's assigned schedule. |
| PUT/PATCH | `/employees/{employee}/schedule` | HR | Assign/update the employee's schedule (`UpdateEmployeeScheduleRequest`). |

## Recruitment (HR management)

| Method | Path | Guard | Purpose |
|---|---|---|---|
| GET | `/requisitions` | HR | List job requisitions. |
| POST | `/requisitions` | HR | Create a requisition (`StoreRequisitionRequest`). |
| PUT/PATCH | `/requisitions/{requisition}` | HR | Update a requisition. |
| DELETE | `/requisitions/{requisition}` | HR | Delete a requisition. |
| GET | `/candidates` | HR | List candidates. |
| POST | `/candidates` | HR | Add a candidate (`StoreCandidateRequest`). |
| POST | `/candidates/{candidate}/move` | HR | Move a candidate across pipeline stages (`MoveCandidateRequest`). |
| POST | `/candidates/{candidate}/hire` | HR | Hire a candidate (creates employee). |
| POST | `/candidates/{candidate}/email` | HR | Email a candidate. |
| POST | `/candidates/{candidate}/reject` | HR | Reject a candidate. |

## Office Expenses (finance, HR)

| Method | Path | Guard | Purpose |
|---|---|---|---|
| GET | `/office-expenses` | HR | List office expenses. |
| POST | `/office-expenses` | HR | Create an office expense (`StoreOfficeExpenseRequest`). |
| PUT/PATCH | `/office-expenses/{officeExpense}` | HR | Update an office expense. |
| POST | `/office-expenses/{officeExpense}/approve` | HR | Approve. |
| POST | `/office-expenses/{officeExpense}/reject` | HR | Reject. |
| DELETE | `/office-expenses/{officeExpense}` | HR | Delete. |

## Holidays (company → holiday list)

| Method | Path | Guard | Purpose |
|---|---|---|---|
| GET | `/holiday-sets` | HR | List holiday sets. |
| POST | `/holiday-sets` | HR | Create a holiday set — country+year auto-loads from Nager.Date (`StoreHolidaySetRequest`). |
| POST | `/holiday-sets/{holidaySet}/refresh` | HR | Re-fetch holidays for the set. |
| DELETE | `/holiday-sets/{holidaySet}` | HR | Delete a holiday set. |
| GET | `/holiday-sets/{holidaySet}/holidays` | HR | List holidays in a set. |
| POST | `/holiday-sets/{holidaySet}/holidays` | HR | Add a holiday (`StoreHolidayRequest`). |
| PUT/PATCH | `/holidays/{holiday}` | HR | Update a holiday. |
| DELETE | `/holidays/{holiday}` | HR | Delete a holiday. |

## FAQ / Knowledge Base

| Method | Path | Guard | Purpose |
|---|---|---|---|
| GET | `/faqs` | Any | List FAQs (employees see published only; role-scoped). |
| POST | `/faqs/{faq}/view` | Any | Increment a FAQ's view count. |
| POST | `/faqs` | HR | Author a FAQ (`StoreFaqRequest`). |
| PUT/PATCH | `/faqs/{faq}` | HR | Update a FAQ. |
| DELETE | `/faqs/{faq}` | HR | Delete a FAQ. |
| POST | `/faqs/{faq}/publish` | HR | Publish a FAQ. |
| POST | `/faqs/{faq}/archive` | HR | Archive a FAQ. |
| POST | `/faqs/{faq}/pin` | HR | Pin a FAQ. |

## Dashboard, Reports, Search & Notifications

| Method | Path | Guard | Purpose |
|---|---|---|---|
| GET | `/me/notifications` | Any | Role-aware notifications (HR = pending approvals; employee = recent decisions). |
| GET | `/search` | Any | Global top-bar search over HR entities. |
| GET | `/dashboard/stats` | HR | HR dashboard statistics. |
| GET | `/reports/{key}` | HR | Render a report by key. |
| POST | `/reports/{key}/export` | HR | Export a report by key. |

**Total HRIS endpoints: 151** (37 self-service + 114 management, counting each
HTTP verb once; `PUT`/`PATCH` `match` routes counted as one).
</content>
