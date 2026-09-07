import { cn } from "@/lib/utils";

/**
 * Small structural primitives for dense screens: a micro-label above a heading,
 * and hairline-separated rows instead of a grid of bordered cards.
 *
 * These arrived with the Accounting module, whose pages are built from them.
 * The build they came from styled them in that product's brand — bracketed
 * labels, a heavy display face, uppercase headings. None of that came across.
 * Same names and same props, so the pages compile unchanged; the look is this
 * product's own, because a client opening Accounting next to TeamOra must not
 * see two companies.
 */

/**
 * A micro-label above a heading — the section's category, not its name.
 * Uppercase and widely tracked so it reads as a label rather than a first line.
 */
export function Eyebrow({
  children,
  className,
}: {
  children: React.ReactNode;
  className?: string;
}) {
  return (
    <span
      className={cn(
        "inline-block text-label font-semibold uppercase tracking-[0.14em] text-muted-foreground",
        className,
      )}
    >
      {children}
    </span>
  );
}

/**
 * A section heading inside a page: optional eyebrow, the title, and optionally
 * something pinned to the far right (a total, a link, a filter).
 */
export function SectionHeading({
  eyebrow,
  title,
  meta,
  className,
}: {
  eyebrow?: string;
  title: React.ReactNode;
  /** Right-aligned trailing content — a total, a link, a filter. */
  meta?: React.ReactNode;
  className?: string;
}) {
  return (
    <div
      className={cn(
        "flex flex-col gap-2 sm:flex-row sm:items-end sm:justify-between sm:gap-6",
        className,
      )}
    >
      <div className="min-w-0">
        {eyebrow ? <Eyebrow className="mb-1.5">{eyebrow}</Eyebrow> : null}
        <h2 className="text-h3 font-bold tracking-tight text-foreground">{title}</h2>
      </div>
      {meta ? (
        <div className="shrink-0 text-caption text-muted-foreground">{meta}</div>
      ) : null}
    </div>
  );
}

/**
 * A hairline-separated list. Cheaper vertically than the same rows in bordered
 * cards, which is why the ledger screens use it for anything longer than a
 * handful of entries.
 */
export function RowList({
  children,
  className,
}: {
  children: React.ReactNode;
  className?: string;
}) {
  return (
    <div
      className={cn(
        "flex flex-col divide-y divide-border border-y border-border",
        className,
      )}
    >
      {children}
    </div>
  );
}

/**
 * One row of a `RowList`. The index renders as a zero-padded ordinal and is
 * hidden from assistive tech: it numbers a position on screen rather than
 * naming anything, so a screen reader announcing "zero three" would be noise.
 */
export function Row({
  index,
  children,
  className,
}: {
  /** 0-based; rendered as 01, 02, … Omit for an unnumbered row. */
  index?: number;
  children: React.ReactNode;
  className?: string;
}) {
  return (
    <div className={cn("flex items-center gap-4 py-3", className)}>
      {index !== undefined ? (
        <span
          aria-hidden
          className="w-7 shrink-0 text-caption font-semibold tabular-nums text-muted-foreground"
        >
          {String(index + 1).padStart(2, "0")}
        </span>
      ) : null}
      <div className="min-w-0 flex-1">{children}</div>
    </div>
  );
}

/**
 * A vertical column ruler — separates the two halves of an asymmetric grid
 * without drawing a box around either. Applies from `md` up, where there are
 * actually two columns to divide.
 */
export function ColumnRule({
  children,
  className,
}: {
  children: React.ReactNode;
  className?: string;
}) {
  return (
    <div className={cn("md:border-l md:border-border md:pl-8", className)}>
      {children}
    </div>
  );
}
