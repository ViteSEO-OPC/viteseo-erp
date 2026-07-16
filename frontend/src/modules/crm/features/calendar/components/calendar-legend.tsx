"use client";

const LEGEND_ITEMS = [
  { label: "Email", color: "var(--chart-1, #4FD8D7)" },
  { label: "Call", color: "var(--chart-5, #7B9EF9)" },
  { label: "Meeting", color: "var(--chart-3, #F9C74F)" },
  { label: "Task", color: "var(--chart-2, #F08080)" },
];

export function CalendarLegend() {
  return (
    <div className="flex flex-wrap items-center gap-4">
      {LEGEND_ITEMS.map((item) => (
        <div key={item.label} className="flex items-center gap-1.5">
          <span
            className="h-2.5 w-2.5 rounded-full"
            style={{ backgroundColor: item.color }}
            aria-hidden
          />
          <span className="text-xs text-secondary-foreground">{item.label}</span>
        </div>
      ))}
    </div>
  );
}
