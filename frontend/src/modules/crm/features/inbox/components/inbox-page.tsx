"use client";

import { useMemo, useState } from "react";
import { Inbox, Link2, Link2Off, Loader2, TriangleAlert } from "lucide-react";
import axios from "axios";

import { Button } from "@/modules/crm/components/ui/button";
import { ChannelBadge } from "@/modules/crm/features/inbox/components/channel-badge";
import { ConversationList } from "@/modules/crm/features/inbox/components/conversation-list";
import { LinkLeadDialog } from "@/modules/crm/features/inbox/components/link-lead-dialog";
import { MessageThread } from "@/modules/crm/features/inbox/components/message-thread";
import { ReplyComposer } from "@/modules/crm/features/inbox/components/reply-composer";
import { useConversation, useConversations } from "@/modules/crm/features/inbox/hooks/use-conversations";
import {
    LINKED_LEAD_UNAVAILABLE,
    leadLinkHeaderState,
    type ChannelFilter,
    type InboxConversationDetail,
} from "@/modules/crm/features/inbox/types/inbox-types";

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

/**
 * What the header says about the conversation's lead, and — only when the server allows it — the
 * control to link one. See `leadLinkHeaderState` for why the decision reads `leadLinked` and
 * `link.enabled`, and never `lead !== null` or the browser's permission list.
 *
 * There is deliberately no unlink, relink or "change lead" affordance in any state: a link is
 * permanent in the app for now.
 */
function LeadLinkStatus({ detail, onLink }: { detail: InboxConversationDetail; onLink: () => void }) {
    const state = leadLinkHeaderState(detail);

    return (
        <span className="ml-auto inline-flex min-w-0 flex-wrap items-center justify-end gap-x-2 gap-y-1 text-xs text-muted-foreground">
            {state === "linked" ? (
                <span className="inline-flex min-w-0 items-center gap-1.5">
                    <Link2 aria-hidden className="size-3.5 shrink-0" />
                    <span className="truncate">{detail.lead?.businessName ?? "Linked to a lead"}</span>
                </span>
            ) : state === "linked_unavailable" ? (
                <span className="inline-flex items-center gap-1.5">
                    <Link2 aria-hidden className="size-3.5 shrink-0" />
                    {LINKED_LEAD_UNAVAILABLE}
                </span>
            ) : (
                <>
                    <span className="inline-flex items-center gap-1.5">
                        <Link2Off aria-hidden className="size-3.5 shrink-0" />
                        Not linked to a lead
                    </span>
                    {state === "unlinked_actionable" ? (
                        <Button variant="outline" size="sm" onClick={onLink}>
                            Link lead
                        </Button>
                    ) : null}
                </>
            )}
        </span>
    );
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
 * The Conversation Inbox.
 *
 * NOW TWO-WAY, AND THE COMPOSER IS ALWAYS PRESENT. This screen was read-only for as long as no
 * outbound path existed behind it, on the grounds that a disabled reply box would imply a
 * capability that was not there. That reasoning inverted once the send path shipped: the server now
 * answers, per conversation and per viewer, WHETHER a reply is possible and why not — so hiding the
 * box would make "you may not reply" and "replying was never built" look identical, which is the
 * confusion the capability field exists to end. The composer therefore renders in both states and
 * explains itself when inert.
 *
 * ITS SECOND JOB IS STILL EVIDENTIARY: for Meta App Review it must be possible to record a message
 * arriving on Messenger or Instagram and appearing here, with the channel unmistakable and the text
 * readable. That is why the channel is labelled in words on both the row and the header, and why
 * this screen reads the real conversation API rather than any demo fixture.
 *
 * NOTHING ON THIS SCREEN DECIDES A SEND. It posts a conversation id, a body and a client request
 * id; the provider, channel, sending account, recipient, credential and messaging window are all
 * the server's to determine, and the browser never learns any of them.
 */
export function InboxPage() {
    const [channel, setChannel] = useState<ChannelFilter>("all");
    const [selectedId, setSelectedId] = useState<string | null>(null);

    /*
     * Which conversation the link dialog is open FOR, rather than a bare boolean: the dialog is
     * modal, so the selection cannot change underneath it, but tying the open state to an id means a
     * stale `true` can never open the dialog over a different thread.
     */
    const [linkDialogFor, setLinkDialogFor] = useState<string | null>(null);

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

    /*
     * THE HEIGHT HAS TO ACCOUNT FOR THE SHELL, AND 10rem DID NOT.
     *
     * This pane is viewport-locked so the thread can scroll inside it, which means its height
     * must equal the viewport minus everything the shell puts around it. The real chrome is
     * roughly 16rem — the sticky PageHeader, `main`'s pt-8, the shell's own <h1> with its
     * mb-6, and `main`'s pb-24 below. The old 10rem under-counted that by about 96px, so the
     * bottom of the composer — the disabled-reason row specifically — sat below the fold until
     * somebody scrolled `main`, and read as clipped.
     *
     * 18rem matches the precedent already in this shell (calendar's board-column uses the same
     * figure) and leaves a little slack rather than sitting exactly on the boundary. `dvh`
     * rather than `vh` because mobile browser chrome collapses and expands, and `vh` measures
     * the larger state — the same reason the dialog and scheduler panel use it.
     *
     * The remaining pb-24 strip below this pane is what keeps the composer clear of the
     * Copilot FAB, which is `fixed bottom-6 right-6` and 48px tall: the FAB lives entirely
     * inside that reserved 96px, and the Send button no longer shares vertical space with it.
     *
     * min-h reduced from 32rem so a short window shrinks the THREAD rather than pushing the
     * composer out of view.
     */
    return (
        <div className="flex h-[calc(100dvh-18rem)] min-h-[24rem] flex-col gap-4">
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

                {/*
                  * `overflow-hidden` here, not `overflow-y-auto`: the THREAD scrolls (below) while
                  * the header and the composer stay put. Scrolling the whole pane would carry the
                  * reply box off-screen on a long conversation, which is exactly the thing it must
                  * not do on a phone.
                  */}
                <section
                    aria-label="Conversation"
                    className="flex min-h-0 flex-col overflow-hidden rounded-card border border-border bg-card"
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
                        <div className="flex min-h-0 flex-1 flex-col">
                            <header className="shrink-0 border-b border-border bg-card px-4 py-3">
                                <div className="flex flex-wrap items-center gap-2">
                                    <ChannelBadge channel={detail.data.channel} />
                                    <span className="text-sm text-muted-foreground">
                                        {detail.data.messageCount} message{detail.data.messageCount === 1 ? "" : "s"}
                                    </span>
                                    <LeadLinkStatus
                                        detail={detail.data}
                                        onLink={() => setLinkDialogFor(detail.data.id)}
                                    />
                                </div>
                            </header>

                            {/*
                              * Always mounted while a thread is open, not only while it is linkable:
                              * an uncertain outcome re-reads the conversation, which can flip it to
                              * "linked" mid-dialog, and unmounting then would swallow the message
                              * explaining what happened. Closed, it renders nothing and fetches
                              * nothing. Keyed by id so another thread starts from a clean slate.
                              */}
                            <LinkLeadDialog
                                key={detail.data.id}
                                conversationId={detail.data.id}
                                open={linkDialogFor === detail.data.id}
                                onOpenChange={(open) => setLinkDialogFor(open ? detail.data.id : null)}
                            />

                            <div className="min-h-0 flex-1 overflow-y-auto px-4 py-4">
                                <MessageThread messages={detail.data.messages} />
                            </div>

                            <ReplyComposer
                                // Keyed by conversation so switching threads starts a fresh draft
                                // rather than carrying one operator's half-written reply into
                                // somebody else's conversation.
                                key={detail.data.id}
                                conversationId={detail.data.id}
                                capability={detail.data.reply}
                            />
                        </div>
                    ) : null}
                </section>
            </div>
        </div>
    );
}
