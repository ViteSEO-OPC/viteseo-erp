"use client";

import { useRouter } from "next/navigation";
import { useEffect, type ReactNode } from "react";
import { useAuth, useIsManager } from "./AuthProvider";

function Centered({ children }: { children: ReactNode }) {
  return (
    <div className="flex h-screen items-center justify-center text-ink-soft">
      {children}
    </div>
  );
}

/**
 * Role gate — renders children only when `allow` is true; otherwise redirects to
 * `redirectTo`. Waits for the auth + profile to load before deciding so a
 * manager is never briefly bounced while their role is still unknown.
 */
function RoleGate({
  ready,
  allow,
  redirectTo,
  children,
}: {
  ready: boolean;
  allow: boolean;
  redirectTo: string;
  children: ReactNode;
}) {
  const router = useRouter();

  useEffect(() => {
    if (ready && !allow) router.replace(redirectTo);
  }, [ready, allow, redirectTo, router]);

  if (!ready) return <Centered>Loading…</Centered>;
  if (!allow) return <Centered>Redirecting…</Centered>;
  return <>{children}</>;
}

/** Restricts a page to PM/Admin; members are redirected (default: member team page). */
export function RequireManager({
  children,
  redirectTo = "/sprintly/team/member",
}: {
  children: ReactNode;
  redirectTo?: string;
}) {
  const { authChecked, user } = useAuth();
  const isManager = useIsManager();
  return (
    <RoleGate
      ready={authChecked && !!user}
      allow={isManager}
      redirectTo={redirectTo}
    >
      {children}
    </RoleGate>
  );
}

/** Restricts a page to members; PM/Admin are redirected (default: manager team page). */
export function RequireMember({
  children,
  redirectTo = "/sprintly/team/manager",
}: {
  children: ReactNode;
  redirectTo?: string;
}) {
  const { authChecked, user } = useAuth();
  const isManager = useIsManager();
  return (
    <RoleGate
      ready={authChecked && !!user}
      allow={!isManager}
      redirectTo={redirectTo}
    >
      {children}
    </RoleGate>
  );
}
