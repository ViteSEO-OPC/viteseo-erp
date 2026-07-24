"use client";

import { TargetAreasPanel } from "@/modules/crm/features/nearby-clients/components/target-areas-panel";

/**
 * Target Service Areas — the administrative registry behind the frozen "Target Location +10" Lead Score
 * signal. Management-only (`/service-areas` is role-gated to Super Admin + Admin server-side).
 *
 * RELOCATION, not a redesign (PO decision 2026-07-24, RBAC Hardening R-2): this capability previously
 * hung off a header action on the Nearby Clients page. Nearby Clients became a BD + Super Admin
 * workspace, which would have left the CRM Manager — the role this registry exists for — with no route
 * to it. Moving the entry point into Settings (an approved Admin module) keeps the capability with the
 * role instead of with the page.
 *
 * The registry itself is untouched: this renders the SAME {@link TargetAreasPanel} the dialog used, so
 * behaviour, validation, and the service-area hooks are unchanged.
 */
export function TargetAreasPage() {
  return (
    <div className="flex flex-col gap-6">
      <header>
        <h2 className="text-2xl font-bold text-foreground">Target Service Areas</h2>
        <p className="mt-1 text-sm text-muted-foreground">
          Configure which administrative areas count as a target location for the Lead Score.
        </p>
      </header>

      <TargetAreasPanel />
    </div>
  );
}
