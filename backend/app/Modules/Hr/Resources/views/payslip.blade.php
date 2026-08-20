{{--
    The payslip PDF — the same document `payslip-document.tsx` renders on screen.

    Nested tables rather than flex because DomPDF has no flexbox. Every subtotal comes from
    `$p['totals']`, computed once in PayslipResource, so this and the UI cannot drift apart.

    DejaVu Sans / DejaVu Sans Mono ship with DomPDF, so no font is installed. Amounts are bare
    numbers with a single "PHP" on the net line — DomPDF's fonts have no ₱ glyph and would
    render it as a blank box.

    One document for both audiences: HR's download and the employee's are byte-identical. That
    includes the employer's share, shown under each statutory line — it is never a term in any total.

    THE 2026-08-14 REBUILD. The document was re-cut to the four-step walk the screen now prints
    (earnings → deductions → contributions → allowances) and the workings were dropped with it: the
    computation-basis table, the per-contribution derivations, and the identity block's government
    numbers and address are all gone. What remains is what was earned, what was taken, and what was
    paid.

    THE COLUMNS CARRY THAT WALK, they do not interrupt it. Left is the period on its own — earned,
    charged, and the subtotal those two produce. Right is what happens to that subtotal — the month
    restated, the government's share, and the allowances paid on top. The conclusions run full width
    beneath both, so the figure the reader is looking for is never in a column.

    ONE PAGE, ALWAYS. See `$capped`, `$fit` and `$fitDates` below: every variable-length list is
    bounded and anything that could wrap is trimmed, so the document's height cannot grow with data.
    Overflow does not truncate the top — it pushes the net pay and signatures onto page two.
--}}
@php
    $t = $p['totals'];
    $month = $p['month_summary'] ?? null;
    $cutoff = $p['cutoff'] ?? null;

    $orDash = fn ($v) => ($v === null || $v === '') ? '—' : $v;

    /** A header rate, or an em dash when the employee is not paid on one (management staff). */
    $rate = fn ($n) => (float) $n > 0 ? $amt($n) : '—';

    /** Two peso figures that differ by more than rounding. */
    $differs = fn ($a, $b) => abs((float) $a - (float) $b) > 0.005;

    /**
     * The rows the Compensation table shows even at zero, in the order it shows them — matching
     * the screen. A reader checking a payslip is checking a list they already expect, and a
     * missing "Overtime" row is indistinguishable from overtime that was silently dropped.
     *
     * ONLY AN ORDINARY PERIOD HAS A SKELETON. A 13th-month, adjustment or final-pay run has no
     * basic pay and no overtime; five zero rows above the one line carrying the money would
     * describe it as an ordinary period that paid nothing, which is the exact defect FIN-011 fixed.
     */
    $skeleton = ['Basic Pay', 'Overtime', 'Night Differential', 'Rest Day', 'Holiday'];

    $earningLines = array_values(array_filter(
        $p['earnings_breakdown'],
        fn ($l) => ($l['kind'] ?? 'earning') !== 'allowance',
    ));

    if ($p['run_type'] === 'regular') {
        $bySkeleton = [];
        foreach ($skeleton as $name) {
            $found = null;
            foreach ($earningLines as $l) {
                if ($l['name'] === $name) { $found = $l; break; }
            }
            $bySkeleton[] = $found ?? ['name' => $name, 'amount' => 0.0, 'note' => null];
        }

        $extra = array_values(array_filter(
            $earningLines,
            fn ($l) => ! in_array($l['name'], $skeleton, true),
        ));

        $earningLines = array_merge($bySkeleton, $extra);
    }

    $allowanceLines = array_values(array_filter(
        $p['earnings_breakdown'],
        fn ($l) => ($l['kind'] ?? null) === 'allowance',
    ));

    /**
     * THE CORRECTIONS HR HAS MADE TO LINES THAT WERE ALREADY HERE, keyed by the line they name.
     *
     * The earnings and deduction rows carry their own correction on them, put there by
     * PayslipDocument. The government lines do not — they are printed straight from their columns,
     * in a table of their own — so those are looked up here instead.
     */
    $corrections = [];

    foreach ($p['amendments'] ?? [] as $amendment) {
        if (($amendment['target_key'] ?? null) !== null) {
            $corrections[$amendment['target_key']] = $amendment;
        }
    }

    // How many lines were corrected, and when the most recent of them was issued.
    $amendedCount = count($corrections);
    $amendedOn = null;

    foreach ($corrections as $amendment) {
        if (($amendment['issued_on'] ?? null) !== null && $amendment['issued_on'] > (string) $amendedOn) {
            $amendedOn = $amendment['issued_on'];
        }
    }

    /** The struck-through original, or nothing at all on a line nobody has corrected. */
    $was = fn ($original) => $original === null ? '' : '<span class="was">' . $amt($original) . '</span>';

    /**
     * ONE PAGE, GUARANTEED — the lists whose length comes from configuration rather than layout.
     *
     * An allowance line per programme the employee is on, a deduction line per benefit share and
     * loan, an earnings line per premium. Nothing caps them, so with enough of any the payslip runs
     * onto a second page — and it takes the net pay and the signature block with it, which is the
     * half that matters.
     *
     * `$capped` keeps the first few and ROLLS THE REMAINDER INTO ONE LABELLED LINE. It is not a
     * truncation: the rolled line carries the summed amount and says how many items it covers, so
     * the column still adds up to the same total and no peso goes unaccounted for. The full
     * itemisation is always on screen, where there is no page boundary.
     */
    $capped = function (array $lines, int $max, string $label) {
        if (count($lines) <= $max) {
            return $lines;
        }

        $keep = array_slice($lines, 0, $max - 1);
        $rest = array_slice($lines, $max - 1);

        $keep[] = [
            'name' => $label . ' (' . count($rest) . ' items)',
            'amount' => array_sum(array_map(fn ($l) => (float) $l['amount'], $rest)),
            'note' => null,
            // Inert: the roll-up is a print-only line, so it is neither an attendance row nor a
            // correctable one. The filters below key off the `other:` prefix, which this is not.
            'key' => 'rolled',
            'count' => 0,
            'minutes' => null,
            'dates' => [],
        ];

        return $keep;
    };

    /**
     * A name long enough to wrap adds a row's height and defeats the caps above. The screen has
     * room to show it in full; a printed half-width column is ~34 characters, so this trims to fit.
     */
    $fit = fn (string $name) => mb_strlen($name) > 32 ? mb_substr($name, 0, 31) . '…' : $name;

    $fitWide = fn ($v, int $max) => ($v === null || $v === '')
        ? '—'
        : (mb_strlen((string) $v) > $max ? mb_substr((string) $v, 0, $max - 1) . '…' : (string) $v);

    /**
     * The dates behind a deduction row, as one grey line — "Aug 5, Aug 10", or "Aug 1 180m ·
     * Aug 5 120m" once minutes are what varies.
     *
     * TRIMMED BY DATE COUNT, not by characters: cutting mid-date reads as a rendering fault, and
     * a period with twenty absences is exactly the period whose row must not wrap onto four lines.
     * The count badge beside the label already states how many there were, so the tail is
     * summarised rather than silently dropped.
     */
    $fitDates = function (array $row) {
        $dates = $row['dates'] ?? [];

        if ($dates === []) {
            return null;
        }

        $isTime = ($row['minutes'] ?? null) !== null;
        $max = 6;
        $shown = array_slice($dates, 0, $max);
        $parts = [];

        foreach ($shown as $d) {
            $day = empty($d['date'])
                ? '—'
                : \Illuminate\Support\Carbon::parse($d['date'])->format('M j');

            if ($isTime && preg_match('/^(\d+)\s*min/', (string) ($d['detail'] ?? ''), $m)) {
                $day .= ' ' . $m[1] . 'm';
            }

            $parts[] = $day;
        }

        $text = implode($isTime ? ' · ' : ', ', $parts);
        $rest = count($dates) - count($shown);

        return $rest > 0 ? $text . ' +' . $rest . ' more' : $text;
    };

    // Attendance reasons are bounded by the number of charge kinds; benefit shares and loans are
    // not, so only those are capped.
    $rows = $p['deductions_breakdown'] ?? [];

    if ($p['run_type'] !== 'regular') {
        $rows = array_values(array_filter($rows, fn ($r) => (float) $r['amount'] != 0.0));
    }

    // Benefit shares, loans and corrections are keyed `other:<name slug>` — a slug rather than a
    // bare 'other' since a line HR can correct has to be nameable. The prefix is what tells the
    // two halves of the table apart.
    $isOther = fn (array $r) => str_starts_with((string) $r['key'], 'other:');
    $attendanceRows = array_values(array_filter($rows, fn ($r) => ! $isOther($r)));
    $otherRows = $capped(
        array_values(array_filter($rows, $isOther)),
        4,
        'Other deductions',
    );

    $earningLines = $capped($earningLines, 9, 'Other earnings');
    $allowanceLines = $capped($allowanceLines, 5, 'Other allowances');

    /**
     * The employer's counterpart contributions — DISCLOSED, NEVER A TERM IN ANY TOTAL.
     *
     * Only used to decide whether the footnote appears at all: a run that takes no statutory
     * contributions (13th month, final pay, an adjustment) has nothing to say. The figures
     * themselves are read per line beside the employee's own.
     */
    $erTotal = (float) ($p['employer_sss'] ?? 0)
        + (float) ($p['employer_philhealth'] ?? 0)
        + (float) ($p['employer_pagibig'] ?? 0);

    // On the month's last cutoff the walk continues from the MONTH's figures, not this period's.
    $initialNet = $month ? $month['initial_net'] : $t['initial_net'];
    $netLabel = ! empty($cutoff['label']) ? 'Total Net (' . $cutoff['label'] . ')' : 'Total Net';
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <style>
        @page { margin: 26px 30px; }

        body { font-family: 'DejaVu Sans', sans-serif; color: #141414; font-size: 9px; margin: 0; }
        table { width: 100%; border-collapse: collapse; }
        td { vertical-align: top; }

        .amt { text-align: right; white-space: nowrap; font-family: 'DejaVu Sans Mono', monospace; }
    /* WHAT THE ENGINE COMPUTED, on a line HR has since corrected. Struck and grey, immediately
       before the figure that replaced it — the reader is entitled to see that the document was
       amended and what it said before, which is the whole point of correcting a line in place
       rather than appending a note underneath the total. */
    .was { text-decoration: line-through; color: #9ca3af; padding-right: 3px; font-weight: normal; }
        .muted { color: #6b7280; }
        .cap { font-family: 'DejaVu Sans Mono', monospace; font-size: 6.5px;
               text-transform: uppercase; color: #6b7280; letter-spacing: 0.2px; }

        /* Header */
        .company { text-align: center; font-size: 13px; font-weight: bold; }
        .address { text-align: center; font-size: 7px; color: #6b7280; padding-top: 1px; }

        .title { font-family: 'DejaVu Sans Mono', monospace; font-size: 17px; font-weight: bold; }
        .paydate { font-size: 10px; font-weight: bold; text-align: right; }
        .rule { border-bottom: 1px solid #e5e7eb; height: 1px; font-size: 0; margin: 5px 0; }

        .val { font-size: 8.5px; font-weight: bold; padding-top: 1px; }

        /* Employee card */
        .card { border: 1px solid #e5e7eb; border-radius: 4px; padding: 6px 9px; }
        .card td { padding-bottom: 4px; }
        .card .last td { padding-bottom: 0; }

        /* Money tables.

           SMALL ON PURPOSE (2026-08-14, Joshua's brief). The screen document grew a Salary Summary
           and an Allowances table on top of what was already here, and print has a page boundary
           the screen does not. PER-ROW SPACING IS THE EXPENSIVE KIND: the tallest column runs to
           about twenty rows at the caps, so a point of row padding costs twenty points of page.
           That is why the padding below is minimal and the caps above are set where they are. */
        .sect { margin-top: 7px; }
        /* The next table inside a column cell. Each column flows on its own, so this is the only
           thing separating one section from the next within a column. */
        .stack { margin-top: 8px; }
        .thead td { background: #f4f5f7; border-bottom: 1px solid #e5e7eb;
                    font-family: 'DejaVu Sans Mono', monospace;
                    font-size: 7.5px; font-weight: bold; text-transform: uppercase;
                    letter-spacing: 0.2px; padding: 3px 6px; }
        .line td { border-bottom: 1px solid #e5e7eb; padding: 2px 6px; font-size: 8.5px; }
        .sum td { padding: 3px 6px; font-size: 8.5px; font-weight: bold; }

        .cols td { vertical-align: top; }
        .cols .gap { width: 12px; }

        /* A subtotal the walk pauses on — quieter than the net bars, louder than a row. */
        .bar { background: #f4f5f7; border-radius: 4px; margin-top: 7px; }
        .bar td { padding: 5px 8px; font-family: 'DejaVu Sans Mono', monospace;
                  font-size: 8.5px; font-weight: bold; text-transform: uppercase; }
        .bar .v { text-align: right; font-size: 10px; }

        .note { border: 1px solid #f0a608; background: #fdf1d6; border-radius: 4px;
                padding: 3px 7px; margin-top: 5px; font-size: 8px; }

        .net { background: #141414; color: #ffffff; border-radius: 4px; margin-top: 6px; }
        .net td { padding: 6px 10px; font-family: 'DejaVu Sans Mono', monospace; font-weight: bold; }
        .net .lbl { font-size: 10px; text-transform: uppercase; }
        .net .v { font-size: 13px; text-align: right; color: #ffffff; }

        /* The count beside a deduction reason — how many dated occurrences it covers. */
        .badge { font-family: 'DejaVu Sans Mono', monospace; font-size: 7px; font-weight: bold;
                 color: #ffffff; background: #f34444; padding: 0 3px; border-radius: 2px; }

        /* The employer's counterpart, tucked under each statutory line. Indented, smaller, italic
           and grey — four signals at once that it belongs to the row above and is not a deduction.
           It is never in the government total; `documentTotals()` cannot see it. */
        .line.pair td { border-bottom: none; padding-bottom: 0; }
        .ersub td { border-bottom: 1px solid #e5e7eb; padding: 0 6px 2px;
                    font-size: 7px; font-style: italic; color: #6b7280; }
        .ersub .erlbl { padding-left: 12px; }

        /* Said once, under the total it is deliberately absent from. */
        .ernote td { padding: 2px 6px 0; font-size: 6.5px; font-style: italic; color: #6b7280; }

        /* The dates behind a count, and the tax derivation. Spans both columns because it is a
           sentence, not an amount. */
        .why td { border-bottom: 1px solid #e5e7eb; padding: 0 6px 2px 6px;
                  font-size: 6.5px; color: #6b7280; }
        .why.open td { border-bottom: none; padding-bottom: 1px; }

        /* Footer */
        .foot { margin-top: 9px; page-break-inside: avoid; }
        .foot td { vertical-align: bottom; }
        .sig { text-align: center; }
        .sig .rule { border-bottom: 1px solid #141414; margin: 0 0 2px; }
        .sig .who { font-size: 7px; text-transform: uppercase; }
    </style>
</head>
<body>

<div class="company">VITE SEO DIGITAL ADVERTISING OPC</div>
<div class="address">26L Burgundy Corporate Tower, 252 Sen. Gil Puyat Avenue, Pio Del Pilar 1230 City of Makati NCR, Fourth District Philippines</div>

<table style="margin-top: 10px;">
    <tr>
        <td class="title">PAYSLIP</td>
        <td style="text-align: right;">
            <div class="cap">Pay Date</div>
            <div class="paydate">{{ $fmt($p['pay_date']) }}</div>
        </td>
    </tr>
</table>

<div class="rule"></div>

<table>
    <tr>
        <td style="width: 22%;">
            <div class="cap">Pay Type</div>
            {{-- FIN-011 — the resolved label, not the raw `frequency`: for a final-pay run that
                 string is "final_pay:<employee id>". --}}
            <div class="val">{{ $p['run_type_label'] }}</div>
        </td>
        <td>
            <div class="cap">Pay Period</div>
            <div class="val">{{ $fmt($p['cycle_start']) }} – {{ $fmt($p['cycle_end']) }}</div>
        </td>
    </tr>
</table>

{{-- Name, role and the three rates the deduction arithmetic is quoted in. The government numbers,
     employee id, department and address were removed on 2026-08-14 (Joshua's call). --}}
<div class="card" style="margin-top: 8px;">
    <table>
        <tr>
            <td style="width: 50%;">
                <div class="cap">Employee Name</div>
                <div class="val">{{ $fitWide($p['employee']['name'], 44) }}</div>
            </td>
            <td>
                <div class="cap">Job Role</div>
                <div class="val">{{ $fitWide($p['employee']['job_title'], 44) }}</div>
            </td>
        </tr>
        <tr>
            <td colspan="2" style="padding-bottom: 4px;">
                <div style="border-bottom: 1px solid #e5e7eb; height: 1px; font-size: 0;"></div>
            </td>
        </tr>
        <tr class="last">
            <td colspan="2">
                <table>
                    <tr>
                        {{-- Em dashes, not zeroes: management staff are not paid by the day, and
                             "Per Day 0.00" states that a day of their time is worth nothing. --}}
                        <td style="width: 34%;">
                            <div class="cap">Per Day</div>
                            <div class="val">{{ $rate($p['rates']['daily']) }}</div>
                        </td>
                        <td style="width: 33%;">
                            <div class="cap">Per Hour</div>
                            <div class="val">{{ $rate($p['rates']['hourly']) }}</div>
                        </td>
                        <td>
                            <div class="cap">Half Day</div>
                            <div class="val">{{ $rate($p['rates']['half_day']) }}</div>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</div>

{{--
    TWO INDEPENDENT COLUMNS.

    WHY ONE TABLE AND NOT TWO STACKED. A table row is as tall as its tallest cell, so a second
    `cols` table could not start until the first finished on BOTH sides — leaving a band of dead
    space under whichever column was shorter, growing with every allowance line opposite it. One
    row, one cell per column, each holding its own stack of tables: the columns flow independently.
    It is also never taller — max(a,b) + max(c,d) >= max(a+c, b+d) — so the one-page guarantee can
    only improve.
--}}
<table class="sect cols">
    <tr>
        {{-- LEFT: the period on its own — earned, charged, and the subtotal those two produce. --}}
        <td style="width: 49%;">
            <table>
                <tr class="thead"><td>Compensation</td><td class="amt">Amount</td></tr>
                @foreach ($earningLines as $line)
                    <tr class="line">
                        <td>
                            @if (! empty($line['original_name']))
                                <span class="was">{{ $fit($line['original_name']) }}</span>
                            @endif
                            {{ $fit($line['name']) }}
                            @if (! empty($line['note']))
                                <span class="muted">({{ $line['note'] }})</span>
                            @endif
                        </td>
                        <td class="amt">{!! $was($line['original_amount'] ?? null) !!}{{ $amt($line['amount']) }}</td>
                    </tr>
                @endforeach
                <tr class="sum"><td>Total Compensation</td><td class="amt">{{ $amt($t['earnings']) }}</td></tr>
            </table>

            <table class="stack">
                <tr class="thead"><td>Deductions</td><td class="amt">Amount</td></tr>
                @foreach (array_merge($attendanceRows, $otherRows) as $row)
                    @php
                        // Assembled here rather than with inline directives. Blade's directive
                        // scanner is unreliable when a conditional opens or closes flush against
                        // adjacent text, and it fails the whole VIEW rather than the line — which
                        // is how the first draft of this loop 500'd every payslip download.
                        $dates = $fitDates($row);
                        $minutes = empty($row['minutes']) ? '' : $row['minutes'] . ' min';
                    @endphp
                    {{-- The count badge and the date line are the point of the row: "Absences
                         1,316.00" has to be taken on trust; "Absences (2) Aug 5, Aug 10" can be
                         checked against the reader's own memory of the month. --}}
                    <tr class="line{{ $dates ? ' pair' : '' }}">
                        <td>
                            @if (! empty($row['original_name']))
                                <span class="was">{{ $fit($row['original_name']) }}</span>
                            @endif
                            {{ $fit($row['name']) }}
                            @if (! empty($row['count']))
                                <span class="badge">{{ $row['count'] }}</span>
                            @endif
                        </td>
                        <td class="amt">{!! $was($row['original_amount'] ?? null) !!}{{ $amt($row['amount']) }}</td>
                    </tr>
                    @if ($dates)
                        <tr class="why">
                            <td>{{ $dates }}</td>
                            <td class="amt">{{ $minutes }}</td>
                        </tr>
                    @endif
                @endforeach
                <tr class="sum"><td>Total Deduction</td><td class="amt">{{ $amt($t['deductions']) }}</td></tr>
            </table>

            <table class="bar">
                <tr>
                    <td>Total Current Amount</td>
                    <td class="v">{{ $amt($t['current']) }}</td>
                </tr>
            </table>
        </td>

        <td class="gap"></td>

        {{-- RIGHT: what happens to that subtotal — the month restated, the government's share,
             and the allowances paid on top of a salary that was already taxed. --}}
        <td>
            {{-- THE MONTH, RESTATED — carried by its last cutoff only. Each earlier cutoff is
                 restated by its Total Current Amount rather than its net pay: the two are equal
                 only on a cutoff that took no contributions, which is exactly what this must not
                 silently depend on. --}}
            @if ($month)
                <table>
                    <tr class="thead"><td>Salary Summary</td><td class="amt">Amount</td></tr>
                    @foreach ($month['periods'] as $period)
                        <tr class="line">
                            <td>{{ $period['is_current'] ? 'Current Salary' : 'Last Salary' }} ({{ $period['label'] }})</td>
                            <td class="amt">{{ $amt($period['current_amount']) }}</td>
                        </tr>
                    @endforeach
                    <tr class="sum"><td>Total Amount</td><td class="amt">{{ $amt($month['total_current']) }}</td></tr>
                </table>
            @endif

            <table class="{{ $month ? 'stack' : '' }}">
                <tr class="thead"><td>Government Benefits &amp; Tax</td><td class="amt">Amount</td></tr>
                {{--
                    Each statutory line carries the EMPLOYER's counterpart directly beneath it —
                    indented, smaller, italic and grey, so it reads as a note on the line above
                    rather than as another deduction.

                    Beside the employee's own figure it means something: they can see that P875 of
                    their pay went to SSS and the company put P1,765 in alongside it. Detached from
                    that comparison it was just another number.

                    IT IS NEVER IN THE TOTAL. That row is `$t['government']`, computed by
                    `documentTotals()` from the employee's deductions alone — there is no code path
                    by which these rows reach it. The styling says so and the arithmetic enforces it.
                --}}
                @foreach ([
                    ['sss', 'SSS', $p['sss'], $p['employer_sss'] ?? 0],
                    ['philhealth', 'PhilHealth', $p['philhealth'], $p['employer_philhealth'] ?? 0],
                    ['pagibig', 'Pag-IBIG', $p['pagibig'], $p['employer_pagibig'] ?? 0],
                ] as [$key, $label, $employeeShare, $employerShare])
                    @php $fix = $corrections[$key] ?? null; @endphp
                    <tr class="line{{ $employerShare > 0 ? ' pair' : '' }}">
                        <td>
                            @if (! empty($fix['label_override']))
                                <span class="was">{{ $label }}</span> {{ $fit($fix['label_override']) }}
                            @else
                                {{ $label }}
                            @endif
                        </td>
                        <td class="amt">{!! $was($fix['original_amount'] ?? null) !!}{{ $amt($employeeShare) }}</td>
                    </tr>
                    @if ($employerShare > 0)
                        <tr class="ersub">
                            <td class="erlbl">+ employer share</td>
                            <td class="amt">{{ $amt($employerShare) }}</td>
                        </tr>
                    @endif
                @endforeach
                @php
                    // Built here rather than with inline directives: Blade will not parse a
                    // conditional that directly follows a digit ("÷ 12" then a directive compiles
                    // the closing tag and leaves the opener literal), and the sentence reads
                    // better assembled than stitched out of six template fragments.
                    $taxWhy = $p['tax_basis'] ?? null;
                    $taxWhyLines = [];

                    if ($taxWhy) {
                        $taxWhyLines[] = '(' . $amt($taxWhy['taxable_monthly']) . ' taxable − '
                            . $amt($taxWhy['contributions_monthly']) . ' contributions) × 12 = '
                            . $amt($taxWhy['annual_taxable']) . ' a year';

                        if (! empty($taxWhy['band'])) {
                            $bandRate = rtrim(rtrim(number_format($taxWhy['band']['rate'] * 100, 3), '0'), '.');
                            $over = $taxWhy['band']['base'] > 0
                                ? ' plus ' . $amt($taxWhy['band']['base'])
                                : '';
                            $split = $taxWhy['timing_factor'] != 1
                                ? ' × ' . $taxWhy['timing_factor']
                                : '';

                            $taxWhyLines[] = $bandRate . '% over ' . $amt($taxWhy['band']['min']) . $over
                                . ' = ' . $amt($taxWhy['annual_tax']) . ' a year ÷ 12' . $split;
                        }
                    }
                @endphp
                @php $taxFix = $corrections['withholding_tax'] ?? null; @endphp
                <tr class="line {{ $taxWhyLines ? 'pair' : '' }}">
                    <td>
                        @if (! empty($taxFix['label_override']))
                            <span class="was">Withholding Tax</span> {{ $fit($taxFix['label_override']) }}
                        @else
                            Withholding Tax
                        @endif
                    </td>
                    <td class="amt">{!! $was($taxFix['original_amount'] ?? null) !!}{{ $amt($p['withholding_tax']) }}</td>
                </tr>
                {{-- The least checkable figure on the document: an annualised projection of one
                     period, net of contributions, against a dated bracket table. Four steps, none
                     of them visible, ending in a peso amount the reader was expected to trust.
                     Kept when the other derivations were dropped, because December's tax line can
                     go NEGATIVE and nothing else on the page would account for that. --}}
                @foreach ($taxWhyLines as $line)
                    <tr class="why {{ $loop->last ? '' : 'open' }}"><td colspan="2">{{ $line }}</td></tr>
                @endforeach
                {{-- FIN-054 — the year-end settlement, shown as its derivation. On the common case
                     the line above is NEGATIVE and net pay exceeds gross; the printed payslip is
                     the copy an employee keeps and queries against. Absent on every other payslip. --}}
                @if (($p['tax_annualisation_basis'] ?? null) !== null)
                    @php $tb = $p['tax_annualisation_basis']; $isRefund = $tb['settled_this_period'] < 0; @endphp
                    <tr class="ernote"><td colspan="2"><strong>Year-end tax settlement · {{ $tb['year'] }}</strong></td></tr>
                    <tr class="ersub"><td class="erlbl">Taxable pay for the year</td><td class="amt">{{ $amt($tb['year_taxable']) }}</td></tr>
                    <tr class="ersub"><td class="erlbl">Tax due on that</td><td class="amt">{{ $amt($tb['annual_tax_due']) }}</td></tr>
                    <tr class="ersub"><td class="erlbl">Already withheld this year</td><td class="amt">{{ $amt($tb['previously_withheld']) }}</td></tr>
                    <tr class="ersub"><td class="erlbl">{{ $isRefund ? 'Refunded on this payslip' : 'Collected on this payslip' }}</td><td class="amt">{{ $amt(abs($tb['settled_this_period'])) }}</td></tr>
                    <tr class="ernote"><td colspan="2">Tax is withheld each period on an estimate of the year. Yours came out {{ $isRefund ? 'higher' : 'lower' }} than the year needed, so the difference is {{ $isRefund ? 'paid back' : 'collected' }} here.</td></tr>
                @endif
                {{-- When the month's contributions did not all land here, BOTH figures are printed:
                     the rows above sum to the first, and the walk continues from the second. One
                     row labelled "monthly" sitting under rows that do not add up to it would be
                     the one error a payslip cannot make. --}}
                @if ($month && $differs($month['government'], $t['government']))
                    <tr class="sum"><td>Total Benefits (This Cut-Off)</td><td class="amt">{{ $amt($t['government']) }}</td></tr>
                @endif
                <tr class="sum">
                    <td>{{ $month ? 'Total Monthly Benefits' : 'Total Benefits' }}</td>
                    <td class="amt">{{ $amt($month ? $month['government'] : $t['government']) }}</td>
                </tr>
                @if ($erTotal > 0)
                    <tr class="ernote">
                        <td colspan="2">Not deducted from your pay — the company&rsquo;s own cost.</td>
                    </tr>
                @endif
            </table>

            <table class="bar">
                <tr>
                    <td>Initial Net Salary</td>
                    <td class="v">{{ $amt($initialNet) }}</td>
                </tr>
            </table>

            {{-- Allowances sit BELOW the contributions rather than among the earnings: they are
                 paid on top of a salary that was already taxed, and printing them inside
                 Compensation made them look like part of the base the deductions came out of. --}}
            <table class="stack">
                <tr class="thead"><td>Non-Taxable Allowance</td><td class="amt">Amount</td></tr>
                @foreach ($allowanceLines as $line)
                    <tr class="line">
                        <td>
                            @if (! empty($line['original_name']))
                                <span class="was">{{ $fit($line['original_name']) }}</span>
                            @endif
                            {{ $fit($line['name']) }}
                        </td>
                        <td class="amt">{!! $was($line['original_amount'] ?? null) !!}{{ $amt($line['amount']) }}</td>
                    </tr>
                @endforeach
                @if ($t['allowances'] == 0 && ! $month)
                    <tr class="line"><td>None</td><td class="amt">{{ $amt(0) }}</td></tr>
                @endif
                @if ($month && $differs($month['allowances'], $t['allowances']))
                    <tr class="sum"><td>Total Allowance (This Cut-Off)</td><td class="amt">{{ $amt($t['allowances']) }}</td></tr>
                @endif
                <tr class="sum">
                    <td>{{ $month ? 'Total Monthly Allowance' : 'Total Allowance' }}</td>
                    <td class="amt">{{ $amt($month ? $month['allowances'] : $t['allowances']) }}</td>
                </tr>
            </table>
        </td>
    </tr>
</table>

{{--
    Disclosure, NOT a term in the total (FIN-053). The government and deduction figures above are
    what was actually collected, so this is no longer credited back — it is shown because the
    employee is entitled to know a deduction they owe went unpaid, and which one.
--}}
@if ($t['uncollected'] > 0)
    <div class="note">
        <table>
            <tr>
                <td>
                    <div style="font-weight: bold;">Not collected this period</div>
                    <div class="muted" style="font-size: 7px; padding-top: 1px;">
                        Pay did not cover the full deductions, so this amount was not taken.
                        The figures above show what was collected.
                        @if (! empty($t['uncollected_breakdown']))
                            @php
                                $labels = [
                                    'sss' => 'SSS',
                                    'philhealth' => 'PhilHealth',
                                    'pagibig' => 'Pag-IBIG',
                                    'withholding_tax' => 'Withholding Tax',
                                    'other_deductions' => 'Other Deductions',
                                ];
                            @endphp
                            <br>
                            @foreach ($t['uncollected_breakdown'] as $key => $shortfall)
                                {{ $labels[$key] ?? $key }} {{ $amt($shortfall) }}@if (! $loop->last) · @endif
                            @endforeach
                        @endif
                    </div>
                </td>
                <td class="amt" style="font-weight: bold;">{{ $amt($t['uncollected']) }}</td>
            </tr>
        </table>
    </div>
@endif

{{-- The conclusions run FULL WIDTH beneath both columns: the figure the reader is looking for is
     never in a column. The month total sits below this cutoff's net rather than instead of it —
     the employee is owed both answers, what lands in their account now and what the month came to. --}}
<table class="net">
    <tr>
        <td class="lbl">{{ $netLabel }}</td>
        <td class="v">PHP {{ $amt($p['net_pay']) }}</td>
    </tr>
</table>

@if ($month)
    <table class="net">
        <tr>
            <td class="lbl">Total Month Salary</td>
            <td class="v">PHP {{ $amt($month['total_month']) }}</td>
        </tr>
    </table>
@endif

{{-- WHAT WAS ACTUALLY TRANSFERRED, when the net above is no longer that figure.

     Silent on an ordinary payslip, where the net IS what was paid — which is nearly all of them.
     It appears when a correction landed after payment: the bar above then states an amount larger
     than the one in the employee's account, and the amount in their account is the one fact on this
     document they can check independently. Both figures, each with its date, and the difference
     named rather than left to be inferred. --}}
@if ($p['disbursed_amount'] !== null && $differs($p['disbursed_amount'], $p['net_pay']))
    @php $owed = (float) $p['amount_outstanding']; @endphp
    <div class="note">
        <strong>{{ $owed < 0 ? 'Paid more than this payslip now shows' : 'Still to be paid' }}
            {{ $amt(abs($owed)) }}</strong> — {{ $amt($p['disbursed_amount']) }} was paid out on
        {{ $fmt($p['disbursed_at']) }}, and this payslip was corrected afterwards, so the net above
        is not the amount that was transferred.
        {{ $owed < 0
            ? 'The difference was overpaid and is not recovered automatically.'
            : 'The difference is still owed.' }}
    </div>
@endif

<table class="foot">
    <tr>
        <td style="width: 34%;">
            <div class="cap">Issued Date</div>
            <div style="font-size: 8px; padding-top: 1px;">{{ $stamp($p['issued_at']) }}</div>
            <div class="cap" style="padding-top: 4px;">Verified By</div>
            <div style="font-size: 8px; padding-top: 1px;">System Automated Check</div>
            {{-- THAT THIS DOCUMENT WAS AMENDED, AND WHEN.

                 The corrections used to be listed in a block of their own above the signatures,
                 because that was the only place they existed: the figures were the engine's and
                 the block was where a person's disagreement with them lived. Each correction now
                 sits ON the line it is about, struck through beside the corrected figure, so the
                 block would be the same information said twice — and the second telling is the one
                 that made the reader do arithmetic.

                 The date does not survive on the line, though, and a payslip somebody has already
                 read once is a document they are entitled to know has changed since. --}}
            @if ($amendedCount > 0)
                <div class="cap" style="padding-top: 4px;">Amended</div>
                <div style="font-size: 8px; padding-top: 1px;">
                    {{ $amendedCount }} line{{ $amendedCount === 1 ? '' : 's' }} corrected{{ $amendedOn ? ' · ' . $fmt($amendedOn) : '' }}
                </div>
            @endif
        </td>
        <td class="sig" style="width: 33%;">
            <div style="height: 10px;"></div>
            <div class="rule"></div>
            <div class="who">{{ $p['processed_by'] ?? '' }}</div>
            <div class="cap">HR Manager</div>
        </td>
        <td class="sig">
            <div style="height: 10px;"></div>
            <div class="rule"></div>
            <div class="who">{{ $p['employee']['name'] ?? '' }}</div>
            <div class="cap">Employee</div>
        </td>
    </tr>
</table>

</body>
</html>
