"use client";

import { useState } from "react";
import {
  Eye,
  Pencil,
  Plus,
  Upload,
  BadgeCheck,
  Download,
  Paperclip,
  CalendarClock,
  Target,
  Trash2,
  RotateCcw,
  Users,
  UserPlus,
  BarChart3,
  Plug,
  MapPin,
  SlidersHorizontal,
  Tags,
  Handshake,
  LayoutDashboard,
  type LucideIcon,
} from "lucide-react";
import { cn } from "@/modules/crm/lib/utils";
import { Card } from "@/modules/crm/components/ui/card";
import { AlertBanner } from "@/modules/crm/components/shared/alert-banner/alert-banner";

type Capability = {
  icon: LucideIcon;
  title: string;
  description: string;
};

type CapabilityGroup = {
  title: string;
  items: Capability[];
};

type RoleKey = "crm_bd" | "crm_admin" | "super_admin";

type RoleReference = {
  key: RoleKey;
  /** Tab label. Uses the canonical role names — see the naming note on ROLES below. */
  tab: string;
  /** Fills "What … accounts can do in Zerem." */
  subject: string;
  groups: CapabilityGroup[];
  /** Stated as prose, never as an unticked row. */
  notAvailable: string;
};

/**
 * The three CRM roles, with the capabilities each one actually holds — traced to the policy, route
 * guard or permission grant behind every line and verified against the code on 2026-08-28.
 * DESCRIPTIVE only: nothing here is read from, or can write to, the permission system.
 *
 * NAMING. The tab labels are the canonical role names from `rbac.ts` ROLE_LABELS —
 * "Business Development Manager", "Admin", "Super Admin". User-Roles.md freezes the first of these
 * and explicitly forbids the partial "Business Development", so the longer label is deliberate.
 *
 * TWO BOUNDARIES, NOT ONE. A role can hold a permission and still not have the page. Admin holds
 * `crm.follow-ups.*` and `crm.calendar.*`, yet Outreach and Planner are hidden from Admin by the
 * route guard (commit a8c59db5, "limit Admin navigation to the approved module list"). Super Admin
 * bypasses every BACKEND check via Gate::before, but the frontend guard has no such bypass —
 * `allowedRoles` is exact membership — so the BD-personal surfaces (Planner, My Performance,
 * Profile) are closed to it too. Page access is therefore its own group per role rather than being
 * folded into the action lists, because collapsing them would state something false either way.
 *
 * Neither "Admin = everything" nor "Super Admin = everything" is true, and the differences below
 * are the real ones, not invented to make the tabs look distinct.
 */
const ROLES: RoleReference[] = [
  {
    key: "crm_bd",
    tab: "Business Development Manager",
    subject: "Business Development",
    notAvailable:
      "Deleting leads, restoring deleted leads, assigning leads to another BD, deleting a Business Niche, and the Admin surfaces — Reports, Team & Roles, Integrations and Business Rules — are Admin and Super Admin only. Permanent deletion is unavailable to every role.",
    groups: [
      {
        title: "Pages",
        items: [
          {
            icon: LayoutDashboard,
            title: "Dashboard, Leads, Nearby Clients, Outreach, Planner, My Performance",
            description:
              "Plus Activity Logs, Notifications and their own profile. Planner and My Performance are BD-only — no other role sees them.",
          },
        ],
      },
      {
        title: "Lead access",
        items: [
          {
            icon: Eye,
            title: "View their assigned leads",
            description: "See the leads assigned to them, and only those.",
          },
          {
            icon: Pencil,
            title: "Edit their assigned leads",
            description: "Update contact details, status and notes on leads they own.",
          },
        ],
      },
      {
        title: "Lead management",
        items: [
          {
            icon: Plus,
            title: "Create new leads",
            description: "Add a lead by hand from the Leads workspace.",
          },
          {
            icon: BadgeCheck,
            title: "Qualify discovered prospects",
            description:
              "Bring a business from Nearby Clients into their own pipeline. Qualifying is what assigns ownership.",
          },
          {
            icon: Handshake,
            title: "Close their own deals",
            description: "Mark a lead they own as Won.",
          },
          {
            icon: Tags,
            title: "Create and rename Business Niches",
            description: "The niche registry is shared. Deleting a niche is Admin and Super Admin only.",
          },
        ],
      },
      {
        title: "Import and export",
        items: [
          {
            icon: Upload,
            title: "Import leads",
            description:
              "Upload a file of one or many leads. Imported rows are assigned to the importing BD — they cannot be handed to someone else.",
          },
          {
            icon: Download,
            title: "Export their own leads",
            description:
              "Their leads as CSV, or a single lead as PDF. Export follows read: it never returns more than they can already see.",
          },
        ],
      },
      {
        title: "Outreach and Planner",
        items: [
          {
            icon: CalendarClock,
            title: "Plan and log outreach",
            description: "Follow-ups, emails, calls and Planner items, including the Calling Workspace.",
          },
          {
            icon: Paperclip,
            title: "Add notes and attachments",
            description: "Keep proposals, quotations and permits on the lead.",
          },
        ],
      },
      {
        title: "Reporting",
        items: [
          {
            icon: Target,
            title: "View their own performance",
            description: "Their personal scorecard and Planner history, exportable as PDF.",
          },
        ],
      },
    ],
  },
  {
    key: "crm_admin",
    tab: "Admin",
    subject: "Admin",
    notAvailable:
      "Deleting an account is Super Admin only, and an Admin may act only on Business Development accounts — never on another Admin or a Super Admin, and it can assign only the BD role. Nearby Clients, Outreach, Planner and My Performance are not part of the Admin navigation.",
    groups: [
      {
        title: "Pages",
        items: [
          {
            icon: LayoutDashboard,
            title: "Dashboard, Leads, Reports & Performance, Planner History, Settings",
            description:
              "Plus Activity Logs and Notifications. Nearby Clients, Outreach and Planner are deliberately outside the Admin module list.",
          },
        ],
      },
      {
        title: "Lead access",
        items: [
          {
            icon: Eye,
            title: "View every lead",
            description: "No ownership boundary — an Admin inspects all leads across every BD.",
          },
          {
            icon: Pencil,
            title: "Edit any lead",
            description: "Correct details on any lead, not only their own.",
          },
        ],
      },
      {
        title: "Lead management",
        items: [
          {
            icon: Plus,
            title: "Create and qualify leads",
            description: "Everything a BD can create, plus qualification of discovered prospects.",
          },
          {
            icon: Users,
            title: "Assign and reassign leads",
            description: "Move ownership between Business Development accounts.",
          },
          {
            icon: Trash2,
            title: "Delete leads",
            description: "A delete is an archive — the lead is soft-deleted, never destroyed.",
          },
          {
            icon: RotateCcw,
            title: "Restore deleted leads",
            description: "Archive Management: bring archived leads back, one at a time or in bulk.",
          },
          {
            icon: Tags,
            title: "Manage Business Niches",
            description: "Create, rename and delete entries in the shared niche registry.",
          },
        ],
      },
      {
        title: "Import and export",
        items: [
          {
            icon: Upload,
            title: "Import leads for any BD",
            description: "Choose which Business Development account the imported rows are assigned to.",
          },
          {
            icon: Download,
            title: "Export leads and reports",
            description: "All leads as CSV, a lead as PDF, and performance scorecards for any scope.",
          },
        ],
      },
      {
        title: "Reporting",
        items: [
          {
            icon: BarChart3,
            title: "Reports & Performance",
            description:
              "CRM-wide analytics or one BD at a time, with the BD Performance Overview and ranking.",
          },
        ],
      },
      {
        title: "Administration",
        items: [
          {
            icon: UserPlus,
            title: "Team & Roles",
            description:
              "Create accounts, grant Zerem access to an existing Vite SEO Systems account, set anyone's role, suspend or restore CRM access, hand a resigning BD's leads on, and build the organisation's own roles.",
          },
          {
            icon: Plug,
            title: "Integrations",
            description: "Connect the shared Gmail mailbox and choose the lead-discovery provider.",
          },
          {
            icon: MapPin,
            title: "Target Service Areas",
            description: "Maintain the service-area registry behind the Target Location signal.",
          },
          {
            icon: SlidersHorizontal,
            title: "Performance Management",
            description: "Define KPIs and set performance goals.",
          },
        ],
      },
    ],
  },
  {
    key: "super_admin",
    tab: "Super Admin",
    subject: "Super Admin",
    notAvailable:
      "Planner, My Performance and the BD profile page are Business Development workspaces and are not part of the Super Admin navigation. Permanent deletion of a lead is unavailable to every role, and Super Admin is never offered as an assignable role.",
    groups: [
      {
        title: "Pages",
        items: [
          {
            icon: LayoutDashboard,
            title: "Every CRM page except the BD-personal workspaces",
            description:
              "Including Nearby Clients and Outreach, which are outside the Admin module list.",
          },
        ],
      },
      {
        title: "Everything an Admin can do",
        items: [
          {
            icon: BadgeCheck,
            title: "The full Admin capability set",
            description:
              "All lead access and management, import and export, reporting and administration listed under Admin.",
          },
        ],
      },
      {
        title: "Platform-owner capabilities",
        items: [
          {
            icon: Trash2,
            title: "Delete accounts",
            description:
              "The only role that can delete a user account, rather than suspending its CRM access.",
          },
          {
            icon: Users,
            title: "Manage every account, including Admins",
            description:
              "An Admin may act only on BD accounts. Super Admin has no such limit and is the only role that can assign the Admin role.",
          },
          {
            icon: SlidersHorizontal,
            title: "Business Rules",
            description:
              "The permanent owner of Lead Scoring administration. Admin holds this only as a temporary development grant.",
          },
        ],
      },
    ],
  },
];

/**
 * Built-in roles — a READ-ONLY reference, and Zerem's own rather than the shared one.
 *
 * {@link CustomRolesManager} ships a `PresetRoles` chip list that every portal can
 * mount, and Zerem mounts it with `showBuiltInRoles={false}` so that this survives
 * instead. Two answers to "what are the built-in roles?" on one page is the confusion
 * the merge exists to remove, so exactly one of them stays — and it is this one,
 * because the chip list cannot say what this says:
 *
 *  - THREE roles, not two. Super Admin belongs to Vite SEO and is never assignable, so
 *    it is absent from the picker and from the presets endpoint — and it is also the
 *    role that answers "why can't an Admin delete an account?".
 *  - TWO BOUNDARIES. A role can hold a permission and still not have the page: Admin
 *    holds `crm.follow-ups.*` and `crm.calendar.*` while Outreach and Planner are
 *    closed to it by the route guard. A flat list of granted capabilities states the
 *    opposite of the truth here, which is why "Pages" is its own group per role.
 *  - What is NOT available, in prose. An absent chip is not a statement; a sentence is.
 *
 * DESCRIPTIVE ONLY: nothing here is read from, or can write to, the permission system.
 * Traced to the policy, route guard or permission grant behind every line and verified
 * against the code on 2026-08-28.
 *
 * ARCHITECTURE — why this can never become the editor, not merely "not yet":
 * Vite SEO Systems is ONE centralized account authority shared by TeamOra, Zerem and
 * Sprintly: a single unprefixed `users` table plus the shared spatie/laravel-permission
 * tables. Zerem owns none of it. No CRM endpoint mutates a permission, and the only role
 * write Zerem has is CrmAccessService::setCrmRole, which is deliberately CRM-SCOPED — it
 * may attach/detach the CRM roles and must never syncRoles(), because that would strip a
 * colleague's hr_employee / pm_member and cut them out of the other two products.
 *
 * So Zerem may assign an account's CRM ROLE; it may not define what that role can DO.
 * The org's OWN roles below are a different matter: those are the client's to define,
 * bounded by the functions it availed, through org-wide endpoints Zerem does not own
 * either. The built-in three stay exactly as they are — read-only, and unchangeable
 * from Zerem.
 *
 * The role tabs are presentation only. `useState` holds which reference list is visible
 * and nothing else — no permission state, no request, no mutation.
 */
export function BuiltInRolesReference() {
  const [activeRole, setActiveRole] = useState<RoleKey>("crm_bd");
  const role = ROLES.find((r) => r.key === activeRole) ?? ROLES[0];

  return (
    <section className="flex flex-col gap-4">
      <div>
        <h2 className="text-lg font-bold text-foreground">Built-in roles</h2>
        <p className="mt-1 max-w-2xl text-sm text-muted-foreground">
          Defined by the platform and not editable here. These are the &ldquo;Built-in
          roles&rdquo; group in the role pickers above. Check what one grants before
          assigning it — if none of them fits, build a custom role below.
        </p>
      </div>

      {/* The picker above offers two built-in roles: CRM Manager (documented here as
          "Admin") and Business Development ("Business Development Manager"). Super
          Admin is Vite SEO's own and is documented but never assignable — saying
          otherwise put a role in the sentence that is absent from the picker. */}
      <AlertBanner
        variant="info"
        message="These roles are built into Vite SEO Systems, the shared account authority, and enforced by the server on every request. Zerem can put an account in a built-in role but cannot change what one grants — this is a read-only reference. In the role picker above, Admin is listed as “CRM Manager” and Business Development Manager as “Business Development”. Super Admin belongs to Vite SEO: it is described here for context and is never offered in the picker."
      />

      {/* Segmented control, matching PlannerModeBar's idiom rather than inventing a new one. These
          are real buttons with aria-pressed, not links — switching tabs changes only which
          reference list is rendered. */}
      <div
        className="inline-flex flex-wrap items-center gap-0.5 self-start rounded-lg bg-muted p-0.5"
        role="group"
        aria-label="CRM roles"
      >
        {ROLES.map((r) => {
          const isActive = r.key === activeRole;
          return (
            <button
              key={r.key}
              type="button"
              aria-pressed={isActive}
              onClick={() => setActiveRole(r.key)}
              className={cn(
                "rounded-md px-3 py-1.5 text-sm font-medium transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-ring",
                isActive
                  ? "bg-card text-teal-900 shadow-sm dark:text-teal-400"
                  : "text-muted-foreground hover:text-foreground",
              )}
            >
              {r.tab}
            </button>
          );
        })}
      </div>

      <Card className="border border-border bg-card p-0 shadow-card">
        <div className="border-b border-border-subtle px-5 py-4">
          <h3 className="text-sm font-bold text-card-foreground">
            What {role.subject} accounts can do
          </h3>
        </div>

        {role.groups.map((group) => (
          <section key={group.title} className="border-b border-border-subtle last:border-b-0">
            <h4 className="bg-muted/40 px-5 py-2 text-[11px] font-bold uppercase tracking-[0.08em] text-muted-foreground">
              {group.title}
            </h4>
            {/* A <ul> because the rows carry no controls: this is a list of capabilities, so it
                should announce itself as one. */}
            <ul className="divide-y divide-border-subtle">
              {group.items.map((item) => {
                const Icon = item.icon;
                return (
                  <li key={item.title} className="flex items-start gap-3 px-5 py-4">
                    <span className="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-muted text-muted-foreground">
                      <Icon className="h-4 w-4" aria-hidden />
                    </span>
                    <div>
                      <p className="text-sm font-semibold text-card-foreground">{item.title}</p>
                      <p className="text-sm text-muted-foreground">{item.description}</p>
                    </div>
                  </li>
                );
              })}
            </ul>
          </section>
        ))}
      </Card>

      <p className="text-sm text-muted-foreground">{role.notAvailable}</p>
    </section>
  );
}
