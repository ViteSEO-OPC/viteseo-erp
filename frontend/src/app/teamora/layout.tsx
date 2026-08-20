"use client";

import { usePathname } from "next/navigation";

import { RequireAuth, useAuth } from "@/core/auth-context";
import { TeamoraShell } from "@/modules/hr/components/teamora-shell";
import { ViewModeProvider } from "@/modules/hr/components/view-mode-context";

/**
 * TeamOra (HRIS) shell — hosts BOTH the HR-management portal and the employee
 * self-service space under `/teamora`, preserving the old `/hr` vs `/` split:
 */

// Employee self-service sub-paths (open to any authenticated user). Everything
// else under /teamora is HR-management and needs an HRIS-management role.
const EMPLOYEE_PATHS = new Set([
  "/teamora",
  "/teamora/work-schedule",
  "/teamora/my-attendance",
  "/teamora/payslips",
  "/teamora/claims",
  "/teamora/concerns",
  "/teamora/faq",
  "/teamora/benefits",
  "/teamora/profile",
]);

export default function TeamoraLayout({
  children,
}: {
  children: React.ReactNode;
}) {
  const { hasRole } = useAuth();
  const pathname = usePathname();

  const isManager = hasRole("hr_officer") || hasRole("super_admin");
  const requiredRole = EMPLOYEE_PATHS.has(pathname)
    ? undefined
    : (["hr_officer", "super_admin"] as const);

  return (
    <RequireAuth role={requiredRole}>
      <ViewModeProvider isManager={isManager}>
        <TeamoraShell>{children}</TeamoraShell>
      </ViewModeProvider>
    </RequireAuth>
  );
}
