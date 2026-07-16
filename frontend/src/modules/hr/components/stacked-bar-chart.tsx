import type { StackedSeries } from "@/modules/hr/types/reports";

// Reusable stacked bar chart (SVG, no chart library). Each series segment stacks
// bottom-up per point; colors come from series[i].color with a token fallback.
const W = 1000;
const H = 400;
const M = { top: 28, right: 24, bottom: 44, left: 64 };
const FALLBACK = ["var(--brand)", "var(--info)", "var(--warning)", "var(--danger)", "var(--success)", "var(--violet)"];

/** Round axis max + step so ticks land on nice numbers (1/2/2.5/5/10 × 10ⁿ). */
function niceScale(max: number): { niceMax: number; step: number } {
  const rough = Math.max(max, 1) / 5;
  const mag = Math.pow(10, Math.floor(Math.log10(rough)));
  const norm = rough / mag;
  const niceNorm = norm <= 1 ? 1 : norm <= 2 ? 2 : norm <= 2.5 ? 2.5 : norm <= 5 ? 5 : 10;
  const step = niceNorm * mag;
  return { niceMax: Math.max(step, Math.ceil(max / step) * step), step };
}

export function StackedBarChart({ data, yLabel }: { data: StackedSeries; yLabel: string }) {
  const { series, points } = data;
  const color = (i: number) => series[i]?.color ?? FALLBACK[i % FALLBACK.length];

  const plotX0 = M.left;
  const plotX1 = W - M.right;
  const plotY0 = M.top;
  const plotY1 = H - M.bottom;
  const plotW = plotX1 - plotX0;
  const plotH = plotY1 - plotY0;

  const maxSum = Math.max(1, ...points.map((p) => p.values.reduce((a, b) => a + b, 0)));
  const { niceMax, step } = niceScale(maxSum);
  const ticks: number[] = [];
  for (let t = 0; t <= niceMax + 1e-9; t += step) ticks.push(Math.round(t * 100) / 100);
  const y = (v: number) => plotY1 - (v / niceMax) * plotH;

  const n = Math.max(1, points.length);
  const slotW = plotW / n;
  const barW = Math.min(52, slotW * 0.5);
  const center = (i: number) => plotX0 + (i + 0.5) * slotW;

  return (
    <div>
      {/* Legend */}
      <div className="mb-4 flex flex-wrap items-center gap-5 text-sm text-muted-foreground">
        {series.map((s, i) => (
          <span key={s.name} className="inline-flex items-center gap-2">
            <span className="size-3 rounded-sm" style={{ background: color(i) }} />
            {s.name}
          </span>
        ))}
      </div>

      <svg viewBox={`0 0 ${W} ${H}`} className="h-auto w-full" role="img" aria-label={`${yLabel} chart`}>
        {/* Gridlines + y ticks */}
        {ticks.map((t) => (
          <g key={t}>
            <line x1={plotX0} x2={plotX1} y1={y(t)} y2={y(t)} style={{ stroke: "var(--border)" }} strokeWidth={1} />
            <text x={plotX0 - 12} y={y(t)} dominantBaseline="middle" textAnchor="end" fontSize={13} style={{ fill: "var(--muted-foreground)" }}>
              {Number.isInteger(t) ? t.toLocaleString() : t.toFixed(1)}
            </text>
          </g>
        ))}

        {/* Y-axis label */}
        <text
          transform={`rotate(-90, 20, ${(plotY0 + plotY1) / 2})`}
          x={20}
          y={(plotY0 + plotY1) / 2}
          textAnchor="middle"
          fontSize={13}
          style={{ fill: "var(--muted-foreground)" }}
        >
          {yLabel}
        </text>

        {/* Stacked bars */}
        {points.map((p, i) => {
          const cx = center(i);
          let acc = 0;
          return (
            <g key={p.label}>
              {p.values.map((v, s) => {
                const yTop = y(acc + v);
                const yBottom = y(acc);
                acc += v;
                if (v <= 0) return null;
                return (
                  <rect key={s} x={cx - barW / 2} y={yTop} width={barW} height={Math.max(0, yBottom - yTop)} style={{ fill: color(s) }} />
                );
              })}
              <text x={cx} y={plotY1 + 24} textAnchor="middle" fontSize={13} style={{ fill: "var(--muted-foreground)" }}>
                {p.label}
              </text>
            </g>
          );
        })}
      </svg>
    </div>
  );
}
