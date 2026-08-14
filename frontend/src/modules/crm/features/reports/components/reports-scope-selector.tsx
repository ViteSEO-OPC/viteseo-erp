"use client";

import { Users } from "lucide-react";
import { useBdUsers } from "@/modules/crm/hooks/use-bd-users";
import { cn } from "@/modules/crm/lib/utils";
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/modules/crm/components/ui/select";
import {
  ALL_SCOPE,
  type PerformanceScope,
} from "@/modules/crm/features/performance/lib/performance-scope";

/** Sentinel for the "everything" option — BD ids are numeric strings, so this can never collide. */
const ALL_VALUE = "__all__";

/** Two initials from a name, for the BD avatar chip. */
function initialsOf(name: string): string {
  return name
    .split(/\s+/)
    .filter(Boolean)
    .slice(0, 2)
    .map((part) => part[0]!)
    .join("")
    .toUpperCase();
}

/**
 * "All <X> | <BD>…" scope selector. A single inline row of filter chips: an "all" chip followed by one
 * chip per active Business Development Manager. Tapping a chip filters immediately — no dropdown,
 * popover, or select; exactly one chip is active at a time (CRM active styling). Reuses the shared BD
 * roster (useBdUsers → GET /users). The row scrolls horizontally once the chips outgrow the width, so
 * the roster never collapses back into a menu.
 *
 * Shared by Reports & Performance, the Admin/Super Admin Leads page, and Planner History. Only the
 * "all" chip's wording and the group's accessible name vary between them — both default to the Reports
 * copy, so that page is unchanged. Each caller maps the scope onto its own filter (Reports → `user_id`;
 * Leads and Planner History → the existing `assigned_to` param), which keeps one tabs implementation
 * rather than two.
 *
 * RESPONSIVE: the chip row is the desktop (`lg`+) presentation. Below `lg` — this codebase's tablet
 * breakpoint, where the sidebar is already an icon rail — a roster of chips either overflows into a
 * horizontal scroller that hides most BDs offscreen or wraps into several rows, so tablet and mobile
 * get a compact dropdown instead. Both presentations are rendered and toggled by CSS alone rather than
 * by a JS media query, which would mismatch between SSR and hydration and flash the wrong control on
 * first paint. They are driven by the SAME `scope`/`onChange` props and the same `bdUsers` roster (one
 * `useBdUsers()` call, one query), so the two views cannot disagree about the selection.
 */
export function ReportsScopeSelector({
  scope,
  onChange,
  allLabel = "All Reports",
  ariaLabel = "Report scope",
}: {
  scope: PerformanceScope;
  onChange: (scope: PerformanceScope) => void;
  /** Wording for the leading "everything" chip. */
  allLabel?: string;
  /** Accessible name for the chip group. */
  ariaLabel?: string;
}) {
  const { bdUsers } = useBdUsers();
  const isAll = scope.mode === "all";
  const activeBdId = scope.mode === "bd" ? scope.userId : "";

  // The dropdown speaks the same scope as the chips: one selected id, mapped back through the same
  // roster. Selecting an unknown id is impossible (the options ARE the roster), but the lookup is
  // guarded anyway so a stale roster can never produce a scope with an empty name.
  const selectedValue = scope.mode === "bd" ? scope.userId : ALL_VALUE;
  // `value` is nullable because Base UI clears the selection to null; both fall back to "all", which
  // is exactly the scope an unset selector means.
  const labelForValue = (value: string | null): string =>
    bdUsers.find((u) => u.id === value)?.name ?? allLabel;

  const handleSelect = (value: string | null) => {
    const user = bdUsers.find((u) => u.id === value);
    onChange(user ? { mode: "bd", userId: user.id, name: user.name } : ALL_SCOPE);
  };

  const chipBase =
    "flex shrink-0 items-center gap-2 whitespace-nowrap rounded-lg border px-3 py-1.5 text-sm font-medium transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-ring";
  const activeChip = "border-border bg-card text-teal-900 shadow-sm";
  const idleChip = "border-transparent text-muted-foreground hover:text-foreground";

  return (
    <div className="max-w-full">
      {/* ---- Desktop (lg+): the chip row, visually unchanged. Horizontal scroll — never pagination —
              when the roster outgrows the row. ---- */}
      <div className="hidden max-w-full overflow-x-auto lg:block">
        <div
          className="inline-flex items-center gap-1 rounded-xl border border-border bg-card p-1"
          role="group"
          aria-label={ariaLabel}
        >
          <button
            type="button"
            aria-pressed={isAll}
            onClick={() => onChange(ALL_SCOPE)}
            className={cn(chipBase, isAll ? activeChip : idleChip)}
          >
            {/* `pointer-events-none` on every decoration inside a chip: the icon and the avatar below
                are presentational, and without it a click (or an automated hit-test) can resolve to
                the inner element as its target instead of the button that carries the handler. The
                button keeps the padding, so the whole pill — avatar, initials, name, empty space —
                is one reliable click target. */}
            <Users
              className={cn(
                "pointer-events-none h-4 w-4",
                isAll ? "text-teal-500" : "text-muted-foreground",
              )}
              aria-hidden
            />
            {allLabel}
          </button>

          {bdUsers.map((user) => {
            const isActive = user.id === activeBdId;
            return (
              <button
                key={user.id}
                type="button"
                aria-pressed={isActive}
                onClick={() =>
                  onChange({ mode: "bd", userId: user.id, name: user.name })
                }
                className={cn(chipBase, "pl-1.5", isActive ? activeChip : idleChip)}
              >
                <span
                  className={cn(
                    "pointer-events-none inline-flex h-6 w-6 shrink-0 items-center justify-center rounded-full text-[10px] font-bold",
                    isActive ? "bg-teal-600 text-white" : "bg-muted text-muted-foreground",
                  )}
                  aria-hidden
                >
                  {initialsOf(user.name)}
                </span>
                {user.name}
              </button>
            );
          })}
        </div>
      </div>

      {/* ---- Tablet + mobile (below lg): the same roster as a compact dropdown. Same scope, same
              onChange, same data — only the presentation differs. ---- */}
      <div className="lg:hidden">
        <Select value={selectedValue} onValueChange={handleSelect}>
          <SelectTrigger className="w-full max-w-xs" aria-label={ariaLabel}>
            {/* The option VALUES are BD ids (names are not unique enough to key on), so the trigger
                formats the id back into the name it stands for. */}
            <SelectValue>{(value: string | null) => labelForValue(value)}</SelectValue>
          </SelectTrigger>
          <SelectContent>
            <SelectItem value={ALL_VALUE}>{allLabel}</SelectItem>
            {bdUsers.map((user) => (
              <SelectItem key={user.id} value={user.id}>
                {user.name}
              </SelectItem>
            ))}
          </SelectContent>
        </Select>
      </div>
    </div>
  );
}
