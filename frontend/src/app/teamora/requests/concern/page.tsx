"use client";

import { useState } from "react";
import { Check, Clock, Eye, EyeOff, Paperclip, ShieldQuestion, X } from "lucide-react";
import { notify as toast } from "@/core/lib/notify";

import { cn } from "@/lib/utils";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import {
  Dialog,
  DialogContent,
  DialogHeader,
  DialogTitle,
  DialogDescription,
  DialogFooter,
  DialogClose,
} from "@/components/ui/dialog";
import { PageHeader } from "@/modules/hr/components/page-header";
import { DataTable, type Column } from "@/core/components/data-table";
import {
  StatusFilter,
  filterByStatus,
  type StatusFilterOption,
} from "@/core/components/status-filter";
import { StatusBadge } from "@/core/components/status-badge";
import { ReopenAction } from "@/modules/hr/components/reopen-action";
import { DetailFields, DetailNote } from "@/modules/hr/components/request-detail-dialog";
import { formatDate, formatDateTime } from "@/core/lib/format";
import {
  useConcerns,
  useSetConcernStatus,
  useScheduleMeeting,
  useReopenConcern,
  useRequestConcernReveal,
} from "@/modules/hr/hooks/use-concerns";
import { concernCategoryLabel, type Concern } from "@/modules/hr/types/concern";
import { DateTimePicker } from "@/modules/hr/ui/date-time-picker";

const CONCERN_STATUS_OPTIONS: StatusFilterOption[] = [
  { value: "all", label: "All" },
  { value: "pending", label: "Pending" },
  { value: "under_review", label: "Under review" },
  { value: "resolved", label: "Resolved" },
];

export default function ConcernsPage() {
  const { data: rows, isLoading, isError } = useConcerns();
  // Track the id, not the row: the open modal then follows the list as HR marks the
  // concern under review, resolves it or undoes that, instead of freezing on click.
  const [selectedId, setSelectedId] = useState<number | null>(null);
  const [status, setStatus] = useState("all");
  const visible = filterByStatus(rows ?? [], status);
  const selected = (rows ?? []).find((c) => c.id === selectedId) ?? null;
  // The concern HR is asking about. Held at page level so the ask can be started from the
  // list row OR from inside the detail modal without two copies of the dialog.
  const [askTargetId, setAskTargetId] = useState<number | null>(null);
  const askTarget = (rows ?? []).find((c) => c.id === askTargetId) ?? null;
  const setAskTarget = (c: Concern | null) => setAskTargetId(c?.id ?? null);

  const columns: Column<Concern>[] = [
    {
      header: "Ref",
      cell: (c) => <span className="font-mono text-xs text-muted-foreground">{c.reference_no}</span>,
    },
    {
      header: "Submitter",
      cell: (c) =>
        c.is_anonymous ? (
          <AnonymousSubmitter concern={c} onAsk={() => setAskTarget(c)} />
        ) : (
          <span className="text-sm font-semibold text-foreground">{c.employee?.name ?? "—"}</span>
        ),
    },
    {
      header: "Category",
      cell: (c) => (
        <span className="inline-flex items-center gap-2 text-sm text-foreground">
          {c.is_safety && <span className="size-2 shrink-0 rounded-full bg-danger" />}
          {concernCategoryLabel(c.category)}
        </span>
      ),
    },
    { header: "Status", cell: (c) => <StatusBadge status={c.status} /> },
    { header: "Submitted", cell: (c) => formatDate(c.submitted_at) },
  ];

  return (
    <div className="p-4 sm:p-8">
      <PageHeader
        title="Concerns"
        subtitle="This queue is restricted to HR/Admin only. Safety-related concerns feed into the Health & Safety report."
      />

      <div className="mt-8">
        {isLoading ? (
          <p className="text-sm text-muted-foreground">Loading concerns…</p>
        ) : isError ? (
          <p className="text-sm text-destructive">Could not load concerns.</p>
        ) : (
          <DataTable
            columns={columns}
            rows={visible}
            rowKey={(c) => c.id}
            onRowClick={(c) => setSelectedId(c.id)}
            needsAction={(c) => c.status !== "resolved"}
            // `employee.name` is null for an anonymous concern unless its reporter has agreed
            // to be named, so searching by name finds only the concerns HR is entitled to
            // find. The 'anonymous' keyword still matches the rest.
            getSearchText={(c) =>
              `${c.reference_no} ${concernCategoryLabel(c.category)} ${c.status} ${c.employee?.name ?? ""} ${c.is_anonymous ? "anonymous" : ""}`
            }
            searchPlaceholder="Search ref, category…"
            toolbar={<StatusFilter options={CONCERN_STATUS_OPTIONS} value={status} onChange={setStatus} />}
            empty="No concerns submitted."
          />
        )}
      </div>

      <ConcernDetail
        concern={selected}
        onOpenChange={(o) => !o && setSelectedId(null)}
        onAsk={() => selected && setAskTarget(selected)}
      />

      <RevealRequestDialog
        concern={askTarget}
        onOpenChange={(o) => !o && setAskTarget(null)}
      />
    </div>
  );
}

/**
 * HR asking an anonymous reporter to identify themselves.
 *
 * The copy does the work the old eye toggle pretended to do. Pressing this reveals nothing —
 * it sends a question the reporter can refuse, and the refusal is a complete answer. Saying so
 * plainly is what stops HR reading a decline as obstruction.
 */
function RevealRequestDialog({
  concern,
  onOpenChange,
}: {
  concern: Concern | null;
  onOpenChange: (open: boolean) => void;
}) {
  const [reason, setReason] = useState("");
  const askMut = useRequestConcernReveal();
  const empty = reason.trim() === "";

  async function submit() {
    if (!concern || empty) return;
    try {
      await askMut.mutateAsync({ concernId: concern.id, reason: reason.trim() });
      toast.success(
        "Request sent",
        "The reporter decides. You will see their answer here — their name only if they agree.",
      );
      setReason("");
      onOpenChange(false);
    } catch (err) {
      const m = (err as { response?: { data?: { message?: string } } }).response?.data;
      toast.error(m?.message ?? "Could not send the request.");
    }
  }

  return (
    <Dialog
      open={!!concern}
      onOpenChange={(o) => {
        if (!o) setReason("");
        onOpenChange(o);
      }}
    >
      <DialogContent className="max-h-[90svh] max-w-[calc(100%-3rem)] overflow-y-auto sm:max-w-lg">
        {concern && (
          <>
            <DialogHeader>
              <DialogTitle>Ask who filed {concern.reference_no}?</DialogTitle>
              <DialogDescription>
                This sends a request to the reporter — it does not reveal anything. They can
                refuse, and if they do you will see their reason instead of their name.
              </DialogDescription>
            </DialogHeader>

            <div className="space-y-1.5">
              <Label htmlFor="reveal-reason">Why do you need to know?</Label>
              <textarea
                id="reveal-reason"
                rows={3}
                value={reason}
                onChange={(e) => setReason(e.target.value)}
                maxLength={1000}
                className="w-full rounded-lg border border-input bg-transparent px-2.5 py-1.5 text-sm outline-none focus-visible:border-ring focus-visible:ring-3 focus-visible:ring-ring/50"
                placeholder="e.g. We cannot verify the leave records in this report without knowing whose they are."
              />
              <p className="text-xs text-muted-foreground">
                The reporter reads this and decides on it.
              </p>
            </div>

            <div className="flex items-start gap-2 rounded-lg bg-warning-subtle px-3 py-2 text-xs text-warning">
              <ShieldQuestion className="mt-0.5 size-3.5 shrink-0" />
              <span>
                Every request is recorded and the reporter sees who asked. Declining costs them
                nothing and does not stall the case.
              </span>
            </div>

            <DialogFooter>
              <DialogClose asChild>
                <Button variant="outline">Cancel</Button>
              </DialogClose>
              <Button disabled={askMut.isPending || empty} onClick={submit}>
                {askMut.isPending ? "Sending…" : "Send request"}
              </Button>
            </DialogFooter>
          </>
        )}
      </DialogContent>
    </Dialog>
  );
}

/**
 * The submitter cell for an anonymous concern.
 *
 * The eye used to toggle a name the browser already had — the masking was decoration over a
 * payload that carried the identity regardless. Now there is no name to toggle: the button
 * ASKS, and what it shows afterwards is the state of that question. `stopPropagation` keeps a
 * press off the row-click that opens the detail modal.
 */
function AnonymousSubmitter({ concern, onAsk }: { concern: Concern; onAsk: () => void }) {
  const ask = concern.reveal_request;
  const revealed = concern.identity_revealed_at !== null;

  if (revealed) {
    return (
      <span className="inline-flex items-center gap-2 text-sm">
        <span className="flex flex-col leading-tight">
          <span className="font-semibold text-foreground">{concern.employee?.name ?? "Unknown"}</span>
          <span className="text-[11px] font-medium text-warning">Shared with you — keep private</span>
        </span>
        <Eye className="size-4 text-muted-foreground" />
      </span>
    );
  }

  return (
    <span className="inline-flex items-center gap-2 text-sm">
      <span className="flex flex-col leading-tight">
        <span className="font-semibold text-muted-foreground">Anonymous</span>
        {ask?.status === "pending" ? (
          <span className="text-[11px] font-medium text-warning">Waiting on their answer</span>
        ) : ask?.status === "declined" ? (
          <span className="text-[11px] font-medium text-muted-foreground">Declined to be named</span>
        ) : (
          <span className="text-[11px] font-medium text-muted-foreground">Identity protected</span>
        )}
      </span>
      <button
        type="button"
        disabled={ask?.status === "pending"}
        onClick={(e) => {
          e.stopPropagation();
          onAsk();
        }}
        className="text-muted-foreground transition-colors hover:text-foreground disabled:opacity-40 disabled:hover:text-muted-foreground"
        aria-label="Ask the reporter to identify themselves"
        title={
          ask?.status === "pending"
            ? "Already asked — waiting on their answer"
            : "Ask the reporter to identify themselves"
        }
      >
        {ask?.status === "pending" ? <Clock className="size-4" /> : <EyeOff className="size-4" />}
      </button>
    </span>
  );
}

function ConcernDetail({
  concern,
  onOpenChange,
  onAsk,
}: {
  concern: Concern | null;
  onOpenChange: (open: boolean) => void;
  onAsk: () => void;
}) {
  const statusMut = useSetConcernStatus();
  const scheduleMut = useScheduleMeeting();
  const reopenMut = useReopenConcern();

  // Inline schedule form, revealed by the "Schedule meeting" toggle.
  const [scheduling, setScheduling] = useState(false);
  const [when, setWhen] = useState("");
  const [location, setLocation] = useState("");
  const [agenda, setAgenda] = useState("");

  function handleOpenChange(open: boolean) {
    if (!open) {
      setScheduling(false);
      setWhen("");
      setLocation("");
      setAgenda("");
    }
    onOpenChange(open);
  }

  async function setStatus(status: string) {
    if (!concern) return;
    try {
      await statusMut.mutateAsync({ id: concern.id, status });
      toast.success(status === "resolved" ? "Concern resolved" : "Marked under review");
    } catch {
      toast.error("Could not update the concern.");
    }
  }

  async function submitSchedule() {
    if (!concern || !when) return;
    try {
      await scheduleMut.mutateAsync({
        concernId: concern.id,
        input: { scheduled_at: when.replace("T", " "), location: location || null, agenda: agenda || null },
      });
      toast.success(
        "Meeting scheduled",
        concern.is_anonymous
          ? "The submitter will see the invite. Keep their identity private."
          : `${concern.employee?.name} will see the invite.`,
      );
      setScheduling(false);
      setWhen("");
      setLocation("");
      setAgenda("");
    } catch {
      toast.error("Could not schedule the meeting.");
    }
  }

  return (
    <Dialog open={!!concern} onOpenChange={handleOpenChange}>
      <DialogContent className="max-h-[90svh] max-w-[calc(100%-3rem)] overflow-y-auto flex flex-col gap-4 px-6 py-5 sm:max-w-[497px]">
        {concern && (
          <>
            <DialogHeader className="gap-1">
              <DialogTitle className="flex items-center gap-2.5">
                <span className="text-base font-semibold text-foreground">{concern.reference_no}</span>
                <StatusBadge status={concern.status} />
              </DialogTitle>
              <DialogDescription className="flex items-center gap-1.5 text-[13px]">
                {concern.is_anonymous ? (
                  <>
                    <span>
                      {concern.identity_revealed_at
                        ? concern.employee?.name ?? "Unknown"
                        : "Anonymous"}
                    </span>
                    {!concern.identity_revealed_at && (
                      <button
                        type="button"
                        disabled={concern.reveal_request?.status === "pending"}
                        onClick={onAsk}
                        className="text-muted-foreground transition-colors hover:text-foreground disabled:opacity-40"
                        aria-label="Ask the reporter to identify themselves"
                        title={
                          concern.reveal_request?.status === "pending"
                            ? "Already asked — waiting on their answer"
                            : "Ask the reporter to identify themselves"
                        }
                      >
                        {concern.reveal_request?.status === "pending" ? (
                          <Clock className="size-3.5" />
                        ) : (
                          <EyeOff className="size-3.5" />
                        )}
                      </button>
                    )}
                  </>
                ) : (
                  <span>{concern.employee?.name ?? "—"}</span>
                )}
                <span>· {concernCategoryLabel(concern.category)}</span>
              </DialogDescription>
            </DialogHeader>

            {concern.is_anonymous && <AnonymityPanel concern={concern} onAsk={onAsk} />}

            <DetailFields
              fields={[
                // Category is already in the header line, per the Figma.
                { label: "Person reported", value: concern.reported_person },
                { label: "Submitted", value: formatDateTime(concern.submitted_at) },
                {
                  label: "Resolved",
                  value: concern.resolved_at ? formatDateTime(concern.resolved_at) : undefined,
                },
              ]}
            />

            <DetailNote label="What happened" text={concern.description} />

            {concern.attachments.length > 0 && (
              <div className="space-y-1.5">
                <p className="text-label font-semibold uppercase tracking-wide text-brand">
                  Evidence ({concern.attachments.length})
                </p>
                <ul className="space-y-1">
                  {concern.attachments.map((a) => (
                    <li key={a.url}>
                      <a
                        href={a.url}
                        target="_blank"
                        rel="noopener noreferrer"
                        className="flex items-center gap-2 rounded-md border border-border px-2.5 py-1.5 text-sm text-foreground transition-colors hover:bg-muted"
                      >
                        <Paperclip className="size-4 shrink-0 text-muted-foreground" />
                        <span className="truncate">{a.name}</span>
                      </a>
                    </li>
                  ))}
                </ul>
              </div>
            )}

            {concern.meetings.length > 0 && (
              <p className="text-xs text-muted-foreground">
                Meeting set for {formatDateTime(concern.meetings[0].scheduled_at)}
                {concern.meetings[0].location ? ` · ${concern.meetings[0].location}` : ""}.
              </p>
            )}

            {/* Action row — three buttons, matching the Figma. */}
            <div className="flex flex-wrap items-center justify-between gap-2">
              <button
                type="button"
                onClick={() => setScheduling((v) => !v)}
                className={cn(
                  "inline-flex h-10 items-center gap-2 rounded-lg border px-4 text-[13px] font-medium transition-colors",
                  scheduling
                    ? "border-brand bg-brand-subtle text-foreground"
                    : "border-border bg-card text-foreground hover:bg-muted",
                )}
              >
                <span
                  className={cn(
                    "flex size-3.5 items-center justify-center rounded-[3px] border-[1.5px]",
                    scheduling
                      ? "border-brand bg-brand text-brand-foreground"
                      : "border-muted-foreground",
                  )}
                >
                  {scheduling && <Check className="size-2.5" strokeWidth={3.5} />}
                </span>
                Schedule meeting
              </button>

              {concern.status === "pending" && (
                <Button
                  variant="outline"
                  className="h-10 text-[13px]"
                  disabled={statusMut.isPending}
                  onClick={() => setStatus("under_review")}
                >
                  Mark under review
                </Button>
              )}
              {concern.status !== "resolved" && (
                <Button
                  variant="success"
                  className="h-10 text-[13px]"
                  disabled={statusMut.isPending}
                  onClick={() => setStatus("resolved")}
                >
                  Mark resolved
                </Button>
              )}
              {concern.status !== "pending" && (
                <ReopenAction
                  className="h-10 text-[13px]"
                  label={concern.status === "resolved" ? "Undo resolve" : "Back to pending"}
                  successMessage="Concern reopened"
                  subject={`Concern ${concern.reference_no}`}
                  description={
                    concern.status === "resolved"
                      ? `Concern ${concern.reference_no} goes back to under review and the resolved date is cleared.`
                      : `Concern ${concern.reference_no} goes back to pending.`
                  }
                  onReopen={(reason) => reopenMut.mutateAsync({ id: concern.id, reason })}
                />
              )}
            </div>

            {/* Inline schedule form, revealed by the toggle above. */}
            {scheduling && (
              <div className="space-y-3 border-t border-border pt-4">
                <p className="text-xs text-muted-foreground">
                  {concern.is_anonymous
                    ? `The invite attaches to ${concern.reference_no}; the anonymous submitter can accept without revealing who they are.`
                    : `Set a meeting with ${concern.employee?.name}.`}
                </p>
                <div className="space-y-1.5">
                  <Label htmlFor="m-when">Date & time</Label>
                  <DateTimePicker value={when} onChange={setWhen} />
                </div>
                <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                  <div className="space-y-1.5">
                    <Label htmlFor="m-loc">Location / link</Label>
                    <Input id="m-loc" value={location} onChange={(e) => setLocation(e.target.value)} placeholder="Room 2 or link" />
                  </div>
                  <div className="space-y-1.5">
                    <Label htmlFor="m-agenda">Agenda (optional)</Label>
                    <Input id="m-agenda" value={agenda} onChange={(e) => setAgenda(e.target.value)} />
                  </div>
                </div>
                <div className="flex justify-end">
                  <Button size="sm" disabled={scheduleMut.isPending || !when} onClick={submitSchedule}>
                    {scheduleMut.isPending ? "Scheduling…" : "Schedule"}
                  </Button>
                </div>
              </div>
            )}
          </>
        )}
      </DialogContent>
    </Dialog>
  );
}

/**
 * Where the anonymity of a concern is explained to HR, and where the negotiation over it is
 * shown once one has started.
 *
 * Four states, each a different sentence rather than a different shade of the same one: nobody
 * has asked; an ask is waiting; the reporter refused, and here is why in their words; the
 * reporter agreed, and the name above is now real. The refusal is given the most room on
 * purpose — it is the state most likely to be misread as the system failing.
 */
function AnonymityPanel({ concern, onAsk }: { concern: Concern; onAsk: () => void }) {
  const ask = concern.reveal_request;

  if (concern.identity_revealed_at) {
    return (
      <div className="flex items-start gap-2 rounded-lg bg-warning-subtle px-3 py-2 text-xs text-warning">
        <Eye className="mt-0.5 size-3.5 shrink-0" />
        <span>
          The reporter agreed to be identified on {formatDate(concern.identity_revealed_at)}. Keep
          their identity to this case — they gave it for this reason, not generally.
        </span>
      </div>
    );
  }

  if (ask?.status === "pending") {
    return (
      <div className="space-y-2 rounded-lg bg-warning-subtle px-3 py-2 text-xs text-warning">
        <div className="flex items-start gap-2">
          <Clock className="mt-0.5 size-3.5 shrink-0" />
          <span>
            You asked to know who filed this on {formatDate(ask.requested_at)}. They decide — you
            will see their answer here, and their name only if they agree.
          </span>
        </div>
        <p className="pl-5 text-[11px] text-foreground/70">Your reason: “{ask.reason}”</p>
      </div>
    );
  }

  if (ask?.status === "declined") {
    return (
      <div className="space-y-2 rounded-lg border border-border bg-muted px-3 py-2 text-xs">
        <div className="flex items-start gap-2 text-foreground">
          <X className="mt-0.5 size-3.5 shrink-0 text-muted-foreground" />
          <span>
            The reporter declined to be named on {formatDate(ask.responded_at ?? ask.requested_at)}.
            The concern stands and should be handled on what it says.
          </span>
        </div>
        <p className="pl-5 text-[11px] text-muted-foreground">
          Their reason: “{ask.decline_reason}”
        </p>
        <button
          type="button"
          onClick={onAsk}
          className="pl-5 text-[11px] font-medium text-brand hover:underline"
        >
          Ask again
        </button>
      </div>
    );
  }

  return (
    <div className="flex items-start justify-between gap-2 rounded-lg bg-warning-subtle px-3 py-2 text-xs text-warning">
      <span className="flex items-start gap-2">
        <EyeOff className="mt-0.5 size-3.5 shrink-0" />
        <span>
          Submitted anonymously. You cannot see who filed it — if the case genuinely needs their
          identity, ask them and say why.
        </span>
      </span>
      <button
        type="button"
        onClick={onAsk}
        className="shrink-0 font-semibold whitespace-nowrap hover:underline"
      >
        Ask
      </button>
    </div>
  );
}
