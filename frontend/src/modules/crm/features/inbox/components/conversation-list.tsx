"use client";

import { Link2, Link2Off } from "lucide-react";

import { ChannelBadge } from "@/modules/crm/features/inbox/components/channel-badge";
import type { InboxConversation } from "@/modules/crm/features/inbox/types/inbox-types";

function when(iso: string | null): string {
    if (!iso) return "";
    const date = new Date(iso);
    if (Number.isNaN(date.getTime())) return "";

    const sameDay = new Date().toDateString() === date.toDateString();
    return sameDay
        ? date.toLocaleTimeString(undefined, { hour: "numeric", minute: "2-digit" })
        : date.toLocaleDateString(undefined, { month: "short", day: "numeric" });
}

/**
 * The left-hand list.
 *
 * Each row shows the channel, a one-line preview, the time of the last message and whether the
 * thread is attached to a lead. The lead marker matters more than it looks: inbound never creates
 * a lead by itself, so "unlinked" is the normal state of a new enquiry and is precisely the triage
 * signal someone working the inbox needs.
 */
export function ConversationList({
    conversations,
    selectedId,
    onSelect,
}: {
    conversations: InboxConversation[];
    selectedId: string | null;
    onSelect: (id: string) => void;
}) {
    return (
        <ul className="divide-y divide-border" role="listbox" aria-label="Conversations">
            {conversations.map((conversation) => {
                const selected = conversation.id === selectedId;

                return (
                    <li key={conversation.id}>
                        <button
                            type="button"
                            role="option"
                            aria-selected={selected}
                            onClick={() => onSelect(conversation.id)}
                            className={`w-full px-4 py-3 text-left transition-colors hover:bg-muted/60 ${
                                selected ? "bg-muted" : ""
                            }`}
                        >
                            <div className="flex items-center justify-between gap-2">
                                <ChannelBadge channel={conversation.channel} />
                                <span className="shrink-0 text-xs text-muted-foreground">
                                    {when(conversation.lastMessageAt)}
                                </span>
                            </div>

                            <p className="mt-1.5 line-clamp-2 text-sm text-card-foreground">
                                {conversation.latestMessage?.preview ?? (
                                    <span className="italic text-muted-foreground">No text in the latest message</span>
                                )}
                            </p>

                            <div className="mt-1.5 flex items-center gap-1.5 text-xs text-muted-foreground">
                                {conversation.leadLinked ? (
                                    <>
                                        <Link2 aria-hidden className="size-3.5" />
                                        {conversation.lead?.businessName ?? "Linked to a lead"}
                                    </>
                                ) : (
                                    <>
                                        <Link2Off aria-hidden className="size-3.5" />
                                        Not linked to a lead
                                    </>
                                )}
                            </div>
                        </button>
                    </li>
                );
            })}
        </ul>
    );
}
