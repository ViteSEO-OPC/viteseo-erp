<?php

namespace App\Modules\Hr\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Hr\Http\Requests\ReopenRequest;
use App\Modules\Hr\Http\Requests\RunAdjustmentRequest;
use App\Modules\Hr\Http\Requests\RunFinalPayRequest;
use App\Modules\Hr\Http\Requests\RunPayrollRequest;
use App\Modules\Hr\Http\Requests\UpdatePayrollSettingsRequest;
use App\Modules\Hr\Http\Resources\PayrollRunResource;
use App\Modules\Hr\Http\Resources\PayslipResource;
use App\Modules\Hr\Http\Resources\PayslipSummaryResource;
use App\Modules\Hr\Models\Payslip;
use App\Modules\Hr\Models\PayrollRun;
use App\Modules\Hr\Models\PayrollSetting;
use App\Modules\Hr\Services\AuditService;
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

        // FIN-025 — the effective date governs the VERSION, it is not a settings field. Pulled
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

        // FIN-025 — declare the effective date, then save: the model's `updated` hook records the
        // version, so there is exactly one writer and no way to update without versioning.
        PayrollSetting::effectiveFrom($effectiveFrom);
        $setting->update($validated);

        if ($before !== []) {
            $audit->log(
                'updated',
                'Payroll',
                'Payroll settings updated: ' . implode(', ', array_keys($before)),
                $setting,
                $before,
                $after,
            );
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
            'anchor_date' => $setting->anchor_date?->toDateString(),
            'pay_delay_days' => $setting->pay_delay_days,
            'other_benefits_annual_cap' => $setting->other_benefits_annual_cap === null ? null : (float) $setting->other_benefits_annual_cap,
            'minimum_daily_wage' => $setting->minimum_daily_wage === null ? null : (float) $setting->minimum_daily_wage,
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

    // POST /api/v1/hr/payroll/run — execute payroll for a cycle.
    public function run(RunPayrollRequest $request): JsonResponse
    {
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

    /**
     * POST /api/v1/hr/payroll/adjustment — pay an off-cycle correction (FIN-010).
     *
     * The route every other correction in this module depends on: a back-dated raise, a fixed
     * attendance record, a late-approved overtime request or reimbursement all produce a
     * difference that used to have nowhere to go.
     */
    public function runAdjustment(RunAdjustmentRequest $request): JsonResponse
    {
        $run = $this->service->runAdjustment(
            $request->validated('cycle_start'),
            $request->validated('cycle_end'),
            $request->validated('reason'),
            $request->validated('lines'),
            $request->user(),
        );

        return PayrollRunResource::make($run)
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    /**
     * POST /api/v1/hr/employees/{employee}/final-pay — the terminal payslip a leaver is owed.
     *
     * FIN-014 — deliberately separate from the regular run, which already prorates the leaver's last
     * cycle. This pays what that run cannot: unused leave converted to cash, separation pay, the
     * outstanding loan balance, and the year's tax trued up against what was actually earned.
     */
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

    /**
     * GET /api/v1/hr/payroll/thirteenth-month/preview — Slice B17, hr_officer only.
     *
     * Exactly what the run would pay, computed and saved nowhere. The accrual table HR is looking
     * at is a projection built from a different query; confirming a payroll run against a figure
     * it will not pay is a small dishonesty that costs trust the first time somebody checks.
     */
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

    /**
     * POST /api/v1/hr/payroll/thirteenth-month/run — Slice B17, hr_officer only.
     *
     * Pays the year's 13th month as a real payroll run and withholds on whatever it pushes past
     * the ₱90,000 exemption. Re-runnable: it recomputes the same run in place, like an ordinary
     * cycle, and refuses once the run is locked.
     */
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
    // can never be recomputed (Slice 5).
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

    /**
     * POST /api/v1/hr/payslips/{payslip}/mark-done — declare one payslip paid.
     *
     * FIN-033 — accepts an idempotency key, from the `Idempotency-Key` header or the body. A repeat
     * carrying the same key is answered as success without disbursing twice; a repeat with a
     * different key is refused as a genuine second attempt. The header is the conventional home for
     * it, and the body is accepted so a client that cannot set headers is not locked out.
     */
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

    // GET /api/v1/hr/payroll/runs/{payrollRun}/export — the run as a CSV file (HR only).
    public function exportRun(PayrollRun $payrollRun): SymfonyResponse
    {
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
        $payslip->load(['payrollRun.runner', 'employee.user', 'employee.department']);

        return PayslipResource::make($payslip)->response();
    }

    // GET /api/v1/hr/payslips/{payslip}/pdf — one employee's payslip as a PDF (HR only).
    public function payslipPdf(Payslip $payslip): SymfonyResponse
    {
        $payslip->loadMissing(['payrollRun', 'employee.user']);
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

        $payslip->load(['payrollRun.runner', 'employee.user', 'employee.department']);
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

        $payslip->loadMissing('payrollRun');

        return $this->service->payslipPdf($payslip)
            ->download("payslip-{$payslip->payrollRun?->cycle_start?->format('Y-m-d')}.pdf");
    }
}
