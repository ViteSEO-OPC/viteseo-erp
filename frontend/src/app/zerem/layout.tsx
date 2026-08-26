"use client";

import { useEffect } from "react";
import { usePathname, useRouter } from "next/navigation";

import { useAuth } from "@/modules/crm/providers/auth-provider";
import { RealtimeProvider } from "@/core/realtime/RealtimeProvider";
import { LeadRealtimeListener } from "@/modules/crm/features/leads/components/lead-realtime-listener";
import { NotificationRealtimeListener } from "@/modules/crm/features/notifications/components/notification-realtime-listener";
import { ProjectNotificationListener } from "@/modules/projects/components/projects/project-notification-listener";
import { PermissionsProvider } from "@/modules/crm/providers/permissions-provider";
import { TableDensityProvider } from "@/modules/crm/providers/table-density-provider";
import { ThemeProvider } from "@/modules/crm/providers/theme-provider";
import { canAccessPath } from "@/modules/crm/lib/rbac";
import { AppShell } from "@/modules/crm/components/layout/app-shell/app-shell";
// Zerem-only dark theme. Imported here (the CRM section layout) so Next bundles it for `/zerem/*`
// routes ONLY — it never loads on HRIS/Sprintly/Admin. Scoped to `.zerem-theme-scope`; touches no
// shared stylesheet.
import "@/modules/crm/styles/zerem-theme.css";

/**
 * CRM (Zerem) section shell. Lives under the platform's root <AuthProvider>
 * (single shared login), so it does NOT re-mount auth/query — it only adds the
 * CRM module's own theme + permissions context and its self-contained AppShell
 * (sidebar/topbar/header-search). Auth redirects use the SHARED /login.
 *
 * This is also where the realtime layer starts. RealtimeProvider is mounted HERE
 * rather than in the root layout so the WebSocket is CRM-scoped: HRIS, Sprintly
 * and the rest of the platform open no socket and subscribe no channel. It reads
 * the platform auth context and the query cache, both provided by the root
 * layout above, and stays inert without NEXT_PUBLIC_REVERB_APP_KEY.
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
      {/* Compact Tables — a Zerem-scoped, localStorage-backed display preference, mounted beside
          the theme for the same reason: it must not leak into HRIS / Sprintly / Admin. */}
      <TableDensityProvider>
      <PermissionsProvider>
        <RealtimeProvider>
          {/* "A lead was assigned to me" toasts, live on every CRM screen. */}
          <LeadRealtimeListener />
          {/* Live bell/badge updates, wherever the user is in the CRM. */}
          <NotificationRealtimeListener />
          {/* Projects notifications ride the same shared `user.{id}` channel and were
              previously handled inside RealtimeProvider — which is exactly how the
              platform's transport ended up able to unsubscribe a module's listeners.
              Mounted here so the CRM shell keeps caching them as it always has, now
              as a peer of the listener above rather than as a hidden side effect. */}
          <ProjectNotificationListener />
          {/* `font-primary` (Exo) is scoped here so CRM keeps its typeface without
              changing the platform's global body font. */}
          <div className="font-primary">
            <AppShell>{children}</AppShell>
          </div>
        </RealtimeProvider>
      </PermissionsProvider>
      </TableDensityProvider>
    </ThemeProvider>
  );
}
