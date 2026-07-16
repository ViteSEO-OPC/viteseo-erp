import Link from "next/link";
import { cn } from "@/modules/crm/lib/utils";
import { Card, CardContent } from "@/modules/crm/components/ui/card";
import { PriorityChip } from "@/modules/crm/components/shared/status-badge/priority-chip";
import { ProgressBar } from "@/modules/crm/components/shared/progress-bar/progress-bar";
import { CardFooterMeta } from "@/modules/crm/features/dashboard/components/card-footer-meta";
import type { PriorityBucket } from "@/modules/crm/features/dashboard/types/dashboard-types";

// Soft pastel priority border per tone — the hue at low opacity so it groups the card
// without dominating (colour still reads fully in the chip + progress bar).
const priorityBorder: Record<string, string> = {
  high: "border-priority-high/40",
  medium: "border-priority-medium/40",
  low: "border-priority-low/40",
};

type Props = {
  bucket: PriorityBucket;
};

export function PriorityCard({ bucket }: Props) {
  return (
    <Link
      href={`/zerem/leads?website_status=${bucket.websiteStatus}`}
      className="group block rounded-xl transition duration-200 hover:-translate-y-0.5 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
    >
      <Card
        className={cn(
          "shadow-card ring-0 transition-shadow duration-200 group-hover:shadow-md [--card-spacing:--spacing(6)]",
          "border",
          priorityBorder[bucket.tone],
        )}
      >
        <CardContent className="flex flex-col">
          <div className="flex items-center justify-between">
            <span className="text-display font-extrabold leading-none text-card-foreground">
              {bucket.count}
            </span>
            <PriorityChip priority={bucket.tone} />
          </div>

          <h3 className="mt-3 text-base font-semibold text-card-foreground">
            {bucket.title}
          </h3>

          <p className="mt-1 text-sm text-secondary-foreground">
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
