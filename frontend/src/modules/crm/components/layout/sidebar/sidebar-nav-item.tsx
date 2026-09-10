import Link from "next/link";
import { cn } from "@/modules/crm/lib/utils";
import { NavCountBadge } from "@/modules/crm/components/layout/sidebar/nav-count-badge";
import type { LucideIcon } from "lucide-react";

type SidebarNavItemProps = {
  label: string;
  icon: LucideIcon;
  href: string;
  active?: boolean;
  badgeCount?: number;
  collapsed?: boolean;
};

export function SidebarNavItem({
  label,
  icon: Icon,
  href,
  active,
  badgeCount,
  collapsed,
}: SidebarNavItemProps) {
  return (
    // In the rail the label is hidden, so the native tooltip becomes the label.
    <Link
      href={href}
      className={cn(
        "group relative flex items-center gap-3 rounded-lg text-sm font-medium transition-colors",
        "focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring",
        collapsed ? "justify-center px-0 py-3" : "px-3 py-2.5",
        active
          ? "bg-sidebar-accent text-white"
          : "text-sidebar-foreground hover:bg-sidebar-accent hover:text-white",
      )}
      aria-current={active ? "page" : undefined}
      title={collapsed ? label : undefined}
    >
      {active && (
        <span className="absolute left-0 top-1/2 h-5 w-0.5 -translate-y-1/2 rounded-r bg-brand" />
      )}
      <Icon
        className={cn(
          "h-[18px] w-[18px] shrink-0",
          active ? "text-white" : "text-sidebar-foreground group-hover:text-white",
        )}
      />
      {collapsed ? (
        // The rail has no room for a count, so an attention dot stands in —
        // the same substitution the platform sidebar makes, so a reader who
        // learns it in one portal reads it correctly in the next.
        badgeCount !== undefined && (
          <span className="absolute right-2 top-1.5 size-2 rounded-full bg-danger" />
        )
      ) : (
        <>
          <span className="flex-1 truncate">{label}</span>
          {badgeCount !== undefined && (
            <NavCountBadge count={badgeCount} />
          )}
        </>
      )}
    </Link>
  );
}
