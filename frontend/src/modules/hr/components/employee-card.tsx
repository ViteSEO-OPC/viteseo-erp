import Link from "next/link";

import { EmployeeStatusBadge } from "@/modules/hr/components/employee-status-badge";
import { EmployeeAvatar } from "@/modules/hr/ui/employee-avatar";
import type { EmployeeListItem } from "@/modules/hr/types/employee";

// Takes the directory projection, not the full record — a full `Employee` still
// satisfies it, so the single-employee screens can pass theirs unchanged.
export function EmployeeCard({ employee }: { employee: EmployeeListItem }) {
  return (
    <Link
      href={`/teamora/people/employees/${employee.id}`}
      className="flex items-center gap-3 rounded-card border border-border bg-card p-4 transition-colors hover:border-brand/60"
    >
      <EmployeeAvatar
        name={employee.name}
        photoUrl={employee.photo_url}
        colorSeed={employee.id}
      />
      <div className="min-w-0 flex-1">
        <p className="truncate font-semibold text-foreground">{employee.name}</p>
        <p className="truncate text-caption text-muted-foreground">
          {employee.job_title}
          {employee.department ? ` · ${employee.department.name}` : ""}
        </p>
        <div className="mt-1.5 flex flex-wrap gap-1.5">
          <EmployeeStatusBadge employee={employee} />
        </div>
      </div>
    </Link>
  );
}
