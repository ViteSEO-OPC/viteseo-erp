{{-- The payslip PDF — the same document `payslip-document.tsx` renders on screen. --}}
@php
    $t = $p['totals'];
    $month = $p['month_summary'] ?? null;
    $cutoff = $p['cutoff'] ?? null;
    // An intern's slip: an allowance, not a salary, with no government contributions or tax to show.
    $intern = ! empty($p['is_intern']);

    $orDash = fn ($v) => ($v === null || $v === '') ? '—' : $v;

    /** A header rate, or an em dash when the employee is not paid on one (management staff). */
    $rate = fn ($n) => (float) $n > 0 ? $amt($n) : '—';

    /** Two peso figures that differ by more than rounding. */
    $differs = fn ($a, $b) => abs((float) $a - (float) $b) > 0.005;

    /*
     * The rows an ordinary period shows even at zero arrive as REAL LINES from
     * PayslipDocument::earningLines(), in the order it shows them — the screen reads the same
     * list. This used to rebuild that skeleton here by NAME, which both duplicated the rule and
     * printed a renamed line twice: once as an empty placeholder under its old name and again
     * under the one HR gave it.
     */
    $earningLines = array_values(array_filter(
        $p['earnings_breakdown'],
        fn ($l) => ($l['kind'] ?? 'earning') !== 'allowance',
    ));

    $allowanceLines = array_values(array_filter(
        $p['earnings_breakdown'],
        fn ($l) => ($l['kind'] ?? null) === 'allowance',
    ));

    /** THE CORRECTIONS HR HAS MADE TO LINES THAT WERE ALREADY HERE, keyed by the line they name. */
    $corrections = [];

    foreach ($p['amendments'] ?? [] as $amendment) {
        if (($amendment['target_key'] ?? null) !== null) {
            $corrections[$amendment['target_key']] = $amendment;
        }
    }

    /*
     * WHAT WAS AMENDED, AND WHEN. Counted over EVERY amendment, not only the ones that changed a
     * line already here: a payslip that had a line added to it has been amended just as much, and
     * used to carry no stamp at all.
     */
    $correctedCount = count($corrections);
    $addedCount = count($p['amendments'] ?? []) - $correctedCount;
    $amendedCount = $correctedCount + $addedCount;
    $amendedOn = null;

    foreach ($p['amendments'] ?? [] as $amendment) {
        if (($amendment['issued_on'] ?? null) !== null && $amendment['issued_on'] > (string) $amendedOn) {
            $amendedOn = $amendment['issued_on'];
        }
    }

    /** Said as what actually happened — three corrected and one added is not "4 corrected". */
    $amendedWhat = implode(' · ', array_filter([
        $correctedCount > 0 ? $correctedCount . ' line' . ($correctedCount === 1 ? '' : 's') . ' corrected' : null,
        $addedCount > 0 ? $addedCount . ' line' . ($addedCount === 1 ? '' : 's') . ' added' : null,
    ]));

    /** The struck-through original, or nothing at all on a line nobody has corrected. */
    $was = fn ($original) => $original === null ? '' : '<span class="was">' . $amt($original) . '</span>';

    /** ONE PAGE, GUARANTEED — the lists whose length comes from configuration rather than layout. */
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

    /** The employer's counterpart contributions — DISCLOSED, NEVER A TERM IN ANY TOTAL. */
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
        /* The mark, when one is set. Height-capped so a large upload can't push the
           document onto a second page — dompdf scales the width to match. */
        .logo { text-align: center; padding-bottom: 3px; }
        .logo img { height: 34px; }
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

        /* Footer */
        .foot { margin-top: 9px; page-break-inside: avoid; }
        .foot td { vertical-align: bottom; }
        .sig { text-align: center; }
        .sig .rule { border-bottom: 1px solid #141414; margin: 0 0 2px; }
        .sig .who { font-size: 7px; text-transform: uppercase; }
    </style>
</head>
<body>

@if (!empty($company['logo']))
    <div class="logo"><img src="{{ $company['logo'] }}" alt=""></div>
@endif
<div class="company">{{ $company['name'] }}</div>
@if (!empty($company['address']))
    <div class="address">{{ $company['address'] }}</div>
@endif

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
            {{--
                The resolved label, not the raw `frequency`: for a final-pay run that
                string is "final_pay:<employee id>".
            --}}
            <div class="val">{{ $p['run_type_label'] }}</div>
        </td>
        <td>
            <div class="cap">Pay Period</div>
            <div class="val">{{ $fmt($p['cycle_start']) }} – {{ $fmt($p['cycle_end']) }}</div>
        </td>
    </tr>
</table>

{{--
    Name, role and the three rates the deduction arithmetic is quoted in. The government numbers,
    employee id, department and address were removed on 2026-08-14 (Joshua's call).
--}}
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
                        {{--
                            Em dashes, not zeroes: management staff are not paid by the day, and
                            "Per Day 0.00" states that a day of their time is worth nothing.
                        --}}
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

{{-- TWO INDEPENDENT COLUMNS. --}}
<table class="sect cols">
    <tr>
        {{-- LEFT: the period on its own — earned, charged, and the subtotal those two produce. --}}
        <td style="width: 49%;">
            <table>
                <tr class="thead"><td>{{ $intern ? 'Allowance' : 'Compensation' }}</td><td class="amt">Amount</td></tr>
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
                <tr class="sum"><td>{{ $intern ? 'Total Allowance' : 'Total Compensation' }}</td><td class="amt">{{ $amt($t['earnings']) }}</td></tr>
            </table>

            <table class="stack">
                <tr class="thead"><td>Deductions</td><td class="amt">Amount</td></tr>
                @foreach (array_merge($attendanceRows, $otherRows) as $row)
                    {{-- The count badge carries the quantity: "Absences (2)". The days behind it
                         are no longer printed. --}}
                    <tr class="line">
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

        {{--
            RIGHT: what happens to that subtotal — the month restated, the government's share,
            and the allowances paid on top of a salary that was already taxed.
        --}}
        <td>
            {{--
                THE MONTH, RESTATED — carried by its last cutoff only. Each earlier cutoff is
                restated by its Total Current Amount rather than its net pay: the two are equal
                only on a cutoff that took no contributions, which is exactly what this must not
                silently depend on.
            --}}
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

            {{-- An intern's allowance carries no contribution and no tax, so the table and the net
                 figure it produces are left off rather than printed as a column of zeroes. --}}
            @unless ($intern)
            <table class="{{ $month ? 'stack' : '' }}">
                <tr class="thead"><td>Government Benefits &amp; Tax</td><td class="amt">Amount</td></tr>
                {{--
                    Each statutory line carries the EMPLOYER's counterpart directly beneath it —
                    indented, smaller, italic and grey, so it reads as a note on the line above
                    rather than as another deduction.
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
                @php $taxFix = $corrections['withholding_tax'] ?? null; @endphp
                <tr class="line">
                    <td>
                        @if (! empty($taxFix['label_override']))
                            <span class="was">Withholding Tax</span> {{ $fit($taxFix['label_override']) }}
                        @else
                            Withholding Tax
                        @endif
                    </td>
                    <td class="amt">{!! $was($taxFix['original_amount'] ?? null) !!}{{ $amt($p['withholding_tax']) }}</td>
                </tr>
                {{--
                    The year-end settlement, shown as its derivation. On the common case
                    the line above is NEGATIVE and net pay exceeds gross; the printed payslip is
                    the copy an employee keeps and queries against. Absent on every other payslip.
                --}}
                @if (($p['tax_annualisation_basis'] ?? null) !== null)
                    @php $tb = $p['tax_annualisation_basis']; $isRefund = $tb['settled_this_period'] < 0; @endphp
                    <tr class="ernote"><td colspan="2"><strong>Year-end tax settlement · {{ $tb['year'] }}</strong></td></tr>
                    <tr class="ersub"><td class="erlbl">Taxable pay for the year</td><td class="amt">{{ $amt($tb['year_taxable']) }}</td></tr>
                    <tr class="ersub"><td class="erlbl">Tax due on that</td><td class="amt">{{ $amt($tb['annual_tax_due']) }}</td></tr>
                    <tr class="ersub"><td class="erlbl">Already withheld this year</td><td class="amt">{{ $amt($tb['previously_withheld']) }}</td></tr>
                    <tr class="ersub"><td class="erlbl">{{ $isRefund ? 'Refunded on this payslip' : 'Collected on this payslip' }}</td><td class="amt">{{ $amt(abs($tb['settled_this_period'])) }}</td></tr>
                    <tr class="ernote"><td colspan="2">Tax is withheld each period on an estimate of the year. Yours came out {{ $isRefund ? 'higher' : 'lower' }} than the year needed, so the difference is {{ $isRefund ? 'paid back' : 'collected' }} here.</td></tr>
                @endif
                {{--
                    When the month's contributions did not all land here, BOTH figures are printed:
                    the rows above sum to the first, and the walk continues from the second. One
                    row labelled "monthly" sitting under rows that do not add up to it would be
                    the one error a payslip cannot make.
                --}}
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
            @endunless

            {{--
                Allowances sit BELOW the contributions rather than among the earnings: they are
                paid on top of a salary that was already taxed, and printing them inside
                Compensation made them look like part of the base the deductions came out of.
            --}}
            <table class="stack">
                <tr class="thead"><td>{{ $intern ? 'Company Allowances' : 'Non-Taxable Allowance' }}</td><td class="amt">Amount</td></tr>
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
    Disclosure, NOT a term in the total. The government and deduction figures above are
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

{{--
    The conclusions run FULL WIDTH beneath both columns: the figure the reader is looking for is
    never in a column. The month total sits below this cutoff's net rather than instead of it —
    the employee is owed both answers, what lands in their account now and what the month came to.
--}}
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

{{-- WHAT WAS ACTUALLY TRANSFERRED, when the net above is no longer that figure. --}}
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
            {{-- THAT THIS DOCUMENT WAS AMENDED, AND WHEN. --}}
            @if ($amendedCount > 0)
                <div class="cap" style="padding-top: 4px;">Amended</div>
                <div style="font-size: 8px; padding-top: 1px;">
                    {{ $amendedWhat }}{{ $amendedOn ? ' · ' . $fmt($amendedOn) : '' }}
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
