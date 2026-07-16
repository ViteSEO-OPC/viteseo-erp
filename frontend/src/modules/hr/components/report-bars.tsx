import type { ReportBars as ReportBarsData } from "@/modules/hr/types/reports";

// Horizontal flex-div bars — no chart library (§1.5 / §5.9).
export function ReportBars({ bars }: { bars: ReportBarsData }) {
  const max = Math.max(...bars.items.map((i) => i.value), 1);

  return (
    <div className="space-y-2.5">
      {bars.items.map((item) => (
        <div key={item.label} className="flex items-center gap-3">
          <span className="w-28 shrink-0 truncate text-caption text-muted-foreground">
            {item.label}
          </span>
          <div className="h-6 flex-1 overflow-hidden rounded-md bg-muted">
            <div
              className="h-full rounded-md bg-brand transition-[width]"
              style={{ width: `${Math.max(2, (item.value / max) * 100)}%` }}
            />
          </div>
          <span className="w-20 shrink-0 text-right text-sm font-medium text-foreground">
            {item.display}
          </span>
        </div>
      ))}
    </div>
  );
}
