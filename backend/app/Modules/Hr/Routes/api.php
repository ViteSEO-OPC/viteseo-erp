<?php

use App\Modules\Hr\Http\Controllers\AttendanceController;
use App\Modules\Hr\Http\Controllers\AttendancePolicyController;
use App\Modules\Hr\Http\Controllers\BenefitController;
use App\Modules\Hr\Http\Controllers\BenefitProgramController;
use App\Modules\Hr\Http\Controllers\ChangeRequestController;
use App\Modules\Hr\Http\Controllers\ConcernController;
use App\Modules\Hr\Http\Controllers\DailyOverrideController;
use App\Modules\Hr\Http\Controllers\DashboardController;
use App\Modules\Hr\Http\Controllers\DocumentRequestController;
use App\Modules\Hr\Http\Controllers\FaqController;
use App\Modules\Hr\Http\Controllers\HolidayController;
use App\Modules\Hr\Http\Controllers\OfficeExpenseController;
use App\Modules\Hr\Http\Controllers\DepartmentController;
use App\Modules\Hr\Http\Controllers\ReportController;
use App\Modules\Hr\Http\Controllers\EmployeeController;
use App\Modules\Hr\Http\Controllers\LeaveConfigController;
use App\Modules\Hr\Http\Controllers\HalfDayRequestController;
use App\Modules\Hr\Http\Controllers\LeaveController;
use App\Modules\Hr\Http\Controllers\NotificationController;
use App\Modules\Hr\Http\Controllers\MeetingController;
use App\Modules\Hr\Http\Controllers\PayrollController;
use App\Modules\Hr\Http\Controllers\PayrollInquiryController;
use App\Modules\Hr\Http\Controllers\PayslipRequestController;
use App\Modules\Hr\Http\Controllers\RecruitmentController;
use App\Modules\Hr\Http\Controllers\ReimbursementController;
use App\Modules\Hr\Http\Controllers\SearchController;
use App\Modules\Hr\Http\Controllers\ViolationController;
use App\Modules\Hr\Http\Controllers\WorkScheduleController;
use Illuminate\Support\Facades\Route;

// All HR routes are auto-prefixed with /api/v1/hr (see HrServiceProvider).

Route::middleware('auth:sanctum')->group(function () {
    // Departments — read is open to any authenticated user (employees need the
    // list for dropdowns); writes are hr_officer-only.
    Route::get('departments', [DepartmentController::class, 'index']);
    Route::get('departments/{department}', [DepartmentController::class, 'show']);

    // The caller's own employee record (identity from the token).
    Route::get('me/employee', [EmployeeController::class, 'me']);

    // Notifications (role-aware: HR = pending approvals, employee = recent decisions).
    Route::get('me/notifications', [NotificationController::class, 'index']);

    // Global top-bar search (HR entities).
    Route::get('search', [SearchController::class, 'index']);

    // Leave — types + the caller's own balances/requests are open to any
    // authenticated user (they file + view their own; service scopes by role).
    Route::get('leave-types', [LeaveController::class, 'leaveTypes']);
    Route::get('me/leave-balances', [LeaveController::class, 'myBalances']);
    Route::get('leave-requests', [LeaveController::class, 'index']);
    Route::post('leave-requests', [LeaveController::class, 'store']);
    // Cancel is open to any authenticated user; the service enforces that an
    // employee can only cancel their own pending request (HR can cancel more).
    Route::post('leave-requests/{leaveRequest}/cancel', [LeaveController::class, 'cancel']);

    // Half-day requests — separate from leave (always unpaid). Caller files + views
    // own; service scopes by role.
    Route::get('half-day-requests', [HalfDayRequestController::class, 'index']);
    Route::post('half-day-requests', [HalfDayRequestController::class, 'store']);

    // Attendance — the caller clocks their own time; HR sees the roster.
    Route::get('me/attendance', [AttendanceController::class, 'me']);
    // The caller's own violation flags (read-only; HR's /violations is role-gated).
    Route::get('me/violations', [ViolationController::class, 'me']);
    Route::post('attendance/clock-in', [AttendanceController::class, 'clockIn']);
    Route::post('attendance/clock-out', [AttendanceController::class, 'clockOut']);
    Route::post('attendance/break', [AttendanceController::class, 'break']);

    // Reimbursement — caller files + views own; HR sees all + decides.
    Route::get('reimbursements', [ReimbursementController::class, 'index']);
    Route::post('reimbursements', [ReimbursementController::class, 'store']);

    // Requests — the caller files + views their own document/change requests.
    Route::get('me/documents', [EmployeeController::class, 'myDocuments']);
    Route::get('me/document-requests', [DocumentRequestController::class, 'index']);
    Route::post('me/document-requests', [DocumentRequestController::class, 'store']);
    Route::get('me/change-requests', [ChangeRequestController::class, 'index']);
    Route::post('me/change-requests', [ChangeRequestController::class, 'store']);
    Route::get('me/payslip-requests', [PayslipRequestController::class, 'index']);
    Route::post('me/payslip-requests', [PayslipRequestController::class, 'store']);
    Route::get('me/payroll-inquiries', [PayrollInquiryController::class, 'index']);
    Route::post('me/payroll-inquiries', [PayrollInquiryController::class, 'store']);

    // FAQ / knowledge base — read (role-scoped: employees see published only) + view count.
    Route::get('faqs', [FaqController::class, 'index']);
    Route::post('faqs/{faq}/view', [FaqController::class, 'view']);

    // Concerns + meeting invites — the caller's own (anonymity preserved server-side).
    Route::get('me/concerns', [ConcernController::class, 'meIndex']);
    Route::post('me/concerns', [ConcernController::class, 'store']);
    Route::get('me/meetings', [MeetingController::class, 'meIndex']);
    Route::post('me/meetings/{meeting}/respond', [MeetingController::class, 'respond']);

    // My Benefits — the caller's own enrolled benefit programs (identity from token).
    Route::get('me/benefit-programs', [BenefitProgramController::class, 'mine']);

    // Payslips — the caller's own (identity from token).
    Route::get('me/next-payday', [PayrollController::class, 'myNextPayday']);
    Route::get('me/payslips', [PayrollController::class, 'myPayslips']);
    Route::get('me/payslips/{payslip}', [PayrollController::class, 'showPayslip']);
    Route::get('me/payslips/{payslip}/pdf', [PayrollController::class, 'myPayslipPdf']);

    Route::middleware('role:hr_officer|super_admin')->group(function () {
        Route::get('dashboard/stats', [DashboardController::class, 'hrStats']);
        Route::get('reports/{key}', [ReportController::class, 'show']);
        Route::post('reports/{key}/export', [ReportController::class, 'export']);
        Route::get('attendance', [AttendanceController::class, 'index']);
        Route::get('attendance/summary', [AttendanceController::class, 'summary']);
        Route::post('attendance/adjust', [AttendanceController::class, 'adjust']);

        // Daily grace-period override (HR only, per-date).
        Route::get('daily-overrides', [DailyOverrideController::class, 'show']);
        Route::match(['put', 'patch'], 'daily-overrides', [DailyOverrideController::class, 'upsert']);

        // Attendance → violation flags (Slice 16).
        Route::get('violations', [ViolationController::class, 'index']);
        Route::post('violations/recompute', [ViolationController::class, 'recompute']);
        Route::post('violations/{violation}/resolve', [ViolationController::class, 'resolve']);

        // Payroll (HR only).
        Route::get('payroll/settings', [PayrollController::class, 'settings']);
        Route::put('payroll/settings', [PayrollController::class, 'updateSettings']);
        Route::get('payroll/runs', [PayrollController::class, 'runsIndex']);
        Route::get('payroll/thirteenth-month', [PayrollController::class, 'thirteenthMonth']);
        Route::post('payroll/run', [PayrollController::class, 'run']);
        Route::get('payroll/runs/{payrollRun}', [PayrollController::class, 'show']);
        Route::get('payroll/runs/{payrollRun}/export', [PayrollController::class, 'exportRun']);
        Route::post('payslips/{payslip}/mark-done', [PayrollController::class, 'markDone']);
        Route::get('payslips/{payslip}/pdf', [PayrollController::class, 'payslipPdf']);

        // Recruitment (HR only).
        Route::get('requisitions', [RecruitmentController::class, 'requisitionsIndex']);
        Route::post('requisitions', [RecruitmentController::class, 'storeRequisition']);
        Route::match(['put', 'patch'], 'requisitions/{requisition}', [RecruitmentController::class, 'updateRequisition']);
        Route::delete('requisitions/{requisition}', [RecruitmentController::class, 'destroyRequisition']);
        Route::get('candidates', [RecruitmentController::class, 'candidatesIndex']);
        Route::post('candidates', [RecruitmentController::class, 'storeCandidate']);
        Route::post('candidates/{candidate}/move', [RecruitmentController::class, 'moveCandidate']);
        Route::post('candidates/{candidate}/hire', [RecruitmentController::class, 'hireCandidate']);
        Route::post('candidates/{candidate}/email', [RecruitmentController::class, 'sendEmail']);
        Route::post('candidates/{candidate}/reject', [RecruitmentController::class, 'reject']);
        Route::post('reimbursements/{reimbursement}/approve', [ReimbursementController::class, 'approve']);
        Route::post('reimbursements/{reimbursement}/reject', [ReimbursementController::class, 'reject']);
        Route::post('reimbursements/{reimbursement}/mark-paid', [ReimbursementController::class, 'markPaid']);
        // Approve/decline leave (HR only).
        Route::post('leave-requests/{leaveRequest}/approve', [LeaveController::class, 'approve']);
        Route::post('leave-requests/{leaveRequest}/reject', [LeaveController::class, 'reject']);
        // Approve/decline half-day requests (HR only).
        Route::post('half-day-requests/{halfDayRequest}/approve', [HalfDayRequestController::class, 'approve']);
        Route::post('half-day-requests/{halfDayRequest}/reject', [HalfDayRequestController::class, 'reject']);
        Route::post('departments', [DepartmentController::class, 'store']);
        Route::match(['put', 'patch'], 'departments/{department}', [DepartmentController::class, 'update']);
        Route::delete('departments/{department}', [DepartmentController::class, 'destroy']);

        // Employee directory + records (HR only).
        Route::get('employees', [EmployeeController::class, 'index']);
        Route::post('employees', [EmployeeController::class, 'store']);
        Route::get('employees/{employee}', [EmployeeController::class, 'show']);
        Route::match(['put', 'patch'], 'employees/{employee}', [EmployeeController::class, 'update']);
        Route::get('employees/{employee}/salary-history', [EmployeeController::class, 'salaryHistory']);
        // Schedule a meeting with an employee (e.g. about attendance/violations).
        Route::post('employees/{employee}/meetings', [MeetingController::class, 'store']);
        Route::post('employees/{employee}/activate', [EmployeeController::class, 'activate']);
        // Grant/revoke HR-officer access for this employee's user account.
        Route::post('employees/{employee}/hr-role', [EmployeeController::class, 'setHrRole']);
        // Documents tab: list / upload / delete.
        Route::get('employees/{employee}/documents', [EmployeeController::class, 'documentsIndex']);
        Route::post('employees/{employee}/documents', [EmployeeController::class, 'storeDocument']);
        Route::delete('employees/{employee}/documents/{document}', [EmployeeController::class, 'destroyDocument']);

        // Company → Departments: archive/restore (HR only). CRUD is above.
        Route::post('departments/{department}/archive', [DepartmentController::class, 'archive']);
        Route::post('departments/{department}/restore', [DepartmentController::class, 'restore']);

        // Company → Leave Type: global mode (shared vs not-shared) + shared pool defaults.
        Route::get('leave-settings', [LeaveConfigController::class, 'settingsShow']);
        Route::match(['put', 'patch'], 'leave-settings', [LeaveConfigController::class, 'settingsUpdate']);
        Route::get('shared-leave-allotments', [LeaveConfigController::class, 'sharedAllotmentsIndex']);
        Route::post('shared-leave-allotments', [LeaveConfigController::class, 'storeSharedAllotment']);

        // Company → Leave Type: catalogue + allotment defaults + per-person balances.
        Route::post('leave-types', [LeaveConfigController::class, 'storeType']);
        Route::match(['put', 'patch'], 'leave-types/{leaveType}', [LeaveConfigController::class, 'updateType']);
        Route::delete('leave-types/{leaveType}', [LeaveConfigController::class, 'destroyType']);
        Route::get('leave-type-allotments', [LeaveConfigController::class, 'allotmentsIndex']);
        Route::post('leave-type-allotments', [LeaveConfigController::class, 'storeAllotment']);
        Route::match(['put', 'patch'], 'leave-type-allotments/{leaveTypeAllotment}', [LeaveConfigController::class, 'updateAllotment']);
        Route::get('employees/{employee}/leave-balances', [LeaveConfigController::class, 'balancesIndex']);
        Route::put('employees/{employee}/leave-balances', [LeaveConfigController::class, 'upsertBalance']);
        Route::delete('leave-balances/{leaveBalance}', [LeaveConfigController::class, 'destroyBalance']);

        // Company → Benefits: flexible benefit-programs catalogue (HR-authored).
        Route::get('benefit-programs', [BenefitProgramController::class, 'index']);
        Route::post('benefit-programs', [BenefitProgramController::class, 'store']);
        Route::post('benefit-programs/assign', [BenefitProgramController::class, 'assign']);
        Route::match(['put', 'patch'], 'benefit-programs/{benefitProgram}', [BenefitProgramController::class, 'update']);
        Route::delete('benefit-programs/{benefitProgram}', [BenefitProgramController::class, 'destroy']);

        // Company → Benefits: contribution settings + per-employee checklist.
        Route::get('contribution-settings', [BenefitController::class, 'settingsIndex']);
        Route::match(['put', 'patch'], 'contribution-settings/{contributionSetting}', [BenefitController::class, 'updateSetting']);
        Route::get('employees/{employee}/benefits', [BenefitController::class, 'employeeBenefitsIndex']);
        Route::match(['put', 'patch'], 'employees/{employee}/benefits', [BenefitController::class, 'updateEmployeeBenefits']);

        // Company → Rules: work schedules + per-employee assignment + attendance policy.
        Route::get('work-schedules', [WorkScheduleController::class, 'index']);
        Route::post('work-schedules', [WorkScheduleController::class, 'store']);
        Route::match(['put', 'patch'], 'work-schedules/{workSchedule}', [WorkScheduleController::class, 'update']);
        Route::delete('work-schedules/{workSchedule}', [WorkScheduleController::class, 'destroy']);
        Route::get('employees/{employee}/schedule', [WorkScheduleController::class, 'employeeSchedule']);
        Route::match(['put', 'patch'], 'employees/{employee}/schedule', [WorkScheduleController::class, 'updateEmployeeSchedule']);
        Route::get('attendance-policy', [AttendancePolicyController::class, 'show']);
        Route::match(['put', 'patch'], 'attendance-policy', [AttendancePolicyController::class, 'update']);

        // Requests → Document + Profile-change queues (HR only).
        Route::get('document-requests', [DocumentRequestController::class, 'index']);
        Route::post('document-requests/{documentRequest}/fulfill', [DocumentRequestController::class, 'fulfill']);
        Route::post('document-requests/{documentRequest}/reject', [DocumentRequestController::class, 'reject']);
        Route::get('change-requests', [ChangeRequestController::class, 'index']);
        Route::post('change-requests/{changeRequest}/approve', [ChangeRequestController::class, 'approve']);
        Route::post('change-requests/{changeRequest}/reject', [ChangeRequestController::class, 'reject']);

        // Requests → Payroll (payslip requests + inquiries, HR only).
        Route::get('payslip-requests', [PayslipRequestController::class, 'index']);
        Route::post('payslip-requests/{payslipRequest}/approve', [PayslipRequestController::class, 'approve']);
        Route::post('payslip-requests/{payslipRequest}/reject', [PayslipRequestController::class, 'reject']);
        Route::get('payroll-inquiries', [PayrollInquiryController::class, 'index']);
        Route::post('payroll-inquiries/{payrollInquiry}/progress', [PayrollInquiryController::class, 'progress']);
        Route::post('payroll-inquiries/{payrollInquiry}/resolve', [PayrollInquiryController::class, 'resolve']);

        // Requests → Concerns (HR/Admin only). Anonymity enforced in ConcernResource.
        Route::get('concerns', [ConcernController::class, 'index']);
        Route::post('concerns/{concern}/status', [ConcernController::class, 'status']);
        Route::post('concerns/{concern}/meetings', [ConcernController::class, 'scheduleMeeting']);

        // Finance → Office Expenses (HR only).
        Route::get('office-expenses', [OfficeExpenseController::class, 'index']);
        Route::post('office-expenses', [OfficeExpenseController::class, 'store']);
        Route::match(['put', 'patch'], 'office-expenses/{officeExpense}', [OfficeExpenseController::class, 'update']);
        Route::post('office-expenses/{officeExpense}/approve', [OfficeExpenseController::class, 'approve']);
        Route::post('office-expenses/{officeExpense}/reject', [OfficeExpenseController::class, 'reject']);
        Route::delete('office-expenses/{officeExpense}', [OfficeExpenseController::class, 'destroy']);

        // Company → Holiday List (HR only). country+year auto-loads from Nager.Date.
        Route::get('holiday-sets', [HolidayController::class, 'setsIndex']);
        Route::post('holiday-sets', [HolidayController::class, 'storeSet']);
        Route::post('holiday-sets/{holidaySet}/refresh', [HolidayController::class, 'refreshSet']);
        Route::delete('holiday-sets/{holidaySet}', [HolidayController::class, 'destroySet']);
        Route::get('holiday-sets/{holidaySet}/holidays', [HolidayController::class, 'holidaysIndex']);
        Route::post('holiday-sets/{holidaySet}/holidays', [HolidayController::class, 'storeHoliday']);
        Route::match(['put', 'patch'], 'holidays/{holiday}', [HolidayController::class, 'updateHoliday']);
        Route::delete('holidays/{holiday}', [HolidayController::class, 'destroyHoliday']);

        // Company → FAQ authoring (HR only).
        Route::post('faqs', [FaqController::class, 'store']);
        Route::match(['put', 'patch'], 'faqs/{faq}', [FaqController::class, 'update']);
        Route::delete('faqs/{faq}', [FaqController::class, 'destroy']);
        Route::post('faqs/{faq}/publish', [FaqController::class, 'publish']);
        Route::post('faqs/{faq}/archive', [FaqController::class, 'archive']);
        Route::post('faqs/{faq}/pin', [FaqController::class, 'pin']);
    });
});
