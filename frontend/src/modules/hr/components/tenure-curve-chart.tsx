// Survival-style tenure curve: % still employed at each tenure milestone.
// Filled area under a line, SVG, no chart library.
const W = 560;
const H = 360;
const M = { top: 24, right: 20, bottom: 40, left: 48 };

export function TenureCurveChart({ data }: { data: { label: string; value: number }[] }) {
  const plotX0 = M.left;
  const plotX1 = W - M.right;
  const plotY0 = M.top;
  const plotY1 = H - M.bottom;
  const plotW = plotX1 - plotX0;
  const plotH = plotY1 - plotY0;

  const ticks = [0, 20, 40, 60, 80, 100];
  const y = (v: number) => plotY1 - (v / 100) * plotH;
  const n = Math.max(1, data.length);
  const x = (i: number) => plotX0 + (n === 1 ? plotW / 2 : (i / (n - 1)) * plotW);

  const line = data.map((d, i) => `${x(i)},${y(d.value)}`).join(" ");
  const area = `M ${x(0)},${plotY1} ${data.map((d, i) => `L ${x(i)},${y(d.value)}`).join(" ")} L ${x(n - 1)},${plotY1} Z`;

  return (
    <svg viewBox={`0 0 ${W} ${H}`} className="h-auto w-full" role="img" aria-label="Tenure curve chart">
      {ticks.map((t) => (
        <g key={t}>
          <line x1={plotX0} x2={plotX1} y1={y(t)} y2={y(t)} style={{ stroke: "var(--border)" }} strokeWidth={1} />
          <text x={plotX0 - 10} y={y(t)} dominantBaseline="middle" textAnchor="end" fontSize={12} style={{ fill: "var(--muted-foreground)" }}>
            {t}
          </text>
        </g>
      ))}
      <text transform={`rotate(-90, 16, ${(plotY0 + plotY1) / 2})`} x={16} y={(plotY0 + plotY1) / 2} textAnchor="middle" fontSize={12} style={{ fill: "var(--muted-foreground)" }}>
        % still employed
      </text>

      <path d={area} style={{ fill: "var(--success)", opacity: 0.15 }} />
      <polyline points={line} fill="none" style={{ stroke: "var(--success)" }} strokeWidth={2.5} strokeLinejoin="round" strokeLinecap="round" />
      {data.map((d, i) => (
        <circle key={d.label} cx={x(i)} cy={y(d.value)} r={4} style={{ fill: "var(--success)" }} />
      ))}
      {data.map((d, i) => (
        <text key={d.label} x={x(i)} y={plotY1 + 22} textAnchor="middle" fontSize={12} style={{ fill: "var(--muted-foreground)" }}>
          {d.label}
        </text>
      ))}
    </svg>
  );
}
