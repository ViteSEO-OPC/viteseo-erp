"use client";

import { PageHeader } from "@/core/components/page-header";
import { CustomRolesManager } from "@/core/roles/custom-roles-manager";
import { TeamAccountsManager } from "@/core/team/team-accounts-manager";

/**
 * Team & Roles — the same screen TeamOra, Zerem and Sprintly use.
 *
 * The module arrived with a Team page of its own: its own hooks, its own role
 * pickers, its own layout — 454 lines doing what the platform's two managers
 * already do for the other three portals. That was right when Accounting was a
 * separate build. Here it would have been a fourth dialect of one screen: an
 * operator who learned team management in TeamOra would have had to learn it
 * again to add a bookkeeper, which is the exact seam the shared shell exists to
 * remove.
 *
 * So the page is now a mount point. Everything specific to Accounting — its
 * three presets and its endpoint — is declared once in TEAM_MODULES, and its
 * capability blocks come from the platform capability registry.
 *
 * No client-side role gate, matching TeamOra: the nav entry is `requiresRole:
 * "acc_admin"` and `/api/v1/team` refuses anyone else, so a non-admin who typed
 * the URL gets an empty screen rather than a redirect that lies about why.
 */
export default function AccountingTeamPage() {
  return (
    <div className="space-y-10">
      <PageHeader
        title="Team & Roles"
        subtitle="Add the people who work in your books and set what each of them can do."
      />
      <TeamAccountsManager module="accounting" />
      <CustomRolesManager feature="accounting" />
    </div>
  );
}
