<?php

namespace App\Modules\Hr\Http\Resources;

use App\Modules\Hr\Models\PayrollSetting;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Carbon;

class PayslipResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'cycle_start' => $this->payrollRun?->cycle_start?->format('Y-m-d'),
            'cycle_end' => $this->payrollRun?->cycle_end?->format('Y-m-d'),
            // When the money actually lands, and how often — the payslip header states both.
            'pay_date' => $this->payDate(),
            'frequency' => $this->payrollRun?->frequency,
            // When the run executed. Not the same as the pay date: a run finalized on the 14th
            // can pay out on the 15th.
            'issued_at' => $this->payrollRun?->run_at?->toIso8601String(),
            'employee' => [
                'id' => $this->employee->id,
                'name' => $this->employee->user?->name,
                // Identity block. A payslip has to be usable as evidence of employment and
                // income, which means it carries who you are and your government numbers.
                'employee_no' => $this->employee->employee_no,
                'job_title' => $this->employee->job_title,
                'department' => $this->employee->department?->name,
                'address' => $this->employee->address,
                'tin' => $this->employee->tin,
                'sss_number' => $this->employee->sss_number,
                'philhealth_number' => $this->employee->philhealth_number,
                'pagibig_number' => $this->employee->pagibig_number,
            ],

            // — Earnings —
            // `period_base` is the full period basic; `base_salary` is what survived absences.
            // The payslip shows the former as "Basic Pay" and charges `absence_deduction`
            // against it, so the cost of an absence is visible instead of folded into basic.
            'period_base' => (float) $this->period_base,
            'base_salary' => (float) $this->base_salary,
            'absence_deduction' => (float) $this->absence_deduction,
            'reimbursements' => (float) $this->reimbursements,
            'allowances' => (float) $this->allowances,
            'allowances_breakdown' => $this->allowancesBreakdown(),
            'holiday_premium' => (float) $this->holiday_premium,
            'overtime_pay' => (float) $this->overtime_pay,
            'overtime_minutes' => (int) $this->overtime_minutes,
            'night_diff_pay' => (float) $this->night_diff_pay,
            'night_diff_minutes' => (int) $this->night_diff_minutes,
            'rest_day_premium' => (float) $this->rest_day_premium,
            'rest_day_minutes' => (int) $this->rest_day_minutes,
            'gross' => (float) $this->gross,

            // — Deductions —
            'sss' => (float) $this->sss,
            'philhealth' => (float) $this->philhealth,
            'pagibig' => (float) $this->pagibig,
            'withholding_tax' => (float) $this->withholding_tax,
            'other_deductions' => (float) $this->other_deductions,
            'other_deductions_breakdown' => $this->other_deductions_breakdown ?? [],
            'total_deductions' => (float) $this->total_deductions,
            // FIN-010 — an off-cycle correction's amount and what it is for, so the document can
            // explain a gross that came from a person rather than from the engine.
            // FIN-028 — the year-end tax correction, so a December payslip can explain a figure
            // that is not this period's ordinary projection. Negative means refunded.
            'tax_annualisation' => (float) $this->tax_annualisation,
            'adjustment_pay' => (float) $this->adjustment_pay,
            'adjustment_note' => $this->adjustment_note,
            'deduction_carryover' => (float) $this->deduction_carryover,
            'net_pay' => (float) $this->net_pay,

            // The document's own subtotals, derived here rather than in each surface. The UI
            // and the PDF both render them, and the arithmetic below is subtle enough that
            // two independent implementations of it would eventually disagree.
            'totals' => $this->documentTotals(),

            // The company's counterpart contributions. Not deducted from the employee and not
            // their business — this is remittance data, so it goes to HR only. It was
            // previously returned to everyone, which `PAYROLL-RULES-FIX-PLAN.md` flagged.
            $this->mergeWhen($this->isHrViewer($request), fn () => [
                'employer_sss' => (float) $this->employer_sss,
                'employer_philhealth' => (float) $this->employer_philhealth,
                'employer_pagibig' => (float) $this->employer_pagibig,
                // Slice B7 — which deductions came from a per-employee override rather than the
                // rates. HR-only for the same reason as the employer shares: it is a remittance
                // problem (the two halves can belong to different brackets), not something the
                // employee can act on, and it must not appear on the document they receive.
                'contribution_overrides' => $this->contribution_overrides ?? [],
            ]),

            'status' => $this->status,
            'processed_by' => $this->payrollRun?->runner?->name,

            // — Computation basis — counts and rates, not money. These explain how the peso
            // figures above were arrived at (0 on payslips that predate the columns).
            'working_days' => (int) $this->working_days,
            'daily_rate' => (float) $this->daily_rate,
            'unpaid_days' => (float) $this->unpaid_days,
            'late_minutes' => (int) $this->late_minutes,
            'undertime_minutes' => (int) $this->undertime_minutes,
            'late_deduction' => (float) $this->late_deduction,
            'undertime_deduction' => (float) $this->undertime_deduction,
            'late_penalty_days' => (float) $this->late_penalty_days,
        ];
    }

    /**
     * The subtotals the payslip document is built from.
     *
     * The identity they satisfy, for every payslip:
     *
     *     compensation − deductions − government + uncollected = net_pay
     *
     * `compensation` leads with the FULL period basic, so the absence charge has to reappear
     * on the deductions side for the two to cancel — that is why `deductions` is
     * `absence_deduction + other_deductions` rather than the stored `total_deductions`.
     *
     * `uncollected` is a term and not a footnote. `total_deductions` is capped at gross, so on
     * a payslip where pay couldn't cover the deductions, `deductions + government` overstate
     * what was actually taken by exactly the carryover. Crediting it back is what stops the
     * document resolving to a negative number against a net pay of zero.
     *
     * `late_undertime` is clamped to the absence charge because basic pay clamps at zero: past
     * that point the raw minute-level figures exceed the reduction that was really applied.
     * The clamp keeps both absence rows non-negative and their sum exact.
     *
     * @return array<string, float>
     */
    private function documentTotals(): array
    {
        $absenceCharge = (float) $this->absence_deduction;
        $lateUndertime = min((float) $this->late_deduction + (float) $this->undertime_deduction, $absenceCharge);

        return [
            'compensation' => round((float) $this->gross + $absenceCharge, 2),
            'absences' => round($absenceCharge - $lateUndertime, 2),
            'late_undertime' => round($lateUndertime, 2),
            'deductions' => round($absenceCharge + (float) $this->other_deductions, 2),
            'government' => round(
                (float) $this->sss + (float) $this->philhealth
                    + (float) $this->pagibig + (float) $this->withholding_tax,
                2,
            ),
            'uncollected' => (float) $this->deduction_carryover,
        ];
    }

    /**
     * The date this period actually pays out: cycle end plus the configured delay.
     *
     * Taken from the run's own policy snapshot first, so an old payslip keeps stating the pay
     * date it was issued under even after HR changes `pay_delay_days`. Runs from before the
     * snapshot existed fall back to the current setting.
     */
    private function payDate(): ?string
    {
        // FIN-036 — the date the RUN recorded, not one re-derived at read time. Changing
        // pay_delay_days used to move the stated pay date on payslips already issued.
        if ($this->payrollRun?->scheduled_pay_date) {
            return $this->payrollRun->scheduled_pay_date->format('Y-m-d');
        }

        // Fallback for runs written before the column existed. The migration backfills them, so
        // this is unreachable in practice — kept because a payslip must always be able to state
        // a pay date, and the old derivation is exactly what it used to show.
        $end = $this->payrollRun?->cycle_end;
        if (! $end) {
            return null;
        }

        $delay = $this->payrollRun->policy_snapshot['payroll_setting']['pay_delay_days']
            ?? PayrollSetting::current()->pay_delay_days
            ?? 0;

        return Carbon::parse($end)->addDays((int) $delay)->format('Y-m-d');
    }

    /**
     * Allowances split into their components, for payslips that recorded the split.
     *
     * Payslips written before the snapshot column existed can't be split after the fact — the
     * employee's allowance columns have moved on since. Those collapse to one honest
     * "Allowances" line rather than a guess.
     *
     * @return list<array{name:string, amount:float}>
     */
    private function allowancesBreakdown(): array
    {
        $lines = $this->allowances_breakdown ?? [];

        if ($lines === [] && (float) $this->allowances > 0) {
            return [['name' => 'Allowances', 'amount' => (float) $this->allowances]];
        }

        return array_map(
            fn (array $line) => ['name' => $line['name'], 'amount' => (float) $line['amount']],
            $lines,
        );
    }

    /** Whether the caller is HR (or an admin) rather than the employee reading their own slip. */
    private function isHrViewer(Request $request): bool
    {
        return (bool) $request->user()?->hasAnyRole(['hr_officer', 'super_admin']);
    }
}
