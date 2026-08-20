"use client";

import { RequireAuth } from "@/core/auth-context";
import { RealtimeProvider } from "@/core/realtime/RealtimeProvider";
import { ProjectNotificationListener } from "@/modules/projects/components/projects/project-notification-listener";
import { Sidebar } from "@/core/layout/Sidebar";
import { Topbar } from "@/core/layout/Topbar";
import { SidebarProvider } from "@/core/layout/SidebarContext";
import { GlobalTaskModal } from "@/modules/projects/components/tasks/global-task-modal";

/**
 * Projects section shell. Uses the platform's shared <RequireAuth> (single
 * login) plus the Projects module's own Sidebar/Topbar. The GlobalTaskModal is
 * mounted once so any projects route can open a task via `?task={uuid}`
 * (deep-links, unassigned tasks, activity feed, deadline lists, board cards, …).
 */
export default function ProjectsLayout({
  children,
}: {
  children: React.ReactNode;
}) {
  return (
    <RequireAuth>
      <RealtimeProvider>
        {/* Projects' own live-notification handler. It used to be embedded inside
            RealtimeProvider, which is how platform infrastructure ended up owning one
            module's business logic — and how its cleanup could silence the CRM's
            handler on the shared `user.{id}` channel. Mounted explicitly here. */}
        <ProjectNotificationListener />
        <SidebarProvider>
          <div className="flex h-screen overflow-hidden bg-canvas">
            <Sidebar />
            <main className="min-w-0 flex-1 overflow-y-auto">
              <Topbar />
              {children}
            </main>
          </div>
        </SidebarProvider>
        <GlobalTaskModal />
      </RealtimeProvider>
    </RequireAuth>
  );
}
