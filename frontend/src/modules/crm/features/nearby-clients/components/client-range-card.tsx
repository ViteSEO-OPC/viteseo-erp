import { CheckCircle2 } from "lucide-react";
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

export function ClientRangeCard({ client, selected, onSelect, onPlanVisit, onDirections, onQualify, qualifying }: Props) {
  const p = PRIORITY[client.priority];
  return (
    <div
      onClick={onSelect}
      className={cn(
        "cursor-pointer rounded-xl border p-4 transition-colors",
        selected ? "border-teal-400 bg-teal-50/50" : "border-border bg-card hover:border-teal-200",
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
      </div>

      {client.website && (
        <div className="mt-3 border-t border-border-subtle pt-3">
          <WebsiteIntelPanel website={client.website} priority={client.priority} />
        </div>
      )}

      <div className="mt-3 flex items-center gap-2">
        <Button
          variant="default"
          size="sm"
          disabled={qualifying}
          onClick={(e) => { e.stopPropagation(); onQualify(); }}
          title="Accept this prospect into your CRM leads"
        >
          <CheckCircle2 className="h-3.5 w-3.5" aria-hidden />
          {qualifying ? "Accepting…" : "Accept into CRM"}
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
