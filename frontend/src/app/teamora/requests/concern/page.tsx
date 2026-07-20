"use client";

import { useState } from "react";
import { Check, Eye, EyeOff } from "lucide-react";
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
} from "@/components/ui/dialog";
import { PageHeader } from "@/core/components/page-header";
import { DataTable, type Column } from "@/core/components/data-table";
import {
  StatusFilter,
  filterByStatus,
  type StatusFilterOption,
} from "@/core/components/status-filter";
import { StatusBadge } from "@/core/components/status-badge";
import { formatDate, formatDateTime } from "@/core/lib/format";
import { useConcerns, useSetConcernStatus, useScheduleMeeting } from "@/modules/hr/hooks/use-concerns";
import { concernCategoryLabel, type Concern } from "@/modules/hr/types/concern";

const CONCERN_STATUS_OPTIONS: StatusFilterOption[] = [
  { value: "all", label: "All" },
  { value: "pending", label: "Pending" },
  { value: "under_review", label: "Under review" },
  { value: "resolved", label: "Resolved" },
];

export default function ConcernsPage() {
  const { data: rows, isLoading, isError } = useConcerns();
  const [selected, setSelected] = useState<Concern | null>(null);
  const [status, setStatus] = useState("all");
  const visible = filterByStatus(rows ?? [], status);

  const columns: Column<Concern>[] = [
    {
      header: "Ref",
      cell: (c) => <span className="font-mono text-xs text-muted-foreground">{c.reference_no}</span>,
    },
    {
      header: "Submitter",
      cell: (c) =>
        c.is_anonymous ? (
          <AnonymousSubmitter name={c.employee?.name ?? null} />
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
            onRowClick={(c) => setSelected(c)}
            needsAction={(c) => c.status !== "resolved"}
            // HR sees the submitter (even anonymous), so name is searchable here — this
            // data is HR-only. The 'anonymous' keyword still matches anonymous concerns.
            getSearchText={(c) =>
              `${c.reference_no} ${concernCategoryLabel(c.category)} ${c.status} ${c.employee?.name ?? ""} ${c.is_anonymous ? "anonymous" : ""}`
            }
            searchPlaceholder="Search ref, category…"
            toolbar={<StatusFilter options={CONCERN_STATUS_OPTIONS} value={status} onChange={setStatus} />}
            empty="No concerns submitted."
          />
        )}
      </div>

      <ConcernDetail concern={selected} onOpenChange={(o) => !o && setSelected(null)} />
    </div>
  );
}

/**
 * Anonymous submitter, masked by default. HR may reveal it (eye toggle) to deter abuse,
 * but must keep it private. The toggle stops row-click propagation so it won't open the
 * detail modal.
 */
function AnonymousSubmitter({ name }: { name: string | null }) {
  const [shown, setShown] = useState(false);
  return (
    <span className="inline-flex items-center gap-2 text-sm">
      <span className="flex flex-col leading-tight">
        <span className={cn("font-semibold", shown ? "text-foreground" : "text-muted-foreground")}>
          {shown ? name ?? "Unknown" : "Anonymous"}
        </span>
        <span className="text-[11px] font-medium text-warning">Keep private</span>
      </span>
      <button
        type="button"
        onClick={(e) => {
          e.stopPropagation();
          setShown((v) => !v);
        }}
        className="text-muted-foreground transition-colors hover:text-foreground"
        aria-label={shown ? "Hide submitter" : "Reveal submitter"}
        title={shown ? "Hide submitter" : "Reveal submitter — keep it private"}
      >
        {shown ? <Eye className="size-4" /> : <EyeOff className="size-4" />}
      </button>
    </span>
  );
}

function ConcernDetail({
  concern,
  onOpenChange,
}: {
  concern: Concern | null;
  onOpenChange: (open: boolean) => void;
}) {
  const statusMut = useSetConcernStatus();
  const scheduleMut = useScheduleMeeting();

  // Inline schedule form, revealed by the "Schedule meeting" toggle.
  const [scheduling, setScheduling] = useState(false);
  const [when, setWhen] = useState("");
  const [location, setLocation] = useState("");
  const [agenda, setAgenda] = useState("");
  // Anonymous submitter stays masked until HR reveals it; re-mask on close.
  const [showName, setShowName] = useState(false);

  function handleOpenChange(open: boolean) {
    if (!open) {
      setScheduling(false);
      setWhen("");
      setLocation("");
      setAgenda("");
      setShowName(false);
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
      <DialogContent className="flex flex-col gap-4 px-6 py-5 sm:max-w-[497px]">
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
                    <span>{showName ? concern.employee?.name ?? "Unknown" : "Anonymous"}</span>
                    <button
                      type="button"
                      onClick={() => setShowName((v) => !v)}
                      className="text-muted-foreground transition-colors hover:text-foreground"
                      aria-label={showName ? "Hide submitter" : "Reveal submitter"}
                      title={showName ? "Hide submitter" : "Reveal submitter — keep it private"}
                    >
                      {showName ? <Eye className="size-3.5" /> : <EyeOff className="size-3.5" />}
                    </button>
                  </>
                ) : (
                  <span>{concern.employee?.name ?? "—"}</span>
                )}
                <span>· {concernCategoryLabel(concern.category)}</span>
              </DialogDescription>
            </DialogHeader>

            {concern.is_anonymous && (
              <div className="flex items-start gap-2 rounded-lg bg-warning-subtle px-3 py-2 text-xs text-warning">
                <EyeOff className="mt-0.5 size-3.5 shrink-0" />
                <span>
                  Submitted anonymously. You can see the submitter to prevent abuse — keep their
                  identity private and don&apos;t disclose it to others.
                </span>
              </div>
            )}

            <div className="whitespace-pre-wrap rounded-lg border border-border bg-muted/40 px-3.5 py-3 text-sm text-foreground">
              {concern.description}
            </div>

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
                  <Input id="m-when" type="datetime-local" value={when} onChange={(e) => setWhen(e.target.value)} />
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
