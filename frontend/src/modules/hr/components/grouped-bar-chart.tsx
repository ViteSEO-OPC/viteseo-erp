import type { StackedSeries } from "@/modules/hr/types/reports";

// Grouped (side-by-side) bars per point — one bar per series. SVG, no library.
const W = 560;
const H = 360;
const M = { top: 24, right: 20, bottom: 40, left: 48 };
const FALLBACK = ["var(--info)", "var(--danger)", "var(--success)", "var(--warning)", "var(--violet)"];

export function GroupedBarChart({ data, yLabel }: { data: StackedSeries; yLabel: string }) {
  const { series, points } = data;
  const color = (i: number) => series[i]?.color ?? FALLBACK[i % FALLBACK.length];

  const plotX0 = M.left;
  const plotX1 = W - M.right;
  const plotY0 = M.top;
  const plotY1 = H - M.bottom;
  const plotW = plotX1 - plotX0;
  const plotH = plotY1 - plotY0;

  const maxVal = Math.max(2, ...points.flatMap((p) => p.values));
  const yMax = Math.ceil(maxVal);
  const ticks = Array.from({ length: yMax + 1 }, (_, i) => i);
  const y = (v: number) => plotY1 - (v / yMax) * plotH;

  const n = Math.max(1, points.length);
  const slotW = plotW / n;
  const sCount = Math.max(1, series.length);
  const groupW = slotW * 0.56;
  const gap = 4;
  const barW = Math.max(4, (groupW - gap * (sCount - 1)) / sCount);
  const center = (i: number) => plotX0 + (i + 0.5) * slotW;

  return (
    <div>
      <div className="mb-4 flex flex-wrap items-center gap-5 text-sm text-muted-foreground">
        {series.map((s, i) => (
          <span key={s.name} className="inline-flex items-center gap-2">
            <span className="size-3 rounded-sm" style={{ background: color(i) }} />
            {s.name}
          </span>
        ))}
      </div>

      <svg viewBox={`0 0 ${W} ${H}`} className="h-auto w-full" role="img" aria-label={`${yLabel} chart`}>
        {ticks.map((t) => (
          <g key={t}>
            <line x1={plotX0} x2={plotX1} y1={y(t)} y2={y(t)} style={{ stroke: "var(--border)" }} strokeWidth={1} />
            <text x={plotX0 - 10} y={y(t)} dominantBaseline="middle" textAnchor="end" fontSize={12} style={{ fill: "var(--muted-foreground)" }}>
              {t}
            </text>
          </g>
        ))}
        <text transform={`rotate(-90, 16, ${(plotY0 + plotY1) / 2})`} x={16} y={(plotY0 + plotY1) / 2} textAnchor="middle" fontSize={12} style={{ fill: "var(--muted-foreground)" }}>
          {yLabel}
        </text>

        {points.map((p, i) => {
          const groupLeft = center(i) - groupW / 2;
          return (
            <g key={p.label}>
              {p.values.map((v, s) => {
                const x = groupLeft + s * (barW + gap);
                const h = Math.max(0, plotY1 - y(v));
                return v > 0 ? <rect key={s} x={x} y={y(v)} width={barW} height={h} rx={2} style={{ fill: color(s) }} /> : null;
              })}
              <text x={center(i)} y={plotY1 + 22} textAnchor="middle" fontSize={12} style={{ fill: "var(--muted-foreground)" }}>
                {p.label}
              </text>
            </g>
          );
        })}
      </svg>
    </div>
  );
}
