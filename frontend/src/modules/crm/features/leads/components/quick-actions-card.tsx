import { Mail, Phone, Calendar, CheckCircle, ChevronRight, Check } from "lucide-react";
import { Button } from "@/modules/crm/components/ui/button";
import { cn } from "@/modules/crm/lib/utils";
import type { QuickAction, QuickActionKey } from "@/modules/crm/features/leads/types/lead-detail-types";

const iconMap: Record<string, typeof Mail> = {
  Mail, Phone, Calendar, CheckCircle,
};

const iconToneStyles: Record<string, string> = {
  teal: "bg-[var(--qa-tile-teal)] text-teal-700",
  indigo: "bg-[var(--qa-tile-indigo)] text-[var(--qa-icon-indigo)]",
  amber: "bg-[var(--qa-tile-amber)] text-[var(--qa-icon-orange)]",
  green: "bg-[var(--qa-tile-green)] text-green-700",
};

type Props = {
  actions: QuickAction[];
  /** Invoked with the action's stable key. Only wired keys act; others are inert (deferred). */
  onSelect?: (key: QuickActionKey) => void;
};

export function QuickActionsCard({ actions, onSelect }: Props) {
  // The final action ("Mark as contacted") is promoted to a prominent CTA button to match the
  // design; the rest render as rows. No actions are dropped — all existing actions are kept.
  const primary = actions.find((a) => a.icon === "CheckCircle") ?? actions.at(-1) ?? null;
  const rowActions = actions.filter((a) => a !== primary);

  return (
    <div className="rounded-xl border border-border bg-card p-5 shadow-card">
      <h3 className="mb-4 text-lg font-bold text-card-foreground">Quick Actions</h3>

      <div className="flex flex-col gap-2">
        {rowActions.map((action) => {
          const Icon = iconMap[action.icon] ?? Mail;
          return (
            <button
              key={action.id}
              type="button"
              onClick={() => onSelect?.(action.key)}
              className={cn(
                "flex w-full items-center gap-3 rounded-lg border border-border-subtle px-3 py-2.5 text-left transition-colors",
                "hover:bg-muted",
                "focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-ring",
              )}
            >
              <span
                className={cn(
                  "flex h-9 w-9 shrink-0 items-center justify-center rounded-lg",
                  iconToneStyles[action.iconTone] ?? "bg-gray-100 text-gray-600",
                )}
              >
                <Icon className="h-4 w-4" />
              </span>
              <div className="flex-1 min-w-0">
                <p className="text-sm font-medium text-foreground">{action.title}</p>
                <p className="text-xs text-muted-foreground">{action.subtitle}</p>
              </div>
              <ChevronRight className="h-4 w-4 shrink-0 text-gray-400" aria-hidden />
            </button>
          );
        })}
      </div>

      {primary ? (
        <Button
          variant="default"
          size="lg"
          className="mt-4 w-full font-semibold"
          onClick={() => onSelect?.(primary.key)}
        >
          <Check className="h-4 w-4" />
          {primary.title}
        </Button>
      ) : null}
    </div>
  );
}
