import {
  PieChart as ConversionIcon,
  Trophy,
  Wallet,
  Coins,
  UserCheck,
  Users,
  CheckCircle2,
  type LucideIcon,
} from "lucide-react";
import { cn } from "@/modules/crm/lib/utils";
import { Card, CardContent } from "@/modules/crm/components/ui/card";
import { PendingBadge } from "@/modules/crm/components/shared/pending-badge/pending-badge";
import type { PerfKpiCard, PerfKpiIcon } from "@/modules/crm/features/performance/types/performance-types";

const ICONS: Record<PerfKpiIcon, LucideIcon> = {
  conversion: ConversionIcon,
  converted: Trophy,
  revenue: Wallet,
  pipeline: Coins,
  active: UserCheck,
  assigned: Users,
  followups: CheckCircle2,
};

type Props = {
  card: PerfKpiCard;
  /** Only the live "Assigned Leads" card sets this while its query resolves. */
  isLoading?: boolean;
};

export function PerformanceKpiCard({ card, isLoading }: Props) {
  const Icon = ICONS[card.icon];

  return (
    <Card className="relative h-full overflow-hidden border border-border shadow-card before:pointer-events-none before:absolute before:inset-x-0 before:top-0 before:h-1 before:content-[''] before:[background:var(--kpi-top-accent)]">
      <CardContent className="flex flex-col gap-3 pt-5">
        <div className="flex items-center justify-between gap-2">
          <div className="flex min-w-0 items-center gap-2">
            <span className="flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-teal-100 text-teal-700">
              <Icon className="h-3.5 w-3.5" aria-hidden />
            </span>
            <span className="truncate text-[11px] font-semibold uppercase tracking-[0.08em] text-muted-foreground">
              {card.label}
            </span>
          </div>
          {card.pending && <PendingBadge reason={card.pendingReason} />}
        </div>

        {isLoading ? (
          <div className="mt-1 h-8 w-20 animate-pulse rounded bg-muted" />
        ) : (
          <span
            className={cn(
              "text-[32px] font-extrabold leading-none",
              card.valueTone === "accent" ? "text-primary-accent" : "text-card-foreground",
            )}
          >
            {card.value}
          </span>
        )}

        {/* No period-over-period delta chips — those wait on a real business computation (PO). */}
        {card.caption ? (
          <span className="text-[11px] font-medium uppercase tracking-wide text-muted-foreground">
            {card.caption}
          </span>
        ) : null}
      </CardContent>
    </Card>
  );
}
