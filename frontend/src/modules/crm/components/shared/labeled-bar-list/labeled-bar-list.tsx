"use client";

import { cn } from "@/modules/crm/lib/utils";

type BarRow = {
  label: string;
  value: number;
  color: string;
};

type LabeledBarListProps = {
  rows: BarRow[];
  max?: number;
  showAxis?: boolean;
  className?: string;
};

export function LabeledBarList({ rows, max, className }: LabeledBarListProps) {
  const maxValue = max ?? Math.max(...rows.map((r) => r.value), 1);

  return (
    <div className={cn("flex flex-col gap-2", className)}>
      {rows.map((row, i) => (
        <div key={i} className="flex items-center gap-3">
          <span className="w-24 shrink-0 truncate text-xs text-gray-600">
            {row.label}
          </span>
          <div className="flex flex-1 items-center gap-2">
            <div className="h-3 flex-1 rounded-full bg-gray-150">
              <div
                className="h-3 rounded-full transition-all"
                style={{
                  width: `${(row.value / maxValue) * 100}%`,
                  backgroundColor: row.value > 0 ? row.color : "transparent",
                  border: row.value === 0 ? "1px dashed #D1D5DB" : undefined,
                }}
              />
            </div>
            <span className="w-6 text-right text-xs font-medium text-gray-900">
              {row.value}
            </span>
          </div>
        </div>
      ))}
    </div>
  );
}
