import {
  Users,
  UserCheck,
  PieChart as ConversionIcon,
  CalendarCheck,
  Wallet,
  type LucideIcon,
} from "lucide-react";
import { Card, CardContent } from "@/modules/crm/components/ui/card";

/**
 * Management KPI card — the All Reports headline metric tile. Deliberately a **separate** presentation
 * from the BD scorecard's {@link PerformanceKpiCard}: the Management Dashboard has its own identity and
 * must not read as a filtered BD page. Distinctions: a **square** brand-teal icon tile (BD uses a circle),
 * a **dark** value in the foreground colour (BD tints its values teal), a **brand-teal** top accent, and
 * a sentence-case caption. Same shared Card shell; different chrome.
 */
export type ManagementKpiIcon =
  | "leads"
  | "contacted"
  | "conversion"
  | "consultations"
  | "revenue";

export type ManagementKpiCardData = {
  id: string;
  label: string;
  icon: ManagementKpiIcon;
  value: string;
  caption: string;
};

const ICONS: Record<ManagementKpiIcon, LucideIcon> = {
  leads: Users,
  contacted: UserCheck,
  conversion: ConversionIcon,
  consultations: CalendarCheck,
  revenue: Wallet,
};

export function ManagementKpiCard({
  card,
  isLoading,
}: {
  card: ManagementKpiCardData;
  isLoading?: boolean;
}) {
  const Icon = ICONS[card.icon];

  return (
    <Card className="relative h-full overflow-hidden border border-border shadow-card before:pointer-events-none before:absolute before:inset-x-0 before:top-0 before:h-[3px] before:content-[''] before:[background:var(--chart-teal-dark)]">
      <CardContent className="flex flex-col gap-2.5 pt-5">
        <div className="flex items-center gap-2.5">
          <span className="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-teal-50 text-teal-600 dark:bg-teal-950/40 dark:text-teal-300">
            <Icon className="h-4 w-4" aria-hidden />
          </span>
          <span className="truncate text-[11px] font-semibold uppercase tracking-[0.08em] text-muted-foreground">
            {card.label}
          </span>
        </div>

        {isLoading ? (
          <div className="mt-1 h-8 w-24 animate-pulse rounded bg-muted" />
        ) : (
          <span className="text-[30px] font-extrabold leading-none text-card-foreground">
            {card.value}
          </span>
        )}

        <span className="text-[11px] text-muted-foreground">{card.caption}</span>
      </CardContent>
    </Card>
  );
}
