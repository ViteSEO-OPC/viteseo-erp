"use client";

import { useState, useEffect } from "react";
import Link from "next/link";
import { usePathname } from "next/navigation";
import { ChevronDown, ChevronsLeft, ChevronsRight, Lock } from "lucide-react";

import { cn } from "@/lib/utils";
import { useAuth } from "@/core/auth-context";
import { useFeatures } from "@/core/use-features";
import { useConnections } from "@/core/use-connections";
import { notify } from "@/core/lib/notify";
import { useNotificationCenter } from "@/core/notifications-context";
import {
  navByVariant,
  workspaceLabel,
  brandLabel,
  brandIcon,
  isGroup,
  type ShellVariant,
  type NavEntry,
  type NavLink as NavLinkType,
  type NavGroup as NavGroupType,
} from "@/core/nav";

// Base-path links that are ALSO the prefix of every sibling route (so
// startsWith() would keep them lit on every sub-page) — match these exactly.
// /teamora = HR/employee root; /accounting = Chart of Accounts (all accounting
// pages live under /accounting/…). A nav built per render declares the same
// rule per entry with `exact`, since its hrefs cannot be listed here.
const EXACT_ONLY_HREFS = new Set(["/teamora", "/accounting"]);

function isActiveLink(pathname: string, item: NavLinkType): boolean {
  // Sibling routes that belong to this row but do not sit under its href
  // (Sprintly's Team owns /team/manager and /team/member, not /team/accounts).
  if (item.activePrefixes?.some((p) => isWithin(pathname, p))) return true;
  if (item.exact || EXACT_ONLY_HREFS.has(item.href)) return pathname === item.href;
  return isWithin(pathname, item.href);
}

/** Plain prefix containment, ignoring the exact-only rules. A group opens for any
    page BELOW one of its children (e.g. a project detail page that is not among
    the few projects the nav lists), which is a different question from which
    single link lights up. */
function isWithin(pathname: string, href: string): boolean {
  return pathname === href || pathname.startsWith(href + "/");
}

function Badge({ count }: { count: number }) {
  return (
    <span className="flex h-5 min-w-5 items-center justify-center rounded-full bg-danger px-1.5 text-xs font-semibold text-white">
      {/* Clamped: some feeds are totals, not queues (Zerem's Leads badge is the
          org's whole lead count), and four digits burst the pill. */}
      {count > 99 ? "99+" : count}
    </span>
  );
}

/** Collapsed rail can't show a count — an attention dot on the icon instead. */
function BadgeDot() {
  return <span className="absolute right-2 top-1.5 size-2 rounded-full bg-danger" />;
}

function NavLink({
  item,
  active,
  badge,
  nested = false,
  locked = false,
  collapsed = false,
}: {
  item: NavLinkType;
  active: boolean;
  badge: number;
  /** Sub-links inside a group sit indented under the group icon. */
  nested?: boolean;
  /** Feature not availed → shown locked with an upsell hint instead of a link. */
  locked?: boolean;
  /** Icon-rail mode: icon only, label via title, badges become dots. */
  collapsed?: boolean;
}) {
  const Icon = item.icon;

  if (locked) {
    return (
      <button
        type="button"
        onClick={() => notify.info(`“${item.label}” needs the ${item.upsell ?? "add-on"} plan — ask your admin to avail it.`)}
        className={cn(
          "relative flex w-full items-center gap-3 rounded-lg py-2 text-nav font-medium text-white/40 transition-colors hover:text-white/60",
          collapsed ? "justify-center px-0" : nested ? "pl-10 pr-3" : "px-3",
        )}
        title={`${item.label} — locked, avail ${item.upsell ?? "this add-on"} to unlock`}
      >
        {(!nested || collapsed) && <Icon className="size-[18px] shrink-0" />}
        {!collapsed && (
          <>
            <span className="flex-1 truncate text-left">{item.label}</span>
            {item.upsell ? (
              <span className="rounded bg-white/10 px-1.5 py-0.5 text-[9px] font-bold uppercase tracking-wide text-white/70">{item.upsell}</span>
            ) : null}
            <Lock className="size-3.5 shrink-0 opacity-70" />
          </>
        )}
      </button>
    );
  }

  return (
    <Link
      href={item.href}
      aria-current={active ? "page" : undefined}
      // The rail has no labels, so the native tooltip IS the label. Only set
      // when collapsed — otherwise every row would show a tooltip repeating
      // the text already next to the cursor.
      title={collapsed ? item.label : undefined}
      className={cn(
        "relative flex items-center gap-3 rounded-lg py-2 text-nav font-medium transition-colors",
        collapsed ? "justify-center px-0" : nested ? "pl-10 pr-3" : "px-3",
        active && (!nested || collapsed)
          ? "overflow-hidden bg-teal-ink text-white before:absolute before:inset-y-0 before:left-0 before:w-3.5 before:[background:radial-gradient(100%_230%_at_100%_50%,transparent_49%,var(--brand)_50%)]"
          : active && nested
            ? "text-brand"
            : "text-white hover:bg-sidebar-accent hover:text-white",
      )}
    >
      {(!nested || collapsed) && <Icon className="size-[18px] shrink-0" />}
      {!collapsed && (
        <>
          {/* The active underline sits on the label, not the link: on the link it
              would also be inherited by the count badge sitting beside it. */}
          <span
            className={cn(
              "flex-1 truncate",
              active && nested && "underline decoration-brand decoration-2 underline-offset-4",
            )}
          >
            {item.label}
          </span>
          {badge > 0 ? <Badge count={badge} /> : null}
        </>
      )}
      {collapsed && badge > 0 ? <BadgeDot /> : null}
    </Link>
  );
}

function NavGroupItem({
  group,
  pathname,
  navBadges,
  isLocked,
  isConnectionOff,
  collapsed,
  onExpandSidebar,
}: {
  group: NavGroupType;
  pathname: string;
  navBadges: Record<string, number>;
  /** Same opt-in SaaS lock check as top-level items, applied to each child. */
  isLocked: (item: NavLinkType) => boolean;
  /** Hide a child whose cross-module connection an admin switched off. */
  isConnectionOff: (item: NavLinkType) => boolean;
  collapsed: boolean;
  /** Collapsed rail can't unfold a group in place — expand the sidebar first. */
  onExpandSidebar?: () => void;
}) {
  const Icon = group.icon;
  const children = group.children.filter((c) => !isConnectionOff(c));
  const hasActiveChild = children.some((c) => isWithin(pathname, c.href));

  const [expanded, setExpanded] = useState(false);

  // Auto-open the group when navigating to a child page; manual toggle
  // overrides after that so the user can close the group at will. That
  // "overrides after that" is why this cannot be derived from hasActiveChild:
  // the state has to survive the user closing a group they are standing in.
  useEffect(() => {
    if (hasActiveChild) {
      // eslint-disable-next-line react-hooks/set-state-in-effect
      setExpanded(true);
    }
  }, [hasActiveChild]);

  const open = expanded && !collapsed;

  const badge = children.reduce((sum, c) => sum + (navBadges[c.href] ?? 0), 0);

  return (
    <div>
      <button
        type="button"
        aria-expanded={open}
        title={collapsed ? group.label : undefined}
        onClick={() => {
          if (collapsed) {
            onExpandSidebar?.();
            setExpanded(true);
            return;
          }
          setExpanded((e) => !e);
        }}
        className={cn(
          "relative flex w-full items-center gap-3 rounded-lg py-2 text-nav font-medium transition-colors",
          collapsed ? "justify-center px-0" : "px-3",
          hasActiveChild
            ? "overflow-hidden bg-teal-ink text-white before:absolute before:inset-y-0 before:left-0 before:w-3.5 before:[background:radial-gradient(100%_230%_at_100%_50%,transparent_49%,var(--brand)_50%)]"
            : "text-white hover:bg-sidebar-accent",
        )}
      >
        <Icon className="size-[18px] shrink-0" />
        {!collapsed && (
          <>
            <span className="flex-1 truncate text-left">{group.label}</span>
            {!open && badge > 0 ? <Badge count={badge} /> : null}
            <ChevronDown
              className={cn(
                "size-4 shrink-0 text-sidebar-foreground transition-transform",
                open && "rotate-180",
              )}
            />
          </>
        )}
        {collapsed && badge > 0 ? <BadgeDot /> : null}
      </button>

      {open ? (
        <div className="mt-1 space-y-1">
          {children.map((child) => (
            <NavLink
              key={child.href}
              item={child}
              active={isActiveLink(pathname, child)}
              badge={navBadges[child.href] ?? 0}
              nested
              locked={isLocked(child)}
            />
          ))}
        </div>
      ) : null}
    </div>
  );
}

/**
 * The platform-standard sidebar: per-portal brand + nav on the permanently
 * dark rail. Identity (profile / log out / app switch) lives in the top bar's
 * UserMenu and AppSwitcher, not here — so it is in the same place in every
 * portal and survives the collapse to an icon rail.
 *
 * `collapsed` is the desktop icon-rail state owned by AppShell (persisted per
 * user); the mobile drawer always renders expanded.
 */
export function Sidebar({
  variant,
  collapsed = false,
  onToggleCollapse,
  items: itemsProp,
  badges,
}: {
  variant: ShellVariant;
  /** Desktop icon-rail mode (labels hidden, tooltips on). Never set in the drawer. */
  collapsed?: boolean;
  /** When provided, renders the collapse toggle in the sidebar footer. */
  onToggleCollapse?: () => void;
  /** Dynamic nav (Sprintly builds its own per render) — overrides navByVariant. */
  items?: NavEntry[];
  /** Portal-supplied badge counts by href, merged over the HR notification badges. */
  badges?: Record<string, number>;
}) {
  const pathname = usePathname();
  const { hasRole } = useAuth();
  const { navBadges: hrBadges } = useNotificationCenter();
  const { data: features } = useFeatures();
  const { data: connections } = useConnections();

  const navBadges = badges ? { ...hrBadges, ...badges } : hrBadges;
  const items = itemsProp ?? navByVariant[variant];
  const BrandIcon = brandIcon[variant];

  // Opt-in SaaS locking: a `lockable` item whose `feature` the org hasn't availed
  // renders locked (with an upsell) instead of hidden. Only affects items that
  // set `lockable` — everything else is unchanged. Permissive while loading.
  const availed = new Set(features?.features ?? []);
  const isLocked = (item: NavLinkType): boolean =>
    !!item.lockable && !!item.feature && features !== undefined && !availed.has(item.feature);

  // A bridge link whose cross-module connection is availed but switched OFF is
  // hidden (a deliberate "integration off" state, distinct from the feature-lock
  // upsell for orgs that haven't availed the feature at all). Permissive while
  // the matrix is still loading.
  const connByKey = new Map((connections ?? []).map((c) => [c.key, c]));
  const isConnectionOff = (item: NavLinkType): boolean => {
    if (!item.connection || connections === undefined) return false;
    const conn = connByKey.get(item.connection);
    return !!conn && conn.available && !conn.enabled;
  };

  return (
    /*
      `h-full`, never `h-screen`. This used to be `h-screen` (= `height: 100vh`),
      which is measured against the LARGE viewport — the height the page would
      have if the browser's URL bar were retracted — and does not shrink while
      that bar is on screen. The shell puts this inside `fixed inset-0` (desktop)
      or `fixed inset-y-0` (the drawer), both of which are the *visible* height,
      so on a real phone the column was ~50-110px taller than its container and
      `overflow-hidden` cut off the bottom of it.

      It never reproduced in devtools device emulation, which has no URL bar, and
      "Request desktop site" did not help either — that changes the user agent and
      the layout width, not the browser chrome.

      Filling the parent also removes the duplicate source of truth: the shell
      already decides how tall this column is, and the two answers only ever
      disagreed. Both parents give a definite height, so `100%` resolves in each.
    */
    <aside
      className={cn(
        "flex h-full shrink-0 flex-col bg-sidebar text-sidebar-foreground transition-[width] duration-200",
        collapsed ? "w-16" : "w-64",
      )}
    >
      {/* Brand block — the PORTAL's name, never another product's. Same shape in
          every system so the eye finds it in the same place; only the glyph and
          the wordmark change. */}
      <div className={cn("flex items-center gap-2.5 py-5", collapsed ? "justify-center px-0" : "px-5")}>
        <span className="flex size-8 shrink-0 items-center justify-center rounded-lg bg-brand text-brand-foreground">
          <BrandIcon className="size-5" />
        </span>
        {!collapsed && (
          <span className="truncate text-h3 font-bold text-white">{brandLabel[variant]}</span>
        )}
      </div>

      {/* Nav */}
      <nav className={cn("sidebar-scroll flex-1 space-y-1 overflow-y-auto py-2", collapsed ? "px-2" : "px-3")}>
        {!collapsed && (
          <p className="px-3 pb-2 text-[11px] font-semibold tracking-wider text-sidebar-foreground/60">
            {workspaceLabel[variant]}
          </p>
        )}
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
                isLocked={isLocked}
                isConnectionOff={isConnectionOff}
                collapsed={collapsed}
                onExpandSidebar={onToggleCollapse}
              />
            );
          }
          // Bridge link whose connection was switched off — hidden entirely.
          if (isConnectionOff(entry)) {
            return null;
          }
          // Role-gated links (e.g. Team = admin-only) — hidden from users who
          // lack the role, so a temp kiosk client never sees BD-only pages.
          if (
            entry.requiresRole &&
            !hasRole(entry.requiresRole) &&
            !hasRole("super_admin")
          ) {
            return null;
          }
          return (
            <NavLink
              key={entry.href}
              item={entry}
              active={isActiveLink(pathname, entry)}
              badge={navBadges[entry.href] ?? 0}
              locked={isLocked(entry)}
              collapsed={collapsed}
            />
          );
        })}
      </nav>

      {/* Footer: the desktop collapse toggle (the drawer never passes it). */}
      {onToggleCollapse && (
        <div className="border-t border-white/10 p-2">
          <button
            type="button"
            onClick={onToggleCollapse}
            aria-label={collapsed ? "Expand sidebar" : "Collapse sidebar"}
            title={collapsed ? "Expand sidebar" : "Collapse sidebar"}
            className={cn(
              "flex w-full items-center gap-3 rounded-lg py-2 text-sm font-medium text-sidebar-foreground transition-colors hover:bg-sidebar-accent hover:text-white",
              collapsed ? "justify-center px-0" : "px-3",
            )}
          >
            {collapsed ? (
              <ChevronsRight className="size-[18px] shrink-0" />
            ) : (
              <>
                <ChevronsLeft className="size-[18px] shrink-0" />
                <span>Collapse</span>
              </>
            )}
          </button>
        </div>
      )}
    </aside>
  );
}
