"use client";

/**
 * CRM-module auth shim.
 *
 * Zerem shipped its own AuthProvider (own /login, /me, and `sanctum_token`).
 * In the unified platform there is ONE real auth provider — `@/core/auth-context`
 * (mounted at the root layout, backed by /api/v1/auth/*). This file does NOT
 * introduce a second provider or a second login; it ADAPTS the shared auth
 * context into the shape the CRM feature code expects (`useAuth()` →
 * { user: { name, email, roles, permissions, role, ... }, isAuthenticated,
 * isLoading, login, logout }).
 */

import type { ReactNode } from "react";
import { useCallback, useMemo } from "react";

import { useAuth as usePlatformAuth } from "@/core/auth-context";
import { highestRole, ROLE_LABELS, type Role } from "@/modules/crm/lib/rbac";

type CrmUser = {
  id?: number;
  name: string;
  email: string;
  roles: string[];
  permissions: string[];
  /** Highest-ranked role string (the app assigns exactly one). */
  role: Role | null;
  /** Human label for the highest role (used by the profile/topbar). */
  roleLabel: string | null;
  status: string;
  lastLoginAt: string | null;
};

/**
 * CRM already lives under the root platform <AuthProvider>, so this is a
 * pass-through kept only for import compatibility with the original module.
 */
export function AuthProvider({ children }: { children: ReactNode }) {
  return <>{children}</>;
}

export function useAuth() {
  const { user, roles, loading, login: platformLogin, logout } = usePlatformAuth();

  const permissions = useMemo(
    () => (user as { permissions?: string[] } | null)?.permissions ?? [],
    [user],
  );

  const adaptedUser: CrmUser | null = useMemo(() => {
    if (!user) return null;
    const roleList = roles as unknown as string[];
    const top = highestRole(roleList);
    return {
      id: (user as { id?: number }).id,
      name: user.name,
      email: user.email,
      roles: roleList,
      permissions,
      role: top,
      roleLabel: top ? ROLE_LABELS[top] : null,
      status: (user as { status?: string }).status ?? "active",
      lastLoginAt: (user as { last_login_at?: string | null }).last_login_at ?? null,
    };
  }, [user, roles, permissions]);

  const login = useCallback(
    async (email: string, password: string) => {
      await platformLogin(email, password);
    },
    [platformLogin],
  );

  return {
    user: adaptedUser,
    isAuthenticated: !!adaptedUser,
    isLoading: loading,
    login,
    logout,
  };
}
