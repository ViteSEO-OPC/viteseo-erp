# HRIS (Teamora) — changes between old snapshot and pod-latest

Generated: 2026-07-16 15:55 | Base: `hris` | Latest: `pod-latest/hris`

## Added (only in pod-latest)

- `README.md`
- `backend/app/Modules/Hr/Database/Migrations/2026_07_15_000001_add_backdating_to_leave_types.php`
- `backend/app/Modules/Hr/Database/Migrations/2026_07_15_000002_add_resolution_attachments_to_payroll_inquiries.php`
- `docs/ERD.md`
- `docs/frontend-duplication-audit.md`
- `frontend/src/core/components/rejection-reason-dialog.tsx`
- `frontend/src/core/components/status-filter.tsx`
- `frontend/src/modules/hr/components/attendance-summary-tiles.tsx`
- `frontend/src/modules/hr/components/leave-type-legend.tsx`
- `frontend/src/modules/hr/lib/attendance-display.tsx`

## Removed (only in old snapshot)


## Modified

- `backend/app/Modules/Hr/Database/Seeders/LeaveTypesSeeder.php`
- `backend/app/Modules/Hr/Http/Controllers/LeaveConfigController.php`
- `backend/app/Modules/Hr/Http/Controllers/NotificationController.php`
- `backend/app/Modules/Hr/Http/Controllers/PayrollController.php`
- `backend/app/Modules/Hr/Http/Controllers/PayrollInquiryController.php`
- `backend/app/Modules/Hr/Http/Controllers/ViolationController.php`
- `backend/app/Modules/Hr/Http/Requests/AdjustAttendanceRequest.php`
- `backend/app/Modules/Hr/Http/Requests/StoreLeaveTypeAllotmentRequest.php`
- `backend/app/Modules/Hr/Http/Requests/StoreLeaveTypeRequest.php`
- `backend/app/Modules/Hr/Http/Requests/StorePayslipRequestRequest.php`
- `backend/app/Modules/Hr/Http/Requests/UpdateLeaveTypeRequest.php`
- `backend/app/Modules/Hr/Http/Resources/ConcernResource.php`
- `backend/app/Modules/Hr/Http/Resources/LeaveBalanceResource.php`
- `backend/app/Modules/Hr/Http/Resources/LeaveRequestResource.php`
- `backend/app/Modules/Hr/Http/Resources/LeaveTypeResource.php`
- `backend/app/Modules/Hr/Http/Resources/PayrollInquiryResource.php`
- `backend/app/Modules/Hr/Http/Resources/PayslipSummaryResource.php`
- `backend/app/Modules/Hr/Models/LeaveBalance.php`
- `backend/app/Modules/Hr/Models/LeaveRequest.php`
- `backend/app/Modules/Hr/Models/LeaveType.php`
- `backend/app/Modules/Hr/Models/LeaveTypeAllotment.php`
- `backend/app/Modules/Hr/Models/PayrollInquiry.php`
- `backend/app/Modules/Hr/Services/AttendanceService.php`
- `backend/app/Modules/Hr/Services/LeaveService.php`
- `backend/app/Modules/Hr/Services/NotificationService.php`
- `backend/app/Modules/Hr/Services/PayrollInquiryService.php`
- `backend/app/Modules/Hr/Services/PayrollService.php`
- `backend/app/Modules/Hr/Services/PayslipRequestService.php`
- `backend/app/Modules/Hr/Services/ViolationService.php`
- `docs/SECURITY-AUDIT.md`
- `frontend/src/app/(employee)/claims/page.tsx`
- `frontend/src/app/(employee)/concerns/page.tsx`
- `frontend/src/app/(employee)/page.tsx`
- `frontend/src/app/(employee)/payslips/page.tsx`
- `frontend/src/app/(employee)/time-off/page.tsx`
- `frontend/src/app/hr/attendance/page.tsx`
- `frontend/src/app/hr/company/faq/page.tsx`
- `frontend/src/app/hr/company/leave-types/page.tsx`
- `frontend/src/app/hr/requests/concern/page.tsx`
- `frontend/src/app/hr/requests/document/page.tsx`
- `frontend/src/app/hr/requests/leave/page.tsx`
- `frontend/src/app/hr/requests/payroll/page.tsx`
- `frontend/src/core/components/app-shell.tsx`
- `frontend/src/core/components/data-table.tsx`
- `frontend/src/core/components/top-bar.tsx`
- `frontend/src/core/lib/format.ts`
- `frontend/src/core/nav.ts`
- `frontend/src/core/notifications-context.tsx`
- `frontend/src/core/types/notifications.ts`
- `frontend/src/core/use-notifications.ts`
- `frontend/src/modules/hr/api/attendance.ts`
- `frontend/src/modules/hr/api/requests.ts`
- `frontend/src/modules/hr/components/attendance-adjust-dialog.tsx`
- `frontend/src/modules/hr/components/checkin-board.tsx`
- `frontend/src/modules/hr/components/employee-leave-credits-editor.tsx`
- `frontend/src/modules/hr/components/leave-balance-card.tsx`
- `frontend/src/modules/hr/components/leave-request-form.tsx`
- `frontend/src/modules/hr/components/leave-type-form-dialog.tsx`
- `frontend/src/modules/hr/components/payroll-inquiry-dialog.tsx`
- `frontend/src/modules/hr/hooks/use-attendance.ts`
- `frontend/src/modules/hr/hooks/use-requests.ts`
- `frontend/src/modules/hr/types/attendance.ts`
- `frontend/src/modules/hr/types/leave-config.ts`
- `frontend/src/modules/hr/types/leave.ts`
- `frontend/src/modules/hr/types/payroll.ts`
- `frontend/src/modules/hr/types/reimbursement.ts`
- `frontend/src/modules/hr/types/request.ts`
