<?php

namespace App\Modules\Hr\Support;

use Illuminate\Support\Str;

/**
 * THE PAYSLIP AS A DOCUMENT — its lines, its rows, and the subtotals they foot to.
 *
 * EXTRACTED FROM PayslipResource, and the reason is no longer only "the PDF renders it too". HR can
 * now EDIT a printed line, and an edit needs the same answer the reader sees: to know what changing
 * "Late" to ₱300 costs, the service has to know what "Late" currently prints as — which, for the
 * attendance rows, is not any single stored column but an apportionment of two authoritative
 * totals (see rows()). A second implementation of that arithmetic living in the service would
 * disagree with the document the first time basic pay clamped at zero, and HR would be editing a
 * figure nobody was shown.
 *
 * SO THIS WORKS ON A PLAIN ARRAY, not on a model. The same function then reads a saved payslip, a
 * payslip mid-computation inside run(), and the `engine_snapshot` of what a payslip looked like
 * before anyone corrected it — which is exactly the set of things an override has to be measured
 * against.
 *
 * EVERY LINE CARRIES A KEY, from PayslipLines. That key is what an override names, what the UI
 * sends back, and what survives a re-run: names are for reading and keys are for pointing at.
 */
final class PayslipDocument
{
    /** How the attendance charge ledger's `kind` reads on the document. */
    public const CHARGE_NAMES = [
        'absent' => 'Absences',
        'undertime' => 'Undertime',
        'half_day' => 'Half-Days',
        'late' => 'Late',
        'suspended_unpaid' => 'Work Suspension',
        'leave_unpaid' => 'Leave Without Pay',
        'holiday_forfeited' => 'Holiday Forfeited',
        'not_employed' => 'Outside Employment',
        'early_out' => 'Early Out',
        'late_penalty' => 'Tardiness Penalty',
    ];

    /** The five deduction rows the document always shows, in the order it shows them. */
    private const ALWAYS_SHOWN = ['absent', 'undertime', 'half_day', 'late', 'suspended_unpaid'];

    /** The two charges measured in minutes rather than in days. */
    private const TIME_KINDS = ['late', 'undertime'];

    /**
     * Every earning on this payslip as a named, keyed line.
     *
     * A line appears only when it carries money, EXCEPT Basic Pay on an ordinary run: a regular
     * period that genuinely paid no basic (a full month of unpaid leave) still has to say so rather
     * than silently omit the row the whole document is built around.
     *
     * Allowances are `kind => 'allowance'` because the redesigned document prints them in their own
     * table BELOW the net-of-contributions subtotal. Splitting them HERE keeps this list the single
     * description of what a run paid — a second, presentation-side rule for "is this an allowance?"
     * is the duplication the breakdown was introduced to end.
     *
     * @param  array<string, mixed>  $slip
     * @param  list<array<string, mixed>>|null  $amendments  appended corrections; null when the
     *                                                       ledger was not loaded, which falls back
     *                                                       to the single stored total.
     * @param  array<string, array{amount?: float|null, label?: string|null, original?: float|null}>  $overrides
     * @return list<array<string, mixed>>
     */
    public static function earningLines(
        array $slip,
        string $runType = 'regular',
        ?array $amendments = null,
        array $overrides = [],
    ): array {
        $lines = [];

        $add = function (string $key, string $name, float $amount, ?string $note = null, string $kind = 'earning') use (&$lines) {
            if (round($amount, 2) != 0.0) {
                $lines[] = ['key' => $key, 'name' => $name, 'amount' => round($amount, 2), 'note' => $note, 'kind' => $kind];
            }
        };

        $f = fn (string $field) => (float) ($slip[$field] ?? 0);

        // --- the ordinary period ---------------------------------------------------------------
        if ($runType === 'regular') {
            $lines[] = [
                'key' => 'basic',
                'name' => 'Basic Pay',
                // The FULL period basic. The absence charge is shown against it on the deductions
                // side rather than folded into it, so the cost of an absence stays visible.
                'amount' => round($f('period_base'), 2),
                'note' => null,
                'kind' => 'earning',
            ];

            foreach (self::allowanceLines($slip) as $line) {
                $lines[] = [
                    'key' => PayslipLines::keyForName(PayslipLines::ALLOWANCE_PREFIX, $line['name']),
                    'name' => $line['name'],
                    'amount' => $line['amount'],
                    'note' => null,
                    'kind' => 'allowance',
                ];
            }

            $add('overtime', 'Overtime', $f('overtime_pay'), self::minutesNote($slip['overtime_minutes'] ?? 0));
            $add('night_diff', 'Night Differential', $f('night_diff_pay'), self::minutesNote($slip['night_diff_minutes'] ?? 0));
            $add('holiday', 'Holiday', $f('holiday_premium'));
            $add('rest_day', 'Rest Day', $f('rest_day_premium'), self::minutesNote($slip['rest_day_minutes'] ?? 0));
        }

        // --- 13th month ------------------------------------------------------------------------
        // `other_benefits` is the same money seen from the tax side (what went into the ₱90,000
        // bucket), so it is NOT a second earnings line — adding it would double the compensation.
        $add('thirteenth_month', '13th Month Pay', $f('thirteenth_month'));

        // --- corrections ADDED to this period ----------------------------------------------------
        //
        // One line per amendment, each carrying the date it was issued, because that date is the
        // only thing distinguishing two corrections to the same period.
        //
        // AN OVERRIDE IS NOT ONE OF THESE. It changes a line that is already printed above rather
        // than adding one, so it must never also appear here — it would then be counted twice and
        // the document would overstate what it paid.
        if ($amendments !== null && $amendments !== []) {
            foreach ($amendments as $amendment) {
                if (($amendment['target_key'] ?? null) !== null) {
                    continue;
                }

                // A missed deduction is not an earning, negative or otherwise: it reaches the
                // document through `other_deductions_breakdown`, where the money actually went.
                if (($amendment['kind'] ?? 'pay') === 'deduction') {
                    continue;
                }

                $add(
                    'amendment:' . ($amendment['id'] ?? 0),
                    // A reversal is a line of its own: "Adjustment −₱1,000" reads as an adjustment
                    // that was mis-entered, which is what it must not be mistaken for.
                    (float) $amendment['amount'] < 0 ? 'Pay Reversed' : 'Adjustment',
                    (float) $amendment['amount'],
                    trim(((string) ($amendment['note'] ?: $amendment['reason'])) . ' · issued '
                        . ((string) ($amendment['issued_on'] ?? ''))),
                );
            }
        } else {
            // The single stored total, for the payslips of the superseded adjustment runs and for
            // any caller that did not load the ledger. Both are real payslips of real money.
            $add('adjustment', 'Adjustment', $f('adjustment_pay'), ($slip['adjustment_note'] ?? null) ?: null);
        }

        // --- final pay (FIN-014) ---------------------------------------------------------------
        $add('separation_pay', 'Separation Pay', $f('separation_pay'),
            ! empty($slip['separation_pay_taxable']) ? 'Taxable' : 'Tax-exempt');
        $add('leave_conversion', 'Unused Leave Converted', $f('leave_conversion'),
            ($slip['leave_conversion_days'] ?? null) === null
                ? null
                : rtrim(rtrim(number_format((float) $slip['leave_conversion_days'], 1), '0'), '.') . ' day(s)');

        // --- paid on any run type --------------------------------------------------------------
        $add('reimbursement', 'Reimbursement', $f('reimbursements'));

        return self::withOverrideMarks($lines, $overrides);
    }

    /**
     * The allowance components, or one aggregate line for payslips issued before the split existed.
     *
     * @param  array<string, mixed>  $slip
     * @return list<array{name: string, amount: float}>
     */
    public static function allowanceLines(array $slip): array
    {
        $lines = $slip['allowances_breakdown'] ?? [];
        $lines = is_array($lines) ? $lines : [];

        if ($lines === [] && (float) ($slip['allowances'] ?? 0) > 0) {
            return [['name' => 'Allowances', 'amount' => round((float) $slip['allowances'], 2)]];
        }

        return array_map(
            fn (array $line) => ['name' => (string) $line['name'], 'amount' => round((float) $line['amount'], 2)],
            $lines,
        );
    }

    /**
     * The rows on the Deductions table: one per REASON, with its dates and its money.
     *
     * WHY THE PESOS ARE APPORTIONED RATHER THAN MULTIPLIED OUT. The obvious construction —
     * `days × daily_rate` per reason — does not add up to what was taken. Basic pay clamps at zero,
     * so a period deep enough in unpaid days has an absence charge SMALLER than its days imply, and
     * the late/undertime figures are clamped again on top of that. Rows built by multiplication
     * would each look defensible and their total would contradict the total printed beneath them —
     * the one error a payslip cannot make.
     *
     * So the two authoritative figures are split instead: `totals.absences` across the day-costing
     * reasons in proportion to their days, `totals.late_undertime` across the two time reasons in
     * proportion to their own charges. Both splits carry their rounding residual onto the largest
     * row, which keeps `Σ rows == totals.deductions` exact rather than approximately true.
     *
     * AN OVERRIDDEN ROW IS PINNED AND TAKEN OUT OF ITS POOL FIRST. HR typed that figure and the
     * document must print it, not a re-apportionment that lands near it — and the remaining rows
     * then share what is left, so the table still foots to its own total. Without the pin, editing
     * "Half-Days" to ₱800 would move the pool by ₱200 and then hand each row a proportional share
     * of the smaller pool: every row would change and none of them would say ₱800.
     *
     * @param  array<string, mixed>  $slip
     * @param  array<string, array{amount?: float|null, label?: string|null, original?: float|null}>  $overrides
     * @return list<array<string, mixed>>
     */
    public static function deductionRows(array $slip, array $overrides = []): array
    {
        $lines = $slip['attendance_lines'] ?? [];
        $lines = is_array($lines) ? $lines : [];

        $groups = [];

        foreach ($lines as $line) {
            $kind = $line['kind'] ?? null;

            if ($kind === null) {
                continue;
            }

            $groups[$kind] ??= ['days' => 0.0, 'minutes' => 0, 'dates' => []];
            $groups[$kind]['days'] += (float) ($line['days'] ?? 0);
            $groups[$kind]['dates'][] = [
                'date' => $line['date'] ?? null,
                'detail' => $line['detail'] ?? null,
            ];

            // "180m" out of "180 min late" — the count the design prints beside each date.
            if (in_array($kind, self::TIME_KINDS, true) && preg_match('/^(\d+)\s*min/', (string) ($line['detail'] ?? ''), $m)) {
                $groups[$kind]['minutes'] += (int) $m[1];
            }
        }

        $totals = self::totals($slip);

        // --- the day-costing reasons share the absence charge ------------------------------------
        $dayKinds = array_values(array_diff(array_keys($groups), self::TIME_KINDS));
        $weights = [];
        foreach ($dayKinds as $k) {
            $weights[$k] = $groups[$k]['days'];
        }

        // A PAYSLIP FROM BEFORE THE LEDGER EXISTED still has an absence charge and no lines to
        // attribute it to (`attendance_lines` is null and is deliberately not backfilled). Without
        // this the rows would print as five zeroes above a five-figure total: the table
        // contradicting itself is far worse than a row that cannot name its dates.
        if (array_sum($weights) <= 0 && round((float) $totals['absences'], 2) != 0.0) {
            $weights = ['absent' => 1.0];
            $groups['absent'] ??= ['days' => 0.0, 'minutes' => 0, 'dates' => []];
        }

        $amounts = self::pinnedApportion((float) $totals['absences'], $weights, $overrides);

        // --- and the two time reasons share the clamped late/undertime charge --------------------
        $amounts += self::pinnedApportion((float) $totals['late_undertime'], [
            'late' => (float) ($slip['late_deduction'] ?? 0),
            'undertime' => (float) ($slip['undertime_deduction'] ?? 0),
        ], $overrides);

        $rows = [];

        foreach ([...self::ALWAYS_SHOWN, ...array_diff(array_keys($groups), self::ALWAYS_SHOWN)] as $kind) {
            $group = $groups[$kind] ?? null;

            $rows[] = [
                'key' => $kind,
                'name' => self::CHARGE_NAMES[$kind] ?? ucwords(str_replace('_', ' ', $kind)),
                'count' => $group === null ? 0 : count($group['dates']),
                'days' => round($group['days'] ?? 0.0, 2),
                'minutes' => in_array($kind, self::TIME_KINDS, true) ? (int) ($group['minutes'] ?? 0) : null,
                'dates' => $group['dates'] ?? [],
                'amount' => round($amounts[$kind] ?? 0.0, 2),
            ];
        }

        // --- everything that is not attendance: benefit shares, loans, cash advances -------------
        //
        // Keyed by a slug of the NAME, which is the only handle these have: neither a benefit share
        // nor a loan repayment carries an id onto the payslip. An index would be worse — inserting
        // a line above would silently re-point every override below it.
        foreach ($slip['other_deductions_breakdown'] ?? [] as $line) {
            $rows[] = [
                'key' => PayslipLines::keyForName(PayslipLines::OTHER_PREFIX, (string) $line['name']),
                'name' => (string) $line['name'],
                'count' => 0,
                'days' => 0.0,
                'minutes' => null,
                'dates' => [],
                'amount' => round((float) $line['amount'], 2),
            ];
        }

        return self::withOverrideMarks($rows, $overrides);
    }

    /**
     * The subtotals the payslip document is built from.
     *
     * The identity they satisfy, for every payslip:
     *
     *     earnings − deductions = current
     *     current − government  = initial_net
     *     initial_net + allowances = net_pay
     *
     * `compensation` leads with the FULL period basic, so the absence charge has to reappear on the
     * deductions side for the two to cancel — that is why `deductions` is
     * `absence_deduction + other_deductions` rather than the stored `total_deductions`.
     *
     * `late_undertime` is clamped to the absence charge because basic pay clamps at zero: past that
     * point the raw minute-level figures exceed the reduction that was really applied. The clamp
     * keeps both absence rows non-negative and their sum exact.
     *
     * `uncollected` is DISCLOSURE, not arithmetic (FIN-053). The components store what was
     * collected, so crediting the shortfall back here would credit it twice.
     *
     * @param  array<string, mixed>  $slip
     * @return array<string, mixed>
     */
    public static function totals(array $slip, string $runType = 'regular', ?array $amendments = null): array
    {
        $f = fn (string $field) => (float) ($slip[$field] ?? 0);

        $absenceCharge = $f('absence_deduction');
        $lateUndertime = min($f('late_deduction') + $f('undertime_deduction'), $absenceCharge);

        $compensation = round($f('gross') + $absenceCharge, 2);
        $allowances = round(array_sum(array_map(
            fn (array $line) => $line['amount'],
            self::allowanceLines($slip),
        )), 2);
        $deductions = round($absenceCharge + $f('other_deductions'), 2);
        $government = round($f('sss') + $f('philhealth') + $f('pagibig') + $f('withholding_tax'), 2);

        $current = round($compensation - $allowances - $deductions, 2);

        return [
            'compensation' => $compensation,
            // The Compensation table's own total: everything that is not an allowance.
            'earnings' => round($compensation - $allowances, 2),
            'allowances' => $allowances,
            'absences' => round($absenceCharge - $lateUndertime, 2),
            'late_undertime' => round($lateUndertime, 2),
            'deductions' => $deductions,
            // "Total Current Amount" — earned, less what attendance and obligations took off it,
            // and before any statutory contribution.
            'current' => $current,
            'government' => $government,
            // "Initial Net Salary" — net of contributions and tax, still without the allowances
            // that are paid on top of it.
            'initial_net' => round($current - $government, 2),
            'uncollected' => $f('deduction_carryover'),
            'uncollected_breakdown' => $slip['deduction_carryover_breakdown'] ?? [],
        ];
    }

    /**
     * Split `$total` across `$weights` in proportion, exactly.
     *
     * The residual left by rounding each share to centavos is carried onto the largest weight, so
     * the parts sum to the whole rather than to the whole ± a centavo per row. With no positive
     * weight the total cannot be attributed to anything and every share is zero — the caller's
     * skeleton rows then print 0.00, which is the truthful outcome.
     *
     * @param  array<string, float>  $weights
     * @return array<string, float>
     */
    public static function apportion(float $total, array $weights): array
    {
        $sum = array_sum($weights);

        if ($sum <= 0 || round($total, 2) == 0.0) {
            return array_map(fn () => 0.0, $weights);
        }

        $out = [];
        foreach ($weights as $key => $weight) {
            $out[$key] = round($total * ($weight / $sum), 2);
        }

        $largest = array_keys($weights, max($weights), true)[0];
        $out[$largest] = round($out[$largest] + ($total - array_sum($out)), 2);

        return $out;
    }

    /**
     * Apportion a pool after paying out the rows HR has pinned to a figure of their own.
     *
     * The pinned rows come off the top and the rest share what remains, so an edited row prints
     * exactly what was typed and the table still sums to its total. If the pins alone exhaust the
     * pool the remaining rows are zero rather than negative — a payslip cannot print a negative
     * charge, and the pins are what the person signing this document chose.
     *
     * @param  array<string, float>  $weights
     * @param  array<string, array{amount?: float|null}>  $overrides
     * @return array<string, float>
     */
    private static function pinnedApportion(float $total, array $weights, array $overrides): array
    {
        $pinned = [];

        foreach (array_keys($weights) as $key) {
            $value = $overrides[$key]['amount'] ?? null;

            if ($value !== null) {
                $pinned[$key] = round((float) $value, 2);
                unset($weights[$key]);
            }
        }

        if ($pinned === []) {
            return self::apportion($total, $weights);
        }

        $remaining = round($total - array_sum($pinned), 2);

        return $pinned + self::apportion(max(0.0, $remaining), $weights);
    }

    /**
     * Stamp each line with the correction standing against it, if any.
     *
     * `original` is the ENGINE's figure — what the document prints struck through beside the one HR
     * typed. It is carried on the line rather than looked up separately because every surface that
     * renders a line has to render its correction: the React document, the PDF and the edit dialog
     * would otherwise each need their own join back to the ledger.
     *
     * @param  list<array<string, mixed>>  $lines
     * @param  array<string, array{amount?: float|null, label?: string|null, original?: float|null}>  $overrides
     * @return list<array<string, mixed>>
     */
    private static function withOverrideMarks(array $lines, array $overrides): array
    {
        if ($overrides === []) {
            return $lines;
        }

        return array_map(function (array $line) use ($overrides) {
            $override = $overrides[$line['key']] ?? null;

            if ($override === null) {
                return $line;
            }

            return [
                ...$line,
                // The label HR typed replaces the printed name; the KEY is untouched, so the
                // payroll register, the remittance report and BIR 2316 keep grouping by what the
                // line is rather than by what it has been called.
                'name' => $override['label'] ?? $line['name'],
                'original_amount' => $override['original'] ?? null,
                'original_name' => ($override['label'] ?? null) === null ? null : $line['name'],
                'overridden' => true,
                'override_id' => $override['id'] ?? null,
                'override_reason' => $override['reason'] ?? null,
                'override_issued_on' => $override['issued_on'] ?? null,
                'override_by' => $override['issued_by'] ?? null,
            ];
        }, $lines);
    }

    /** "2.5 hrs" from a minute count, or null when there are none. */
    public static function minutesNote(mixed $minutes): ?string
    {
        $m = (int) $minutes;

        return $m > 0 ? rtrim(rtrim(number_format($m / 60, 2), '0'), '.') . ' hrs' : null;
    }

    /**
     * The per-date charge ledger, grouped into the two questions people actually ask.
     *
     * `unpaid` — WHERE THE FIGURE COMES FROM, and specifically where its decimal comes from. It
     * carries every line that cost a fraction of a day, lateness included, because "4.5 unpaid
     * days" beside a list of four absences leaves the .5 unexplained.
     *
     * `late` — HOW THE MINUTES BECAME MONEY. Every late/undertime line, whether or not it was
     * charged, so the minute counts can be traced to their days.
     *
     * A late line therefore appears in BOTH, deliberately: they answer different questions.
     *
     * @param  array<string, mixed>  $slip
     * @return array{unpaid: list<array<string, mixed>>, late: list<array<string, mixed>>}|null
     */
    public static function attendanceLines(array $slip): ?array
    {
        $lines = $slip['attendance_lines'] ?? null;

        if (! is_array($lines) || $lines === []) {
            return null;
        }

        $unpaid = [];
        $late = [];

        foreach ($lines as $line) {
            if (in_array($line['kind'] ?? null, self::TIME_KINDS, true)) {
                $late[] = $line;
            }

            if (round((float) ($line['days'] ?? 0), 4) > 0) {
                $unpaid[] = $line;
            }
        }

        if ($unpaid === [] && $late === []) {
            return null;
        }

        return ['unpaid' => $unpaid, 'late' => $late];
    }

    /** The slug half of a dynamic key, for matching an override back to the line it named. */
    public static function slug(string $name): string
    {
        return Str::slug($name);
    }
}
