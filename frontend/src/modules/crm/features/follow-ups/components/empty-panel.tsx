"use client";

import type { LucideIcon } from "lucide-react";
import { cn } from "@/modules/crm/lib/utils";

type EmptyPanelProps = {
  icon: LucideIcon;
  message: string;
  className?: string;
};

// Centered card empty state used across the Outreach workspace (no selection, no drafts, …).
export function EmptyPanel({ icon: Icon, message, className }: EmptyPanelProps) {
  return (
    <div
      className={cn(
        "flex min-h-0 flex-1 flex-col items-center justify-center gap-3 rounded-xl border border-border bg-card text-sm text-muted-foreground shadow-card",
        className,
      )}
    >
      <Icon className="h-8 w-8 text-muted-foreground" aria-hidden />
      <span>{message}</span>
    </div>
  );
}
