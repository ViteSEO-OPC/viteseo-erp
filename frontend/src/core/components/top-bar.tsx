"use client";

import { useEffect, useState } from "react";
import Link from "next/link";
import { Search, Bell, Check, X } from "lucide-react";

import { cn } from "@/lib/utils";
import { formatRelative } from "@/core/lib/format";
import { useNotificationCenter } from "@/core/notifications-context";
import { useSearch } from "@/core/use-search";

export function TopBar() {
  const { items, total, markRead, dismiss, markAllRead } = useNotificationCenter();
  const [bellOpen, setBellOpen] = useState(false);

  const [q, setQ] = useState("");
  const [debouncedQ, setDebouncedQ] = useState("");
  useEffect(() => {
    const t = setTimeout(() => setDebouncedQ(q), 250);
    return () => clearTimeout(t);
  }, [q]);
  const { data: search, isFetching } = useSearch(debouncedQ);

  const searchOpen = q.trim().length >= 2;
  const hasResults = (search?.groups ?? []).some((g) => g.items.length > 0);

  return (
    <header className="flex h-16 shrink-0 items-center gap-4 border-b border-border bg-card px-6">
      {/* Global search */}
      <div className="relative max-w-md flex-1">
        <Search className="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground" />
        <input
          type="text"
          value={q}
          onChange={(e) => setQ(e.target.value)}
          placeholder="Search employees, departments, requisitions…"
          className="h-9 w-full rounded-lg border border-border bg-muted/40 pl-9 pr-3 text-sm text-foreground placeholder:text-muted-foreground focus:border-ring focus:outline-none"
        />

        {searchOpen && (
          <>
            <div className="fixed inset-0 z-10" onClick={() => setQ("")} />
            <div className="absolute left-0 top-full z-20 mt-1 w-full overflow-hidden rounded-xl border border-border bg-popover shadow-lg">
              <div className="max-h-96 overflow-y-auto py-1">
                {isFetching && !search ? (
                  <p className="px-4 py-6 text-center text-sm text-muted-foreground">Searching…</p>
                ) : !hasResults ? (
                  <p className="px-4 py-6 text-center text-sm text-muted-foreground">
                    No matches for “{q}”.
                  </p>
                ) : (
                  search?.groups.map((group) => (
                    <div key={group.label}>
                      <p className="px-4 pt-2 pb-1 text-label font-bold uppercase tracking-wide text-muted-foreground">
                        {group.label}
                      </p>
                      {group.items.map((item, i) => (
                        <Link
                          key={i}
                          href={item.href}
                          onClick={() => setQ("")}
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

      {/* Notification bell */}
      <div className="relative ml-auto">
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
            <div className="absolute right-0 top-full z-20 mt-2 w-80 overflow-hidden rounded-xl border border-border bg-popover shadow-lg">
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
                  items.map((n) => (
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
                  ))
                )}
              </div>
            </div>
          </>
        )}
      </div>
    </header>
  );
}
