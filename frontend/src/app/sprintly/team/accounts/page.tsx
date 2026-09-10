"use client";

import Link from "next/link";
import { ArrowLeft } from "lucide-react";

import { RequireManager } from "@/core/auth/RequireRole";
import { CustomRolesManager } from "@/core/roles/custom-roles-manager";
import { TeamAccountsManager } from "@/core/team/team-accounts-manager";

/**
 * Team › Team & Roles — the whole staff-access job on one page.
 *
 * Absorbs the former /sprintly/roles route: building a role and putting somebody
 * in it is ONE task, and as two rail entries it was a task an operator could not
 * find the halves of. Both screens are the platform's own, and RequireManager
 * matches what /projects/team checks on the server, so this route adds Sprintly's
 * page frame and nothing else.
 *
 * The back link stays because the rail's own Team row goes to the project team
 * view (groups, workload, assignment) — a different question about the same
 * people, and the two are one click apart in both directions.
 */
export default function TeamAccountsPage() {
  return (
    <RequireManager>
      <div className="px-4 py-6 sm:px-6 lg:px-8">
        <Link
          href="/sprintly/team"
          className="inline-flex items-center gap-1.5 text-sm text-ink-soft transition-colors hover:text-ink"
        >
          <ArrowLeft className="size-4" />
          Back to Team
        </Link>
        <h1 className="mt-3 text-2xl font-bold text-ink sm:text-3xl">Team &amp; Roles</h1>
        <p className="mt-1 text-sm text-ink-soft">
          Add your Sprintly staff and set what they can do.
        </p>
        <TeamAccountsManager module="projects" className="mt-6" />
        <CustomRolesManager feature="pms" className="mt-10" />
      </div>
    </RequireManager>
  );
}
