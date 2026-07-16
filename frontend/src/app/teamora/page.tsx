"use client";

import { useAuth } from "@/core/auth-context";
import ManagerDashboardPage from "./manager-dashboard";
import EmployeeOverviewPage from "./employee-overview";

/**
 * TeamOra (HRIS) landing page at `/teamora`. Renders the HR-management dashboard
 * for HR officers, and the employee self-service overview for everyone else —
 * the same role-based split the platform had when these lived at `/hr` and `/`.
 */
export default function TeamoraHomePage() {
  const { hasRole } = useAuth();
  return hasRole("hr_officer") || hasRole("super_admin") ? (
    <ManagerDashboardPage />
  ) : (
    <EmployeeOverviewPage />
  );
}
