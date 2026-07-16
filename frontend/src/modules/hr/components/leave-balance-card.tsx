import { ProgressBar } from "@/core/components/progress-bar";
import type { LeaveBalance } from "@/modules/hr/types/leave";

export function LeaveBalanceCard({ balance }: { balance: LeaveBalance }) {
  const entitled = balance.entitled_days;
  const remaining = balance.remaining_days;
  const pct = entitled > 0 ? (remaining / entitled) * 100 : 0;

  return (
    <div className="rounded-card border border-border bg-card p-5">
      <p className="text-label font-bold uppercase tracking-wide text-muted-foreground">
        {balance.leave_type.name}
      </p>
      <p className="mt-2">
        <span className="text-h2 font-bold text-foreground">
          {remaining.toFixed(1)}
        </span>
        <span className="text-sm text-muted-foreground">
          {" "}
          / {entitled.toFixed(1)} days
        </span>
      </p>
      <ProgressBar percent={pct} className="mt-3" />
      <div className="mt-2 flex justify-between text-caption">
        <span className="font-medium text-brand">{Math.round(pct)}% Remaining</span>
        <span className="text-muted-foreground">
          {balance.used_days.toFixed(1)} used
        </span>
      </div>
    </div>
  );
}
