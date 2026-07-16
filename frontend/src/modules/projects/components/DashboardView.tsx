"use client";

import { useIsManager } from "@/core/auth/AuthProvider";
import { ManagerDashboardView } from "./ManagerDashboardView";
import { MemberDashboardView } from "./MemberDashboardView";

/** Routes to the PM/Admin or member dashboard based on the current user's role. */
export function DashboardView(): React.ReactElement {
  const isManager = useIsManager();
  return isManager ? <ManagerDashboardView /> : <MemberDashboardView />;
}
