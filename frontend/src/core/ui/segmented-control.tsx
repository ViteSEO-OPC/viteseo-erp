"use client";

import * as React from "react";
import { cn } from "@/core/lib/utils";

/**
 * A pill-style toggle group — the centralized control for the view switcher
 * (List / Kanban / Calendar) and scope filters (All / Projects) across the
 * Projects module. Matches the Figma "Component Sets" spec: a white, bordered,
 * rounded container holding segments whose active state is a teal tint.
 *
 * Compositional so a segment can carry a trailing chevron (dropdown trigger)
 * or an icon while sharing one source of truth for the styling.
 */

const ACTIVE_SEGMENT =
  "bg-[#0f766e]/10 text-[#0f766e] font-bold dark:bg-brand/15 dark:text-brand";
const INACTIVE_SEGMENT = "text-ink-soft hover:text-ink";

function SegmentedControl({
  className,
  ...props
}: React.ComponentProps<"div">) {
  return (
    <div
      role="tablist"
      data-slot="segmented-control"
      className={cn(
        "inline-flex items-center gap-1 rounded-xl border border-line bg-surface p-1",
        className,
      )}
      {...props}
    />
  );
}

function SegmentedItem({
  active = false,
  className,
  type = "button",
  ...props
}: React.ComponentProps<"button"> & { active?: boolean }) {
  return (
    <button
      type={type}
      role="tab"
      aria-selected={active}
      data-active={active}
      data-slot="segmented-item"
      className={cn(
        "inline-flex items-center justify-center gap-1.5 rounded-lg px-4 py-1.5 text-sm font-semibold whitespace-nowrap transition-colors outline-none focus-visible:ring-2 focus-visible:ring-ring/50",
        active ? ACTIVE_SEGMENT : INACTIVE_SEGMENT,
        className,
      )}
      {...props}
    />
  );
}

export { SegmentedControl, SegmentedItem };
