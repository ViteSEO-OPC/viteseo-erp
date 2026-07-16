"use client";

import { cn } from "@/modules/crm/lib/utils";

type ErrorStateProps = {
  message?: string;
  onRetry?: () => void;
  className?: string;
};

export function ErrorState({
  message = "Failed to load",
  onRetry,
  className,
}: ErrorStateProps) {
  return (
    <div
      className={cn(
        "flex flex-col items-center justify-center gap-3 rounded-lg border border-dashed border-border py-12",
        className,
      )}
    >
      <p className="text-sm text-red-500">{message}</p>
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
