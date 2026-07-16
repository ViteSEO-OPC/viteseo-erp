import { Target, Zap, CheckCircle2, TrendingUp, Clock, type LucideIcon } from "lucide-react";
import { cn } from "@/modules/crm/lib/utils";
import type {
  ManagementKpi,
  ManagementKpiIcon,
  ManagementKpiTone,
} from "@/modules/crm/features/management/types/management-types";

const ICONS: Record<ManagementKpiIcon, LucideIcon> = {
  target: Target,
  zap: Zap,
  check: CheckCircle2,
  trending: TrendingUp,
  clock: Clock,
};

const TONE_ICON_BG: Record<ManagementKpiTone, string> = {
  teal: "bg-teal-100 text-teal-700",
  green: "bg-green-100 text-green-700",
  amber: "bg-amber-100 text-amber-700",
};

export function ManagementKpiCard({ kpi }: { kpi: ManagementKpi }) {
  const Icon = ICONS[kpi.icon];
  return (
    <div className="rounded-xl border border-border bg-card p-5 shadow-card">
      <div className="flex items-start justify-between gap-2">
        <p className="text-[11px] font-semibold uppercase tracking-[0.08em] text-muted-foreground">
          {kpi.label}
        </p>
        <span className={cn("flex h-8 w-8 shrink-0 items-center justify-center rounded-lg", TONE_ICON_BG[kpi.tone])}>
          <Icon className="h-4 w-4" aria-hidden />
        </span>
      </div>
      <p className={cn("mt-3 text-[32px] font-bold leading-none", kpi.tone === "green" ? "text-green-600" : "text-foreground")}>
        {kpi.value}
      </p>
      <p className="mt-2 text-sm text-muted-foreground">{kpi.subtitle}</p>
    </div>
  );
}
