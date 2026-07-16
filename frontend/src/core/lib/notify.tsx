"use client";

import { toast as sonner } from "sonner";
import { Check, X, Info } from "lucide-react";
import type { LucideIcon } from "lucide-react";

import { cn } from "@/lib/utils";

/**
 * Figma toast (Components → Pop-ups 163:534): a shared light-teal container with
 * a teal border; only the icon badge + title color change per variant —
 * green for "Approved", red for "Declined", teal for neutral info. This replaces
 * sonner's default all-green look so approvals and declines read differently.
 *
 * Drop-in for the old `import { toast } from "sonner"`: import as
 * `import { notify as toast } from "@/core/lib/notify"` — the .success/.error/
 * .info(message) call sites keep working, and an optional 2nd arg adds the
 * sub-line copy from the design.
 */
type Variant = "approved" | "declined" | "info";

const VARIANTS: Record<Variant, { badge: string; title: string; Icon: LucideIcon }> = {
  approved: { badge: "bg-success", title: "text-success", Icon: Check },
  declined: { badge: "bg-danger", title: "text-danger", Icon: X },
  info: { badge: "bg-brand", title: "text-dark-teal", Icon: Info },
};

function ToastCard({
  variant,
  title,
  description,
  toastId,
}: {
  variant: Variant;
  title: React.ReactNode;
  description?: React.ReactNode;
  toastId: string | number;
}) {
  const { badge, title: titleColor, Icon } = VARIANTS[variant];
  return (
    <div className="flex w-[360px] max-w-[calc(100vw-2rem)] items-start gap-3 rounded-xl border border-brand bg-brand-subtle p-4 shadow-lg">
      <span className={cn("mt-0.5 flex size-8 shrink-0 items-center justify-center rounded-md text-white", badge)}>
        <Icon className="size-5" strokeWidth={3} />
      </span>
      <div className="min-w-0 flex-1">
        <p className={cn("text-table font-bold leading-tight", titleColor)}>{title}</p>
        {description ? (
          <p className="mt-1 text-caption text-muted-foreground">{description}</p>
        ) : null}
      </div>
      <button
        type="button"
        onClick={() => sonner.dismiss(toastId)}
        aria-label="Dismiss"
        className="-mr-1 -mt-1 shrink-0 rounded-md p-1 text-muted-foreground transition-colors hover:text-foreground"
      >
        <X className="size-4" />
      </button>
    </div>
  );
}

function fire(variant: Variant, title: React.ReactNode, description?: React.ReactNode) {
  return sonner.custom(
    (id) => <ToastCard variant={variant} title={title} description={description} toastId={id} />,
  );
}

export const notify = {
  /** Green "Approved"-style toast (positive outcome). */
  success: (title: React.ReactNode, description?: React.ReactNode) => fire("approved", title, description),
  /** Red "Declined"-style toast (rejection or failure). */
  error: (title: React.ReactNode, description?: React.ReactNode) => fire("declined", title, description),
  /** Neutral teal info toast. */
  info: (title: React.ReactNode, description?: React.ReactNode) => fire("info", title, description),
};
