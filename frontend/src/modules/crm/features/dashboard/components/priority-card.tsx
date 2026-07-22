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

type Props = {
  bucket: PriorityBucket;
  /**
   * How the card's click reads to the user.
   *  - "navigate" (default) → "View leads →". Dashboard: the entry point into the workspace.
   *  - "filter"             → "Click to filter". Leads: the same href simply narrows the list.
   * Both render the identical card and link to the identical target.
   */
  action?: "navigate" | "filter";
};

export function PriorityCard({ bucket, action = "navigate" }: Props) {
  // Same card, same data, same target — only the affordance wording differs, because on the
  // Dashboard the click leaves for the workspace while on Leads it filters the list in place.
  const actionLabel = action === "filter" ? "Click to filter" : "View leads";
  const ActionIcon = action === "filter" ? SlidersHorizontal : ArrowRight;

  return (
    <Link
      href={`/zerem/leads?website_status=${bucket.websiteStatus}`}
      className="group block h-full rounded-xl transition duration-200 hover:-translate-y-0.5 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
    >
      <Card
        className={cn(
          "h-full shadow-card ring-0 transition-shadow duration-200 group-hover:shadow-md [--card-spacing:--spacing(6)]",
          "border",
          priorityBorder[bucket.tone],
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
              {/* "High Priority" per the approved design. Note the chip reflects the card's
                  website-condition tone, not the lead's `priority` column — see the Lead Priority
                  Overview note in the KB. Wording is fixed by the approved design. */}
              <PriorityChip priority={bucket.tone} variant="full" />
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
            value={bucket.pipelinePct}
            tone={bucket.tone}
            className="mt-4"
          />

          <CardFooterMeta
            items={[
              `AVG LEAD SCORE ${bucket.avgScore}`,
              `${bucket.pipelinePct}% OF PIPELINE`,
            ]}
          />
        </CardContent>
      </Card>
    </Link>
  );
}
