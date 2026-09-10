"use client";

import { useCallback, useState } from "react";
import { usePathname, useRouter } from "next/navigation";
import { ArrowLeft, Menu, Search } from "lucide-react";
import { cn } from "@/modules/crm/lib/utils";
import { SearchInput } from "@/modules/crm/components/shared/search-input/search-input";
import { ThemeToggle } from "@/modules/crm/components/layout/topbar/theme-toggle";
import { NotificationBell } from "@/modules/crm/features/notifications/components/notification-bell";
import { UserMenu } from "@/modules/crm/components/layout/user-menu/user-menu";
import { AppSwitcher } from "@/core/components/app-switcher";
import { useHeaderSearch } from "@/modules/crm/providers/header-search-provider";
import { useLeadSearch } from "@/modules/crm/features/leads/hooks/use-lead-search";

type PageHeaderProps = {
  onMenuClick?: () => void;
  /** Drawer state, so the hamburger can report it to assistive tech. */
  menuOpen?: boolean;
  className?: string;
};

/** Where a search with no page-specific handler goes — the CRM's existing results surface. */
const LEADS_ROUTE = "/zerem/leads";

export function PageHeader({ onMenuClick, menuOpen = false, className }: PageHeaderProps) {
  const { value, setValue, registered, onSubmit } = useHeaderSearch();
  const router = useRouter();
  const pathname = usePathname();
  // The panel remembers WHERE it was opened, so a route change closes it by derivation rather than
  // by writing state from an effect: a completed search navigates, and leaving the route means the
  // panel has done its job. This also covers back/forward and sidebar navigation for free.
  const [panel, setPanel] = useState({ open: false, path: pathname });
  const mobileSearchOpen = panel.open && panel.path === pathname;
  const setMobileSearchOpen = useCallback(
    (open: boolean) => setPanel({ open, path: pathname }),
    [pathname],
  );

  /**
   * Submitting the header search, from ANY Zerem route.
   *
   * A registered page owns its own behavior (Leads filters its table live from `value`; Dashboard
   * registers an explicit handler). Everywhere else — Lead Details, Follow-ups, My Performance,
   * Planner, Reports, Settings … — search previously did nothing at all, because the input was only
   * wired up when a page had registered. The fallback below is what makes it route-independent:
   * with no page to handle the term, hand it to the Leads list, which is the CRM's existing
   * search-results surface and already reads `?search=`.
   */
  const submit = useCallback(
    (raw: string) => {
      if (registered) {
        // The page is driving this input; it either has a submit handler or is already filtering
        // live off `value`. Either way, do not navigate out from under it.
        onSubmit?.(raw);
        return;
      }
      const query = raw.trim();
      router.push(query ? `${LEADS_ROUTE}?search=${encodeURIComponent(query)}` : LEADS_ROUTE);
    },
    [registered, onSubmit, router],
  );

  // Results are fetched only while the mobile panel is open — desktop keeps its
  // type-and-press-Enter behavior and issues no extra requests.
  const { results, isLoading, isError, active } = useLeadSearch(value, mobileSearchOpen);

  const closeMobileSearch = useCallback(() => setMobileSearchOpen(false), [setMobileSearchOpen]);

  const submitFromPanel = useCallback(
    (raw: string) => {
      submit(raw);
      // Closed unconditionally: on a registered page (Leads) `submit` is intentionally a no-op and
      // the filtered list is already behind the panel, so leaving it open would hide the answer.
      setMobileSearchOpen(false);
    },
    [submit, setMobileSearchOpen],
  );

  const openLead = useCallback(
    (id: string) => {
      setMobileSearchOpen(false);
      setValue("");
      router.push(`${LEADS_ROUTE}/${id}`);
    },
    [router, setValue, setMobileSearchOpen],
  );

  return (
    <header className={cn("border-b border-border-subtle bg-background", className)}>
      {/* The original single-row header, unchanged — padding moved off <header> onto this row so the
          mobile panel below can span the full width and own its own spacing. */}
      <div className="flex items-center justify-between px-4 py-3 lg:px-6">
        <div className="flex items-center gap-3">
          <button
            type="button"
            onClick={onMenuClick}
            className="flex h-9 w-9 items-center justify-center rounded-lg text-muted-foreground hover:bg-muted focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring lg:hidden"
            aria-label="Open navigation"
            aria-haspopup="dialog"
            aria-expanded={menuOpen}
          >
            <Menu className="h-5 w-5" />
          </button>
          {/* Search sits on the LEFT, beside the hamburger — the position it holds in
              core/components/top-bar.tsx, whose row reads
              `[hamburger] [brand] [search] ··· [bell] [theme] [switcher] [user]`.
              It was on the right because the page title occupied this slot; the title now sits
              at the top of the page like every other portal, which frees it. */}
          <SearchInput
            className="hidden sm:block w-[240px]"
            value={value}
            onChange={setValue}
            onSubmit={submit}
          />
          <button
            type="button"
            onClick={() => setMobileSearchOpen(!mobileSearchOpen)}
            aria-label="Search"
            aria-expanded={mobileSearchOpen}
            className={cn(
              "flex h-9 w-9 items-center justify-center rounded-lg focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring sm:hidden",
              mobileSearchOpen ? "bg-muted text-foreground" : "text-muted-foreground hover:bg-muted",
            )}
          >
            <Search className="h-4 w-4" />
          </button>
        </div>

        <div className="flex items-center gap-2">
          {/* One order across every portal (see core/components/top-bar.tsx):
              notifications → theme → app switcher → account. */}
          <NotificationBell />
          <ThemeToggle />
          <AppSwitcher current="crm" />
          <UserMenu />
        </div>
      </div>

      {/* Mobile search panel — a full-width row BELOW the header row, so the input gets the whole
          screen width instead of the few pixels left beside the title and action icons. `sm:hidden`
          keeps it off desktop entirely; the desktop header is untouched. */}
      {mobileSearchOpen ? (
        <div
          className="border-t border-border-subtle px-4 py-3 sm:hidden"
          onKeyDown={(e) => {
            if (e.key === "Escape") closeMobileSearch();
          }}
        >
          <div className="flex items-center gap-2">
            <button
              type="button"
              onClick={closeMobileSearch}
              aria-label="Close search"
              className="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg text-muted-foreground hover:bg-muted focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
            >
              <ArrowLeft className="h-4 w-4" />
            </button>
            {/* `min-w-0` lets this flex child shrink below its content width — without it the input's
                intrinsic size pushes the row past the viewport and scrolls the page sideways. */}
            <SearchInput
              className="min-w-0 flex-1"
              value={value}
              onChange={setValue}
              onSubmit={submitFromPanel}
              autoFocus
            />
          </div>

          {active ? (
            <div className="mt-3">
              {isLoading ? (
                <div className="space-y-2" aria-hidden>
                  {[0, 1, 2].map((i) => (
                    <div key={i} className="h-12 animate-pulse rounded-lg bg-muted" />
                  ))}
                </div>
              ) : isError ? (
                <p className="py-2 text-sm text-muted-foreground">Couldn&apos;t load results.</p>
              ) : results.length === 0 ? (
                <p className="py-2 text-sm text-muted-foreground">No leads found.</p>
              ) : (
                <ul className="flex flex-col gap-1">
                  {results.map((lead) => (
                    <li key={lead.id}>
                      <button
                        type="button"
                        onClick={() => openLead(lead.id)}
                        className="w-full rounded-lg px-3 py-2 text-left hover:bg-muted focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                      >
                        <span className="block truncate text-sm font-medium text-card-foreground">
                          {lead.business}
                        </span>
                        <span className="block truncate text-xs text-muted-foreground">
                          {[lead.contact, lead.email].filter(Boolean).join(" · ") || "—"}
                        </span>
                      </button>
                    </li>
                  ))}
                </ul>
              )}
            </div>
          ) : null}
        </div>
      ) : null}
    </header>
  );
}
