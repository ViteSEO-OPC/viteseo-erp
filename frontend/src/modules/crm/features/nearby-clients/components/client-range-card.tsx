import { useState } from "react";
import { CheckCircle2, ChevronDown, Globe } from "lucide-react";
import { AvatarInitials } from "@/modules/crm/components/shared/avatar-initials/avatar-initials";
import { Button } from "@/modules/crm/components/ui/button";
import { cn } from "@/modules/crm/lib/utils";
import { WebsiteIntelPanel } from "@/modules/crm/features/nearby-clients/components/website-intel-panel";
import type { NearbyClient, NearbyPriority } from "@/modules/crm/features/nearby-clients/types/nearby-clients-types";

const PRIORITY: Record<NearbyPriority, { dot: string; text: string; cls: string }> = {
  high: { dot: "bg-red-500", text: "High priority", cls: "text-red-600" },
  medium: { dot: "bg-amber-400", text: "Medium priority", cls: "text-amber-600" },
  low: { dot: "bg-blue-500", text: "Low priority", cls: "text-blue-600" },
};

/**
 * WHERE THE BUSINESS CAME FROM, on the row itself.
 *
 * Provenance was filterable but never shown, so a board mixing three discovery lanes looked like one
 * undifferentiated pool. Read from `foundBy`, which the payload already carries — no new request.
 *
 * `unknown` draws nothing. A lane this build does not recognise must not be folded into one it does;
 * saying nothing is the honest rendering of a provenance we cannot name.
 */
const FOUND_BY_CHIP: Record<string, string> = {
  "google-places": "Google Places",
  "ai-assisted-google-places": "AI-assisted · Google Places",
  "ai-assisted": "AI-assisted · OpenStreetMap",
  openstreetmap: "OpenStreetMap",
};

function Chip({ dot, text, cls }: { dot: string; text: string; cls: string }) {
  return (
    <span className={cn("inline-flex items-center gap-1.5 text-xs font-medium", cls)}>
      <span className={cn("h-1.5 w-1.5 rounded-full", dot)} aria-hidden />
      {text}
    </span>
  );
}

type Props = {
  client: NearbyClient;
  selected?: boolean;
  onSelect: () => void;
  onPlanVisit: () => void;
  onDirections: () => void;
  onQualify: () => void;
  qualifying?: boolean;
};

/**
 * The one line that stands in for the audit when it is collapsed.
 *
 * Says only what the record actually establishes. `website-unknown` is NOT "no website" — it means
 * nobody has looked yet — and the two must never read the same, because one is a finding and the
 * other is an absence of one.
 */
function websiteHeadline(website: NonNullable<NearbyClient["website"]>): string {
  // A SOCIAL PROFILE IS NOT A WEBSITE, and saying so is the point. Google Places returns one URL per
  // business and a third of them are Facebook pages; calling that "website found" told a BD the
  // opposite of what the record means.
  if (website.urlKind === "social") {
    const where = website.social.facebook ? "Facebook" : website.social.instagram ? "Instagram" : "social";
    return `No website — ${where} page only`;
  }
  if (website.urlKind === "directory" || website.urlKind === "link_in_bio") {
    return "No website — directory listing only";
  }
  if (!website.audited) {
    return website.url ? "Website found — not audited yet" : "Website — not checked yet";
  }
  switch (website.status) {
    case "has-website": return "Website audited";
    case "needs-redesign": return "Website audited — needs work";
    case "no-website": return "No website found";
    case "website-unreachable": return "Website unreachable";
    default: return "Website — not verified";
  }
}

export function ClientRangeCard({ client, selected, onSelect, onPlanVisit, onDirections, onQualify, qualifying }: Props) {
  const p = PRIORITY[client.priority];
  // Collapsed by default: see the disclosure below.
  const [intelOpen, setIntelOpen] = useState(false);
  return (
    <div
      onClick={onSelect}
      className={cn(
        "cursor-pointer rounded-xl border p-4 transition-colors",
        selected ? "border-teal-400 bg-teal-50/50 dark:bg-teal-950/40" : "border-border bg-card hover:border-teal-200",
      )}
    >
      <div className="flex items-start justify-between gap-2">
        <div className="flex min-w-0 items-start gap-3">
          <AvatarInitials initials={client.initials} size="sm" />
          <div className="min-w-0">
            <p className="truncate text-sm font-bold text-card-foreground">{client.business}</p>
            <p className="truncate text-xs text-muted-foreground">
              {[client.niche, client.area, client.contact].filter(Boolean).join(" · ")}
            </p>
          </div>
        </div>
        {client.distanceKm !== undefined && (
          <span className="shrink-0 text-sm font-bold text-teal-700">{client.distanceKm.toFixed(1)} km</span>
        )}
      </div>

      <div className="mt-3 flex flex-wrap items-center gap-3">
        {client.active && <Chip dot="bg-green-500" text="Active" cls="text-green-700" />}
        <Chip dot={p.dot} text={p.text} cls={p.cls} />
        {FOUND_BY_CHIP[client.foundBy] !== undefined && (
          <Chip
            dot="bg-slate-400"
            text={FOUND_BY_CHIP[client.foundBy]}
            cls="text-muted-foreground"
          />
        )}
        {client.qualified && (
          <Chip dot="bg-muted-foreground" text="Already added to CRM" cls="text-muted-foreground" />
        )}
      </div>

      {/* The pool is shared, so a claimed prospect stays listed. Naming the claimant is what makes
          that read as "someone got there first" rather than as a broken button. */}
      {client.qualified && client.qualifiedBy && (
        <p className="mt-2 text-xs text-muted-foreground">
          Added by: <span className="font-medium text-card-foreground">{client.qualifiedBy.name}</span>
        </p>
      )}

      {/* WEBSITE INTELLIGENCE, COLLAPSED BY DEFAULT — the single change that makes this a list.
          Measured: the panel rendered 307px of a 457px card, for every one of 464 cards, so the
          results column could show less than one business at a time while the map beside it showed
          the whole country. It is a DETAIL view, and rendering a detail view once per row is what
          turned the list into a stack of dossiers.
          Nothing is removed: the headline the panel leads with is summarised on one line, and the
          full audit — signals, evidence, priority rationale — is one click away and unchanged. The
          disclosure stops the card's click from selecting it, so opening intelligence never moves
          the map. */}
      {client.website && (
        <div className="mt-3 border-t border-border-subtle pt-3">
          <button
            type="button"
            onClick={(e) => { e.stopPropagation(); setIntelOpen((v) => !v); }}
            aria-expanded={intelOpen}
            className="flex w-full items-center justify-between gap-2 text-left"
          >
            <span className="flex min-w-0 items-center gap-1.5 text-xs font-medium text-muted-foreground">
              <Globe className="h-3.5 w-3.5 shrink-0" aria-hidden />
              <span className="truncate">{websiteHeadline(client.website)}</span>
            </span>
            <ChevronDown
              className={cn("h-3.5 w-3.5 shrink-0 text-muted-foreground transition-transform", intelOpen && "rotate-180")}
              aria-hidden
            />
          </button>

          {intelOpen && (
            <div className="mt-3">
              <WebsiteIntelPanel
                leadId={client.id}
                business={client.business}
                website={client.website}
                priority={client.priority}
              />
            </div>
          )}
        </div>
      )}

      <div className="mt-3 flex items-center gap-2">
        <Button
          variant="default"
          size="sm"
          disabled={qualifying || client.qualified}
          onClick={(e) => { e.stopPropagation(); onQualify(); }}
          title={
            client.qualified
              ? `Already accepted into the CRM${client.qualifiedBy ? ` by ${client.qualifiedBy.name}` : " by another BD"} — it can no longer be accepted.`
              : "Accept this prospect into your CRM leads"
          }
        >
          <CheckCircle2 className="h-3.5 w-3.5" aria-hidden />
          {client.qualified ? "Already in CRM" : qualifying ? "Accepting…" : "Accept into CRM"}
        </Button>
        <Button
          variant="outline"
          size="sm"
          onClick={(e) => { e.stopPropagation(); onPlanVisit(); }}
        >
          Plan visit
        </Button>
        <Button
          variant="ghost"
          size="sm"
          onClick={(e) => { e.stopPropagation(); onDirections(); }}
        >
          Directions
        </Button>
      </div>
    </div>
  );
}
