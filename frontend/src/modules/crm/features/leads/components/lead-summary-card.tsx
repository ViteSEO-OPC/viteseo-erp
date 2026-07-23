import {
  User,
  Mail,
  Phone,
  Briefcase,
  UserCheck,
  Wallet,
  Globe,
  ThumbsUp,
  Camera,
  Music2,
  ShoppingBag,
  ShoppingCart,
  Store,
  type LucideIcon,
} from "lucide-react";
import { AvatarInitials } from "@/modules/crm/components/shared/avatar-initials/avatar-initials";
import { PriorityChip } from "@/modules/crm/components/shared/status-badge/priority-chip";
import { StatusChip } from "@/modules/crm/components/shared/status-badge/status-chip";
import { LeadScoreRing } from "@/modules/crm/features/leads/components/lead-score-ring";
import { InfoGrid, InfoField } from "@/modules/crm/components/shared/info-grid/info-grid";
import type { LeadDetail } from "@/modules/crm/features/leads/types/lead-detail-types";
import { WEBSITE_STATUS_LABELS } from "@/modules/crm/features/leads/types/lead-types";

type Props = {
  lead: LeadDetail;
};

// A value becomes a clickable link when it looks like a URL / bare domain; free text (e.g. a store
// name) renders as plain text rather than a broken link. Future-friendly: the same values can later
// feed analytics / outreach / AI enrichment without changing storage.
function normalizePresenceHref(value: string): string | null {
  const v = value.trim();
  if (/^https?:\/\//i.test(v)) return v;
  if (/^[^\s]+\.[^\s]+$/.test(v)) return `https://${v}`;
  return null;
}

// The value node for a presence InfoField: a clickable external link when the value is a URL/domain,
// otherwise plain text (so free text like a store name never renders as a broken link). Rendered as
// the InfoField child so it inherits the exact Contact/Email/Phone value styling.
function PresenceValue({ value }: { value: string }) {
  const href = normalizePresenceHref(value);
  if (!href) return <>{value}</>;
  return (
    <a
      href={href}
      target="_blank"
      rel="noopener noreferrer"
      className="block truncate text-primary hover:underline focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
    >
      {value}
    </a>
  );
}

export function LeadSummaryCard({ lead }: Props) {
  // Reuse the website value already mapped into lead.chips (from api.websiteStatus) — the
  // same source the Edit drawer reads — so Lead Detail and Edit never diverge. No separate
  // field is introduced, avoiding duplicate copies of the same data.
  const websiteStatus = lead.chips.find((chip) => chip.type === "website")?.value;
  const websiteLabel = websiteStatus
    ? (WEBSITE_STATUS_LABELS[websiteStatus as keyof typeof WEBSITE_STATUS_LABELS] ?? websiteStatus)
    : "No Website";

  // Digital + Marketplace presence, in display order — only the values that exist. When none are
  // set the whole section is omitted (no invented placeholders). "Website Domain" reuses lead.website.
  // Each entry pairs the platform label + value with a Lucide icon that echoes the reference (this
  // lucide build ships no brand marks, so semantic glyphs are used). Only populated values are kept.
  const presenceItems: Array<[string, string, LucideIcon]> = (
    [
      ["Website Domain", lead.website, Globe],
      ["Facebook", lead.facebook, ThumbsUp],
      ["Instagram", lead.instagram, Camera],
      ["TikTok", lead.tiktok, Music2],
      ["LinkedIn", lead.linkedin, Briefcase],
      ["Shopee", lead.shopee, ShoppingBag],
      ["Lazada", lead.lazada, ShoppingCart],
      ["TikTok Shop", lead.tiktokShop, Music2],
      ["Other Marketplace", lead.otherMarketplace, Store],
    ] as Array<[string, string | null, LucideIcon]>
  ).filter((entry): entry is [string, string, LucideIcon] => !!entry[1] && entry[1].trim() !== "");

  return (
    <div className="rounded-2xl border border-border bg-card p-6 shadow-card sm:p-7">
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
              <span className="inline-flex items-center rounded-full bg-muted px-2 py-0.5 text-[11px] font-medium text-secondary-foreground">
                {websiteLabel}
              </span>
            </div>
          </div>
        </div>
        <LeadScoreRing value={lead.score} />
      </div>

      <div className="mt-6 border-t border-border-subtle pt-6">
        <InfoGrid className="gap-x-8 gap-y-6">
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
          {/* <InfoField label="LAST ACTIVITY" icon={<Clock />}>
            {formatActivityTimestamp(lead.lastActivity)}
          </InfoField> */}
        </InfoGrid>
      </div>

      {presenceItems.length > 0 ? (
        <div className="mt-6 border-t border-border-subtle pt-6">
          <p className="mb-4 text-[11px] font-semibold uppercase tracking-[0.08em] text-muted-foreground">
            Digital &amp; Marketplace Presence
          </p>
          <InfoGrid className="gap-x-8 gap-y-6">
            {presenceItems.map(([label, value, Icon]) => (
              <InfoField key={label} label={label} icon={<Icon />}>
                <PresenceValue value={value} />
              </InfoField>
            ))}
          </InfoGrid>
        </div>
      ) : null}
    </div>
  );
}
