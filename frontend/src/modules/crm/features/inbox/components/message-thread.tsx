"use client";

import { Paperclip } from "lucide-react";

import type { InboxMessage } from "@/modules/crm/features/inbox/types/inbox-types";

function stamp(message: InboxMessage): string {
    const iso = message.occurredAt ?? message.receivedAt;
    if (!iso) return "";
    const date = new Date(iso);
    return Number.isNaN(date.getTime())
        ? ""
        : date.toLocaleString(undefined, { month: "short", day: "numeric", hour: "numeric", minute: "2-digit" });
}

/**
 * The thread, oldest first.
 *
 * Inbound and outbound are distinguished by side, colour AND a text label. Today every message is
 * inbound — there is no send path anywhere in the system — so the distinction is groundwork rather
 * than decoration, and the label keeps it legible if that ever changes.
 */
export function MessageThread({ messages }: { messages: InboxMessage[] }) {
    if (messages.length === 0) {
        return <p className="py-10 text-center text-sm text-muted-foreground">This conversation has no messages yet.</p>;
    }

    return (
        <ol className="space-y-3">
            {messages.map((message) => {
                const inbound = message.direction === "inbound";

                return (
                    <li key={message.id} className={`flex ${inbound ? "justify-start" : "justify-end"}`}>
                        <div className={`max-w-[85%] sm:max-w-[70%] ${inbound ? "" : "text-right"}`}>
                            <div
                                className={`rounded-card border px-3.5 py-2.5 text-sm ${
                                    inbound
                                        ? "border-border bg-muted text-card-foreground"
                                        : "border-brand/30 bg-brand/10 text-card-foreground"
                                }`}
                            >
                                {message.body ? (
                                    <p className="whitespace-pre-wrap break-words">{message.body}</p>
                                ) : (
                                    <p className="italic text-muted-foreground">
                                        {message.hasAttachment ? "Attachment" : "No text content"}
                                    </p>
                                )}

                                {message.hasAttachment && message.body ? (
                                    <p className="mt-1.5 flex items-center gap-1.5 text-xs text-muted-foreground">
                                        <Paperclip aria-hidden className="size-3.5" />
                                        {message.messageType}
                                    </p>
                                ) : null}
                            </div>

                            <p className="mt-1 px-1 text-xs text-muted-foreground">
                                {inbound ? "Customer" : "Us"} · {stamp(message)}
                            </p>
                        </div>
                    </li>
                );
            })}
        </ol>
    );
}
