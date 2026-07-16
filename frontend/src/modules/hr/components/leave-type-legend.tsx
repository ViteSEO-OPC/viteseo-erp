import type { LeaveType } from "@/modules/hr/types/leave";

// Leave-type colour legend (dashboard style: bordered pills with a colour dot).
// Renders nothing when there are no leave types.
export function LeaveTypeLegend({ leaveTypes }: { leaveTypes?: LeaveType[] }) {
  if (!leaveTypes || leaveTypes.length === 0) return null;

  return (
    <div>
      <p className="text-label font-bold uppercase tracking-wide text-muted-foreground">Leave types</p>
      <div className="mt-2 flex flex-wrap gap-2">
        {leaveTypes.map((lt) => (
          <span
            key={lt.id}
            className="inline-flex items-center gap-1.5 rounded-full border border-border bg-card px-2.5 py-1 text-xs font-medium text-foreground"
          >
            <span className="size-2 rounded-full" style={{ backgroundColor: lt.color ?? "#6b7280" }} />
            {lt.name}
          </span>
        ))}
      </div>
    </div>
  );
}
