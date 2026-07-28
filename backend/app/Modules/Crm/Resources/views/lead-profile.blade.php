{{--
    Single-lead PDF profile. Rendered by LeadPdfExporter via DomPDF, shared by the Lead Details
    header action and the BD table's row action.

    Profile fields only — activity timeline, internal notes and email history are intentionally out
    of scope for this iteration. Styling mirrors the HR payslip template (DejaVu Sans for DomPDF's
    font support, table-based layout, no external assets) so the two documents look like one system.
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
                 letter-spacing: .06em; width: 32%; }
        td.val { font-weight: bold; }
        .score { margin-top: 16px; border: 1px solid #e2e8f0; border-radius: 6px; padding: 10px 12px; }
        .score td { width: 50%; }
        .score .lbl { color: #64748b; font-size: 10px; }
        .score .val { font-weight: bold; font-size: 16px; color: #0d9488; }
        .foot { margin-top: 28px; color: #94a3b8; font-size: 10px; text-align: center; }
    </style>
</head>
<body>
<div class="wrap">
    <div class="head">
        <div class="company">Viteseo Inc.</div>
        <div class="doc">Lead Profile</div>
        <div class="meta">
            <strong>{{ $lead->business_name ?: '—' }}</strong>
            @if ($lead->industry) · {{ $lead->industry }} @endif
        </div>
    </div>

    <h2>Business</h2>
    <table>
        <tr>
            <td class="lbl">Business Name</td>
            <td class="val">{{ $lead->business_name ?: '—' }}</td>
        </tr>
        <tr>
            <td class="lbl">Industry</td>
            <td class="val">{{ $lead->industry ?: '—' }}</td>
        </tr>
        <tr>
            <td class="lbl">Website</td>
            <td class="val">{{ $lead->website ?: '—' }}</td>
        </tr>
    </table>

    <h2>Contact</h2>
    <table>
        <tr>
            <td class="lbl">Contact Name</td>
            <td class="val">{{ $lead->contact_name ?: '—' }}</td>
        </tr>
        <tr>
            <td class="lbl">Email</td>
            <td class="val">{{ $lead->email ?: '—' }}</td>
        </tr>
        <tr>
            <td class="lbl">Phone</td>
            <td class="val">{{ $lead->phone ?: '—' }}</td>
        </tr>
    </table>

    <h2>Pipeline</h2>
    <table>
        <tr>
            <td class="lbl">Priority</td>
            <td class="val">{{ ucfirst((string) $lead->priority) ?: '—' }}</td>
        </tr>
        <tr>
            <td class="lbl">Status</td>
            {{-- Hyphenated enum values (call-back-req) read as words in a printed document. --}}
            <td class="val">{{ ucfirst(str_replace('-', ' ', (string) $lead->status)) ?: '—' }}</td>
        </tr>
        <tr>
            <td class="lbl">Assigned BD</td>
            <td class="val">{{ $lead->assignedTo?->name ?: 'Unassigned' }}</td>
        </tr>
        <tr>
            <td class="lbl">Created</td>
            <td class="val">{{ $createdAt ?: '—' }}</td>
        </tr>
    </table>

    <table class="score">
        <tr>
            <td>
                <div class="lbl">Lead Score</div>
                <div class="val">{{ $lead->score !== null ? $lead->score . ' / 100' : '—' }}</div>
            </td>
        </tr>
    </table>

    <div class="foot">
        Generated {{ $generatedAt }} · Viteseo CRM · Confidential — internal use only
    </div>
</div>
</body>
</html>
