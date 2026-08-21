<?php

namespace App\Modules\Hr\Http\Resources;

use App\Modules\Hr\Models\ContributionVersion;
use App\Modules\Hr\Models\Employee;
use App\Modules\Hr\Models\PayrollSetting;
use App\Modules\Hr\Models\Payslip;
use App\Modules\Hr\Services\PayScheduleService;
use App\Modules\Hr\Support\PayslipDocument;
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
                // FULL for the employee reading their OWN payslip, masked for anyone
                // else. A payslip has to be usable as evidence of employment and income, which
                // means the person it belongs to must see their own government numbers on it.
                ...$this->identityNumbers($request),
            ],

            // — Earnings —
            // `period_base` is the full period basic; `base_salary` is what survived absences.
            // The payslip shows the former as "Basic Pay" and charges `absence_deduction`
            // against it, so the cost of an absence is visible instead of folded into basic.
            // WHICH KIND OF PAYSLIP THIS IS. Without it the document cannot label itself,
            // and a 13th-month or final-pay slip reads as an ordinary period that paid no salary.
            // WHICH RUN THIS BELONGS TO, and whether that run is still open. Both are here for the
            // correction flow: adding a line to a payslip is a run-level call, and a locked run
            // refuses every correction — which the screen should say before HR types one.
            'payroll_run_id' => (int) $this->payroll_run_id,
            'run_is_locked' => (bool) $this->payrollRun?->isLocked(),
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
            // Stored all along and never serialised, which is why a 13th-month payslip
            // showed a gross with every earnings line at ₱0.
            'thirteenth_month' => (float) $this->thirteenth_month,
            'other_benefits' => (float) $this->other_benefits,
            // What of the above became taxable (the part over the ₱90,000 bucket). The withholding
            // figure on a 13th-month slip is otherwise a number with no visible basis.
            'taxable_pay' => (float) $this->taxable_pay,
            'gross' => (float) $this->gross,
            // The earnings lines, built HERE rather than in each surface, for the same
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
            // The Deductions table, one row per REASON with the dates behind it. See
            // deductionsBreakdown() — in particular why the pesos are apportioned here.
            'deductions_breakdown' => $this->deductionsBreakdown(),
            // Interest collected on loans this period. Already itemised as its own line in
            // the breakdown above; exposed separately so a consumer can total the cost of borrowing
            // without re-parsing the breakdown's labels.
            'loan_interest' => (float) $this->loan_interest,
            'total_deductions' => (float) $this->total_deductions,
            // An off-cycle correction's amount and what it is for, so the document can
            // explain a gross that came from a person rather than from the engine.
            // The year-end tax correction, so a December payslip can explain a figure
            // that is not this period's ordinary projection. Negative means refunded.
            'tax_annualisation' => (float) $this->tax_annualisation,
            // The year's figures behind that adjustment. Null on every payslip but the
            // one that settles a tax year, and on any written before the column existed.
            'tax_annualisation_basis' => $this->tax_annualisation_basis,
            // The terminal lines, so a final payslip can show its own derivation rather
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

            // WHAT HAS ACTUALLY BEEN PAID, AND WHAT IS STILL OWED.
            'disbursed_amount' => $this->disbursed_amount === null ? null : (float) $this->disbursed_amount,
            'amount_outstanding' => $this->amountOutstanding(),
            // WHEN it was paid. An amended payslip has to date both halves — "paid on the 20th,
            // corrected on the 27th" is the whole explanation for why the two figures differ.
            'disbursed_at' => $this->disbursed_at?->toIso8601String(),

            // The corrections on this payslip, each with the date it was issued and why — so an
            // amended document can show its own history instead of a gross that silently changed.
            'amendments' => $this->whenLoaded(
                'amendments',
                fn () => $this->amendments->map(fn ($a) => [
                    'id' => $a->id,
                    // Extra pay, pay reversed, or a deduction the period missed. The sign of
                    // `amount` follows it; the kind is what says why it moved that way.
                    'kind' => $a->kind,
                    'kind_label' => $a->kindLabel(),
                    // WHICH LINE this corrected, when it corrected one rather than adding one.
                    // Null on an added line — that is the whole difference between the two.
                    'target_key' => $a->target_key,
                    'target_label' => $a->targetLabel(),
                    'override_amount' => $a->override_amount === null ? null : (float) $a->override_amount,
                    'original_amount' => $a->original_amount === null ? null : (float) $a->original_amount,
                    // The engine figure this correction was entered AGAINST, frozen at the time.
                    // Where it and `original_amount` disagree, the engine has since changed its
                    // mind and the correction is waiting on HR to confirm it still stands.
                    'edited_against' => $a->edited_against === null ? null : (float) $a->edited_against,
                    // The comparison itself, from the model rather than redone in the UI — the
                    // rounding it does is what decides whether a run reports this as drift, and
                    // two implementations of that would eventually disagree.
                    'has_drifted' => $a->hasDrifted(),
                    'label_override' => $a->label_override,
                    'amount' => (float) $a->amount,
                    'tax' => (float) $a->tax,
                    'net' => $a->net(),
                    'reason' => $a->reason,
                    'note' => $a->note,
                    'issued_on' => $a->issued_on?->toDateString(),
                    'issued_by' => $a->creator?->name,
                ])->values()->all(),
                [],
            ),

            // The document's own subtotals, derived here rather than in each surface. The UI
            // and the PDF both render them, and the arithmetic below is subtle enough that
            // two independent implementations of it would eventually disagree.
            'totals' => $this->documentTotals(),

            // Where this slip sits among its calendar month's cutoffs, and — on the last one —
            // the whole month rolled up. See cutoff() and monthSummary().
            'cutoff' => $this->cutoff(),
            'month_summary' => $this->monthSummary(),

            // The company's counterpart contributions.
            'employer_sss' => (float) $this->employer_sss,
            'employer_philhealth' => (float) $this->employer_philhealth,
            'employer_pagibig' => (float) $this->employer_pagibig,

            // WHAT EACH STATUTORY FIGURE WAS CHARGED ON. See contributionBasis().
            'contribution_basis' => $this->contributionBasis(),

            // These two stay HR-only. Unlike the employer share, they are genuinely remittance
            // workflow: one says a filing cannot be made as it stands, the other is what a
            // remittance query asks for. Neither is something the employee can act on.
            $this->mergeWhen($this->isHrViewer($request), fn () => [
                // Which deductions came from a per-employee override rather than the
                // rates. The two halves can belong to different brackets, which is a problem for
                // the person filing, not for the person paid.
                'contribution_overrides' => $this->contribution_overrides ?? [],
                // The monthly salary figure each contribution was charged on. Null on
                // payslips computed before B19 and on runs that take no statutory contributions.
                'contribution_bases' => $this->contribution_bases,
            ]),

            'status' => $this->status,
            'processed_by' => $this->payrollRun?->runner?->name,

            // — Computation basis — counts and rates, not money. These explain how the peso
            // figures above were arrived at (0 on payslips that predate the columns).
            'working_days' => (int) $this->working_days,
            'daily_rate' => (float) $this->daily_rate,
            // The same rate said three ways, because the document's header states all three and
            // the two derived ones are not stored. See rates().
            'rates' => $this->rates(),
            'unpaid_days' => (float) $this->unpaid_days,
            'late_minutes' => (int) $this->late_minutes,
            'undertime_minutes' => (int) $this->undertime_minutes,
            'late_deduction' => (float) $this->late_deduction,
            'undertime_deduction' => (float) $this->undertime_deduction,
            'late_penalty_days' => (float) $this->late_penalty_days,

            // — Why those figures are what they are —
            // The counts above say "2.5 unpaid days"; these say WHICH days, and how the tax
            // figure was reached. Null on payslips issued before the columns existed, which the
            // document renders as simply not showing the note.
            'attendance_lines' => $this->attendanceLines(),
            // How minutes became a fraction of a day — the missing half of the lateness note.
            'deduction_rule' => $this->deductionRule(),
            'tax_basis' => $this->tax_basis,
        ];
    }

    /**
     * The per-date charge ledger, grouped into the two questions people actually ask.
     *
     * @return array{unpaid: list<array<string, mixed>>, late: list<array<string, mixed>>}|null
     */
    private function attendanceLines(): ?array
    {
        return PayslipDocument::attendanceLines($this->figures());
    }

    /**
     * The rows on the Deductions table: one per REASON, with its dates and its money.
     *
     * @return list<array<string, mixed>>
     */
    private function deductionsBreakdown(): array
    {
        return PayslipDocument::deductionRows($this->figures(), $this->lineOverrides());
    }

    /**
     * The rule that turned late/undertime MINUTES into a fraction of a day.
     *
     * @return array<string, mixed>|null
     */
    private function deductionRule(): ?array
    {
        $policy = $this->payrollRun?->policy_snapshot['attendance_policy'] ?? null;

        if (! is_array($policy)) {
            return null;
        }

        if ((float) $this->late_deduction == 0.0 && (float) $this->undertime_deduction == 0.0) {
            return null;
        }

        return [
            'late_mode' => $policy['late_deduction_mode'] ?? 'none',
            // Only meaningful under the tiered mode, and the thing that explains why 194 minutes
            // and 72 minutes can cost exactly the same.
            'late_tiers' => $policy['late_tiers'] ?? [],
            // Without this the tier line under-explains a repeating charge: "over 30 min → 1h"
            // cannot account for the 3h docked off a 95-minute arrival.
            'late_tier_recurring' => (bool) ($policy['late_tier_recurring'] ?? false),
            'undertime_mode' => $policy['undertime_deduction_mode'] ?? 'none',
            // The DOLE day the fraction is taken against — dailyRate ÷ this.
            'hours_per_day' => (int) ($policy['hours_per_day_divisor'] ?? 8),
            'rounding' => $policy['deduction_rounding'] ?? 'exact',
            'block_minutes' => (int) ($policy['deduction_block_minutes'] ?? 60),
        ];
    }

    /** What kind of payslip this is, in words the recipient can read. */
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
     * Every earning on this payslip as a named, keyed line.
     *
     * @return list<array<string, mixed>>
     */
    private function earningsBreakdown(): array
    {
        return PayslipDocument::earningLines(
            $this->figures(),
            $this->payrollRun?->type ?? 'regular',
            $this->amendmentRows(),
            $this->lineOverrides(),
        );
    }

    /** "2.5 hrs" from a minute count, or null when there are none. */
    private function minutesNote(mixed $minutes): ?string
    {
        return PayslipDocument::minutesNote($minutes);
    }

    /**
     * The subtotals the payslip document is built from.
     *
     * @return array<string, mixed>
     */
    private function documentTotals(): array
    {
        return PayslipDocument::totals($this->figures());
    }

    /**
     * THE PAYSLIP'S FIGURES as a plain array, which is what PayslipDocument works on.
     *
     * @return array<string, mixed>
     */
    private function figures(): array
    {
        return $this->resource->attributesToArray();
    }

    /**
     * The corrections HR has made to lines that were already on this payslip, keyed by line.
     *
     * @return array<string, array<string, mixed>>
     */
    private function lineOverrides(): array
    {
        if (! $this->relationLoaded('amendments')) {
            return [];
        }

        $out = [];

        foreach ($this->amendments as $amendment) {
            if ($amendment->target_key === null) {
                continue;
            }

            $out[$amendment->target_key] = [
                'id' => $amendment->id,
                // Null on a label-only correction — HR renamed the line and left the figure alone.
                'amount' => $amendment->override_amount === null ? null : (float) $amendment->override_amount,
                'label' => $amendment->label_override,
                // What the engine computed, refreshed on every run: the struck-through figure.
                'original' => $amendment->original_amount === null ? null : (float) $amendment->original_amount,
                'reason' => $amendment->reason,
                'issued_on' => $amendment->issued_on?->toDateString(),
                'issued_by' => $amendment->creator?->name,
            ];
        }

        return $out;
    }

    /**
     * The corrections that ADDED a line to this payslip, in the shape the document reader wants.
     *
     * @return list<array<string, mixed>>|null
     */
    private function amendmentRows(): ?array
    {
        if (! $this->relationLoaded('amendments')) {
            return null;
        }

        return $this->amendments->map(fn ($a) => [
            'id' => $a->id,
            'kind' => $a->kind,
            'target_key' => $a->target_key,
            'amount' => (float) $a->amount,
            'note' => $a->note,
            'reason' => $a->reason,
            'issued_on' => $a->issued_on?->format('M j, Y'),
        ])->values()->all();
    }

    /**
     * The daily rate said three ways, because the document's header states all three.
     *
     * @return array{daily: float, hourly: float, half_day: float}
     */
    private function rates(): array
    {
        $daily = (float) $this->daily_rate;
        $hoursPerDay = (int) ($this->payrollRun?->policy_snapshot['attendance_policy']['hours_per_day_divisor'] ?? 8);

        return [
            'daily' => round($daily, 2),
            'hourly' => $hoursPerDay > 0 ? round($daily / $hoursPerDay, 2) : 0.0,
            'half_day' => round($daily / 2, 2),
        ];
    }

    /**
     * Where this slip sits among its calendar month's cutoffs.
     *
     * @return array{index: int, count: int, is_last: bool, label: string|null}|null
     */
    private function cutoff(): ?array
    {
        $run = $this->payrollRun;

        if ($run === null || ($run->type ?? 'regular') !== 'regular') {
            return null;
        }

        $frequency = (string) ($run->frequency ?? '');
        $start = $run->cycle_start?->format('Y-m-d');
        $end = $run->cycle_end?->format('Y-m-d');

        if ($frequency === '' || $start === null || $end === null) {
            return null;
        }

        [$index, $count] = $this->schedule()->monthPosition(
            $frequency,
            PayrollSetting::asOf($end)->anchor_date,
            $start,
            $end,
        );

        return [
            'index' => $index,
            'count' => $count,
            'is_last' => $index >= $count - 1,
            'label' => $this->cutoffLabel($index, $count),
        ];
    }

    /**
     * What a cutoff is called on the document.
     *
     * Null when the month has only one, which is the honest answer rather than "1st Cut-Off": a
     * monthly-paid employee has no cutoffs to be first among, and the bar simply reads TOTAL NET.
     */
    private function cutoffLabel(int $index, int $count): ?string
    {
        if ($count <= 1) {
            return null;
        }

        // Semi-monthly, where the two have names people actually use.
        if ($count === 2) {
            return $index === 0 ? '1st Cut-Off' : '2nd Cut-Off';
        }

        // Weekly, where they do not — "4th Cut-Off" says nothing about how many are left.
        return 'Cut-Off '.($index + 1).' of '.$count;
    }

    /**
     * THE MONTH, ROLLED UP — carried by the last cutoff only.
     *
     * @return array<string, mixed>|null
     */
    private function monthSummary(): ?array
    {
        $cutoff = $this->cutoff();

        if ($cutoff === null || ! $cutoff['is_last'] || $cutoff['count'] < 2) {
            return null;
        }

        $end = $this->payrollRun?->cycle_end;

        if ($end === null) {
            return null;
        }

        $slips = Payslip::query()
            ->where('employee_id', $this->employee_id)
            ->whereHas('payrollRun', fn ($q) => $q
                ->where('type', 'regular')
                ->whereBetween('cycle_end', [
                    $end->copy()->startOfMonth()->format('Y-m-d'),
                    $end->copy()->endOfMonth()->format('Y-m-d'),
                ]))
            ->with('payrollRun')
            ->get()
            ->sortBy(fn (Payslip $s) => $s->payrollRun?->cycle_start?->format('Y-m-d') ?? '')
            ->values();

        // One slip means this one — there is no earlier cutoff to restate.
        if ($slips->count() < 2) {
            return null;
        }

        $anchor = PayrollSetting::asOf($end->format('Y-m-d'))->anchor_date;
        $periods = [];
        $totalCurrent = 0.0;
        $government = 0.0;
        $allowances = 0.0;
        $alreadyPaid = 0.0;

        foreach ($slips as $slip) {
            $run = $slip->payrollRun;
            $start = $run?->cycle_start?->format('Y-m-d');
            $slipEnd = $run?->cycle_end?->format('Y-m-d');

            [$i, $c] = $start !== null && $slipEnd !== null
                ? $this->schedule()->monthPosition((string) $run->frequency, $anchor, $start, $slipEnd)
                : [0, $cutoff['count']];

            $current = $this->currentAmountOf($slip);
            $isCurrent = (int) $slip->id === (int) $this->id;

            $periods[] = [
                'label' => $this->cutoffLabel($i, $c) ?? 'This Period',
                'cycle_start' => $start,
                'cycle_end' => $slipEnd,
                'current_amount' => $current,
                'net_pay' => round((float) $slip->net_pay, 2),
                'is_current' => $isCurrent,
            ];

            $totalCurrent += $current;
            $government += (float) $slip->sss + (float) $slip->philhealth
                + (float) $slip->pagibig + (float) $slip->withholding_tax;
            $allowances += $this->allowanceTotalOf($slip);

            if (! $isCurrent) {
                $alreadyPaid += (float) $slip->net_pay;
            }
        }

        $totalCurrent = round($totalCurrent, 2);
        $government = round($government, 2);
        $allowances = round($allowances, 2);
        $initialNet = round($totalCurrent - $government, 2);

        return [
            'periods' => $periods,
            'total_current' => $totalCurrent,
            'government' => $government,
            'allowances' => $allowances,
            'initial_net' => $initialNet,
            'total_month' => round($initialNet + $allowances, 2),
            // What earlier cutoffs already paid out, so the bar for THIS one can state the
            // remainder rather than the month total a second time.
            'already_paid' => round($alreadyPaid, 2),
        ];
    }

    /** Another slip's "Total Current Amount" — earned less allowances less deductions. */
    private function currentAmountOf(Payslip $slip): float
    {
        return round(
            (float) $slip->gross - $this->allowanceTotalOf($slip) - (float) $slip->other_deductions,
            2,
        );
    }

    /** The allowance money on another slip. */
    private function allowanceTotalOf(Payslip $slip): float
    {
        $lines = $slip->allowances_breakdown ?? [];

        if ($lines === []) {
            return round((float) $slip->allowances, 2);
        }

        return round(array_sum(array_map(fn (array $l) => (float) $l['amount'], $lines)), 2);
    }

    private function schedule(): PayScheduleService
    {
        return app(PayScheduleService::class);
    }

    /** The date this period actually pays out: cycle end plus the configured delay. */
    private function payDate(): ?string
    {
        // The date the RUN recorded, not one re-derived at read time. Changing
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
     * What each statutory contribution was actually charged on.
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
     * The allowance components, or one aggregate line for payslips issued before the split existed.
     *
     * @return list<array{name: string, amount: float}>
     */
    private function allowancesBreakdown(): array
    {
        return PayslipDocument::allowanceLines($this->figures());
    }

    /** Whether the caller is HR (or an admin) rather than the employee reading their own slip. */
    private function isHrViewer(Request $request): bool
    {
        return (bool) $request->user()?->hasAnyRole(['hr_officer', 'super_admin']);
    }

    /**
     * The government numbers on the identity block — full only for their owner.
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
