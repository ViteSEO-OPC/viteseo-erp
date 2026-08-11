import { ProgressBar } from "@/core/components/progress-bar";

/** Days without a pointless ".0": 45, 7.5. */
function days(n: number): string {
  return Number.isInteger(n) ? String(n) : n.toFixed(1);
}

export interface LeaveTypeRow {
  key: string;
  name: string;
  remaining: number;
  entitled: number;
}

/**
 * The employee's leave standing in a single card: the headline balance with its
 * progress bar, the pending-request count, then the per-type breakdown — three
 * sections split by rules.
 *
 * The headline is the shared pool in shared mode and the total across every paid
 * type otherwise, which is why it takes a label and plain numbers rather than
 * reading the summary itself.
 */
export function LeaveSummaryCard({
  featuredLabel,
  featured,
  emptyNote = "No balance for this year",
  pending,
  types,
  showCredits,
  note,
}: {
  featuredLabel: string;
  /** null when the employee has no credits on record for the year. */
  featured: { entitled: number; remaining: number; used: number } | null;
  emptyNote?: string;
  pending: number;
  types: LeaveTypeRow[];
  /** false = names only (shared mode, where every type spends one pool). */
  showCredits: boolean;
  note?: string;
}) {
  const pct =
    featured && featured.entitled > 0
      ? (featured.remaining / featured.entitled) * 100
      : 0;

  return (
    <div className="flex flex-col gap-6 rounded-card border border-border bg-card p-6">
      <section>
        <p className="text-label font-bold uppercase tracking-wide text-muted-foreground">
          {featuredLabel}
        </p>
        {featured ? (
          <>
            <p className="mt-2">
              <span className="text-h2 font-bold text-foreground">
                {days(featured.remaining)}
              </span>
              <span className="text-caption text-muted-foreground">
                {" "}
                / {days(featured.entitled)} days
              </span>
            </p>
            <ProgressBar percent={pct} className="mt-4" />
            <div className="mt-2 flex justify-between text-caption">
              <span className="font-bold text-brand">{Math.round(pct)}% Remaining</span>
              <span className="text-muted-foreground">{days(featured.used)} used</span>
            </div>
          </>
        ) : (
          <>
            <p className="mt-2 text-h2 font-bold text-foreground">—</p>
            <p className="mt-2 text-caption text-muted-foreground">{emptyNote}</p>
          </>
        )}
      </section>

      <div className="h-px bg-border" />

      <section>
        <p className="text-label font-bold uppercase tracking-wide text-muted-foreground">
          Pending Requests
        </p>
        <p className="mt-2 text-h1 font-bold text-foreground">{pending}</p>
        <p className="mt-3 text-caption text-muted-foreground">Awaiting HR decision</p>
      </section>

      <div className="h-px bg-border" />

      <section>
        <p className="text-label font-bold uppercase tracking-wide text-muted-foreground">
          Leave Types
        </p>
        <div className="mt-4 max-h-64 space-y-3 overflow-y-auto pr-1">
          {types.length === 0 ? (
            <p className="text-sm text-muted-foreground">No leave types.</p>
          ) : (
            types.map((t) => (
              <div key={t.key} className="flex items-center justify-between gap-2">
                <span className="truncate text-sm text-muted-foreground">{t.name}</span>
                {showCredits && (
                  <span className="shrink-0 text-sm font-bold text-foreground">
                    {t.remaining.toFixed(1)} / {t.entitled.toFixed(1)} days
                  </span>
                )}
              </div>
            ))
          )}
        </div>
        {note && <p className="mt-3 text-caption text-muted-foreground">{note}</p>}
      </section>
    </div>
  );
}
