"use client";

import { Lock } from "lucide-react";
import { Card, CardContent } from "@/modules/crm/components/ui/card";
import { cn } from "@/modules/crm/lib/utils";

type Props = {
  title: string;
  subtitle?: string;
  /** Honest reason this widget isn't live yet — the identified backend dependency + when. */
  reason: string;
  className?: string;
};

/**
 * Deferred-widget placeholder. Used ONLY where a backend dependency has been explicitly identified
 * and approved for a future milestone (Outreach-by-channel, Decline reasons, Quarter targets, BD
 * drill-down). It never renders fabricated data — it states plainly what the widget needs and that
 * it is planned, so the page is honest about current backend capability.
 */
export function WidgetPlaceholder({ title, subtitle, reason, className }: Props) {
  return (
    <Card className={cn("border-dashed border-border shadow-card", className)}>
      <CardContent className="flex h-full flex-col gap-3 pt-4">
        <div>
          <h3 className="text-sm font-bold text-foreground">{title}</h3>
          {subtitle && <p className="text-xs text-muted-foreground">{subtitle}</p>}
        </div>
        <div className="flex flex-1 flex-col items-center justify-center gap-2 rounded-lg bg-muted/40 px-4 py-10 text-center">
          <Lock className="h-5 w-5 text-muted-foreground" aria-hidden />
          <p className="text-xs font-semibold uppercase tracking-[0.08em] text-muted-foreground">
            Planned — future milestone
          </p>
          <p className="max-w-xs text-xs text-muted-foreground">{reason}</p>
        </div>
      </CardContent>
    </Card>
  );
}
