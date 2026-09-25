"use client";

import { AlertCircle, Check, CircleHelp, Clock, Paperclip } from "lucide-react";

import { UNRESOLVED_FAILURE_CODES } from "@/modules/crm/features/inbox/types/inbox-types";
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
 * The delivery state of one OUTBOUND turn, in words.
 *
 * `deliveryStatus` IS AUTHORITATIVE AND `providerMessageIdPresent` IS NOT. A provider id can exist
 * on a row whose delivery was never confirmed, and it exists on most inbound rows too, so treating
 * it as evidence of delivery would announce a success the server refused to claim. It is not read
 * here at all.
 *
 * THE FIFTH STATE IS THE ONE THAT MATTERS. `pending` CARRYING a failure code is not "in flight" and
 * not "failed" — it means the message may already be with the customer and nobody knows. It reads
 * as "Delivery unconfirmed" so that an operator does not do the one thing that would make it worse,
 * which is send it again. The codes come from UNRESOLVED_FAILURE_CODES rather than a second copy of
 * the list.
 *
 * NO RAW FAILURE CODE IS EVER RENDERED. An operator sees a state, not `provider_unavailable`.
 */
function deliveryLabel(message: InboxMessage): { text: string; icon: typeof Check; tone: string } | null {
    if (message.direction !== "outbound") {
        return null;
    }

    if (message.deliveryStatus === "sent") {
        return { text: "Sent", icon: Check, tone: "text-muted-foreground" };
    }

    if (message.deliveryStatus === "failed") {
        return { text: "Failed", icon: AlertCircle, tone: "text-destructive" };
    }

    if (message.deliveryStatus === "pending") {
        return message.failureCode !== null && UNRESOLVED_FAILURE_CODES.includes(message.failureCode)
            ? { text: "Delivery unconfirmed", icon: CircleHelp, tone: "text-amber-600 dark:text-amber-400" }
            : { text: "Pending", icon: Clock, tone: "text-muted-foreground" };
    }

    // An outbound row with no status at all predates delivery tracking; say nothing rather than guess.
    return null;
}

/**
 * The thread, oldest first.
 *
 * Inbound and outbound are distinguished by side, colour AND a text label, and outbound turns
 * additionally carry their delivery state — inbound ones never do, because we did not deliver them.
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

                            {(() => {
                                const delivery = deliveryLabel(message);

                                if (delivery === null) {
                                    return null;
                                }

                                const Icon = delivery.icon;

                                return (
                                    <p
                                        className={`mt-0.5 flex items-center gap-1 px-1 text-xs ${inbound ? "" : "justify-end"} ${delivery.tone}`}
                                    >
                                        <Icon aria-hidden className="size-3.5" />
                                        {delivery.text}
                                    </p>
                                );
                            })()}
                        </div>
                    </li>
                );
            })}
        </ol>
    );
}
