"use client";

import { useViewMode } from "@/modules/hr/components/view-mode-context";
import ManagerDashboardPage from "./manager-dashboard";
import EmployeeOverviewPage from "./employee-overview";

/**
 * TeamOra (HRIS) landing page at `/teamora`. Renders the HR-management dashboard
 * for HR officers, and the employee self-service overview for everyone else —
 * the same role-based split the platform had when these lived at `/hr` and `/`.
 *
 * Follows the effective view mode (not the raw role) so an HR manager who flips
 * to the employee view via the sidebar toggle lands on the employee overview.
 */
export default function TeamoraHomePage() {
  const { variant } = useViewMode();
  return variant === "hr" ? <ManagerDashboardPage /> : <EmployeeOverviewPage />;
}
