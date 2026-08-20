"use client";

import { useIsManager } from "@/core/auth/AuthProvider";
import {
  ManagerDashboardView,
} from "@/modules/projects/components/dashboard/manager-dashboard-view";
import {
  MemberDashboardView,
} from "@/modules/projects/components/dashboard/member-dashboard-view";

/** Routes to the PM/Admin or member dashboard based on the current user's role. */
export function DashboardView(): React.ReactElement {
  const isManager = useIsManager();
  return isManager ? <ManagerDashboardView /> : <MemberDashboardView />;
}
