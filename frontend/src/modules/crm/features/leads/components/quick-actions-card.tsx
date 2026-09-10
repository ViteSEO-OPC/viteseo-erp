import { Mail, Calendar, ChevronRight, Check, X } from "lucide-react";
import { cn } from "@/modules/crm/lib/utils";
import {
  Select,
  SelectTrigger,
  SelectContent,
  SelectItem,
} from "@/modules/crm/components/ui/select";
import { StatusChip } from "@/modules/crm/components/shared/status-badge/status-chip";
import { LeadOutcomeChip } from "@/modules/crm/features/leads/components/lead-outcome-chip";
import { formatDateLong } from "@/modules/crm/lib/formatters";
// The four editable statuses now live beside the taxonomy itself — the Leads bulk bar
// needs the same list, and a component is the wrong home for a shared vocabulary.
import { EDITABLE_STATUS_OPTIONS } from "@/modules/crm/lib/lead-status";
import type { LeadStatus } from "@/modules/crm/features/dashboard/types/dashboard-types";
import type { LeadDeal, QuickAction, QuickActionKey } from "@/modules/crm/features/leads/types/lead-detail-types";

const iconMap: Record<string, typeof Mail> = { Mail, Calendar };

const iconToneStyles: Record<string, string> = {
  // Both tile bg and glyph colour are theme tokens (--qa-tile-*/--qa-icon-*); the theme flips them
  // on dark, so the component carries no dark: overrides.
  teal: "bg-[var(--qa-tile-teal)] text-[var(--qa-icon-teal)]",
  amber: "bg-[var(--qa-tile-amber)] text-[var(--qa-icon-orange)]",
};

type Props = {
  /** Row actions: "email" (redirect to Outreach) and "follow-up" (Schedule modal). */
  actions: QuickAction[];
  onSelect: (key: QuickActionKey) => void;
  /** Lead Status quick-change — reuses the existing lead-update path (no new backend). */
  status: LeadStatus;
  onStatusChange: (status: string) => void;
  statusPending?: boolean;
  /** Sales-outcome actions. */
  onWon: () => void;
  onLost: () => void;
  outcomePending?: boolean;
  /**
   * The lead's Deal, or null when it has none. `wonDeal !== null` IS "is won" — Won is nothing but
   * the Deal record. Supplies the realized amount and conversion date shown under the chips.
   */
  wonDeal: LeadDeal | null;
  /**
   * Whether a `lead.lost` Activity exists. Deliberately INDEPENDENT of `wonDeal` rather than a
   * single resolved outcome: a lead can hold both, and collapsing them (as the header badge does,
   * Won first) is what hides an existing Lost state on the very card that offers the Lose button.
   */
  isLost: boolean;
};

export function QuickActionsCard({
  actions,
  onSelect,
  status,
  onStatusChange,
  statusPending,
  onWon,
  onLost,
  outcomePending,
  wonDeal,
  isLost,
}: Props) {
  return (
    // `flex flex-col` + the `mt-auto` on the outcome row below: this card shares a grid row with
    // the Lead Overview from xl up, so on a lead whose overview carries a Digital Presence section
    // it is stretched past its own content. Laying it out as a column sends that spare height to
    // the gap ABOVE the Won/Lose pair, which then sits on the card's bottom edge like a footer,
    // instead of leaving it stranded underneath them.
    <div className="flex flex-col rounded-2xl border border-border bg-card p-6 shadow-card">
      <h3 className="mb-5 text-lg font-bold text-card-foreground">Quick Actions</h3>

      {/* Lead Status */}
      <div className="mb-4">
        <p className="mb-1.5 text-[11px] font-semibold uppercase tracking-[0.08em] text-muted-foreground">
          Lead Status
        </p>
        <Select value={status} onValueChange={(v) => v && onStatusChange(v)} disabled={statusPending}>
          <SelectTrigger className="w-full" aria-label="Lead status">
            <StatusChip status={status} />
          </SelectTrigger>
          <SelectContent>
            {EDITABLE_STATUS_OPTIONS.map((s) => (
              <SelectItem key={s.value} value={s.value}>{s.label}</SelectItem>
            ))}
          </SelectContent>
        </Select>
      </div>

      {/* Row actions: Draft & send email, Schedule follow-up */}
      <div className="flex flex-col gap-2">
        {actions.map((action) => {
          const Icon = iconMap[action.icon] ?? Mail;
          return (
            <button
              key={action.id}
              type="button"
              onClick={() => onSelect(action.key)}
              className={cn(
                "flex w-full items-center gap-3 rounded-lg border border-border-subtle px-3 py-2.5 text-left transition-colors",
                "hover:bg-muted",
                "focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-ring",
              )}
            >
              <span
                className={cn(
                  "flex h-9 w-9 shrink-0 items-center justify-center rounded-lg",
                  iconToneStyles[action.iconTone] ?? "bg-muted text-secondary-foreground",
                )}
              >
                <Icon className="h-4 w-4" />
              </span>
              <div className="flex-1 min-w-0">
                <p className="text-sm font-medium text-foreground">{action.title}</p>
                <p className="text-xs text-muted-foreground">{action.subtitle}</p>
              </div>
              <ChevronRight className="h-4 w-4 shrink-0 text-muted-foreground" aria-hidden />
            </button>
          );
        })}
      </div>

      {/* Recorded outcome. Rendered ONLY once there is one, so a lead with no outcome keeps the
          approved card unchanged. Sibling label styling to Lead Status above is the point: the two
          are separate concepts, and Won/Lost never write the status — a lead can read "New" here
          and still be both Won and Lost. Both chips show when both apply; no ordering is implied
          (the payload carries the Deal's date but no timestamp for the loss). */}
      {wonDeal || isLost ? (
        <div className="mt-4">
          <p className="mb-1.5 text-[11px] font-semibold uppercase tracking-[0.08em] text-muted-foreground">
            Outcome
          </p>
          <div className="flex flex-wrap items-center gap-1.5">
            {wonDeal ? <LeadOutcomeChip outcome="won" /> : null}
            {isLost ? <LeadOutcomeChip outcome="lost" /> : null}
          </div>
          {wonDeal ? (
            <p className="mt-1.5 text-xs text-muted-foreground">
              {/* Realized revenue, formatted like the sibling Amount field on the Summary card. */}
              Deal ₱{wonDeal.amount.toLocaleString("en-PH")}
              {wonDeal.convertedAt ? ` · ${formatDateLong(wonDeal.convertedAt)}` : null}
            </p>
          ) : null}
        </div>
      ) : null}

      {/* Sales outcomes — Won creates a Deal; Lose records a lost opportunity (no Deal). Both stay
          enabled on a lead that already carries the outcome: Won is guarded server-side (one Deal
          per lead) and a repeat Lost is a legitimate append-only audit entry. */}
      <div className="mt-auto grid grid-cols-2 gap-2 pt-4">
        <button
          type="button"
          onClick={onWon}
          disabled={outcomePending}
          className="inline-flex items-center justify-center gap-1.5 rounded-lg bg-primary px-3 py-2.5 text-sm font-semibold text-primary-foreground transition-colors hover:bg-primary/90 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring disabled:cursor-not-allowed disabled:opacity-60"
        >
          <Check className="h-4 w-4" aria-hidden />
          Won
        </button>
        <button
          type="button"
          onClick={onLost}
          disabled={outcomePending}
          className="inline-flex items-center justify-center gap-1.5 rounded-lg border border-destructive/40 px-3 py-2.5 text-sm font-semibold text-destructive transition-colors hover:bg-destructive/10 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring disabled:cursor-not-allowed disabled:opacity-60"
        >
          <X className="h-4 w-4" aria-hidden />
          Lose
        </button>
      </div>
    </div>
  );
}
