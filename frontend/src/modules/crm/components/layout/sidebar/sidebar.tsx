"use client";

import { ChevronsLeft, ChevronsRight } from "lucide-react";
import { cn } from "@/modules/crm/lib/utils";
import { BrandLockup } from "@/modules/crm/components/layout/sidebar/brand-lockup";
import { NavGroupLabel } from "@/modules/crm/components/layout/sidebar/nav-group-label";
import { SidebarNav } from "@/modules/crm/components/layout/sidebar/sidebar-nav";
import { Separator } from "@/modules/crm/components/ui/separator";

type SidebarProps = {
  /**
   * Icon-rail mode: labels hidden, tooltips on. Owned by the shell and
   * persisted per user — it used to be derived from the breakpoint alone
   * (a rail between md and lg that the reader could not open), which is not
   * the same thing as a preference.
   */
  collapsed?: boolean;
  /** When provided, renders the collapse toggle in the footer. */
  onToggleCollapse?: () => void;
  /** Inside the off-canvas drawer, which supplies its own width. */
  overlay?: boolean;
  className?: string;
};

export function Sidebar({ collapsed = false, onToggleCollapse, overlay, className }: SidebarProps) {
  return (
    <aside
      aria-label="Sidebar"
      className={cn(
        "flex h-full flex-col overflow-y-auto border-r border-sidebar-border bg-sidebar transition-[width] duration-200",
        // 16rem / 4rem are the platform's shell widths — the same two numbers
        // AppShell publishes as `--shell-sidebar-w`, so a modal offsetting
        // around the sidebar lands correctly in Zerem too.
        overlay ? "w-full" : collapsed ? "w-16" : "w-64",
        className,
      )}
    >
      <BrandLockup collapsed={collapsed} />
      <Separator />
      <NavGroupLabel label="WORKSPACE" collapsed={collapsed} />
      <SidebarNav collapsed={collapsed} />

      {/* Footer: the desktop collapse toggle (the drawer never passes it).
          "Switch app" used to live here; it is now the header's AppSwitcher,
          in the same position as in every other portal. */}
      {onToggleCollapse && (
        <div className="mt-auto border-t border-sidebar-border p-2">
          <button
            type="button"
            onClick={onToggleCollapse}
            aria-label={collapsed ? "Expand sidebar" : "Collapse sidebar"}
            title={collapsed ? "Expand sidebar" : "Collapse sidebar"}
            className={cn(
              "flex w-full items-center gap-3 rounded-lg text-sm font-medium text-sidebar-foreground transition-colors hover:bg-sidebar-accent hover:text-white",
              collapsed ? "justify-center px-0 py-3" : "px-3 py-2.5",
            )}
          >
            {collapsed ? (
              <ChevronsRight className="h-[18px] w-[18px] shrink-0" />
            ) : (
              <>
                <ChevronsLeft className="h-[18px] w-[18px] shrink-0" />
                <span>Collapse</span>
              </>
            )}
          </button>
        </div>
      )}
    </aside>
  );
}
