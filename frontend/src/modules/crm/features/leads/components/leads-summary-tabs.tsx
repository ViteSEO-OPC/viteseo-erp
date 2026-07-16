"use client";

import Link from "next/link";
import { cn } from "@/modules/crm/lib/utils";
import { useLeadStatusSummary } from "@/modules/crm/features/leads/hooks/use-leads-data";

/**
 * Lead Summary tabs — status count strip (TOTAL/NEW/CONTACTED/QUALIFIED/DECLINED).
 * Live counts from /dashboard/kpis. Each tab is a link that filters the Leads list via the
 * existing ?status= URL mechanism (TOTAL clears it); the active tab reflects the current URL.
 * Labels are presentation-only pending BD terminology; the label→status mapping lives in
 * STATUS_TAB_MAP (single source, shared with the counts).
 */
export function LeadsSummaryTabs() {
  const { data: items } = useLeadStatusSummary();

  return (
    <div
      className="inline-flex items-stretch overflow-hidden rounded-lg border border-border bg-card"
      role="group"
      aria-label="Lead status summary"
    >
      {items.map((item, index) => (
        <Link
          key={item.key}
          href={item.href}
          aria-current={item.active ? "true" : undefined}
          className={cn(
            "flex items-center gap-2 px-4 py-2 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-ring",
            index > 0 && "border-l border-border-subtle",
            item.active && "bg-muted",
          )}
        >
          <span className="text-[11px] font-semibold uppercase tracking-[0.08em] text-muted-foreground">
            {item.label}
          </span>
          <span className={cn("text-sm font-bold", item.className)}>
            {item.count.toLocaleString()}
          </span>
        </Link>
      ))}
    </div>
  );
}
