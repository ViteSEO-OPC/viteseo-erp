"use client";

import { useRef } from "react";
import { useDraggable } from "@dnd-kit/core";
import { Avatar } from "@/core/components/avatar";
import { StatusBadge } from "@/core/components/status-badge";
import { cn } from "@/lib/utils";
import { sourceLabel, type Candidate } from "@/modules/hr/types/recruitment";

function DraggableTableRow({
  candidate,
  disabled,
  onSelect,
}: {
  candidate: Candidate;
  disabled: boolean;
  onSelect: (c: Candidate) => void;
}) {
  const { attributes, listeners, setNodeRef, transform, isDragging } = useDraggable({
    id: candidate.id,
    disabled,
  });
  // Track the pointer-down point so a real drag doesn't also fire the row click.
  const downPos = useRef<{ x: number; y: number } | null>(null);

  const style = transform
    ? { transform: `translate3d(${transform.x}px, ${transform.y}px, 0)` }
    : undefined;

  return (
    <tr
      ref={setNodeRef}
      style={style}
      {...listeners}
      {...attributes}
      onPointerDownCapture={(e) => { downPos.current = { x: e.clientX, y: e.clientY }; }}
      onClick={(e) => {
        const d = downPos.current;
        if (d && Math.hypot(e.clientX - d.x, e.clientY - d.y) < 6) onSelect(candidate);
      }}
      className={cn(
        "border-b border-border cursor-pointer touch-none",
        !disabled && "cursor-grab active:cursor-grabbing",
        isDragging && "opacity-40",
      )}
    >
      <td className="px-4 py-3">
        <div className="flex items-center gap-2.5">
          <Avatar name={candidate.name} colorSeed={candidate.id} size="sm" />
          <span className="font-semibold text-foreground">{candidate.name}</span>
        </div>
      </td>
      <td className="px-4 py-3 text-muted-foreground">{candidate.email || "—"}</td>
      <td className="px-4 py-3 text-muted-foreground">{sourceLabel(candidate.source)}</td>
      <td className="px-4 py-3 text-muted-foreground">
        {candidate.experience_years != null ? `${candidate.experience_years}y` : "—"}
      </td>
      <td className="px-4 py-3">
        {candidate.stage ? (
          <StatusBadge status={candidate.stage} label={candidate.stage} />
        ) : (
          <span className="rounded-full bg-muted px-2 py-0.5 text-xs font-medium text-muted-foreground">
            Unassigned
          </span>
        )}
      </td>
      <td className="px-4 py-3 text-muted-foreground">
        {candidate.rating != null ? candidate.rating.toFixed(1) : "—"}
      </td>
    </tr>
  );
}

export function CandidatesTable({
  candidates,
  locked,
  emptyMessage,
  onSelect,
}: {
  candidates: Candidate[];
  locked: boolean;
  emptyMessage?: string;
  onSelect: (c: Candidate) => void;
}) {
  if (candidates.length === 0) {
    return (
      <div className="mt-6">
        <h2 className="mb-3 text-h3 font-bold text-foreground">Candidates</h2>
        <p className="rounded-card border border-border bg-card px-4 py-8 text-center text-sm text-muted-foreground">
          {emptyMessage ?? "No candidates yet. Add one to start filling the pipeline."}
        </p>
      </div>
    );
  }

  return (
    <div className="mt-6">
      <div className="mb-3 flex items-center justify-between">
        <h2 className="text-h3 font-bold text-foreground">
          Candidates · {candidates.length}
        </h2>
        {!locked && (
          <span className="text-caption text-muted-foreground">
            Drag a row onto a pipeline column to add it to the board
          </span>
        )}
      </div>
      <div className="overflow-hidden rounded-card border border-border bg-card">
        <table className="w-full text-left text-sm">
          <thead>
            <tr className="border-b border-border text-label uppercase tracking-wide text-muted-foreground">
              <th className="px-4 py-3">Name</th>
              <th className="px-4 py-3">Email</th>
              <th className="px-4 py-3">Source</th>
              <th className="px-4 py-3">Exp</th>
              <th className="px-4 py-3">Stage</th>
              <th className="px-4 py-3">Rating</th>
            </tr>
          </thead>
          <tbody className="divide-y divide-border text-table">
            {candidates.map((c) => (
              <DraggableTableRow
                key={c.id}
                candidate={c}
                disabled={locked || !!c.hired_employee_id}
                onSelect={onSelect}
              />
            ))}
          </tbody>
        </table>
      </div>
    </div>
  );
}
