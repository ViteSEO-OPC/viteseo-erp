import { StatusBadge } from "@/core/components/status-badge";
import { workStatusLabel, type EmployeeListItem } from "@/modules/hr/types/employee";

/**
 * Where an employee stands, as one badge — shared by the directory card and the record so the
 * two surfaces agree. Separation comes first because it changes what everything else means:
 * onboarding state is irrelevant once somebody has left.
 */
export function EmployeeStatusBadge({
  employee,
}: {
  employee: Pick<EmployeeListItem, "is_separated" | "separation_date" | "work_status" | "onboarding_status">;
}) {
  if (employee.is_separated) {
    // `archived` (grey) rather than a new key — status-badge.tsx lives in shared core/.
    const label = workStatusLabel("separated");
    return (
      <StatusBadge
        status="archived"
        label={employee.separation_date ? `${label} · ${employee.separation_date}` : label}
      />
    );
  }
  if (employee.work_status === "floating") {
    return <StatusBadge status="pending" label={workStatusLabel("floating")} />;
  }
  if (employee.work_status === "suspended") {
    return <StatusBadge status="rejected" label={workStatusLabel("suspended")} />;
  }
  if (employee.onboarding_status === "pending") {
    return <StatusBadge status="pending" label="Onboarding" />;
  }
  return <StatusBadge status="active" label={workStatusLabel("active")} />;
}
