"use client";

import { useEffect, useId, useRef, useState } from "react";
import { useQueryClient } from "@tanstack/react-query";
import { CircleHelp, Clock, Loader2, Lock, Send, Sparkles, TriangleAlert } from "lucide-react";

import { Button } from "@/modules/crm/components/ui/button";
import { Textarea } from "@/modules/crm/components/ui/textarea";
import {
    formatStamp,
    ResolveOutboundDialog,
    type ResolveTarget,
} from "@/modules/crm/features/inbox/components/resolve-outbound-dialog";
import { classifyReplyError, useSendReply } from "@/modules/crm/features/inbox/hooks/use-send-reply";
import { draftErrorMessage, draftTextFrom, useDraftReply } from "@/modules/crm/features/inbox/hooks/use-draft-reply";
import {
    composerLock,
    draftAfterResolution,
    isAmbiguousReplyFailure,
    nextClientRequestId,
} from "@/modules/crm/features/inbox/lib/outbound-lock";
import { queryKeys } from "@/modules/crm/lib/query-keys";
import type {
    InboxConversationDetail,
    InboxDraftCapability,
    InboxMessage,
    InboxReplyCapability,
    InboxUnresolvedOutbound,
    ResolutionOutcome,
} from "@/modules/crm/features/inbox/types/inbox-types";

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
 * a real person, which is the one thing the whole outbound path is built to prevent. Instead, the
 * operator records what actually happened — "Mark as sent" / "Mark as not sent" — from the panel
 * above the box (Phase 3H.5B), which never sends anything.
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
 * ================== THE LOCK (Phase 3H.5B) ==================
 *
 * Send is refused — WHATEVER the draft says; editing the text is no longer an escape — while any of
 * these holds (see `composerLock`):
 *
 *  - a reply from this browser is in flight;
 *  - the SERVER lists an unresolved outbound message (`unresolvedOutbound`), which survives reload;
 *  - an outbound row is still `pending` without being listed — another operator's reply, or ours,
 *    inside its sending window — shown as "Still sending" with a Check again that only re-reads;
 *  - this browser lost the answer to its own send (no response, 5xx, a proxy page, or a
 *    `delivery_unknown` / `provider_unavailable` refusal). Set IMMEDIATELY, before any re-read.
 *
 * The local lock is cleared by resolving a message, or by "Start a new reply" — offered only once the
 * conversation has been read back after the ambiguous moment. It is NOT cleared just because a re-read
 * shows nothing pending: a request still queued upstream may not have created its row yet.
 *
 * NO CLIENT REQUEST ID IS EVER REUSED. The reply endpoint's idempotency ignores the body and a failed
 * row is re-claimable, so replaying an old id after "Mark as not sent" would send again under the OLD
 * row, possibly with different text. Every click mints a fresh one; nothing remembers the old one.
 */
export function ReplyComposer({
    conversationId,
    capability,
    messages,
    unresolvedOutbound,
    serverReadAt,
    draftCapability,
}: {
    conversationId: string;
    capability: InboxReplyCapability;
    messages: InboxMessage[];
    unresolvedOutbound: InboxUnresolvedOutbound[] | undefined;
    /** `dataUpdatedAt` of the conversation detail these props came from. */
    serverReadAt: number;
    /** Phase 4A-lite server hint. Absent reads as "not offered". */
    draftCapability?: InboxDraftCapability;
}) {
    const [draft, setDraft] = useState("");
    const [failure, setFailure] = useState<string | null>(null);

    /*
     * When this browser lost the answer to its own send, or null. A timestamp and nothing else: the
     * client request id is deliberately NOT kept, so nothing can ever replay it.
     */
    const [ambiguousAt, setAmbiguousAt] = useState<number | null>(null);

    /* The resolve dialog, mounted once. The target is a SNAPSHOT so the dialog outlives the entry. */
    const [resolveTarget, setResolveTarget] = useState<ResolveTarget | null>(null);
    const [resolveOpen, setResolveOpen] = useState(false);

    /* "Check again" — a GET, nothing more. */
    const [checking, setChecking] = useState(false);
    const [checkFailed, setCheckFailed] = useState(false);

    const queryClient = useQueryClient();

    const textareaId = useId();
    const counterId = useId();
    const noticeId = useId();
    const lockNoticeId = useId();

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

    /*
     * ============ "DRAFT WITH AI" (Phase 4A-lite) — TEXT ONLY, NEVER A SEND ============
     *
     * The draft flow owns none of the send machinery: it never calls `mutation`, never mints a
     * client request id, never touches the ambiguous lock or the resolve panel. Its only effect is
     * `setDraft(text)`, after which the operator reviews and presses the normal Send (or doesn't).
     */
    const draftMutation = useDraftReply(conversationId);
    const [confirmReplace, setConfirmReplace] = useState(false);
    const [draftNotice, setDraftNotice] = useState<{ tone: "error" | "info"; text: string } | null>(null);

    /* Same synchronous double-click guard as `inFlight`, for the (paid) draft request. */
    const draftInFlight = useRef(false);

    /*
     * The composer text as last rendered, and a count of send attempts. Read only in the draft's
     * response handler, to refuse to overwrite anything the operator typed — or sent — meanwhile.
     */
    const latestDraft = useRef(draft);
    const sendAttempts = useRef(0);

    useEffect(() => {
        latestDraft.current = draft;
    }, [draft]);

    const trimmed = draft.trim();
    const tooLong = draft.length > MAX_BODY;

    const lock = composerLock({
        unresolvedOutbound,
        messages,
        sending: mutation.isPending,
        ambiguousAt,
        serverReadAt,
    });

    const unresolvedList = unresolvedOutbound ?? [];

    /* Resolving is CRM-only, so it stays available when outbound is disabled or the window closed. */
    const canResolve = capability.reason !== "permission_denied";

    const canType = capability.enabled && ! mutation.isPending;
    const canSend = capability.enabled && trimmed !== "" && ! tooLong && lock.reason === null;

    /** Re-read the conversation and the list rows. GET only; says whether a fresh answer arrived. */
    async function refreshConversation(): Promise<boolean> {
        const key = queryKeys.conversations.detail(conversationId);
        const before = queryClient.getQueryState(key)?.dataUpdatedAt ?? 0;

        void queryClient.invalidateQueries({ queryKey: queryKeys.conversations.lists() });
        await queryClient.invalidateQueries({ queryKey: key });

        const state = queryClient.getQueryState<InboxConversationDetail>(key);

        return state?.data !== undefined && state.dataUpdatedAt > before;
    }

    async function checkAgain(): Promise<void> {
        if (checking) {
            return;
        }

        setChecking(true);
        setCheckFailed(false);

        try {
            setCheckFailed(! (await refreshConversation()));
        } finally {
            setChecking(false);
        }
    }

    function openResolve(entry: InboxUnresolvedOutbound, outcome: ResolutionOutcome): void {
        const body = messages.find((message) => message.id === entry.id)?.body ?? null;

        setResolveTarget({ entry, body, outcome });
        setResolveOpen(true);
    }

    /**
     * The server now shows `messageId` as settled. The local lock goes, because the thread above is
     * the truth again. Whenever the SERVER says the message is `sent` — the 200's verdict, or the row
     * read back after a lost response or a 409 — a draft that is still that text (compared trimmed:
     * the backend trims `body` before storing it) is cleared, so the retained draft cannot be sent a
     * second time under a new id. After an EXPLICIT "not sent" the text is offered back — only into
     * an empty composer, never over something the operator is typing. See `draftAfterResolution`.
     * `body` comes from the dialog's re-read data; the `messages` lookup is only a fallback.
     * Whatever is sent next gets a new client request id (see `nextClientRequestId`).
     */
    function handleResolved(messageId: string, outcome: ResolutionOutcome | null, explicit: boolean, body: string | null): void {
        setAmbiguousAt(null);
        setFailure(null);
        setCheckFailed(false);

        const text = body ?? messages.find((message) => message.id === messageId)?.body ?? null;

        setDraft((current) => draftAfterResolution(current, outcome, text, explicit));
    }

    function submit(): void {
        if (! canSend || inFlight.current) {
            return;
        }

        inFlight.current = true;
        sendAttempts.current += 1;
        setFailure(null);

        /*
         * Minted HERE, once, at the moment the operator commits — not on mount and not per
         * keystroke — and never reused (see the component note). crypto.randomUUID() rather than
         * Math.random(): the server validates the UUID format, and a weak generator could collide
         * across two tabs.
         */
        const clientRequestId = nextClientRequestId();

        mutation.mutate(
            { body: draft, clientRequestId },
            {
                onSuccess: (response) => {
                    // Only a server-confirmed acceptance clears the draft.
                    if (response?.accepted === true) {
                        setDraft("");
                        return;
                    }

                    /*
                     * A 2xx WITHOUT the contract — a proxy page, an empty body, an anomalous
                     * `accepted: false`. The send may well have happened, so this is ambiguous,
                     * never "try again": lock first, then re-read. The draft is kept.
                     */
                    setAmbiguousAt(Date.now());
                    setFailure(null);
                    void refreshConversation();
                },
                onError: (error) => {
                    /*
                     * Every refusal is a 4xx/5xx, so it arrives here rather than in onSuccess — and
                     * the three classes must not be collapsed. See classifyReplyError().
                     */
                    const failed = classifyReplyError(error);

                    if (isAmbiguousReplyFailure(failed)) {
                        /*
                         * The dangerous case — including a 502 carrying `delivery_unknown` or
                         * `provider_unavailable`, which used to leave Send enabled. Lock FIRST, then
                         * re-read (GET) so the server's unresolved list can take over.
                         */
                        setAmbiguousAt(Date.now());
                        setFailure(null);
                        void refreshConversation();

                        return;
                    }

                    // Authoritative: the server answered. The reason shown for a contract refusal is
                    // the SERVER's sanitized sentence — the backend owns that copy.
                    // (`responseUnknown` is always ambiguous and never reaches this line.)
                    setFailure(failed.kind === "refused"
                        ? (failed.reply.reason ?? "The reply could not be sent.")
                        : failed.kind === "rejected"
                          ? failed.message
                          : "The reply could not be sent.");
                },
                onSettled: () => {
                    inFlight.current = false;
                },
            },
        );
    }

    /**
     * The manual way out of the LOCAL ambiguous lock (server-derived locks are unaffected), offered
     * only once the conversation has been read back after the ambiguous moment.
     *
     * Named for what it is: a NEW operator decision, not a retry. It CLEARS the draft, so whatever
     * is typed next is plainly a different message — there is no path here that resends the
     * uncertain body, with its id or with a new one.
     */
    function startNewReply(): void {
        if (! lock.ambiguousDismissible) {
            return;
        }

        setAmbiguousAt(null);
        setFailure(null);
        setCheckFailed(false);
        setDraft("");
    }

    /* Offered only when the server says so; unusable while anything could be sending or unsettled. */
    const draftOffered = draftCapability?.enabled === true;
    const canDraft = draftOffered && canType && lock.reason === null && ! draftMutation.isPending;

    /**
     * The button. If the operator already wrote something, ask FIRST — before spending a model call
     * whose answer would then be thrown away.
     */
    function requestDraft(): void {
        if (! canDraft || draftInFlight.current || inFlight.current) {
            return;
        }

        if (draft.trim() !== "") {
            setDraftNotice(null);
            setConfirmReplace(true);
            return;
        }

        startDraft();
    }

    function startDraft(): void {
        setConfirmReplace(false);

        if (! canDraft || draftInFlight.current || inFlight.current) {
            return;
        }

        draftInFlight.current = true;
        setDraftNotice(null);

        // The state the answer is allowed to replace. Anything else by then is the operator's.
        const startedWith = draft;
        const startedAfterSends = sendAttempts.current;

        draftMutation.mutate(undefined, {
            onSuccess: (data) => {
                const text = draftTextFrom(data);

                if (text === null) {
                    setDraftNotice({ tone: "error", text: "The AI draft could not be generated. Try again." });
                    return;
                }

                if (latestDraft.current !== startedWith || sendAttempts.current !== startedAfterSends) {
                    setDraftNotice({ tone: "info", text: "Draft not applied because you edited the reply." });
                    return;
                }

                // Plain text into the textarea — nothing else. The operator reviews it and sends it.
                setDraft(text);
                setFailure(null);
                setDraftNotice({ tone: "info", text: "AI draft added. Review and edit it before sending." });
            },
            onError: (error) => {
                setDraftNotice({ tone: "error", text: draftErrorMessage(error) });
            },
            onSettled: () => {
                draftInFlight.current = false;
            },
        });
    }

    const disabledReason = capability.enabled ? null : disabledCopy(capability.reason);

    const showLockNotice = unresolvedList.length > 0 || lock.reason === "pending_elsewhere" || lock.reason === "ambiguous";

    return (
        <div className="border-t border-border bg-card px-4 py-3">
            {/*
              * The lock notices sit OUTSIDE the disabled/failure ternary below, so they still show —
              * and resolving still works — when outbound is disabled or the window has closed.
              */}
            {showLockNotice ? (
                <div
                    id={lockNoticeId}
                    role="status"
                    className="mb-2 flex flex-col gap-2 text-xs font-medium text-amber-700 dark:text-amber-400"
                >
                    {unresolvedList.length > 0 ? (
                        <>
                            <p className="flex items-start gap-1.5">
                                <CircleHelp aria-hidden className="mt-0.5 size-3.5 shrink-0" />
                                <span>
                                    We couldn&apos;t confirm whether {unresolvedList.length === 1 ? "this message" : "these messages"} reached
                                    the customer. Check with the customer, then record what happened. Replies are paused until then.
                                </span>
                            </p>
                            <ul className="flex flex-col gap-1.5">
                                {unresolvedList.map((entry) => {
                                    const body = messages.find((message) => message.id === entry.id)?.body ?? null;

                                    return (
                                        <li
                                            key={entry.id}
                                            className="flex flex-wrap items-center gap-2 rounded-lg border border-border bg-muted px-2.5 py-1.5 text-card-foreground"
                                        >
                                            <span className="min-w-0 flex-1 truncate font-normal">
                                                {body ? body : (
                                                    <span className="italic text-muted-foreground">
                                                        An earlier message{entry.createdAt ? ` · ${formatStamp(entry.createdAt)}` : ""}
                                                    </span>
                                                )}
                                            </span>
                                            {canResolve ? (
                                                <span className="flex shrink-0 gap-1.5">
                                                    <Button type="button" variant="outline" size="xs" onClick={() => openResolve(entry, "sent")}>
                                                        Mark as sent
                                                    </Button>
                                                    <Button type="button" variant="outline" size="xs" onClick={() => openResolve(entry, "not_sent")}>
                                                        Mark as not sent
                                                    </Button>
                                                </span>
                                            ) : null}
                                        </li>
                                    );
                                })}
                            </ul>
                            {! canResolve ? (
                                <p className="font-normal text-muted-foreground">
                                    Someone with permission to reply needs to confirm {unresolvedList.length === 1 ? "it" : "them"}.
                                </p>
                            ) : null}
                        </>
                    ) : lock.reason === "pending_elsewhere" ? (
                        <p className="flex flex-wrap items-start gap-1.5">
                            <Clock aria-hidden className="mt-0.5 size-3.5 shrink-0" />
                            <span>Still sending — another reply in this conversation hasn&apos;t settled yet.</span>
                            <Button type="button" variant="ghost" size="xs" onClick={() => void checkAgain()} disabled={checking} className="ml-auto">
                                {checking ? "Checking…" : "Check again"}
                            </Button>
                        </p>
                    ) : (
                        <p className="flex flex-wrap items-start gap-1.5">
                            <CircleHelp aria-hidden className="mt-0.5 size-3.5 shrink-0" />
                            <span>
                                We couldn&apos;t confirm whether your last reply was processed. Check the conversation before
                                sending another reply.
                            </span>
                            <span className="ml-auto flex gap-1.5">
                                <Button type="button" variant="ghost" size="xs" onClick={() => void checkAgain()} disabled={checking}>
                                    {checking ? "Checking…" : "Check again"}
                                </Button>
                                <Button
                                    type="button"
                                    variant="ghost"
                                    size="xs"
                                    onClick={startNewReply}
                                    disabled={! lock.ambiguousDismissible}
                                    aria-label="Discard this draft and start a new reply"
                                >
                                    Start a new reply
                                </Button>
                            </span>
                        </p>
                    )}
                    {checkFailed ? (
                        <p className="font-normal text-destructive">The conversation could not be re-read. Try Check again.</p>
                    ) : null}
                </div>
            ) : null}

            <ResolveOutboundDialog
                conversationId={conversationId}
                target={resolveTarget}
                open={resolveOpen}
                onOpenChange={setResolveOpen}
                onSettled={handleResolved}
            />

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
                aria-describedby={`${counterId}${disabledReason !== null || failure !== null ? ` ${noticeId}` : ""}${showLockNotice ? ` ${lockNoticeId}` : ""}`}
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

                {draftOffered ? (
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        onClick={requestDraft}
                        disabled={! canDraft || confirmReplace}
                        aria-busy={draftMutation.isPending}
                        className="ml-auto"
                    >
                        {draftMutation.isPending ? (
                            <>
                                <Loader2 aria-hidden className="animate-spin" />
                                Drafting…
                            </>
                        ) : (
                            <>
                                <Sparkles aria-hidden />
                                Draft with AI
                            </>
                        )}
                    </Button>
                ) : null}

                {/*
                  * The variant drops to `secondary` when the button cannot be pressed.
                  *
                  * `disabled` remains the authoritative gate — this changes nothing about whether a
                  * click does anything. It is purely legibility: `default` is the brand teal, and
                  * the shared `disabled:opacity-50` still left it reading as a live call to action
                  * against a light card. `secondary` is an existing variant, so no new colour is
                  * introduced, and the inherited `disabled:pointer-events-none` already removes the
                  * hover treatment that would otherwise suggest it is actionable.
                  */}
                <Button
                    type="button"
                    variant={canSend ? "default" : "secondary"}
                    onClick={submit}
                    disabled={! canSend}
                    className={draftOffered ? undefined : "ml-auto"}
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

            {draftOffered && confirmReplace ? (
                <div role="group" aria-label="Replace your text with an AI draft?" className="mt-2 flex flex-wrap items-center gap-2 text-xs">
                    <span className="font-medium text-card-foreground">Replace your text with an AI draft?</span>
                    <span className="ml-auto flex gap-1.5">
                        <Button type="button" variant="outline" size="xs" onClick={startDraft} disabled={! canDraft}>
                            Replace
                        </Button>
                        <Button type="button" variant="ghost" size="xs" onClick={() => setConfirmReplace(false)}>
                            Keep mine
                        </Button>
                    </span>
                </div>
            ) : null}

            {draftOffered && draftNotice !== null ? (
                <p
                    role={draftNotice.tone === "error" ? "alert" : "status"}
                    className={`mt-2 flex items-start gap-1.5 text-xs ${draftNotice.tone === "error" ? "font-medium text-destructive" : "text-muted-foreground"}`}
                >
                    {draftNotice.tone === "error" ? <TriangleAlert aria-hidden className="mt-0.5 size-3.5 shrink-0" /> : <Sparkles aria-hidden className="mt-0.5 size-3.5 shrink-0" />}
                    {draftNotice.text}
                </p>
            ) : null}

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
            ) : failure !== null ? (
                <p id={noticeId} role="alert" className="mt-2 flex items-start gap-1.5 text-xs font-medium text-destructive">
                    <TriangleAlert aria-hidden className="mt-0.5 size-3.5 shrink-0" />
                    {failure}
                </p>
            ) : null}
        </div>
    );
}
