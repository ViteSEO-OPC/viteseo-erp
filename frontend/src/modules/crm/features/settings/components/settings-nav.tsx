"use client";

import Link from "next/link";
import { usePathname } from "next/navigation";
import {
  Settings,
  Archive,
  Users,
  Plug,
  SlidersHorizontal,
  Gauge,
  Mail,
  Palette,
  DatabaseZap,
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
    heading: "NERBY",
    items: [
      // Dataset Import — lands an existing NERBY dataset (JSONL) in the admin's own org. No
      // `permission` gate, like Integrations: the settings area is minRole crm_admin and every
      // endpoint behind the page is role:super_admin|crm_admin server-side, so a BD never reaches it.
      { label: "Dataset Import", href: "/zerem/settings/nerby/dataset-import", icon: DatabaseZap },
    ],
  },
  {
    heading: "Administrator",
    items: [
      // ONE entry, matching TeamOra and Sprintly. It was Team Accounts + Roles &
      // Access, and they were never two tasks: adding a person and deciding what
      // they can do is one, and split across two entries an operator found neither
      // half. Worse, the Team Accounts list ran on /users — which selects by Spatie
      // role and so showed NOTHING at all for an org staffed on its own custom
      // roles. Both paths now redirect here (see next.config.ts).
      { label: "Team & Roles", href: "/zerem/settings/team", icon: Users },
      // Integrations (Gmail connect/disconnect). No `permission` gate: the whole /zerem/settings
      // area is already minRole crm_admin in PAGE_ACCESS, and the endpoints behind it are
      // role:super_admin|crm_admin server-side — a nav-level check would add nothing.
      { label: "Integrations", href: "/zerem/settings/integrations", icon: Plug },
      // Email Templates — the Outreach composer's and automation's reusable templates. No `permission`
      // gate, for the same reason as Integrations: the settings area is minRole crm_admin and every
      // endpoint behind the page is role:super_admin|crm_admin server-side.
      { label: "Email Templates", href: "/zerem/settings/email-templates", icon: Mail },
      // Email Designs — the reusable looks designed templates are shown in. Ungated for the same reason.
      { label: "Email Designs", href: "/zerem/settings/email-designs", icon: Palette },
      // Business Rules Administration — gated by the read permission (Super Admin + temporary Admin).
      {
        label: "Business Rules",
        href: "/zerem/settings/business-rules",
        icon: SlidersHorizontal,
        permission: "crm.business_rules.view",
      },
      // Performance Management — the KPI definition catalog and the performance targets. Sits next
      // to Business Rules because its KPI tab IS the Business Rules surface with `module="kpi"`.
      // Gated by the same permission the page itself checks; the mutations behind both of its tabs
      // are authorized server-side, so this only decides whether the entry point is shown.
      {
        label: "Performance Management",
        href: "/zerem/settings/performance",
        icon: Gauge,
        permission: "crm.performance.manage",
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
