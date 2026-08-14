import { Frown, Trophy } from "lucide-react";
import { cn } from "@/modules/crm/lib/utils";

/**
 * A resolved sales outcome. Purely presentational: it adds a glyph ABOVE the score inside the ring
 * (and, for Lost, tints the ring) while changing nothing else — the score is still computed, stored,
 * sent, and displayed exactly as before, and the arc still encodes it.
 */
export type LeadOutcome = "won" | "lost";

const OUTCOME = {
  // Won keeps the default teal arc (`arc: null`): the success tint on the trophy is enough, and the
  // ring is unchanged from the approved screenshot. Same emerald as the header's WON badge.
  won: { Icon: Trophy, text: "text-emerald-700 dark:text-emerald-400", arc: null, label: "Won" },
  // Lost turns the arc red so it is unmistakable against Won at a glance.
  lost: { Icon: Frown, text: "text-destructive", arc: "var(--color-destructive)", label: "Lost" },
} as const;

type Props = {
  value: number;
  label?: string;
  size?: number;
  /** Set once the lead is closed — adds the outcome glyph above the score. */
  outcome?: LeadOutcome | null;
};

export function LeadScoreRing({ value, label = "LEAD SCORE", size = 72, outcome }: Props) {
  const stroke = 6;
  const radius = (size - stroke) / 2;
  const circumference = 2 * Math.PI * radius;
  const offset = circumference - (value / 100) * circumference;
  const resolved = outcome ? OUTCOME[outcome] : null;

  return (
    <div
      className="inline-flex flex-col items-center"
      role="img"
      aria-label={
        resolved
          ? `${resolved.label}. Lead score ${value} of 100`
          : `Lead score ${value} of 100`
      }
    >
      {/* Relative box sized to the ring so the score centers against the SVG itself,
          not the page/outer container — stays put on scroll, resize, and re-layout. */}
      <div className="relative" style={{ width: size, height: size }}>
        <svg
          width={size}
          height={size}
          className="-rotate-90"
          aria-hidden
        >
          <circle
            cx={size / 2}
            cy={size / 2}
            r={radius}
            fill="none"
            stroke="var(--color-border-subtle)"
            strokeWidth={stroke}
          />
          <circle
            cx={size / 2}
            cy={size / 2}
            r={radius}
            fill="none"
            stroke={resolved?.arc ?? "var(--teal-400)"}
            strokeWidth={stroke}
            strokeLinecap="round"
            strokeDasharray={circumference}
            strokeDashoffset={offset}
            className="transition-all duration-500"
          />
        </svg>
        {/* Outcome glyph stacked above the score. `leading-none` on both keeps the pair optically
            centred in the ring at the existing 72px size — no container or sizing change. */}
        <span className="absolute inset-0 flex flex-col items-center justify-center gap-0.5">
          {resolved ? (
            <resolved.Icon className={cn("h-4 w-4 shrink-0", resolved.text)} aria-hidden />
          ) : null}
          <span
            className={cn(
              "font-extrabold leading-none text-card-foreground",
              // One step down when sharing the ring with a glyph, so both fit without touching
              // the ring's diameter.
              resolved ? "text-lg" : "text-xl",
            )}
          >
            {value}
          </span>
        </span>
      </div>
      <span className="mt-2 text-[10px] font-semibold uppercase tracking-[0.08em] text-muted-foreground">
        {label}
      </span>
    </div>
  );
}
