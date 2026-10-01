"use client";

import { useRef, useState } from "react";
import { Loader2 } from "lucide-react";

import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from "@/modules/crm/components/ui/dialog";
import { Button } from "@/modules/crm/components/ui/button";
import { classifyResolveError, settledOutcome } from "@/modules/crm/features/inbox/lib/outbound-lock";
import { UnconfirmedResolutionResponse, useResolveMessage } from "@/modules/crm/features/inbox/hooks/use-resolve-message";
import type {
    InboxUnresolvedOutbound,
    ResolutionOutcome,
} from "@/modules/crm/features/inbox/types/inbox-types";

/** What the operator clicked, snapshotted so the dialog survives the entry leaving the live list. */
export type ResolveTarget = {
    entry: InboxUnresolvedOutbound;
    /** The message text when it is in the loaded thread; null otherwise. */
    body: string | null;
    outcome: ResolutionOutcome;
};

type Notice = { tone: "error" | "info"; text: string; action: "close" | "recheck" | "retry" };

const UNREADABLE_NOTICE: Notice = {
    tone: "error",
    text: "We couldn't confirm whether your choice was recorded. Check again before doing anything else.",
    action: "recheck",
};

const STILL_WAITING_NOTICE: Notice = {
    tone: "error",
    text: "Your choice was not recorded — this message is still waiting for confirmation. You can try again.",
    action: "retry",
};

export function formatStamp(iso: string | null): string {
    if (!iso) return "";
    const date = new Date(iso);
    return Number.isNaN(date.getTime())
        ? ""
        : date.toLocaleString(undefined, { month: "short", day: "numeric", hour: "numeric", minute: "2-digit" });
}

/**
 * Confirm an operator verdict on ONE unresolved outbound message: "sent" or "not sent".
 *
 * NOTHING IS SENT TO THE CUSTOMER from here, and nothing is ever re-posted by itself. An uncertain
 * answer is settled by re-reading the conversation; "Check again" only re-reads; "Try again" is a
 * deliberate click after a re-read proved the first attempt did not land.
 *
 * Same dismissal rule as the link dialog: while a request or re-read is running, Escape and backdrop
 * clicks are ignored so the operator always sees how it ended.
 *
 * `onSettled` fires once the server shows the message is no longer unresolved. `outcome` is what the
 * SERVER says — the 200's own verdict, or the row's status read back after a lost response or a 409
 * — and null when the re-read could not tell. `explicit` is true only for a 200 answering THIS
 * operator's POST. `body` is the message text from the re-read data (falling back to the snapshot
 * taken when the dialog opened), never from a render captured before the re-read.
 */
export function ResolveOutboundDialog({
    conversationId,
    target,
    open,
    onOpenChange,
    onSettled,
}: {
    conversationId: string;
    target: ResolveTarget | null;
    open: boolean;
    onOpenChange: (open: boolean) => void;
    onSettled: (messageId: string, outcome: ResolutionOutcome | null, explicit: boolean, body: string | null) => void;
}) {
    const { mutation, refreshConversation } = useResolveMessage(conversationId);

    const [notice, setNotice] = useState<Notice | null>(null);
    const [busy, setBusy] = useState<"saving" | "checking" | null>(null);

    /* The synchronous guard; `busy` only takes effect on the next render. */
    const inFlight = useRef(false);

    function handleOpenChange(next: boolean) {
        if (!next) {
            if (inFlight.current) {
                return;
            }
            setNotice(null);
        }
        onOpenChange(next);
    }

    /** Re-read and settle an uncertain outcome from what the SERVER now says. GET only. */
    async function settleFromServer(messageId: string): Promise<void> {
        const refreshed = await refreshConversation();

        if (!refreshed.fresh) {
            setNotice(UNREADABLE_NOTICE);
            return;
        }

        if ((refreshed.detail.unresolvedOutbound ?? []).some((entry) => entry.id === messageId)) {
            setNotice(STILL_WAITING_NOTICE);
            return;
        }

        const row = refreshed.detail.messages.find((message) => message.id === messageId);
        const settled = settledOutcome(row);

        onSettled(messageId, settled === "sent" || settled === "not_sent" ? settled : null, false, row?.body ?? target?.body ?? null);

        setNotice({
            tone: "info",
            action: "close",
            text:
                settled === "sent"
                    ? "This message is now recorded as sent."
                    : settled === "not_sent"
                      ? "This message is now recorded as not sent."
                      : settled === "failed"
                        ? "This message is now recorded as failed."
                        : "This message is no longer waiting for confirmation.",
        });
    }

    async function confirm() {
        if (target === null || inFlight.current) {
            return;
        }

        const messageId = target.entry.id;

        inFlight.current = true;
        setBusy("saving");
        setNotice(null);

        let done = false;

        try {
            const outcome = await mutation.mutateAsync({ messageId, outcome: target.outcome });

            const refreshed = await refreshConversation();
            const row = refreshed.fresh ? refreshed.detail.messages.find((message) => message.id === messageId) : undefined;

            onSettled(messageId, outcome, true, row?.body ?? target.body);
            done = true;
        } catch (error) {
            const result = error instanceof UnconfirmedResolutionResponse ? { kind: "unknown" as const } : classifyResolveError(error);

            switch (result.kind) {
                case "conflict": {
                    // Someone or something settled it first. Show the server's own sentence, and
                    // report what the re-read row ACTUALLY says (a "sent" there clears a matching draft).
                    const refreshed = await refreshConversation();
                    const row = refreshed.fresh ? refreshed.detail.messages.find((message) => message.id === messageId) : undefined;
                    const settled = settledOutcome(row);

                    onSettled(messageId, settled === "sent" || settled === "not_sent" ? settled : null, false, row?.body ?? target.body);
                    setNotice({ tone: "info", text: result.reason, action: "close" });
                    break;
                }
                case "definitive":
                    if (result.status === 403 || result.status === 404) {
                        await refreshConversation();
                    }
                    setNotice({ tone: "error", text: result.message, action: result.status === 429 ? "retry" : "close" });
                    break;
                default:
                    // No trustworthy answer. Claim nothing; read the conversation back.
                    setBusy("checking");
                    await settleFromServer(messageId);
                    break;
            }
        } finally {
            inFlight.current = false;
            setBusy(null);
        }

        if (done) {
            handleOpenChange(false);
        }
    }

    /** Re-read only. Never re-posts. */
    async function recheck() {
        if (target === null || inFlight.current) {
            return;
        }

        inFlight.current = true;
        setBusy("checking");
        setNotice(null);

        try {
            await settleFromServer(target.entry.id);
        } finally {
            inFlight.current = false;
            setBusy(null);
        }
    }

    const markSent = target?.outcome === "sent";
    const actionLabel = markSent ? "Mark as sent" : "Mark as not sent";

    return (
        <Dialog open={open} onOpenChange={handleOpenChange} disablePointerDismissal={busy !== null}>
            <DialogContent showCloseButton={false} className="flex max-h-[85dvh] w-full flex-col gap-0 overflow-hidden p-0 sm:max-w-[480px]">
                <DialogHeader className="shrink-0 px-5 pt-5 pb-0">
                    <DialogTitle>{markSent ? "Mark this message as sent?" : "Mark this message as not sent?"}</DialogTitle>
                </DialogHeader>

                <div className="min-h-0 flex-1 overflow-y-auto px-5 pt-4 pb-4">
                    {target !== null ? (
                        <div className="rounded-lg border border-border bg-muted px-3 py-2 text-sm text-card-foreground">
                            {target.body ? (
                                <p className="line-clamp-4 whitespace-pre-wrap break-words">{target.body}</p>
                            ) : (
                                <p className="italic text-muted-foreground">
                                    An earlier message{target.entry.createdAt ? ` · ${formatStamp(target.entry.createdAt)}` : ""}
                                </p>
                            )}
                        </div>
                    ) : null}

                    <DialogDescription className="mt-4">
                        {markSent
                            ? "Only confirm this if you have checked that the customer received it. It will show as “Sent (confirmed by operator)”. Nothing is sent."
                            : "Only confirm this if you are sure the customer did not receive it. Nothing is re-sent automatically — you can write a new reply afterwards."}
                    </DialogDescription>

                    <div role="status" aria-live="polite" className="mt-4 min-h-5">
                        {busy === "checking" ? (
                            <p className="flex items-center gap-2 text-sm text-muted-foreground">
                                <Loader2 aria-hidden className="size-4 animate-spin" />
                                Checking the conversation…
                            </p>
                        ) : notice ? (
                            <p className={notice.tone === "error" ? "text-sm text-destructive" : "text-sm text-muted-foreground"}>
                                {notice.text}
                            </p>
                        ) : null}
                    </div>
                </div>

                {/* mx-0/mb-0 cancel the footer's -mx-4/-mb-4, which assume the default p-4 panel. */}
                <DialogFooter className="mx-0 mb-0 border-t border-border px-5 py-3">
                    {notice?.action === "close" ? (
                        <Button size="sm" onClick={() => handleOpenChange(false)}>
                            Close
                        </Button>
                    ) : (
                        <>
                            <Button variant="outline" size="sm" onClick={() => handleOpenChange(false)} disabled={busy !== null}>
                                Cancel
                            </Button>
                            {notice?.action === "recheck" ? (
                                <Button size="sm" onClick={() => void recheck()} disabled={busy !== null}>
                                    {busy === "checking" ? "Checking…" : "Check again"}
                                </Button>
                            ) : (
                                <Button size="sm" onClick={() => void confirm()} disabled={busy !== null || target === null}>
                                    {busy === "saving" ? (
                                        <>
                                            <Loader2 aria-hidden className="size-3.5 animate-spin" />
                                            Saving…
                                        </>
                                    ) : notice?.action === "retry" ? (
                                        "Try again"
                                    ) : (
                                        actionLabel
                                    )}
                                </Button>
                            )}
                        </>
                    )}
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
