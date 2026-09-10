"use client";

import { useMemo } from "react";
import {
  Archive,
  BarChart3,
  ClipboardCheck,
  Folder,
  FolderKanban,
  KeyRound,
  Layers,
  LayoutGrid,
  Users,
  UsersRound,
} from "lucide-react";

import { RequireAuth } from "@/core/auth-context";
import { useIsManager } from "@/core/auth/AuthProvider";
import { AppShell } from "@/core/components/app-shell";
import type { NavEntry } from "@/core/nav";
import { RealtimeProvider } from "@/core/realtime/RealtimeProvider";
import { AccessChangedListener } from "@/core/realtime/access-changed-listener";
import { RequireModule } from "@/core/require-module";
import { NotificationBell } from "@/modules/projects/components/notifications/notification-bell";
import { ProjectNotificationListener } from "@/modules/projects/components/projects/project-notification-listener";
import { GlobalSearch } from "@/modules/projects/components/shared/global-search";
import { GlobalTaskModal } from "@/modules/projects/components/tasks/global-task-modal";
import { usePendingTasks } from "@/modules/projects/hooks/use-approvals";
import { useProjects } from "@/modules/projects/hooks/use-projects";
import { useWorkspaces } from "@/modules/projects/hooks/use-workspaces";

/** How many live workspaces/projects the nav groups list before "View All". */
const NAV_CHILD_CAP = 4;

/**
 * Sprintly's nav is data-driven (workspaces + the user's live projects) and
 * role-driven, so it is built per render and handed to AppShell via `navItems`
 * — `navByVariant.projects` is deliberately empty.
 *
 * Every destination and every gate is carried over from the module's old
 * sidebar unchanged:
 *  · Workspace / Approvals / Team & Roles / Archives are manager-only
 *    (`useIsManager` = the platform's Admin/PM tier, as before).
 *  · Workload Analysis is open to everyone — the payload is role-scoped, so a
 *    member sees only their own work.
 *  · "Team" owns /sprintly/team plus its manager/member sub-pages but NOT the
 *    sibling /sprintly/team/accounts, which is "Team & Roles" — hence the
 *    `exact` + `activePrefixes` pair, which is the shared Sidebar's form of
 *    the startsWith fix the old sidebar spelled out inline.
 */
function useSprintlyNav(): { items: NavEntry[]; badges: Record<string, number> } {
  const isManager = useIsManager();
  const { data: workspaces } = useWorkspaces();
  const { data: projectsPage } = useProjects();
  // How much work is waiting on a review decision — managers only; members have
  // no approvals endpoint (403), so the query never runs for them.
  const { data: pendingPage } = usePendingTasks(
    { per_page: 1, state: "all" },
    isManager,
  );
  const pendingCount = pendingPage?.meta.total ?? 0;

  const spaces = useMemo(
    () => workspaces?.data?.slice(0, NAV_CHILD_CAP) ?? [],
    [workspaces],
  );
  // For members this is the set of projects they're assigned to (the API scopes
  // `useProjects` to the caller). Done projects drop out of the nav, as they do
  // from every other active view.
  const myProjects = useMemo(
    () =>
      (projectsPage?.data ?? [])
        .filter((p) => p.status !== "done")
        .slice(0, NAV_CHILD_CAP),
    [projectsPage],
  );

  const items = useMemo<NavEntry[]>(() => {
    const nav: NavEntry[] = [
      // Every Sprintly route sits under /sprintly, so the dashboard row can
      // only be matched exactly or it lights up on every page.
      { label: "Dashboard", href: "/sprintly", icon: LayoutGrid, exact: true },
    ];

    // Workspaces are a management surface — Admin/PM only.
    if (isManager) {
      nav.push({
        label: "Workspace",
        icon: Layers,
        children: [
          ...spaces.map((ws) => ({
            label: ws.name,
            href: `/sprintly/workspaces/${ws.id}`,
            icon: Folder,
            exact: true,
          })),
          // Sits in the same group as the workspaces it prefixes, so it too
          // has to be exact or it lights up alongside whichever one is open.
          {
            label: "View All Workspace",
            href: "/sprintly/workspaces",
            icon: Layers,
            exact: true,
          },
        ],
      });
    }

    nav.push({
      label: "Projects",
      icon: FolderKanban,
      children: [
        ...myProjects.map((p) => ({
          label: p.name,
          href: `/sprintly/projects/${p.id}`,
          icon: Folder,
          exact: true,
        })),
        {
          label: "View All Projects",
          href: "/sprintly/projects",
          icon: FolderKanban,
          exact: true,
        },
      ],
    });

    nav.push(
      { label: "Groups", href: "/sprintly/groups", icon: UsersRound },
      // Team is the project-side view of the same people — groups, workload,
      // who is on what. Team & Roles below owns the accounts and the access,
      // so this row must not light up for it.
      {
        label: "Team",
        href: "/sprintly/team",
        icon: Users,
        exact: true,
        activePrefixes: ["/sprintly/team/manager", "/sprintly/team/member"],
      },
      // Open to everyone: the payload is role-scoped, so a member sees only
      // their own work.
      { label: "Workload Analysis", href: "/sprintly/workload", icon: BarChart3 },
    );

    if (isManager) {
      // The badge is the count of work still waiting on a review decision.
      nav.push({ label: "Approvals", href: "/sprintly/approvals", icon: ClipboardCheck });
      // Team & Roles — staff accounts AND the org-wide role builder, on one
      // page. Admin/PM only, matching every other management row here.
      nav.push({ label: "Team & Roles", href: "/sprintly/team/accounts", icon: KeyRound });
      nav.push({ label: "Archives", href: "/sprintly/archives", icon: Archive });
    }

    return nav;
  }, [isManager, spaces, myProjects]);

  return { items, badges: { "/sprintly/approvals": pendingCount } };
}

/**
 * The shell itself, mounted INSIDE <RequireAuth> so the nav's data hooks
 * (workspaces / projects / approvals) never fire before the session resolves.
 */
function SprintlyShell({ children }: { children: React.ReactNode }) {
  const { items, badges } = useSprintlyNav();

  return (
    <AppShell
      variant="projects"
      navItems={items}
      navBadges={badges}
      // Sprintly's ⌘K search takes the header's search position.
      searchSlot={<GlobalSearch />}
      // Sprintly's own feed. Also what suppresses the shell's built-in bell,
      // which is the HR notification feed and does not belong in this portal.
      rightSlot={<NotificationBell />}
    >
      {children}
    </AppShell>
  );
}

/**
 * Projects section shell. Uses the platform's shared <RequireAuth> (single
 * login) and the platform-standard <AppShell> (static sidebar at lg+, off-canvas
 * drawer below it, standard header) — it used to carry its own Sidebar/Topbar
 * pair from `core/layout`, which is why this portal's frame never matched the
 * others'. The GlobalTaskModal is mounted once so any projects route can open a
 * task via `?task={uuid}` (deep-links, unassigned tasks, activity feed, deadline
 * lists, board cards, …).
 */
export default function ProjectsLayout({
  children,
}: {
  children: React.ReactNode;
}) {
  return (
    <RequireAuth>
      <RequireModule module="projects">
        <RealtimeProvider>
          {/* Projects' own live-notification handler. It used to be embedded inside
              RealtimeProvider, which is how platform infrastructure ended up owning one
              module's business logic — and how its cleanup could silence the CRM's
              handler on the shared `user.{id}` channel. Mounted explicitly here. */}
          <ProjectNotificationListener />
          {/* Access changed on the server: re-read who this is, and leave if Projects is gone. */}
          <AccessChangedListener />
          <SprintlyShell>{children}</SprintlyShell>
          <GlobalTaskModal />
        </RealtimeProvider>
      </RequireModule>
    </RequireAuth>
  );
}
