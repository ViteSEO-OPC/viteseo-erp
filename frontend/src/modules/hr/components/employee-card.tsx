import Link from "next/link";

import { StatusBadge } from "@/core/components/status-badge";
import { EmployeeAvatar } from "@/modules/hr/ui/employee-avatar";
import type { Employee } from "@/modules/hr/types/employee";

export function EmployeeCard({ employee }: { employee: Employee }) {
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
          {/*
           * A former employee must not read as "Active". The lifecycle badge comes first
           * because it changes what the other one means: onboarding state is irrelevant once
           * somebody has left.
           */}
          {employee.is_separated ? (
            // `archived` (grey) rather than a new key — status-badge.tsx lives in shared core/.
            <StatusBadge
              status="archived"
              label={employee.separation_date ? `Left ${employee.separation_date}` : "Former"}
            />
          ) : employee.onboarding_status === "pending" ? (
            <StatusBadge status="pending" label="Onboarding" />
          ) : (
            <StatusBadge status="active" label="Active" />
          )}
        </div>
      </div>
    </Link>
  );
}
