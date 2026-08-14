import Link from "next/link";
import { ArrowRight, SlidersHorizontal } from "lucide-react";
import { cn } from "@/modules/crm/lib/utils";
import { Card, CardContent } from "@/modules/crm/components/ui/card";
import { PriorityChip } from "@/modules/crm/components/shared/status-badge/priority-chip";
import { ProgressBar } from "@/modules/crm/components/shared/progress-bar/progress-bar";
import { CardFooterMeta } from "@/modules/crm/features/dashboard/components/card-footer-meta";
import type { PriorityBucket } from "@/modules/crm/features/dashboard/types/dashboard-types";

// Soft priority border per tone — the accent hue at 50% alpha (exact Figma values) so it groups
// the card without dominating (colour still reads fully in the chip + progress bar). All three
// share the identical 1px border + neutral shadow treatment; only the hue differs.
const priorityBorder: Record<string, string> = {
  high: "border-dashboard-priority-high-border",
  medium: "border-dashboard-priority-medium-border",
  low: "border-dashboard-priority-low-border",
};

// Selected-filter emphasis, per tone. Both halves reuse the SAME `-border` token the resting card
// is already outlined with, so the active state is the card's own hue turned up rather than a new
// colour — and the ring and the shadow read as ONE treatment in that hue, not a coloured edge over
// a grey drop shadow.
//
// The shadow is the same token at `/25`. Tailwind resolves that with `color-mix(… , transparent)`,
// so it compounds with the token's own 50% alpha to land near 12% — a tint that colours the cast
// shadow without becoming a glow. Left ungraded it would sit at the full 50% and read as a halo.
//
// A ring — not `border-2` — is what thickens the edge, and that is deliberate. The cards are grid
// items whose row height is set by the tallest card's intrinsic height, so growing the border to
// 2px would add 2px to every card in the row the moment a filter is applied. A ring is painted as
// a box-shadow: it sits flush outside the existing 1px border, reads as a thicker edge, and takes
// part in no layout at all — the card's box is byte-identical selected or not. This is the same
// `shadow-md ring-2` emphasis idiom the Calendar board card already uses for its lifted state.
const priorityActive: Record<string, string> = {
  high: "ring-dashboard-priority-high-border shadow-dashboard-priority-high-border/25",
  medium: "ring-dashboard-priority-medium-border shadow-dashboard-priority-medium-border/25",
  low: "ring-dashboard-priority-low-border shadow-dashboard-priority-low-border/25",
};

type Props = {
  bucket: PriorityBucket;
  /**
   * How the card's click reads to the user.
   *  - "navigate" (default) → "View leads →". Dashboard: the entry point into the workspace.
   *  - "filter"             → "Click to filter". Leads: the same href simply narrows the list.
   * Both render the identical card and link to the identical target.
   */
  action?: "navigate" | "filter";
  /**
   * True when THIS bucket is the list's current `priority` filter, so the card shows the selected
   * state. Owned by the caller: the Leads page derives it from the one URL-driven filter value the
   * list already runs on, so there is no second source of truth for "what is selected".
   * Defaults to false, which is why the Dashboard's `action="navigate"` cards are untouched.
   */
  isActive?: boolean;
};

export function PriorityCard({ bucket, action = "navigate", isActive = false }: Props) {
  // Same card, same data, same target — only the affordance wording differs, because on the
  // Dashboard the click leaves for the workspace while on Leads it filters the list in place.
  const actionLabel = action === "filter" ? "Click to filter" : "View leads";
  const ActionIcon = action === "filter" ? SlidersHorizontal : ArrowRight;

  return (
    <Link
      // Drill down on the SAME column the card counts and chips (`leads.priority`), so the rows
      // you land on always match the card you clicked. `?priority=` is the list's existing,
      // already-validated filter — no new param.
      href={`/zerem/leads?priority=${bucket.priority}`}
      className="group block h-full rounded-xl transition duration-200 hover:-translate-y-0.5 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
    >
      <Card
        className={cn(
          "h-full shadow-card ring-0 transition-shadow duration-200 group-hover:shadow-md [--card-spacing:--spacing(6)]",
          "border",
          priorityBorder[bucket.priority],
          // Selected filter: tone ring + a lifted shadow tinted to that same tone.
          // `transition-shadow` above already covers both (a ring IS a box-shadow), so selecting a
          // card eases in rather than snapping.
          isActive && ["shadow-md ring-2", priorityActive[bucket.priority]],
        )}
      >
        <CardContent className="flex flex-1 flex-col">
          {/* Per the approved design: count and chip sit together on the left, with the drill-down
              affordance right-aligned on the same line. The whole card remains the link target. */}
          <div className="flex items-center justify-between gap-3">
            <div className="flex items-center gap-3">
              <span className="text-display font-extrabold leading-none text-card-foreground">
                {bucket.count}
              </span>
              {/* The chip now reflects the lead's own `priority` column — the same value this card
                  counts and drills down on. It previously showed a tone mapped from
                  `website_status`, which is why a card chipped "High Priority" could open a list
                  of Medium-priority leads. */}
              <PriorityChip priority={bucket.priority} variant="full" />
            </div>
            <span className="inline-flex shrink-0 items-center gap-1.5 text-sm font-medium text-secondary-foreground transition-colors group-hover:text-primary-accent">
              {actionLabel}
              <ActionIcon className="h-3.5 w-3.5" aria-hidden />
            </span>
          </div>

          <h3 className="mt-3 text-base font-semibold text-card-foreground">
            {bucket.title}
          </h3>

          <p className="mt-1 flex-1 text-sm text-secondary-foreground">
            {bucket.description}
          </p>

          <ProgressBar
            value={bucket.totalPct}
            tone={bucket.priority}
            className="mt-4"
          />

          <CardFooterMeta
            items={[
              `AVG LEAD SCORE ${bucket.avgScore}`,
              `${bucket.totalPct}% OF LEADS`,
            ]}
          />
        </CardContent>
      </Card>
    </Link>
  );
}
