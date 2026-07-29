"use client";

import { EmployeeAvatar } from "@/modules/hr/ui/employee-avatar";
import type { EmployeeRef } from "@/modules/hr/types/employee";

/**
 * The "who is this" cell every HR queue opens with: face then name. Shared so a person
 * looks the same in the leave queue, the claims table and the attendance roster — and so
 * the photo, once uploaded, shows up in all of them at once.
 */
export function EmployeeCell({ employee }: { employee: EmployeeRef }) {
  const name = employee.name ?? "?";

  return (
    <div className="flex items-center gap-2.5">
      <EmployeeAvatar
        name={name}
        photoUrl={employee.photo_url}
        colorSeed={employee.id}
        size="sm"
      />
      <span className="font-semibold text-foreground">{name}</span>
    </div>
  );
}
