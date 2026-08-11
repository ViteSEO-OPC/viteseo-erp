"use client";

import { Eye, EyeOff } from "lucide-react";

/** What a hidden amount reads as. Fixed width so toggling does not reflow the card. */
export const MASKED_AMOUNT = "₱ ••••••";

/**
 * Summary card for a money figure, with its own show/hide (eye) toggle — FIN-043's
 * shoulder-surfing protection at card level rather than one page-wide switch.
 *
 * This hides the figure in the RENDER only. It is already in the fetched payload, so it is a
 * privacy affordance for people nearby, never an access control.
 *
 * Deliberately not built on `core/components/stat-card`: that one is shared with the other pods
 * and types `label` as a plain string, so the button could not be slotted in without changing a
 * shared signature. The visual result is identical — same padding, radius, and type scale.
 */
export function MoneyStatCard({
  label,
  value,
  subNote,
  maskValue = true,
  hidden,
  onToggle,
}: {
  label: string;
  value: string;
  /** Optional second line. Always hidden with the card, since it usually carries an amount. */
  subNote?: string;
  /**
   * Whether the headline figure is itself money. `false` for cards whose value is a count and
   * whose amount lives in `subNote` — masking a count as "₱ ••••••" would misread it as pesos.
   */
  maskValue?: boolean;
  hidden: boolean;
  onToggle: () => void;
}) {
  return (
    <div className="flex items-center justify-between gap-3 rounded-card border border-border bg-card p-5">
      <div className="min-w-0">
        <p className="text-label font-bold uppercase tracking-wide text-muted-foreground">{label}</p>
        <p className="mt-2 text-h2 font-bold text-foreground">
          {hidden && maskValue ? MASKED_AMOUNT : value}
        </p>
        {subNote ? (
          <p className="mt-1 text-caption text-muted-foreground">{hidden ? "••••" : subNote}</p>
        ) : null}
      </div>
      <button
        type="button"
        onClick={onToggle}
        aria-pressed={hidden}
        aria-label={hidden ? `Show ${label}` : `Hide ${label}`}
        title={hidden ? `Show ${label}` : `Hide ${label} — for privacy from onlookers`}
        className="shrink-0 rounded-md p-1.5 text-muted-foreground transition-colors hover:bg-muted hover:text-foreground"
      >
        {hidden ? <EyeOff className="size-5" /> : <Eye className="size-5" />}
      </button>
    </div>
  );
}
