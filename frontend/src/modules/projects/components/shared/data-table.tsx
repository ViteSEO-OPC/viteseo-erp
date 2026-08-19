import type { ReactNode } from "react";
import { cn } from "@/core/lib/utils";

/**
 * The module's one table look.
 *
 * PMS grew four separate table idioms — the `<table>` in the admin queues, the
 * CSS-grid list on the board and the dashboards, the members list, and the
 * comparison table in the capacity modal. They disagreed on the frame (Card vs.
 * bare border, `rounded-2xl` vs. `rounded-xl`), on the header (`text-ink` vs.
 * `text-ink-soft`, two padding pairs), and on whether rows were zebra-striped or
 * divided by a hairline. Those are the same widget, so they now read from the
 * same constants and only differ where the data actually differs.
 *
 * Two decisions worth stating, because they're what the tables converged ON:
 *
 *  · **The frame is the table's own.** A table already draws a bordered,
 *    rounded, white box, so wrapping one in a `<Card>` produced a container
 *    inside an identical container — two borders and two radii, 1px apart, on a
 *    canvas that is only a shade off white. `TableFrame` matches `Card`'s
 *    surface exactly, so callers drop the Card and lose nothing.
 *
 *  · **Hairlines, not zebra.** Half the tables striped rows and half divided
 *    them. Striping paints roughly half the rows `--color-surface-alt`, which on
 *    the warm paper canvas reads as "these rows are selected" rather than as a
 *    ruling. The divider is the quieter of the two and the one already used by
 *    every task list, so it wins.
 *
 * Rows and cells come as class strings rather than components because half the
 * callers are real `<tr>`/`<td>` and half are CSS-grid `<div>`s — a shared
 * component would have to abstract over both and would be harder to read than
 * the class name it replaced.
 */

/**
 * The bordered, rounded white box a table sits in. Mirrors `Card`'s surface.
 *
 * No `overflow` of its own: the scrolling tables set `overflow-auto` here, and
 * two overflow utilities on one element resolve by stylesheet order rather than
 * class order, so whichever won would be a coin toss. `TableFrame` adds the
 * clip for the callers that don't scroll.
 */
export const TABLE_FRAME = "rounded-xl border border-line bg-surface shadow-sm";

/** Header band — a `<thead> <tr>` or the sticky header row of a grid table. */
export const TABLE_HEAD = "border-b border-line bg-surface-alt";

/** Type and colour of a column label, wherever the label lives. */
export const TABLE_HEAD_TEXT =
  "text-xs font-bold uppercase tracking-wide text-ink-soft";

/** One padding pair per row kind, so every table keeps the same rhythm. */
export const TABLE_HEAD_PAD = "px-5 py-3";
export const TABLE_CELL_PAD = "px-5 py-3.5";

/** A `<th>`. */
export const TABLE_HEAD_CELL = `${TABLE_HEAD_PAD} text-left ${TABLE_HEAD_TEXT}`;

/** A grid table's header bar — the band, its padding and its label type in one. */
export const TABLE_HEAD_BAR = `${TABLE_HEAD} ${TABLE_HEAD_PAD} ${TABLE_HEAD_TEXT}`;

/** A `<td>`. */
export const TABLE_CELL = `${TABLE_CELL_PAD} text-sm text-ink`;

/** A `<tr>`. Rows are separated by a hairline and lift slightly on hover. */
export const TABLE_ROW =
  "border-b border-line last:border-b-0 transition-colors hover:bg-black/[0.02]";

/** A grid table's row. Pair with `TABLE_BODY` on the wrapper for the hairlines. */
export const TABLE_GRID_ROW = `${TABLE_CELL_PAD} text-left transition-colors hover:bg-black/[0.02]`;

/** Wrapper for a grid table's rows — supplies the hairlines `TABLE_ROW` carries. */
export const TABLE_BODY = "divide-y divide-line";

/** "No results" copy, sized to sit where a few rows would have been. */
export const TABLE_EMPTY = "px-5 py-10 text-center text-sm text-ink-soft";

/**
 * The frame around a table.
 *
 * `scroll` puts the overflow on the horizontal axis, which is what a wide
 * `<table>` with a `min-w-*` needs. Grid tables that scroll vertically inside a
 * capped height set that themselves and pass `scroll={false}`.
 */
export function TableFrame({
  children,
  className,
  scroll = true,
}: {
  children: ReactNode;
  className?: string;
  scroll?: boolean;
}) {
  return (
    <div className={cn(TABLE_FRAME, "overflow-hidden", className)}>
      {scroll ? <div className="overflow-x-auto">{children}</div> : children}
    </div>
  );
}

/**
 * A table-shaped message — the error and empty states that used to be rendered
 * as a bare `<Card>` and so didn't line up with the table they replaced.
 */
export function TableMessage({
  title,
  children,
  tone = "muted",
  className,
}: {
  title?: ReactNode;
  children?: ReactNode;
  tone?: "muted" | "danger";
  className?: string;
}) {
  return (
    <div
      className={cn(TABLE_FRAME, "overflow-hidden px-5 py-10 text-center", className)}
    >
      {title && (
        <p
          className={cn(
            "font-semibold",
            tone === "danger" ? "text-danger" : "text-ink",
          )}
        >
          {title}
        </p>
      )}
      {children && (
        <p className={cn("text-sm text-ink-soft", title && "mt-1")}>
          {children}
        </p>
      )}
    </div>
  );
}
