<?php

namespace App\Modules\Hr\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Hr\Http\Requests\AmendPayrollRunRequest;
use App\Modules\Hr\Http\Requests\CorrectPayslipLineRequest;
use App\Modules\Hr\Http\Requests\CorrectRunLineRequest;
use App\Modules\Hr\Http\Requests\ApplyMinimumWageRequest;
use App\Modules\Hr\Http\Requests\MinimumWageReviewRequest;
use App\Modules\Hr\Http\Requests\ReopenRequest;
use App\Modules\Hr\Http\Requests\RerunPayrollRunRequest;
use App\Modules\Hr\Http\Requests\RunFinalPayRequest;
use App\Modules\Hr\Http\Requests\RunPayrollRequest;
use App\Modules\Hr\Http\Requests\UpdatePayrollSettingsRequest;
use App\Modules\Hr\Http\Resources\PayrollRunResource;
use App\Modules\Hr\Http\Resources\PayslipResource;
use App\Modules\Hr\Http\Resources\PayslipSummaryResource;
use App\Modules\Hr\Models\Payslip;
use App\Modules\Hr\Models\PayslipAmendment;
use App\Modules\Hr\Models\PayrollRun;
use App\Modules\Hr\Models\PayrollSetting;
use App\Modules\Hr\Services\AuditService;
use App\Modules\Hr\Services\AttendanceService;
use App\Modules\Hr\Services\PayrollService;
use App\Modules\Hr\Services\PayScheduleService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

class PayrollController extends Controller
{
    public function __construct(
        private readonly PayrollService $service,
        private readonly PayScheduleService $schedule,
    ) {}

    // GET /api/v1/hr/payroll/settings — the pay schedule + the derived current period.
    public function settings(): JsonResponse
    {
        return response()->json(['data' => $this->settingsPayload()]);
    }

    // PUT /api/v1/hr/payroll/settings — HR changes how often payroll runs.
    public function updateSettings(UpdatePayrollSettingsRequest $request, AuditService $audit): JsonResponse
    {
        $setting = PayrollSetting::current();
        $validated = $request->validated();

        // The effective date governs the VERSION, it is not a settings field. Pulled
        // out before the diff below so it never appears in the audit line as a changed setting.
        $effectiveFrom = $validated['effective_from'] ?? null;
        unset($validated['effective_from']);

        // Item 13b.12 — this endpoint wrote NO audit entry at all, despite setting the pay
        // frequency, the deduction/tax timing and the de-minimis ceiling: every one of them
        // changes what lands on a payslip.
        $before = [];
        $after = [];
        foreach ($validated as $field => $value) {
            $old = $setting->getOriginal($field);
            if ($old != $value) {
                $before[$field] = $old;
                $after[$field] = $value;
            }
        }

        // Counted BEFORE the write: these are the employees the change is about to move, and
        // after it they are indistinguishable from anyone else in the destination group.
        $inheriting = app(PayScheduleService::class)->inheritingDefaultCount();

        // Declare the effective date, then save: the model's `updated` hook records the
        // version, so there is exactly one writer and no way to update without versioning.
        PayrollSetting::effectiveFrom($effectiveFrom);
        $setting->update($validated);

        if ($before !== []) {
            $description = 'Payroll settings updated: ' . implode(', ', array_keys($before));

            // A frequency change is not just a setting: every employee with no explicit
            // `pay_frequency` follows it, so this line silently re-assigns them to a different pay
            // group. Nothing is written to their records, so without this the move leaves no trace
            // anywhere and only surfaces when a run stops finding them.
            if (array_key_exists('frequency', $before) && $inheriting > 0) {
                $description .= sprintf(
                    ' — %d employee%s with no explicit pay frequency moved from the %s to the %s pay group',
                    $inheriting,
                    $inheriting === 1 ? '' : 's',
                    str_replace('_', '-', (string) $before['frequency']),
                    str_replace('_', '-', (string) $after['frequency']),
                );
            }

            $audit->log('updated', 'Payroll', $description, $setting, $before, $after);
        }

        return response()->json(['data' => $this->settingsPayload()]);
    }

    /** @return array<string, mixed> */
    private function settingsPayload(): array
    {
        $setting = PayrollSetting::current();

        return [
            'frequency' => $setting->frequency,
            'deduction_timing' => $setting->deduction_timing,
            'tax_timing' => $setting->tax_timing,
            // Which cutoff the monthly allowance lands on.
            'allowance_timing' => $setting->allowance_timing,
            'anchor_date' => $setting->anchor_date?->toDateString(),
            'pay_delay_days' => $setting->pay_delay_days,
            'other_benefits_annual_cap' => $setting->other_benefits_annual_cap === null ? null : (float) $setting->other_benefits_annual_cap,
            'minimum_daily_wage' => $setting->minimum_daily_wage === null ? null : (float) $setting->minimum_daily_wage,
            // How many people this default actually decides the pay group for, so the
            // Rules screen can say so BEFORE the frequency is changed rather than after.
            'inheriting_employee_count' => app(PayScheduleService::class)->inheritingDefaultCount(),
            'periods_per_year' => PayScheduleService::periodsPerYear($setting->frequency),
            'current_period' => $this->schedule->currentPeriod($setting),
            // Active pay groups (per-employee frequencies) — each runs on its own period.
            'pay_groups' => $this->schedule->payGroups($setting),
        ];
    }

    // GET /api/v1/hr/payroll/runs — HR run history.
    public function runsIndex(): JsonResponse
    {
        return PayrollRunResource::collection($this->service->listRuns())->response();
    }

    /** GET /api/v1/hr/payroll/minimum-wage-check — who this run would pay below the floor. */
    /**
     * GET /api/v1/hr/payroll/attendance-exceptions?start=&end= — what to look at before pricing
     * this cycle (item 12). HR only, read-only, derived on every call.
     */
    public function attendanceExceptions(Request $request, AttendanceService $attendance): JsonResponse
    {
        $data = $request->validate([
            'start' => ['required', 'date_format:Y-m-d'],
            'end' => ['required', 'date_format:Y-m-d', 'after_or_equal:start'],
        ]);

        return response()->json([
            'data' => $attendance->exceptionsForCycle($data['start'], $data['end']),
        ]);
    }

    public function minimumWageCheck(MinimumWageReviewRequest $request): JsonResponse
    {
        $cycle = $request->cycle();

        $review = $this->service->minimumWageReview(
            $cycle['frequency'],
            $cycle['cycle_start'],
            $cycle['cycle_end'],
        );

        return response()->json([
            'data' => $review['employees'],
            'meta' => [
                'minimum' => $review['minimum'],
                'basis' => $review['basis'],
                'divisor' => $review['divisor'],
                'cycle' => $review['cycle'],
                'checked' => $review['checked'],
                'flagged_count' => $review['flagged_count'],
                'exempt_count' => $review['exempt_count'],
            ],
        ]);
    }

    /** POST /api/v1/hr/payroll/minimum-wage-adjustments — raise the named employees to the floor. */
    public function applyMinimumWage(ApplyMinimumWageRequest $request): JsonResponse
    {
        $result = $this->service->applyMinimumWageAdjustments(
            $request->validated('employee_ids'),
            $request->validated('effective_date'),
            $request->validated('reason'),
            (bool) $request->validated('acknowledge_repricing', false),
        );

        return response()->json(['data' => $result]);
    }

    // POST /api/v1/hr/payroll/run — execute payroll for a cycle.
    public function run(RunPayrollRequest $request, AuditService $audit): JsonResponse
    {
        // The run was allowed past a minimum-wage breach. The reason is the record that
        // somebody decided this, rather than the run simply having been clicked; without it the
        // override would be invisible the moment it is questioned.
        if ($request->validated('minimum_wage_ack')) {
            $audit->log(
                'updated',
                'Payroll',
                'Ran payroll past a minimum-wage warning ('
                    . $request->validated('cycle_start') . ' – ' . $request->validated('cycle_end')
                    . '): ' . $request->validated('minimum_wage_ack_reason'),
            );
        }

        $run = $this->service->run(
            $request->validated('cycle_start'),
            $request->validated('cycle_end'),
            $request->validated('frequency'),
            $request->user(),
        );

        return PayrollRunResource::make($run)
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    /** GET /api/v1/hr/payroll/runs/{payrollRun}/rerun-preview — what recomputing would change. */
    public function rerunPreview(PayrollRun $payrollRun, Request $request): JsonResponse
    {
        $preview = $this->service->rerunPreview($payrollRun, $request->user());

        return response()->json(['data' => $preview['rows'], 'meta' => $preview['meta']]);
    }

    /**
     * POST /api/v1/hr/payroll/runs/{payrollRun}/rerun — recompute a period that has already run.
     *
     * The cycle comes from the run, never from the request: recomputing the wrong period because a
     * date was mistyped is the one mistake this route can structurally prevent.
     */
    public function rerunRun(PayrollRun $payrollRun, RerunPayrollRunRequest $request): JsonResponse
    {
        $run = $this->service->rerunRun(
            $payrollRun,
            $request->validated('reason'),
            $request->user(),
        );

        return PayrollRunResource::make($run)->response();
    }

    /** POST /api/v1/hr/payroll/runs/{payrollRun}/amendments — correct a period on its own payslips. */
    public function amendRun(PayrollRun $payrollRun, AmendPayrollRunRequest $request): JsonResponse
    {
        $run = $this->service->amendRun(
            $payrollRun,
            $request->validated('reason'),
            $request->validated('lines'),
            $request->validated('issued_on'),
            $request->user(),
            $request->validated('kind') ?? 'pay',
        );

        return PayrollRunResource::make($run)->response();
    }

    /** POST /api/v1/hr/payroll/runs/{payrollRun}/line-corrections — one line, the whole period. */
    public function overrideRunLine(PayrollRun $payrollRun, CorrectRunLineRequest $request): JsonResponse
    {
        $result = $this->service->overrideLineForRun(
            $payrollRun,
            $request->validated('target_key'),
            $request->validated('amount') === null ? null : (float) $request->validated('amount'),
            $request->validated('label'),
            $request->validated('reason'),
            $request->validated('issued_on'),
            $request->validated('employee_ids'),
            $request->user(),
        );

        return PayrollRunResource::make($result['run'])
            ->additional([
                'meta' => [
                    'applied' => $result['applied'],
                    // Whose payslip this could not touch, and why. Reported rather than swallowed:
                    // "corrected 12 payslips" reads as "corrected everyone" when it was 12 of 15.
                    'skipped' => $result['skipped'],
                ],
            ])
            ->response();
    }

    /** POST /api/v1/hr/employees/{employee}/final-pay — the terminal payslip a leaver is owed. */
    public function runFinalPay(RunFinalPayRequest $request, \App\Modules\Hr\Models\Employee $employee): JsonResponse
    {
        $run = $this->service->runFinalPay($employee, $request->validated(), $request->user());

        return PayrollRunResource::make($run)
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    // GET /api/v1/hr/payroll/thirteenth-month — per-employee 13th-month accrual for a year.
    public function thirteenthMonth(Request $request): JsonResponse
    {
        $year = (int) ($request->query('year') ?: now(config('app.timezone_local'))->year);

        return response()->json([
            'data' => $this->service->thirteenthMonth($year),
            'meta' => ['year' => $year],
        ]);
    }

    /** GET /api/v1/hr/payroll/thirteenth-month/preview, hr_officer only. */
    public function previewThirteenthMonth(Request $request): JsonResponse
    {
        $year = (int) ($request->query('year') ?: now(config('app.timezone_local'))->year);
        $existing = PayrollRun::where('type', 'thirteenth_month')
            ->whereYear('cycle_start', $year)
            ->first();

        return response()->json([
            'data' => $this->service->thirteenthMonthPreview($year),
            'meta' => [
                'year' => $year,
                // So the screen can say "recompute" rather than "pay", and stop offering a button
                // that a locked run would refuse anyway.
                'already_run' => $existing !== null,
                'run_id' => $existing?->id,
                'run_at' => $existing?->run_at?->toDateTimeString(),
                'is_locked' => (bool) $existing?->locked_at,
            ],
        ]);
    }

    /** POST /api/v1/hr/payroll/thirteenth-month/run, hr_officer only. */
    public function runThirteenthMonth(Request $request): JsonResponse
    {
        $data = $request->validate([
            'year' => ['required', 'integer', 'min:2000', 'max:2100'],
        ]);

        $run = $this->service->runThirteenthMonth($data['year'], $request->user());

        return response()->json(['data' => $this->service->runWithBreakdown($run)]);
    }

    // GET /api/v1/hr/payroll/runs/{payrollRun} — run + per-employee breakdown.
    public function show(PayrollRun $payrollRun): JsonResponse
    {
        return PayrollRunResource::make(
            $this->service->runWithBreakdown($payrollRun),
        )->response();
    }

    // POST /api/v1/hr/payroll/runs/{payrollRun}/lock — seal a fully-disbursed cycle so it
    // can never be recomputed.
    public function lockRun(Request $request, PayrollRun $payrollRun): JsonResponse
    {
        return PayrollRunResource::make(
            $this->service->lock($payrollRun, $request->user()),
        )->response();
    }

    // POST /api/v1/hr/payroll/runs/{payrollRun}/unlock — re-open a locked cycle. Audited.
    public function unlockRun(Request $request, PayrollRun $payrollRun): JsonResponse
    {
        return PayrollRunResource::make(
            $this->service->unlock($payrollRun, $request->user()),
        )->response();
    }

    /** POST /api/v1/hr/payslips/{payslip}/mark-done — declare one payslip paid. */
    public function markDone(Request $request, Payslip $payslip): JsonResponse
    {
        $key = $request->header('Idempotency-Key') ?? $request->input('idempotency_key');

        $this->service->markDone($payslip, $request->user(), $key ? (string) $key : null);

        return PayrollRunResource::make(
            $this->service->runWithBreakdown($payslip->payrollRun),
        )->response();
    }

    // POST /api/v1/hr/payslips/{payslip}/reopen — undo the above: the payslip goes back to
    // computed and any claims it paid go back to scheduled. Refused on a locked run.
    public function reopenPayslip(ReopenRequest $request, Payslip $payslip): JsonResponse
    {
        $this->service->reopenPayslip($payslip, $request->validated('reason'));

        return PayrollRunResource::make(
            $this->service->runWithBreakdown($payslip->payrollRun),
        )->response();
    }

    // GET /api/v1/hr/payroll/runs/{payrollRun}/export — the run as a file (HR only).
    public function exportRun(Request $request, PayrollRun $payrollRun): SymfonyResponse
    {
        if ($request->query('format') === 'xlsx') {
            ['path' => $path, 'name' => $name] = $this->service->runXlsx($payrollRun);

            // deleteFileAfterSend, because runXlsx() streams the workbook to a temp file rather
            // than building it in memory: nothing else is going to clean it up.
            return response()->download($path, $name, [
                'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            ])->deleteFileAfterSend(true);
        }

        $csv = $this->service->runCsv($payrollRun);
        $name = "payroll-{$payrollRun->cycle_start?->format('Y-m-d')}-to-{$payrollRun->cycle_end?->format('Y-m-d')}.csv";

        return response($csv, Response::HTTP_OK, [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => "attachment; filename=\"{$name}\"",
        ]);
    }

    // GET /api/v1/hr/payslips/{payslip} — one payslip in full (HR only). The run breakdown
    // only carries gross/deductions/net, so HR had no way to read a payslip without
    // downloading its PDF.
    public function showHrPayslip(Payslip $payslip): JsonResponse
    {
        // `amendments` is loaded here, not lazily inside the Resource: the document lists one line
        // per correction, and a payslip rendered without them falls back to a single lump figure.
        $payslip->load(['payrollRun.runner', 'employee.user', 'employee.department', 'amendments.creator']);

        return PayslipResource::make($payslip)->response();
    }

    /** POST /api/v1/hr/payslips/{payslip}/line-corrections — overrule the engine on ONE line. */
    public function overridePayslipLine(Payslip $payslip, CorrectPayslipLineRequest $request): JsonResponse
    {
        $payslip->loadMissing('payrollRun');

        $slip = $this->service->overrideLine(
            $payslip,
            $request->validated('target_key'),
            $request->validated('amount') === null ? null : (float) $request->validated('amount'),
            $request->validated('label'),
            $request->validated('reason'),
            $request->validated('issued_on'),
            $request->user(),
        );

        $slip->load(['payrollRun.runner', 'employee.user', 'employee.department', 'amendments.creator']);

        return PayslipResource::make($slip)->response();
    }

    /** DELETE /api/v1/hr/payslips/{payslip}/line-corrections/{amendment} — put a line back. */
    public function removePayslipLineCorrection(Payslip $payslip, PayslipAmendment $amendment): JsonResponse
    {
        $payslip->loadMissing('payrollRun');

        $slip = $this->service->removeLineOverride($payslip, $amendment, request()->user());

        $slip->load(['payrollRun.runner', 'employee.user', 'employee.department', 'amendments.creator']);

        return PayslipResource::make($slip)->response();
    }

    // POST /api/v1/hr/payslips/{payslip}/line-corrections/{amendment}/acknowledge — HR confirming
    // a drifted correction still stands. Changes no figure; only stops the run reporting it.
    public function acknowledgePayslipLineCorrection(Payslip $payslip, PayslipAmendment $amendment): JsonResponse
    {
        $payslip->loadMissing('payrollRun');

        $slip = $this->service->acknowledgeLineOverride($payslip, $amendment, request()->user());

        $slip->load(['payrollRun.runner', 'employee.user', 'employee.department', 'amendments.creator']);

        return PayslipResource::make($slip)->response();
    }

    // GET /api/v1/hr/payslips/{payslip}/pdf — one employee's payslip as a PDF (HR only).
    public function payslipPdf(Payslip $payslip): SymfonyResponse
    {
        $payslip->loadMissing(['payrollRun', 'employee.user', 'amendments.creator']);
        $slug = str($payslip->employee?->user?->name ?? 'employee')->slug();

        return $this->service->payslipPdf($payslip)
            ->download("payslip-{$slug}-{$payslip->payrollRun?->cycle_start?->format('Y-m-d')}.pdf");
    }

    // GET /api/v1/hr/me/next-payday — the caller's own next pay date, from their pay
    // group (their pay_frequency, or the company default). Identity from the token.
    public function myNextPayday(Request $request): JsonResponse
    {
        $setting = PayrollSetting::current();
        $employee = $request->user()->employee;
        // Empty-string pay_frequency (unset) falls back to the company default.
        $frequency = $employee?->pay_frequency ?: $setting->frequency;

        $payDate = $this->schedule->nextPayDate(
            $frequency,
            $setting->anchor_date,
            $setting->pay_delay_days ?? 0,
        );

        return response()->json(['data' => [
            'pay_date' => $payDate,
            'frequency' => $frequency,
        ]]);
    }

    // GET /api/v1/hr/me/payslips — the caller's payslip history (periods only, no amounts).
    public function myPayslips(Request $request): JsonResponse
    {
        $employee = $request->user()->employee;

        if (! $employee) {
            return response()->json(['data' => []]);
        }

        return PayslipSummaryResource::collection($this->service->myPayslips($employee))->response();
    }

    // GET /api/v1/hr/me/payslips/{payslip} — one payslip (own) + YTD. Viewable once the
    // payroll run is finalized (cutoff done); no request needed.
    public function showPayslip(Request $request, Payslip $payslip): JsonResponse
    {
        $employee = $request->user()->employee;

        if (! $employee || $payslip->employee_id !== $employee->id) {
            return response()->json(['message' => 'Not found.'], Response::HTTP_NOT_FOUND);
        }

        if (! $this->service->isViewable($payslip)) {
            return response()->json([
                'message' => 'This payslip is not yet available — its payroll run is still being finalized.',
            ], Response::HTTP_FORBIDDEN);
        }

        $payslip->load(['payrollRun.runner', 'employee.user', 'employee.department', 'amendments.creator']);
        $year = (int) $payslip->payrollRun?->cycle_start?->format('Y');

        return PayslipResource::make($payslip)
            ->additional(['ytd' => $this->service->ytd($employee, $year)])
            ->response();
    }

    // GET /api/v1/hr/me/payslips/{payslip}/pdf — the caller's own payslip as a PDF.
    // Same gate as showPayslip: must be owned AND its run finalized.
    public function myPayslipPdf(Request $request, Payslip $payslip): SymfonyResponse
    {
        $employee = $request->user()->employee;

        if (! $employee || $payslip->employee_id !== $employee->id) {
            return response()->json(['message' => 'Not found.'], Response::HTTP_NOT_FOUND);
        }

        if (! $this->service->isViewable($payslip)) {
            return response()->json([
                'message' => 'This payslip is not yet available — its payroll run is still being finalized.',
            ], Response::HTTP_FORBIDDEN);
        }

        $payslip->loadMissing(['payrollRun', 'amendments.creator']);

        return $this->service->payslipPdf($payslip)
            ->download("payslip-{$payslip->payrollRun?->cycle_start?->format('Y-m-d')}.pdf");
    }
}
