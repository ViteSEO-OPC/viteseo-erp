"use client";

import { useRef, useState } from "react";
import { AxiosError } from "axios";
import {
  ArrowRight,
  EyeOff,
  FileText,
  ShieldCheck,
  ShieldAlert,
  ShieldQuestion,
  UploadCloud,
  User,
  X,
} from "lucide-react";
import { notify as toast } from "@/core/lib/notify";

import { cn } from "@/lib/utils";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { PageHeader } from "@/modules/hr/components/page-header";
import { DataTable, type Column } from "@/core/components/data-table";
import { StatusBadge } from "@/core/components/status-badge";
import { RequestDetailDialog } from "@/modules/hr/components/request-detail-dialog";
import { CancelLeaveDialog } from "@/modules/hr/components/cancel-leave-dialog";
import { formatDate, formatDateTime } from "@/core/lib/format";
import {
  useMyConcerns,
  useCreateConcern,
  useCancelConcern,
  useAcceptConcernReveal,
  useDeclineConcernReveal,
} from "@/modules/hr/hooks/use-concerns";
import {
  CONCERN_CATEGORIES,
  concernCategoryLabel,
  type Concern,
} from "@/modules/hr/types/concern";
import { SimpleSelect } from "@/modules/hr/ui/select";

const MAX_FILES = 5;
const MAX_FILE_BYTES = 10 * 1024 * 1024; // 10 MB — matches the backend rule.
const ACCEPT = ".pdf,.png,.jpg,.jpeg";

export default function MyConcernsPage() {
  return (
    <div className="p-4 sm:p-8">
      <PageHeader
        title="Concerns"
        subtitle="Raise a concern, named or anonymous. Your psychological safety is our top priority."
      />
      <div className="mt-8 grid items-start gap-6 lg:grid-cols-2">
        <RaiseConcernCard />
        <MyConcernsCard />
      </div>
    </div>
  );
}

function RaiseConcernCard() {
  const createMut = useCreateConcern();
  const fileInputRef = useRef<HTMLInputElement>(null);

  const [category, setCategory] = useState<string>(CONCERN_CATEGORIES[0].value);
  const [reportedPerson, setReportedPerson] = useState("");
  const [description, setDescription] = useState("");
  const [anonymous, setAnonymous] = useState(false);
  const [files, setFiles] = useState<File[]>([]);
  const [dragOver, setDragOver] = useState(false);

  function addFiles(incoming: FileList | null) {
    if (!incoming?.length) return;
    const next: File[] = [...files];
    for (const f of Array.from(incoming)) {
      if (next.length >= MAX_FILES) {
        toast.error(`You can attach up to ${MAX_FILES} files.`);
        break;
      }
      if (f.size > MAX_FILE_BYTES) {
        toast.error(`"${f.name}" is larger than 10MB.`);
        continue;
      }
      next.push(f);
    }
    setFiles(next);
  }

  function removeFile(index: number) {
    setFiles((prev) => prev.filter((_, i) => i !== index));
  }

  async function submit() {
    if (!description.trim()) {
      toast.error("Please describe the concern before submitting.");
      return;
    }

    const form = new FormData();
    form.append("category", category);
    form.append("description", description.trim());
    if (reportedPerson.trim()) form.append("reported_person", reportedPerson.trim());
    form.append("is_anonymous", anonymous ? "1" : "0");
    files.forEach((f) => form.append("attachments[]", f));

    try {
      await createMut.mutateAsync(form);
      toast.success(
        "Concern submitted",
        anonymous
          ? "Your name is hidden from colleagues. HR can see it only to deter abuse, and keeps it private."
          : "HR has been notified.",
      );
      setDescription("");
      setReportedPerson("");
      setAnonymous(false);
      setFiles([]);
      setCategory(CONCERN_CATEGORIES[0].value);
    } catch (err) {
      const msg =
        err instanceof AxiosError && err.response?.status === 422
          ? (Object.values(err.response.data?.errors ?? {})[0] as string[])?.[0]
          : undefined;
      toast.error(msg ?? "Could not submit the concern.");
    }
  }

  return (
    <section className="flex flex-col gap-6 rounded-card border border-border bg-card p-6 shadow-sm sm:p-8">
      <div className="flex items-center justify-between gap-3">
        <h2 className="text-h3 font-bold text-foreground">Raise a New Concern</h2>
        <span className="inline-flex items-center gap-1.5 text-sm font-semibold text-brand">
          <ShieldCheck className="size-4" />
          Encrypted &amp; Secure
        </span>
      </div>

      <div className="grid gap-4 sm:grid-cols-2">
        <div className="space-y-1.5">
          <Label htmlFor="cat">Category</Label>
          <SimpleSelect
            id="cat"
            className="h-11"
            value={category}
            onValueChange={setCategory}
            options={CONCERN_CATEGORIES.map((c) => ({ value: c.value, label: c.label }))}
          />
        </div>
        <div className="space-y-1.5">
          <Label htmlFor="reported">Person Being Reported (Optional)</Label>
          <Input
            id="reported"
            value={reportedPerson}
            onChange={(e) => setReportedPerson(e.target.value)}
            placeholder="Name of individual involved (not required)"
          />
          <p className="text-caption text-muted-foreground">
            You are never required to name anyone.
          </p>
        </div>
      </div>

      <div className="space-y-1.5">
        <Label htmlFor="desc">Description</Label>
        <textarea
          id="desc"
          rows={4}
          value={description}
          onChange={(e) => setDescription(e.target.value)}
          placeholder="Describe the concern in detail…"
          className="w-full rounded-lg border border-input bg-transparent px-3 py-2 text-sm outline-none focus-visible:border-ring focus-visible:ring-3 focus-visible:ring-ring/50"
        />
      </div>

      <div className="space-y-2">
        <label
          onDragOver={(e) => {
            e.preventDefault();
            setDragOver(true);
          }}
          onDragLeave={() => setDragOver(false)}
          onDrop={(e) => {
            e.preventDefault();
            setDragOver(false);
            addFiles(e.dataTransfer.files);
          }}
          className={cn(
            "flex cursor-pointer flex-col items-center justify-center gap-2 rounded-lg border border-dashed border-brand bg-brand-subtle px-4 py-6 text-center transition-colors hover:bg-brand-subtle/70",
            dragOver && "bg-brand-subtle/70 ring-2 ring-brand/40",
          )}
        >
          <UploadCloud className="size-6 text-brand" />
          <span className="text-sm font-semibold text-dark-teal">
            Drag &amp; drop supporting documents or click to upload
          </span>
          <span className="text-caption text-muted-foreground">
            PDF, PNG, JPG up to 10MB (metadata will be stripped if anonymous)
          </span>
          <input
            ref={fileInputRef}
            type="file"
            accept={ACCEPT}
            multiple
            className="hidden"
            onChange={(e) => {
              addFiles(e.target.files);
              e.target.value = "";
            }}
          />
        </label>

        {files.length > 0 && (
          <ul className="space-y-1.5">
            {files.map((f, i) => (
              <li
                key={`${f.name}-${i}`}
                className="flex items-center gap-2 rounded-lg border border-border bg-muted/40 px-3 py-2 text-sm"
              >
                <FileText className="size-4 shrink-0 text-muted-foreground" />
                <span className="truncate text-foreground">{f.name}</span>
                <span className="ml-auto shrink-0 text-caption text-muted-foreground">
                  {(f.size / 1024 / 1024).toFixed(1)} MB
                </span>
                <button
                  type="button"
                  onClick={() => removeFile(i)}
                  className="shrink-0 rounded p-0.5 text-muted-foreground transition-colors hover:text-danger"
                  aria-label={`Remove ${f.name}`}
                >
                  <X className="size-4" />
                </button>
              </li>
            ))}
          </ul>
        )}
      </div>

      <label className="flex items-start gap-3 rounded-lg border border-border bg-muted/40 p-4">
        <input
          type="checkbox"
          className="mt-0.5 size-5 shrink-0 rounded border-input accent-brand"
          checked={anonymous}
          onChange={(e) => setAnonymous(e.target.checked)}
        />
        <span className="space-y-0.5">
          <span className="flex items-center gap-1.5 text-sm font-bold text-foreground">
            <EyeOff className="size-3.5 text-muted-foreground" />
            Anonymous
          </span>
          <span className="block text-caption text-muted-foreground">
            Your name is hidden from colleagues, and you can track this concern by its
            reference number. HR can still see who you are to deter abuse and is
            responsible for keeping your identity private.
          </span>
        </span>
      </label>

      <div className="flex flex-col-reverse items-stretch gap-3 sm:flex-row sm:items-center sm:justify-between">
        <p className="text-caption text-muted-foreground">
          All submissions are protected under our Strict Non-Retaliation Policy.
        </p>
        <Button
          onClick={submit}
          disabled={createMut.isPending || !description.trim()}
          className="h-11 shrink-0 gap-2 px-7"
        >
          {createMut.isPending ? "Submitting…" : "Submit Concern"}
          {!createMut.isPending && <ArrowRight className="size-4" />}
        </Button>
      </div>
    </section>
  );
}

/** Backend status, upgraded to "Meeting scheduled" when an invite is live. */
function concernBadge(c: Concern): { status: string; label?: string } {
  if (c.status === "resolved") return { status: "resolved" };
  const hasMeeting = (c.meetings ?? []).some((m) =>
    ["scheduled", "accepted"].includes(m.status),
  );
  if (hasMeeting) return { status: "scheduled", label: "Meeting scheduled" };
  return { status: c.status };
}

function MyConcernsCard() {
  const { data: rows } = useMyConcerns();
  const all = rows ?? [];
  // By id, then read back from the live list, so a status change or a newly
  // scheduled meeting shows up without reopening.
  const [detailId, setDetailId] = useState<number | null>(null);
  const detail = all.find((c) => c.id === detailId) ?? null;
  // The concern being withdrawn — separate state so the confirm sits above the detail.
  const [cancelId, setCancelId] = useState<number | null>(null);
  const cancelTarget = all.find((c) => c.id === cancelId) ?? null;
  const cancelConcernMut = useCancelConcern();

  /** HR is waiting on an answer from this employee about who they are. */
  const awaitingAnswer = (c: Concern) => c.reveal_request?.status === "pending";

  const columns: Column<Concern>[] = [
    {
      header: "Ref",
      cell: (c) => (
        <span className="inline-flex items-center gap-2">
          <span className="font-semibold text-foreground">{c.reference_no}</span>
          {/* The row IS the notification. A pending ask expires by being answered, so it
              needs to be visible without opening anything — but the decision itself lives
              in the detail, where the employee can read what they are answering about. */}
          {awaitingAnswer(c) && (
            <span className="inline-flex items-center gap-1 rounded-full bg-warning px-2 py-0.5 text-[11px] font-semibold text-white">
              <span className="size-1.5 rounded-full bg-white" />
              HR asked
            </span>
          )}
        </span>
      ),
    },
    {
      header: "Category",
      cell: (c) => (
        <span className="inline-flex items-center gap-1.5">
          {c.is_safety && <ShieldAlert className="size-3.5 text-danger" />}
          {concernCategoryLabel(c.category)}
        </span>
      ),
    },
    {
      header: "Visibility",
      cell: (c) => (
        <span className="inline-flex items-center gap-1.5 text-muted-foreground">
          {c.is_anonymous ? <EyeOff className="size-3.5" /> : <User className="size-3.5" />}
          {c.is_anonymous ? "Anonymous" : "Named"}
        </span>
      ),
    },
    {
      header: "Status",
      cell: (c) => {
        const b = concernBadge(c);
        return <StatusBadge status={b.status} label={b.label} />;
      },
    },
    {
      header: "Submitted",
      align: "right",
      cell: (c) => formatDate(c.submitted_at),
    },
  ];

  return (
    <>
      <DataTable
        title="My Concerns"
        columns={columns}
        rows={all}
        rowKey={(c) => c.id}
        onRowClick={(c) => setDetailId(c.id)}
        // Floats a concern HR is waiting on to the top of the list.
        needsAction={awaitingAnswer}
        // Privacy: search on reference/category/visibility/status only — never identity.
        getSearchText={(c) =>
          [
            c.reference_no,
            concernCategoryLabel(c.category),
            c.is_anonymous ? "anonymous" : "named",
            concernBadge(c).label ?? c.status,
          ].join(" ")
        }
        searchPlaceholder="Search concerns…"
        filterable={false}
        empty="You haven't raised any concerns."
      />

      {detail && (
        <RequestDetailDialog
          open
          onOpenChange={(o) => !o && setDetailId(null)}
          title={detail.reference_no}
          status={concernBadge(detail).status}
          statusLabel={concernBadge(detail).label}
          submitted={detail.submitted_at}
          fields={[
            { label: "Category", value: concernCategoryLabel(detail.category) },
            { label: "Person reported", value: detail.reported_person },
            { label: "Filed as", value: detail.is_anonymous ? "Anonymous" : "Named" },
            {
              label: "Resolved",
              value: detail.resolved_at ? formatDateTime(detail.resolved_at) : undefined,
            },
          ]}
          note={{ label: "What you reported", text: detail.description }}
          cancellation={{
            at: detail.cancelled_at,
            by: detail.cancelled_by,
            reason: detail.cancel_reason,
          }}
          actions={
            // Only before HR opens it — once it is under review, withdrawing it silently would
            // strand whatever they have already started.
            detail.status === "pending" ? (
              <Button
                variant="outline"
                className="text-danger hover:text-danger"
                onClick={() => setCancelId(detail.id)}
              >
                Cancel this concern
              </Button>
            ) : undefined
          }
        >
          {detail.attachments.length > 0 && (
            <div className="space-y-1.5">
              <p className="text-label font-semibold uppercase tracking-wide text-brand">
                Files you attached ({detail.attachments.length})
              </p>
              <ul className="space-y-1">
                {detail.attachments.map((a) => (
                  <li key={a.url}>
                    <a
                      href={a.url}
                      target="_blank"
                      rel="noopener noreferrer"
                      className="flex items-center gap-2 rounded-md border border-border px-2.5 py-1.5 text-sm text-foreground transition-colors hover:bg-muted"
                    >
                      <FileText className="size-4 shrink-0 text-muted-foreground" />
                      <span className="truncate">{a.name}</span>
                    </a>
                  </li>
                ))}
              </ul>
            </div>
          )}

          {detail.meetings.length > 0 && (
            <div className="space-y-1.5">
              <p className="text-label font-semibold uppercase tracking-wide text-brand">
                Meeting with HR
              </p>
              <ul className="space-y-1.5">
                {detail.meetings.map((m) => (
                  <li key={m.id} className="rounded-lg border border-border px-3 py-2 text-sm">
                    <p className="font-medium text-foreground">
                      {formatDateTime(m.scheduled_at)}
                    </p>
                    <p className="text-caption text-muted-foreground">
                      {m.location || "Location to follow"}
                      {m.agenda ? ` · ${m.agenda}` : ""}
                    </p>
                  </li>
                ))}
              </ul>
            </div>
          )}

          {detail.is_anonymous && <AnonymityPanel concern={detail} />}
        </RequestDetailDialog>
      )}

      <CancelLeaveDialog
        open={cancelId !== null}
        onOpenChange={(o) => !o && setCancelId(null)}
        title="Cancel Concern"
        subject={cancelTarget ? `concern ${cancelTarget.reference_no}` : undefined}
        onConfirm={async (reason) => {
          if (cancelId === null) return;
          try {
            await cancelConcernMut.mutateAsync({ id: cancelId, reason });
            toast.success("Concern withdrawn");
            setCancelId(null);
            setDetailId(null);
          } catch {
            toast.error("Could not cancel this concern.");
            throw new Error("cancel failed");
          }
        }}
      />
    </>
  );
}

/**
 * HR has asked this employee to identify themselves on an anonymous concern.
 *
 * Lives inside the concern's own detail dialog, reached by clicking the flagged row. The table
 * carries the notice; the decision belongs next to the thing being decided about, so the
 * employee reads what they reported before choosing whether to put their name to it.
 *
 * Both answers are offered with
 * equal weight and neither is styled as the correct one — a decline is a complete answer here,
 * and a UI that made "Yes" the obvious button would quietly undo the protection the whole
 * feature exists to give.
 */
function RevealRequestCard({ concern }: { concern: Concern }) {
  const ask = concern.reveal_request;
  const acceptMut = useAcceptConcernReveal();
  const declineMut = useDeclineConcernReveal();
  const [declining, setDeclining] = useState(false);
  const [reason, setReason] = useState("");
  const busy = acceptMut.isPending || declineMut.isPending;

  if (!ask || ask.status !== "pending") return null;

  async function accept() {
    if (!ask) return;
    try {
      await acceptMut.mutateAsync(ask.id);
      toast.success("HR can now see your name", `Only on ${concern.reference_no}.`);
    } catch {
      toast.error("Could not send your answer.");
    }
  }

  async function decline() {
    if (!ask || !reason.trim()) return;
    try {
      await declineMut.mutateAsync({ id: ask.id, reason: reason.trim() });
      toast.success("You stayed anonymous", "HR sees your reason instead of your name.");
      setDeclining(false);
      setReason("");
    } catch {
      toast.error("Could not send your answer.");
    }
  }

  return (
    <div className="rounded-card border border-warning bg-warning-subtle p-4">
      <div className="flex items-start gap-2">
        <ShieldQuestion className="mt-0.5 size-4 shrink-0 text-warning" />
        <div className="min-w-0 flex-1">
          <p className="text-sm font-semibold text-foreground">
            HR asked to know who filed this
          </p>
          <p className="mt-0.5 text-caption text-muted-foreground">
            {ask.requested_by ?? "HR"} asked on {formatDate(ask.requested_at)}. It&apos;s your
            call — saying no keeps you anonymous and your concern carries on either way.
          </p>
          <p className="mt-2 rounded-lg bg-card px-3 py-2 text-sm text-foreground">
            “{ask.reason}”
          </p>

          {declining ? (
            <div className="mt-3 space-y-2">
              <label htmlFor={`decline-${ask.id}`} className="text-sm font-medium text-foreground">
                Why would you rather stay anonymous?
              </label>
              <textarea
                id={`decline-${ask.id}`}
                rows={2}
                value={reason}
                onChange={(e) => setReason(e.target.value)}
                maxLength={1000}
                className="w-full rounded-lg border border-input bg-card px-2.5 py-1.5 text-sm outline-none focus-visible:border-ring focus-visible:ring-3 focus-visible:ring-ring/50"
                placeholder="HR sees this instead of your name."
              />
              <div className="flex flex-wrap gap-2">
                <Button
                  size="sm"
                  variant="destructive"
                  disabled={busy || !reason.trim()}
                  onClick={decline}
                >
                  {declineMut.isPending ? "Sending…" : "Send my answer"}
                </Button>
                <Button
                  size="sm"
                  variant="outline"
                  disabled={busy}
                  onClick={() => {
                    setDeclining(false);
                    setReason("");
                  }}
                >
                  Back
                </Button>
              </div>
            </div>
          ) : (
            <div className="mt-3 flex flex-wrap gap-2">
              <Button size="sm" disabled={busy} onClick={accept}>
                Let HR see my name
              </Button>
              <Button size="sm" variant="outline" disabled={busy} onClick={() => setDeclining(true)}>
                Stay anonymous
              </Button>
            </div>
          )}
        </div>
      </div>
    </div>
  );
}

/**
 * What the employee is told about their own anonymity, inside the concern detail.
 *
 * The old copy said HR could see them all along. It was accurate and it was the problem: the
 * only assurance on the page was that the protection did not really exist. Now the plain state
 * is the reassuring one, and the exceptions are the ones that need explaining.
 */
function AnonymityPanel({ concern }: { concern: Concern }) {
  const ask = concern.reveal_request;

  if (concern.identity_revealed_at) {
    return (
      <div className="flex items-start gap-2 rounded-lg bg-warning-subtle px-3 py-2 text-xs text-warning">
        <User className="mt-0.5 size-3.5 shrink-0" />
        <span>
          You agreed to be named on this concern on {formatDate(concern.identity_revealed_at)}, so
          HR can see it here. Your other concerns are unaffected.
        </span>
      </div>
    );
  }

  if (ask?.status === "pending") {
    return <RevealRequestCard concern={concern} />;
  }

  if (ask?.status === "declined") {
    return (
      <div className="flex items-start gap-2 rounded-lg border border-border bg-muted px-3 py-2 text-xs text-foreground">
        <EyeOff className="mt-0.5 size-3.5 shrink-0 text-muted-foreground" />
        <span>
          You chose to stay anonymous on {formatDate(ask.responded_at ?? ask.requested_at)}. HR
          still cannot see who you are, and they were given your reason.
        </span>
      </div>
    );
  }

  return (
    <div className="flex items-start gap-2 rounded-lg bg-warning-subtle px-3 py-2 text-xs text-warning">
      <EyeOff className="mt-0.5 size-3.5 shrink-0" />
      <span>
        Filed anonymously. HR cannot see who you are. If they ever need to know, they have to ask
        you here and say why — and you can say no.
      </span>
    </div>
  );
}
