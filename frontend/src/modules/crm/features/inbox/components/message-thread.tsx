"use client";

import { AlertCircle, Check, CircleHelp, CircleX, Clock, Paperclip, UserCheck } from "lucide-react";

import { deliveryState } from "@/modules/crm/features/inbox/lib/outbound-lock";
import type { InboxMessage } from "@/modules/crm/features/inbox/types/inbox-types";

const NO_UNRESOLVED: ReadonlySet<string> = new Set();

function stamp(message: InboxMessage): string {
    const iso = message.occurredAt ?? message.receivedAt;
    if (!iso) return "";
    const date = new Date(iso);
    return Number.isNaN(date.getTime())
        ? ""
        : date.toLocaleString(undefined, { month: "short", day: "numeric", hour: "numeric", minute: "2-digit" });
}

/**
 * The delivery state of one OUTBOUND turn, in words. The decision itself is `deliveryState` in
 * lib/outbound-lock, so it can be tested without React.
 *
 * `deliveryStatus` IS AUTHORITATIVE. `providerMessageIdPresent` is read in exactly ONE place: on a
 * row already `sent`, `false` marks a send an OPERATOR confirmed (Phase 3H.5A never invents a
 * provider id) as opposed to one the provider acknowledged. It is never evidence of delivery on its
 * own — it exists on most inbound rows too.
 *
 * THE SERVER'S UNRESOLVED LIST WINS. An id in `unresolvedOutbound` reads "Delivery unconfirmed" even
 * when the row is a stranded `pending` with no code, so an operator does not read it as "still
 * sending" and wait forever — or send it again. A `pending` row carrying one of the unresolved codes
 * reads the same way even without the list (an older server).
 *
 * NO RAW FAILURE CODE IS EVER RENDERED. An operator sees a state, not `provider_unavailable`.
 */
function deliveryLabel(
    message: InboxMessage,
    unresolvedIds: ReadonlySet<string>,
): { text: string; icon: typeof Check; tone: string } | null {
    switch (deliveryState(message, unresolvedIds)) {
        case "unconfirmed":
            return { text: "Delivery unconfirmed", icon: CircleHelp, tone: "text-amber-600 dark:text-amber-400" };
        case "not_sent_by_operator":
            return { text: "Not sent (confirmed by operator)", icon: CircleX, tone: "text-muted-foreground" };
        case "failed":
            return { text: "Failed", icon: AlertCircle, tone: "text-destructive" };
        case "sent_by_operator":
            return { text: "Sent (confirmed by operator)", icon: UserCheck, tone: "text-muted-foreground" };
        case "sent":
            return { text: "Sent", icon: Check, tone: "text-muted-foreground" };
        case "pending":
            return { text: "Pending", icon: Clock, tone: "text-muted-foreground" };
        default:
            // Inbound, or an outbound row with no status (predates delivery tracking): say nothing.
            return null;
    }
}

/**
 * The thread, oldest first.
 *
 * Inbound and outbound are distinguished by side, colour AND a text label, and outbound turns
 * additionally carry their delivery state — inbound ones never do, because we did not deliver them.
 */
export function MessageThread({
    messages,
    unresolvedIds = NO_UNRESOLVED,
}: {
    messages: InboxMessage[];
    /** Ids from the detail's `unresolvedOutbound`. */
    unresolvedIds?: ReadonlySet<string>;
}) {
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
                                const delivery = deliveryLabel(message, unresolvedIds);

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
