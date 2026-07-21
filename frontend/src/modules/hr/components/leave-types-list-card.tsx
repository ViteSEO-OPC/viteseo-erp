/**
 * The "Leave Types" summary card beside the featured balance card. In not-shared
 * mode it lists each type's own credits; in shared mode it lists the type names
 * only (they all draw from the single pool), with a note.
 */
export function LeaveTypesListCard({
  items,
  showCredits,
  note,
}: {
  items: { key: string; name: string; remaining: number; entitled: number }[];
  /** false = names only (shared mode) */
  showCredits: boolean;
  note?: string;
}) {
  return (
    <div className="rounded-card border border-border bg-card p-5">
      <p className="text-label font-bold uppercase tracking-wide text-muted-foreground">
        Leave Types
      </p>
      <div className="mt-4 max-h-56 space-y-3 overflow-y-auto pr-1">
        {items.length === 0 ? (
          <p className="text-sm text-muted-foreground">No leave types.</p>
        ) : (
          items.map((it) => (
            <div key={it.key} className="flex items-center justify-between gap-2">
              <span className="truncate text-sm text-muted-foreground">{it.name}</span>
              {showCredits && (
                <span className="shrink-0 text-sm font-bold text-foreground">
                  {it.remaining} / {it.entitled} days
                </span>
              )}
            </div>
          ))
        )}
      </div>
      {note && <p className="mt-3 text-caption text-muted-foreground">{note}</p>}
    </div>
  );
}
