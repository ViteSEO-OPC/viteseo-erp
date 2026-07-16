"use client";

import { FileText } from "lucide-react";

import { Avatar } from "@/core/components/avatar";
import { StatusBadge } from "@/core/components/status-badge";
import {
  Dialog,
  DialogContent,
  DialogHeader,
  DialogTitle,
} from "@/components/ui/dialog";
import { formatDate } from "@/core/lib/format";
import { sourceLabel, type Candidate } from "@/modules/hr/types/recruitment";

function Row({ label, children }: { label: string; children: React.ReactNode }) {
  return (
    <div className="grid grid-cols-[130px_1fr] items-center gap-3 py-2.5">
      <dt className="text-caption text-muted-foreground">{label}</dt>
      <dd className="min-w-0 text-sm text-foreground">{children}</dd>
    </div>
  );
}

const DASH = <span className="text-muted-foreground">—</span>;

export function CandidateDetailDialog({
  candidate,
  onOpenChange,
}: {
  candidate: Candidate | null;
  onOpenChange: (open: boolean) => void;
}) {
  return (
    <Dialog open={!!candidate} onOpenChange={onOpenChange}>
      <DialogContent className="max-w-lg">
        {candidate && (
          <>
            <DialogHeader>
              <DialogTitle className="flex items-center gap-3">
                <Avatar name={candidate.name} colorSeed={candidate.id} size="sm" />
                {candidate.name}
              </DialogTitle>
            </DialogHeader>

            <dl className="divide-y divide-border">
              <Row label="Role">{candidate.requisition?.title ?? DASH}</Row>
              <Row label="Email">{candidate.email || DASH}</Row>
              <Row label="Phone">{candidate.phone || DASH}</Row>
              <Row label="Source">{sourceLabel(candidate.source)}</Row>
              <Row label="Location">{candidate.location || DASH}</Row>
              <Row label="Expected salary">{candidate.expected_salary || DASH}</Row>
              <Row label="Experience">
                {candidate.experience_years != null ? `${candidate.experience_years} years` : DASH}
              </Row>
              <Row label="Stage">
                {candidate.stage ? (
                  <StatusBadge status={candidate.stage} label={candidate.stage} />
                ) : (
                  <span className="rounded-full bg-muted px-2 py-0.5 text-xs font-medium text-muted-foreground">
                    Unassigned
                  </span>
                )}
              </Row>
              <Row label="Scorecard">
                {candidate.rating != null ? `${candidate.rating.toFixed(1)} / 5` : DASH}
              </Row>
              <Row label="Resume">
                {candidate.resume_url ? (
                  <a
                    href={candidate.resume_url}
                    target="_blank"
                    rel="noopener noreferrer"
                    className="flex min-w-0 items-center gap-1.5 font-medium text-brand hover:underline"
                  >
                    <FileText className="size-3.5 shrink-0" />
                    <span className="truncate">
                      {candidate.resume_path?.split("/").pop() ?? "View resume"}
                    </span>
                  </a>
                ) : (
                  DASH
                )}
              </Row>
              <Row label="Interview feedback">{candidate.feedback || DASH}</Row>
              <Row label="Note">{candidate.notes || DASH}</Row>
              <Row label="Stage history">
                {candidate.stage ? `${candidate.stage} · ` : "Added "}
                {formatDate(candidate.created_at)}
              </Row>
            </dl>
          </>
        )}
      </DialogContent>
    </Dialog>
  );
}
