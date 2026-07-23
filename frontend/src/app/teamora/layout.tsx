"use client";

import { usePathname } from "next/navigation";

import { RequireAuth, useAuth } from "@/core/auth-context";
import { TeamoraShell } from "@/modules/hr/components/teamora-shell";
import { ViewModeProvider } from "@/modules/hr/components/view-mode-context";

/**
 * TeamOra (HRIS) shell — hosts BOTH the HR-management portal and the employee
 * self-service space under `/teamora`, preserving the old `/hr` vs `/` split:
 *
 * - The shared landing (`/teamora`) and the employee self-service pages only
 *   require an authenticated session (login guard).
 * - The HR-management pages require an HRIS-management role (`hr_officer`, or the
 *   all-access `super_admin`) — non-managers are bounced to the launcher.
 *
 * The sidebar nav set (`variant`) defaults to the user's role — HR managers get
 * the management nav, everyone else the employee nav — but managers can flip it
 * via the sidebar's "Switch to Employee view" toggle (see `ViewModeProvider`).
 */

// Employee self-service sub-paths (open to any authenticated user). Everything
// else under /teamora is HR-management and needs an HRIS-management role.
const EMPLOYEE_PATHS = new Set([
  "/teamora",
  "/teamora/time-off",
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
