"use client";

import { cn } from "@/modules/crm/lib/utils";

type ErrorStateProps = {
  message?: string;
  onRetry?: () => void;
  className?: string;
  /**
   * `error` (default) for a failure the user should worry about; `info` for an
   * expected outcome that simply ends the view — a lead reassigned out from
   * under the viewer, say. Same panel, neutral ink, so a normal state change
   * does not read as something broken.
   */
  tone?: "error" | "info";
};

export function ErrorState({
  message = "Failed to load",
  onRetry,
  className,
  tone = "error",
}: ErrorStateProps) {
  return (
    <div
      className={cn(
        "flex flex-col items-center justify-center gap-3 rounded-lg border border-dashed border-border py-12",
        className,
      )}
    >
      <p className={cn("text-sm", tone === "info" ? "text-muted-foreground" : "text-red-500")}>
        {message}
      </p>
      {onRetry && (
        <button
          type="button"
          onClick={onRetry}
          className="text-sm font-medium text-primary-accent hover:underline focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
        >
          Try again
        </button>
      )}
    </div>
  );
}
