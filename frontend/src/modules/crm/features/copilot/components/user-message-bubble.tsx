"use client";

import { AvatarInitials } from "@/modules/crm/components/shared/avatar-initials/avatar-initials";
import { MessageRichText } from "@/modules/crm/features/copilot/components/message-rich-text";
import { formatActivityTimestamp } from "@/modules/crm/lib/formatters";
import { useAuth } from "@/modules/crm/providers/auth-provider";
import type { Message } from "@/modules/crm/features/copilot/types/copilot-types";

type Props = {
  message: Message;
};

export function UserMessageBubble({ message }: Props) {
  // Identity comes from the centralized auth user — same source (and same full-name → initials
  // handling) as the topbar UserMenu avatar, so the Copilot never shows a demo name.
  const { user } = useAuth();

  return (
    <div className="flex flex-row-reverse items-start gap-3">
      <AvatarInitials initials={user?.name ?? ""} size="sm" />
      <div className="max-w-[75%] rounded-xl border border-gray-200 bg-white p-3 shadow-sm">
        <MessageRichText segments={message.segments} />
        <p className="mt-1 text-xs text-gray-500">
          {formatActivityTimestamp(message.timestamp)}
        </p>
      </div>
    </div>
  );
}
