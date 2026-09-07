"use client";

import { useEffect, useState } from "react";
import Link from "next/link";
import { ArrowLeft, Search } from "lucide-react";

import { cn } from "@/lib/utils";
import { MIN_SEARCH_LENGTH, useAccountingSearch } from "@/modules/accounting/hooks/use-accounting-search";

/**
 * The Accounting portal's global search, rendered in the platform header's
 * `searchSlot`.
 *
 * It replaces the shell's interim "jump to page" box: instead of matching nav
 * labels, it hits `GET /accounting/search` and lists the real records — the
 * invoice, the bill, the customer, the ledger account — grouped exactly like
 * HR's backend search, so the dropdown reads the same in every portal.
 *
 * Below `sm` it collapses to an icon that expands over the whole bar (the same
 * shape as the shell's built-in search and Zerem's): a full-width input cannot
 * share a 360px header with the hamburger, the brand chip and the right-hand
 * controls. The overlay anchors to the shell's `relative` <header>.
 */
export function AccountingSearch() {
  const [q, setQ] = useState("");
  const [debouncedQ, setDebouncedQ] = useState("");
  /** Phone only: whether the input has taken over the header bar. */
  const [expanded, setExpanded] = useState(false);

  useEffect(() => {
    const t = setTimeout(() => setDebouncedQ(q), 250);
    return () => clearTimeout(t);
  }, [q]);

  const { data, isFetching } = useAccountingSearch(debouncedQ);

  const groups = data?.groups ?? [];
  const hasResults = groups.some((g) => g.items.length > 0);
  // Driven by the LIVE term so the panel opens on the keystroke, not 250ms late.
  const open = q.trim().length >= MIN_SEARCH_LENGTH;

  /** A completed search (or a dismissal) empties the box and folds the phone overlay away. */
  const close = () => {
    setQ("");
    setDebouncedQ("");
    setExpanded(false);
  };

  return (
    <>
      {/* Phone: the trigger. Expanding it takes over the bar (below), so the row
          keeps room for the hamburger, brand and the right-hand controls. */}
      <button
        type="button"
        onClick={() => setExpanded(true)}
        aria-label="Search"
        className="flex size-9 items-center justify-center rounded-lg text-muted-foreground transition-colors hover:bg-muted hover:text-foreground sm:hidden"
      >
        <Search className="size-5" />
      </button>

      <div
        className={cn(
          "w-full",
          // Inline from sm up; below it, only while expanded — as an overlay on
          // the header bar, anchored to the shell's `relative` <header>.
          expanded
            ? "absolute inset-x-0 top-0 z-30 flex h-16 items-center gap-2 bg-card px-4 sm:static sm:h-auto sm:px-0"
            : "hidden sm:block",
        )}
        onKeyDown={(e) => {
          if (e.key === "Escape") close();
        }}
      >
        {expanded && (
          <button
            type="button"
            onClick={close}
            aria-label="Close search"
            className="flex size-9 shrink-0 items-center justify-center rounded-lg text-muted-foreground hover:bg-muted sm:hidden"
          >
            <ArrowLeft className="size-5" />
          </button>
        )}

        <div className="relative min-w-0 flex-1">
          <Search className="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground" />
          <input
            type="search"
            value={q}
            autoFocus={expanded}
            onChange={(e) => setQ(e.target.value)}
            placeholder="Search invoices, bills, contacts, accounts…"
            aria-label="Search accounting records"
            className="h-9 w-full rounded-lg border border-border bg-muted/40 pl-9 pr-3 text-sm text-foreground placeholder:text-muted-foreground focus:border-ring focus:outline-none"
          />

          {open && (
            <>
              {/* Click-away catcher, the same idiom the shared TopBar search uses. */}
              <div className="fixed inset-0 z-10" onClick={close} aria-hidden />
              <div className="absolute left-0 top-full z-20 mt-1 w-full overflow-hidden rounded-xl border border-border bg-popover shadow-lg">
                <div className="max-h-96 overflow-y-auto py-1">
                  {isFetching && !hasResults ? (
                    <p className="px-4 py-6 text-center text-sm text-muted-foreground">Searching…</p>
                  ) : !hasResults ? (
                    <p className="px-4 py-6 text-center text-sm text-muted-foreground">
                      No matches for “{q.trim()}”.
                    </p>
                  ) : (
                    groups.map((group) => (
                      <div key={group.label}>
                        <p className="px-4 pt-2 pb-1 text-label font-bold uppercase tracking-wide text-muted-foreground">
                          {group.label}
                        </p>
                        {group.items.map((item, i) => (
                          <Link
                            key={`${group.label}-${i}`}
                            href={item.href}
                            onClick={close}
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
      </div>
    </>
  );
}
