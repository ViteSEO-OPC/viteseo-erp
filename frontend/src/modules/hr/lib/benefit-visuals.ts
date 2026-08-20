import {
  Clock,
  Gift,
  HeartPulse,
  Leaf,
  ShieldCheck,
  TrendingUp,
  type LucideIcon,
} from "lucide-react";

/** Icon + tinted swatch classes per benefit category (drives the card visual). */
export const BENEFIT_VISUALS: Record<string, { Icon: LucideIcon; swatch: string }> = {
  hmo: { Icon: HeartPulse, swatch: "bg-danger-subtle text-danger" },
  retirement: { Icon: TrendingUp, swatch: "bg-brand-subtle text-brand" },
  allowance: { Icon: Leaf, swatch: "bg-success-subtle text-success" },
  insurance: { Icon: ShieldCheck, swatch: "bg-warning-subtle text-warning" },
  enrollment: { Icon: Clock, swatch: "bg-brand-subtle text-brand" },
  general: { Icon: Gift, swatch: "bg-muted text-muted-foreground" },
};

export function benefitVisual(category: string) {
  return BENEFIT_VISUALS[category] ?? BENEFIT_VISUALS.general;
}

/** A live benefit reads as an active (green) badge; anything else is neutral. */
export function isEnrolledStatus(status: string): boolean {
  return ["enrolled", "active"].includes(status.trim().toLowerCase());
}
