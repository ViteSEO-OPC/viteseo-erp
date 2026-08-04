import { PerformanceManagementPage } from "@/modules/crm/features/performance/components/performance-management-page";

// Reached from Reports & Performance → Manage Performance (Admin + Super Admin). It sits under the
// settings route group so it inherits the existing minRole: crm_admin guard by prefix; the
// permission check inside the component and the server-side gate are what actually authorize it.
export default function SettingsPerformanceManagement() {
  return <PerformanceManagementPage />;
}
