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

      <button
        type="button"
        onClick={toggle}
        className={cn(
          "fixed bottom-6 right-6 z-50 flex h-12 w-12 items-center justify-center rounded-full transition-colors shadow-fab",
          "focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2",
          isOpen
            ? "bg-[var(--copilot-fab-open-bg)] hover:bg-[var(--copilot-fab-open-hover)]"
            : "bg-primary hover:bg-teal-500",
        )}
        aria-label={isOpen ? "Close Vite AI Copilot" : "Open Vite AI Copilot"}
        aria-expanded={isOpen}
        aria-haspopup="dialog"
      >
        {isOpen ? (
          <X className="h-5 w-5 text-teal-700" />
        ) : (
          <Sparkles className="h-5 w-5 text-card-foreground" />
        )}
      </button>
    </>
  );
}
