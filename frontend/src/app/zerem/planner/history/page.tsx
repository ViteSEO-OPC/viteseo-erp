import { PlannerHistoryWorkspace } from "@/modules/crm/features/calendar/components/planner-history-workspace";

// Planner History — completed Planner work with no 7-day window (RBAC: crm_bd + crm_admin +
// super_admin, see PAGE_ACCESS). Its own route rather than a mode inside /zerem/planner so that
// Admin/Super Admin can review BD output without the Planner's Calendar and Board: /zerem/planner
// stays BD-only, and the RoleGuard bounces them off it. BDs reach the same surface from the History
// tab in their Planner — both render the one PlannerHistoryWorkspace.
export default function PlannerHistoryPage() {
  return <PlannerHistoryWorkspace />;
}
