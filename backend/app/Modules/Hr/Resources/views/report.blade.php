<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <style>
        * { font-family: DejaVu Sans, sans-serif; }
        body { color: #1e293b; font-size: 12px; margin: 0; }
        .wrap { padding: 32px 36px; }
        .head { border-bottom: 2px solid #0d9488; padding-bottom: 12px; margin-bottom: 4px; }
        .company { font-size: 18px; font-weight: bold; color: #0f172a; }
        .doc { font-size: 13px; color: #0d9488; font-weight: bold; margin-top: 2px; }
        .desc { margin-top: 6px; color: #64748b; font-size: 11px; }
        .meta { margin-top: 4px; color: #94a3b8; font-size: 10px; }
        h2 { font-size: 11px; text-transform: uppercase; letter-spacing: .06em; color: #64748b;
             border-bottom: 1px solid #e2e8f0; padding-bottom: 4px; margin: 18px 0 8px; }
        table { width: 100%; border-collapse: collapse; margin-bottom: 6px; }
        th { text-align: left; font-size: 10px; text-transform: uppercase; color: #64748b;
             padding: 5px 8px; border-bottom: 1px solid #e2e8f0; }
        td { padding: 5px 8px; border-bottom: 1px solid #f1f5f9; }
        td.r, th.r { text-align: right; }
        .sum td { border: none; padding: 6px 12px 6px 0; }
        .sum .lbl { color: #64748b; font-size: 10px; }
        .sum .val { font-weight: bold; font-size: 15px; color: #0f172a; }
        .track { background: #e2e8f0; height: 8px; border-radius: 4px; width: 140px; }
        .fill { background: #0d9488; height: 8px; border-radius: 4px; }
        .note { margin-top: 14px; color: #64748b; font-size: 10px; font-style: italic; }
        .foot { margin-top: 28px; color: #94a3b8; font-size: 10px; text-align: center; }
    </style>
</head>
<body>
<div class="wrap">
    <div class="head">
        <div class="company">Viteseo Inc.</div>
        <div class="doc">{{ $r['title'] }}</div>
        <div class="desc">{{ $r['description'] }}</div>
        <div class="meta">Generated {{ $generatedAt }}</div>
    </div>

    @if (!empty($r['summary']))
        <h2>Summary</h2>
        <table class="sum"><tr>
            @foreach ($r['summary'] as $s)
                <td><div class="lbl">{{ $s['label'] }}</div><div class="val">{{ $s['value'] }}</div></td>
            @endforeach
        </tr></table>
    @endif

    @if (!empty($r['bars']))
        @php $max = max(array_map(fn ($i) => $i['value'], $r['bars']['items']) ?: [1]); @endphp
        <h2>{{ $r['bars']['title'] }}</h2>
        <table>
            @foreach ($r['bars']['items'] as $i)
                <tr>
                    <td style="width:40%">{{ $i['label'] }}</td>
                    <td style="width:40%"><div class="track"><div class="fill" style="width: {{ $max > 0 ? round($i['value'] / $max * 140) : 0 }}px"></div></div></td>
                    <td class="r" style="width:20%">{{ $i['display'] }}</td>
                </tr>
            @endforeach
        </table>
    @endif

    @foreach (['stacked' => 'Breakdown', 'departures' => 'Departures', 'representation' => 'Representation'] as $skey => $slabel)
        @if (!empty($r[$skey]))
            <h2>{{ $slabel }}</h2>
            <table>
                <tr><th>Period</th>@foreach ($r[$skey]['series'] as $ser)<th class="r">{{ $ser['name'] }}</th>@endforeach</tr>
                @foreach ($r[$skey]['points'] as $pt)
                    <tr><td>{{ $pt['label'] }}</td>@foreach ($pt['values'] as $v)<td class="r">{{ $v }}</td>@endforeach</tr>
                @endforeach
            </table>
        @endif
    @endforeach

    @if (!empty($r['movement']))
        <h2>Movement (12 months)</h2>
        <table>
            <tr><th>Month</th><th class="r">Joiners</th><th class="r">Leavers</th><th class="r">Net</th></tr>
            @foreach ($r['movement'] as $m)
                <tr><td>{{ $m['label'] }}</td><td class="r">{{ $m['joiners'] }}</td><td class="r">{{ $m['leavers'] }}</td><td class="r">{{ $m['net'] }}</td></tr>
            @endforeach
        </table>
    @endif

    @if (!empty($r['funnel']))
        <h2>Funnel</h2>
        <table>
            <tr><th>Stage</th><th class="r">Count</th></tr>
            @foreach ($r['funnel'] as $f)
                <tr><td>{{ $f['label'] }}</td><td class="r">{{ $f['display'] }}</td></tr>
            @endforeach
        </table>
    @endif

    @if (!empty($r['tenure']))
        <h2>Tenure curve</h2>
        <table>
            <tr><th>Milestone</th><th class="r">% remaining</th></tr>
            @foreach ($r['tenure'] as $t)
                <tr><td>{{ $t['label'] }}</td><td class="r">{{ $t['value'] }}%</td></tr>
            @endforeach
        </table>
    @endif

    @foreach (['table', 'remittance'] as $tkey)
        @if (!empty($r[$tkey]))
            @php $tbl = $r[$tkey]; @endphp
            <h2>{{ $tbl['title'] ?? 'Details' }}</h2>
            <table>
                <tr>@foreach ($tbl['columns'] as $ci => $col)<th class="{{ $ci > 0 ? 'r' : '' }}">{{ $col }}</th>@endforeach</tr>
                @foreach ($tbl['rows'] as $row)
                    <tr>@foreach ($row as $ci => $cell)<td class="{{ $ci > 0 ? 'r' : '' }}">{{ $cell }}</td>@endforeach</tr>
                @endforeach
            </table>
        @endif
    @endforeach

    @if (!empty($r['note']))
        <div class="note">{{ $r['note'] }}</div>
    @endif

    <div class="foot">Viteseo HRIS · Confidential</div>
</div>
</body>
</html>
