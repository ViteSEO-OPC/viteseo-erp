import { cn } from "@/modules/crm/lib/utils";
import { ROLE_SHORT_LABELS, isRole } from "@/modules/crm/lib/rbac";
import type { TeamUserStatus } from "@/modules/crm/features/settings/types/user-types";

export function RoleChip({ role }: { role: string | null }) {
  const label = role && isRole(role) ? ROLE_SHORT_LABELS[role] : "—";
  return (
    <span className="inline-flex items-center rounded-full bg-muted px-2 py-0.5 text-[11px] font-medium text-secondary-foreground">
      {label}
    </span>
  );
}

export function StatusPill({ status }: { status: TeamUserStatus }) {
  const isActive = status === "active";
  return (
    <span
      className={cn(
        "inline-flex items-center rounded-full px-2 py-0.5 text-[11px] font-semibold uppercase tracking-wide",
        isActive ? "bg-teal-500/10 text-teal-700" : "bg-gray-100 text-gray-500",
      )}
    >
      {isActive ? "Active" : "Inactive"}
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
