import type { ReportBarItem } from "@/modules/hr/types/reports";

// Horizontal funnel: one bar per pipeline stage, shaded light→dark (the single
// --info token at increasing opacity) with an x-axis of candidate counts.
const W = 1000;
const ROW_H = 64;
const TOP = 16;
const AXIS_H = 46;
const LABEL_W = 190;
const RIGHT = 56;

export function RecruitmentFunnelChart({ data }: { data: ReportBarItem[] }) {
  const n = Math.max(1, data.length);
  const H = TOP + n * ROW_H + AXIS_H;
  const plotX0 = LABEL_W;
  const plotX1 = W - RIGHT;
  const plotW = plotX1 - plotX0;
  const baseline = TOP + n * ROW_H + 4;

  const maxVal = Math.max(1, ...data.map((d) => d.value));
  const niceMax = Math.max(5, Math.ceil(maxVal / 5) * 5);
  const step = niceMax / 5;
  const ticks = Array.from({ length: 6 }, (_, i) => i * step);
  const tickX = (t: number) => plotX0 + (t / niceMax) * plotW;

  return (
    <svg viewBox={`0 0 ${W} ${H}`} className="h-auto w-full" role="img" aria-label="Recruitment funnel chart">
      {/* Gridlines + x ticks */}
      {ticks.map((t) => (
        <g key={t}>
          <line x1={tickX(t)} x2={tickX(t)} y1={TOP} y2={baseline} style={{ stroke: "var(--border)" }} strokeWidth={1} />
          <text x={tickX(t)} y={baseline + 20} textAnchor="middle" fontSize={13} style={{ fill: "var(--muted-foreground)" }}>
            {t}
          </text>
        </g>
      ))}
      <text x={(plotX0 + plotX1) / 2} y={H - 6} textAnchor="middle" fontSize={13} style={{ fill: "var(--muted-foreground)" }}>
        Candidates
      </text>

      {/* Bars */}
      {data.map((d, i) => {
        const cy = TOP + i * ROW_H + ROW_H / 2;
        const barW = (d.value / niceMax) * plotW;
        const opacity = 0.3 + 0.7 * (i / Math.max(1, n - 1));
        return (
          <g key={d.label}>
            <text x={LABEL_W - 14} y={cy} dominantBaseline="middle" textAnchor="end" fontSize={13} style={{ fill: "var(--muted-foreground)" }}>
              {d.label}
            </text>
            <rect x={plotX0} y={cy - 13} width={Math.max(0, barW)} height={26} rx={3} style={{ fill: "var(--info)", opacity }} />
            <text x={plotX0 + Math.max(0, barW) + 10} y={cy} dominantBaseline="middle" fontSize={14} fontWeight={700} style={{ fill: "var(--info)" }}>
              {d.display}
            </text>
          </g>
        );
      })}
    </svg>
  );
}
