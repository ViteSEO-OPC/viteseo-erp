"use client";

import { useRef, useState } from "react";
import { useDraggable } from "@dnd-kit/core";
import { Star, Check, UserPlus, Mail } from "lucide-react";
import { notify as toast } from "@/core/lib/notify";

import { cn } from "@/lib/utils";
import { Button } from "@/components/ui/button";
import { Avatar } from "@/core/components/avatar";
import { formatDate } from "@/core/lib/format";
import { CandidateEmailDialog } from "@/modules/hr/components/candidate-email-dialog";
import { useHireCandidate } from "@/modules/hr/hooks/use-recruitment";
import {
  sourceLabel,
  STAGE_EMAIL,
  type Candidate,
} from "@/modules/hr/types/recruitment";

/** Pure visual — reused by the draggable card and the drag overlay. */
export function CandidateCardView({
  candidate,
  footer,
  dragging,
}: {
  candidate: Candidate;
  footer?: React.ReactNode;
  dragging?: boolean;
}) {
  const exp =
    candidate.experience_years != null
      ? `${candidate.experience_years}y · ${sourceLabel(candidate.source)}`
      : sourceLabel(candidate.source);

  return (
    <div
      className={cn(
        "rounded-lg border border-border bg-card p-3 shadow-sm",
        dragging && "rotate-2 shadow-lg",
      )}
    >
      <div className="flex items-center gap-2">
        <Avatar name={candidate.name} colorSeed={candidate.id} size="sm" />
        <div className="min-w-0 flex-1">
          <p className="truncate text-sm font-semibold text-foreground">{candidate.name}</p>
          <p className="truncate text-caption text-muted-foreground">{exp}</p>
        </div>
        {candidate.rating != null && (
          <span className="flex items-center gap-0.5 text-caption text-foreground">
            <Star className="size-3 fill-warning text-warning" />
            {candidate.rating.toFixed(1)}
          </span>
        )}
      </div>
      {(candidate.location || candidate.expected_salary) && (
        <p className="mt-1.5 truncate text-caption text-muted-foreground">
          {[candidate.location, candidate.expected_salary].filter(Boolean).join(" · ")}
        </p>
      )}
      {footer}
    </div>
  );
}

export function CandidateCard({
  candidate,
  canHire = true,
  onSelect,
}: {
  candidate: Candidate;
  /** False when the requisition isn't active or has no openings left. */
  canHire?: boolean;
  onSelect?: (c: Candidate) => void;
}) {
  // Once the draft employee exists, the hire is committed — lock the card.
  const locked = !!candidate.hired_employee_id;
  const { attributes, listeners, setNodeRef, transform, isDragging } = useDraggable({
    id: candidate.id,
    disabled: locked,
  });
  // Track the pointer-down point so dragging the card doesn't also open details.
  const downPos = useRef<{ x: number; y: number } | null>(null);
  const hireMut = useHireCandidate();
  const stageEmail = candidate.stage ? STAGE_EMAIL[candidate.stage] : undefined;
  const [composeOpen, setComposeOpen] = useState(false);

  const style = transform
    ? { transform: `translate3d(${transform.x}px, ${transform.y}px, 0)` }
    : undefined;

  async function onCreateDraft() {
    try {
      await hireMut.mutateAsync(candidate.id);
      toast.success(`${candidate.name} — draft employee record created`);
    } catch {
      toast.error("Could not create the draft record.");
    }
  }

  // "Send X email", or a "sent" marker plus a re-send once it's gone out for this stage.
  // Every one of them opens the compose modal — the template is a starting point, not the
  // message, so nothing is sent without HR seeing it first.
  function emailAction(type: string, label: string) {
    const sent = candidate.emails_sent.includes(type);
    const cap = label.charAt(0).toUpperCase() + label.slice(1);

    if (sent) {
      return (
        <div className="mt-2 space-y-1">
          <p className="flex items-center gap-1 text-caption font-medium text-info">
            <Mail className="size-3.5" />
            {cap} sent
            {type === "assessment" && candidate.assessment_deadline
              ? ` · due ${formatDate(candidate.assessment_deadline)}`
              : ""}
          </p>
          <Button
            size="xs"
            variant="ghost"
            className="h-6 w-full text-caption"
            onPointerDown={(e) => e.stopPropagation()}
            onClick={() => setComposeOpen(true)}
          >
            Re-send or edit
          </Button>
        </div>
      );
    }

    return (
      <Button
        size="xs"
        variant="outline"
        className="mt-2 w-full"
        disabled={!candidate.email}
        title={candidate.email ? undefined : "No email on file"}
        onPointerDown={(e) => e.stopPropagation()}
        onClick={() => setComposeOpen(true)}
      >
        <Mail />
        Compose {label}
      </Button>
    );
  }

  // Hired: provisioning + offer email. Interview stages: their invite email.
  const footer =
    candidate.stage === "hired" ? (
      <>
        {candidate.hired_employee_id ? (
          <p className="mt-2 flex items-center gap-1 text-caption font-medium text-success">
            <Check className="size-3.5" /> Employee record created
          </p>
        ) : canHire ? (
          <Button
            size="xs"
            variant="success"
            className="mt-2 w-full"
            disabled={hireMut.isPending}
            // Don't let a button press start a drag.
            onPointerDown={(e) => e.stopPropagation()}
            onClick={onCreateDraft}
          >
            <UserPlus />
            {hireMut.isPending ? "Creating…" : "Create draft record"}
          </Button>
        ) : (
          <p className="mt-2 text-caption text-muted-foreground">
            No open positions to hire into.
          </p>
        )}
        {emailAction("offer", "offer email")}
      </>
    ) : stageEmail && candidate.stage !== "rejected" ? (
      emailAction(stageEmail.type, stageEmail.label)
    ) : null;

  return (
    <>
      <div
        ref={setNodeRef}
        style={style}
        {...listeners}
        {...attributes}
        onPointerDownCapture={(e) => { downPos.current = { x: e.clientX, y: e.clientY }; }}
        onClick={(e) => {
          // Ignore clicks on the inner action buttons, and any real drag.
          if ((e.target as HTMLElement).closest("button, a")) return;
          const d = downPos.current;
          if (d && Math.hypot(e.clientX - d.x, e.clientY - d.y) < 6) onSelect?.(candidate);
        }}
        className={cn(
          "cursor-pointer touch-none",
          !locked && "cursor-grab active:cursor-grabbing",
          isDragging && "opacity-40",
        )}
      >
        <CandidateCardView candidate={candidate} footer={footer} />
      </div>

      {/* Kept outside the draggable element: a portal still bubbles its events through
          the React tree, so clicks inside the dialog would reach the drag listeners. */}
      {composeOpen && stageEmail && (
        <CandidateEmailDialog
          candidate={candidate}
          type={stageEmail.type}
          open
          onOpenChange={setComposeOpen}
        />
      )}
    </>
  );
}
