import Link from "next/link";
import { cn } from "@/modules/crm/lib/utils";
import { Card, CardContent } from "@/modules/crm/components/ui/card";
import { PriorityChip } from "@/modules/crm/components/shared/status-badge/priority-chip";
import { ProgressBar } from "@/modules/crm/components/shared/progress-bar/progress-bar";
import { CardFooterMeta } from "@/modules/crm/features/dashboard/components/card-footer-meta";
import type { LeadPriorityBucket } from "@/modules/crm/features/leads/hooks/use-leads-priority-summary";

// Leads-owned card so this surface stays fully decoupled from the Dashboard's Website Opportunity
// card (which is intentionally left untouched). Reuses the shared primitives (Card / PriorityChip /
// ProgressBar / CardFooterMeta) for identical styling. Unlike the Website card, EVERY visual here —
// the count, the chip, the drill-down, the copy — is driven by leads.priority.

// Soft pastel priority border per level — the hue at low opacity groups the card without dominating.
const priorityBorder: Record<string, string> = {
  high: "border-priority-high/40",
  medium: "border-priority-medium/40",
  low: "border-priority-low/40",
};

type Props = {
  bucket: LeadPriorityBucket;
};

export function LeadsPriorityCard({ bucket }: Props) {
  return (
    <Link
      href={`/zerem/leads?priority=${bucket.priority}`}
      className="group block rounded-xl transition duration-200 hover:-translate-y-0.5 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
    >
      <Card
        className={cn(
          "shadow-card ring-0 transition-shadow duration-200 group-hover:shadow-md [--card-spacing:--spacing(6)]",
          "border",
          priorityBorder[bucket.priority],
        )}
      >
        <CardContent className="flex flex-col">
          <div className="flex items-center justify-between">
            <span className="text-display font-extrabold leading-none text-card-foreground">
              {bucket.count}
            </span>
            <PriorityChip priority={bucket.priority} />
          </div>

          <h3 className="mt-3 text-base font-semibold text-card-foreground">
            {bucket.title}
          </h3>

          <p className="mt-1 text-sm text-secondary-foreground">
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
              `${bucket.totalPct}% OF TOTAL LEADS`,
            ]}
          />
        </CardContent>
      </Card>
    </Link>
  );
}
