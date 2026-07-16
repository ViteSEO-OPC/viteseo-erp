import { cn } from "@/lib/utils";

// Maps a status string to a color, per the Style Guide usage notes:
// green = approved/paid/positive · orange = pending · red = rejected/declined ·
// blue = in progress. Badges are pills (full-radius) with white text on a solid
// colored fill.
const STATUS_STYLES: Record<string, string> = {
  // green — positive / terminal-good
  approved: "bg-success",
  paid: "bg-success",
  present: "bg-success",
  active: "bg-success",
  fulfilled: "bg-success",
  resolved: "bg-success",
  done: "bg-success",
  published: "bg-success",
  accepted: "bg-success",
  completed: "bg-success",
  disbursed: "bg-success",
  // amber — awaiting action
  pending: "bg-warning",
  processing: "bg-warning",
  late: "bg-warning",
  offer_out: "bg-warning",
  recorded: "bg-warning",
  scheduled: "bg-warning",
  // red — negative
  rejected: "bg-danger",
  declined: "bg-danger",
  absent: "bg-danger",
  cancelled: "bg-danger",
  // blue — in flight
  in_progress: "bg-info",
  under_review: "bg-info",
  on_leave: "bg-info",
  computed: "bg-info",
  // grey — inactive / neutral
  draft: "bg-muted-foreground",
  closed: "bg-muted-foreground",
  archived: "bg-muted-foreground",
};

export function StatusBadge({
  status,
  label,
  className,
}: {
  status: string;
  /** Optional display text; defaults to the humanized status. */
  label?: string;
  className?: string;
}) {
  const key = status.toLowerCase();
  const color = STATUS_STYLES[key] ?? "bg-muted-foreground";
  const text = label ?? key.replace(/_/g, " ");

  return (
    <span
      className={cn(
        "inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-semibold capitalize text-white",
        color,
        className,
      )}
    >
      {text}
    </span>
  );
}
