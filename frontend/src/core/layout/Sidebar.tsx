"use client";

import Image from "next/image";
import Link from "next/link";
import { usePathname, useRouter } from "next/navigation";
import { useState } from "react";
import { BarChart3, ClipboardCheck, LayoutGrid, X } from "lucide-react";
import { useAuth, useIsManager } from "@/core/auth/AuthProvider";
import { useSidebar } from "@/core/layout/SidebarContext";
import { cn } from "@/core/lib/utils";
import { usePendingTasks, useProjects, useWorkspaces } from "@/modules/projects/hooks";
import {
  Avatar,
  ChevronLeftIcon,
  ChevronRightIcon,
  LogoutIcon,
  SidebarArchivesIcon,
  SidebarCaretIcon,
  SidebarDashboardIcon,
  SidebarGroupsIcon,
  SidebarProjectsIcon,
  SidebarTeamIcon,
  SidebarWorkspaceIcon,
} from "@/core/ui";

/** Workspaces carry no stored colour, so the swatch is assigned by position. */
const SPACE_COLORS = ["#6366f1", "#10b981", "#f59e0b", "#ef4444", "#8b5cf6"];

/** Shared nav-row geometry — icon, label, 12px gap, 12px radius (Figma 801:22341). */
const ROW = "flex items-center gap-3 rounded-xl px-3 py-2.5 text-sm font-semibold transition-colors";
const ROW_IDLE = "text-sidebar-ink/60 hover:text-sidebar-ink";
const ROW_ACTIVE = "text-brand";

function NavLink({
  href,
  active,
  icon,
  collapsed,
  badge,
  children,
}: {
  href: string;
  active: boolean;
  icon: React.ReactNode;
  /** When true, hide the label + center the icon at the `lg` breakpoint. */
  collapsed: boolean;
  /** Optional count bubble (omitted when zero/undefined). */
  badge?: number;
  children: React.ReactNode;
}) {
  return (
    <Link
      href={href}
      title={typeof children === "string" ? children : undefined}
      className={cn(
        ROW,
        active ? cn(ROW_ACTIVE, "bg-brand/10") : ROW_IDLE,
        collapsed && "lg:justify-center lg:px-2",
      )}
    >
      <span className="relative shrink-0">
        {icon}
        {/* Collapsed rows have no label to sit beside, so the count rides the icon. */}
        {!!badge && collapsed && (
          <span className="absolute -right-1.5 -top-1 hidden h-4 min-w-4 items-center justify-center rounded-full bg-danger px-1 text-[10px] font-bold text-white lg:flex">
            {badge > 99 ? "99+" : badge}
          </span>
        )}
      </span>
      <span className={cn("truncate", collapsed && "lg:hidden")}>
        {children}
      </span>
      {!!badge && (
        <span
          className={cn(
            "ml-auto flex h-5 min-w-5 items-center justify-center rounded-full bg-danger px-1.5 text-[11px] font-bold text-white",
            collapsed && "lg:hidden",
          )}
        >
          {badge > 99 ? "99+" : badge}
        </span>
      )}
    </Link>
  );
}

/**
 * A nav row that expands into a tinted panel listing its children, per the
 * Workspace / Projects dropdowns in the design. The panel wraps the parent row
 * and the children together, so the whole group reads as one teal block.
 */
function NavGroup({
  label,
  icon,
  open,
  onToggle,
  active,
  collapsed,
  railHref,
  children,
}: {
  label: string;
  icon: React.ReactNode;
  open: boolean;
  onToggle: () => void;
  /** True when the current route is inside this group. */
  active: boolean;
  collapsed: boolean;
  /** Where the collapsed rail's icon links to (the dropdown can't open there). */
  railHref: string;
  children: React.ReactNode;
}) {
  return (
    <>
      {/* Collapsed rail: the panel has nowhere to go, so the icon is a plain link. */}
      {collapsed && (
        <Link
          href={railHref}
          title={label}
          className={cn(ROW, active ? cn(ROW_ACTIVE, "bg-brand/10") : ROW_IDLE, "hidden lg:flex lg:justify-center lg:px-2")}
        >
          <span className="shrink-0">{icon}</span>
        </Link>
      )}

      <div className={cn("rounded-xl", open && "bg-brand/10", collapsed && "lg:hidden")}>
        <button
          type="button"
          onClick={onToggle}
          aria-expanded={open}
          className={cn(
            ROW,
            "w-full justify-between",
            open || active ? ROW_ACTIVE : ROW_IDLE,
          )}
        >
          <span className="flex min-w-0 items-center gap-3">
            <span className="shrink-0">{icon}</span>
            <span className="truncate">{label}</span>
          </span>
          <SidebarCaretIcon
            className={cn("h-6 w-6 shrink-0 transition-transform", open && "rotate-180")}
          />
        </button>

        {open && <div className="space-y-4 px-2.5 pb-2.5">{children}</div>}
      </div>
    </>
  );
}

/** A child row inside an expanded group — 18px glyph (or colour dot) + name. */
function NavChild({
  href,
  active,
  glyph,
  children,
}: {
  href: string;
  active: boolean;
  glyph: React.ReactNode;
  children: React.ReactNode;
}) {
  return (
    <Link
      href={href}
      className={cn(
        "flex items-center gap-3 rounded-lg text-xs transition-colors",
        active ? "text-brand" : "text-sidebar-ink/60 hover:text-sidebar-ink",
      )}
    >
      <span className="flex h-[18px] w-[18px] shrink-0 items-center justify-center">
        {glyph}
      </span>
      <span className="truncate">{children}</span>
    </Link>
  );
}

/** The "View All …" footer link of an expanded group. */
function NavChildAll({ href, children }: { href: string; children: React.ReactNode }) {
  return (
    <Link
      href={href}
      className="block text-xs text-sidebar-ink/60 underline underline-offset-2 transition-colors hover:text-sidebar-ink"
    >
      {children}
    </Link>
  );
}

export function Sidebar() {
  const pathname = usePathname();
  const router = useRouter();
  const { user, logout } = useAuth();
  const isManager = useIsManager();
  const { collapsed, toggleCollapsed, mobileOpen, closeMobile } = useSidebar();
  const { data: workspaces } = useWorkspaces();
  const { data: projectsPage } = useProjects();
  // How much work is waiting on a review decision — managers only; members have
  // no approvals endpoint (403), so the query never runs for them.
  const { data: pendingPage } = usePendingTasks(
    { per_page: 1, state: "all" },
    isManager,
  );
  const pendingCount = pendingPage?.meta.total ?? 0;
  const [spacesOpen, setSpacesOpen] = useState(false);
  const [projectsOpen, setProjectsOpen] = useState(false);

  const spaces = workspaces?.data?.slice(0, 4) ?? [];
  // For members, the sidebar lists the projects they're assigned to (the API
  // scopes `useProjects` to the current member's projects).
  // Done projects drop out of the sidebar (and every other active view).
  const myProjects = (projectsPage?.data ?? [])
    .filter((p) => p.status !== "done")
    .slice(0, 4);

  const onLogout = async () => {
    await logout();
    router.push("/login");
  };

  const inWorkspaces = pathname.startsWith("/sprintly/workspaces");
  const inProjects = pathname.startsWith("/sprintly/projects");

  // Hidden at `lg` when collapsed, but always visible on the mobile drawer
  // (which is a full-width 260px panel regardless of the collapsed flag).
  const labelHidden = collapsed ? "lg:hidden" : "";

  return (
    <>
      {/* Backdrop — only rendered for the mobile off-canvas drawer. */}
      {mobileOpen && (
        <div
          className="fixed inset-0 z-30 bg-black/50 lg:hidden"
          onClick={closeMobile}
          aria-hidden="true"
        />
      )}

      <aside
        id="app-sidebar"
        className={cn(
          "fixed inset-y-0 left-0 z-40 flex h-screen shrink-0 flex-col bg-sidebar text-sidebar-ink",
          "transition-[transform,width] duration-300 ease-in-out",
          "w-[260px] lg:static",
          collapsed ? "lg:w-[72px]" : "lg:w-[260px]",
          mobileOpen ? "translate-x-0" : "-translate-x-full",
          "lg:translate-x-0",
        )}
      >
        {/* Desktop collapse/expand control — chevron on the sidebar edge. */}
        <button
          type="button"
          onClick={toggleCollapsed}
          aria-label={collapsed ? "Expand sidebar" : "Collapse sidebar"}
          aria-expanded={!collapsed}
          className="absolute -right-3 top-15 z-10 hidden h-7 w-7 items-center justify-center rounded-full bg-brand text-sidebar-surface hover:text-sidebar-ink shadow transition-colors lg:flex"
        >
          {collapsed ? (
            <ChevronRightIcon className="h-4 w-4" />
          ) : (
            <ChevronLeftIcon className="h-4 w-4" />
          )}
        </button>

        {/* Logo + mobile close button */}
        <div
          className={cn(
            "flex items-center gap-3 px-6 pb-8 pt-6",
            collapsed && "lg:justify-center lg:px-3",
          )}
        >
          <Link href="/sprintly" aria-label="Sprintly home" className="min-w-0 flex-1">
            <Image
              src="/sprintly-logo.png"
              alt="Sprintly — intelligent project management"
              width={662}
              height={184}
              priority
              className={cn("h-auto w-full", labelHidden)}
            />
            {collapsed && (
              <Image
                src="/sprintly-mark.png"
                alt="Sprintly"
                width={196}
                height={184}
                priority
                className="hidden h-auto w-10 lg:block"
              />
            )}
          </Link>
          <button
            type="button"
            onClick={closeMobile}
            className="shrink-0 text-sidebar-ink/60 hover:text-sidebar-ink lg:hidden"
            aria-label="Close navigation menu"
          >
            <X className="h-5 w-5" />
          </button>
        </div>

        {/* Nav */}
        <nav className="sidebar-scroll flex-1 space-y-1 overflow-y-auto px-4">
          <NavLink
            href="/sprintly"
            active={pathname === "/sprintly"}
            collapsed={collapsed}
            icon={<SidebarDashboardIcon className="h-6 w-6" />}
          >
            Dashboard
          </NavLink>

          {/* Workspaces are a management surface — Admin/PM only. */}
          {isManager && (
            <NavGroup
              label="Workspace"
              icon={<SidebarWorkspaceIcon className="h-6 w-6" />}
              open={spacesOpen}
              onToggle={() => setSpacesOpen((v) => !v)}
              active={inWorkspaces}
              collapsed={collapsed}
              railHref="/sprintly/workspaces"
            >
              {spaces.length === 0 ? (
                <p className="text-xs text-sidebar-ink/40">No workspaces yet</p>
              ) : (
                spaces.map((ws, i) => (
                  <NavChild
                    key={ws.id}
                    href={`/sprintly/workspaces/${ws.id}`}
                    active={pathname === `/sprintly/workspaces/${ws.id}`}
                    glyph={
                      <span
                        className="flex h-[18px] w-[18px] items-center justify-center rounded-md text-[10px] font-bold text-white"
                        style={{
                          backgroundColor: SPACE_COLORS[i % SPACE_COLORS.length],
                        }}
                      >
                        {ws.name.charAt(0).toUpperCase()}
                      </span>
                    }
                  >
                    {ws.name}
                  </NavChild>
                ))
              )}
              <NavChildAll href="/sprintly/workspaces">View All Workspace</NavChildAll>
            </NavGroup>
          )}

          <NavGroup
            label="Projects"
            icon={<SidebarProjectsIcon className="h-6 w-6" />}
            open={projectsOpen}
            onToggle={() => setProjectsOpen((v) => !v)}
            active={inProjects}
            collapsed={collapsed}
            railHref="/sprintly/projects"
          >
            {myProjects.length === 0 ? (
              <p className="text-xs text-sidebar-ink/40">No projects yet</p>
            ) : (
              myProjects.map((p) => (
                <NavChild
                  key={p.id}
                  href={`/sprintly/projects/${p.id}`}
                  active={pathname === `/sprintly/projects/${p.id}`}
                  glyph={
                    <span
                      className="h-2.5 w-2.5 rounded-full"
                      style={{ backgroundColor: p.color ?? "#4fdbd7" }}
                    />
                  }
                >
                  {p.name}
                </NavChild>
              ))
            )}
            <NavChildAll href="/sprintly/projects">View All Projects</NavChildAll>
          </NavGroup>

          <NavLink
            href="/sprintly/groups"
            active={pathname.startsWith("/sprintly/groups")}
            collapsed={collapsed}
            icon={<SidebarGroupsIcon className="h-6 w-6" />}
          >
            Groups
          </NavLink>
          <NavLink
            href="/sprintly/team"
            active={pathname.startsWith("/sprintly/team")}
            collapsed={collapsed}
            icon={<SidebarTeamIcon className="h-6 w-6" />}
          >
            Team
          </NavLink>

          {/* Workload Analysis is open to everyone — the payload is scoped by
              role, so a member sees only their own work. */}
          <NavLink
            href="/sprintly/workload"
            active={pathname.startsWith("/sprintly/workload")}
            collapsed={collapsed}
            icon={<BarChart3 className="h-6 w-6" />}
          >
            Workload Analysis
          </NavLink>

          {/* Approvals is a management surface — Admin/PM only. The badge is the
              count of work still waiting on a review decision. */}
          {isManager && (
            <NavLink
              href="/sprintly/approvals"
              active={pathname.startsWith("/sprintly/approvals")}
              collapsed={collapsed}
              icon={<ClipboardCheck className="h-6 w-6" />}
              badge={pendingCount}
            >
              Approvals
            </NavLink>
          )}

          {/* Archives is a management surface — Admin/PM only. */}
          {isManager && (
            <NavLink
              href="/sprintly/archives"
              active={pathname.startsWith("/sprintly/archives")}
              collapsed={collapsed}
              icon={<SidebarArchivesIcon className="h-6 w-6" />}
            >
              Archives
            </NavLink>
          )}
        </nav>

        {/* Switch app — back to the launcher (all systems), consistent with the HRIS shell. */}
        <Link
          href="/"
          title="Switch app"
          className={cn(
            "mx-4 mb-1 flex items-center gap-3 rounded-xl px-3 py-2 text-sm font-semibold text-sidebar-ink/60 transition-colors hover:text-sidebar-ink",
            collapsed && "lg:justify-center lg:px-2",
          )}
        >
          <LayoutGrid className="h-5 w-5 shrink-0" />
          <span className={labelHidden}>Switch app</span>
        </Link>

        {/* User footer */}
        <div className="mx-6 border-t border-sidebar-ink/10 py-5">
          <div
            className={cn(
              "flex items-center gap-3",
              collapsed && "lg:flex-col lg:gap-2",
            )}
          >
            <Link
              href="/sprintly/profile"
              className={cn(
                "flex min-w-0 flex-1 items-center gap-3 rounded-lg transition-opacity hover:opacity-80",
                collapsed && "lg:flex-none lg:justify-center",
              )}
            >
              <Avatar name={user?.name ?? "User"} src={user?.avatar} size={36} />
              <div className={cn("min-w-0 flex-1", labelHidden)}>
                <p className="truncate text-sm font-semibold text-sidebar-ink">
                  {user?.name ?? "Loading…"}
                </p>
                <p className="truncate text-xs text-sidebar-ink/50">
                  {user?.role ?? "Member"}
                </p>
              </div>
            </Link>
            <button
              type="button"
              onClick={onLogout}
              className="shrink-0 text-sidebar-ink/50 transition-colors hover:text-danger"
              aria-label="Log out"
            >
              <LogoutIcon className="h-5 w-5" />
            </button>
          </div>
        </div>
      </aside>
    </>
  );
}
