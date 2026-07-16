"use client";

import { CopilotHeader } from "@/modules/crm/features/copilot/components/copilot-header";
import { ConversationArea } from "@/modules/crm/features/copilot/components/conversation-area";
import { CopilotInputBar } from "@/modules/crm/features/copilot/components/copilot-input-bar";
import { SuggestedPromptChips } from "@/modules/crm/features/copilot/components/suggested-prompt-chips";
import type { Message, SuggestedPrompt, CopilotStatus } from "@/modules/crm/features/copilot/types/copilot-types";

type Props = {
  messages: Message[];
  prompts: SuggestedPrompt[];
  status: CopilotStatus;
  onClose: () => void;
  onSend: (text: string) => void;
  onPromptSelect: (prompt: SuggestedPrompt) => void;
};

export function CopilotPanel({
  messages,
  prompts,
  status,
  onClose,
  onSend,
  onPromptSelect,
}: Props) {
  return (
    // Fixed-height column: full viewport on mobile (inset-0), a bounded box on desktop
    // (md:h-[600px] capped to the viewport). Header / Quick Actions / Input are shrink-0; only the
    // ConversationArea (flex-1 min-h-0) grows and scrolls — the panel itself never grows.
    <div
      role="dialog"
      aria-label="Vite AI Copilot"
      className="fixed inset-0 z-[70] flex flex-col overflow-hidden bg-white md:inset-auto md:bottom-24 md:right-6 md:h-[600px] md:max-h-[calc(100vh-7rem)] md:w-[373px] md:rounded-2xl md:border md:border-gray-200 md:shadow-lg"
    >
      <CopilotHeader status={status} onMinimize={onClose} />
      <ConversationArea messages={messages} />
      {prompts.length > 0 && (
        <div className="shrink-0 border-t border-gray-150 bg-white px-4 py-3">
          <SuggestedPromptChips prompts={prompts} onSelect={onPromptSelect} />
        </div>
      )}
      <CopilotInputBar onSend={onSend} />
    </div>
  );
}
