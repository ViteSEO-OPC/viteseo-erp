import { FileText } from "lucide-react";
import { ProgressBar } from "@/modules/crm/components/shared/progress-bar/progress-bar";

type Props = {
  filename: string;
  size: string;
  percent: number;
  onCancel?: () => void;
};

export function UploadProgressItem({
  filename,
  size,
  percent,
  onCancel,
}: Props) {
  return (
    <div className="rounded-lg border border-border bg-gray-50 p-4">
      <div className="flex items-center gap-3">
        <FileText className="h-5 w-5 shrink-0 text-teal-400" />
        <div className="flex-1 min-w-0">
          <div className="flex items-baseline justify-between gap-2">
            <p className="truncate text-sm font-semibold text-foreground">
              {filename}
            </p>
            <span className="shrink-0 text-sm font-bold text-teal-400">
              {percent}%
            </span>
          </div>
          <p className="text-xs text-muted-foreground">{size}</p>
        </div>
      </div>

      <ProgressBar value={percent} tone="high" className="mt-2" />

      <div className="mt-1 flex items-center justify-between">
        <span className="text-xs text-secondary-foreground">Processing&hellip;</span>
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
