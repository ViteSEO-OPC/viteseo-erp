"use client";

import { ChartCard } from "@/modules/crm/components/shared/chart-card/chart-card";
import { cn } from "@/modules/crm/lib/utils";
import {
  useReportsAnalytics,
  type PendingFollowUpRow,
} from "@/modules/crm/features/reports/hooks/use-reports-analytics";

/**
 * Pending follow-ups — LIVE. The team's action queue: scoped Planner follow-ups that aren't completed,
 * classified Overdue / Today / Upcoming by the Asia/Manila day boundary (server-side). Deliberately a
 * table, not a chart — it's a worklist. Company / description / due date come straight from the event.
 *
 * The server returns at most ten rows while the header counts the whole pending set, so a trailing
 * "+N more not shown" row keeps the two from contradicting each other. This card is a summary, not
 * the worklist of record — the cap stays, and the count stays truthful.
 */
const STATUS_STYLE: Record<PendingFollowUpRow["status"], { label: string; className: string; dot: string }> = {
  overdue: { label: "Overdue", className: "bg-red-500/10 text-red-600", dot: "bg-red-500" },
  today: { label: "Today", className: "bg-emerald-500/10 text-emerald-600", dot: "bg-emerald-500" },
  upcoming: { label: "Upcoming", className: "bg-muted text-muted-foreground", dot: "bg-muted-foreground" },
};

const DATE_FMT = new Intl.DateTimeFormat("en-PH", {
  month: "short",
  day: "numeric",
  timeZone: "Asia/Manila",
});

function formatDue(iso: string | null): string {
  if (!iso) return "—";
  const d = new Date(iso);
  return Number.isNaN(d.getTime()) ? "—" : DATE_FMT.format(d);
}

export function PendingFollowUpsCard({ className }: { className?: string }) {
  const { data, isLoading, isError } = useReportsAnalytics();
  const rows = data?.pendingFollowUps.rows ?? [];
  const counts = data?.pendingFollowUps.counts;

  // The server caps `rows` (10) but `counts` covers the WHOLE pending set, so the header could read
  // "15 overdue" above ten rows with nothing saying the other five existed. The two numbers are both
  // already in hand — the gap was only ever unrendered, so nothing extra is fetched to close it.
  // The three buckets partition the pending set server-side, so their sum IS the total.
  const total = counts ? counts.overdue + counts.today + counts.upcoming : rows.length;
  const notShown = Math.max(0, total - rows.length);

  return (
    <ChartCard
      title="Pending follow-ups"
      subtitle="The team's daily action queue — deliberately not a chart"
      className={className}
      isLoading={isLoading}
      isError={isError}
      isEmpty={!isLoading && rows.length === 0}
      emptyMessage="No pending follow-ups — the queue is clear."
      filter={
        counts && (
          <span className="rounded-full border border-border bg-muted px-2.5 py-1 text-[11px] font-medium text-muted-foreground">
            {counts.overdue} overdue · {counts.today} today · {counts.upcoming} upcoming
          </span>
        )
      }
    >
      {/* Cap at ~5 visible rows (rows ≈ 42px); the body scrolls beyond that. The ChartCard title +
          counts header stays fixed above this scroll region. */}
      <div className="max-h-56 overflow-auto">
        <table className="w-full text-sm">
          <tbody>
            {rows.map((row) => {
              const style = STATUS_STYLE[row.status];
              return (
                <tr key={row.id} className="border-b border-border/60 last:border-0">
                  <td className="py-2.5 pr-3 align-middle">
                    <span
                      className={cn(
                        "inline-flex items-center gap-1.5 rounded-full px-2 py-0.5 text-[11px] font-semibold",
                        style.className,
                      )}
                    >
                      <span className={cn("h-1.5 w-1.5 rounded-full", style.dot)} aria-hidden />
                      {style.label}
                    </span>
                  </td>
                  <td className="py-2.5 pr-3 align-middle font-semibold text-card-foreground">
                    {row.company}
                  </td>
                  <td className="py-2.5 pr-3 align-middle text-muted-foreground">
                    {row.description}
                  </td>
                  <td className="py-2.5 pl-3 text-right align-middle whitespace-nowrap text-muted-foreground tabular-nums">
                    {formatDue(row.dueDate)}
                  </td>
                </tr>
              );
            })}
            {notShown > 0 ? (
              <tr>
                {/* Spans the row's four columns. Muted and non-interactive on purpose: this states
                    what the list is NOT showing, and is not itself a follow-up to act on. */}
                <td colSpan={4} className="py-2.5 text-center text-xs text-muted-foreground">
                  +{notShown} more not shown
                </td>
              </tr>
            ) : null}
          </tbody>
        </table>
      </div>
    </ChartCard>
  );
}
