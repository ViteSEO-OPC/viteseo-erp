import { cn } from "@/lib/utils";

/**
 * Teal fill on a light track — used for leave-balance cards. Pass `value` and
 * `max` (or a precomputed `percent`).
 */
export function ProgressBar({
  value,
  max = 100,
  percent,
  className,
}: {
  value?: number;
  max?: number;
  percent?: number;
  className?: string;
}) {
  const pct =
    percent ?? (max > 0 ? Math.min(100, Math.max(0, (value! / max) * 100)) : 0);

  return (
    <div
      className={cn("h-2 w-full overflow-hidden rounded-full bg-muted", className)}
      role="progressbar"
      aria-valuenow={Math.round(pct)}
      aria-valuemin={0}
      aria-valuemax={100}
    >
      <div
        className="h-full rounded-full bg-brand transition-[width]"
        style={{ width: `${pct}%` }}
      />
    </div>
  );
}
