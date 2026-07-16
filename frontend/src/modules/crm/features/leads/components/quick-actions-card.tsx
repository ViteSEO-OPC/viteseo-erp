import { Mail, Calendar, ChevronRight, Check, X } from "lucide-react";
import { cn } from "@/modules/crm/lib/utils";
import {
  Select,
  SelectTrigger,
  SelectContent,
  SelectItem,
} from "@/modules/crm/components/ui/select";
import { StatusChip } from "@/modules/crm/components/shared/status-badge/status-chip";
import type { LeadStatus } from "@/modules/crm/features/dashboard/types/dashboard-types";
import type { QuickAction, QuickActionKey } from "@/modules/crm/features/leads/types/lead-detail-types";

const iconMap: Record<string, typeof Mail> = { Mail, Calendar };

const iconToneStyles: Record<string, string> = {
  teal: "bg-[var(--qa-tile-teal)] text-teal-700",
  amber: "bg-[var(--qa-tile-amber)] text-[var(--qa-icon-orange)]",
};

// The four editable lead statuses (the frozen enum minus the create-only "new").
const STATUS_OPTIONS: { value: LeadStatus; label: string }[] = [
  { value: "interested", label: "Interested" },
  { value: "awaiting-reply", label: "Awaiting Reply" },
  { value: "call-back-req", label: "Call-back Req." },
  { value: "declined", label: "Declined" },
];

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
}: Props) {
  return (
    <div className="rounded-xl border border-border bg-card p-5 shadow-card">
      <h3 className="mb-4 text-lg font-bold text-card-foreground">Quick Actions</h3>

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
            {STATUS_OPTIONS.map((s) => (
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

      {/* Sales outcomes — Won creates a Deal; Lose records a lost opportunity (no Deal). */}
      <div className="mt-4 grid grid-cols-2 gap-2">
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
