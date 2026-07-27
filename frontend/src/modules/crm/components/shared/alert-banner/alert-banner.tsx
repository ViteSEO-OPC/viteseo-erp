"use client";

import { useState } from "react";
import { AlertTriangle, ChevronDown, Zap } from "lucide-react";
import { cn } from "@/modules/crm/lib/utils";

type Props = {
  message: string;
  variant?: "warning" | "info";
  children?: React.ReactNode;
};

export function AlertBanner({ message, variant = "warning", children }: Props) {
  const [expanded, setExpanded] = useState(false);

  const isInfo = variant === "info";

  const icon = isInfo ? (
    <Zap className="h-4 w-4 shrink-0 text-teal-700 dark:text-teal-400" />
  ) : (
    <AlertTriangle className="h-4 w-4 shrink-0 text-yellow-800 dark:text-chart-3" />
  );

  const messageEl = (
    <span
      className={cn(
        "flex-1 text-sm font-medium",
        isInfo ? "text-teal-900 dark:text-teal-400" : "text-yellow-800 dark:text-chart-3",
      )}
    >
      {message}
    </span>
  );

  return (
    <div
      className={cn(
        "rounded-lg border",
        isInfo
          ? "border-teal-400/30 bg-[var(--color-ai-banner-bg)]"
          : "border-yellow-800/20 bg-[var(--color-warning-bg)]",
      )}
    >
      {children ? (
        <>
          <button
            type="button"
            onClick={() => setExpanded((p) => !p)}
            className="flex w-full items-center gap-2 px-4 py-3 text-left focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-ring"
            aria-expanded={expanded}
          >
            {icon}
            {messageEl}
            <ChevronDown
              className={cn(
                "h-4 w-4 shrink-0 transition-transform",
                isInfo ? "text-teal-900 dark:text-teal-400" : "text-yellow-800 dark:text-chart-3",
                expanded && "rotate-180",
              )}
            />
          </button>
          {expanded && (
            <div
              className={cn(
                "border-t px-4 py-3",
                isInfo ? "border-teal-400/20" : "border-yellow-800/10",
              )}
            >
              {children}
            </div>
          )}
        </>
      ) : (
        <div className="flex w-full items-center gap-2 px-4 py-3">
          {icon}
          {messageEl}
        </div>
      )}
    </div>
  );
}
