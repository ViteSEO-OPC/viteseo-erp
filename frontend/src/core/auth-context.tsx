"use client";

import {
  createContext,
  useCallback,
  useContext,
  useEffect,
  useState,
} from "react";
import { useRouter } from "next/navigation";
import { isAxiosError } from "axios";

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
  /**
   * Re-read who this browser is, quietly: after an access change, or when a tab
   * comes back to the front. A dead session leaves `user` null, and <RequireAuth>
   * takes it from there.
   */
  refresh: () => Promise<void>;
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

  // On app load/refresh: ask the server whether this browser's session cookie is
  // still good. The cookie is httpOnly, so script cannot look first -- asking is
  // the only way to know. Quietly: a signed-out visitor on /forgot-password must
  // not be bounced to /login by the probe itself; <RequireAuth> decides that.
  useEffect(() => {
    // The bearer token this app kept here before sessions replaced it. It opens
    // nothing now, but it stayed readable in DevTools until something removed it.
    localStorage.removeItem("token");

    fetchMe({ quiet: true })
      .then((me) => {
        setUser(me.user);
        setRoles(me.roles);
        setEmployee(me.employee);
      })
      .catch(() => {
        // No session, or it has ended.
        setUser(null);
        setRoles([]);
        setEmployee(null);
      })
      .finally(() => setLoading(false));
  }, []);

  const login = useCallback(async (email: string, password: string) => {
    const res = await loginRequest(email, password);
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
    setUser(null);
    setRoles([]);
    setEmployee(null);
    router.replace("/login");
  }, [router]);

  const refresh = useCallback(async () => {
    try {
      const me = await fetchMe({ quiet: true });
      setUser(me.user);
      setRoles(me.roles);
      setEmployee(me.employee);
    } catch (err) {
      // Only an answer that says the session is gone clears it. A network blip
      // while the laptop wakes up must not sign anyone out.
      const status = isAxiosError(err) ? err.response?.status : undefined;
      if (status === 401 || status === 403 || status === 419) {
        setUser(null);
        setRoles([]);
        setEmployee(null);
      }
    }
  }, []);

  // A tab coming back to the front asks again. It is the one moment a page left
  // open on HR or Accounting -- which hold no socket, so hear no AccessChanged --
  // could still be showing access that was taken away. On return only, never on a
  // timer: a timer would keep an unattended session alive for as long as the tab
  // stayed open, and the two-day idle timeout would never come.
  const userId = user?.id;
  useEffect(() => {
    if (userId == null) return;
    let lastAsked = Date.now();
    const onReturn = () => {
      if (document.visibilityState !== "visible" || Date.now() - lastAsked < 15_000) return;
      lastAsked = Date.now();
      void refresh();
    };
    window.addEventListener("focus", onReturn);
    document.addEventListener("visibilitychange", onReturn);
    return () => {
      window.removeEventListener("focus", onReturn);
      document.removeEventListener("visibilitychange", onReturn);
    };
  }, [userId, refresh]);

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
      value={{ user, roles, permissions, employee, loading, login, logout, refresh, hasRole, updateUser }}
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
