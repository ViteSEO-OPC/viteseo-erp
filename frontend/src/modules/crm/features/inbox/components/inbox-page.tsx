"use client";

import { useMemo, useState } from "react";
import { Inbox, Link2, Link2Off, Loader2, TriangleAlert } from "lucide-react";
import axios from "axios";

import { ChannelBadge } from "@/modules/crm/features/inbox/components/channel-badge";
import { ConversationList } from "@/modules/crm/features/inbox/components/conversation-list";
import { MessageThread } from "@/modules/crm/features/inbox/components/message-thread";
import { useConversation, useConversations } from "@/modules/crm/features/inbox/hooks/use-conversations";
import type { ChannelFilter } from "@/modules/crm/features/inbox/types/inbox-types";

const FILTERS: { value: ChannelFilter; label: string }[] = [
    { value: "all", label: "All" },
    { value: "messenger", label: "Messenger" },
    { value: "instagram_dm", label: "Instagram" },
];

/** Turns an API failure into something a person can act on, never a stack trace. */
function errorMessage(error: unknown): string {
    if (axios.isAxiosError(error)) {
        if (error.response?.status === 403) return "You do not have access to the Conversation Inbox.";
        if (error.response?.status === 401) return "Your session has ended. Please sign in again.";
        if (error.response?.status === 404) return "That conversation is no longer available.";
    }
    return "The inbox could not be loaded. Please try again.";
}

function Notice({ icon: Icon, title, body }: { icon: typeof Inbox; title: string; body: string }) {
    return (
        <div className="flex h-full flex-col items-center justify-center gap-2 p-10 text-center">
            <Icon aria-hidden className="size-8 text-muted-foreground" />
            <p className="text-sm font-medium text-card-foreground">{title}</p>
            <p className="max-w-sm text-sm text-muted-foreground">{body}</p>
        </div>
    );
}

/**
 * The Conversation Inbox — read-only by design.
 *
 * There is no composer, no send button and no AI action anywhere on this screen, because no
 * outbound path exists in the system behind it. A disabled reply box would imply a capability that
 * is not there and would be the first thing a reviewer tried.
 *
 * Its second job is evidentiary: for Meta App Review it must be possible to record a message
 * arriving on Messenger or Instagram and appearing here, with the channel unmistakable and the text
 * readable. That is why the channel is labelled in words on both the row and the header, and why
 * this screen reads the real conversation API rather than any demo fixture.
 */
export function InboxPage() {
    const [channel, setChannel] = useState<ChannelFilter>("all");
    const [selectedId, setSelectedId] = useState<string | null>(null);

    const list = useConversations(channel);
    const conversations = useMemo(() => list.data?.data ?? [], [list.data]);

    /*
     * The selection is DERIVED, not synchronised.
     *
     * If the channel filter changes and the chosen thread is no longer in the list, the detail pane
     * must stop showing it — but doing that by clearing state inside an effect causes a cascading
     * re-render and leaves one frame where the panes contradict each other. Computing the effective
     * selection during render has neither problem: the moment the list no longer contains the id,
     * the detail pane is empty, with no extra render and no stale frame.
     */
    const effectiveId = selectedId !== null && conversations.some((c) => c.id === selectedId) ? selectedId : null;

    const detail = useConversation(effectiveId);

    return (
        <div className="flex h-[calc(100vh-10rem)] min-h-[32rem] flex-col gap-4">
            <div className="flex flex-wrap items-center gap-2">
                {FILTERS.map((filter) => (
                    <button
                        key={filter.value}
                        type="button"
                        onClick={() => setChannel(filter.value)}
                        aria-pressed={channel === filter.value}
                        className={`rounded-full border px-3 py-1.5 text-sm transition-colors ${
                            channel === filter.value
                                ? "border-brand bg-brand/10 text-card-foreground"
                                : "border-border bg-card text-muted-foreground hover:text-card-foreground"
                        }`}
                    >
                        {filter.label}
                    </button>
                ))}
                {list.data?.meta?.total !== undefined ? (
                    <span className="ml-auto text-sm text-muted-foreground">
                        {list.data.meta.total} conversation{list.data.meta.total === 1 ? "" : "s"}
                    </span>
                ) : null}
            </div>

            <div className="grid min-h-0 flex-1 grid-cols-1 gap-4 lg:grid-cols-[22rem_1fr]">
                <section
                    aria-label="Conversations"
                    className="min-h-0 overflow-y-auto rounded-card border border-border bg-card"
                >
                    {list.isLoading ? (
                        <Notice icon={Loader2} title="Loading conversations" body="Fetching the inbox." />
                    ) : list.isError ? (
                        <Notice icon={TriangleAlert} title="Could not load" body={errorMessage(list.error)} />
                    ) : conversations.length === 0 ? (
                        <Notice
                            icon={Inbox}
                            title="No conversations"
                            body={
                                channel === "all"
                                    ? "Messages sent to your connected Facebook Page or Instagram account will appear here."
                                    : "No conversations on this channel yet."
                            }
                        />
                    ) : (
                        <ConversationList
                            conversations={conversations}
                            selectedId={effectiveId}
                            onSelect={setSelectedId}
                        />
                    )}
                </section>

                <section
                    aria-label="Conversation"
                    className="min-h-0 overflow-y-auto rounded-card border border-border bg-card"
                >
                    {!effectiveId ? (
                        <Notice
                            icon={Inbox}
                            title="No conversation selected"
                            body="Choose a conversation on the left to read it."
                        />
                    ) : detail.isLoading ? (
                        <Notice icon={Loader2} title="Loading conversation" body="Fetching the messages." />
                    ) : detail.isError ? (
                        <Notice icon={TriangleAlert} title="Could not load" body={errorMessage(detail.error)} />
                    ) : detail.data ? (
                        <div className="flex h-full flex-col">
                            <header className="sticky top-0 z-10 border-b border-border bg-card px-4 py-3">
                                <div className="flex flex-wrap items-center gap-2">
                                    <ChannelBadge channel={detail.data.channel} />
                                    <span className="text-sm text-muted-foreground">
                                        {detail.data.messageCount} message{detail.data.messageCount === 1 ? "" : "s"}
                                    </span>
                                    <span className="ml-auto inline-flex items-center gap-1.5 text-xs text-muted-foreground">
                                        {detail.data.leadLinked ? (
                                            <>
                                                <Link2 aria-hidden className="size-3.5" />
                                                {detail.data.lead?.businessName ?? "Linked to a lead"}
                                            </>
                                        ) : (
                                            <>
                                                <Link2Off aria-hidden className="size-3.5" />
                                                Not linked to a lead
                                            </>
                                        )}
                                    </span>
                                </div>
                            </header>

                            <div className="flex-1 px-4 py-4">
                                <MessageThread messages={detail.data.messages} />
                            </div>
                        </div>
                    ) : null}
                </section>
            </div>
        </div>
    );
}
