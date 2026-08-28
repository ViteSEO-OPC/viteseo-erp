"use client";

import { useState, useCallback, useEffect } from "react";
import { Sparkles, X } from "lucide-react";
import { cn } from "@/modules/crm/lib/utils";
import { CopilotPanel } from "@/modules/crm/features/copilot/components/copilot-panel";
import { useCopilot } from "@/modules/crm/features/copilot/hooks/use-copilot";

export function CopilotWidget() {
  const [isOpen, setIsOpen] = useState(false);
  const { messages, prompts, status, send, runQuickAction } = useCopilot();

  useEffect(() => {
    if (!isOpen) return;
    const handleKeyDown = (e: KeyboardEvent) => {
      if (e.key === "Escape") setIsOpen(false);
    };
    document.addEventListener("keydown", handleKeyDown);
    return () => document.removeEventListener("keydown", handleKeyDown);
  }, [isOpen]);

  const toggle = useCallback(() => {
    setIsOpen((prev) => !prev);
  }, []);

  const close = useCallback(() => {
    setIsOpen(false);
  }, []);

  return (
    <>
      {isOpen && (
        <CopilotPanel
          messages={messages}
          prompts={prompts}
          status={status}
          onClose={close}
          onSend={send}
          onPromptSelect={runQuickAction}
        />
      )}

      {/*
        Position, size, handler and ARIA are unchanged. What changed is that the FAB was barely
        there in dark mode, for two separate reasons:

        1. `shadow-fab` is a teal-900 DROP shadow (`rgba(12,98,96,.30)`) — tuned for a pale page. On
           #141414 a dark shadow is invisible, so the disc read as flat. The tokens below keep that
           exact shadow in light and swap to a teal-400 GLOW in dark. They are Copilot-specific
           rather than an override of `--shadow-fab`, which the Dashboard FAB also uses.
        2. The icon was `text-card-foreground`, which is near-black in light but flips to #dbdfe4 in
           dark — near-white on a #4fdbd7 disc, about 1.3:1. `text-primary-foreground` (#19414b) is
           the token that actually means "content on primary" and is the same in both themes.

        Hover/active/focus speak the same glow language: the shadow widens on hover, the disc presses
        in on active, and the focus ring gets `ring-offset-background` (the switch's convention) so
        the 2px offset draws in the page colour instead of the default white.
      */}
      <button
        type="button"
        onClick={toggle}
        className={cn(
          "fixed bottom-6 right-6 z-50 flex h-12 w-12 items-center justify-center rounded-full",
          "shadow-[var(--copilot-fab-shadow)] hover:shadow-[var(--copilot-fab-shadow-hover)]",
          "transition-[background-color,box-shadow,transform] duration-200 active:scale-95",
          "focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-background",
          isOpen
            ? "bg-[var(--copilot-fab-open-bg)] hover:bg-[var(--copilot-fab-open-hover)]"
            : "bg-primary hover:bg-teal-500",
        )}
        aria-label={isOpen ? "Close Vite AI Copilot" : "Open Vite AI Copilot"}
        aria-expanded={isOpen}
        aria-haspopup="dialog"
      >
        {isOpen ? (
          // teal-700 on the #2F3E3E open-state disc was ~1.5:1; teal-400 is the same brand hue and
          // reads cleanly on it in both themes.
          <X className="h-5 w-5 text-teal-400" />
        ) : (
          // `Sparkles` is lucide's ✨: the four-point star plus a small cross and dot. Left as an
          // OUTLINE on purpose — filling it collapses the star's concave notches and the glyph
          // reads as a plus/add button rather than a sparkle. Rendered at 24px rather than 20px so
          // it holds the 48px disc at the usual half-the-button ratio; at 20px the secondary marks
          // shrink toward noise and the disc looks empty. Compared side by side before choosing.
          <Sparkles className="h-6 w-6 text-primary-foreground" />
        )}
      </button>
    </>
  );
}
