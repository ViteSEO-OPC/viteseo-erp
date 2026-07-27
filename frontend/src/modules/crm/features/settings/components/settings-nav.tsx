"use client";

import Link from "next/link";
import { usePathname } from "next/navigation";
import {
  Settings,
  Archive,
  Users,
  Lock,
  SlidersHorizontal,
  type LucideIcon,
} from "lucide-react";
import { cn } from "@/modules/crm/lib/utils";
import { usePermissions } from "@/modules/crm/providers/permissions-provider";

type NavItem = { label: string; href: string; icon: LucideIcon; permission?: string };
type NavGroup = { heading?: string; items: NavItem[] };

const GROUPS: NavGroup[] = [
  { items: [{ label: "General", href: "/zerem/settings", icon: Settings }] },
  {
    heading: "Records & Data",
    items: [
      // Single consolidated Archive Management surface (replaces the former Lead Archive /
      // Recycle Bin / Restore Archived Leads stubs). One archive state: SoftDeletes (deleted_at).
      { label: "Archive Management", href: "/zerem/settings/archive", icon: Archive },
    ],
  },
  {
    heading: "Administrator",
    items: [
      { label: "Team Accounts", href: "/zerem/settings/team-accounts", icon: Users },
      { label: "Rules & Permissions", href: "/zerem/settings/rules-permissions", icon: Lock },
      // Business Rules Administration — gated by the read permission (Super Admin + temporary Admin).
      {
        label: "Business Rules",
        href: "/zerem/settings/business-rules",
        icon: SlidersHorizontal,
        permission: "crm.business_rules.view",
      },
    ],
  },
];

export function SettingsNav() {
  const pathname = usePathname();
  const { can } = usePermissions();
  const isActive = (href: string) =>
    href === "/zerem/settings" ? pathname === "/zerem/settings" : pathname.startsWith(href);

  return (
    <nav
      aria-label="Settings sections"
      className="w-full shrink-0 rounded-lg border border-border bg-card p-2 shadow-card md:w-60"
    >
      {GROUPS.map((group, gi) => (
        <div key={gi} className={cn(gi > 0 && "mt-4")}>
          {group.heading ? (
            <p className="px-3 pb-1 text-[11px] font-semibold uppercase tracking-[0.08em] text-muted-foreground">
              {group.heading}
            </p>
          ) : null}
          <div className="flex flex-col gap-0.5">
            {group.items
              .filter((item) => !item.permission || can(item.permission))
              .map((item) => {
              const active = isActive(item.href);
              const Icon = item.icon;
              return (
                <Link
                  key={item.href}
                  href={item.href}
                  aria-current={active ? "page" : undefined}
                  className={cn(
                    "flex items-center gap-2.5 rounded-md px-3 py-2 text-sm transition-colors",
                    active
                      ? "bg-teal-400/10 font-semibold text-teal-700"
                      : "text-secondary-foreground hover:bg-muted",
                  )}
                >
                  <Icon className="h-4 w-4 shrink-0" aria-hidden />
                  <span className="truncate">{item.label}</span>
                </Link>
              );
            })}
          </div>
        </div>
      ))}
    </nav>
  );
}
