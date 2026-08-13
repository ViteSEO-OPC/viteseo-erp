import { cn } from "@/core/lib/utils";

/**
 * Loading placeholders for the module.
 *
 * These replace the "Loading…" strings the views used to render. A line of text
 * says something is happening but not what is coming, so every load looked the
 * same and the layout jumped when the real content arrived. A skeleton holds
 * the shape of the thing being fetched, which keeps the page still.
 *
 * Everything is built from the `Skeleton` block below so there is one pulse
 * rhythm and one colour across the module. Compositions take a `count`/`rows`
 * so a caller can match the size of what it is actually waiting for.
 *
 * Marked `aria-hidden` and paired with an `aria-busy` region by the callers
 * that own a live region: a screen reader should hear "loading", not a
 * description of twelve grey rectangles.
 */

/** The base block. Everything else is composed of these. */
export function Skeleton({ className, ...props }: React.ComponentProps<"div">) {
  return (
    <div
      aria-hidden
      className={cn("animate-pulse rounded-md bg-line", className)}
      {...props}
    />
  );
}

/** Paragraph-ish lines. The last one is short, the way real text ends. */
export function SkeletonText({
  lines = 3,
  className,
}: {
  lines?: number;
  className?: string;
}) {
  return (
    <div className={cn("space-y-2", className)}>
      {Array.from({ length: lines }).map((_, i) => (
        <Skeleton
          key={i}
          className={cn("h-3.5", i === lines - 1 ? "w-2/5" : "w-full")}
        />
      ))}
    </div>
  );
}

/** A page's title block — heading plus its one-line description. */
export function SkeletonPageHeader({ className }: { className?: string }) {
  return (
    <div className={cn("space-y-3", className)}>
      <Skeleton className="h-8 w-56" />
      <Skeleton className="h-4 w-80 max-w-full" />
    </div>
  );
}

/** The KPI row several dashboards open with. */
export function SkeletonStatCards({
  count = 4,
  className,
}: {
  count?: number;
  className?: string;
}) {
  return (
    <div
      className={cn(
        "grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4",
        className,
      )}
    >
      {Array.from({ length: count }).map((_, i) => (
        <div key={i} className="rounded-xl border border-line bg-surface p-5">
          <Skeleton className="h-3.5 w-24" />
          <Skeleton className="mt-3 h-7 w-16" />
          <Skeleton className="mt-3 h-3 w-32" />
        </div>
      ))}
    </div>
  );
}

/**
 * Table rows. `columns` widths vary so the block reads as a table rather than a
 * grid of identical bars — the first column is the wide one, as it is in every
 * table here.
 */
export function SkeletonTableRows({
  rows = 5,
  columns = 4,
  className,
}: {
  rows?: number;
  columns?: number;
  className?: string;
}) {
  return (
    <div className={cn("divide-y divide-line", className)}>
      {Array.from({ length: rows }).map((_, r) => (
        <div key={r} className="flex items-center gap-4 px-4 py-3.5">
          {Array.from({ length: columns }).map((_, c) => (
            <Skeleton
              key={c}
              className={cn("h-4", c === 0 ? "flex-[2]" : "flex-1")}
            />
          ))}
        </div>
      ))}
    </div>
  );
}

/** A single card in a grid of projects/workspaces/groups. */
export function SkeletonCard({ className }: { className?: string }) {
  return (
    <div
      className={cn(
        "space-y-4 rounded-xl border border-line bg-surface p-5",
        className,
      )}
    >
      <div className="flex items-start justify-between gap-3">
        <Skeleton className="h-5 w-2/5" />
        <Skeleton className="h-5 w-14 rounded-full" />
      </div>
      <SkeletonText lines={2} />
      <div className="flex items-center gap-2 pt-1">
        <Skeleton className="h-7 w-7 rounded-full" />
        <Skeleton className="h-7 w-7 rounded-full" />
        <Skeleton className="h-3.5 w-20" />
      </div>
    </div>
  );
}

export function SkeletonCardGrid({
  count = 6,
  className,
}: {
  count?: number;
  className?: string;
}) {
  return (
    <div
      className={cn(
        "grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3",
        className,
      )}
    >
      {Array.from({ length: count }).map((_, i) => (
        <SkeletonCard key={i} />
      ))}
    </div>
  );
}

/** A vertical list of rows — activity feeds, notifications, the claim pool. */
export function SkeletonList({
  rows = 4,
  withAvatar = true,
  className,
}: {
  rows?: number;
  withAvatar?: boolean;
  className?: string;
}) {
  return (
    <div className={cn("space-y-3", className)}>
      {Array.from({ length: rows }).map((_, i) => (
        <div key={i} className="flex items-start gap-3">
          {withAvatar && <Skeleton className="h-8 w-8 shrink-0 rounded-full" />}
          <div className="min-w-0 flex-1 space-y-2">
            <Skeleton className="h-3.5 w-3/5" />
            <Skeleton className="h-3 w-1/4" />
          </div>
        </div>
      ))}
    </div>
  );
}

/** Kanban columns, each holding a few cards. */
export function SkeletonBoard({
  columns = 4,
  cards = 3,
  className,
}: {
  columns?: number;
  cards?: number;
  className?: string;
}) {
  return (
    <div className={cn("flex gap-4 overflow-hidden", className)}>
      {Array.from({ length: columns }).map((_, c) => (
        <div key={c} className="w-72 shrink-0 space-y-3">
          <div className="flex items-center gap-2">
            <Skeleton className="h-4 w-24" />
            <Skeleton className="h-4 w-6 rounded-full" />
          </div>
          {Array.from({ length: cards }).map((_, i) => (
            <div
              key={i}
              className="space-y-3 rounded-xl border border-line bg-surface p-4"
            >
              <Skeleton className="h-4 w-4/5" />
              <Skeleton className="h-3 w-2/5" />
              <div className="flex items-center gap-2">
                <Skeleton className="h-6 w-6 rounded-full" />
                <Skeleton className="h-3 w-16" />
              </div>
            </div>
          ))}
        </div>
      ))}
    </div>
  );
}

/** Form fields inside a modal — a label above an input, repeated. */
export function SkeletonFormFields({
  fields = 3,
  className,
}: {
  fields?: number;
  className?: string;
}) {
  return (
    <div className={cn("space-y-4", className)}>
      {Array.from({ length: fields }).map((_, i) => (
        <div key={i} className="space-y-2">
          <Skeleton className="h-3.5 w-24" />
          <Skeleton className="h-10 w-full rounded-lg" />
        </div>
      ))}
    </div>
  );
}

/**
 * The body of the task modal while the task is still being fetched.
 *
 * Deliberately shaped like the real thing: same padding, same
 * `1fr / 320px` split. It fills whatever height the shell gives it — the shell
 * pins itself to its 90vh cap while loading, which is where a normal task's
 * content lands too, so the panel opens at the size it will keep and the
 * content swaps in underneath without the modal resizing under the cursor.
 *
 * If ViewTaskBody's layout changes, this should follow it.
 */
export function SkeletonModalBody({ className }: { className?: string }) {
  return (
    <div
      className={cn(
        "grid min-h-full grid-cols-1 gap-6 p-4 sm:p-6 md:grid-cols-[1fr_320px] md:gap-8",
        className,
      )}
    >
      {/* Left: title, its chips, the description, then the sub-task list. */}
      <div className="min-w-0 space-y-5">
        <Skeleton className="h-7 w-3/5" />
        <div className="flex flex-wrap gap-2">
          <Skeleton className="h-6 w-20 rounded-full" />
          <Skeleton className="h-6 w-24 rounded-full" />
          <Skeleton className="h-6 w-16 rounded-full" />
        </div>
        <SkeletonText lines={3} />
        <div className="space-y-3 pt-2">
          <Skeleton className="h-4 w-28" />
          {Array.from({ length: 3 }).map((_, i) => (
            <div
              key={i}
              className="flex items-start gap-3 rounded-xl border border-line px-3 py-2.5"
            >
              <Skeleton className="h-9 w-9 shrink-0 rounded-full" />
              <div className="min-w-0 flex-1 space-y-2">
                <Skeleton className="h-3.5 w-1/2" />
                <Skeleton className="h-3 w-1/4" />
              </div>
            </div>
          ))}
        </div>
      </div>

      {/* Right: the fixed-width details panel. */}
      <div className="space-y-4">
        <Skeleton className="h-4 w-24" />
        <SkeletonFormFields fields={4} />
      </div>
    </div>
  );
}

/**
 * A whole page: header, then whatever the page's main block is. Used by the
 * route-level guards that used to render a bare "Loading...".
 */
export function SkeletonPage({
  children,
  className,
}: {
  children?: React.ReactNode;
  className?: string;
}) {
  return (
    <div
      aria-busy="true"
      aria-live="polite"
      aria-label="Loading"
      className={cn("px-4 py-4 sm:px-6 lg:px-8", className)}
    >
      <SkeletonPageHeader />
      <div className="mt-6">{children ?? <SkeletonCardGrid />}</div>
    </div>
  );
}
