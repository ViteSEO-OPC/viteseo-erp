"use client";

import { useAuth, useIsManager } from "@/core/auth/AuthProvider";
import { MemberTeamView } from "@/modules/projects/components/team/member-team-view";
import { ManagerTeamView } from "@/modules/projects/components/team/manager-team-view";
import {
  SkeletonCardGrid,
  SkeletonPage,
} from "@/modules/projects/components/shared/skeletons";

/**
 * TeamView — Routes to the appropriate view based on user role.
 * - Members see MemberTeamView (read-only)
 * - PM/Admin see ManagerTeamView (full management)
 */
export function TeamView() {
  const { authChecked } = useAuth();
  const isManager = useIsManager();

  // Show loading state while auth is being checked
  if (!authChecked) {
    return (
      <SkeletonPage>
        <SkeletonCardGrid />
      </SkeletonPage>
    );
  }

  // Route based on role
  return isManager ? <ManagerTeamView /> : <MemberTeamView />;
}
