<?php

use App\Modules\Hr\Http\Controllers\ApprovalQueueController;
use App\Modules\Hr\Http\Controllers\AttendanceController;
use App\Modules\Hr\Http\Controllers\AttendancePolicyController;
use App\Modules\Hr\Http\Controllers\RemoteCheckinController;
use App\Modules\Hr\Http\Controllers\BenefitController;
use App\Modules\Hr\Http\Controllers\BenefitProgramController;
use App\Modules\Hr\Http\Controllers\ChangeRequestController;
use App\Modules\Hr\Http\Controllers\CompanyProfileController;
use App\Modules\Hr\Http\Controllers\ConcernController;
use App\Modules\Hr\Http\Controllers\DailyOverrideController;
use App\Modules\Hr\Http\Controllers\DeductionArrearsController;
use App\Modules\Hr\Http\Controllers\DashboardController;
use App\Modules\Hr\Http\Controllers\DocumentRequestController;
use App\Modules\Hr\Http\Controllers\FaqController;
use App\Modules\Hr\Http\Controllers\HolidayController;
use App\Modules\Hr\Http\Controllers\OfficeExpenseController;
use App\Modules\Hr\Http\Controllers\DepartmentController;
use App\Modules\Hr\Http\Controllers\ReportController;
use App\Modules\Hr\Http\Controllers\TaxTableController;
use App\Modules\Hr\Http\Controllers\WorkRequestController;
use App\Modules\Hr\Http\Controllers\WorkSuspensionController;
use App\Modules\Hr\Http\Controllers\RetroFlagController;
use App\Modules\Hr\Http\Controllers\EmployeeController;
use App\Modules\Hr\Http\Controllers\EmployeeLoanController;
use App\Modules\Hr\Http\Controllers\EmployeeSensitiveController;
use App\Modules\Hr\Http\Controllers\EmploymentHistoryController;
use App\Modules\Hr\Http\Controllers\SalaryHistoryController;
use App\Modules\Hr\Http\Controllers\LeaveConfigController;
use App\Modules\Hr\Http\Controllers\HalfDayRequestController;
use App\Modules\Hr\Http\Controllers\EarlyOutRequestController;
use App\Modules\Hr\Http\Controllers\LeaveController;
use App\Modules\Hr\Http\Controllers\NotificationController;
use App\Modules\Hr\Http\Controllers\OvertimeRequestController;
use App\Modules\Hr\Http\Controllers\MeetingController;
use App\Modules\Hr\Http\Controllers\PaymentBatchController;
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
    // The employer's own details (Company → Settings). Read is open to any authenticated
    // user: the payslip on screen prints this letterhead for employees too. Writes are
    // hr_officer-only, further down.
    Route::get('company-profile', [CompanyProfileController::class, 'show']);

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
    // Withdraw your own, pending only. Ownership + status enforced in the service.
    Route::post('half-day-requests/{halfDayRequest}/cancel', [HalfDayRequestController::class, 'cancel']);

    // Work requests — permission to work a rest day, or a suspended day HR marked as needing
    // permission. Filed by pressing Time in, so there is no date in the body: the server uses
    // the caller's Manila today and derives the type from it. Caller files + views own.
    Route::get('work-requests', [WorkRequestController::class, 'index']);
    Route::post('work-requests', [WorkRequestController::class, 'store']);
    Route::post('work-requests/{workRequest}/cancel', [WorkRequestController::class, 'cancel']);

    // HR-declared non-working days (typhoon, shutdown). Readable by everyone: the time
    // clock tells the employee work is suspended today, and a rule they cannot see is one
    // they discover by being marked absent. Writes are HR-only, below.
    Route::get('work-suspensions', [WorkSuspensionController::class, 'index']);

    // Early-out (undertime) requests — separate from leave (always unpaid). Caller
    // files + views own; service scopes by role.
    Route::get('early-out-requests', [EarlyOutRequestController::class, 'index']);
    Route::post('early-out-requests', [EarlyOutRequestController::class, 'store']);
    Route::post('early-out-requests/{earlyOutRequest}/cancel', [EarlyOutRequestController::class, 'cancel']);
    Route::get('overtime-requests', [OvertimeRequestController::class, 'index']);
    Route::get('overtime-requests/settings', [OvertimeRequestController::class, 'settings']);
    Route::get('overtime-requests/classify', [OvertimeRequestController::class, 'classify']);
    Route::post('overtime-requests', [OvertimeRequestController::class, 'store']);
    // Not HR-gated any more: the service lets the OWNER withdraw a pending request, while
    // cancelling an APPROVED one (which can raise an overpayment) stays HR-only.
    Route::post('overtime-requests/{overtimeRequest}/cancel', [OvertimeRequestController::class, 'cancel']);

    // Attendance — the caller clocks their own time; HR sees the roster.
    Route::get('me/attendance', [AttendanceController::class, 'me']);
    // The caller's own month-by-month attendance record (self-service counterpart to the
    // Reports hub's Attendance Summary). Identity from the token — see the controller.
    Route::get('me/attendance-report', [AttendanceController::class, 'myReport']);
    // The caller's own violation flags (read-only; HR's /violations is role-gated).
    Route::get('me/violations', [ViolationController::class, 'me']);
    Route::post('attendance/clock-in', [AttendanceController::class, 'clockIn']);
    Route::post('attendance/clock-out', [AttendanceController::class, 'clockOut']);
    Route::post('attendance/break', [AttendanceController::class, 'break']);
    // Off-site time-in: submit a selfie for HR to approve, and read back its status.
    Route::post('attendance/remote-checkin', [RemoteCheckinController::class, 'store'])->middleware('throttle:20,1,hr-upload:');
    Route::get('me/remote-checkin', [RemoteCheckinController::class, 'me']);

    // Reimbursement — caller files + views own; HR sees all + decides.
    Route::get('reimbursements', [ReimbursementController::class, 'index']);
    Route::post('reimbursements', [ReimbursementController::class, 'store'])->middleware('throttle:20,1,hr-upload:');
    // Withdraw one you filed by mistake. Ownership + 'pending only' enforced in the service.
    Route::post('reimbursements/{reimbursement}/cancel', [ReimbursementController::class, 'cancel']);

    // Requests — the caller files + views their own document/change requests.
    Route::get('me/documents', [EmployeeController::class, 'myDocuments']);
    Route::get('me/document-requests', [DocumentRequestController::class, 'index']);
    Route::post('me/document-requests', [DocumentRequestController::class, 'store']);
    Route::get('me/change-requests', [ChangeRequestController::class, 'index']);
    Route::post('me/change-requests', [ChangeRequestController::class, 'store']);
    Route::get('me/payslip-requests', [PayslipRequestController::class, 'index']);
    Route::post('me/payslip-requests', [PayslipRequestController::class, 'store']);
    Route::get('me/payroll-inquiries', [PayrollInquiryController::class, 'index']);
    Route::post('me/payroll-inquiries', [PayrollInquiryController::class, 'store'])->middleware('throttle:20,1,hr-upload:');
    Route::post('me/payroll-inquiries/{payrollInquiry}/cancel', [PayrollInquiryController::class, 'cancel']);

    // FAQ / knowledge base — read (role-scoped: employees see published only) + view count.
    Route::get('faqs', [FaqController::class, 'index']);
    Route::post('faqs/{faq}/view', [FaqController::class, 'view']);

    // Concerns + meeting invites — the caller's own (anonymity preserved server-side).
    Route::get('me/concerns', [ConcernController::class, 'meIndex']);
    Route::post('me/concerns', [ConcernController::class, 'store'])->middleware('throttle:20,1,hr-upload:');
    Route::post('me/concerns/{concern}/cancel', [ConcernController::class, 'cancel']);
    // Answering HR's request to be identified. NOT role-gated on purpose — this decision
    // belongs to the reporter, and the service checks the caller IS them.
    Route::post('me/concern-reveal-requests/{revealRequest}/accept', [ConcernController::class, 'acceptReveal']);
    Route::post('me/concern-reveal-requests/{revealRequest}/decline', [ConcernController::class, 'declineReveal']);
    Route::get('me/meetings', [MeetingController::class, 'meIndex']);
    Route::post('me/meetings/{meeting}/respond', [MeetingController::class, 'respond']);

    // The caller's own loans: what they owe, and the authorization that makes any
    // deduction lawful. Not hr_officer-gated: this is the employee's own record and consent.
    Route::get('me/loans', [EmployeeLoanController::class, 'mine']);
    Route::post('me/loans', [EmployeeLoanController::class, 'requestLoan']);
    Route::post('me/loans/{loan}/authorize', [EmployeeLoanController::class, 'authorizeDeduction']);
    // The projected amortisation. Open to the loan's owner as well as HR: this is what the
    // employee consents to under Art. 113, and the controller checks ownership.
    Route::get('loans/{loan}/schedule', [EmployeeLoanController::class, 'schedule']);

    // My Benefits — the caller's own enrolled benefit programs (identity from token).
    Route::get('me/benefit-programs', [BenefitProgramController::class, 'mine']);

    /* Statutory contribution rates, readable by ANY signed-in user rather than HR only. */
    Route::get('contribution-settings', [BenefitController::class, 'settingsIndex']);

    // Which contributions the caller pays into, READ-ONLY (identity from token). The hr_officer
    // reader for the same data stays where it is: an employee may see what is deducted from them —
    // every payslip itemises it — but deciding it is HR's. Without this the Compensation tab
    // rendered the enrolment block against a 403, which drew three ticked, clickable checkboxes
    // for a change the server was always going to refuse.
    Route::get('me/benefits', [BenefitController::class, 'myBenefits']);

    // Payslips — the caller's own (identity from token).
    Route::get('me/next-payday', [PayrollController::class, 'myNextPayday']);
    Route::get('me/payslips', [PayrollController::class, 'myPayslips']);
    Route::get('me/payslips/{payslip}', [PayrollController::class, 'showPayslip']);
    Route::get('me/payslips/{payslip}/pdf', [PayrollController::class, 'myPayslipPdf'])->middleware('throttle:20,1,hr-pdf:');

    Route::middleware('role:hr_officer|super_admin')->group(function () {
        Route::get('dashboard/stats', [DashboardController::class, 'hrStats']);
        // The withholding-tax bands. Editable, because the schedule is statute that CHANGES
        // (the BIR table has moved once already, and a bill to move it again is in the House
        // as of Aug 2026) and a rate change should not need a migration and a deploy. The
        // danger an editable table carries — one mistyped figure mis-withholding for everyone
        // in a band at once — is answered by SaveTaxTableRequest, which refuses a table whose
        // bands do not add up, not by keeping the numbers in code.
        Route::get('tax-table', [TaxTableController::class, 'show']);
        Route::match(['put', 'patch'], 'tax-table', [TaxTableController::class, 'update']);
        Route::delete('tax-versions/{taxVersion}', [TaxTableController::class, 'destroyVersion']);
        Route::get('reports/{key}', [ReportController::class, 'show']);
        Route::post('reports/{key}/export', [ReportController::class, 'export'])->middleware('throttle:10,1,report-export:');
        Route::get('attendance', [AttendanceController::class, 'index']);
        Route::get('attendance/summary', [AttendanceController::class, 'summary']);
        // Field time-ins queue. Declared BEFORE attendance/adjust is irrelevant, but these
        // must stay above any `attendance/{param}` route if one is ever added.
        Route::get('attendance/remote-checkins', [RemoteCheckinController::class, 'index']);
        Route::post('attendance/remote-checkins/{remoteCheckin}/approve', [RemoteCheckinController::class, 'approve']);
        Route::post('attendance/remote-checkins/{remoteCheckin}/reject', [RemoteCheckinController::class, 'reject']);
        // Stale-attendance review queue (item 6). Same "keep it above any attendance/{param}"
        // caveat as the field time-ins above.
        Route::get('attendance/open-shifts', [AttendanceController::class, 'openShifts']);
        // Management attendance is GENERATED, not punched — these two say whether the nightly
        // job actually ran, and let HR run it now when it did not.
        Route::get('attendance/management-generation', [AttendanceController::class, 'managementGeneration']);
        Route::post('attendance/management-generation', [AttendanceController::class, 'regenerateManagement']);
        Route::post('attendance/adjust', [AttendanceController::class, 'adjust']);

        // Daily grace-period override (HR only, per-date).
        // statutory/benefit deductions a thin payslip could not collect. Recovery is
        // automatic on the next run with room; this is the visibility for the ones that cannot be.
        Route::get('deduction-arrears', [DeductionArrearsController::class, 'index']);
        // Retroactive pay differences waiting to be settled.
        Route::get('retro-flags', [RetroFlagController::class, 'index']);
        Route::post('retro-flags/{retroFlag}/resolve', [RetroFlagController::class, 'resolve']);
        Route::get('daily-overrides', [DailyOverrideController::class, 'show']);
        Route::match(['put', 'patch'], 'daily-overrides', [DailyOverrideController::class, 'upsert']);

        // Attendance → violation flags.
        Route::get('violations', [ViolationController::class, 'index']);
        Route::post('violations/recompute', [ViolationController::class, 'recompute'])->middleware('throttle:5,1,hr-bulk:');
        Route::post('violations/{violation}/resolve', [ViolationController::class, 'resolve']);
        Route::post('violations/{violation}/reopen', [ViolationController::class, 'reopen']);

        // Payroll (HR only).
        // The payroll run holds the payroll tables inside ONE transaction for its whole
        // duration (PayrollService::run), so repeated submits queue up long lock waits rather than
        // simply wasting CPU. Exports are the other expensive path: each one reads every payslip in
        // a run and builds a file. 5/min and 10/min are far above any human workflow and far below
        // what it takes to hurt the database.
        Route::get('payroll/settings', [PayrollController::class, 'settings']);
        Route::put('payroll/settings', [PayrollController::class, 'updateSettings']);
        Route::get('payroll/runs', [PayrollController::class, 'runsIndex']);
        // BEFORE any payroll/runs/{run} route, so 'for-cycle' is not read as an id.
        Route::get('payroll/runs/for-cycle', [PayrollController::class, 'runForCycle']);
        Route::get('payroll/thirteenth-month', [PayrollController::class, 'thirteenthMonth']);
        Route::get('payroll/thirteenth-month/preview', [PayrollController::class, 'previewThirteenthMonth']);
        // Pay it, and withhold on what it pushes past the ₱90,000 exemption.
        Route::post('payroll/thirteenth-month/run', [PayrollController::class, 'runThirteenthMonth'])->middleware('throttle:5,1,payroll-13th:');
        // Who this run would pay below the regional floor, and the one-click fix.
        // The check is read-only; the adjustment writes salaries, so it carries a run-sized limit.
        // Item 12 — attendance exceptions for a cycle, read before the run turns them into money.
        Route::get('payroll/attendance-exceptions', [PayrollController::class, 'attendanceExceptions']);
        Route::get('payroll/minimum-wage-check', [PayrollController::class, 'minimumWageCheck']);
        Route::post('payroll/minimum-wage-adjustments', [PayrollController::class, 'applyMinimumWage'])
            ->middleware('throttle:5,1,payroll-minimum-wage:');
        Route::post('payroll/run', [PayrollController::class, 'run'])->middleware('throttle:5,1,payroll-run:');
        // A correction added to the period's OWN payslips. Same tight limit as a run: it
        // changes payslips, and the amounts are typed by a person rather than computed.
        Route::post('payroll/runs/{payrollRun}/amendments', [PayrollController::class, 'amendRun'])
            ->middleware('throttle:5,1,payroll-amendment:');
        // The same correction applied to ONE LINE across the whole period — a charge waived for
        // everybody after an outage. Run-sized limit: it rewrites every payslip in the run.
        Route::post('payroll/runs/{payrollRun}/line-corrections', [PayrollController::class, 'overrideRunLine'])
            ->middleware('throttle:5,1,payroll-amendment:');
        // Recompute a period that has already run. The preview is read-only but costs a whole run
        // (it computes for real and rolls back), so both carry the run's own limit.
        Route::get('payroll/runs/{payrollRun}/rerun-preview', [PayrollController::class, 'rerunPreview'])
            ->middleware('throttle:5,1,payroll-rerun-preview:');
        Route::post('payroll/runs/{payrollRun}/rerun', [PayrollController::class, 'rerunRun'])
            ->middleware('throttle:5,1,payroll-rerun:');
        Route::get('payroll/runs/{payrollRun}', [PayrollController::class, 'show']);
        Route::get('payroll/runs/{payrollRun}/export', [PayrollController::class, 'exportRun'])->middleware('throttle:10,1,payroll-export:');
        Route::post('payroll/runs/{payrollRun}/lock', [PayrollController::class, 'lockRun']);
        Route::post('payroll/runs/{payrollRun}/unlock', [PayrollController::class, 'unlockRun']);
        Route::post('payslips/{payslip}/mark-done', [PayrollController::class, 'markDone']);
        Route::post('payslips/{payslip}/reopen', [PayrollController::class, 'reopenPayslip']);
        // Correcting ONE LINE on one payslip — the engine said ₱500, HR says ₱300, and the
        // document prints both. A higher limit than the batch routes above because this is how a
        // payslip is now corrected at all: a person walking a document and fixing three lines on
        // it is the ordinary case, not a burst worth refusing.
        Route::post('payslips/{payslip}/line-corrections', [PayrollController::class, 'overridePayslipLine'])
            ->middleware('throttle:30,1,payslip-correction:');
        Route::delete('payslips/{payslip}/line-corrections/{amendment}', [PayrollController::class, 'removePayslipLineCorrection'])
            ->middleware('throttle:30,1,payslip-correction:');
        // "Seen it, my figure still stands" — clears the drift warning a recompute raises when the
        // engine's own figure moved out from under a correction. Moves no money, so it shares the
        // correction limit rather than the heavier run limits.
        Route::post('payslips/{payslip}/line-corrections/{amendment}/acknowledge', [PayrollController::class, 'acknowledgePayslipLineCorrection'])
            ->middleware('throttle:30,1,payslip-correction:');
        Route::get('payslips/{payslip}', [PayrollController::class, 'showHrPayslip']);
        Route::get('payslips/{payslip}/pdf', [PayrollController::class, 'payslipPdf'])->middleware('throttle:20,1,hr-pdf:');

        // Disbursement — where the money actually went. Building a batch reads every
        // payslip in a run and writes one row per employee, so it carries the same 5/min limit as
        // the run that produced it; the bank file is an export and matches the export limit.
        Route::get('payment-batches', [PaymentBatchController::class, 'index']);
        Route::post('payment-batches', [PaymentBatchController::class, 'store'])->middleware('throttle:5,1,payment-batch:');
        // BEFORE the {paymentBatch} route, or 'summary' is read as a batch id.
        Route::get('payment-batches/summary', [PaymentBatchController::class, 'summary']);
        Route::get('payment-batches/{paymentBatch}', [PaymentBatchController::class, 'show']);
        Route::get('payment-batches/{paymentBatch}/bank-file', [PaymentBatchController::class, 'bankFile'])->middleware('throttle:10,1,bank-file:');
        // Reconciliation — what the bank says actually happened. The import moves payslips into
        // `disbursed`, so it previews unless explicitly confirmed.
        Route::post('payment-batches/{paymentBatch}/reconcile', [PaymentBatchController::class, 'reconcile'])->middleware('throttle:10,1,reconcile:');
        Route::patch('payments/{payment}', [PaymentBatchController::class, 'settle']);

        // Recruitment (HR only).
        Route::get('requisitions', [RecruitmentController::class, 'requisitionsIndex']);
        Route::post('requisitions', [RecruitmentController::class, 'storeRequisition']);
        Route::match(['put', 'patch'], 'requisitions/{requisition}', [RecruitmentController::class, 'updateRequisition']);
        Route::delete('requisitions/{requisition}', [RecruitmentController::class, 'destroyRequisition']);
        Route::get('candidates', [RecruitmentController::class, 'candidatesIndex']);
        Route::post('candidates', [RecruitmentController::class, 'storeCandidate'])->middleware('throttle:20,1,hr-upload:');
        Route::post('candidates/{candidate}/move', [RecruitmentController::class, 'moveCandidate']);
        Route::post('candidates/{candidate}/hire', [RecruitmentController::class, 'hireCandidate']);
        Route::get('candidates/{candidate}/email/draft', [RecruitmentController::class, 'emailDraft']);
        Route::get('candidates/{candidate}/emails', [RecruitmentController::class, 'emailHistory']);
        // Attachments push this past a plain JSON POST, so it shares the upload throttle's spirit
        // while keeping the tighter send limit.
        Route::post('candidates/{candidate}/email', [RecruitmentController::class, 'sendEmail'])->middleware('throttle:10,1,hr-email:');
        Route::post('candidates/{candidate}/reject', [RecruitmentController::class, 'reject']);
        // Counts everybody's claims, so it sits inside the HR-only group.
        // The dashboard's approvals card — thirteen queues merged server-side.
        Route::get('approvals/pending', [ApprovalQueueController::class, 'index']);
        Route::get('reimbursements/summary', [ReimbursementController::class, 'summary']);
        Route::post('reimbursements/{reimbursement}/approve', [ReimbursementController::class, 'approve']);
        Route::post('reimbursements/{reimbursement}/reject', [ReimbursementController::class, 'reject']);
        Route::post('reimbursements/{reimbursement}/mark-paid', [ReimbursementController::class, 'markPaid']);
        Route::post('reimbursements/{reimbursement}/reopen', [ReimbursementController::class, 'reopen']);
        // Approve/decline leave (HR only).
        Route::post('leave-requests/{leaveRequest}/approve', [LeaveController::class, 'approve']);
        Route::post('leave-requests/{leaveRequest}/reject', [LeaveController::class, 'reject']);
        Route::post('leave-requests/{leaveRequest}/reopen', [LeaveController::class, 'reopen']);
        // Approve/decline half-day requests (HR only).
        Route::post('half-day-requests/{halfDayRequest}/approve', [HalfDayRequestController::class, 'approve']);
        Route::post('half-day-requests/{halfDayRequest}/reject', [HalfDayRequestController::class, 'reject']);
        Route::post('half-day-requests/{halfDayRequest}/reopen', [HalfDayRequestController::class, 'reopen']);
        // Approve/decline work requests (HR only). Approving WRITES the attendance row,
        // stamped at the moment the employee pressed the button.
        Route::post('work-requests/{workRequest}/approve', [WorkRequestController::class, 'approve']);
        Route::post('work-requests/{workRequest}/reject', [WorkRequestController::class, 'reject']);
        Route::post('work-requests/{workRequest}/reopen', [WorkRequestController::class, 'reopen']);
        // Approve/decline early-out requests (HR only).
        Route::post('early-out-requests/{earlyOutRequest}/approve', [EarlyOutRequestController::class, 'approve']);
        Route::post('early-out-requests/{earlyOutRequest}/reject', [EarlyOutRequestController::class, 'reject']);
        Route::post('early-out-requests/{earlyOutRequest}/reopen', [EarlyOutRequestController::class, 'reopen']);
        // Item 10 — HR files overtime for an employee on a date that has passed. Above the
        // `{overtimeRequest}` routes below so "for-employee" is never read as an id.
        Route::post('overtime-requests/for-employee', [OvertimeRequestController::class, 'storeForEmployee']);
        Route::post('overtime-requests/{overtimeRequest}/approve', [OvertimeRequestController::class, 'approve']);
        Route::post('overtime-requests/{overtimeRequest}/reject', [OvertimeRequestController::class, 'reject']);
        Route::post('overtime-requests/{overtimeRequest}/reopen', [OvertimeRequestController::class, 'reopen']);
        // auto-mode overtime the caps held back, awaiting someone's yes. Derived on read:
        // it writes nothing, and an approval on the ordinary queue above makes an item disappear.
        Route::get('overtime-requests/auto-review', [OvertimeRequestController::class, 'autoReview']);
        Route::get('overtime-requests/auto-review/totals', [OvertimeRequestController::class, 'autoReviewTotals']);
        Route::post('departments', [DepartmentController::class, 'store']);
        Route::match(['put', 'patch'], 'departments/{department}', [DepartmentController::class, 'update']);
        Route::delete('departments/{department}', [DepartmentController::class, 'destroy']);

        // Employee directory + records (HR only).
        Route::get('employees', [EmployeeController::class, 'index']);
        Route::post('employees', [EmployeeController::class, 'store']);
        // `withTrashed()` on the READ only. The directory's "Former staff" roster links every card
        // here, and a separated employee is soft-deleted, so without it those cards were dead links —
        // and with them the only route to Reinstate/Rehire, which live on the record's Job tab.
        // Deliberately not on the update below: an archived record is read-only (the record page
        // drops its edit affordances for a leaver), so a Save that resolved would be a Save the rest
        // of the record's endpoints could not honour anyway.
        // BEFORE the {employee} route below, or 'options' is read as an employee id.
        Route::get('employees/options', [EmployeeController::class, 'options']);
        Route::get('employees/{employee}', [EmployeeController::class, 'show'])->withTrashed();
        Route::match(['put', 'patch'], 'employees/{employee}', [EmployeeController::class, 'update']);
        Route::get('employees/{employee}/salary-history', [EmployeeController::class, 'salaryHistory']);

        // Hand-corrections to the two timelines on the record. Both are also written automatically —
        // the employment one by a job-title change, the compensation one by a salary change — and
        // that stays as it is; these exist for the mistakes automation cannot fix.
        Route::get('employees/{employee}/employment-histories', [EmploymentHistoryController::class, 'index']);
        Route::post('employees/{employee}/employment-histories', [EmploymentHistoryController::class, 'store']);
        Route::match(['put', 'patch'], 'employment-histories/{employmentHistory}', [EmploymentHistoryController::class, 'update']);
        Route::delete('employment-histories/{employmentHistory}', [EmploymentHistoryController::class, 'destroy']);
        Route::post('employees/{employee}/salary-histories', [SalaryHistoryController::class, 'store']);
        Route::match(['put', 'patch'], 'salary-histories/{salaryHistory}', [SalaryHistoryController::class, 'update']);
        Route::delete('salary-histories/{salaryHistory}', [SalaryHistoryController::class, 'destroy']);
        // Profile picture: set/replace or clear (falls back to initials).
        Route::post('employees/{employee}/photo', [EmployeeController::class, 'updatePhoto'])->middleware('throttle:20,1,hr-upload:');
        Route::delete('employees/{employee}/photo', [EmployeeController::class, 'destroyPhoto']);
        // Loans & cash advances repaid through payroll.
        // The unmasked bank/government ID numbers. Audited per view: an HR officer must
        // be able to see a bank account to pay someone, so the control is that looking is
        // deliberate and traceable, not that it is refused.
        // Final pay. Its own limiter, and a low one: it writes a payslip and the amounts
        // are entered by a person.
        // `withTrashed()` is REQUIRED here, not optional. Final pay is by definition for
        // someone who has left, and a separation soft-deletes the record, so without it route model
        // binding 404s for every employee this endpoint exists to pay. shipped unreachable
        // twice over: nothing wrote `separation_date`, and this route could not resolve a leaver.
        Route::post('employees/{employee}/final-pay', [PayrollController::class, 'runFinalPay'])
            ->withTrashed()
            ->middleware('throttle:5,1,final-pay:');
        Route::get('employees/{employee}/sensitive', [EmployeeSensitiveController::class, 'show'])
            ->middleware('throttle:20,1,employee-sensitive:');
        // Every employee's loans, for the approvals queue. Declared before `loans/{loan}` so the
        // literal path is never read as an id.
        Route::get('loans', [EmployeeLoanController::class, 'all']);
        Route::get('employees/{employee}/loans', [EmployeeLoanController::class, 'index']);
        Route::post('employees/{employee}/loans', [EmployeeLoanController::class, 'store']);
        Route::match(['put', 'patch'], 'loans/{loan}', [EmployeeLoanController::class, 'update']);
        Route::post('loans/{loan}/cancel', [EmployeeLoanController::class, 'cancel']);
        Route::post('loans/{loan}/reopen', [EmployeeLoanController::class, 'reopen']);
        // HR decides on an employee's request. Neither starts a deduction.
        Route::post('loans/{loan}/approve', [EmployeeLoanController::class, 'approve']);
        Route::post('loans/{loan}/reject', [EmployeeLoanController::class, 'reject']);
        // Schedule a meeting with an employee (e.g. about attendance/violations).
        Route::post('employees/{employee}/meetings', [MeetingController::class, 'store']);
        Route::post('employees/{employee}/activate', [EmployeeController::class, 'activate']);
        Route::post('employees/{employee}/reopen-onboarding', [EmployeeController::class, 'reopenOnboarding']);
        // The employment lifecycle's exit and its two ways back in.
        Route::post('employees/{employee}/separate', [EmployeeController::class, 'separate']);
        // `withTrashed()` on both: a separated employee IS soft-deleted, so without it route model
        // binding 404s and the reversal is unreachable — which is the gap describes.
        Route::post('employees/{employee}/reinstate', [EmployeeController::class, 'reinstate'])
            ->withTrashed();
        Route::post('employees/{employee}/rehire', [EmployeeController::class, 'rehire'])
            ->withTrashed();
        // Grant/revoke HR-officer access for this employee's user account.
        Route::post('employees/{employee}/hr-role', [EmployeeController::class, 'setHrRole']);
        // Documents tab: list / upload / delete.
        Route::get('employees/{employee}/documents', [EmployeeController::class, 'documentsIndex']);
        Route::post('employees/{employee}/documents', [EmployeeController::class, 'storeDocument'])->middleware('throttle:20,1,hr-upload:');
        Route::patch('employees/{employee}/documents/{document}', [EmployeeController::class, 'renameDocument']);
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
        // Shared-mode: this person's single pool balance (entitled days) for the year.
        Route::get('employees/{employee}/shared-leave-balance', [LeaveConfigController::class, 'sharedBalanceShow']);
        Route::put('employees/{employee}/shared-leave-balance', [LeaveConfigController::class, 'upsertSharedBalance']);

        // Company → Benefits: flexible benefit-programs catalogue (HR-authored).
        Route::get('benefit-programs', [BenefitProgramController::class, 'index']);
        Route::post('benefit-programs', [BenefitProgramController::class, 'store']);
        Route::match(['put', 'patch'], 'benefit-programs/{benefitProgram}', [BenefitProgramController::class, 'update']);
        Route::delete('benefit-programs/{benefitProgram}', [BenefitProgramController::class, 'destroy']);
        // One employee's own allowance amounts. NOT `/benefits`: that path is the
        // statutory checklist, a different table with a near-identical name (B5 item 8).
        // Who is on a company plan, at what tier and share. The capability that went
        // with "Add a Plan": without it a plan can be published and nobody can ever be put on it.
        Route::get('benefit-programs/{benefitProgram}/enrollments', [BenefitProgramController::class, 'planEnrollments']);
        Route::match(['put', 'patch'], 'benefit-programs/{benefitProgram}/enrollments', [BenefitProgramController::class, 'updatePlanEnrollments']);
        Route::delete('benefit-programs/{benefitProgram}/enrollments/{employee}', [BenefitProgramController::class, 'endPlanEnrollment']);
        Route::get('employees/{employee}/allowances', [BenefitProgramController::class, 'employeeAllowances']);
        Route::match(['put', 'patch'], 'employees/{employee}/allowances/{benefitProgram}', [BenefitProgramController::class, 'setEmployeeAllowance']);

        // Company → Benefits: per-employee checklist. (The rates themselves are readable by
        // any signed-in user — see the employee group above.)
        // Dated statutory rate versions.
        Route::get('contribution-versions', [BenefitController::class, 'versionsIndex']);
        Route::post('contribution-versions', [BenefitController::class, 'storeVersion']);
        // Read the agency's published spreadsheet (a dry run; it saves nothing), and
        // remove a version saved with the wrong date.
        Route::post('contribution-versions/import-schedule', [BenefitController::class, 'importSchedule'])->middleware('throttle:5,1,hr-bulk:');
        Route::delete('contribution-versions/{contributionVersion}', [BenefitController::class, 'destroyVersion']);
        Route::match(['put', 'patch'], 'contribution-settings/{contributionSetting}', [BenefitController::class, 'updateSetting']);
        Route::get('employees/{employee}/benefits', [BenefitController::class, 'employeeBenefitsIndex']);
        Route::match(['put', 'patch'], 'employees/{employee}/benefits', [BenefitController::class, 'updateEmployeeBenefits']);
        // What the rates would charge, beside any per-employee override.

        // Company → Settings: work schedules + per-employee assignment + attendance policy.
        Route::get('work-schedules', [WorkScheduleController::class, 'index']);
        Route::post('work-schedules', [WorkScheduleController::class, 'store']);
        Route::match(['put', 'patch'], 'work-schedules/{workSchedule}', [WorkScheduleController::class, 'update']);
        Route::delete('work-schedules/{workSchedule}', [WorkScheduleController::class, 'destroy']);
        Route::get('employees/{employee}/schedule', [WorkScheduleController::class, 'employeeSchedule']);
        Route::match(['put', 'patch'], 'employees/{employee}/schedule', [WorkScheduleController::class, 'updateEmployeeSchedule']);
        Route::get('attendance-policy', [AttendancePolicyController::class, 'show']);
        Route::match(['put', 'patch'], 'attendance-policy', [AttendancePolicyController::class, 'update']);

        // Company → Settings: who the employer is, as printed on every letterhead.
        Route::match(['put', 'patch'], 'company-profile', [CompanyProfileController::class, 'update']);
        Route::post('company-profile/logo', [CompanyProfileController::class, 'updateLogo'])->middleware('throttle:20,1,hr-upload:');
        Route::delete('company-profile/logo', [CompanyProfileController::class, 'destroyLogo']);

        // Requests → Document + Profile-change queues (HR only).
        Route::get('document-requests', [DocumentRequestController::class, 'index']);
        Route::post('document-requests/{documentRequest}/fulfill', [DocumentRequestController::class, 'fulfill']);
        Route::post('document-requests/{documentRequest}/reject', [DocumentRequestController::class, 'reject']);
        Route::post('document-requests/{documentRequest}/reopen', [DocumentRequestController::class, 'reopen']);
        Route::get('change-requests', [ChangeRequestController::class, 'index']);
        Route::post('change-requests/{changeRequest}/approve', [ChangeRequestController::class, 'approve']);
        Route::post('change-requests/{changeRequest}/reject', [ChangeRequestController::class, 'reject']);
        Route::post('change-requests/{changeRequest}/reopen', [ChangeRequestController::class, 'reopen']);

        // Requests → Payroll (payslip requests + inquiries, HR only).
        Route::get('payslip-requests', [PayslipRequestController::class, 'index']);
        Route::post('payslip-requests/{payslipRequest}/approve', [PayslipRequestController::class, 'approve']);
        Route::post('payslip-requests/{payslipRequest}/reject', [PayslipRequestController::class, 'reject']);
        Route::post('payslip-requests/{payslipRequest}/reopen', [PayslipRequestController::class, 'reopen']);
        Route::get('payroll-inquiries', [PayrollInquiryController::class, 'index']);
        Route::post('payroll-inquiries/{payrollInquiry}/progress', [PayrollInquiryController::class, 'progress']);
        Route::post('payroll-inquiries/{payrollInquiry}/resolve', [PayrollInquiryController::class, 'resolve']);
        Route::post('payroll-inquiries/{payrollInquiry}/reopen', [PayrollInquiryController::class, 'reopen']);

        // Requests → Concerns (HR/Admin only). Anonymity enforced in ConcernResource.
        Route::get('concerns', [ConcernController::class, 'index']);
        Route::post('concerns/{concern}/status', [ConcernController::class, 'status']);
        Route::post('concerns/{concern}/reopen', [ConcernController::class, 'reopen']);
        Route::post('concerns/{concern}/meetings', [ConcernController::class, 'scheduleMeeting']);
        // Ask an anonymous reporter to identify themselves. Asking only — the identity is not
        // emitted until they accept, and the answer is theirs to give.
        Route::post('concerns/{concern}/reveal-requests', [ConcernController::class, 'requestReveal']);

        // Finance → Office Expenses (HR only).
        Route::get('office-expenses', [OfficeExpenseController::class, 'index']);
        Route::get('office-expenses/summary', [OfficeExpenseController::class, 'summary']);
        Route::post('office-expenses', [OfficeExpenseController::class, 'store'])->middleware('throttle:20,1,hr-upload:');
        Route::match(['put', 'patch'], 'office-expenses/{officeExpense}', [OfficeExpenseController::class, 'update']);
        Route::post('office-expenses/{officeExpense}/approve', [OfficeExpenseController::class, 'approve']);
        Route::post('office-expenses/{officeExpense}/reject', [OfficeExpenseController::class, 'reject']);
        Route::post('office-expenses/{officeExpense}/reopen', [OfficeExpenseController::class, 'reopen']);
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
        // Work suspensions — deliberately NOT holidays: a holiday is a legal, classified
        // calendar date; this is the company cancelling one day and saying whether it is paid.
        Route::post('work-suspensions', [WorkSuspensionController::class, 'store']);
        Route::match(['put', 'patch'], 'work-suspensions/{workSuspension}', [WorkSuspensionController::class, 'update']);
        Route::delete('work-suspensions/{workSuspension}', [WorkSuspensionController::class, 'destroy']);

        // Company → FAQ authoring (HR only).
        Route::post('faqs', [FaqController::class, 'store']);
        Route::match(['put', 'patch'], 'faqs/{faq}', [FaqController::class, 'update']);
        Route::delete('faqs/{faq}', [FaqController::class, 'destroy']);
        Route::post('faqs/{faq}/publish', [FaqController::class, 'publish']);
        Route::post('faqs/{faq}/archive', [FaqController::class, 'archive']);
        Route::post('faqs/{faq}/pin', [FaqController::class, 'pin']);
    });
});
