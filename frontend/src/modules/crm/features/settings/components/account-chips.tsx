import { cn } from "@/modules/crm/lib/utils";
import type { TeamUserStatus } from "@/modules/crm/features/settings/types/user-types";

/**
 * There is deliberately no RoleChip here any more.
 *
 * It mapped a role slug through ROLE_SHORT_LABELS and fell back to an em dash,
 * so every member on one of the org's OWN roles rendered as "—" — a person with
 * real access shown as having none. Team & Roles now resolves the name against
 * the presets AND the custom role the member actually holds, and passes the
 * resulting label in.
 */

export function StatusPill({ status }: { status: TeamUserStatus }) {
  const isActive = status === "active";
  return (
    <span
      className={cn(
        "inline-flex items-center rounded-full px-2 py-0.5 text-[11px] font-semibold uppercase tracking-wide",
        isActive ? "bg-teal-500/10 text-teal-700" : "bg-muted text-muted-foreground",
      )}
    >
      {isActive ? "Active" : "Suspended"}
    </span>
  );
}

export function initialsOf(name: string): string {
  return (
    name
      .split(" ")
      .map((w) => w[0])
      .filter(Boolean)
      .join("")
      .slice(0, 2)
      .toUpperCase() || "U"
  );
}
