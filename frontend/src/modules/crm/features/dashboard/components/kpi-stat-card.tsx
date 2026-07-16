import Link from "next/link";
import { cn } from "@/modules/crm/lib/utils";
import { Card, CardContent } from "@/modules/crm/components/ui/card";
import { DeltaIndicator } from "@/modules/crm/features/dashboard/components/delta-indicator";
import { PendingBadge } from "@/modules/crm/components/shared/pending-badge/pending-badge";
import type { KpiStat } from "@/modules/crm/features/dashboard/types/dashboard-types";

type Props = {
  stat: KpiStat;
  // Font-size utility for the primary value (default text-display = 30px). The Dashboard KPI
  // row overrides this to text-[33px] per approved sizing; other reuse (e.g. the import
  // preview stats) keeps the default. Only the value's font-size changes — nothing else.
  valueSizeClassName?: string;
};

export function KpiStatCard({ stat, valueSizeClassName = "text-display" }: Props) {
  const card = (
    <Card className="relative h-full border border-border shadow-card ring-0 transition-shadow duration-200 group-hover:shadow-md [--card-spacing:--spacing(5)] before:pointer-events-none before:absolute before:inset-x-0 before:top-0 before:h-1 before:content-[''] before:[background:var(--kpi-top-accent)]">
      <CardContent className="flex flex-col">
        <span className="text-subheading font-semibold uppercase tracking-[0.08em] text-kpi-caption">
          {stat.label}
        </span>
        <span
          className={cn(
            "mt-6 font-extrabold leading-none",
            valueSizeClassName,
            stat.valueVariant === "accent"
              ? "text-primary-accent"
              : "text-card-foreground",
          )}
        >
          {stat.value}
        </span>
        {stat.pending ? (
          <span className="mt-5">
            <PendingBadge reason={stat.pendingReason} />
          </span>
        ) : stat.delta ? (
          <DeltaIndicator delta={stat.delta} />
        ) : stat.caption ? (
          <span className="mt-5 text-xs font-medium text-kpi-caption">{stat.caption}</span>
        ) : null}
      </CardContent>
    </Card>
  );

  // Supported KPIs deep-link via client-side routing; deferred KPIs are display-only.
  if (stat.href) {
    return (
      <Link
        href={stat.href}
        className="group block rounded-xl transition duration-200 hover:-translate-y-0.5 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
      >
        {card}
      </Link>
    );
  }

  return card;
}
