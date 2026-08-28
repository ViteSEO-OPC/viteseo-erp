"use client";

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
  type LucideIcon,
} from "lucide-react";
import { Card } from "@/modules/crm/components/ui/card";
import { AlertBanner } from "@/modules/crm/components/shared/alert-banner/alert-banner";
import { PendingBadge } from "@/modules/crm/components/shared/pending-badge/pending-badge";

/**
 * The reason carried by the PendingBadge. It names where permissions actually come from, because
 * "not available here" on its own leaves an admin with nowhere to go — while stopping short of
 * implying Zerem could persist them. See the architecture note on the component below.
 */
const PENDING_REASON =
  "Zerem does not define permissions. What each role is allowed to do is set in Vite SEO Systems and enforced by the server. Zerem can only assign an account's CRM role, in Team Accounts.";

type Rule = {
  icon: LucideIcon;
  title: string;
  description: string;
};

/**
 * What a Business Development account can actually do, traced to the authorization that grants it
 * and verified against the code on 2026-08-28. DESCRIPTIVE only — this list is not read from, and
 * cannot write to, the permission system.
 *
 * Every entry below is ownership-scoped by `Lead::scopeVisibleTo`: a BD acts on leads assigned to
 * them, never the whole CRM. The as-built action-by-action table, with the route and policy behind
 * each row, is in the KB at Team-Accounts-and-RBAC-Visibility.md §4.
 *
 * Three rows the page used to carry were simply false and are gone: "Can delete leads" and "Can
 * restore deleted leads" are Admin/Super Admin only, and "Can view reports" described the Admin
 * Reports surface, which a BD cannot reach. The `defaultChecked` flags that drove the old switch
 * positions and an "N of 6 actions enabled" count are gone too — invented values with no source.
 */
const RULES: Rule[] = [
  { icon: Eye, title: "View their assigned leads", description: "See the leads assigned to them, and only those." },
  { icon: Pencil, title: "Edit their assigned leads", description: "Update contact details, status, and notes." },
  { icon: Plus, title: "Create new leads", description: "Add a lead by hand from the Leads workspace." },
  { icon: Upload, title: "Import leads", description: "Upload a file of one or many leads. Imported rows are assigned to them." },
  { icon: BadgeCheck, title: "Qualify discovered prospects", description: "Bring a business from Nearby Clients into their own pipeline." },
  { icon: Download, title: "Export their own leads", description: "Download their leads as CSV, or a single lead as PDF." },
  { icon: Paperclip, title: "Add notes and attachments", description: "Keep proposals, quotations and permits on the lead." },
  { icon: CalendarClock, title: "Plan and log outreach", description: "Follow-ups, emails, calls and Planner items." },
  { icon: Target, title: "View their own performance", description: "Their personal scorecard, exportable as PDF." },
];

/**
 * The boundary, stated once as prose rather than as unticked rows. Rendering these as capabilities
 * with an "off" marker would misread a hard role boundary as a switch someone could flip — and
 * restore is blocked at the route group before any permission check even runs.
 */
const NOT_AVAILABLE_TO_BD =
  "Deleting leads, restoring deleted leads, assigning leads to another BD, and the Admin Reports surface are Admin and Super Admin only. Permanent deletion is unavailable to every role.";

/**
 * Rules & Permissions — a READ-ONLY reference surface.
 *
 * The page previously looked like a working permission editor: six switches carrying hardcoded
 * positions, a "Save rules" button, and a "Last saved Jul 1, 2026" line. None of it was connected —
 * there is no request, no mutation and no state in this file — so an admin could switch off
 * "Can delete leads", press Save, read the saved date and believe a permission had been revoked
 * while the real authorization (role assignment + server-side policies) was untouched.
 *
 * It is now a read-only reference rather than a broken editor. The six switches are gone entirely,
 * which goes one step past the PreferenceRow rule in general-page.tsx (an inert control must never
 * assert a state): merely disabling them still left six controls reading OFF, and "a BD can do none
 * of these" is as false as the invented ON/OFF mix it replaced. With no control there is no state
 * to misread — the list documents the capabilities, and the badge and banner carry the reason.
 *
 * "Save rules" went with them. Its only referent was those switches, and a Save control sitting
 * under a read-only list would re-imply exactly the Zerem-side persistence this page must not
 * claim.
 *
 * ARCHITECTURE — why this page can never be the editor, not merely "not yet":
 * Vite SEO Systems is ONE centralized account authority shared by HRIS, Zerem and PMS: a single unprefixed
 * `users` table plus the shared spatie/laravel-permission `roles` / `permissions` tables. Zerem owns
 * none of it. No CRM endpoint mutates a permission (zero routes under /api/v1/crm touch one), and
 * the only role write Zerem has is CrmAccessService::setCrmRole, which is deliberately CRM-SCOPED —
 * it may attach/detach the CRM roles and must never syncRoles(), because that would strip a
 * colleague's hr_employee / pm_member and cut them out of TeamOra and Sprintly.
 *
 * So Zerem may assign an account's CRM ROLE (Team Accounts); it may not define what that role can
 * DO. Wiring these switches would mean standing up a parallel authorization source beside the
 * platform's, which is exactly what must not happen. The copy below is worded to say that, rather
 * than promising a Zerem-side permission editor that should never exist.
 *
 * Wording follows the sentence already approved one page over, in general-page.tsx: "Sign-in is
 * handled by your Vite SEO Systems account, not by Zerem." Same boundary, same voice, same name for
 * the authority — so the two Settings pages do not describe the platform in two different ways.
 */
export function RulesPermissionsPage() {
  return (
    <div className="flex flex-col gap-6">
      <header>
        <h2 className="text-2xl font-bold text-foreground">Rules &amp; Permissions</h2>
        <p className="mt-1 text-sm text-muted-foreground">
          What a Business Development account can do in Zerem. Determined by the account&apos;s role
          in Vite SEO Systems — not on this page.
        </p>
      </header>

      <AlertBanner
        variant="info"
        message="Permissions are defined in Vite SEO Systems, the shared account authority, and enforced by the server on every request. Zerem can assign an account's CRM role in Team Accounts, but cannot change what a role is allowed to do. The list below is a read-only reference."
      />

      <Card className="border border-border bg-card p-0 shadow-card">
        <div className="flex items-center gap-2 border-b border-border-subtle px-5 py-4">
          <h3 className="text-sm font-bold text-card-foreground">What BD accounts can do</h3>
          <PendingBadge reason={PENDING_REASON} />
        </div>
        {/* A <ul> now that the rows carry no controls: this is a list of capabilities, so it should
            announce itself as one. The row keeps its icon tile, padding and dividers — only the
            trailing switch is gone, and the wrapper the switch needed is flattened away with it. */}
        <ul className="divide-y divide-border-subtle">
          {RULES.map((rule) => {
            const Icon = rule.icon;
            return (
              <li key={rule.title} className="flex items-start gap-3 px-5 py-4">
                <span className="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-muted text-muted-foreground">
                  <Icon className="h-4 w-4" aria-hidden />
                </span>
                <div>
                  <p className="text-sm font-semibold text-card-foreground">{rule.title}</p>
                  <p className="text-sm text-muted-foreground">{rule.description}</p>
                </div>
              </li>
            );
          })}
        </ul>
      </Card>

      <p className="text-sm text-muted-foreground">{NOT_AVAILABLE_TO_BD}</p>
    </div>
  );
}
