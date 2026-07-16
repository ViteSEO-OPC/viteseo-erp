"use client";

import type { TrendPoint } from "@/modules/hr/types/dashboard";

const W = 720;
const H = 240;
const PAD = { left: 40, right: 28, top: 20, bottom: 30 };
const PLOT_W = W - PAD.left - PAD.right;
const PLOT_H = H - PAD.top - PAD.bottom;

const x = (i: number, n: number) => PAD.left + (n === 1 ? 0 : (i / (n - 1)) * PLOT_W);
const y = (v: number) => PAD.top + PLOT_H - (v / 100) * PLOT_H;
const pts = (series: number[]) => series.map((v, i) => `${x(i, series.length)},${y(v)}`).join(" ");

export function AttendanceTrendChart({ data }: { data: TrendPoint[] }) {
  const days = data.map((d) => d.date);
  const onTime = data.map((d) => d.on_time);
  const late = data.map((d) => d.late);
  const n = data.length;
  const gridVals = [0, 25, 50, 75, 100];

  if (n === 0) {
    return (
      <div>
        <h3 className="text-table font-bold text-foreground">Attendance and punctuality — last 30 days</h3>
        <p className="mt-4 text-sm text-muted-foreground">No attendance data yet.</p>
      </div>
    );
  }

  const maxLabels = n <= 7 ? n : 6;
  const labelStep = Math.max(1, Math.floor((n - 1) / (maxLabels - 1)));

  return (
    <div>
      <div className="flex flex-wrap items-center justify-between gap-2">
        <h3 className="text-table font-bold text-foreground">Attendance and punctuality — last 30 days</h3>
        <div className="flex items-center gap-4 text-caption text-muted-foreground">
          <span className="inline-flex items-center gap-1.5">
            <span className="h-0.5 w-4 rounded-full" style={{ background: "var(--brand)" }} /> On-time rate
          </span>
          <span className="inline-flex items-center gap-1.5">
            <span className="h-0.5 w-4 rounded-full" style={{ background: "var(--danger)" }} /> Late rate
          </span>
        </div>
      </div>

      <svg viewBox={`0 0 ${W} ${H}`} className="mt-3 h-auto w-full" role="img" aria-label="Attendance and punctuality trend, last 30 days">
        {gridVals.map((g) => (
          <g key={g}>
            <line x1={PAD.left} x2={W - PAD.right} y1={y(g)} y2={y(g)} stroke="var(--border)" strokeWidth={1} />
            <text x={PAD.left - 8} y={y(g) + 4} textAnchor="end" fontSize={11} fill="var(--muted-foreground)">{g}%</text>
          </g>
        ))}
        {days.map((lbl, i) => {
          if (i % labelStep !== 0 && i !== n - 1) return null;
          return (
            <text key={lbl} x={x(i, n)} y={H - 8} textAnchor={i === 0 ? "start" : i === n - 1 ? "end" : "middle"} fontSize={11} fill="var(--muted-foreground)">
              {lbl}
            </text>
          );
        })}
        <polyline points={pts(late)} fill="none" stroke="var(--danger)" strokeWidth={2.5} strokeLinejoin="round" strokeLinecap="round" />
        <polyline points={pts(onTime)} fill="none" stroke="var(--brand)" strokeWidth={2.5} strokeLinejoin="round" strokeLinecap="round" />
        <text x={x(n - 1, n)} y={y(onTime[n - 1]) - 8} textAnchor="end" fontSize={12} fontWeight={700} fill="var(--brand)">
          {onTime[n - 1]}%
        </text>
        <text x={x(n - 1, n)} y={y(late[n - 1]) + 16} textAnchor="end" fontSize={12} fontWeight={700} fill="var(--danger)">
          {late[n - 1]}%
        </text>
      </svg>
    </div>
  );
}
