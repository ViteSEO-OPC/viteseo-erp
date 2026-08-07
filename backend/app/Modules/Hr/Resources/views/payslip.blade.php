{{--
    The payslip PDF — the same document `payslip-document.tsx` renders on screen.

    Nested tables rather than flex because DomPDF has no flexbox. Every subtotal comes from
    `$p['totals']`, computed once in PayslipResource, so this and the UI cannot drift apart.

    DejaVu Sans / DejaVu Sans Mono ship with DomPDF, so no font is installed. Amounts are bare
    numbers with a single "PHP" on the net line — DomPDF's fonts have no ₱ glyph and would
    render it as a blank box.

    One document for both audiences: HR's download and the employee's are byte-identical. That
    now includes the employer's share, disclosed under the net-pay bar (reversed 2026-08-07) —
    it is never a term in any total.

    ONE PAGE, ALWAYS. See `$capped` and `$fit` below: the two variable-length lists are bounded
    and long names are trimmed, so the document's height cannot grow with data. Overflow does not
    truncate the top — it pushes the net pay and signatures onto page two.
--}}
@php
    $t = $p['totals'];
    $hrs = fn ($minutes) => number_format($minutes / 60, 1);
    $dayCount = fn ($n) => rtrim(rtrim(number_format((float) $n, 2), '0'), '.');
    $orDash = fn ($v) => ($v === null || $v === '') ? '—' : $v;

    /**
     * ONE PAGE, GUARANTEED — the two things that can make this document unbounded (2026-08-07).
     *
     * Every other block on the page has a fixed height. These two are lists whose length comes
     * from configuration: an allowance line per programme the employee is on, a deduction line per
     * benefit share and loan. Nothing caps them, so with enough of either the payslip runs onto a
     * second page — and it takes the net pay and the signature block with it, which is the half
     * that matters. Measured: it already did, at six allowances and six deductions.
     *
     * `$capped` keeps the first few and ROLLS THE REMAINDER INTO ONE LABELLED LINE. It is not a
     * truncation: the rolled line carries the summed amount and says how many items it covers, so
     * the column still adds up to the same total and no peso goes unaccounted for. The full
     * itemisation is always on screen, where there is no page boundary.
     *
     * The caps are set ABOVE what this company can currently produce (four allowance programmes,
     * so eleven earnings rows) — the roll-up is a backstop for a configuration that does not exist
     * yet, not something an ordinary payslip hits.
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
        ];

        return $keep;
    };

    /**
     * A name long enough to wrap adds a row's height and defeats the caps above. The screen has
     * room to show it in full; the printed column is ~45 characters wide, so this trims to fit.
     */
    $fit = fn (string $name) => mb_strlen($name) > 40 ? mb_substr($name, 0, 39) . '…' : $name;

    /**
     * The same guard for the employee card's free text. A long address is the realistic one — it
     * gets the full page width, so it wraps at around a hundred characters and costs a row. Name,
     * role and department share a half-width column. These are the last unbounded things on the
     * page; with them fixed the document's height cannot vary with data at all.
     */
    $fitWide = fn ($v, int $max) => ($v === null || $v === '')
        ? '—'
        : (mb_strlen((string) $v) > $max ? mb_substr((string) $v, 0, $max - 1) . '…' : (string) $v);

    $earnings = $capped($p['earnings_breakdown'], 11, 'Other earnings');
    $otherDeductions = $capped($p['other_deductions_breakdown'], 4, 'Other deductions');

    /**
     * The employer's counterpart contributions — DISCLOSED, NEVER A TERM IN ANY TOTAL.
     *
     * Only used to decide whether the sub-lines and their footnote appear at all: a run that takes
     * no statutory contributions (13th month, final pay, an adjustment) has nothing to show.
     * The figures themselves are read per line beside the employee's own.
     */
    $erTotal = (float) ($p['employer_sss'] ?? 0)
        + (float) ($p['employer_philhealth'] ?? 0)
        + (float) ($p['employer_pagibig'] ?? 0);
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <style>
        @page { margin: 36px 40px; }

        body { font-family: 'DejaVu Sans', sans-serif; color: #141414; font-size: 11px; margin: 0; }
        table { width: 100%; border-collapse: collapse; }
        td { vertical-align: top; }

        .mono { font-family: 'DejaVu Sans Mono', monospace; }
        .amt { text-align: right; white-space: nowrap; font-family: 'DejaVu Sans Mono', monospace; }
        .muted { color: #6b7280; }
        .cap { font-family: 'DejaVu Sans Mono', monospace; font-size: 8px;
               text-transform: uppercase; color: #6b7280; }

        /* Header */
        .company { text-align: center; font-size: 17px; font-weight: bold; }
        .address { text-align: center; font-size: 9.5px; color: #6b7280; padding-top: 2px; }

        .title { font-family: 'DejaVu Sans Mono', monospace; font-size: 22px; font-weight: bold; }
        .paydate { font-size: 12px; font-weight: bold; text-align: right; }
        .rule { border-bottom: 1px solid #e5e7eb; height: 1px; font-size: 0; margin: 8px 0; }

        .val { font-size: 10.5px; font-weight: bold; padding-top: 1px; }

        /* Employee card */
        .card { border: 1px solid #e5e7eb; border-radius: 6px; padding: 10px 14px; }
        .card td { padding-bottom: 6px; }
        .card .last td { padding-bottom: 0; }

        /* Money tables. Tighter than the on-screen document on purpose: print has a page
           boundary to respect.

           BALANCED AGAINST TWO DOCUMENTS, not one (2026-08-07). Tightened first, because the
           measured worst case — six allowances, all four premium lines, six benefit deductions,
           an uncollected note — ran onto a second page and took the net pay and signatures with
           it. Then loosened again, because that left an ORDINARY payslip ending barely two
           thirds down the page and reading as cramped.

           PER-ROW SPACING IS THE EXPENSIVE KIND: the tallest column runs to about twenty rows at
           the caps, so a point of row padding costs twenty. Fixed blocks — the card, the net bar,
           the footer, the gaps between sections — cost the same on every payslip and are where
           the air actually comes from. That is why the padding below is modest and the margins
           around it are not.

           Measured at these values: an ordinary payslip ends ~612pt and the bounded worst case
           ~794pt, against a usable ~815pt. Change any of it against BOTH documents. */
        .sect { margin-top: 10px; }
        /* The second table inside a column cell. Each column flows on its own, so this is the
           only thing separating one section from the next within a column. */
        .stack { margin-top: 12px; }
        /* Section titles carry the document's structure, so they are a shade larger and heavier
           than the rows beneath them — enough to scan by, not enough to compete with the net-pay
           bar. The bottom rule gives the band a floor so the heading reads as a header. */
        .thead td { background: #f4f5f7; border-bottom: 1px solid #e5e7eb;
                    font-family: 'DejaVu Sans Mono', monospace;
                    font-size: 9.5px; font-weight: bold; text-transform: uppercase;
                    letter-spacing: 0.3px; padding: 5px 8px; }
        .line td { border-bottom: 1px solid #e5e7eb; padding: 3px 8px; font-size: 10.5px; }
        .sum td { padding: 5.5px 8px; font-size: 10.5px; font-weight: bold; }

        .cols td { vertical-align: top; }
        .cols .gap { width: 18px; }

        .note { border: 1px solid #f0a608; background: #fdf1d6; border-radius: 6px;
                padding: 4px 9px; margin-top: 5px; font-size: 9.5px; }

        .net { background: #141414; color: #ffffff; border-radius: 6px; margin-top: 10px; }
        .net td { padding: 10px 14px; font-family: 'DejaVu Sans Mono', monospace; font-weight: bold; }
        .net .lbl { font-size: 13px; text-transform: uppercase; }
        .net .val { font-size: 17px; text-align: right; color: #ffffff; padding-top: 10px; }

        /* The employer's counterpart, tucked under each statutory line. Indented, two points
           smaller, italic and grey — four signals at once that it belongs to the row above and
           is not a deduction. It is never in `Total Gov`; `documentTotals()` cannot see it. */
        .line.pair td { border-bottom: none; padding-bottom: 0; }
        .ersub td { border-bottom: 1px solid #e5e7eb; padding: 0 8px 3.5px;
                    font-size: 8px; font-style: italic; color: #6b7280; }
        .ersub .erlbl { padding-left: 16px; }
        .ersub .eramt { font-style: italic; }

        /* Said once, under the total it is deliberately absent from. */
        .ernote td { padding: 3px 8px 0; font-size: 7.5px; font-style: italic; color: #6b7280; }

        /* Footer */
        .foot { margin-top: 14px; page-break-inside: avoid; }
        .foot td { vertical-align: bottom; }
        .sig { text-align: center; }
        .sig .rule { border-bottom: 1px solid #141414; margin: 0 0 3px; }
        .sig .who { font-size: 8px; text-transform: uppercase; }
    </style>
</head>
<body>

<div class="company">VITE SEO</div>
<div class="address">Makati City, Metro Manila, Philippines</div>

<table style="margin-top: 18px;">
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

<div class="card" style="margin-top: 14px;">
    <table>
        <tr>
            <td style="width: 50%;">
                <div class="cap">Employee Name</div>
                <div class="val">{{ $fitWide($p['employee']['name'], 44) }}</div>
            </td>
            <td>
                <div class="cap">Employee ID</div>
                <div class="val">{{ $orDash($p['employee']['employee_no']) }}</div>
            </td>
        </tr>
        <tr>
            <td>
                <div class="cap">Job Role</div>
                <div class="val">{{ $fitWide($p['employee']['job_title'], 44) }}</div>
            </td>
            <td>
                <div class="cap">Department</div>
                <div class="val">{{ $fitWide($p['employee']['department'], 44) }}</div>
            </td>
        </tr>
        <tr>
            <td colspan="2">
                <div class="cap">Address</div>
                <div class="val">{{ $fitWide($p['employee']['address'], 95) }}</div>
            </td>
        </tr>
        <tr>
            <td colspan="2" style="padding-bottom: 5px;">
                <div style="border-bottom: 1px solid #e5e7eb; height: 1px; font-size: 0;"></div>
            </td>
        </tr>
        <tr class="last">
            <td colspan="2">
                <table>
                    <tr>
                        <td style="width: 25%;">
                            <div class="cap">TIN</div>
                            <div class="val">{{ $orDash($p['employee']['tin']) }}</div>
                        </td>
                        <td style="width: 25%;">
                            <div class="cap">SSS #</div>
                            <div class="val">{{ $orDash($p['employee']['sss_number']) }}</div>
                        </td>
                        <td style="width: 25%;">
                            <div class="cap">PhilHealth</div>
                            <div class="val">{{ $orDash($p['employee']['philhealth_number']) }}</div>
                        </td>
                        <td>
                            <div class="cap">Pag-IBIG</div>
                            <div class="val">{{ $orDash($p['employee']['pagibig_number']) }}</div>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</div>

{{--
    TWO INDEPENDENT COLUMNS (2026-08-07, revised).

    WHY COLUMNS AT ALL. Stacked, these four blocks are the tallest thing on the page: a payslip
    with six allowances and all four premium lines runs to thirteen compensation rows, and the
    basis table adds nine more. Measured before changing anything, that payslip rendered on TWO
    pages — the second carrying the net pay and the signature block, which is the half that matters.

    WHY ONE TABLE AND NOT TWO. The first version was two `cols` tables stacked: Compensation |
    Basis, then Deductions | Gov. A table row is as tall as its tallest cell, so Gov could not
    start until Deductions did — and Deductions could not start until Compensation finished. A
    short Computation Basis therefore left a band of dead space above Gov Contributions, growing
    with every allowance line on the left.

    One row, one cell per column, each holding its two tables: the columns now flow independently
    and Gov starts as soon as Basis ends. It is also never taller — max(a,b) + max(c,d) is always
    greater than or equal to max(a+c, b+d) — so the one-page guarantee can only improve.

    FIN-011 — one loop over `earnings_breakdown`, which the resource builds, instead of a hardcoded
    ordinary-period list. The old list rendered "Basic Pay ₱0" for a 13th-month, adjustment or
    final-pay slip and showed no line for what those runs actually paid.
--}}
<table class="sect cols">
    <tr>
        {{-- LEFT COLUMN: what was earned, then what was taken off it. --}}
        <td style="width: 49%;">
            <table>
                <tr class="thead"><td>Compensation</td><td class="amt">Amount</td></tr>
                @foreach ($earnings as $line)
                    <tr class="line">
                        <td>
                            {{ $fit($line['name']) }}
                            @if (! empty($line['note']))
                                <span class="muted">({{ $line['note'] }})</span>
                            @endif
                        </td>
                        <td class="amt">{{ $amt($line['amount']) }}</td>
                    </tr>
                @endforeach
                <tr class="sum"><td>Total Compensation</td><td class="amt">{{ $amt($t['compensation']) }}</td></tr>
            </table>

            <table class="stack">
                <tr class="thead"><td>Deductions</td><td class="amt">Amount</td></tr>
                @if ($t['absences'] > 0)
                    <tr class="line"><td>Absences</td><td class="amt">{{ $amt($t['absences']) }}</td></tr>
                @endif
                @if ($t['late_undertime'] > 0)
                    <tr class="line"><td>Late / Undertime</td><td class="amt">{{ $amt($t['late_undertime']) }}</td></tr>
                @endif
                @foreach ($otherDeductions as $line)
                    <tr class="line"><td>{{ $fit($line['name']) }}</td><td class="amt">{{ $amt($line['amount']) }}</td></tr>
                @endforeach
                @if ($t['deductions'] == 0)
                    <tr class="line"><td>None</td><td class="amt">{{ $amt(0) }}</td></tr>
                @endif
                <tr class="sum"><td>Total Deductions</td><td class="amt">{{ $amt($t['deductions']) }}</td></tr>
            </table>
        </td>

        <td class="gap"></td>

        {{-- RIGHT COLUMN: how the left was arrived at, then what went to the government.
             Both live in ONE cell so Gov starts where Basis ends — see the note above. --}}
        <td>
            {{-- Counts and rates, not money — how the figures beside this were arrived at. --}}
            @if ($p['working_days'] > 0)
                <table>
                    <tr class="thead"><td>Computation Basis</td><td class="amt">Amount</td></tr>
                    <tr class="line"><td>Working days in period</td><td class="amt">{{ $p['working_days'] }}</td></tr>
                    <tr class="line"><td>Daily rate</td><td class="amt">{{ $amt($p['daily_rate']) }}</td></tr>
                    @if ($p['unpaid_days'] > 0)
                        <tr class="line"><td>Unpaid days</td><td class="amt">{{ $dayCount($p['unpaid_days']) }}</td></tr>
                    @endif
                    @if ($p['late_minutes'] > 0)
                        <tr class="line"><td>Late</td><td class="amt">{{ $p['late_minutes'] }} min</td></tr>
                    @endif
                    @if ($p['undertime_minutes'] > 0)
                        <tr class="line"><td>Undertime</td><td class="amt">{{ $p['undertime_minutes'] }} min</td></tr>
                    @endif
                    @if ($p['late_penalty_days'] > 0)
                        <tr class="line"><td>Tardiness penalty</td><td class="amt">{{ $dayCount($p['late_penalty_days']) }} day</td></tr>
                    @endif
                    @if ($p['overtime_minutes'] > 0)
                        <tr class="line"><td>Overtime rendered</td><td class="amt">{{ $hrs($p['overtime_minutes']) }} hrs</td></tr>
                    @endif
                    @if ($p['night_diff_minutes'] > 0)
                        <tr class="line"><td>Night hours</td><td class="amt">{{ $hrs($p['night_diff_minutes']) }} hrs</td></tr>
                    @endif
                    @if ($p['rest_day_minutes'] > 0)
                        <tr class="line"><td>Rest day worked</td><td class="amt">{{ $hrs($p['rest_day_minutes']) }} hrs</td></tr>
                    @endif
                </table>
            @endif

            {{-- `stack` only when something precedes it: on a run with no working days the basis
                 table is absent entirely and Gov must sit flush at the top of the column. --}}
            <table class="{{ $p['working_days'] > 0 ? 'stack' : '' }}">
                <tr class="thead"><td>Gov Contributions &amp; Tax</td><td class="amt">Amount</td></tr>
                {{--
                    Each statutory line carries the EMPLOYER's counterpart directly beneath it
                    (2026-08-07) — indented, smaller, italic and grey, so it reads as a note on
                    the line above rather than as another deduction.

                    WHY HERE AND NOT AS ITS OWN BLOCK. It was a standalone strip under the net-pay
                    bar first. Beside the employee's own figure it actually means something: the
                    employee can see that P875 of their pay went to SSS and the company put in
                    P1,765 alongside it. Detached from that comparison it was just another number.

                    IT IS NEVER IN `Total Gov`. That row is `$t['government']`, computed by
                    `documentTotals()` from the employee's deductions alone — there is no code path
                    by which these rows reach it. The styling says so and the arithmetic enforces it.
                --}}
                @foreach ([
                    ['SSS Contribution', $p['sss'], $p['employer_sss'] ?? 0],
                    ['PhilHealth', $p['philhealth'], $p['employer_philhealth'] ?? 0],
                    ['Pag-IBIG', $p['pagibig'], $p['employer_pagibig'] ?? 0],
                ] as [$label, $employeeShare, $employerShare])
                    {{-- The pair reads as one unit: the employee row drops its own rule when a
                         sub-line follows, and the sub-line carries it instead. --}}
                    <tr class="line{{ $employerShare > 0 ? ' pair' : '' }}">
                        <td>{{ $label }}</td>
                        <td class="amt">{{ $amt($employeeShare) }}</td>
                    </tr>
                    @if ($employerShare > 0)
                        <tr class="ersub">
                            <td class="erlbl">+ employer share</td>
                            <td class="amt eramt">{{ $amt($employerShare) }}</td>
                        </tr>
                    @endif
                @endforeach
                <tr class="line"><td>Withholding Tax</td><td class="amt">{{ $amt($p['withholding_tax']) }}</td></tr>
                <tr class="sum"><td>Total Gov</td><td class="amt">{{ $amt($t['government']) }}</td></tr>
                @if ($erTotal > 0)
                    <tr class="ernote">
                        <td colspan="2">Not deducted from your pay — the company&rsquo;s own cost.</td>
                    </tr>
                @endif
            </table>
        </td>
    </tr>
</table>

{{--
    Disclosure, NOT a term in the total (FIN-053). The government and deduction figures above are
    now what was actually collected, so this is no longer credited back — it is shown because the
    employee is entitled to know a deduction they owe went unpaid, and which one.
--}}
@if ($t['uncollected'] > 0)
    <div class="note">
        <table>
            <tr>
                <td>
                    <div style="font-weight: bold;">Not collected this period</div>
                    <div class="muted" style="font-size: 9px; padding-top: 1px;">
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
                            @foreach ($t['uncollected_breakdown'] as $key => $amount)
                                {{ $labels[$key] ?? $key }} {{ $amt($amount) }}@if (! $loop->last) · @endif
                            @endforeach
                        @endif
                    </div>
                </td>
                <td class="amt" style="font-weight: bold;">{{ $amt($t['uncollected']) }}</td>
            </tr>
        </table>
    </div>
@endif

<table class="net">
    <tr>
        <td class="lbl">Total Net Pay</td>
        <td class="val">PHP {{ $amt($p['net_pay']) }}</td>
    </tr>
</table>

<table class="foot">
    <tr>
        <td style="width: 34%;">
            <div class="cap">Issued Date</div>
            <div style="font-size: 9.5px; padding-top: 1px;">{{ $stamp($p['issued_at']) }}</div>
            <div class="cap" style="padding-top: 6px;">Verified By</div>
            <div style="font-size: 9.5px; padding-top: 1px;">System Automated Check</div>
        </td>
        <td class="sig" style="width: 33%;">
            <div style="height: 12px;"></div>
            <div class="rule"></div>
            <div class="who">{{ $p['processed_by'] ?? '' }}</div>
            <div class="cap">Human Resource</div>
        </td>
        <td class="sig">
            <div style="height: 12px;"></div>
            <div class="rule"></div>
            <div class="who">{{ $p['employee']['name'] ?? '' }}</div>
            <div class="cap">Employee</div>
        </td>
    </tr>
</table>

</body>
</html>
