"use client";

import { cn } from "@/lib/utils";

/**
 * Explanatory prose that stays out of the way until you look at it.
 *
 * The contribution dialogs carry a lot of "how this works" text, and all of it at once buried the
 * controls it was describing — the reader had to find three inputs inside four paragraphs. The
 * words are still here, one hover (or one tab) away from whichever `group/explain` ancestor they
 * belong to, and they slide open rather than snapping so the fields below don't jump under the
 * cursor.
 *
 * Two rules for anything put in here. It must be an EXPLANATION — a warning nobody hovers is a
 * warning nobody reads, so the uncapped-rate box, the PhilHealth basis note and the repricing
 * confirmation all stay on screen. And it must be text, not a control: the collapsed state clips
 * to zero height, and a focusable element inside would be tabbable while invisible.
 *
 * Watch the stack it sits in. A collapsed Explainer is zero-height but still takes a `space-y-*`
 * margin from its parent, which leaves a stray gap; give the preceding element its own margin
 * instead, or place this first or last in the stack.
 *
 * `grid-rows-[0fr]` → `[1fr]` is what animates the height without hard-coding one; the inner
 * `min-h-0 overflow-hidden` is what makes the clip work. Same pattern as the CRM filter bar.
 */
export function Explainer({ children }: { children: React.ReactNode }) {
  return (
    <div
      className={cn(
        "grid grid-rows-[0fr] opacity-0 transition-all duration-300 ease-in-out",
        "group-hover/explain:grid-rows-[1fr] group-hover/explain:opacity-100",
        // Hover is not the only way in. Tabbing into the section reveals the same text, so the
        // explanation is reachable from the keyboard and on a touch screen that has no hover.
        "group-focus-within/explain:grid-rows-[1fr] group-focus-within/explain:opacity-100",
        "motion-reduce:transition-none",
      )}
    >
      {/*
       * Clipped, not removed — the text stays in the accessibility tree, which is what lets
       * DialogDescription live in here without breaking the dialog's aria-describedby.
       */}
      <div className="min-h-0 overflow-hidden">
        <div className="pt-2">{children}</div>
      </div>
    </div>
  );
}
