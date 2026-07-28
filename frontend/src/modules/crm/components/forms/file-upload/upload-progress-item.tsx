import { FileText, Loader2 } from "lucide-react";
import { ProgressBar } from "@/modules/crm/components/shared/progress-bar/progress-bar";

type Props = {
  filename: string;
  size: string;
  /** Real transfer progress (bytes sent). Never synthesised. */
  percent: number;
  onCancel?: () => void;
  /**
   * Caption under the bar. Defaults to the previous wording so existing callers are unchanged.
   * Callers pass the phase actually in progress rather than a generic "Processing…".
   */
  status?: string;
  /**
   * True while work is happening that reports no measurable progress (e.g. the server analysing an
   * uploaded workbook). Shows a spinner beside the status and hides the percentage, because at that
   * point the number would say 100% while work is still running — the bar itself is left alone
   * rather than animated, so nothing on screen implies progress that isn't being measured.
   */
  busy?: boolean;
};

export function UploadProgressItem({
  filename,
  size,
  percent,
  onCancel,
  status = "Processing…",
  busy = false,
}: Props) {
  return (
    <div className="rounded-lg border border-border bg-muted p-4">
      <div className="flex items-center gap-3">
        <FileText className="h-5 w-5 shrink-0 text-teal-400" />
        <div className="flex-1 min-w-0">
          <div className="flex items-baseline justify-between gap-2">
            <p className="truncate text-sm font-semibold text-foreground">
              {filename}
            </p>
            {busy ? null : (
              <span className="shrink-0 text-sm font-bold text-teal-400">
                {percent}%
              </span>
            )}
          </div>
          <p className="text-xs text-muted-foreground">{size}</p>
        </div>
      </div>

      <ProgressBar value={percent} tone="high" className="mt-2" />

      <div className="mt-1 flex items-center justify-between">
        <span className="inline-flex items-center gap-1.5 text-xs text-secondary-foreground">
          {busy ? <Loader2 className="h-3 w-3 animate-spin" aria-hidden /> : null}
          {status}
        </span>
        {onCancel && (
          <button
            type="button"
            onClick={onCancel}
            className="text-xs font-medium text-red-500 underline transition-colors hover:text-red-700 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
          >
            Cancel
          </button>
        )}
      </div>
    </div>
  );
}
