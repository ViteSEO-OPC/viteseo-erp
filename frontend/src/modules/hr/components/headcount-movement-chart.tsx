import type { MovementPoint } from "@/modules/hr/types/reports";

// Grouped bars (Onboard / Off-board) + a Net-change line, drawn as plain SVG so we
// stay free of a chart library. Colors come from the design tokens.
const W = 1000;
const H = 380;
const M = { top: 28, right: 24, bottom: 44, left: 56 };

export function HeadcountMovementChart({ data }: { data: MovementPoint[] }) {
  const plotX0 = M.left;
  const plotX1 = W - M.right;
  const plotY0 = M.top;
  const plotY1 = H - M.bottom;
  const plotW = plotX1 - plotX0;
  const plotH = plotY1 - plotY0;

  const values = data.flatMap((d) => [d.joiners, d.leavers, d.net]);
  const yMax = Math.max(3, ...values);
  const yMin = Math.min(0, ...data.map((d) => d.net));
  const span = yMax - yMin || 1;

  const y = (v: number) => plotY1 - ((v - yMin) / span) * plotH;
  const baseline = y(0);

  const step = Math.max(1, Math.ceil(span / 5));
  const ticks: number[] = [];
  for (let t = yMin; t <= yMax; t += step) ticks.push(t);

  const n = Math.max(1, data.length);
  const slotW = plotW / n;
  const barW = Math.min(26, slotW * 0.24);
  const gap = 4;
  const center = (i: number) => plotX0 + (i + 0.5) * slotW;

  const linePoints = data.map((d, i) => `${center(i)},${y(d.net)}`).join(" ");

  return (
    <div>
      {/* Legend */}
      <div className="mb-4 flex flex-wrap items-center gap-5 text-sm text-muted-foreground">
        <span className="inline-flex items-center gap-2">
          <span className="relative inline-flex h-0.5 w-5 items-center rounded-full" style={{ background: "var(--success)" }}>
            <span className="absolute left-1/2 size-2 -translate-x-1/2 rounded-full" style={{ background: "var(--success)" }} />
          </span>
          Net change
        </span>
        <span className="inline-flex items-center gap-2">
          <span className="size-3 rounded-sm" style={{ background: "var(--info)" }} /> Onboard
        </span>
        <span className="inline-flex items-center gap-2">
          <span className="size-3 rounded-sm" style={{ background: "var(--danger)" }} /> Off-board
        </span>
      </div>

      <svg viewBox={`0 0 ${W} ${H}`} className="h-auto w-full" role="img" aria-label="Headcount movement chart">
        {/* Gridlines + y ticks */}
        {ticks.map((t) => (
          <g key={t}>
            <line x1={plotX0} x2={plotX1} y1={y(t)} y2={y(t)} style={{ stroke: "var(--border)" }} strokeWidth={1} />
            <text x={plotX0 - 12} y={y(t)} dominantBaseline="middle" textAnchor="end" fontSize={13} style={{ fill: "var(--muted-foreground)" }}>
              {t}
            </text>
          </g>
        ))}

        {/* Y-axis label */}
        <text
          transform={`rotate(-90, 18, ${(plotY0 + plotY1) / 2})`}
          x={18}
          y={(plotY0 + plotY1) / 2}
          textAnchor="middle"
          fontSize={13}
          style={{ fill: "var(--muted-foreground)" }}
        >
          Employees
        </text>

        {/* Bars */}
        {data.map((d, i) => {
          const c = center(i);
          return (
            <g key={d.label}>
              <rect
                x={c - barW - gap / 2}
                y={y(d.joiners)}
                width={barW}
                height={Math.max(0, baseline - y(d.joiners))}
                rx={3}
                style={{ fill: "var(--info)" }}
              />
              <rect
                x={c + gap / 2}
                y={y(d.leavers)}
                width={barW}
                height={Math.max(0, baseline - y(d.leavers))}
                rx={3}
                style={{ fill: "var(--danger)" }}
              />
              <text x={c} y={plotY1 + 24} textAnchor="middle" fontSize={13} style={{ fill: "var(--muted-foreground)" }}>
                {d.label}
              </text>
            </g>
          );
        })}

        {/* Net-change line + dots */}
        <polyline points={linePoints} fill="none" style={{ stroke: "var(--success)" }} strokeWidth={2.5} strokeLinejoin="round" strokeLinecap="round" />
        {data.map((d, i) => (
          <circle key={d.label} cx={center(i)} cy={y(d.net)} r={4.5} style={{ fill: "var(--success)" }} />
        ))}
      </svg>
    </div>
  );
}
