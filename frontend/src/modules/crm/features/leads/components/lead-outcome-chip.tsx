import { BadgeCheck, Frown } from "lucide-react";
import { cn } from "@/modules/crm/lib/utils";
import type { LeadOutcome } from "@/modules/crm/features/leads/components/lead-score-ring";

/**
 * Won / Lost chip — the shared glyph + tint pairing for a lead's sales outcome.
 *
 * Deliberately takes a single concrete outcome rather than a lead: the two flags are INDEPENDENT
 * (a lead can hold both a Deal and a `lead.lost` Activity), so any surface showing both renders two
 * of these. Collapsing them into one value is a presentation choice each caller makes for itself —
 * the header keeps Won-precedence for its single badge slot; the Quick Actions card, where both
 * actions live, shows both.
 *
 * `lead-detail-header.tsx` still carries its own equivalent badge and is intentionally left frozen;
 * folding it onto this component is a separate, purely mechanical change.
 */
const OUTCOME_CHIP: Record<LeadOutcome, { label: string; Icon: typeof BadgeCheck; tone: string; title: string }> = {
  won: {
    label: "Won",
    Icon: BadgeCheck,
    tone: "bg-emerald-100 text-emerald-700 dark:bg-emerald-400/10 dark:text-emerald-400",
    title: "This lead has been converted into a deal",
  },
  lost: {
    label: "Lost",
    Icon: Frown,
    tone: "bg-destructive/10 text-destructive",
    title: "This lead has been marked as a lost opportunity",
  },
};

export function LeadOutcomeChip({ outcome, className }: { outcome: LeadOutcome; className?: string }) {
  const { label, Icon, tone, title } = OUTCOME_CHIP[outcome];
  return (
    <span
      title={title}
      className={cn(
        "inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-xs font-semibold",
        tone,
        className,
      )}
    >
      <Icon className="h-3.5 w-3.5" aria-hidden />
      {label}
    </span>
  );
}
