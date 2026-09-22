<?php

namespace App\Modules\Hr\Support;

use Illuminate\Support\Str;

/** THE PAYSLIP AS A DOCUMENT — its lines, its rows, and the subtotals they foot to. */
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
        // Intern allowance charges (Company → Intern Allowance).
        'holiday_unpaid' => 'Holiday (No Allowance)',
        'below_min_attendance' => 'Below Minimum Attendance',
        // The salary's share of a cycle an intern was converted inside: the days before are paid
        // as the intern allowance line on the same slip.
        'pre_conversion' => 'Before Conversion',
    ];

    /** The five deduction rows the document always shows, in the order it shows them. */
    private const ALWAYS_SHOWN = ['absent', 'undertime', 'half_day', 'late', 'suspended_unpaid'];

    /**
     * The earning rows an ordinary period shows even at zero, in the order it shows them.
     *
     * A missing "Overtime" row is indistinguishable from overtime that was silently dropped, so
     * the skeleton is part of the document rather than something each renderer paints on top —
     * and being real rows they carry their KEYS, which is what makes them correctable. `basic` is
     * in the list for its place in the order; it is emitted unconditionally in any case.
     */
    private const ALWAYS_SHOWN_EARNINGS = ['basic', 'overtime', 'night_diff', 'rest_day', 'holiday'];

    /** The two charges measured in minutes rather than in days. */
    private const TIME_KINDS = ['late', 'undertime'];

    /**
     * Every earning on this payslip as a named, keyed line.
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

        $add = function (string $key, string $name, float $amount, ?string $note = null, string $kind = 'earning', ?array $dates = null) use (&$lines, $runType, $overrides, $slip) {
            /*
             * A ZERO LINE IS DROPPED, with two exceptions.
             *
             * The rows an ordinary period always shows, because their absence would read as the
             * figure having been lost rather than as there being none — and because a row HR
             * cannot see is a row HR cannot correct.
             *
             * And any line a correction stands against, whatever it is: HR correcting a figure
             * down to zero must not delete the line, or the struck-through original and the way
             * back to it go with it.
             */
            $keep = round($amount, 2) != 0.0
                || isset($overrides[$key])
                || ($runType === 'regular'
                    && in_array($key, self::ALWAYS_SHOWN_EARNINGS, true)
                    // An intern's premium row is drawn at zero only if their rules can pay it at
                    // all: "Overtime ₱0" on a slip whose rules pay no overtime reads as a figure
                    // withheld rather than as one that does not exist.
                    && (empty($slip['is_intern']) || ($slip['intern_basis']['premiums'][$key] ?? false)));

            if ($keep) {
                $lines[] = ['key' => $key, 'name' => $name, 'amount' => round($amount, 2), 'note' => $note, 'kind' => $kind, 'dates' => $dates];
            }
        };

        $f = fn (string $field) => (float) ($slip[$field] ?? 0);

        // --- the ordinary period ---------------------------------------------------------------
        if ($runType === 'regular') {
            $intern = ! empty($slip['is_intern']);

            $lines[] = [
                // Still `basic` for an intern: the key is what corrections, amendments and the
                // register group by, and an allowance occupies exactly that place on the slip.
                'key' => 'basic',
                'name' => $intern ? 'Allowance' : 'Basic Pay',
                // The FULL period basic. The absence charge is shown against it on the deductions
                // side rather than folded into it, so the cost of an absence stays visible.
                'amount' => round($f('period_base'), 2),
                // "11 days × ₱500.00" — how the allowance was reached, since no salary explains it.
                'note' => $intern ? ($slip['intern_basis']['note'] ?? null) : null,
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
            $add('rest_day', 'Rest Day', $f('rest_day_premium'), self::minutesNote($slip['rest_day_minutes'] ?? 0));
            $holidayDates = self::holidayDates($slip);
            $add('holiday', 'Holiday', $f('holiday_premium'), self::daysNote(count($holidayDates)), 'earning', $holidayDates ?: null);
        }

        // --- 13th month ------------------------------------------------------------------------
        // `other_benefits` is the same money seen from the tax side (what went into the ₱90,000
        // bucket), so it is NOT a second earnings line — adding it would double the compensation.
        $add('thirteenth_month', '13th Month Pay', $f('thirteenth_month'));

        // --- corrections ADDED to this period ----------------------------------------------------
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

                // THE LABEL HR TYPED IS THE LINE'S NAME. The form calls that field "what the line
                // is called on the payslip", and it used to print as the small note underneath
                // while the line itself was always "Adjustment" — the deduction side has always
                // named its line from the same field. Falling back to the catalogue wording when
                // nothing was typed: a reversal is a line of its own, since "Adjustment −₱1,000"
                // reads as an adjustment that was mis-entered.
                $default = (float) $amendment['amount'] < 0 ? 'Pay Reversed' : 'Adjustment';
                $label = trim((string) ($amendment['note'] ?? ''));

                $add(
                    'amendment:' . ($amendment['id'] ?? 0),
                    $label !== '' ? $label : $default,
                    (float) $amendment['amount'],
                    // The REASON underneath, which is the half that explains the line rather than
                    // naming it, and which was previously only visible when no label was typed.
                    trim(((string) ($amendment['reason'] ?? '')) . ' · issued '
                        . ((string) ($amendment['issued_on'] ?? ''))),
                );
            }
        } else {
            // The single stored total, for the payslips of the superseded adjustment runs and for
            // any caller that did not load the ledger. Both are real payslips of real money.
            $add('adjustment', 'Adjustment', $f('adjustment_pay'), ($slip['adjustment_note'] ?? null) ?: null);
        }

        // --- final pay ---------------------------------------------------------------
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
     * THE HOLIDAYS BEHIND THE HOLIDAY FIGURE — one entry per day worked, in the same
     * {date, detail} shape the deduction rows use, so the document renders both with one
     * component instead of two that drift.
     *
     * Empty for payslips written before `holiday_lines` existed, which keeps their Holiday row
     * exactly as it has always printed: a total with nothing under it. Deriving those days now
     * would read TODAY'S holiday calendar and schedule, which is the one answer a document of
     * record must not give.
     *
     * @param  array<string, mixed>  $slip
     * @return list<array{date: string|null, detail: string|null, amount: float}>
     */
    public static function holidayDates(array $slip): array
    {
        $lines = $slip['holiday_lines'] ?? [];
        $lines = is_array($lines) ? $lines : [];

        return array_values(array_map(fn (array $line) => [
            'date' => ($line['date'] ?? null) === null ? null : (string) $line['date'],
            'detail' => self::holidayDetail($line),
            // The same facts at a third of the width, for the PDF. The printed row is a 6.5px
            // half-column: the full wording trims mid-phrase there, and what it loses is the
            // rate — the one thing on the line the employee can actually check.
            'short' => self::holidayShort($line),
            'amount' => round((float) ($line['amount'] ?? 0), 2),
        ], $lines));
    }

    /**
     * One holiday line as words: what the day was, what rate it paid, and — when it landed on a
     * rest day — why that rate is the WHOLE daily rate rather than the premium on top of it.
     * The employee otherwise sees a figure twice the size of the one beside it with no reason given.
     *
     * @param  array<string, mixed>  $line
     */
    private static function holidayDetail(array $line): ?string
    {
        $type = (string) ($line['type'] ?? '');
        $kind = match ($type) {
            'regular' => 'Regular holiday',
            'special_non_working' => 'Special non-working',
            default => 'Holiday',
        };

        $parts = [];
        if (($line['name'] ?? null) !== null && trim((string) $line['name']) !== '') {
            $parts[] = trim((string) $line['name']);
        }

        $rate = (int) ($line['rate_pct'] ?? 0);
        $parts[] = $rate > 0 ? "{$kind} {$rate}%" : $kind;

        if (! empty($line['rest_day'])) {
            // Not an extra premium — the full rate, because a rest day was never inside the
            // salary in the first place. Worth saying, or the bigger number reads as a mistake.
            $parts[] = 'on a rest day (full rate)';
        }

        return $parts === [] ? null : implode(' · ', $parts);
    }

    /**
     * The print form: the holiday, its rate, and the rest-day flag — everything material, with
     * only the NAME allowed to trim, because it is the one part whose length is unbounded.
     *
     * @param  array<string, mixed>  $line
     */
    private static function holidayShort(array $line): string
    {
        $rate = (int) ($line['rate_pct'] ?? 0);
        $parts = [];

        $name = trim((string) ($line['name'] ?? ''));
        if ($name !== '') {
            $parts[] = Str::limit($name, 24, '…');
        }

        $parts[] = ($rate > 0 ? $rate . '%' : 'holiday') . (empty($line['rest_day']) ? '' : ' rest day');

        return implode(' · ', $parts);
    }

    /** "2 days" beside the Holiday label, or null when there is nothing to count. */
    private static function daysNote(int $days): ?string
    {
        return $days > 0 ? $days . ' ' . ($days === 1 ? 'day' : 'days') : null;
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

        /*
         * A CORRECTION CAN NAME A ROW THE LEDGER NEVER PRODUCED. The five rows above are drawn
         * whether or not anything was charged, and every charge kind is correctable, so HR can pin
         * a figure to a row with no attendance lines behind it. Its key would not be in `$weights`
         * — that is built from the ledger — and pinnedApportion can only pin what it is handed: the
         * row would print zero while the money HR entered, which went into the one shared
         * `absence_deduction` column, was apportioned out among the kinds that DO have days. That
         * is the Absences row silently absorbing somebody else's charge.
         *
         * Seeded at ZERO weight, so a pinned row takes its own figure and nothing more; and seeded
         * AFTER the legacy fallback above, which replaces `$weights` wholesale and would otherwise
         * throw these away.
         */
        $pinnedKinds = array_values(array_filter(
            array_keys($overrides),
            fn (string $key) => ! array_key_exists($key, $weights)
                && ! in_array($key, self::TIME_KINDS, true)
                && PayslipLines::classFor($key) === PayslipLines::ATTENDANCE_CHARGE,
        ));

        foreach ($pinnedKinds as $kind) {
            $weights[$kind] = 0.0;
        }

        $amounts = self::pinnedApportion((float) $totals['absences'], $weights, $overrides);

        // --- and the two time reasons share the clamped late/undertime charge --------------------
        $amounts += self::pinnedApportion((float) $totals['late_undertime'], [
            'late' => (float) ($slip['late_deduction'] ?? 0),
            'undertime' => (float) ($slip['undertime_deduction'] ?? 0),
        ], $overrides);

        $rows = [];

        // The always-drawn five, then whatever else the ledger charged, then any further row a
        // correction has brought into existence.
        $order = array_unique([...self::ALWAYS_SHOWN, ...array_keys($groups), ...$pinnedKinds]);

        foreach ($order as $kind) {
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
        foreach ($slip['other_deductions_breakdown'] ?? [] as $line) {
            $rows[] = [
                // A line an amendment created is keyed by that amendment, so two entered under the
                // same label cannot collapse onto one key. Everything else — benefit shares, loans,
                // cash advances — is keyed by a slug of its name, as it always was.
                'key' => ($line['amendment_id'] ?? null) === null
                    ? PayslipLines::keyForName(PayslipLines::OTHER_PREFIX, (string) $line['name'])
                    : PayslipLines::keyForAmendment(PayslipLines::OTHER_PREFIX, (int) $line['amendment_id']),
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
