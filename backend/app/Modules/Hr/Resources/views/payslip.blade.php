<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <style>
        * { font-family: DejaVu Sans, sans-serif; }
        body { color: #1e293b; font-size: 12px; margin: 0; }
        .wrap { padding: 32px 36px; }
        .head { border-bottom: 2px solid #0d9488; padding-bottom: 12px; margin-bottom: 18px; }
        .company { font-size: 18px; font-weight: bold; color: #0f172a; }
        .doc { font-size: 13px; color: #0d9488; font-weight: bold; margin-top: 2px; }
        .meta { margin-top: 6px; color: #64748b; font-size: 11px; }
        .meta strong { color: #1e293b; }
        h2 { font-size: 11px; text-transform: uppercase; letter-spacing: .06em; color: #64748b;
             border-bottom: 1px solid #e2e8f0; padding-bottom: 4px; margin: 18px 0 8px; }
        table { width: 100%; border-collapse: collapse; }
        td { padding: 4px 0; }
        td.amt { text-align: right; }
        .neg { color: #dc2626; }
        .net { margin-top: 16px; border-top: 2px solid #0d9488; padding-top: 10px; }
        .net td { font-size: 16px; font-weight: bold; }
        .net td.amt { color: #0d9488; }
        .ytd { margin-top: 18px; border: 1px solid #e2e8f0; border-radius: 6px; padding: 10px 12px; }
        .ytd td { width: 33%; }
        .ytd .lbl { color: #64748b; font-size: 10px; }
        .ytd .val { font-weight: bold; font-size: 12px; }
        .thirteenth { margin-top: 10px; background: #f0fdfa; border: 1px solid #99f6e4;
                      border-radius: 6px; padding: 8px 12px; }
        .sign { margin-top: 48px; }
        .sign td { width: 45%; padding-top: 26px; }
        .sign .line { border-top: 1px solid #1e293b; padding-top: 3px; font-weight: bold; }
        .sign .cap { color: #64748b; font-size: 10px; }
        .foot { margin-top: 28px; color: #94a3b8; font-size: 10px; text-align: center; }
    </style>
</head>
<body>
<div class="wrap">
    <div class="head">
        <div class="company">Viteseo Inc.</div>
        <div class="doc">Payslip</div>
        <div class="meta">
            <strong>{{ $p['employee']['name'] ?? '—' }}</strong> ·
            Pay period {{ $fmt($p['cycle_start']) }} – {{ $fmt($p['cycle_end']) }}
        </div>
    </div>

    <h2>Earnings</h2>
    <table>
        <tr><td>Base salary</td><td class="amt">{{ $peso($p['base_salary']) }}</td></tr>
        @if ($p['allowances'] > 0)
            <tr><td>Allowances</td><td class="amt">{{ $peso($p['allowances']) }}</td></tr>
        @endif
        @if (($p['holiday_premium'] ?? 0) > 0)
            <tr><td>Holiday premium</td><td class="amt">{{ $peso($p['holiday_premium']) }}</td></tr>
        @endif
        @if (($p['overtime_pay'] ?? 0) > 0)
            <tr><td>Overtime ({{ number_format(($p['overtime_minutes'] ?? 0) / 60, 1) }} hrs)</td><td class="amt">{{ $peso($p['overtime_pay']) }}</td></tr>
        @endif
        @if (($p['night_diff_pay'] ?? 0) > 0)
            <tr><td>Night differential ({{ number_format(($p['night_diff_minutes'] ?? 0) / 60, 1) }} hrs)</td><td class="amt">{{ $peso($p['night_diff_pay']) }}</td></tr>
        @endif
        @if ($p['reimbursements'] > 0)
            <tr><td>Reimbursement</td><td class="amt">{{ $peso($p['reimbursements']) }}</td></tr>
        @endif
        <tr><td><strong>Gross</strong></td><td class="amt"><strong>{{ $peso($p['gross']) }}</strong></td></tr>
    </table>

    @if (($p['working_days'] ?? 0) > 0)
    <h2>Attendance basis</h2>
    <table>
        <tr><td>Working days this period</td><td class="amt">{{ $p['working_days'] }}</td></tr>
        <tr><td>Daily rate</td><td class="amt">{{ $peso($p['daily_rate']) }}</td></tr>
        @if (($p['unpaid_days'] ?? 0) > 0)
            <tr><td>Unpaid days</td><td class="amt">{{ number_format((float) $p['unpaid_days'], 2) }}</td></tr>
        @endif
        @if (($p['late_minutes'] ?? 0) > 0)
            <tr><td>Late ({{ $p['late_minutes'] }} min)</td><td class="amt neg">− {{ $peso($p['late_deduction']) }}</td></tr>
        @endif
        @if (($p['undertime_minutes'] ?? 0) > 0)
            <tr><td>Undertime ({{ $p['undertime_minutes'] }} min)</td><td class="amt neg">− {{ $peso($p['undertime_deduction']) }}</td></tr>
        @endif
    </table>
    @endif

    <h2>Deductions</h2>
    <table>
        <tr><td>SSS</td><td class="amt neg">− {{ $peso($p['sss']) }}</td></tr>
        <tr><td>PhilHealth</td><td class="amt neg">− {{ $peso($p['philhealth']) }}</td></tr>
        <tr><td>Pag-IBIG</td><td class="amt neg">− {{ $peso($p['pagibig']) }}</td></tr>
        <tr><td>Withholding tax</td><td class="amt neg">− {{ $peso($p['withholding_tax']) }}</td></tr>
        <tr><td><strong>Total deductions</strong></td>
            <td class="amt neg"><strong>− {{ $peso($p['total_deductions']) }}</strong></td></tr>
    </table>

    <table class="net">
        <tr><td>Net pay</td><td class="amt">{{ $peso($p['net_pay']) }}</td></tr>
    </table>

    @isset($ytd)
        <table class="ytd">
            <tr>
                <td><div class="lbl">YTD Gross</div><div class="val">{{ $peso($ytd['gross']) }}</div></td>
                <td><div class="lbl">YTD Deductions</div><div class="val">{{ $peso($ytd['deductions']) }}</div></td>
                <td><div class="lbl">YTD Net</div><div class="val">{{ $peso($ytd['net']) }}</div></td>
            </tr>
        </table>
        <table class="thirteenth">
            <tr>
                <td>13th-Month Pay (accrued) — basic earned this year ÷ 12</td>
                <td class="amt"><strong>{{ $peso($ytd['thirteenth_month']) }}</strong></td>
            </tr>
        </table>
    @endisset

    <table class="sign">
        <tr>
            <td><div class="line">{{ $p['processed_by'] ?? '—' }}</div><div class="cap">Processed by (HR)</div></td>
            <td></td>
            <td><div class="line">&nbsp;</div><div class="cap">Employee signature</div></td>
        </tr>
    </table>

    <div class="foot">
        This is a computer-generated payslip. Amounts in Philippine Peso (PHP).
        Generated {{ $generatedAt }}.
    </div>
</div>
</body>
</html>
