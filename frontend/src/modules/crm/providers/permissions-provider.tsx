"use client";

import { createContext, useContext, useCallback, useMemo, type ReactNode } from "react";
import { useAuth } from "@/modules/crm/providers/auth-provider";
import {
  highestRole,
  hasMinRole as hasMinRoleFn,
  satisfiesRoleAccess,
  type Role,
  type RoleAccessRule,
} from "@/modules/crm/lib/rbac";

type PermissionsContextValue = {
  /** Permission-based check (in-page actions), backed by user.permissions. */
  can: (action: string) => boolean;
  /** Role-based checks (page/module visibility). */
  hasRole: (role: Role) => boolean;
  hasAnyRole: (roles: Role[]) => boolean;
  hasMinRole: (min: Role) => boolean;
  /** Generic role-access check — honors both `minRole` (inherit) and `allowedRoles` (allow-list).
   *  The reusable primitive for any role-gated surface (sidebar items, sections, actions). */
  canAccess: (rule: RoleAccessRule) => boolean;
  /** The user's highest-ranked role, or null. */
  role: Role | null;
};

const PermissionsContext = createContext<PermissionsContextValue | undefined>(
  undefined
);

export function PermissionsProvider({ children }: { children: ReactNode }) {
  const { user } = useAuth();

  const roles = useMemo(() => user?.roles ?? [], [user]);
  const permissions = useMemo(() => user?.permissions ?? [], [user]);

  const can = useCallback(
    (action: string): boolean => permissions.includes(action),
    [permissions],
  );
  const hasRole = useCallback((role: Role): boolean => roles.includes(role), [roles]);
  const hasAnyRole = useCallback(
    (rs: Role[]): boolean => rs.some((r) => roles.includes(r)),
    [roles],
  );
  const hasMinRole = useCallback((min: Role): boolean => hasMinRoleFn(roles, min), [roles]);
  const canAccess = useCallback(
    (rule: RoleAccessRule): boolean => satisfiesRoleAccess(roles, rule),
    [roles],
  );
  const role = useMemo(() => highestRole(roles), [roles]);

  const value = useMemo(
    () => ({ can, hasRole, hasAnyRole, hasMinRole, canAccess, role }),
    [can, hasRole, hasAnyRole, hasMinRole, canAccess, role],
  );

  return (
    <PermissionsContext.Provider value={value}>
      {children}
    </PermissionsContext.Provider>
  );
}

export function usePermissions() {
  const context = useContext(PermissionsContext);
  if (!context) {
    throw new Error(
      "usePermissions must be used within a PermissionsProvider"
    );
  }
  return context;
}
