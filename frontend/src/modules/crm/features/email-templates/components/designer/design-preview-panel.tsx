"use client";

import { AlertTriangle, ImageOff, Loader2, Monitor, RotateCw, Smartphone } from "lucide-react";
import { Button } from "@/modules/crm/components/ui/button";
import { Skeleton } from "@/modules/crm/components/ui/skeleton";
import { EmailHtmlFrame } from "@/modules/crm/components/shared/email-body/email-html-frame";
import { cn } from "@/modules/crm/lib/utils";
import { formatKilobytes, problemCount } from "@/modules/crm/features/email-templates/lib/email-design";
import type { DesignProblems, EmailDesignPreview } from "@/modules/crm/features/email-templates/types/email-design-types";

export type PreviewDevice = "desktop" | "mobile";

const DEVICE_OPTIONS = [
  { value: "desktop", label: "Desktop", icon: Monitor },
  { value: "mobile", label: "Mobile", icon: Smartphone },
] as const;

type Props = {
  preview: EmailDesignPreview | null;
  isUpdating: boolean;
  requestFailed: boolean;
  problems: DesignProblems;
  /** "Button (block 3)" for a block id. */
  blockLabel: (id: string) => string;
  /** "Page style · Font" for a document field such as `styles.font`. */
  fieldLabel: (field: string) => string;
  device: PreviewDevice;
  onDeviceChange: (device: PreviewDevice) => void;
  onRetry: () => void;
  /** What the preview shows, under the frame. */
  note: string;
};

/**
 * The server's rendering of an email, at desktop (600px email) or phone width.
 *
 * The HTML comes only from the server's preview endpoints — the same EmailDesignRenderer that will send the
 * email — and is shown in EmailHtmlFrame, the sandboxed, script-free frame Lead Details uses for received
 * mail. The designers never build HTML of their own. While the document has problems, the last good
 * rendering stays on screen, dimmed, with the problems listed above it.
 */
export function DesignPreviewPanel({
  preview,
  isUpdating,
  requestFailed,
  problems,
  blockLabel,
  fieldLabel,
  device,
  onDeviceChange,
  onRetry,
  note,
}: Props) {
  const count = problemCount(problems);
  const items = [
    ...problems.general.map((message, i) => ({ key: `general-${i}`, text: message })),
    ...Object.entries(problems.fields).map(([field, message]) => ({ key: `field-${field}`, text: `${fieldLabel(field)}: ${message}` })),
    ...Object.entries(problems.blocks).flatMap(([id, list]) =>
      list.map((problem, i) => ({ key: `block-${id}-${i}`, text: `${blockLabel(id)}: ${problem.message}` })),
    ),
  ];
  const usage = preview ? Math.min(100, Math.round((preview.htmlBytes / preview.maxHtmlBytes) * 100)) : 0;
  const stale = isUpdating || count > 0;

  return (
    <div className="flex flex-col gap-4">
      <div className="flex flex-wrap items-center justify-between gap-2">
        <div role="group" aria-label="Preview width" className="inline-flex items-center gap-1 rounded-lg border border-border bg-card p-1">
          {DEVICE_OPTIONS.map(({ value, label, icon: Icon }) => (
            <button
              key={value}
              type="button"
              aria-pressed={device === value}
              onClick={() => onDeviceChange(value)}
              className={cn(
                "inline-flex items-center gap-1.5 rounded-md px-3 py-1 text-sm font-medium transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring",
                device === value ? "bg-muted text-foreground shadow-sm" : "text-muted-foreground hover:text-foreground",
              )}
            >
              <Icon className="size-4" aria-hidden />
              {label}
            </button>
          ))}
        </div>
        <span className="inline-flex items-center gap-1.5 text-xs text-muted-foreground" aria-live="polite">
          {isUpdating ? (
            <>
              <Loader2 className="size-3.5 animate-spin" aria-hidden />
              Updating preview…
            </>
          ) : count > 0 ? (
            "Preview paused until the problems are fixed"
          ) : preview ? (
            "Preview up to date"
          ) : null}
        </span>
      </div>

      {count > 0 ? (
        <div role="alert" className="rounded-lg border border-destructive/40 bg-destructive/5 px-3 py-2">
          <p className="flex items-center gap-1.5 text-sm font-medium text-destructive">
            <AlertTriangle className="size-4 shrink-0" aria-hidden />
            {count === 1 ? "Fix 1 problem to save" : `Fix ${count} problems to save`}
          </p>
          <ul className="mt-1 list-disc space-y-0.5 pl-6 text-xs text-foreground">
            {items.map((item) => (
              <li key={item.key}>{item.text}</li>
            ))}
          </ul>
        </div>
      ) : null}

      {requestFailed ? (
        <div className="flex flex-wrap items-center justify-between gap-2 rounded-lg border border-border bg-card px-3 py-2 text-sm text-muted-foreground">
          The preview could not be updated.
          <Button type="button" size="xs" variant="outline" onClick={onRetry}>
            <RotateCw aria-hidden />
            Try again
          </Button>
        </div>
      ) : null}

      {preview ? (
        <>
          <div className="rounded-lg border border-border bg-card px-4 py-3">
            <p className="text-[11px] font-semibold uppercase tracking-[0.08em] text-muted-foreground">Subject</p>
            <p className="mt-0.5 break-words text-sm font-semibold text-foreground">{preview.subject.trim() || "(no subject)"}</p>
          </div>

          <div className={cn("mx-auto w-full transition-opacity", device === "mobile" ? "max-w-[375px]" : "max-w-[640px]", stale && "opacity-60")}>
            <EmailHtmlFrame html={preview.html} />
          </div>

          <div className="flex flex-col gap-1">
            <div className="h-1.5 w-full overflow-hidden rounded-full bg-muted" aria-hidden>
              <div className={cn("h-full rounded-full", usage >= 90 ? "bg-destructive" : "bg-teal-600")} style={{ width: `${usage}%` }} />
            </div>
            <p className="text-xs text-muted-foreground">
              Email size {formatKilobytes(preview.htmlBytes)} of {formatKilobytes(preview.maxHtmlBytes)}. Gmail cuts off emails over
              about 102 KB.
            </p>
          </div>
        </>
      ) : count === 0 && !requestFailed ? (
        <Skeleton className="h-96 w-full" />
      ) : null}

      <p className="flex items-start gap-1.5 text-xs text-muted-foreground">
        <ImageOff className="mt-0.5 size-3.5 shrink-0" aria-hidden />
        {note}
      </p>
    </div>
  );
}
