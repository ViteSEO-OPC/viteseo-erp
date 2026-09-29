import { AiAvatarTile } from "@/modules/crm/features/copilot/components/ai-avatar-tile";
import { MessageRichText } from "@/modules/crm/features/copilot/components/message-rich-text";
import { formatActivityTimestamp } from "@/modules/crm/lib/formatters";
import type { Message } from "@/modules/crm/features/copilot/types/copilot-types";

type Props = {
  message: Message;
};

export function AiMessageBubble({ message }: Props) {
  return (
    <div className="flex items-start gap-3">
      <AiAvatarTile />
      <div className="max-w-[75%] rounded-xl border border-border bg-card p-3 shadow-sm">
        <MessageRichText segments={message.segments} />
        <p className="mt-1 text-xs text-muted-foreground">
          {formatActivityTimestamp(message.timestamp)}
        </p>
        {message.aiAssisted && <p className="mt-0.5 text-xs text-muted-foreground">AI-assisted</p>}
      </div>
    </div>
  );
}
