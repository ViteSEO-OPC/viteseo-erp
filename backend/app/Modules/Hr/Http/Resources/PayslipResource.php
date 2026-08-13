<?php

namespace App\Modules\Hr\Http\Resources;

use App\Modules\Hr\Models\ContributionVersion;
use App\Modules\Hr\Models\Employee;
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
                'id' => $this->employee?->id,
                'name' => $this->employee?->user?->name,
                // Identity block. A payslip has to be usable as evidence of employment and
                // income, which means it carries who you are and your government numbers.
                'employee_no' => $this->employee?->employee_no,
                'job_title' => $this->employee?->job_title,
                'department' => $this->employee?->department?->name,
                'address' => $this->employee?->address,
                // FIN-022 — FULL for the employee reading their OWN payslip, masked for anyone
                // else. A payslip has to be usable as evidence of employment and income, which
                // means the person it belongs to must see their own government numbers on it.
                //
                // But HR opening someone else's payslip is a different act, and leaving these
                // unmasked here would route straight around the audited reveal endpoint: every
                // government number in the company, readable, with no record of who looked. HR that
                // genuinely needs the full value uses `/employees/{id}/sensitive`, which logs it.
                ...$this->identityNumbers($request),
            ],

            // — Earnings —
            // `period_base` is the full period basic; `base_salary` is what survived absences.
            // The payslip shows the former as "Basic Pay" and charges `absence_deduction`
            // against it, so the cost of an absence is visible instead of folded into basic.
            // FIN-011 — WHICH KIND OF PAYSLIP THIS IS. Without it the document cannot label itself,
            // and a 13th-month or final-pay slip reads as an ordinary period that paid no salary.
            'run_type' => $this->payrollRun?->type ?? 'regular',
            // What the header prints. The raw `frequency` was going straight onto the document, and
            // for a final-pay run that string is "final_pay:<employee id>" — an internal key, with an
            // id in it, on a document handed to the employee.
            'run_type_label' => $this->runTypeLabel(),
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
            // FIN-011 — stored all along and never serialised, which is why a 13th-month payslip
            // showed a gross with every earnings line at ₱0.
            'thirteenth_month' => (float) $this->thirteenth_month,
            'other_benefits' => (float) $this->other_benefits,
            // What of the above became taxable (the part over the ₱90,000 bucket). The withholding
            // figure on a 13th-month slip is otherwise a number with no visible basis.
            'taxable_pay' => (float) $this->taxable_pay,
            'gross' => (float) $this->gross,
            // FIN-011 — the earnings lines, built HERE rather than in each surface, for the same
            // reason `totals` is: the React document and the PDF both render them, and two
            // independent lists would drift the first time a run type gained a line.
            'earnings_breakdown' => $this->earningsBreakdown(),

            // — Deductions —
            'sss' => (float) $this->sss,
            'philhealth' => (float) $this->philhealth,
            'pagibig' => (float) $this->pagibig,
            'withholding_tax' => (float) $this->withholding_tax,
            'other_deductions' => (float) $this->other_deductions,
            'other_deductions_breakdown' => $this->other_deductions_breakdown ?? [],
            // FIN-032 — interest collected on loans this period. Already itemised as its own line in
            // the breakdown above; exposed separately so a consumer can total the cost of borrowing
            // without re-parsing the breakdown's labels.
            'loan_interest' => (float) $this->loan_interest,
            'total_deductions' => (float) $this->total_deductions,
            // FIN-010 — an off-cycle correction's amount and what it is for, so the document can
            // explain a gross that came from a person rather than from the engine.
            // FIN-028 — the year-end tax correction, so a December payslip can explain a figure
            // that is not this period's ordinary projection. Negative means refunded.
            'tax_annualisation' => (float) $this->tax_annualisation,
            // FIN-054 — the year's figures behind that adjustment. Null on every payslip but the
            // one that settles a tax year, and on any written before the column existed.
            'tax_annualisation_basis' => $this->tax_annualisation_basis,
            // FIN-014 — the terminal lines, so a final payslip can show its own derivation rather
            // than one opaque gross.
            'separation_pay' => $this->separation_pay === null ? null : (float) $this->separation_pay,
            'separation_pay_taxable' => (bool) $this->separation_pay_taxable,
            'leave_conversion' => $this->leave_conversion === null ? null : (float) $this->leave_conversion,
            'leave_conversion_days' => $this->leave_conversion_days === null ? null : (float) $this->leave_conversion_days,
            'loan_settlement' => $this->loan_settlement === null ? null : (float) $this->loan_settlement,
            'adjustment_pay' => (float) $this->adjustment_pay,
            'adjustment_note' => $this->adjustment_note,
            'deduction_carryover' => (float) $this->deduction_carryover,
            'net_pay' => (float) $this->net_pay,

            // The document's own subtotals, derived here rather than in each surface. The UI
            // and the PDF both render them, and the arithmetic below is subtle enough that
            // two independent implementations of it would eventually disagree.
            'totals' => $this->documentTotals(),

            // The company's counterpart contributions.
            //
            // SHOWN TO THE EMPLOYEE TOO (2026-08-07, Joshua's call). These were HR-only on the
            // grounds that remittance data is "not the employee's business". That reasoning holds
            // for the two fields below it and not for this one: the employer share is money paid
            // into the employee's OWN SSS, PhilHealth and Pag-IBIG record, and it is the part of
            // their compensation they are least likely to know about. It is disclosure, not
            // remittance workflow.
            //
            // It is never a term in any total — see `documentTotals()`, which does not reference
            // it. Nothing deducts it, because nothing was deducted: it is the company's cost.
            'employer_sss' => (float) $this->employer_sss,
            'employer_philhealth' => (float) $this->employer_philhealth,
            'employer_pagibig' => (float) $this->employer_pagibig,

            // FIN-049 — WHAT EACH STATUTORY FIGURE WAS CHARGED ON. See contributionBasis().
            'contribution_basis' => $this->contributionBasis(),

            // These two stay HR-only. Unlike the employer share, they are genuinely remittance
            // workflow: one says a filing cannot be made as it stands, the other is what a
            // remittance query asks for. Neither is something the employee can act on.
            $this->mergeWhen($this->isHrViewer($request), fn () => [
                // Slice B7 — which deductions came from a per-employee override rather than the
                // rates. The two halves can belong to different brackets, which is a problem for
                // the person filing, not for the person paid.
                'contribution_overrides' => $this->contribution_overrides ?? [],
                // Slice B19 — the monthly salary figure each contribution was charged on. Null on
                // payslips computed before B19 and on runs that take no statutory contributions.
                'contribution_bases' => $this->contribution_bases,
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

    /** What kind of payslip this is, in words the recipient can read (FIN-011). */
    private function runTypeLabel(): string
    {
        return match ($this->payrollRun?->type) {
            'thirteenth_month' => '13th Month Pay',
            'adjustment' => 'Adjustment',
            'final_pay' => 'Final Pay',
            // An ordinary run is identified by how often it pays, which is what the header always
            // meant to show — for the other types `frequency` is an internal key, not a pay type.
            default => ucwords(str_replace('_', '-', (string) ($this->payrollRun?->frequency ?: '—'))),
        };
    }

    /**
     * Every earning on this payslip as a named line (FIN-011).
     *
     * THE DEFECT THIS FIXES was wider than "13th month has no lines". Both documents hardcoded an
     * ordinary period's earnings — Basic Pay, allowances, overtime, night differential, holiday, rest
     * day, reimbursement — and rendered "Basic Pay ₱0" for anything else. That covered FOUR run types:
     * 13th month, the off-cycle adjustment (FIN-010) and final pay (FIN-014) all store their amounts in
     * their own columns, none of which either document knew about. An employee opened a legally
     * consequential document stating a gross, a withholding-tax figure, and every earnings line at zero.
     *
     * Built here rather than in each surface for the same reason as `documentTotals()`: the React
     * payslip and the PDF both render this, and two hand-maintained lists would disagree the first time
     * a run type gained a line — which is exactly how the adjustment and final-pay types shipped
     * invisible after their columns were added.
     *
     * A line appears only when it carries money, EXCEPT Basic Pay on an ordinary run: a regular period
     * that genuinely paid no basic (a full month of unpaid leave) still has to say so rather than
     * silently omit the row the whole document is built around.
     *
     * @return list<array{name: string, amount: float, note: string|null}>
     */
    private function earningsBreakdown(): array
    {
        $type = $this->payrollRun?->type ?? 'regular';
        $lines = [];

        $add = function (string $name, float $amount, ?string $note = null) use (&$lines) {
            if (round($amount, 2) != 0.0) {
                $lines[] = ['name' => $name, 'amount' => round($amount, 2), 'note' => $note];
            }
        };

        // --- the ordinary period ---------------------------------------------------------------
        if ($type === 'regular') {
            $lines[] = [
                'name' => 'Basic Pay',
                'amount' => round((float) $this->period_base, 2),
                'note' => null,
            ];

            foreach ($this->allowancesBreakdown() as $line) {
                $lines[] = ['name' => $line['name'], 'amount' => $line['amount'], 'note' => null];
            }

            $add('Overtime', (float) $this->overtime_pay, $this->minutesNote($this->overtime_minutes));
            $add('Night Differential', (float) $this->night_diff_pay, $this->minutesNote($this->night_diff_minutes));
            $add('Holiday', (float) $this->holiday_premium);
            $add('Rest Day', (float) $this->rest_day_premium, $this->minutesNote($this->rest_day_minutes));
        }

        // --- 13th month ------------------------------------------------------------------------
        // The whole gross of a 13th-month run. `other_benefits` is the same money seen from the tax
        // side (what went into the ₱90,000 bucket), so it is NOT a second earnings line — adding it
        // would double the compensation total.
        $add('13th Month Pay', (float) $this->thirteenth_month);

        // --- the off-cycle correction (FIN-010) -------------------------------------------------
        $add('Adjustment', (float) $this->adjustment_pay, $this->adjustment_note ?: null);

        // --- final pay (FIN-014) ---------------------------------------------------------------
        $add('Separation Pay', (float) $this->separation_pay,
            $this->separation_pay_taxable ? 'Taxable' : 'Tax-exempt');
        $add('Unused Leave Converted', (float) $this->leave_conversion,
            $this->leave_conversion_days === null
                ? null
                : rtrim(rtrim(number_format((float) $this->leave_conversion_days, 1), '0'), '.') . ' day(s)');

        // --- paid on any run type --------------------------------------------------------------
        $add('Reimbursement', (float) $this->reimbursements);

        return $lines;
    }

    /** "2.5 hrs" from a minute count, or null when there are none. */
    private function minutesNote(mixed $minutes): ?string
    {
        $m = (int) $minutes;

        return $m > 0 ? rtrim(rtrim(number_format($m / 60, 2), '0'), '.') . ' hrs' : null;
    }

    /**
     * The subtotals the payslip document is built from.
     *
     * The identity they satisfy, for every payslip:
     *
     *     compensation − deductions − government = net_pay
     *
     * `compensation` leads with the FULL period basic, so the absence charge has to reappear
     * on the deductions side for the two to cancel — that is why `deductions` is
     * `absence_deduction + other_deductions` rather than the stored `total_deductions`.
     *
     * `uncollected` USED TO BE A TERM IN THIS IDENTITY and no longer is (FIN-053). It was here
     * because the component columns stored the full computed deduction while `total_deductions`
     * was capped at gross: `deductions + government` therefore overstated what was really taken
     * by exactly the carryover, and crediting it back was what stopped the document resolving to
     * a negative number against a net pay of zero.
     *
     * The components now store what was COLLECTED, so the overstatement is gone at the source and
     * the identity closes without the correction. `uncollected` stays in this array — the employee
     * is entitled to know a deduction went unpaid, and it is the figure the arrears work reads —
     * but it is now a disclosure, not arithmetic. Adding it back would credit the shortfall twice.
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
            // Disclosure only — see the identity note above. Not summed into net.
            'uncollected' => (float) $this->deduction_carryover,
            // FIN-053 — which obligations that shortfall belongs to, so the document can name
            // them instead of showing one unexplained figure. Empty when nothing went short, and
            // on payslips issued before the split (the migration does not backfill).
            'uncollected_breakdown' => $this->deduction_carryover_breakdown ?? [],
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
     * What each statutory contribution was actually charged on (FIN-049).
     *
     * THE QUESTION THIS ANSWERS. A ₱95,000 salary shows Pag-IBIG at ₱200 and reads as an error —
     * 2% of ₱95,000 is ₱1,900. It is right: the rate applies to a ₱10,000 salary CAP. SSS is
     * worse, because the rate multiplies a bracket's Monthly Salary Credit rather than the salary
     * at all. Nothing on the payslip said either thing, so the only way to check a deduction was
     * to open Company → Benefits and reconstruct the arithmetic by hand.
     *
     * BUILT FROM WHAT THE RUN STORED, never from live config: `contribution_bases` on this
     * payslip and the rate version on the run's `policy_snapshot`. A payslip must keep explaining
     * itself the same way after HR edits a rate, which is the whole reason both were snapshot.
     *
     * Degrades rather than guesses. A payslip predating either snapshot, or a bracket version
     * whose bands were not captured, simply omits the entry and the document shows the figure
     * with no note — the same choice `allowancesBreakdown()` makes.
     *
     * @return array<string, array<string, mixed>>
     */
    private function contributionBasis(): array
    {
        $bases = $this->contribution_bases;
        $versions = $this->payrollRun?->policy_snapshot['contribution_versions'] ?? null;

        if (! is_array($bases) || ! is_array($versions)) {
            return [];
        }

        $overrides = is_array($this->contribution_overrides) ? $this->contribution_overrides : [];
        $out = [];

        foreach ($versions as $version) {
            $type = $version['type'] ?? null;

            if ($type === null || ! array_key_exists($type, $bases)) {
                continue;
            }

            $base = (float) $bases[$type];
            $rate = (float) ($version['employee_rate'] ?? 0);
            $isBracket = ($version['basis'] ?? 'percentage') === 'bracket';

            // A per-employee override replaces the EMPLOYEE side with a typed peso figure, so no
            // rate was applied and describing one would be a lie about where the number came from.
            if (($overrides[$type] ?? null) !== null) {
                $out[$type] = ['overridden' => true];

                continue;
            }

            $creditable = $isBracket
                ? ContributionVersion::mscFor($version['brackets'] ?? [], $base)
                : $this->clampToBand($base, $version);

            if ($creditable === null) {
                continue;
            }

            $out[$type] = [
                'overridden' => false,
                'basis' => $isBracket ? 'bracket' : 'percentage',
                'rate' => $rate,
                // The salary/earnings figure this contribution was computed FROM …
                'salary' => round($base, 2),
                // … and the figure the rate was actually applied TO. They differ whenever a cap,
                // a floor or a bracket sits between them, which is exactly when the deduction
                // looks wrong.
                'charged_on' => round((float) $creditable, 2),
                'capped' => ! $isBracket && $version['salary_cap'] !== null && $base > (float) $version['salary_cap'],
                'floored' => ! $isBracket && $version['salary_floor'] !== null && $base < (float) $version['salary_floor'],
            ];
        }

        return $out;
    }

    /** The percentage path's creditable salary: the base held between its floor and cap. */
    private function clampToBand(float $base, array $version): float
    {
        $creditable = $base;

        if (($version['salary_floor'] ?? null) !== null) {
            $creditable = max((float) $version['salary_floor'], $creditable);
        }

        if (($version['salary_cap'] ?? null) !== null) {
            $creditable = min((float) $version['salary_cap'], $creditable);
        }

        return $creditable;
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

    /**
     * The government numbers on the identity block — full only for their owner (FIN-022).
     *
     * Ownership, not role, is the test. An HR officer viewing their own payslip is the owner and sees
     * their own numbers; the same officer viewing a colleague's does not. Getting this the other way
     * round — keying on role — would show HR every number in the company and show an employee
     * nothing of their own.
     *
     * @return array<string, string|null>
     */
    private function identityNumbers(Request $request): array
    {
        $isOwner = $request->user()?->employee?->id === $this->employee?->id;

        $fields = ['tin', 'sss_number', 'philhealth_number', 'pagibig_number'];
        $out = [];

        foreach ($fields as $field) {
            $value = $this->employee?->getAttribute($field);
            $out[$field] = $isOwner ? $value : Employee::maskSensitive($value);
        }

        return $out;
    }
}
