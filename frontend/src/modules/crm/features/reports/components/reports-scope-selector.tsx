"use client";

import { Users } from "lucide-react";
import { useBdUsers } from "@/modules/crm/hooks/use-bd-users";
import { cn } from "@/modules/crm/lib/utils";
import {
  ALL_SCOPE,
  type PerformanceScope,
} from "@/modules/crm/features/performance/lib/performance-scope";

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
 * Shared by Reports & Performance and the Admin/Super Admin Leads page. Only the "all" chip's wording
 * and the group's accessible name vary between them — both default to the Reports copy, so that page
 * is unchanged. Each caller maps the scope onto its own filter (Reports → `user_id`; Leads → the
 * existing `assigned_to` param), which keeps one tabs implementation rather than two.
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

  const chipBase =
    "flex shrink-0 items-center gap-2 whitespace-nowrap rounded-lg border px-3 py-1.5 text-sm font-medium transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-ring";
  const activeChip = "border-border bg-card text-teal-900 shadow-sm";
  const idleChip = "border-transparent text-muted-foreground hover:text-foreground";

  return (
    // Horizontal scroll — never pagination or a dropdown — when the roster outgrows the row.
    <div className="max-w-full overflow-x-auto">
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
          <Users
            className={cn("h-4 w-4", isAll ? "text-teal-500" : "text-muted-foreground")}
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
                  "inline-flex h-6 w-6 shrink-0 items-center justify-center rounded-full text-[10px] font-bold",
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
  );
}
