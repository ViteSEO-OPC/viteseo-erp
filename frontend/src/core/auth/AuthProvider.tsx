"use client";

/**
 * Projects-module auth shim.
 *
 * The PMS module was written against its own `@/core/auth/AuthProvider`. In the
 * unified platform there is ONE real auth provider — `@/core/auth-context`
 * (mounted at the root layout, backed by /api/v1/auth/*). This file does NOT
 * introduce a second provider or a second login; it simply ADAPTS the shared
 * auth context into the shape the Projects components expect, and derives the
 * PMS Admin/PM/Member role from the platform's Spatie roles.
 */

import type { ReactNode } from "react";
import { useCallback, useMemo } from "react";

import { useAuth as usePlatformAuth } from "@/core/auth-context";
import { apiClient } from "@/core/api-client";

/** Spatie roles that count as a project "manager" (PMS management tier). */
const MANAGER_ROLES = ["pm_apm", "super_admin"];

type ProjectsUserRole = "Admin" | "PM" | "Member";

export interface ProjectsUser {
  id: number;
  name: string;
  email: string;
  avatar?: string | null;
  role?: ProjectsUserRole;
  capacity_points?: number | null;
  preferences?: Record<string, unknown> | null;
}

function toProjectsRole(roles: string[]): ProjectsUserRole {
  if (roles.some((r) => r === "super_admin")) return "Admin";
  if (roles.some((r) => r === "pm_apm")) return "PM";
  return "Member";
}

/**
 * The Projects section already lives under the root <AuthProvider>, so this is a
 * pass-through kept only for import compatibility with the original module.
 */
export function AuthProvider({ children }: { children: ReactNode }) {
  return <>{children}</>;
}

export function useAuth() {
  const { user, roles, loading, login: platformLogin, logout } = usePlatformAuth();

  const adaptedUser: ProjectsUser | null = useMemo(() => {
    if (!user) return null;
    return {
      id: user.id,
      name: user.name,
      email: user.email,
      // avatar/capacity_points aren't in the shared auth payload; the Projects
      // user-directory endpoint (/projects/users) supplies them for other users.
      avatar: (user as { avatar?: string | null }).avatar ?? null,
      role: toProjectsRole(roles as unknown as string[]),
      capacity_points: (user as { capacity_points?: number | null }).capacity_points ?? null,
      preferences: (user as { preferences?: Record<string, unknown> | null }).preferences ?? null,
    };
  }, [user, roles]);

  const login = useCallback(
    async (credentials: { email: string; password: string }) => {
      const res = await platformLogin(credentials.email, credentials.password);
      return { user: res.user, token: res.token };
    },
    [platformLogin],
  );

  const register = useCallback(async () => {
    // Registration is not exposed by the platform's shared auth (users are
    // provisioned centrally). The PMS register page is not part of this merge.
    throw new Error("Registration is handled by the platform, not the Projects module.");
  }, []);

  const updateProfile = useCallback(
    async (payload: {
      name?: string;
      avatar?: string | null;
      preferences?: Record<string, unknown> | null;
    }) => {
      // The platform has no profile-update endpoint yet; attempt it and fall back
      // to the current user so the Projects profile screen degrades gracefully.
      try {
        const { data } = await apiClient.put("/auth/profile", payload);
        return data?.data ?? adaptedUser;
      } catch {
        return adaptedUser;
      }
    },
    [adaptedUser],
  );

  return {
    user: adaptedUser,
    isLoading: loading,
    isAuthenticated: !!adaptedUser,
    authChecked: !loading,
    login,
    register,
    logout,
    updateProfile,
  };
}

/** True for Admin / PM tier users (managers) — who may create tasks, projects, etc. */
export function useIsManager(): boolean {
  const { roles } = usePlatformAuth();
  return (roles as unknown as string[]).some((r) => MANAGER_ROLES.includes(r));
}
