"use client";

import { useState } from "react";
import { cn } from "@/modules/crm/lib/utils";
import { usePermissions } from "@/modules/crm/providers/permissions-provider";
import { ErrorState } from "@/modules/crm/components/shared/error-state/error-state";
import { BusinessRulesPage } from "@/modules/crm/features/business-rules/components/business-rules-page";
import { PerformanceTargetsPanel } from "@/modules/crm/features/performance/components/performance-targets-panel";

/**
 * Performance Management — the destination behind Settings → Administrator → Performance Management.
 *
 * Two surfaces, one context, so a manager never feels sent into Lead Scoring:
 *   KPI Definitions    — the seeded M-01…M-06 catalog, rendered by the EXISTING Business Rules
 *                        components with `module="kpi"`. No second CRUD implementation.
 *   Performance Targets — Goal Management, the source of Personal Target and Quarter Targets.
 *
 * Gated on `crm.performance.manage` (Admin + Super Admin). The check here only decides what to
 * render; every mutation behind both tabs is authorized server-side by the same permission, so
 * hiding this page is a courtesy, not the control.
 */
type Tab = "kpi" | "targets";

const TABS: { id: Tab; label: string }[] = [
  { id: "kpi", label: "KPI Definitions" },
  { id: "targets", label: "Performance Targets" },
];

export function PerformanceManagementPage() {
  const { can } = usePermissions();
  const [tab, setTab] = useState<Tab>("kpi");

  if (!can("crm.performance.manage")) {
    return (
      <ErrorState message="You do not have access to Performance Management." tone="error" />
    );
  }

  return (
    <div className="flex flex-col gap-6">
      <header>
        <h2 className="text-2xl font-bold text-foreground">Performance Management</h2>
        <p className="mt-1 text-sm text-muted-foreground">
          Manage the KPI definition catalog and the performance targets behind Reports &amp;
          Performance. Computed results stay read-only — these settings supply definitions and goals.
        </p>
      </header>

      <div className="flex gap-1 border-b border-border">
        {TABS.map((t) => (
          <button
            key={t.id}
            type="button"
            onClick={() => setTab(t.id)}
            aria-current={tab === t.id ? "page" : undefined}
            className={cn(
              "-mb-px border-b-2 px-4 py-2 text-sm transition-colors",
              tab === t.id
                ? "border-teal-500 font-semibold text-teal-700"
                : "border-transparent text-secondary-foreground hover:text-foreground",
            )}
          >
            {t.label}
          </button>
        ))}
      </div>

      {tab === "kpi" ? (
        // The same components Settings → Business Rules uses, pointed at the kpi module and gated by
        // the performance permission. rule_key, the formulas and point_value stay immutable.
        <BusinessRulesPage module="kpi" managePermission="crm.performance.manage" showHeader={false} />
      ) : (
        <PerformanceTargetsPanel />
      )}
    </div>
  );
}
