"use client";

import { useEffect, useMemo, useState } from "react";
import Link from "next/link";
import { ArrowLeft, Bell, Check, Menu, Search, X } from "lucide-react";

import { cn } from "@/lib/utils";
import { formatRelative } from "@/core/lib/format";
import {
  brandLabel,
  brandIcon,
  employeeNav,
  kioskNav,
  isGroup,
  type NavEntry,
  type ShellVariant,
} from "@/core/nav";
import { useNotificationCenter } from "@/core/notifications-context";
import { useSearch, type SearchGroup } from "@/core/use-search";
import { AppSwitcher } from "@/core/components/app-switcher";
import { ThemeToggle } from "@/core/components/theme-toggle";
import { UserMenu } from "@/core/components/user-menu";

/**
 * The platform-standard top bar, in the SAME order in every portal:
 *
 *   [hamburger <lg] [brand chip <lg] [search] ··· [bell/rightSlot] [theme] [app switcher] [user menu]
 *
 * That order is the whole point of this component: a client who learns where
 * search, notifications and their account live in one system finds them in the
 * same places in the next one. What each slot CONTAINS is still the portal's
 * own — `rightSlot` swaps in a module's own feed (Sprintly's task bell) in
 * place of the platform HR bell, and `searchSlot` swaps in a portal's own
 * search (Sprintly's ⌘K jump-to-task). Search adapts per portal otherwise: HR
 * hits the backend search endpoint; employee and kiosk do a local "jump to
 * page" over their own nav. Below `sm` the search collapses to an icon that
 * expands over the whole bar, so dropdowns fit a 320px screen.
 */
export function TopBar({
  variant,
  rightSlot,
  onOpenNav,
  navOpen = false,
  canSwitchView,
  onSwitchView,
  avatar,
  searchSlot,
  profileHrefOverride,
}: {
  variant: ShellVariant;
  rightSlot?: React.ReactNode;
  /** Opens the mobile nav drawer (shell-owned). Renders the hamburger when set. */
  onOpenNav?: () => void;
  /** Drawer state, so the hamburger can report it to assistive tech. */
  navOpen?: boolean;
  /** Passed through to the UserMenu (TeamOra's HR ⇄ employee view switch). */
  canSwitchView?: boolean;
  onSwitchView?: () => void;
  avatar?: React.ReactNode;
  /** A portal-owned search (Sprintly's ⌘K) rendered in the search position
      INSTEAD of the built-in box; the slot owns its own responsive behavior. */
  searchSlot?: React.ReactNode;
  /** Role-dependent profile destination — overrides profileHref[variant]. */
  profileHrefOverride?: string | null;
}) {
  const { items, total, markRead, dismiss, markAllRead, hasMore, loadMore } = useNotificationCenter();
  const [bellOpen, setBellOpen] = useState(false);
  const [mobileSearch, setMobileSearch] = useState(false);
  const BrandIcon = brandIcon[variant];

  const [q, setQ] = useState("");
  const [debouncedQ, setDebouncedQ] = useState("");
  useEffect(() => {
    const t = setTimeout(() => setDebouncedQ(q), 250);
    return () => clearTimeout(t);
  }, [q]);

  // Portals with their own nav-jump search; null → use the backend search (HR).
  const navSet: NavEntry[] | null =
    variant === "employee" ? employeeNav
      : variant === "kiosk" ? kioskNav
        : null;
  // Sprintly and Zerem bring their own search through `searchSlot`, which wins
  // over this built-in box. They have no static nav to fall back to, so they
  // are excluded here — without that they would fall through to the HR backend
  // search and query an endpoint that knows nothing about their records.
  const hasSearch = !searchSlot && variant !== "projects" && variant !== "crm";
  const useBackendSearch = navSet === null && hasSearch;

  const { data: search, isFetching } = useSearch(useBackendSearch ? debouncedQ : "");

  const navMatches = useMemo<SearchGroup[]>(() => {
    if (navSet === null) return [];
    const term = debouncedQ.trim().toLowerCase();
    if (term.length < 2) return [];
    const matched = navSet
      .flatMap((e) => (isGroup(e) ? e.children : [e]))
      .filter((l) => l.label.toLowerCase().includes(term))
      .map((l) => ({ title: l.label, subtitle: "Go to page", href: l.href }));
    return matched.length ? [{ label: "Pages", items: matched }] : [];
  }, [navSet, debouncedQ]);

  const groups = navSet !== null ? navMatches : (search?.groups ?? []);
  const fetching = navSet !== null ? false : isFetching;
  const searchPlaceholder = navSet !== null
    ? "Jump to a page…"
    : "Search employees, departments, requisitions…";

  const searchOpen = q.trim().length >= 2;
  const hasResults = groups.some((g) => g.items.length > 0);

  const closeSearch = () => {
    setQ("");
    setMobileSearch(false);
  };

  /** The input + live-results dropdown, shared by the inline and phone layouts. */
  const searchBox = (autoFocus = false) => (
    <div className="relative min-w-0 flex-1">
      <Search className="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground" />
      <input
        type="text"
        value={q}
        autoFocus={autoFocus}
        onChange={(e) => setQ(e.target.value)}
        placeholder={searchPlaceholder}
        className="h-9 w-full rounded-lg border border-border bg-muted/40 pl-9 pr-3 text-sm text-foreground placeholder:text-muted-foreground focus:border-ring focus:outline-none"
      />

      {searchOpen && (
        <>
          <div className="fixed inset-0 z-10" onClick={closeSearch} />
          <div className="absolute left-0 top-full z-20 mt-1 w-full overflow-hidden rounded-xl border border-border bg-popover shadow-lg">
            <div className="max-h-96 overflow-y-auto py-1">
              {fetching && !hasResults ? (
                <p className="px-4 py-6 text-center text-sm text-muted-foreground">Searching…</p>
              ) : !hasResults ? (
                <p className="px-4 py-6 text-center text-sm text-muted-foreground">
                  No matches for “{q}”.
                </p>
              ) : (
                groups.map((group) => (
                  <div key={group.label}>
                    <p className="px-4 pt-2 pb-1 text-label font-bold uppercase tracking-wide text-muted-foreground">
                      {group.label}
                    </p>
                    {group.items.map((item, i) => (
                      <Link
                        key={i}
                        href={item.href}
                        onClick={closeSearch}
                        className="flex flex-col gap-0.5 px-4 py-2 hover:bg-muted"
                      >
                        <span className="text-sm font-medium text-foreground">{item.title}</span>
                        <span className="text-caption text-muted-foreground">{item.subtitle}</span>
                      </Link>
                    ))}
                  </div>
                ))
              )}
            </div>
          </div>
        </>
      )}
    </div>
  );

  return (
    // `relative` is the anchor a portal searchSlot expands over on phones.
    <header className="relative flex h-16 shrink-0 items-center gap-2 border-b border-border bg-card px-4 sm:gap-3 sm:px-6">
      {/* Hamburger — only below lg, where the sidebar is a drawer. */}
      {onOpenNav && (
        <button
          type="button"
          onClick={onOpenNav}
          aria-label="Open navigation"
          aria-haspopup="dialog"
          aria-controls="app-mobile-nav"
          aria-expanded={navOpen}
          className="flex size-9 shrink-0 items-center justify-center rounded-lg text-muted-foreground transition-colors hover:bg-muted hover:text-foreground lg:hidden"
        >
          <Menu className="size-5" />
        </button>
      )}

      {/* Brand chip below lg — the sidebar (and its brand block) is off-canvas
          there, so the header carries the portal identity. */}
      <span className="flex shrink-0 items-center gap-2 lg:hidden">
        <span className="flex size-7 items-center justify-center rounded-lg bg-brand text-brand-foreground">
          <BrandIcon className="size-4" />
        </span>
        <span className="hidden text-sm font-bold text-foreground sm:inline">
          {brandLabel[variant]}
        </span>
      </span>

      {/* Global search — inline from sm up; an expanding icon below. A portal
          searchSlot takes the position instead and owns its own responsive. */}
      {searchSlot ? (
        // `justify-end` below sm so a slot that collapses to an icon on phones
        // lands next to the other header controls instead of floating alone in
        // the middle of the bar; from sm up the inline input fills the box.
        <div className="flex min-w-0 max-w-md flex-1 justify-end sm:justify-start">
          {searchSlot}
        </div>
      ) : hasSearch ? (
        <div className="hidden max-w-md flex-1 sm:block">{searchBox()}</div>
      ) : null}

      <div className="ml-auto flex shrink-0 items-center gap-1 sm:gap-2">
        {hasSearch && (
          <button
            type="button"
            onClick={() => setMobileSearch(true)}
            aria-label="Search"
            className="flex size-9 items-center justify-center rounded-lg text-muted-foreground transition-colors hover:bg-muted hover:text-foreground sm:hidden"
          >
            <Search className="size-5" />
          </button>
        )}

        {/* A portal-supplied header action (e.g. Sprintly's task bell) replaces the default bell. */}
        {rightSlot ? <div className="relative flex items-center">{rightSlot}</div> : null}

        {/* Default platform notification bell — HR-only feed, so hidden in portals
            with no module feed (kiosk) and when a portal supplies its own. */}
        {!rightSlot && variant !== "kiosk" && (
          <div className="relative">
            <button
              type="button"
              onClick={() => setBellOpen((o) => !o)}
              className="relative flex size-9 items-center justify-center rounded-lg text-muted-foreground hover:bg-muted"
              aria-label="Notifications"
            >
              <Bell className="size-5" />
              {total > 0 && (
                <span className="absolute top-1 right-1 flex h-4 min-w-4 items-center justify-center rounded-full bg-danger px-1 text-[10px] font-semibold text-white">
                  {total}
                </span>
              )}
            </button>

            {bellOpen && (
              <>
                <div className="fixed inset-0 z-10" onClick={() => setBellOpen(false)} />
                <div className="absolute right-0 top-full z-20 mt-2 w-80 max-w-[calc(100vw-2rem)] overflow-hidden rounded-xl border border-border bg-popover shadow-lg">
                  <div className="flex items-center justify-between border-b border-border px-4 py-3">
                    <p className="text-sm font-semibold text-foreground">Notifications</p>
                    {items.some((i) => !i.read) && (
                      <button
                        onClick={markAllRead}
                        className="text-caption font-medium text-brand hover:opacity-80"
                      >
                        Mark all read
                      </button>
                    )}
                  </div>
                  <div className="max-h-96 overflow-y-auto">
                    {items.length === 0 ? (
                      <p className="px-4 py-8 text-center text-sm text-muted-foreground">
                        You’re all caught up.
                      </p>
                    ) : (
                      <>
                        {items.map((n) => (
                          <div
                            key={n.id}
                            className={cn(
                              "flex items-start gap-2 border-b border-border px-3 py-3 last:border-0",
                              !n.read && "bg-brand-subtle/40",
                            )}
                          >
                            {!n.read && <span className="mt-1.5 size-2 shrink-0 rounded-full bg-brand" />}
                            <Link
                              href={n.href}
                              onClick={() => {
                                markRead(n.id);
                                setBellOpen(false);
                              }}
                              className="min-w-0 flex-1"
                            >
                              <span className="block text-sm font-medium text-foreground">{n.title}</span>
                              <span className="block text-caption text-muted-foreground">{n.subtitle}</span>
                              <span className="block text-[11px] text-muted-foreground">
                                {formatRelative(n.at)}
                              </span>
                            </Link>
                            <div className="flex shrink-0 flex-col gap-1">
                              {!n.read && (
                                <button
                                  onClick={() => markRead(n.id)}
                                  title="Mark as read"
                                  className="rounded p-1 text-muted-foreground hover:bg-muted hover:text-foreground"
                                >
                                  <Check className="size-3.5" />
                                </button>
                              )}
                              <button
                                onClick={() => dismiss(n.id)}
                                title="Delete"
                                className="rounded p-1 text-muted-foreground hover:bg-muted hover:text-destructive"
                              >
                                <X className="size-3.5" />
                              </button>
                            </div>
                          </div>
                        ))}
                        {hasMore && (
                          <button
                            type="button"
                            onClick={loadMore}
                            className="w-full border-t border-border px-4 py-2.5 text-center text-sm font-medium text-brand transition-colors hover:bg-muted/40"
                          >
                            Load more
                          </button>
                        )}
                      </>
                    )}
                  </div>
                </div>
              </>
            )}
          </div>
        )}

        <ThemeToggle />
        <AppSwitcher current={variant} />
        <UserMenu
          variant={variant}
          canSwitchView={canSwitchView}
          onSwitchView={onSwitchView}
          avatar={avatar}
          profileHrefOverride={profileHrefOverride}
        />
      </div>

      {/* Phone search takes over the whole bar while active. */}
      {mobileSearch && (
        <div className="absolute inset-0 z-30 flex items-center gap-2 bg-card px-4 sm:hidden">
          <button
            type="button"
            onClick={closeSearch}
            aria-label="Close search"
            className="flex size-9 shrink-0 items-center justify-center rounded-lg text-muted-foreground hover:bg-muted"
          >
            <ArrowLeft className="size-5" />
          </button>
          {searchBox(true)}
        </div>
      )}
    </header>
  );
}
