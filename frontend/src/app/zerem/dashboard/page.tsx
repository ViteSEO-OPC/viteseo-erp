"use client";

import { usePermissions } from "@/modules/crm/providers/permissions-provider";
import { DashboardPage } from "@/modules/crm/features/dashboard/components/dashboard-page";
import { ManagementDashboardPage } from "@/modules/crm/features/management/components/management-dashboard-page";

/**
 * The Dashboard is one menu / one route (`/dashboard`) whose content is role-dependent:
 * - Business Development Manager → the existing BD Dashboard (unchanged).
 * - Admin (CRM Manager) / Super Admin → the Management Dashboard.
 * This is the app composition layer, so importing both feature pages here is intentional.
 */
export default function Page() {
  const { hasMinRole } = usePermissions();
  return hasMinRole("crm_admin") ? <ManagementDashboardPage /> : <DashboardPage />;
}
