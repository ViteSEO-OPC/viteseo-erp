"use client";

import { useEffect, useMemo, useState } from "react";
import Link from "next/link";
import { Bell, Check, Menu, Search, X, Zap } from "lucide-react";

import { cn } from "@/lib/utils";
import { formatRelative } from "@/core/lib/format";
import { employeeNav, isGroup, type ShellVariant } from "@/core/nav";
import { useNotificationCenter } from "@/core/notifications-context";
import { useSearch, type SearchGroup } from "@/core/use-search";

/**
 * TeamOra's phone/tablet top bar — rendered below `lg`, where `core/TopBar`
 * takes over.
 *
 * Two reasons this exists rather than reusing the shared bar:
 * - `core/TopBar`'s notification dropdown is a fixed `w-80` (320px) anchored
 *   `right-0`, which overflows a 320px viewport. The dropdown markup is
 *   internal to that component, so there is no slot to constrain it from
 *   outside — this one spans `inset-x-4` instead.
 * - It absorbs the drawer's hamburger, so mobile gets one 56px bar instead of
 *   a hamburger strip stacked on top of the desktop-shaped bar.
 *
 * Search collapses to an icon and expands over the full bar width when tapped,
 * so a 320px screen doesn't spend permanent width on a search field.
 *
 * State/data all come from the shared hooks (`useNotificationCenter`,
 * `useSearch`) — only the layout is duplicated, not the logic.
 */
export function TeamoraMobileTopBar({
  variant,
  onOpenNav,
}: {
  variant: ShellVariant;
  onOpenNav: () => void;
}) {
  const { items, total, markRead, dismiss, markAllRead, hasMore, loadMore } =
    useNotificationCenter();
  const [bellOpen, setBellOpen] = useState(false);
  const [searchExpanded, setSearchExpanded] = useState(false);

  const isEmployee = variant === "employee";

  const [q, setQ] = useState("");
  const [debouncedQ, setDebouncedQ] = useState("");
  useEffect(() => {
    const t = setTimeout(() => setDebouncedQ(q), 250);
    return () => clearTimeout(t);
  }, [q]);
  const { data: search, isFetching } = useSearch(isEmployee ? "" : debouncedQ);

  // Employees don't hit the search API — their bar just jumps between pages.
  const navMatches = useMemo<SearchGroup[]>(() => {
    if (!isEmployee) return [];
    const term = debouncedQ.trim().toLowerCase();
    if (term.length < 2) return [];
    const matches = employeeNav
      .flatMap((e) => (isGroup(e) ? e.children : [e]))
      .filter((l) => l.label.toLowerCase().includes(term))
      .map((l) => ({ title: l.label, subtitle: "Go to page", href: l.href }));
    return matches.length ? [{ label: "Pages", items: matches }] : [];
  }, [isEmployee, debouncedQ]);

  const groups = isEmployee ? navMatches : (search?.groups ?? []);
  const fetching = isEmployee ? false : isFetching;
  const searchOpen = q.trim().length >= 2;
  const hasResults = groups.some((g) => g.items.length > 0);

  const closeSearch = () => {
    setQ("");
    setSearchExpanded(false);
  };

  useEffect(() => {
    if (!bellOpen && !searchExpanded) return;
    const onKey = (e: KeyboardEvent) => {
      if (e.key !== "Escape") return;
      setBellOpen(false);
      closeSearch();
    };
    window.addEventListener("keydown", onKey);
    return () => window.removeEventListener("keydown", onKey);
  }, [bellOpen, searchExpanded]);

  return (
    <header className="relative flex h-14 shrink-0 items-center gap-1 border-b border-border bg-card px-4 xl:hidden">
      {searchExpanded ? (
        <>
          <div className="relative flex-1">
            <Search className="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground" />
            <input
              type="text"
              autoFocus
              value={q}
              onChange={(e) => setQ(e.target.value)}
              placeholder={isEmployee ? "Jump to a page…" : "Search employees…"}
              className="h-9 w-full rounded-lg border border-border bg-muted/40 pr-3 pl-9 text-sm text-foreground placeholder:text-muted-foreground focus:border-ring focus:outline-none"
            />
          </div>
          <button
            type="button"
            onClick={closeSearch}
            aria-label="Close search"
            className="flex size-9 shrink-0 items-center justify-center rounded-lg text-muted-foreground transition-colors hover:bg-muted hover:text-foreground"
          >
            <X className="size-5" />
          </button>

          {searchOpen && (
            <div className="absolute inset-x-4 top-full z-20 mt-1 overflow-hidden rounded-xl border border-border bg-popover shadow-lg">
              <div className="max-h-[60svh] overflow-y-auto py-1">
                {fetching && !hasResults ? (
                  <p className="px-4 py-6 text-center text-sm text-muted-foreground">
                    Searching…
                  </p>
                ) : !hasResults ? (
                  <p className="px-4 py-6 text-center text-sm text-muted-foreground">
                    No matches for &quot;{q}&quot;.
                  </p>
                ) : (
                  groups.map((group) => (
                    <div key={group.label}>
                      <p className="px-4 pt-2 pb-1 text-label font-bold tracking-wide uppercase text-muted-foreground">
                        {group.label}
                      </p>
                      {group.items.map((item, i) => (
                        <Link
                          key={i}
                          href={item.href}
                          onClick={closeSearch}
                          className="flex flex-col gap-0.5 px-4 py-2 hover:bg-muted"
                        >
                          <span className="text-sm font-medium text-foreground">
                            {item.title}
                          </span>
                          <span className="text-caption text-muted-foreground">
                            {item.subtitle}
                          </span>
                        </Link>
                      ))}
                    </div>
                  ))
                )}
              </div>
            </div>
          )}
        </>
      ) : (
        <>
          <button
            type="button"
            onClick={onOpenNav}
            aria-label="Open navigation"
            aria-controls="teamora-mobile-nav"
            className="-ml-1.5 flex size-9 shrink-0 items-center justify-center rounded-lg text-muted-foreground transition-colors hover:bg-muted hover:text-foreground"
          >
            <Menu className="size-5" />
          </button>

          <span className="ml-0.5 flex size-6 shrink-0 items-center justify-center rounded-md bg-brand text-brand-foreground">
            <Zap className="size-4" fill="currentColor" />
          </span>
          <span className="truncate font-bold text-foreground">TeamOra</span>

          <button
            type="button"
            onClick={() => setSearchExpanded(true)}
            aria-label="Search"
            className="ml-auto flex size-9 shrink-0 items-center justify-center rounded-lg text-muted-foreground transition-colors hover:bg-muted hover:text-foreground"
          >
            <Search className="size-5" />
          </button>

          <button
            type="button"
            onClick={() => setBellOpen((o) => !o)}
            aria-label="Notifications"
            className="relative flex size-9 shrink-0 items-center justify-center rounded-lg text-muted-foreground transition-colors hover:bg-muted hover:text-foreground"
          >
            <Bell className="size-5" />
            {total > 0 && (
              <span className="absolute top-1 right-1 flex h-4 min-w-4 items-center justify-center rounded-full bg-danger px-1 text-[10px] font-semibold text-white">
                {total}
              </span>
            )}
          </button>
        </>
      )}

      {bellOpen && (
        <>
          <div
            className="fixed inset-0 z-10"
            onClick={() => setBellOpen(false)}
            aria-hidden="true"
          />
          {/* `inset-x-4` rather than a fixed width — this is the overflow that
              core/TopBar's `w-80` dropdown causes at 320px. */}
          <div className="absolute inset-x-4 top-full z-20 mt-2 overflow-hidden rounded-xl border border-border bg-popover shadow-lg">
            <div className="flex items-center justify-between border-b border-border px-4 py-3">
              <p className="text-sm font-semibold text-foreground">
                Notifications
              </p>
              {items.some((i) => !i.read) && (
                <button
                  onClick={markAllRead}
                  className="text-caption font-medium text-brand hover:opacity-80"
                >
                  Mark all read
                </button>
              )}
            </div>
            <div className="max-h-[60svh] overflow-y-auto">
              {items.length === 0 ? (
                <p className="px-4 py-8 text-center text-sm text-muted-foreground">
                  You&rsquo;re all caught up.
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
                      {!n.read && (
                        <span className="mt-1.5 size-2 shrink-0 rounded-full bg-brand" />
                      )}
                      <Link
                        href={n.href}
                        onClick={() => {
                          markRead(n.id);
                          setBellOpen(false);
                        }}
                        className="min-w-0 flex-1"
                      >
                        <span className="block text-sm font-medium text-foreground">
                          {n.title}
                        </span>
                        <span className="block text-caption text-muted-foreground">
                          {n.subtitle}
                        </span>
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
    </header>
  );
}
