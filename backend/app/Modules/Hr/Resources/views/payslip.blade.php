{{--
    The payslip PDF — the same document `payslip-document.tsx` renders on screen.

    Nested tables rather than flex because DomPDF has no flexbox. Every subtotal comes from
    `$p['totals']`, computed once in PayslipResource, so this and the UI cannot drift apart.

    DejaVu Sans / DejaVu Sans Mono ship with DomPDF, so no font is installed. Amounts are bare
    numbers with a single "PHP" on the net line — DomPDF's fonts have no ₱ glyph and would
    render it as a blank box.

    One document for both audiences: HR's download and the employee's are byte-identical. The
    employer share is screen-only (see the note above the footer).
--}}
@php
    $t = $p['totals'];
    $hrs = fn ($minutes) => number_format($minutes / 60, 1);
    $dayCount = fn ($n) => rtrim(rtrim(number_format((float) $n, 2), '0'), '.');
    $orDash = fn ($v) => ($v === null || $v === '') ? '—' : $v;
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
        .company { text-align: center; font-size: 19px; font-weight: bold; }
        .address { text-align: center; font-size: 10px; color: #6b7280; padding-top: 4px; }

        .title { font-family: 'DejaVu Sans Mono', monospace; font-size: 26px; font-weight: bold; }
        .paydate { font-size: 13px; font-weight: bold; text-align: right; }
        .rule { border-bottom: 1px solid #e5e7eb; height: 1px; font-size: 0; margin: 12px 0; }

        .val { font-size: 11px; font-weight: bold; padding-top: 2px; }

        /* Employee card */
        .card { border: 1px solid #e5e7eb; border-radius: 6px; padding: 12px 16px; }
        .card td { padding-bottom: 8px; }
        .card .last td { padding-bottom: 0; }

        /* Money tables. Tighter than the on-screen document on purpose: print has a page
           boundary to respect, and a payslip that spills a second page for two rows is worse
           than one that breathes a little less. */
        .sect { margin-top: 12px; }
        .thead td { background: #f9fafb; font-family: 'DejaVu Sans Mono', monospace;
                    font-size: 9px; font-weight: bold; text-transform: uppercase;
                    padding: 5px 10px; }
        .line td { border-bottom: 1px solid #e5e7eb; padding: 5px 10px; font-size: 11px; }
        .sum td { padding: 6px 10px; font-size: 11px; font-weight: bold; }

        .cols td { vertical-align: top; }
        .cols .gap { width: 18px; }

        .note { border: 1px solid #f0a608; background: #fdf1d6; border-radius: 6px;
                padding: 8px 12px; margin-top: 12px; }

        .net { background: #141414; color: #ffffff; border-radius: 6px; margin-top: 12px; }
        .net td { padding: 11px 16px; font-family: 'DejaVu Sans Mono', monospace; font-weight: bold; }
        .net .lbl { font-size: 14px; text-transform: uppercase; }
        .net .val { font-size: 18px; text-align: right; color: #ffffff; padding-top: 11px; }

        /* Footer */
        .foot { margin-top: 22px; page-break-inside: avoid; }
        .foot td { vertical-align: bottom; }
        .sig { text-align: center; }
        .sig .rule { border-bottom: 1px solid #141414; margin: 0 0 3px; }
        .sig .who { font-size: 8px; text-transform: uppercase; }
    </style>
</head>
<body>

<div class="company">VITE SEO</div>
<div class="address">Makati City, Metro Manila, Philippines</div>

<table style="margin-top: 22px;">
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
            <div class="val">{{ ucwords(str_replace('_', '-', $p['frequency'] ?? '—')) }}</div>
        </td>
        <td>
            <div class="cap">Pay Period</div>
            <div class="val">{{ $fmt($p['cycle_start']) }} – {{ $fmt($p['cycle_end']) }}</div>
        </td>
    </tr>
</table>

<div class="card" style="margin-top: 16px;">
    <table>
        <tr>
            <td style="width: 50%;">
                <div class="cap">Employee Name</div>
                <div class="val">{{ $orDash($p['employee']['name']) }}</div>
            </td>
            <td>
                <div class="cap">Employee ID</div>
                <div class="val">{{ $orDash($p['employee']['employee_no']) }}</div>
            </td>
        </tr>
        <tr>
            <td>
                <div class="cap">Job Role</div>
                <div class="val">{{ $orDash($p['employee']['job_title']) }}</div>
            </td>
            <td>
                <div class="cap">Department</div>
                <div class="val">{{ $orDash($p['employee']['department']) }}</div>
            </td>
        </tr>
        <tr>
            <td colspan="2">
                <div class="cap">Address</div>
                <div class="val">{{ $orDash($p['employee']['address']) }}</div>
            </td>
        </tr>
        <tr>
            <td colspan="2" style="padding-bottom: 12px;">
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

{{-- Compensation: the FULL period basic, with the absence charged under Deductions. --}}
<table class="sect">
    <tr class="thead"><td>Compensation</td><td class="amt">Amount</td></tr>
    <tr class="line"><td>Basic Pay</td><td class="amt">{{ $amt($p['period_base']) }}</td></tr>
    @foreach ($p['allowances_breakdown'] as $line)
        <tr class="line"><td>{{ $line['name'] }}</td><td class="amt">{{ $amt($line['amount']) }}</td></tr>
    @endforeach
    @if ($p['overtime_pay'] > 0)
        <tr class="line">
            <td>Overtime <span class="muted">({{ $hrs($p['overtime_minutes']) }} hrs)</span></td>
            <td class="amt">{{ $amt($p['overtime_pay']) }}</td>
        </tr>
    @endif
    @if ($p['night_diff_pay'] > 0)
        <tr class="line">
            <td>Night Differential <span class="muted">({{ $hrs($p['night_diff_minutes']) }} hrs)</span></td>
            <td class="amt">{{ $amt($p['night_diff_pay']) }}</td>
        </tr>
    @endif
    @if ($p['holiday_premium'] > 0)
        <tr class="line"><td>Holiday</td><td class="amt">{{ $amt($p['holiday_premium']) }}</td></tr>
    @endif
    @if ($p['rest_day_premium'] > 0)
        <tr class="line">
            <td>Rest Day <span class="muted">({{ $hrs($p['rest_day_minutes']) }} hrs)</span></td>
            <td class="amt">{{ $amt($p['rest_day_premium']) }}</td>
        </tr>
    @endif
    @if ($p['reimbursements'] > 0)
        <tr class="line"><td>Reimbursement</td><td class="amt">{{ $amt($p['reimbursements']) }}</td></tr>
    @endif
    <tr class="sum"><td>Total Compensation</td><td class="amt">{{ $amt($t['compensation']) }}</td></tr>
</table>

{{-- Counts and rates, not money — how the figures above were arrived at. --}}
@if ($p['working_days'] > 0)
    <table class="sect">
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

<table class="sect cols">
    <tr>
        <td style="width: 49%;">
            <table>
                <tr class="thead"><td>Deductions</td><td class="amt">Amount</td></tr>
                @if ($t['absences'] > 0)
                    <tr class="line"><td>Absences</td><td class="amt">{{ $amt($t['absences']) }}</td></tr>
                @endif
                @if ($t['late_undertime'] > 0)
                    <tr class="line"><td>Late / Undertime</td><td class="amt">{{ $amt($t['late_undertime']) }}</td></tr>
                @endif
                @foreach ($p['other_deductions_breakdown'] as $line)
                    <tr class="line"><td>{{ $line['name'] }}</td><td class="amt">{{ $amt($line['amount']) }}</td></tr>
                @endforeach
                @if ($t['deductions'] == 0)
                    <tr class="line"><td>None</td><td class="amt">{{ $amt(0) }}</td></tr>
                @endif
                <tr class="sum"><td>Total Deductions</td><td class="amt">{{ $amt($t['deductions']) }}</td></tr>
            </table>
        </td>
        <td class="gap"></td>
        <td>
            <table>
                <tr class="thead"><td>Gov Contributions &amp; Tax</td><td class="amt">Amount</td></tr>
                <tr class="line"><td>SSS Contribution</td><td class="amt">{{ $amt($p['sss']) }}</td></tr>
                <tr class="line"><td>PhilHealth</td><td class="amt">{{ $amt($p['philhealth']) }}</td></tr>
                <tr class="line"><td>Pag-IBIG</td><td class="amt">{{ $amt($p['pagibig']) }}</td></tr>
                <tr class="line"><td>Withholding Tax</td><td class="amt">{{ $amt($p['withholding_tax']) }}</td></tr>
                <tr class="sum"><td>Total Gov</td><td class="amt">{{ $amt($t['government']) }}</td></tr>
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
                    <div class="muted" style="font-size: 10px; padding-top: 2px;">
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

{{-- NO EMPLOYER SHARE HERE, deliberately. The counterpart contributions are remittance data:
     they are not deducted from the employee and are not part of what this document certifies.
     A downloaded payslip gets forwarded — to a bank, a landlord, an embassy — so the file has
     to say the same thing whoever exported it. HR reads the employer share on screen instead
     (see `payslip-document.tsx`, variant="hr"). --}}

<table class="foot">
    <tr>
        <td style="width: 34%;">
            <div class="cap">Issued Date</div>
            <div style="font-size: 10px; padding-top: 2px;">{{ $stamp($p['issued_at']) }}</div>
            <div class="cap" style="padding-top: 10px;">Verified By</div>
            <div style="font-size: 10px; padding-top: 2px;">System Automated Check</div>
        </td>
        <td class="sig" style="width: 33%;">
            <div style="height: 30px;"></div>
            <div class="rule"></div>
            <div class="who">{{ $p['processed_by'] ?? '' }}</div>
            <div class="cap">Human Resource</div>
        </td>
        <td class="sig">
            <div style="height: 30px;"></div>
            <div class="rule"></div>
            <div class="who">{{ $p['employee']['name'] ?? '' }}</div>
            <div class="cap">Employee</div>
        </td>
    </tr>
</table>

</body>
</html>
