"use client";

import {
  createContext,
  useCallback,
  useContext,
  useEffect,
  useState,
} from "react";
import { useRouter } from "next/navigation";

import { fetchMe, loginRequest, logoutRequest } from "@/core/api/auth";
import type { AuthUser, AuthEmployee, LoginResponse, Role } from "@/core/types/auth";

interface AuthContextValue {
  user: AuthUser | null;
  roles: Role[];
  /** Effective permission names (roles ∪ direct) for the current user. */
  permissions: string[];
  /** Employee context (job title / department) — null for users without a record. */
  employee: AuthEmployee | null;
  /** True until the initial /me check finishes — prevents portal flicker. */
  loading: boolean;
  login: (email: string, password: string) => Promise<LoginResponse>;
  logout: () => Promise<void>;
  hasRole: (role: Role) => boolean;
  /** Merge a partial update into the cached auth user (e.g. after a self-service
   *  profile save) so headers/sidebars reflect it without a full /me refetch. */
  updateUser: (patch: Partial<AuthUser>) => void;
}

const AuthContext = createContext<AuthContextValue | null>(null);

export function AuthProvider({ children }: { children: React.ReactNode }) {
  const [user, setUser] = useState<AuthUser | null>(null);
  const [roles, setRoles] = useState<Role[]>([]);
  const [employee, setEmployee] = useState<AuthEmployee | null>(null);
  const [loading, setLoading] = useState(true);

  // On app load/refresh: if a token exists, restore the session via /me.
  // If it's missing or invalid, we simply stay logged out.
  useEffect(() => {
    const token = localStorage.getItem("token");
    if (!token) {
      // localStorage only exists after mount, so this can't be decided during render.
      // eslint-disable-next-line react-hooks/set-state-in-effect
      setLoading(false);
      return;
    }
    fetchMe()
      .then((me) => {
        setUser(me.user);
        setRoles(me.roles);
        setEmployee(me.employee);
      })
      .catch(() => {
        // Invalid/expired token — the 401 interceptor already cleared it.
        setUser(null);
        setRoles([]);
        setEmployee(null);
      })
      .finally(() => setLoading(false));
  }, []);

  const login = useCallback(async (email: string, password: string) => {
    const res = await loginRequest(email, password);
    localStorage.setItem("token", res.token);
    setUser(res.user);
    setRoles(res.roles);
    setEmployee(res.employee);
    return res;
  }, []);

  const router = useRouter();

  const logout = useCallback(async () => {
    try {
      await logoutRequest();
    } catch {
      // Even if the network call fails, drop the session locally.
    }
    localStorage.removeItem("token");
    setUser(null);
    setRoles([]);
    setEmployee(null);
    router.replace("/login");
  }, [router]);

  const hasRole = useCallback(
    (role: Role) => roles.includes(role),
    [roles],
  );

  const updateUser = useCallback((patch: Partial<AuthUser>) => {
    setUser((current) => (current ? { ...current, ...patch } : current));
  }, []);

  // Effective permissions ride on the user payload (platform UserResource).
  const permissions = user?.permissions ?? [];

  return (
    <AuthContext.Provider
      value={{ user, roles, permissions, employee, loading, login, logout, hasRole, updateUser }}
    >
      {children}
    </AuthContext.Provider>
  );
}

export function useAuth(): AuthContextValue {
  const ctx = useContext(AuthContext);
  if (!ctx) {
    throw new Error("useAuth must be used within an <AuthProvider>");
  }
  return ctx;
}

/**
 * Wrap any protected page. Bounces to /login when unauthenticated, and (if a
 * `role` is given) to the launcher when the user holds none of the allowed roles.
 * `role` may be a single role or a list (satisfied by holding ANY of them) — e.g.
 * HR-management pages allow both `hr_officer` and `super_admin`.
 */
export function RequireAuth({
  role,
  children,
}: {
  role?: Role | readonly Role[];
  children: React.ReactNode;
}) {
  const { user, loading, hasRole } = useAuth();
  const router = useRouter();

  const roleList: readonly Role[] =
    role === undefined ? [] : typeof role === "string" ? [role] : role;
  const lacksRole = roleList.length > 0 && !roleList.some((r) => hasRole(r));

  useEffect(() => {
    if (loading) return;
    if (!user) {
      router.replace("/login");
    } else if (lacksRole) {
      router.replace("/");
    }
  }, [loading, user, lacksRole, router]);

  if (loading || !user || lacksRole) {
    return (
      <div className="flex min-h-screen items-center justify-center text-sm text-muted-foreground">
        Loading…
      </div>
    );
  }

  return <>{children}</>;
}
