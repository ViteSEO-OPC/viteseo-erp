import { ProgressBar } from "@/core/components/progress-bar";
import { cn } from "@/lib/utils";

/**
 * The featured balance card (per-type in not-shared mode, or the single pool in
 * shared mode). Takes primitives so the same card renders either. `centered`
 * fills the grid cell and centers the content vertically (used for the pool).
 */
export function LeaveBalanceCard({
  label,
  entitled,
  remaining,
  used,
  centered = false,
}: {
  label: string;
  entitled: number;
  remaining: number;
  used: number;
  centered?: boolean;
}) {
  const pct = entitled > 0 ? (remaining / entitled) * 100 : 0;

  return (
    <div
      className={cn(
        "rounded-card border border-border bg-card p-5",
        centered && "flex h-full flex-col justify-center",
      )}
    >
      <p className="text-label font-bold uppercase tracking-wide text-muted-foreground">
        {label}
      </p>
      <p className="mt-2">
        <span className="text-h2 font-bold text-foreground">{remaining}</span>
        <span className="text-sm text-muted-foreground"> / {entitled} days</span>
      </p>
      <ProgressBar percent={pct} className="mt-3" />
      <div className="mt-2 flex justify-between text-caption">
        <span className="font-medium text-brand">{Math.round(pct)}% Remaining</span>
        <span className="text-muted-foreground">{used} used</span>
      </div>
    </div>
  );
}
