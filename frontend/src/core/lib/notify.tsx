"use client";

import { toast as sonner } from "sonner";
import { AlertTriangle, Check, Info, X } from "lucide-react";
import type { LucideIcon } from "lucide-react";

import { cn } from "@/lib/utils";

/**
 * CRM toast card. Each variant carries its own subtle background + border + solid icon badge
 * (success = green, error = red, warning = amber, info = teal) so outcomes read distinctly.
 *
 * Contrast/theme note: the toast host is mounted in the root <body>, OUTSIDE the CRM's
 * `.zerem-theme-scope` wrapper, so it always renders against the light `:root` tokens — in both
 * light and dark app themes. The title therefore uses the dark-teal ink (>10:1 on every pale
 * variant background) rather than the vibrant per-variant token (globals.css overrides those to
 * bright values that would fail as text). Variant hue is carried by the badge/border/surface.
 *
 * Drop-in for `import { toast } from "sonner"` — import as
 * `import { notify as toast } from "@/core/lib/notify"`; the .success/.error/.warning/.info
 * (message, description?) call sites keep working unchanged, including sonner's animation.
 */
type Variant = "success" | "error" | "warning" | "info";

const VARIANTS: Record<Variant, { surface: string; badge: string; Icon: LucideIcon }> = {
  success: { surface: "border-success bg-success-subtle", badge: "bg-success", Icon: Check },
  error: { surface: "border-danger bg-danger-subtle", badge: "bg-danger", Icon: X },
  warning: { surface: "border-warning bg-warning-subtle", badge: "bg-warning", Icon: AlertTriangle },
  info: { surface: "border-brand bg-brand-subtle", badge: "bg-brand", Icon: Info },
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
  const { surface, badge, Icon } = VARIANTS[variant];
  return (
    <div
      className={cn(
        "flex w-[360px] max-w-[calc(100vw-2rem)] items-start gap-3 rounded-xl border p-4 shadow-lg",
        surface,
      )}
    >
      <span className={cn("mt-0.5 flex size-8 shrink-0 items-center justify-center rounded-md text-white", badge)}>
        <Icon className="size-5" strokeWidth={3} />
      </span>
      <div className="min-w-0 flex-1">
        <p className="text-table font-bold leading-tight text-dark-teal">{title}</p>
        {description ? (
          <p className="mt-1 text-caption text-dark-teal/70">{description}</p>
        ) : null}
      </div>
      <button
        type="button"
        onClick={() => sonner.dismiss(toastId)}
        aria-label="Dismiss"
        className="-mr-1 -mt-1 shrink-0 rounded-md p-1 text-dark-teal/60 transition-colors hover:text-dark-teal"
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
  /** Green success toast (positive outcome). */
  success: (title: React.ReactNode, description?: React.ReactNode) => fire("success", title, description),
  /** Red error toast (rejection or failure). */
  error: (title: React.ReactNode, description?: React.ReactNode) => fire("error", title, description),
  /** Amber warning toast (caution / no-op). */
  warning: (title: React.ReactNode, description?: React.ReactNode) => fire("warning", title, description),
  /** Teal info toast (neutral information). */
  info: (title: React.ReactNode, description?: React.ReactNode) => fire("info", title, description),
};
