"use client";

import { useEffect, useRef } from "react";
import { AiMessageBubble } from "@/modules/crm/features/copilot/components/ai-message-bubble";
import { UserMessageBubble } from "@/modules/crm/features/copilot/components/user-message-bubble";
import type { Message } from "@/modules/crm/features/copilot/types/copilot-types";

type Props = {
  messages: Message[];
};

export function ConversationArea({ messages }: Props) {
  const bottomRef = useRef<HTMLDivElement>(null);

  // Keep the newest turn in view as the engine appends replies.
  useEffect(() => {
    bottomRef.current?.scrollIntoView({ behavior: "smooth", block: "end" });
  }, [messages.length]);

  // min-h-0 lets this flex child shrink below its content height so overflow-y-auto actually
  // scrolls (instead of expanding the panel). This is the only scroll region in the panel.
  return (
    <div className="min-h-0 flex-1 overflow-y-auto px-4 py-4 [&::-webkit-scrollbar-thumb]:rounded-full [&::-webkit-scrollbar-thumb]:bg-teal-400 [&::-webkit-scrollbar-track]:bg-transparent [&::-webkit-scrollbar]:w-1.5">
      <div className="flex flex-col gap-4">
        {messages.map((msg) =>
          msg.author === "ai" ? (
            <AiMessageBubble key={msg.id} message={msg} />
          ) : (
            <UserMessageBubble key={msg.id} message={msg} />
          ),
        )}
      </div>
      <div ref={bottomRef} aria-hidden />
    </div>
  );
}
