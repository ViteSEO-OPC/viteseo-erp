"use client";

import { EmployeeAvatar } from "@/modules/hr/ui/employee-avatar";
import type { EmployeeRef } from "@/modules/hr/types/employee";

/**
 * The "who is this" cell every HR queue opens with: face then name. Shared so a person
 * looks the same in the leave queue, the claims table and the attendance roster — and so
 * the photo, once uploaded, shows up in all of them at once.
 */
export function EmployeeCell({
  employee,
  displayName,
}: {
  employee: EmployeeRef;
  /**
   * What to PRINT, when a listing wants a different form of the name — "Dela Cruz, Juan" on a
   * roster filed by surname. The avatar still reads `employee.name`, because initials come off
   * the natural order: "Reyes, Dana" would otherwise initial as RD.
   */
  displayName?: string | null;
}) {
  const name = employee.name ?? "?";

  return (
    <div className="flex items-center gap-2.5">
      <EmployeeAvatar
        name={name}
        photoUrl={employee.photo_url}
        colorSeed={employee.id}
        size="sm"
      />
      <span className="font-semibold text-foreground">{displayName || name}</span>
    </div>
  );
}
