import type { StackedSeries } from "@/modules/hr/types/reports";

// 100%-stacked horizontal bars (one row per point). Each row spans the full width
// and is split by its series values (which should sum to 100). SVG, no library.
const W = 1000;
const ROW_H = 66;
const BAR_H = 34;
const TOP = 12;
const AXIS_H = 46;
const LABEL_W = 150;
const RIGHT = 24;
const FALLBACK = ["var(--violet)", "var(--brand)", "var(--info)", "var(--warning)", "var(--success)"];

export function HorizontalStackedChart({ data, xLabel }: { data: StackedSeries; xLabel: string }) {
  const { series, points } = data;
  const color = (i: number) => series[i]?.color ?? FALLBACK[i % FALLBACK.length];

  const n = Math.max(1, points.length);
  const H = TOP + n * ROW_H + AXIS_H;
  const plotX0 = LABEL_W;
  const plotX1 = W - RIGHT;
  const plotW = plotX1 - plotX0;
  const baseline = TOP + n * ROW_H + 4;
  const ticks = [0, 20, 40, 60, 80, 100];
  const tx = (v: number) => plotX0 + (v / 100) * plotW;

  return (
    <div>
      {/* Legend */}
      <div className="mb-4 flex items-center justify-end gap-5 text-sm text-muted-foreground">
        {series.map((s, i) => (
          <span key={s.name} className="inline-flex items-center gap-2">
            <span className="size-3 rounded-sm" style={{ background: color(i) }} />
            {s.name}
          </span>
        ))}
      </div>

      <svg viewBox={`0 0 ${W} ${H}`} className="h-auto w-full" role="img" aria-label={`${xLabel} chart`}>
        {/* Gridlines + x ticks */}
        {ticks.map((t) => (
          <g key={t}>
            <line x1={tx(t)} x2={tx(t)} y1={TOP} y2={baseline} style={{ stroke: "var(--border)" }} strokeWidth={1} />
            <text x={tx(t)} y={baseline + 20} textAnchor="middle" fontSize={13} style={{ fill: "var(--muted-foreground)" }}>
              {t}
            </text>
          </g>
        ))}
        <text x={(plotX0 + plotX1) / 2} y={H - 6} textAnchor="middle" fontSize={13} style={{ fill: "var(--muted-foreground)" }}>
          {xLabel}
        </text>

        {/* Rows */}
        {points.map((p, i) => {
          const cy = TOP + i * ROW_H + ROW_H / 2;
          let acc = 0;
          return (
            <g key={p.label}>
              <text x={LABEL_W - 14} y={cy} dominantBaseline="middle" textAnchor="end" fontSize={13} style={{ fill: "var(--muted-foreground)" }}>
                {p.label}
              </text>
              {p.values.map((v, s) => {
                const x = tx(acc);
                const segW = (v / 100) * plotW;
                acc += v;
                if (v <= 0) return null;
                return (
                  <g key={s}>
                    <rect x={x} y={cy - BAR_H / 2} width={segW} height={BAR_H} style={{ fill: color(s) }} />
                    {v >= 8 && (
                      <text x={x + segW / 2} y={cy} dominantBaseline="middle" textAnchor="middle" fontSize={13} fontWeight={600} fill="white">
                        {v}%
                      </text>
                    )}
                  </g>
                );
              })}
            </g>
          );
        })}
      </svg>
    </div>
  );
}
