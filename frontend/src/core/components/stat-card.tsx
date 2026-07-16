import { cn } from "@/lib/utils";

/**
 * Dashboard/summary stat card: uppercase gray label, big bold value, optional
 * sub-note. `highlight` = the light-teal + teal-border variant from the design's
 * first dashboard card.
 */
export function StatCard({
  label,
  value,
  subNote,
  highlight = false,
  className,
}: {
  label: string;
  value: React.ReactNode;
  subNote?: React.ReactNode;
  highlight?: boolean;
  className?: string;
}) {
  return (
    <div
      className={cn(
        "rounded-card border p-5",
        highlight
          ? "border-brand bg-brand-subtle"
          : "border-border bg-card",
        className,
      )}
    >
      <p className="text-label font-bold uppercase tracking-wide text-muted-foreground">
        {label}
      </p>
      <p className="mt-2 text-h2 font-bold text-foreground">{value}</p>
      {subNote ? (
        <p className="mt-1 text-caption text-muted-foreground">{subNote}</p>
      ) : null}
    </div>
  );
}
