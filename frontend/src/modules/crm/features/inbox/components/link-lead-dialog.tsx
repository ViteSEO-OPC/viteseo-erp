"use client";

import { useRef, useState } from "react";
import { Loader2, X } from "lucide-react";

import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from "@/modules/crm/components/ui/dialog";
import { Badge } from "@/modules/crm/components/ui/badge";
import { Button } from "@/modules/crm/components/ui/button";
import { EmptyState } from "@/modules/crm/components/shared/empty-state/empty-state";
import { ErrorState } from "@/modules/crm/components/shared/error-state/error-state";
import { Pagination } from "@/modules/crm/components/shared/pagination/pagination";
import { SearchInput } from "@/modules/crm/components/shared/search-input/search-input";
import { StatusChip } from "@/modules/crm/components/shared/status-badge/status-chip";
import { useDebouncedValue } from "@/core/use-debounced-value";
import type { LeadStatus } from "@/modules/crm/features/dashboard/types/dashboard-types";
import { useLinkableLeads } from "@/modules/crm/features/inbox/hooks/use-linkable-leads";
import {
    classifyLinkError,
    reconcileLink,
    useLinkLead,
    type LinkReconciliation,
} from "@/modules/crm/features/inbox/hooks/use-link-lead";
import type { LeadPickerCandidate } from "@/modules/crm/features/inbox/types/inbox-types";

/**
 * What the confirmation step offers after something did not simply succeed.
 *
 *  - `retry`   — nothing was linked; sending the SAME link again is safe and is the operator's call.
 *  - `recheck` — we could not read the conversation back; look again before doing anything else.
 *  - `back`    — this choice cannot work; pick another lead.
 *  - `close`   — the conversation's state has been settled by someone else; nothing left to do here.
 */
type NoticeAction = "retry" | "recheck" | "back" | "close";

type Notice = { tone: "error" | "info"; text: string; action: NoticeAction };

const CONFLICT_NOTICE: Notice = {
    tone: "info",
    text: "This conversation is already linked to a different lead.",
    action: "close",
};

const UNVERIFIABLE_NOTICE: Notice = {
    tone: "info",
    text: "This conversation is now linked, but the lead can't be shown here.",
    action: "close",
};

const UNCONFIRMED_NOTICE: Notice = {
    tone: "error",
    text: "The link could not be confirmed, and the conversation is still unlinked. You can try again.",
    action: "retry",
};

const UNREADABLE_NOTICE: Notice = {
    tone: "error",
    text: "We couldn't confirm whether the link was made. Check again before doing anything else.",
    action: "recheck",
};

/** The notice for a re-read that came back linked to someone OTHER than the chosen lead. */
function noticeFor(outcome: Exclude<LinkReconciliation, "linked_to_selected">): Notice {
    switch (outcome) {
        case "linked_to_other":
            return CONFLICT_NOTICE;
        case "linked_unverifiable":
            return UNVERIFIABLE_NOTICE;
        case "still_unlinked":
            return UNCONFIRMED_NOTICE;
    }
}

/** Every picker label goes through here: a lead can legitimately have no business or contact name. */
function businessLabel(candidate: LeadPickerCandidate): string {
    return candidate.businessName?.trim() || "Unnamed business";
}

/** The picker rows are the compact linkable resource, so status is a plain string. StatusChip has a
 *  runtime fallback for anything outside its mapped values, which is why the cast is safe. */
function CandidateMeta({ candidate }: { candidate: LeadPickerCandidate }) {
    return (
        <span className="flex shrink-0 items-center gap-1.5">
            {candidate.isProspect ? <Badge variant="outline">Prospect</Badge> : null}
            <StatusChip status={candidate.status as LeadStatus} />
        </span>
    );
}

/**
 * Link an unlinked conversation to an existing lead: search → choose → confirm → POST → re-read.
 *
 * ONE DIALOG, TWO STEPS. Choosing a lead never posts anything — it only moves this same dialog to a
 * confirmation that names the lead and says plainly that the link is sticky. Keeping both steps in
 * one dialog keeps the chosen lead in view while the operator reads that, and gives the whole flow a
 * single dismissal rule instead of two stacked modals.
 *
 * NOTHING CAN DISMISS IT MID-REQUEST. While the POST — or the re-read that follows an uncertain
 * answer — is running, the controlled `onOpenChange(false)` is ignored (Escape) and pointer dismissal
 * is disabled (clicks on the backdrop), so the operator always sees how it ended.
 *
 * THE SCREEN NEVER CLAIMS A LINK THE SERVER HAS NOT CONFIRMED. A 2xx carrying the endpoint's own
 * contract closes the dialog; an uncertain outcome is resolved by reading the conversation back, and
 * nothing is ever re-sent without a deliberate click.
 *
 * Mounted keyed by conversation id, so moving to another thread starts from a clean slate.
 */
export function LinkLeadDialog({
    conversationId,
    open,
    onOpenChange,
}: {
    conversationId: string;
    open: boolean;
    onOpenChange: (open: boolean) => void;
}) {
    const { mutation, refreshConversation } = useLinkLead(conversationId);

    const [query, setQuery] = useState("");
    const term = useDebouncedValue(query, 300).trim();

    /*
     * The page belongs to the search it was chosen for. Deriving it — rather than resetting it in an
     * effect when the term changes — means a new search can never render one frame on a page
     * number carried over from the previous one.
     */
    const [pageFor, setPageFor] = useState<{ term: string; page: number }>({ term: "", page: 1 });
    const page = pageFor.term === term ? pageFor.page : 1;

    const [step, setStep] = useState<"search" | "confirm">("search");
    const [selected, setSelected] = useState<LeadPickerCandidate | null>(null);
    const [notice, setNotice] = useState<Notice | null>(null);
    const [busy, setBusy] = useState<"linking" | "checking" | null>(null);

    /*
     * `busy` is what the operator sees; this ref is what actually stops a second request. State
     * updates only take effect on the next render, so two clicks inside one frame would both read
     * `busy === null`. The ref is set synchronously before any await.
     */
    const inFlight = useRef(false);

    const candidates = useLinkableLeads(term, page, open && step === "search");
    const rows = candidates.data?.data ?? [];
    const meta = candidates.data?.meta;

    function reset() {
        setStep("search");
        setSelected(null);
        setNotice(null);
    }

    function handleOpenChange(next: boolean) {
        if (!next) {
            // The in-flight guard: Escape and any other close request are ignored mid-request.
            if (inFlight.current) {
                return;
            }
            reset();
        }
        onOpenChange(next);
    }

    function choose(candidate: LeadPickerCandidate) {
        setSelected(candidate);
        setNotice(null);
        setStep("confirm");
    }

    /**
     * Read the conversation back and settle an uncertain outcome from what the SERVER now says.
     * Returns true when the chosen lead is confirmed linked.
     */
    async function settleFromServer(leadId: string): Promise<boolean> {
        const refreshed = await refreshConversation();

        if (!refreshed.fresh) {
            setNotice(UNREADABLE_NOTICE);
            return false;
        }

        const outcome = reconcileLink(refreshed.detail, leadId);

        if (outcome === "linked_to_selected") {
            return true;
        }

        setNotice(noticeFor(outcome));
        return false;
    }

    /** The POST — reached only from the confirmation step's button, never from a keypress. */
    async function submit() {
        if (selected === null || inFlight.current) {
            return;
        }

        inFlight.current = true;
        setBusy("linking");
        setNotice(null);

        let linked = false;

        try {
            await mutation.mutateAsync(selected.id);

            // A 2xx with the endpoint's contract — `changed: false` included, which only means the
            // thread already named this lead. Re-read so the header renders the server's answer.
            await refreshConversation();
            linked = true;
        } catch (error) {
            const failure = classifyLinkError(error);

            if (failure.kind === "unknown") {
                // No reliable answer. Claim nothing; the conversation itself will say.
                setBusy("checking");
                linked = await settleFromServer(selected.id);
            } else {
                switch (failure.status) {
                    case 409:
                        // Linked elsewhere. The 409 body deliberately does not name that lead, so
                        // the re-read is the only source of truth for what the header now shows.
                        setBusy("checking");
                        linked = await settleFromServer(selected.id);
                        break;
                    case 403:
                        // Authority changed since the page loaded; the re-read updates the header.
                        await refreshConversation();
                        setNotice({
                            tone: "error",
                            text: "You no longer have permission to link this conversation.",
                            action: "close",
                        });
                        break;
                    case 404:
                        setNotice({
                            tone: "error",
                            text: "That conversation or lead is no longer available. Go back and choose again.",
                            action: "back",
                        });
                        break;
                    case 422:
                        setNotice({
                            tone: "error",
                            text: "The link request was not accepted. Go back and choose again.",
                            action: "back",
                        });
                        break;
                    case 429:
                        setNotice({
                            tone: "error",
                            text: "Too many attempts. Wait a minute, then try again.",
                            action: "retry",
                        });
                        break;
                }
            }
        } finally {
            inFlight.current = false;
            setBusy(null);
        }

        if (linked) {
            handleOpenChange(false);
        }
    }

    /** Re-read only — for when the conversation itself could not be read back. Never re-posts. */
    async function recheck() {
        if (selected === null || inFlight.current) {
            return;
        }

        inFlight.current = true;
        setBusy("checking");
        setNotice(null);

        let linked = false;

        try {
            linked = await settleFromServer(selected.id);
        } finally {
            inFlight.current = false;
            setBusy(null);
        }

        if (linked) {
            handleOpenChange(false);
        }
    }

    const closeButton = (
        <button
            type="button"
            onClick={() => handleOpenChange(false)}
            disabled={busy !== null}
            aria-label="Close"
            className="flex h-8 w-8 shrink-0 items-center justify-center rounded-full text-muted-foreground transition-colors hover:bg-muted focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring disabled:pointer-events-none disabled:opacity-40"
        >
            <X className="h-4 w-4" />
        </button>
    );

    return (
        <Dialog open={open} onOpenChange={handleOpenChange} disablePointerDismissal={busy !== null}>
            <DialogContent
                showCloseButton={false}
                className="flex h-[520px] max-h-[85dvh] w-full flex-col gap-0 overflow-hidden p-0 sm:max-w-[520px]"
            >
                {step === "search" || selected === null ? (
                    <>
                        <DialogHeader className="shrink-0 px-5 pt-5 pb-0">
                            <div className="flex items-start justify-between gap-4">
                                <div className="flex min-w-0 flex-col gap-1">
                                    <DialogTitle>Link to a lead</DialogTitle>
                                    <DialogDescription>
                                        Search by business, contact, email or phone. Prospects are included.
                                    </DialogDescription>
                                </div>
                                {closeButton}
                            </div>
                        </DialogHeader>

                        <div className="shrink-0 px-5 pt-4">
                            <SearchInput
                                value={query}
                                onChange={setQuery}
                                placeholder="Search leads and prospects…"
                                aria-label="Search leads and prospects"
                                autoFocus
                            />
                        </div>

                        <div
                            className="min-h-0 flex-1 overflow-y-auto px-5 pt-3 pb-4"
                            aria-busy={candidates.isFetching}
                        >
                            {term === "" ? (
                                <EmptyState message="Type a business, contact, email or phone to search." />
                            ) : candidates.isLoading ? (
                                <p role="status" className="flex items-center justify-center gap-2 py-12 text-sm text-muted-foreground">
                                    <Loader2 aria-hidden className="size-4 animate-spin" />
                                    Searching…
                                </p>
                            ) : candidates.isError ? (
                                <ErrorState
                                    message="Leads could not be loaded."
                                    onRetry={() => void candidates.refetch()}
                                />
                            ) : rows.length === 0 ? (
                                <EmptyState message={`No leads match “${term}”.`} />
                            ) : (
                                <ul className="flex flex-col gap-2">
                                    {rows.map((candidate) => (
                                        <li key={candidate.id}>
                                            <button
                                                type="button"
                                                onClick={() => choose(candidate)}
                                                aria-label={`Choose ${businessLabel(candidate)}`}
                                                className="flex w-full items-center gap-3 rounded-lg border border-border bg-card px-3 py-2 text-left transition-colors hover:bg-muted focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                                            >
                                                <span className="min-w-0 flex-1">
                                                    <span className="block truncate text-sm font-medium text-card-foreground">
                                                        {businessLabel(candidate)}
                                                    </span>
                                                    <span className="block truncate text-xs text-muted-foreground">
                                                        {candidate.contactName?.trim() || "No contact name"}
                                                    </span>
                                                </span>
                                                <CandidateMeta candidate={candidate} />
                                            </button>
                                        </li>
                                    ))}
                                </ul>
                            )}
                        </div>

                        {meta && rows.length > 0 ? (
                            <Pagination
                                className="shrink-0 border-t border-border px-5"
                                page={meta.current_page - 1}
                                totalPages={meta.last_page}
                                total={meta.total}
                                perPage={meta.per_page}
                                unit="leads"
                                showControls={meta.last_page > 1}
                                onPageChange={(next) => setPageFor({ term, page: next + 1 })}
                            />
                        ) : null}
                    </>
                ) : (
                    <>
                        <DialogHeader className="shrink-0 px-5 pt-5 pb-0">
                            <div className="flex items-start justify-between gap-4">
                                <DialogTitle className="min-w-0 break-words">
                                    Link this conversation to {businessLabel(selected)}?
                                </DialogTitle>
                                {closeButton}
                            </div>
                        </DialogHeader>

                        <div className="min-h-0 flex-1 overflow-y-auto px-5 pt-4 pb-4">
                            <div className="flex items-center gap-3 rounded-lg border border-border bg-card px-3 py-2">
                                <span className="min-w-0 flex-1">
                                    <span className="block truncate text-sm font-medium text-card-foreground">
                                        {businessLabel(selected)}
                                    </span>
                                    <span className="block truncate text-xs text-muted-foreground">
                                        {selected.contactName?.trim() || "No contact name"}
                                    </span>
                                </span>
                                <CandidateMeta candidate={selected} />
                            </div>

                            <DialogDescription className="mt-4">
                                Future messages from this contact may be associated with this lead.
                            </DialogDescription>
                            <p className="mt-2 text-sm font-medium text-card-foreground">
                                This link can&apos;t be changed or removed in the app yet.
                            </p>

                            <div role="status" aria-live="polite" className="mt-4 min-h-5">
                                {busy === "checking" ? (
                                    <p className="flex items-center gap-2 text-sm text-muted-foreground">
                                        <Loader2 aria-hidden className="size-4 animate-spin" />
                                        Checking the conversation…
                                    </p>
                                ) : notice ? (
                                    <p className={notice.tone === "error" ? "text-sm text-red-500" : "text-sm text-muted-foreground"}>
                                        {notice.text}
                                    </p>
                                ) : null}
                            </div>
                        </div>

                        {/* mx-0/mb-0 cancel the footer's -mx-4/-mb-4, which assume the default p-4
                            panel; this one is p-0, and would otherwise clip the buttons. */}
                        <DialogFooter className="mx-0 mb-0 border-t border-border px-5 py-3">
                            {notice?.action === "close" ? (
                                <Button size="sm" onClick={() => handleOpenChange(false)}>
                                    Close
                                </Button>
                            ) : (
                                <>
                                    <Button
                                        variant="outline"
                                        size="sm"
                                        onClick={() => {
                                            setNotice(null);
                                            setStep("search");
                                        }}
                                        disabled={busy !== null}
                                    >
                                        Back
                                    </Button>
                                    {notice?.action === "back" ? null : notice?.action === "recheck" ? (
                                        <Button size="sm" onClick={() => void recheck()} disabled={busy !== null}>
                                            {busy === "checking" ? "Checking…" : "Check again"}
                                        </Button>
                                    ) : (
                                        <Button size="sm" onClick={() => void submit()} disabled={busy !== null}>
                                            {busy === "linking" ? (
                                                <>
                                                    <Loader2 aria-hidden className="size-3.5 animate-spin" />
                                                    Linking…
                                                </>
                                            ) : notice?.action === "retry" ? (
                                                "Try again"
                                            ) : (
                                                "Link lead"
                                            )}
                                        </Button>
                                    )}
                                </>
                            )}
                        </DialogFooter>
                    </>
                )}
            </DialogContent>
        </Dialog>
    );
}
