"use client";

import Link from "next/link";
import { usePathname, useRouter } from "next/navigation";
import { useState } from "react";
import { LayoutGrid, X } from "lucide-react";
import { useAuth, useIsManager } from "@/core/auth/AuthProvider";
import { useSidebar } from "@/core/layout/SidebarContext";
import { cn } from "@/core/lib/utils";
import { useProjects, useWorkspaces } from "@/modules/projects/hooks";
import {
  ArchiveIcon,
  Avatar,
  ChevronDownIcon,
  ChevronLeftIcon,
  ChevronRightIcon,
  DashboardIcon,
  FolderIcon,
  LogoutIcon,
  PlusIcon,
  UsersIcon,
} from "@/core/ui";

const SPACE_COLORS = ["#6366f1", "#10b981", "#f59e0b", "#ef4444", "#8b5cf6"];

function NavLink({
  href,
  active,
  icon,
  collapsed,
  children,
}: {
  href: string;
  active: boolean;
  icon: React.ReactNode;
  /** When true, hide the label + center the icon at the `lg` breakpoint. */
  collapsed: boolean;
  children: React.ReactNode;
}) {
  return (
    <Link
      href={href}
      className={cn(
        "flex items-center gap-3 rounded-lg px-3 py-2.5 text-sm font-bold transition-colors",
        active
          ? "bg-white/10 text-brand"
          : "text-white/70 hover:bg-white/5 hover:text-white",
        collapsed && "lg:justify-center lg:px-2",
      )}
    >
      <span className="shrink-0">{icon}</span>
      <span className={cn("truncate", collapsed && "lg:hidden")}>
        {children}
      </span>
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
  const [spacesOpen, setSpacesOpen] = useState(true);
  const [projectsOpen, setProjectsOpen] = useState(true);

  const spaces = workspaces?.data?.slice(0, 4) ?? [];
  // For members, the sidebar lists the projects they're assigned to (the API
  // scopes `useProjects` to the current member's projects).
  // Done projects drop out of the sidebar (and every other active view).
  const myProjects = (projectsPage?.data ?? []).filter(
    (p) => p.status !== "done",
  );

  const onLogout = async () => {
    await logout();
    router.push("/login");
  };

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
          "fixed inset-y-0 left-0 z-40 flex h-screen shrink-0 flex-col bg-sidebar text-white",
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
          className="absolute -right-3 top-7 z-10 hidden h-6 w-6 items-center justify-center rounded-full border border-line-dark bg-sidebar text-white/70 shadow transition-colors hover:text-white lg:flex"
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
            "flex items-center gap-3 px-6 py-6",
            collapsed && "lg:justify-center lg:px-4",
          )}
        >
          <span className="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-brand">
            <FolderIcon className="h-5 w-5 text-ink" />
          </span>
          <span className={cn("text-xl font-bold", labelHidden)}>Sprintly</span>
          <button
            type="button"
            onClick={closeMobile}
            className="ml-auto text-white/60 hover:text-white lg:hidden"
            aria-label="Close navigation menu"
          >
            <X className="h-5 w-5" />
          </button>
        </div>

        {/* Nav */}
        <nav className="flex-1 space-y-1 overflow-y-auto px-4">
          <NavLink
            href="/sprintly"
            active={pathname === "/sprintly"}
            collapsed={collapsed}
            icon={<DashboardIcon className="h-5 w-5" />}
          >
            Dashboard
          </NavLink>

          {isManager ? (
            <>
              {/* Work Spaces group — hidden on the collapsed rail. */}
              <div className={labelHidden}>
                <div className="flex items-center justify-between rounded-lg px-3 py-2.5 text-sm font-bold text-white/70">
                  <button
                    type="button"
                    onClick={() => setSpacesOpen((v) => !v)}
                    className="flex items-center gap-2 hover:text-white"
                  >
                    {spacesOpen ? (
                      <ChevronDownIcon className="h-4 w-4" />
                    ) : (
                      <ChevronRightIcon className="h-4 w-4" />
                    )}
                    Work Spaces
                  </button>
                  <Link
                    href="/sprintly/workspaces"
                    className="text-white/60 hover:text-brand"
                    aria-label="New workspace"
                  >
                    <PlusIcon className="h-4 w-4" />
                  </Link>
                </div>

                {spacesOpen && (
                  <div className="mt-1 space-y-1 pl-2">
                    {spaces.map((ws, i) => (
                      <Link
                        key={ws.id}
                        href={`/sprintly/workspaces/${ws.id}`}
                        className={`flex items-center gap-3 rounded-lg px-3 py-2 text-sm font-semibold transition-colors ${
                          pathname === `/sprintly/workspaces/${ws.id}`
                            ? "bg-white/10 text-white"
                            : "text-white/70 hover:bg-white/5 hover:text-white"
                        }`}
                      >
                        <span
                          className="flex h-6 w-6 items-center justify-center rounded-md text-xs font-bold text-white"
                          style={{
                            backgroundColor:
                              SPACE_COLORS[i % SPACE_COLORS.length],
                          }}
                        >
                          {ws.name.charAt(0).toUpperCase()}
                        </span>
                        <span className="truncate">{ws.name}</span>
                      </Link>
                    ))}
                    <Link
                      href="/sprintly/workspaces"
                      className="block px-3 py-2 text-sm font-semibold text-brand underline underline-offset-2 hover:text-brand-hover"
                    >
                      View all Spaces
                    </Link>
                  </div>
                )}
              </div>

              <NavLink
                href="/sprintly/projects"
                active={pathname === "/sprintly/projects"}
                collapsed={collapsed}
                icon={<FolderIcon className="h-5 w-5" />}
              >
                Projects
              </NavLink>
              <NavLink
                href="/sprintly/groups"
                active={pathname.startsWith("/sprintly/groups")}
                collapsed={collapsed}
                icon={<UsersIcon className="h-5 w-5" />}
              >
                Groups
              </NavLink>
              <NavLink
                href="/sprintly/team"
                active={pathname.startsWith("/sprintly/team")}
                collapsed={collapsed}
                icon={<UsersIcon className="h-5 w-5" />}
              >
                Team
              </NavLink>

              <NavLink
                href="/sprintly/archives"
                active={pathname.startsWith("/sprintly/archives")}
                collapsed={collapsed}
                icon={<ArchiveIcon className="h-5 w-5" />}
              >
                Archives
              </NavLink>
            </>
          ) : (
            /* Member nav — Projects dropdown listing their joined projects only */
            <div>
              <button
                type="button"
                onClick={() => setProjectsOpen((v) => !v)}
                className={cn(
                  "flex w-full items-center gap-3 rounded-lg px-3 py-2.5 text-sm font-bold text-white/70 transition-colors hover:bg-white/5 hover:text-white",
                  collapsed && "lg:justify-center lg:px-2",
                )}
              >
                <FolderIcon className="h-5 w-5 shrink-0" />
                <span className={cn("flex-1 text-left", labelHidden)}>
                  Projects
                </span>
                {projectsOpen ? (
                  <ChevronDownIcon className={cn("h-4 w-4", labelHidden)} />
                ) : (
                  <ChevronRightIcon className={cn("h-4 w-4", labelHidden)} />
                )}
              </button>

              {projectsOpen && (
                <div className={cn("mt-1 space-y-1 pl-2", labelHidden)}>
                  {myProjects.length === 0 ? (
                    <p className="px-3 py-2 text-xs text-white/40">
                      No projects yet
                    </p>
                  ) : (
                    myProjects.map((p) => (
                      <Link
                        key={p.id}
                        href={`/sprintly/projects/${p.id}`}
                        className={`flex items-center gap-3 rounded-lg px-3 py-2 text-sm font-semibold transition-colors ${
                          pathname === `/sprintly/projects/${p.id}`
                            ? "bg-white/10 text-white"
                            : "text-white/70 hover:bg-white/5 hover:text-white"
                        }`}
                      >
                        <span
                          className="h-2.5 w-2.5 shrink-0 rounded-full"
                          style={{ backgroundColor: p.color ?? "#4fdbd7" }}
                        />
                        <span className="truncate">{p.name}</span>
                      </Link>
                    ))
                  )}
                  <Link
                    href="/sprintly/projects"
                    className="block px-3 py-2 text-sm font-semibold text-white/70 underline underline-offset-2 hover:text-brand-hover"
                  >
                    View all Projects
                  </Link>
                </div>
              )}

              <NavLink
                href="/sprintly/groups"
                active={pathname.startsWith("/sprintly/groups")}
                collapsed={collapsed}
                icon={<UsersIcon className="h-5 w-5" />}
              >
                Groups
              </NavLink>
              <NavLink
                href="/sprintly/team"
                active={pathname.startsWith("/sprintly/team")}
                collapsed={collapsed}
                icon={<UsersIcon className="h-5 w-5" />}
              >
                Team
              </NavLink>
            </div>
          )}
        </nav>

        {/* Switch app — back to the launcher (all systems), consistent with the HRIS shell. */}
        <Link
          href="/"
          title="Switch app"
          className={cn(
            "mx-4 mb-1 flex items-center gap-3 rounded-lg px-2 py-2 text-sm font-medium text-white/80 transition-colors hover:bg-white/10 hover:text-white",
            collapsed && "lg:justify-center lg:px-1",
          )}
        >
          <LayoutGrid className="h-5 w-5 shrink-0" />
          {!collapsed && <span>Switch app</span>}
        </Link>

        {/* User footer */}
        <div className="border-t border-line-dark px-4 py-4">
          <div
            className={cn(
              "flex items-center gap-3",
              collapsed && "lg:flex-col lg:gap-2",
            )}
          >
            <Link
              href="/sprintly/profile"
              className={cn(
                "flex min-w-0 flex-1 items-center gap-3 rounded-lg px-2 py-1.5 transition-colors",
                pathname === "/sprintly/profile"
                  ? "bg-white/10"
                  : "hover:bg-white/5",
                collapsed && "lg:flex-none lg:justify-center lg:px-1",
              )}
            >
              <Avatar name={user?.name ?? "User"} src={user?.avatar} size={36} />
              <div className={cn("min-w-0 flex-1", labelHidden)}>
                <p className="truncate text-sm font-bold text-white">
                  {user?.name ?? "Loading…"}
                </p>
                <p className="truncate text-xs text-white/50">
                  {user?.role ?? "Member"}
                </p>
              </div>
            </Link>
            <button
              type="button"
              onClick={onLogout}
              className="shrink-0 text-white/50 hover:text-danger"
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
