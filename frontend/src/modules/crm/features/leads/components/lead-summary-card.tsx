import { User, Mail, Phone, Briefcase, UserCheck, Clock, Wallet } from "lucide-react";
import { AvatarInitials } from "@/modules/crm/components/shared/avatar-initials/avatar-initials";
import { PriorityChip } from "@/modules/crm/components/shared/status-badge/priority-chip";
import { StatusChip } from "@/modules/crm/components/shared/status-badge/status-chip";
import { LeadScoreRing } from "@/modules/crm/features/leads/components/lead-score-ring";
import { InfoGrid, InfoField } from "@/modules/crm/components/shared/info-grid/info-grid";
import { formatActivityTimestamp } from "@/modules/crm/lib/formatters";
import type { LeadDetail } from "@/modules/crm/features/leads/types/lead-detail-types";
import { WEBSITE_STATUS_LABELS } from "@/modules/crm/features/leads/types/lead-types";

type Props = {
  lead: LeadDetail;
};

export function LeadSummaryCard({ lead }: Props) {
  // Reuse the website value already mapped into lead.chips (from api.websiteStatus) — the
  // same source the Edit drawer reads — so Lead Detail and Edit never diverge. No separate
  // field is introduced, avoiding duplicate copies of the same data.
  const websiteStatus = lead.chips.find((chip) => chip.type === "website")?.value;
  const websiteLabel = websiteStatus
    ? (WEBSITE_STATUS_LABELS[websiteStatus as keyof typeof WEBSITE_STATUS_LABELS] ?? websiteStatus)
    : "No Website";
  return (
    <div className="rounded-xl border border-border bg-card p-6 shadow-card">
      <div className="flex items-start justify-between gap-4">
        <div className="flex items-start gap-4">
          <AvatarInitials
            initials={lead.business.split(" ").map(w => w[0]).join("").slice(0, 2)}
            size="md"
          />
          <div>
            <h3 className="text-2xl font-bold leading-tight text-card-foreground">
              {lead.business}
            </h3>
            <div className="mt-2 flex flex-wrap items-center gap-2">
              <PriorityChip priority={lead.priority} />
              <StatusChip status={lead.status} />
              <span className="inline-flex items-center rounded-full bg-gray-100 px-2 py-0.5 text-[11px] font-medium text-gray-600">
                {websiteLabel}
              </span>
            </div>
          </div>
        </div>
        <LeadScoreRing value={lead.score} />
      </div>

      <div className="mt-6 border-t border-border-subtle pt-6">
        <InfoGrid className="gap-x-6 gap-y-5">
          <InfoField label="CONTACT PERSON" icon={<User />}>
            {lead.contact}
          </InfoField>
          <InfoField label="EMAIL" icon={<Mail />}>
            {lead.email}
          </InfoField>
          <InfoField label="PHONE" icon={<Phone />}>
            {lead.phone}
          </InfoField>
          <InfoField label="INDUSTRY" icon={<Briefcase />}>
            {lead.industry}
          </InfoField>
          <InfoField label="ASSIGNED BD" icon={<UserCheck />}>
            {lead.assignedTo ? (
              lead.assignedTo.name
            ) : (
              <span className="text-muted-foreground italic">Unassigned</span>
            )}
          </InfoField>
          {/* Estimated Deal Value (prospect-stage) — NOT revenue (revenue is on the Deal). */}
          <InfoField label="AMOUNT" icon={<Wallet />}>
            {lead.amount !== null ? (
              lead.amount.toLocaleString("en-US")
            ) : (
              <span className="text-muted-foreground italic">Not set</span>
            )}
          </InfoField>
          <InfoField label="LAST ACTIVITY" icon={<Clock />}>
            {formatActivityTimestamp(lead.lastActivity)}
          </InfoField>
        </InfoGrid>
      </div>
    </div>
  );
}
