"use client";

import { useState } from "react";
import { PlannerHistory } from "@/modules/crm/features/calendar/components/planner-history";
import { ReportsScopeSelector } from "@/modules/crm/features/reports/components/reports-scope-selector";
import {
  ALL_SCOPE,
  type PerformanceScope,
} from "@/modules/crm/features/performance/lib/performance-scope";
import { usePermissions } from "@/modules/crm/providers/permissions-provider";

/**
 * Planner History, scope selector included — the ONE implementation of the History surface, mounted by
 * both of its entry points:
 *
 *   • the BD's History tab inside the Planner (/zerem/planner), and
 *   • the standalone /zerem/planner/history route, which is how Admin/Super Admin reach History
 *     without being handed the Calendar and Board.
 *
 * Keeping it in one component is what makes the two routes a routing decision rather than a second
 * History: the table, the query, the cache key and the backend endpoint are identical either way.
 *
 * Admin/Super Admin may inspect any BD's completed work; a BD never mounts the selector, sends no
 * scope param, and sees only their own. That is a UI affordance, not the boundary — the server applies
 * PlannerEvent::scopeVisibleTo before `assigned_to`, so the scope below is a FILTER, never a grant.
 */
export function PlannerHistoryWorkspace() {
  const { hasMinRole } = usePermissions();
  const canFilterByBd = hasMinRole("crm_admin");

  // ALL_SCOPE for everyone, deliberately: "all" sends no filter param and the backend bounds it to
  // whatever the caller may see, so for a BD it already means "all of mine" and for an Admin it means
  // "every BD". No role-dependent seeding needed.
  const [scope, setScope] = useState<PerformanceScope>(ALL_SCOPE);

  return (
    <div className="flex flex-col gap-4">
      {/* Same selector as Reports & Performance and the Admin Leads page — one implementation,
          different "all" wording. */}
      {canFilterByBd && (
        <ReportsScopeSelector
          scope={scope}
          onChange={setScope}
          allLabel="All BD"
          ariaLabel="Filter Planner history by Business Development Manager"
        />
      )}
      <PlannerHistory scope={scope} showOwner={canFilterByBd} />
    </div>
  );
}
