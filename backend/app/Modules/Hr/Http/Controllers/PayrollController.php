<?php

namespace App\Modules\Hr\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Hr\Http\Requests\RunPayrollRequest;
use App\Modules\Hr\Http\Requests\UpdatePayrollSettingsRequest;
use App\Modules\Hr\Http\Resources\PayrollRunResource;
use App\Modules\Hr\Http\Resources\PayslipResource;
use App\Modules\Hr\Http\Resources\PayslipSummaryResource;
use App\Modules\Hr\Models\Payslip;
use App\Modules\Hr\Models\PayrollRun;
use App\Modules\Hr\Models\PayrollSetting;
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
    public function updateSettings(UpdatePayrollSettingsRequest $request): JsonResponse
    {
        PayrollSetting::current()->update($request->validated());

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

    // GET /api/v1/hr/payroll/thirteenth-month — per-employee 13th-month accrual for a year.
    public function thirteenthMonth(Request $request): JsonResponse
    {
        $year = (int) ($request->query('year') ?: now(config('app.timezone_local'))->year);

        return response()->json([
            'data' => $this->service->thirteenthMonth($year),
            'meta' => ['year' => $year],
        ]);
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

    // POST /api/v1/hr/payslips/{payslip}/mark-done — mark one payslip processed.
    public function markDone(Payslip $payslip): JsonResponse
    {
        $this->service->markDone($payslip);

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

    // GET /api/v1/hr/payslips/{payslip}/pdf — one employee's payslip as a PDF (HR only).
    public function payslipPdf(Payslip $payslip): SymfonyResponse
    {
        $payslip->loadMissing(['payrollRun', 'employee.user']);
        $year = (int) $payslip->payrollRun?->cycle_start?->format('Y');
        $ytd = $payslip->employee ? $this->service->ytd($payslip->employee, $year) : null;
        $slug = str($payslip->employee?->user?->name ?? 'employee')->slug();

        return $this->service->payslipPdf($payslip, $ytd)
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

        $payslip->load(['payrollRun.runner', 'employee.user']);
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
        $year = (int) $payslip->payrollRun?->cycle_start?->format('Y');

        return $this->service->payslipPdf($payslip, $this->service->ytd($employee, $year))
            ->download("payslip-{$payslip->payrollRun?->cycle_start?->format('Y-m-d')}.pdf");
    }
}
