{{--
    Performance scorecard PDF. Rendered by PerformancePdfExporter via DomPDF, shared by the BD's
    My Performance export and the Admin/Super Admin Reports & Performance export (one BD or the
    CRM-wide "All Reports" scope).

    Every figure arrives pre-computed from PerformanceSummaryService + the KPI Engine's M-04 — this
    template performs NO business computation, so the document and the screen cannot disagree.

    NO GLOBAL REPORTING PERIOD. The performance architecture has no date-range filter, so the
    document never claims one. Only metrics that genuinely carry a window are labelled with it
    (Completed Follow-ups — This Month); current-state metrics carry no date label rather than an
    invented one. Personal Target (Pending) and Recent Activity are deliberately out of scope.

    Styling mirrors lead-profile.blade.php (DejaVu Sans for DomPDF, table layout, no external
    assets) so the CRM's documents look like one system.
--}}
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
        td { padding: 6px 0; vertical-align: top; }
        td.lbl { color: #64748b; font-size: 10px; text-transform: uppercase;
                 letter-spacing: .06em; width: 62%; }
        td.val { font-weight: bold; text-align: right; }
        td.lbl .window { text-transform: none; letter-spacing: 0; font-style: italic; }
        .foot { margin-top: 28px; color: #94a3b8; font-size: 10px; text-align: center; }
    </style>
</head>
<body>
<div class="wrap">
    <div class="head">
        <div class="company">Zerem CRM</div>
        <div class="doc">Performance Report</div>
        <div class="meta">
            @if ($isAllReports)
                <strong>Scope:</strong> All Reports
            @else
                <strong>BD:</strong> {{ $subjectName ?: '—' }}
            @endif
            <br>
            <strong>Generated:</strong> {{ $generatedAt }}
        </div>
    </div>

    <h2>Pipeline</h2>
    <table>
        <tr>
            <td class="lbl">Assigned Leads</td>
            <td class="val">{{ number_format($summary['assignedLeads']) }}</td>
        </tr>
        <tr>
            <td class="lbl">Active Leads</td>
            <td class="val">{{ number_format($summary['activeLeads']) }}</td>
        </tr>
        <tr>
            <td class="lbl">Converted Leads</td>
            <td class="val">{{ number_format($summary['convertedLeads']) }}</td>
        </tr>
        {{-- Undefined (no engaged leads) prints "—", never a misleading 0%. --}}
        <tr>
            <td class="lbl">Conversion Rate</td>
            <td class="val">
                {{ $summary['conversionRate'] === null ? '—' : $summary['conversionRate'] . '%' }}
            </td>
        </tr>
    </table>

    <h2>Value</h2>
    <table>
        <tr>
            <td class="lbl">Overall Revenue</td>
            <td class="val">₱{{ number_format($summary['overallRevenue'], 2) }}</td>
        </tr>
        <tr>
            <td class="lbl">Estimated Pipeline</td>
            <td class="val">₱{{ number_format($summary['estimatedPipelineValue'], 2) }}</td>
        </tr>
    </table>

    <h2>Outreach &amp; Follow-ups</h2>
    <table>
        <tr>
            <td class="lbl">Outreach Progress</td>
            <td class="val">{{ $outreachCoverage === null ? '—' : round($outreachCoverage) . '%' }}</td>
        </tr>
        {{-- The one metric with a real window in the approved definition — stated, not assumed. --}}
        <tr>
            <td class="lbl">Completed Follow-ups <span class="window">— this month</span></td>
            <td class="val">{{ number_format($summary['completedFollowUps']) }}</td>
        </tr>
    </table>

    <h2>Follow-up Discipline</h2>
    <table>
        <tr>
            <td class="lbl">Missed Follow-ups</td>
            <td class="val">{{ number_format($summary['followUpDiscipline']['missedFollowUps']) }}</td>
        </tr>
        <tr>
            <td class="lbl">Missed Meetings</td>
            <td class="val">{{ number_format($summary['followUpDiscipline']['missedMeetings']) }}</td>
        </tr>
        <tr>
            <td class="lbl">Missed Calls</td>
            <td class="val">{{ number_format($summary['followUpDiscipline']['missedCalls']) }}</td>
        </tr>
    </table>

    <div class="foot">
        Generated by Zerem CRM on {{ $generatedAt }}. Figures reflect current CRM data at that time.
    </div>
</div>
</body>
</html>
