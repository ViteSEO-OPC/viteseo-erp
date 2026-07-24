"use client";

import { useState, useEffect } from "react";
import Link from "next/link";
import { usePathname } from "next/navigation";
import { Zap, User, LogOut, ChevronUp, ChevronDown, Repeat, LayoutGrid } from "lucide-react";

import { cn } from "@/lib/utils";
import { useAuth } from "@/core/auth-context";
import { getAccessibleModules } from "@/core/access";
import { useNotificationCenter } from "@/core/notifications-context";
import {
  navByVariant,
  workspaceLabel,
  roleLabel,
  initials,
  isGroup,
  type ShellVariant,
  type NavLink as NavLinkType,
  type NavGroup as NavGroupType,
} from "@/core/nav";

function isActiveLink(pathname: string, href: string): boolean {
  // /teamora is both the HR dashboard and the employee overview root — match it
  // exactly so it doesn't light up for every /teamora/* sub-page.
  if (href === "/teamora") return pathname === href;
  return pathname === href || pathname.startsWith(href + "/");
}

function Badge({ count }: { count: number }) {
  return (
    <span className="flex h-5 min-w-5 items-center justify-center rounded-full bg-danger px-1.5 text-xs font-semibold text-white">
      {count}
    </span>
  );
}

function NavLink({
  item,
  active,
  badge,
  nested = false,
}: {
  item: NavLinkType;
  active: boolean;
  badge: number;
  /** Sub-links inside a group sit indented under the group icon. */
  nested?: boolean;
}) {
  const Icon = item.icon;
  return (
    <Link
      href={item.href}
      className={cn(
        "relative flex items-center gap-3 rounded-lg py-2 text-nav font-medium transition-colors",
        nested ? "pl-10 pr-3" : "px-3",
        active && !nested
          ? "overflow-hidden bg-dark-teal text-white before:absolute before:inset-y-0 before:left-0 before:w-3.5 before:[background:radial-gradient(100%_230%_at_100%_50%,transparent_49%,var(--brand)_50%)]"
          : active && nested
            ? "text-brand underline decoration-brand decoration-2 underline-offset-4"
            : "text-white hover:bg-sidebar-accent hover:text-white",
      )}
    >
      {!nested && <Icon className="size-[18px] shrink-0" />}
      <span className="flex-1 truncate">{item.label}</span>
      {badge > 0 ? <Badge count={badge} /> : null}
    </Link>
  );
}

function NavGroupItem({
  group,
  pathname,
  navBadges,
}: {
  group: NavGroupType;
  pathname: string;
  navBadges: Record<string, number>;
}) {
  const Icon = group.icon;
  const hasActiveChild = group.children.some((c) => isActiveLink(pathname, c.href));

  const [expanded, setExpanded] = useState(false);

  // Auto-open the group when navigating to a child page; manual toggle
  // overrides after that so the user can close the group at will.
  useEffect(() => {
    if (hasActiveChild) {
      setExpanded(true);
    }
  }, [hasActiveChild]);

  const open = expanded;

  const badge = group.children.reduce((sum, c) => sum + (navBadges[c.href] ?? 0), 0);

  return (
    <div>
      <button
        type="button"
        aria-expanded={open}
        onClick={() => setExpanded((e) => !e)}
        className={cn(
          "relative flex w-full items-center gap-3 rounded-lg px-3 py-2 text-nav font-medium transition-colors",
          hasActiveChild
            ? "overflow-hidden bg-dark-teal text-white before:absolute before:inset-y-0 before:left-0 before:w-3.5 before:[background:radial-gradient(100%_230%_at_100%_50%,transparent_49%,var(--brand)_50%)]"
            : "text-white hover:bg-sidebar-accent",
        )}
      >
        <Icon className="size-[18px] shrink-0" />
        <span className="flex-1 truncate text-left">{group.label}</span>
        {!open && badge > 0 ? <Badge count={badge} /> : null}
        <ChevronDown
          className={cn(
            "size-4 shrink-0 text-sidebar-foreground transition-transform",
            open && "rotate-180",
          )}
        />
      </button>

      {open ? (
        <div className="mt-1 space-y-1">
          {group.children.map((child) => (
            <NavLink
              key={child.href}
              item={child}
              active={isActiveLink(pathname, child.href)}
              badge={navBadges[child.href] ?? 0}
              nested
            />
          ))}
        </div>
      ) : null}
    </div>
  );
}

export function Sidebar({
  variant,
  canSwitchView = false,
  onSwitchView,
}: {
  variant: ShellVariant;
  /**
   * Whether to offer the HR ⇄ employee view toggle. The owning portal decides —
   * this used to be a `hasRole("hr_officer")` check here, which disagreed with the
   * portal's own rule and hid the control from super admins. One authority only:
   * TeamOra passes `useViewMode().canSwitch`.
   */
  canSwitchView?: boolean;
  /** Perform the switch. Without it the control does nothing, so it isn't shown. */
  onSwitchView?: () => void;
}) {
  const pathname = usePathname();
  const { user, roles, permissions, employee, logout, hasRole } = useAuth();
  const { navBadges } = useNotificationCenter();
  const [menuOpen, setMenuOpen] = useState(false);
  // Multi-system users get a "Switch app" jump back to the launcher.
  const canSwitchApp =
    getAccessibleModules(roles as unknown as string[], permissions).length > 1;

  const items = navByVariant[variant];

  return (
    <aside className="flex h-screen w-64 shrink-0 flex-col bg-sidebar text-sidebar-foreground">
      {/* Brand block */}
      <div className="flex items-center gap-2.5 px-5 py-5">
        <span className="flex size-8 items-center justify-center rounded-lg bg-brand text-brand-foreground">
          <Zap className="size-5" fill="currentColor" />
        </span>
        <span className="text-h3 font-bold text-white">TeamOra</span>
      </div>

      {/* Nav */}
      <nav className="sidebar-scroll flex-1 space-y-1 overflow-y-auto px-3 py-2">
        <p className="px-3 pb-2 text-[11px] font-semibold tracking-wider text-sidebar-foreground/60">
          {workspaceLabel[variant]}
        </p>
        {items.map((entry) => {
          if (isGroup(entry)) {
            // Role-gated groups (e.g. Company = HR-officer/super-admin only).
            if (
              entry.requiresRole &&
              !hasRole(entry.requiresRole) &&
              !hasRole("super_admin")
            ) {
              return null;
            }
            return (
              <NavGroupItem
                key={entry.label}
                group={entry}
                pathname={pathname}
                navBadges={navBadges}
              />
            );
          }
          return (
            <NavLink
              key={entry.href}
              item={entry}
              active={isActiveLink(pathname, entry.href)}
              badge={navBadges[entry.href] ?? 0}
            />
          );
        })}
      </nav>

      {/* User card → menu (profile / log out) */}
      <div className="relative p-3">
        {menuOpen && (
          <>
            <div className="fixed inset-0 z-10" onClick={() => setMenuOpen(false)} />
            <div className="absolute bottom-full left-3 right-3 z-20 mb-1 overflow-hidden rounded-xl border border-border bg-popover py-1 shadow-lg">
              {canSwitchApp && (
                <Link
                  href="/"
                  onClick={() => setMenuOpen(false)}
                  className="flex items-center gap-2 px-3 py-2 text-sm text-foreground hover:bg-muted"
                >
                  <LayoutGrid className="size-4" />
                  Switch app
                </Link>
              )}
              {canSwitchView && onSwitchView && (
                <button
                  type="button"
                  onClick={() => {
                    setMenuOpen(false);
                    onSwitchView();
                  }}
                  className="flex w-full items-center gap-2 px-3 py-2 text-sm text-foreground hover:bg-muted"
                >
                  <Repeat className="size-4" />
                  Switch to {variant === "hr" ? "Employee" : "HR"} view
                </button>
              )}
              <Link
                href="/teamora/profile"
                onClick={() => setMenuOpen(false)}
                className="flex items-center gap-2 px-3 py-2 text-sm text-foreground hover:bg-muted"
              >
                <User className="size-4" />
                My Profile
              </Link>
              <button
                type="button"
                onClick={() => {
                  setMenuOpen(false);
                  logout();
                }}
                className="flex w-full items-center gap-2 px-3 py-2 text-sm text-foreground hover:bg-muted"
              >
                <LogOut className="size-4" />
                Log out
              </button>
            </div>
          </>
        )}

        <button
          type="button"
          onClick={() => setMenuOpen((o) => !o)}
          className="flex w-full items-center gap-3 rounded-card bg-sidebar-accent px-3 py-3 text-left transition-colors hover:bg-sidebar-accent/70"
        >
          <span className="flex size-9 shrink-0 items-center justify-center rounded-full bg-brand text-sm font-semibold text-brand-foreground">
            {user ? initials(user.name) : "?"}
          </span>
          <div className="min-w-0 flex-1">
            <p className="truncate text-sm font-semibold text-white">
              {user?.name ?? "—"}
            </p>
            <p className="truncate text-xs text-sidebar-foreground">
              {employee?.job_title ?? roleLabel(roles)}
            </p>
          </div>
          <ChevronUp
            className={cn(
              "size-4 shrink-0 text-sidebar-foreground transition-transform",
              menuOpen && "rotate-180",
            )}
          />
        </button>
      </div>
    </aside>
  );
}
