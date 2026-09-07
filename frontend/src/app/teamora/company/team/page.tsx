"use client";

import { PageHeader } from "@/modules/hr/components/page-header";
import { CustomRolesManager } from "@/core/roles/custom-roles-manager";
import { TeamAccountsManager } from "@/core/team/team-accounts-manager";

/**
 * Company › Team & Roles — the whole staff-access job on one page.
 *
 * Replaces the former Team Accounts and Roles & Permissions pair. Adding a person
 * and deciding what they can do is ONE task, and as two navigation entries it was
 * a task an operator could not find the halves of. Both screens are the
 * platform's own (roles are org-wide, not TeamOra's), so this route supplies the
 * portal's header and nothing else; the Company group already restricts the area
 * to hr_officer/super_admin, which is what /hr/team checks on the server.
 */
export default function CompanyTeamPage() {
  return (
    // p-4 sm:p-8 is what every other Company page uses; TeamOra's layout supplies
    // no padding of its own, so without it this page sat flush against the rail.
    <div className="space-y-10 p-4 sm:p-8">
      <PageHeader
        title="Team & Roles"
        subtitle="Add your TeamOra staff and set what they can do."
      />
      <TeamAccountsManager module="hr" />
      <CustomRolesManager feature="hr" />
    </div>
  );
}
