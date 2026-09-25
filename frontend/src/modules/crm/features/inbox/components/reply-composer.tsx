"use client";

import { useId, useRef, useState } from "react";
import { CircleHelp, Loader2, Lock, Send, TriangleAlert } from "lucide-react";

import { Button } from "@/modules/crm/components/ui/button";
import { Textarea } from "@/modules/crm/components/ui/textarea";
import { classifyReplyError, useSendReply } from "@/modules/crm/features/inbox/hooks/use-send-reply";
import type { InboxReplyCapability } from "@/modules/crm/features/inbox/types/inbox-types";

/** The server's own ceiling, mirrored so the counter can warn before the request is refused. */
const MAX_BODY = 2000;

/**
 * Why the composer is inert, in words an operator can act on.
 *
 * DELIBERATELY SHORT AND DELIBERATELY NOT EXHAUSTIVE. Only the three reasons the capability field
 * can actually produce are spelled out; anything else falls to a neutral sentence rather than
 * rendering a raw code. The server owns the vocabulary and may add to it, and an unrecognised
 * reason must read as "unavailable", never as `resource_ambiguous`.
 */
function disabledCopy(reason: string | null): string {
    switch (reason) {
        case "permission_denied":
            return "You do not have permission to reply to this conversation.";
        case "unsupported_channel":
            return "Replies are not supported for this conversation channel.";
        case "outbound_disabled":
            return "Replies are not currently enabled for this conversation.";
        default:
            return "Replies are currently unavailable.";
    }
}

/**
 * The reply box.
 *
 * ALWAYS VISIBLE, EVEN WHEN IT CANNOT BE USED. Hiding it would make "you may not reply" and
 * "replying was never built" look identical, which is exactly the confusion the capability field
 * was added to end. So it renders disabled with the reason underneath.
 *
 * IT CLAIMS NOTHING. There is no optimistic bubble and no local "Sent" — on success the draft is
 * cleared and the thread is refetched, so the status an operator reads is the one the server
 * persisted. For the two ambiguous outcomes even the server will not claim delivery, and this
 * component offers no Retry affordance at all: pressing it again would be a second real message to
 * a real person, which is the one thing the whole outbound path is built to prevent. The operator
 * workflow for resolving those belongs to a later phase.
 *
 * ================== THE THIRD KIND OF UNCERTAINTY, AND THE ONE THIS UI OWNS ==================
 *
 * The server can tell us a send was refused, and it can tell us delivery was unconfirmed. There is a
 * third case only the BROWSER can be in: the POST left, and no response came back. The connection
 * dropped, or timed out, or was reset. The request may have reached the backend and been processed
 * in full.
 *
 * An earlier version of this component treated that identically to any other error — draft kept,
 * composer re-enabled, "please try again". That was wrong, and wrong in the expensive direction: a
 * second click mints a SECOND client request id, and two ids are two distinct sends as far as the
 * server's idempotency is concerned. The operator would have duplicated a message that was already
 * delivered, while being told to do exactly that.
 *
 * So an unresolved attempt is REMEMBERED — its id and its exact body — and Send stays disabled for
 * as long as the draft still matches it. The id is deliberately NOT discarded: a future
 * reconciliation workflow can replay that same key safely, which a fresh UUID could never do. The
 * only way out is an explicit operator decision: edit the text, or discard the draft. Neither is a
 * retry, and neither happens by itself.
 */
export function ReplyComposer({
    conversationId,
    capability,
}: {
    conversationId: string;
    capability: InboxReplyCapability;
}) {
    const [draft, setDraft] = useState("");
    const [failure, setFailure] = useState<string | null>(null);

    /*
     * An attempt whose outcome the browser never learned. Holds the id AND the exact body it was
     * sent with, because "has the operator changed their mind" is answered by comparing the draft
     * against that body — not by a flag somebody could forget to clear.
     */
    const [unresolved, setUnresolved] = useState<{ clientRequestId: string; body: string } | null>(null);

    const textareaId = useId();
    const counterId = useId();
    const noticeId = useId();

    /*
     * The double-click guard.
     *
     * `disabled={isPending}` is the visible protection, but React only applies it on the next
     * render, so two clicks inside one frame could both reach the handler. This ref is checked and
     * set synchronously, which closes that window. It matters more than it looks: the two clicks
     * would mint two different clientRequestIds, and two distinct ids are two distinct sends as far
     * as the server's idempotency is concerned — the customer would receive the message twice.
     */
    const inFlight = useRef(false);

    const mutation = useSendReply(conversationId);

    const trimmed = draft.trim();
    const tooLong = draft.length > MAX_BODY;

    /*
     * The lock. While the draft is byte-identical to an attempt we never got an answer for, Send is
     * refused — pressing it could only duplicate that attempt under a new id. Editing the text makes
     * this false again, which is the intended escape: a different message is a different message, not
     * a resend of the uncertain one.
     */
    const lockedOnUnresolved = unresolved !== null && unresolved.body === draft;

    const canType = capability.enabled && ! mutation.isPending;
    const canSend =
        capability.enabled && trimmed !== "" && ! tooLong && ! mutation.isPending && ! lockedOnUnresolved;

    function submit(): void {
        if (! canSend || inFlight.current) {
            return;
        }

        inFlight.current = true;
        setFailure(null);

        /*
         * Minted HERE, once, at the moment the operator commits — not on mount and not per
         * keystroke. One id belongs to one attempt: a retry of THIS attempt would replay it and the
         * server would recognise the duplicate, while a genuinely new message gets a new one.
         * crypto.randomUUID() rather than Math.random(): the server validates the UUID format, and
         * a weak generator could collide across two tabs.
         */
        const clientRequestId = crypto.randomUUID();

        mutation.mutate(
            { body: draft, clientRequestId },
            {
                onSuccess: (response) => {
                    // Only a server-confirmed acceptance clears the draft.
                    if (response.accepted) {
                        setDraft("");
                        return;
                    }

                    // A 2xx that is not `accepted` should not happen, but losing the operator's
                    // text over it would be worse than a generic sentence.
                    setFailure(response.reason ?? "The reply could not be sent.");
                },
                onError: (error) => {
                    /*
                     * Every refusal is a 4xx/5xx, so it arrives here rather than in onSuccess — and
                     * the three classes must not be collapsed. See classifyReplyError().
                     */
                    const failed = classifyReplyError(error);

                    if (failed.kind === "responseUnknown") {
                        /*
                         * The dangerous case. Keep the id, keep the body, lock Send. No "try again",
                         * because the browser genuinely does not know whether this was delivered.
                         */
                        setUnresolved({ clientRequestId, body: draft });
                        setFailure(null);

                        return;
                    }

                    // Authoritative: the server answered. The reason shown for a contract refusal is
                    // the SERVER's sanitized sentence — the backend owns that copy.
                    setFailure(failed.kind === "refused"
                        ? (failed.reply.reason ?? "The reply could not be sent.")
                        : failed.message);
                },
                onSettled: () => {
                    inFlight.current = false;
                },
            },
        );
    }

    /**
     * The only way to leave the unresolved state without editing the text.
     *
     * Named for what it is: a NEW operator decision, not a retry. It CLEARS the draft, so whatever
     * is typed next is plainly a different message — there is no path here that resends the
     * uncertain body, with its id or with a new one.
     */
    function startNewReply(): void {
        setUnresolved(null);
        setFailure(null);
        setDraft("");
    }

    const disabledReason = capability.enabled ? null : disabledCopy(capability.reason);

    return (
        <div className="border-t border-border bg-card px-4 py-3">
            <label htmlFor={textareaId} className="sr-only">
                Reply to this conversation
            </label>

            <Textarea
                id={textareaId}
                value={draft}
                onChange={(event) => setDraft(event.target.value)}
                disabled={! canType}
                rows={3}
                maxLength={MAX_BODY}
                aria-describedby={`${counterId}${disabledReason !== null || lockedOnUnresolved || failure !== null ? ` ${noticeId}` : ""}`}
                aria-invalid={tooLong ? true : undefined}
                placeholder={capability.enabled ? "Write a reply…" : "Replies are unavailable"}
                className="resize-y"
            />

            <div className="mt-2 flex flex-wrap items-center gap-2">
                <span
                    id={counterId}
                    className={`text-xs ${tooLong ? "font-medium text-destructive" : "text-muted-foreground"}`}
                >
                    {draft.length} / {MAX_BODY}
                </span>

                <Button
                    type="button"
                    onClick={submit}
                    disabled={! canSend}
                    className="ml-auto"
                    aria-label={mutation.isPending ? "Sending reply" : "Send reply"}
                >
                    {mutation.isPending ? (
                        <>
                            <Loader2 aria-hidden className="animate-spin" />
                            Sending…
                        </>
                    ) : (
                        <>
                            <Send aria-hidden />
                            Send
                        </>
                    )}
                </Button>
            </div>

            {/*
              * One live region for both states, so a screen reader hears whichever applies without
              * two competing announcements. `status` rather than `alert` for the disabled
              * explanation — it is context, not an error the operator just caused.
              */}
            {disabledReason !== null ? (
                <p id={noticeId} role="status" className="mt-2 flex items-start gap-1.5 text-xs text-muted-foreground">
                    <Lock aria-hidden className="mt-0.5 size-3.5 shrink-0" />
                    {disabledReason}
                </p>
            ) : lockedOnUnresolved ? (
                <div
                    id={noticeId}
                    role="alert"
                    className="mt-2 flex flex-wrap items-start gap-1.5 text-xs font-medium text-amber-700 dark:text-amber-400"
                >
                    <CircleHelp aria-hidden className="mt-0.5 size-3.5 shrink-0" />
                    <span>
                        We couldn&apos;t confirm whether this reply was processed. Check the conversation before
                        sending another reply.
                    </span>
                    <Button
                        type="button"
                        variant="ghost"
                        size="xs"
                        onClick={startNewReply}
                        className="ml-auto"
                        aria-label="Discard this draft and start a new reply"
                    >
                        Start a new reply
                    </Button>
                </div>
            ) : failure !== null ? (
                <p id={noticeId} role="alert" className="mt-2 flex items-start gap-1.5 text-xs font-medium text-destructive">
                    <TriangleAlert aria-hidden className="mt-0.5 size-3.5 shrink-0" />
                    {failure}
                </p>
            ) : null}
        </div>
    );
}
