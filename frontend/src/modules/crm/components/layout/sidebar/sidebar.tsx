import Link from "next/link";
import { LayoutGrid } from "lucide-react";
import { cn } from "@/modules/crm/lib/utils";
import { BrandLockup } from "@/modules/crm/components/layout/sidebar/brand-lockup";
import { NavGroupLabel } from "@/modules/crm/components/layout/sidebar/nav-group-label";
import { SidebarNav } from "@/modules/crm/components/layout/sidebar/sidebar-nav";
import { Separator } from "@/modules/crm/components/ui/separator";

type SidebarVariant = "full" | "icon-rail" | "overlay";

type SidebarProps = {
  variant?: SidebarVariant;
  className?: string;
};

export function Sidebar({ variant = "full", className }: SidebarProps) {
  const collapsed = variant === "icon-rail";

  return (
    <aside
      aria-label="Sidebar"
      className={cn(
        "flex h-full flex-col overflow-y-auto border-r border-sidebar-border bg-sidebar",
        variant === "full" && "w-[288px]",
        variant === "icon-rail" && "w-[64px]",
        variant === "overlay" && "w-full",
        className,
      )}
    >
      <BrandLockup collapsed={collapsed} />
      <Separator />
      <NavGroupLabel label="WORKSPACE" collapsed={collapsed} />
      <SidebarNav collapsed={collapsed} />

      {/* Back to the launcher (all systems) — consistent with the HRIS shell. */}
      <div className="mt-auto border-t border-sidebar-border p-2">
        <Link
          href="/"
          title="Switch app"
          className={cn(
            "flex items-center gap-3 rounded-lg text-sm font-medium text-sidebar-foreground transition-colors hover:bg-sidebar-accent hover:text-white",
            collapsed ? "justify-center px-0 py-3" : "px-3 py-2.5",
          )}
        >
          <LayoutGrid className="h-[18px] w-[18px] shrink-0" />
          {!collapsed && <span>Switch app</span>}
        </Link>
      </div>
    </aside>
  );
}
