"use client";

import { useState } from "react";
import { SendButton } from "@/modules/crm/features/copilot/components/send-button";

type Props = {
  onSend: (text: string) => void;
};

export function CopilotInputBar({ onSend }: Props) {
  const [value, setValue] = useState("");

  const submit = () => {
    const text = value.trim();
    if (!text) return;
    onSend(text);
    setValue("");
  };

  return (
    <div className="flex shrink-0 items-center gap-2 border-t border-border-subtle bg-card px-4 py-3">
      <input
        type="text"
        value={value}
        onChange={(e) => setValue(e.target.value)}
        onKeyDown={(e) => {
          if (e.key === "Enter" && !e.nativeEvent.isComposing) {
            e.preventDefault();
            submit();
          }
        }}
        placeholder="Ask about leads, draft an email…"
        className="h-11 flex-1 rounded-lg border border-border bg-card px-3 text-sm text-foreground placeholder:text-muted-foreground focus:outline-none focus:ring-2 focus:ring-ring"
        aria-label="Chat input"
      />
      <SendButton disabled={!value.trim()} onClick={submit} />
    </div>
  );
}
