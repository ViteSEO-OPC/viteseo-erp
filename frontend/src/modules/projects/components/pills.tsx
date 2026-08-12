import { Badge } from "@/core/ui";
import { cn } from "@/core/lib/utils";

/**
 * The module's pill primitives. Every chip in Projects is a `Badge` underneath,
 * so they all share one set of metrics (`rounded-full px-2.5 py-0.5 text-xs
 * font-bold`) and land on the same 22px height wherever they sit next to each
 * other — status next to priority next to project next to workload.
 *
 * The one deliberate split is casing: label pills (status, priority, OVERDUE)
 * are upper-cased by `Badge`, while pills that carry a *name* someone typed
 * (a project, a group) keep that name's own casing. Shouting "WEBSITE REVAMP"
 * back at the user is not consistency, it's just loud.
 */

/**
 * Name pills hold user-typed text of unbounded length, so they always shrink
 * and ellipsize rather than push their row apart. `Badge` ships `w-fit
 * shrink-0 whitespace-nowrap`, which is right for a fixed label like HIGH but
 * exactly wrong here — `min-w-0 shrink` undoes it so flexbox can squeeze the
 * pill, and `max-w-full` keeps it inside a wrapping container. The full name
 * stays reachable via the `title` tooltip.
 *
 * A container only yields the space if it can shrink too: call sites put
 * `min-w-0` on the wrapper and `shrink-0` on the pill's neighbours.
 */
const NAME_PILL = "normal-case tracking-normal min-w-0 max-w-full shrink";

/**
 * The label itself does the ellipsizing. `Badge` is `inline-flex`, and
 * `text-overflow` doesn't apply to a flex container's anonymous text — it has
 * to be its own block-level child.
 */
function PillLabel({ children }: { children: React.ReactNode }) {
  return <span className="min-w-0 truncate">{children}</span>;
}

/** Fallback for projects created before a colour was assigned. */
export const DEFAULT_PROJECT_COLOR = "#6366f1";

/**
 * A project's name, tinted with the project's own colour. Used anywhere a task
 * needs to say which project it belongs to — dashboards, list/kanban boards.
 */
export function ProjectPill({
  name,
  color,
  className,
}: {
  name: string;
  color?: string | null;
  className?: string;
}) {
  const tint = color ?? DEFAULT_PROJECT_COLOR;
  return (
    <Badge
      // `15` is the hex alpha suffix — a 8% wash of the project's colour.
      style={{ backgroundColor: `${tint}15`, color: tint }}
      title={name}
      className={cn(NAME_PILL, className)}
    >
      <PillLabel>{name}</PillLabel>
    </Badge>
  );
}

/**
 * A group's name with its colour as a leading dot (the group's colour is the
 * dot, not the fill — that's how groups read everywhere else in the module).
 */
export function GroupPill({
  name,
  color,
  className,
}: {
  name: string;
  color?: string | null;
  className?: string;
}) {
  return (
    <Badge
      tone="accent"
      title={name}
      className={cn(NAME_PILL, "border-brand", className)}
    >
      {color && (
        <span
          className="h-2 w-2 shrink-0 rounded-full"
          style={{ backgroundColor: color }}
          aria-hidden
        />
      )}
      <PillLabel>{name}</PillLabel>
    </Badge>
  );
}

/** Any pill whose content is a name rather than a status label. */
export function NamePill({
  children,
  tone,
  title,
  className,
}: {
  children: React.ReactNode;
  tone?: React.ComponentProps<typeof Badge>["tone"];
  /** Tooltip carrying the full text once the pill has to ellipsize it. */
  title?: string;
  className?: string;
}) {
  return (
    <Badge tone={tone} title={title} className={cn(NAME_PILL, className)}>
      <PillLabel>{children}</PillLabel>
    </Badge>
  );
}

const COUNT_TONES = {
  brand: "bg-brand-strong text-white",
  danger: "bg-danger-soft text-danger",
  neutral: "bg-surface text-ink-soft",
  // Solid rather than soft-on-soft: this one sits ON a tinted bar, and a
  // `bg-warning-soft` chip on a `bg-warning-soft` header is invisible — which is
  // what happened to the danger bubble it replaced. Ink on amber is 8.9:1;
  // white on amber would have been 2.1:1.
  warning: "bg-warning text-ink",
} as const;

/**
 * Numeric counter bubble — active filters, unread items, cards in a column.
 * Square-ish at one digit and grows sideways past that, at the same type size
 * as the pills it sits beside.
 */
export function CountBubble({
  children,
  tone = "brand",
  className,
}: {
  children: React.ReactNode;
  tone?: keyof typeof COUNT_TONES;
  className?: string;
}) {
  return (
    <span
      className={cn(
        "flex h-5 min-w-5 items-center justify-center rounded-full px-1.5 text-xs font-bold",
        COUNT_TONES[tone],
        className,
      )}
    >
      {children}
    </span>
  );
}
