"use client";

import { useMemo, useState } from "react";
import Link from "next/link";
import { Plus, Zap, Check, Pencil, Trash2, RotateCcw } from "lucide-react";
import {
  DndContext,
  DragOverlay,
  PointerSensor,
  pointerWithin,
  MeasuringStrategy,
  useSensor,
  useSensors,
  useDroppable,
  type DragEndEvent,
  type DragStartEvent,
} from "@dnd-kit/core";
import { notify as toast } from "@/core/lib/notify";

import { cn } from "@/lib/utils";
import { Button } from "@/components/ui/button";
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
import { StatusBadge } from "@/core/components/status-badge";
import { formatDate } from "@/core/lib/format";
import {
  CandidateCard,
  CandidateCardView,
} from "@/modules/hr/components/candidate-card";
import { RequisitionFormDialog } from "@/modules/hr/components/requisition-form-dialog";
import { CandidateFormDialog } from "@/modules/hr/components/candidate-form-dialog";
import { CandidateDetailDialog } from "@/modules/hr/components/candidate-detail-dialog";
import { DeleteConfirmDialog } from "@/modules/hr/components/delete-confirm-dialog";
import { CandidatesTable } from "@/modules/hr/components/candidates-table";
import {
  useRequisitions,
  useCandidates,
  useMoveCandidate,
  useDeleteRequisition,
  useRejectCandidate,
} from "@/modules/hr/hooks/use-recruitment";
import {
  pipelineLabel,
  REJECTION_REASONS,
  type Candidate,
  type Requisition,
} from "@/modules/hr/types/recruitment";

export default function RecruitmentPage() {
  const { data: requisitions } = useRequisitions();
  // undefined = initial (fall back to the busiest req) · null = explicitly
  // deselected (show ALL candidates) · number = a chosen requisition.
  const [selectedId, setSelectedId] = useState<number | null | undefined>();
  const [reqDialog, setReqDialog] = useState(false);
  const [editingReq, setEditingReq] = useState<Requisition | null>(null);
  const [deletingReq, setDeletingReq] = useState<Requisition | null>(null);
  const [candDialog, setCandDialog] = useState(false);
  const [detail, setDetail] = useState<Candidate | null>(null);
  const [activeId, setActiveId] = useState<number | null>(null);
  const [rejecting, setRejecting] = useState<Candidate | null>(null);

  const moveMut = useMoveCandidate();
  const deleteReqMut = useDeleteRequisition();

  function openNewReq() {
    setEditingReq(null);
    setReqDialog(true);
  }

  function openEditReq(req: Requisition) {
    setEditingReq(req);
    setReqDialog(true);
  }

  // Default to the requisition with the most candidates until the user picks one
  // (derived, not an effect — avoids a setState-on-render cascade).
  const defaultReqId = useMemo(() => {
    if (!requisitions || requisitions.length === 0) return undefined;
    return [...requisitions].sort((a, b) => b.candidates_count - a.candidates_count)[0].id;
  }, [requisitions]);
  // null means the user deliberately cleared the selection → show all candidates.
  const activeReqId = selectedId === null ? undefined : (selectedId ?? defaultReqId);

  async function performDeleteReq(req: Requisition) {
    try {
      await deleteReqMut.mutateAsync(req.id);
      if (activeReqId === req.id) setSelectedId(null);
      toast.success("Requisition deleted");
    } catch {
      toast.error("Could not delete the requisition.");
    }
  }

  const reqColumns: Column<Requisition>[] = useMemo(() => [
    {
      header: "Role",
      cell: (r) => (
        <div className="flex items-center gap-2">
          <span className="font-semibold text-foreground">{r.title}</span>
          <span className="rounded-full bg-muted px-2 py-0.5 text-xs font-medium text-muted-foreground">
            {pipelineLabel(r.pipeline)}
          </span>
        </div>
      ),
    },
    { header: "Dept", cell: (r) => r.department?.name ?? "—" },
    { header: "Hiring Mgr", cell: (r) => r.hiring_manager?.name ?? "—" },
    { header: "Openings", cell: (r) => r.openings },
    { header: "Stage", cell: (r) => <StatusBadge status={r.stage} label={r.stage} /> },
    { header: "Opened", cell: (r) => formatDate(r.opened_at), className: "text-muted-foreground" },
    {
      header: "",
      align: "right",
      cell: (r) => (
        <div className="flex justify-end gap-1">
          <Button
            size="icon-sm"
            variant="ghost"
            onClick={(e) => { e.stopPropagation(); openEditReq(r); }}
          >
            <Pencil />
            <span className="sr-only">Edit</span>
          </Button>
          <Button
            size="icon-sm"
            variant="ghost"
            onClick={(e) => { e.stopPropagation(); setDeletingReq(r); }}
          >
            <Trash2 />
            <span className="sr-only">Delete</span>
          </Button>
        </div>
      ),
    },
  ], [openEditReq]);

  // Require an 8px drag before starting, so button clicks aren't treated as drags.
  const sensors = useSensors(
    useSensor(PointerSensor, { activationConstraint: { distance: 8 } }),
  );

  const { data: candidates } = useCandidates(activeReqId);
  const selected = requisitions?.find((r) => r.id === activeReqId);

  const openReqs = (requisitions ?? []).filter((r) => r.stage === "active").length;
  const totalCandidates = (requisitions ?? []).reduce((s, r) => s + r.candidates_count, 0);
  const activeCandidate = (candidates ?? []).find((c) => c.id === activeId) ?? null;

  // Candidates can only be added to an active req; hiring also needs an opening.
  const canAddCandidate = selected?.stage === "active";
  const canHire = selected?.stage === "active" && selected.openings > 0;

  function onDragStart(e: DragStartEvent) {
    setActiveId(Number(e.active.id));
  }

  function onDragEnd(e: DragEndEvent) {
    setActiveId(null);
    const { active, over } = e;
    if (!over) return;
    const candidate = (candidates ?? []).find((c) => c.id === Number(active.id));
    const newStage = String(over.id);
    if (!candidate || candidate.stage === newStage) return;

    // Rejecting opens a dialog (reason + optional email) rather than moving silently.
    if (newStage === "rejected") {
      setRejecting(candidate);
      return;
    }

    moveMut.mutate(
      { id: candidate.id, stage: newStage },
      { onError: () => toast.error("Could not move candidate.") },
    );
  }

  // Reopen a rejected candidate → back to the first pipeline stage.
  function onReopen(c: Candidate) {
    const first = selected?.stages[0]?.key;
    if (!first) return;
    moveMut.mutate(
      { id: c.id, stage: first },
      { onError: () => toast.error("Could not reopen the candidate.") },
    );
  }

  return (
    <div className="p-4 sm:p-8">
      <PageHeader
        title="Recruitment"
        subtitle={`${openReqs} open requisitions · ${totalCandidates} candidates in pipeline`}
        actions={
          <Button onClick={openNewReq}>
            <Plus />
            New requisition
          </Button>
        }
      />

      <div className="mt-6 flex items-start gap-3 rounded-card border border-brand/40 bg-brand-subtle px-4 py-3">
        <span className="mt-0.5 flex size-8 shrink-0 items-center justify-center rounded-lg bg-brand text-brand-foreground">
          <Zap className="size-4" fill="currentColor" />
        </span>
        <p className="text-sm text-foreground">
          <span className="font-semibold">Handoff automation</span> — drag a candidate
          to <span className="font-semibold">Hired</span>, then click{" "}
          <span className="font-semibold">Create draft record</span> to provision their
          employee login + leave balances from the application.
        </p>
      </div>

      {/* Open requisitions */}
      <div className="mt-6">
        <h2 className="mb-3 text-h3 font-bold text-foreground">Open Requisitions</h2>
        <DataTable
          columns={reqColumns}
          rows={requisitions ?? []}
          rowKey={(r) => r.id}
          onRowClick={(r) => setSelectedId(r.id === activeReqId ? null : r.id)}
          rowClassName={(r) => r.id === activeReqId ? "bg-brand-subtle" : undefined}
          pageSize={10}
        />
      </div>

      {/* Candidates — always visible, shows all or filtered by selected req */}
      <DndContext
        sensors={sensors}
        // Key drops off the cursor, not the dragged element's box — a table row is
        // full-width and its box overlaps several columns, which made drops flaky.
        collisionDetection={pointerWithin}
        // Re-measure columns during the drag so horizontal scrolling / layout shifts
        // don't leave stale drop rects.
        measuring={{ droppable: { strategy: MeasuringStrategy.Always } }}
        onDragStart={onDragStart}
        onDragEnd={onDragEnd}
      >
        {selected ? (
          <div className="mt-6">
            <div className="mb-3 flex items-center justify-between">
              <h2 className="text-h3 font-bold text-foreground">Unassigned Candidates · {selected.title}</h2>
              <Button
                disabled={!canAddCandidate}
                title={canAddCandidate ? undefined : "Requisition must be active to add candidates"}
                onClick={() => setCandDialog(true)}
              >
                <Plus />
                Add Candidate
              </Button>
            </div>
            {!canAddCandidate && (
              <p className="mb-3 text-caption text-muted-foreground">
                This requisition is <span className="font-medium">{selected.stage}</span> —
                set it to <span className="font-medium">active</span> (edit it) to add
                candidates or hire.
              </p>
            )}
            {/* Only candidates not yet on the board — dragging one onto a pipeline
                column gives it a stage, so it leaves this table for the kanban. */}
            <CandidatesTable
              candidates={(candidates ?? []).filter((c) => !c.stage)}
              locked={!canAddCandidate}
              emptyMessage="No candidates waiting — everyone's on the board. Add a candidate to place a new one."
              onSelect={setDetail}
            />
          </div>
        ) : (
          <div className="mt-6">
            <div className="mb-3 flex items-center justify-between">
              <h2 className="text-h3 font-bold text-foreground">All Candidates · {(candidates ?? []).length}</h2>
              <span className="text-caption text-muted-foreground">
                Select a requisition to add candidates or use the pipeline
              </span>
            </div>
            <CandidatesTable candidates={candidates ?? []} locked onSelect={setDetail} />
          </div>
        )}

        {/* Pipeline kanban — only when a requisition is selected */}
        {selected && (
          <div className="mt-8">
            <h2 className="mb-3 text-h3 font-bold text-foreground">Pipeline — {selected.title}</h2>
            {/* Horizontal scroll with snap: on a phone one 256px column fills
                most of the width and the next one peeks, so a swipe lands
                cleanly on the following stage instead of drifting between two.
                `overscroll-x-contain` keeps that swipe from triggering the
                browser's back gesture. */}
            <div className="flex snap-x snap-mandatory gap-3 overflow-x-auto overscroll-x-contain pb-2">
              {selected.stages.map(({ key, label }) => {
                const all = (candidates ?? []).filter((c) => c.stage === key);
                if (key === "rejected") {
                  return (
                    <RejectedColumn
                      key={key}
                      label={label}
                      cards={all}
                      onSelect={setDetail}
                      onReopen={onReopen}
                    />
                  );
                }
                if (key === "hired") {
                  const pending = all.filter((c) => !c.hired_employee_id);
                  const graduated = all.length - pending.length;
                  return (
                    <KanbanColumn
                      key={key}
                      stageKey={key}
                      label={label}
                      cards={pending}
                      totalCount={all.length}
                      canHire={canHire}
                      onSelect={setDetail}
                      footer={graduated > 0 ? <GraduatedSummary count={graduated} /> : null}
                    />
                  );
                }
                return (
                  <KanbanColumn
                    key={key}
                    stageKey={key}
                    label={label}
                    cards={all}
                    totalCount={all.length}
                    canHire={canHire}
                    onSelect={setDetail}
                  />
                );
              })}
            </div>
          </div>
        )}

        <DragOverlay>
          {activeCandidate ? (
            <CandidateCardView candidate={activeCandidate} dragging />
          ) : null}
        </DragOverlay>
      </DndContext>

      <RequisitionFormDialog
        open={reqDialog}
        onOpenChange={setReqDialog}
        requisition={editingReq}
      />
      {selected && (
        <CandidateFormDialog
          open={candDialog}
          onOpenChange={setCandDialog}
          requisitionId={selected.id}
        />
      )}

      <CandidateDetailDialog candidate={detail} onOpenChange={(o) => !o && setDetail(null)} />

      <RejectionDialog
        candidate={rejecting}
        onOpenChange={(o) => !o && setRejecting(null)}
      />

      <DeleteConfirmDialog
        open={!!deletingReq}
        onOpenChange={(o) => !o && setDeletingReq(null)}
        title="Delete requisition"
        entityName={deletingReq?.title}
        description="This action cannot be undone. The requisition and its candidate pipeline will be permanently removed."
        onConfirm={() => performDeleteReq(deletingReq!)}
      />
    </div>
  );
}

// Confirm a rejection: capture a reason (+ optional note) and optionally email the
// candidate. Opened when a card is dragged onto the Rejected column.
function RejectionDialog({
  candidate,
  onOpenChange,
}: {
  candidate: Candidate | null;
  onOpenChange: (open: boolean) => void;
}) {
  const rejectMut = useRejectCandidate();
  const [reason, setReason] = useState<string>(REJECTION_REASONS[0]);
  const [note, setNote] = useState("");
  const [sendEmail, setSendEmail] = useState(true);

  function handleOpenChange(open: boolean) {
    if (!open) {
      setReason(REJECTION_REASONS[0]);
      setNote("");
      setSendEmail(true);
    }
    onOpenChange(open);
  }

  async function submit() {
    if (!candidate) return;
    const willEmail = sendEmail && !!candidate.email;
    try {
      await rejectMut.mutateAsync({
        id: candidate.id,
        reason,
        note: note || null,
        send_email: willEmail,
      });
      toast.success(
        `${candidate.name} moved to Rejected`,
        willEmail ? "A rejection email was sent." : undefined,
      );
      handleOpenChange(false);
    } catch {
      toast.error("Could not reject the candidate.");
    }
  }

  return (
    <Dialog open={!!candidate} onOpenChange={handleOpenChange}>
      <DialogContent className="max-h-[90svh] max-w-[calc(100%-3rem)] overflow-y-auto sm:max-w-md">
        {candidate && (
          <>
            <DialogHeader>
              <DialogTitle>Reject {candidate.name}?</DialogTitle>
              <DialogDescription>
                Record a reason, and optionally send a courteous rejection email.
              </DialogDescription>
            </DialogHeader>

            <div className="space-y-3">
              <div className="space-y-1.5">
                <Label htmlFor="rej-reason">Reason</Label>
                <select
                  id="rej-reason"
                  className="h-9 w-full rounded-lg border border-input bg-transparent px-2.5 text-sm outline-none focus-visible:border-ring"
                  value={reason}
                  onChange={(e) => setReason(e.target.value)}
                >
                  {REJECTION_REASONS.map((r) => (
                    <option key={r} value={r}>
                      {r}
                    </option>
                  ))}
                </select>
              </div>
              <div className="space-y-1.5">
                <Label htmlFor="rej-note">Note (optional, internal)</Label>
                <textarea
                  id="rej-note"
                  rows={2}
                  value={note}
                  onChange={(e) => setNote(e.target.value)}
                  placeholder="Kept on the record — not emailed."
                  className="w-full rounded-lg border border-input bg-transparent px-2.5 py-1.5 text-sm outline-none focus-visible:border-ring"
                />
              </div>
              <label
                className={cn(
                  "flex items-center gap-2 text-sm",
                  !candidate.email && "text-muted-foreground",
                )}
              >
                <input
                  type="checkbox"
                  className="size-4 rounded border-input accent-brand"
                  checked={sendEmail && !!candidate.email}
                  disabled={!candidate.email}
                  onChange={(e) => setSendEmail(e.target.checked)}
                />
                Send a courteous rejection email
                {!candidate.email && (
                  <span className="text-caption">(no email on file)</span>
                )}
              </label>
            </div>

            <DialogFooter>
              <DialogClose asChild>
                <Button variant="outline">Cancel</Button>
              </DialogClose>
              <Button variant="destructive" disabled={rejectMut.isPending} onClick={submit}>
                {rejectMut.isPending ? "Rejecting…" : "Reject candidate"}
              </Button>
            </DialogFooter>
          </>
        )}
      </DialogContent>
    </Dialog>
  );
}

// The Rejected column: collapsed by default so a big pile stays out of the way;
// expands into a compact, scrollable list within the fixed-height column.
function RejectedColumn({
  label,
  cards,
  onSelect,
  onReopen,
}: {
  label: string;
  cards: Candidate[];
  onSelect: (c: Candidate) => void;
  onReopen: (c: Candidate) => void;
}) {
  const { setNodeRef, isOver } = useDroppable({ id: "rejected" });
  const [open, setOpen] = useState(false);

  return (
    <div
      ref={setNodeRef}
      className={cn(
        "flex h-[32rem] w-64 shrink-0 snap-start flex-col rounded-card border p-2.5 transition-colors",
        isOver ? "border-danger bg-danger-subtle" : "border-border bg-muted/30",
      )}
    >
      <div className="mb-2 flex shrink-0 items-center justify-between px-1">
        <span className="text-label font-bold uppercase tracking-wide text-muted-foreground">
          {label}
        </span>
        <span className="text-caption text-muted-foreground">{cards.length}</span>
      </div>
      <div className="-mr-1 flex-1 space-y-1.5 overflow-y-auto pr-1">
        {cards.length === 0 ? (
          <p className="px-1 py-6 text-center text-caption text-muted-foreground">
            Drop here to reject
          </p>
        ) : !open ? (
          <button
            onClick={() => setOpen(true)}
            className="w-full rounded-lg border border-dashed border-border py-2 text-caption text-muted-foreground hover:bg-muted"
          >
            Review {cards.length} rejected
          </button>
        ) : (
          <>
            {cards.map((c) => (
              <RejectedRow key={c.id} candidate={c} onSelect={onSelect} onReopen={onReopen} />
            ))}
            <button
              onClick={() => setOpen(false)}
              className="w-full py-1 text-caption text-muted-foreground hover:text-foreground"
            >
              Collapse
            </button>
          </>
        )}
      </div>
    </div>
  );
}

// A compact one-line rejected entry — name + reason, with a reopen action.
function RejectedRow({
  candidate,
  onSelect,
  onReopen,
}: {
  candidate: Candidate;
  onSelect: (c: Candidate) => void;
  onReopen: (c: Candidate) => void;
}) {
  return (
    <div className="flex items-center gap-1.5 rounded-md border border-border bg-card px-2 py-1.5">
      <button onClick={() => onSelect(candidate)} className="min-w-0 flex-1 text-left">
        <p className="truncate text-caption font-medium text-foreground">{candidate.name}</p>
        {candidate.rejection_reason && (
          <p className="truncate text-[0.7rem] text-muted-foreground">
            {candidate.rejection_reason}
          </p>
        )}
      </button>
      <button
        onClick={() => onReopen(candidate)}
        title="Reopen — move back to the first stage"
        className="shrink-0 rounded p-1 text-muted-foreground transition-colors hover:bg-muted hover:text-foreground"
      >
        <RotateCcw className="size-3.5" />
        <span className="sr-only">Reopen</span>
      </button>
    </div>
  );
}

function KanbanColumn({
  stageKey,
  label,
  cards,
  totalCount,
  canHire,
  onSelect,
  footer,
}: {
  stageKey: string;
  label: string;
  cards: Candidate[];
  totalCount: number;
  canHire: boolean;
  onSelect: (c: Candidate) => void;
  footer?: React.ReactNode;
}) {
  const { setNodeRef, isOver } = useDroppable({ id: stageKey });

  return (
    <div
      ref={setNodeRef}
      className={cn(
        // Fixed height so every column lines up; the card list inside scrolls.
        "flex h-[32rem] w-64 shrink-0 snap-start flex-col rounded-card border p-2.5 transition-colors",
        isOver ? "border-brand bg-brand-subtle" : "border-border bg-muted/30",
      )}
    >
      <div className="mb-2 flex shrink-0 items-center justify-between px-1">
        <span className="text-label font-bold uppercase tracking-wide text-muted-foreground">
          {label}
        </span>
        <span className="text-caption text-muted-foreground">{totalCount}</span>
      </div>
      {/* Scrolls when the pile gets tall; -mr/pr keeps the scrollbar off the cards. */}
      <div className="-mr-1 flex-1 space-y-2 overflow-y-auto pr-1">
        {cards.length === 0 && !footer ? (
          <p className="px-1 py-6 text-center text-caption text-muted-foreground">
            Drop here
          </p>
        ) : (
          cards.map((c) => (
            <CandidateCard key={c.id} candidate={c} canHire={canHire} onSelect={onSelect} />
          ))
        )}
        {footer}
      </div>
    </div>
  );
}

// Provisioned hires drop off the board — they live in the Employees directory now.
function GraduatedSummary({ count }: { count: number }) {
  return (
    <Link
      href="/teamora/people/employees"
      className="flex items-center justify-between rounded-lg border border-success/30 bg-success-subtle px-3 py-2 text-caption transition-opacity hover:opacity-80"
    >
      <span className="flex items-center gap-1 font-medium text-success">
        <Check className="size-3.5" />
        {count} hired
      </span>
      <span className="text-brand">View in Employees →</span>
    </Link>
  );
}
