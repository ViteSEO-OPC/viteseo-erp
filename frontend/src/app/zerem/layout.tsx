"use client";

import { useEffect } from "react";
import { usePathname, useRouter } from "next/navigation";

import { useAuth } from "@/modules/crm/providers/auth-provider";
import { PermissionsProvider } from "@/modules/crm/providers/permissions-provider";
import { ThemeProvider } from "@/modules/crm/providers/theme-provider";
import { canAccessPath } from "@/modules/crm/lib/rbac";
import { AppShell } from "@/modules/crm/components/layout/app-shell/app-shell";

/**
 * CRM (Zerem) section shell. Lives under the platform's root <AuthProvider>
 * (single shared login), so it does NOT re-mount auth/query — it only adds the
 * CRM module's own theme + permissions context and its self-contained AppShell
 * (sidebar/topbar/header-search). Auth redirects use the SHARED /login.
 */
export default function CrmLayout({ children }: { children: React.ReactNode }) {
  const { user, isAuthenticated, isLoading } = useAuth();
  const router = useRouter();
  const pathname = usePathname();

  // RoleGuard: /crm/dashboard is the universal CRM landing page — always reachable
  // (prevents redirect loops) and the fallback for role-forbidden routes.
  const roleAllowed =
    pathname === "/zerem/dashboard" || canAccessPath(user?.roles, pathname);

  useEffect(() => {
    if (isLoading) return;
    if (!isAuthenticated) {
      router.push("/login");
      return;
    }
    if (!roleAllowed) {
      router.replace("/zerem/dashboard");
    }
  }, [isLoading, isAuthenticated, roleAllowed, router]);

  if (isLoading) {
    return (
      <div className="flex h-screen items-center justify-center bg-cream">
        <div className="h-8 w-8 animate-spin rounded-full border-2 border-teal-400 border-t-transparent" />
      </div>
    );
  }

  if (!isAuthenticated) return null;
  if (!roleAllowed) return null;

  return (
    <ThemeProvider>
      <PermissionsProvider>
        {/* `font-primary` (Exo) is scoped here so CRM keeps its typeface without
            changing the platform's global body font. */}
        <div className="font-primary">
          <AppShell>{children}</AppShell>
        </div>
      </PermissionsProvider>
    </ThemeProvider>
  );
}
