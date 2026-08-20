"use client";

import * as React from "react";

import { EmployeeAvatar } from "@/modules/hr/ui/employee-avatar";
import type { EmployeeRef } from "@/modules/hr/types/employee";
import { StatusBadge } from "@/core/components/status-badge";
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from "@/components/ui/dialog";
import { formatDateTime } from "@/core/lib/format";

export interface DetailField {
  label: string;
  /** Omitted from the list when null, undefined or empty — no blank rows. */
  value: React.ReactNode;
}

/** The em-dash filler for a field that exists but was left blank. */
export const DASH = <span className="text-muted-foreground">—</span>;

function Row({ label, children }: { label: string; children: React.ReactNode }) {
  return (
    <div className="grid grid-cols-[130px_1fr] items-start gap-3 py-2.5">
      <dt className="text-caption text-muted-foreground">{label}</dt>
      <dd className="min-w-0 text-sm break-words text-foreground">{children}</dd>
    </div>
  );
}

/**
 * The label/value list. Exported for the detail modals that can't use the whole shell —
 * concerns, whose header has to stay anonymous — so their fields still read the same.
 */
export function DetailFields({ fields }: { fields: DetailField[] }) {
  const visible = fields.filter(
    (f) => f.value !== null && f.value !== undefined && f.value !== "",
  );
  if (visible.length === 0) return null;

  return (
    <dl className="divide-y divide-border border-y border-border">
      {visible.map((f) => (
        <Row key={f.label} label={f.label}>
          {f.value}
        </Row>
      ))}
    </dl>
  );
}

/** A quoted block for the requester's own words — the reason, message or description. */
export function DetailNote({ label, text }: { label: string; text: string | null }) {
  return (
    <div className="space-y-1.5">
      <p className="text-label font-semibold uppercase tracking-wide text-brand">{label}</p>
      <p className="whitespace-pre-wrap rounded-lg border border-border bg-muted/40 px-3.5 py-3 text-sm text-foreground">
        {text?.trim() || "Not given."}
      </p>
    </div>
  );
}

/** A withdrawal by the person who filed the request. */
export interface RequestCancellation {
  /** When — the block is hidden entirely while this is null. */
  at: string | null;
  /** Who withdrew it. Null on an anonymous concern, where the name is withheld by policy. */
  by?: string | null;
  reason?: string | null;
}

export interface RequestDecision {
  /** Who decided. Absent on older records reviewed before this was tracked. */
  by?: string | null;
  /** When — the block is hidden entirely while this is null, i.e. still undecided. */
  at: string | null;
  /** The approver's or decliner's note. */
  note?: string | null;
  /** Overrides the heading, e.g. "Resolution" for an inquiry. */
  label?: string;
}

/** The full record behind a row in an HR queue. */
export function RequestDetailDialog({
  open,
  onOpenChange,
  title,
  status,
  statusLabel,
  employee,
  submitted,
  fields,
  note,
  decision,
  cancellation,
  children,
  actions,
}: {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  /** What kind of request this is, e.g. "Sick Leave" or "Overtime". */
  title: React.ReactNode;
  status: string;
  statusLabel?: string;
  /**
   * Whose request this is. Omitted on the employee's own screens, where the record is
   * theirs by definition and their own name and avatar would just be noise.
   */
  employee?: EmployeeRef;
  /** created_at — shown as "Filed <date>". */
  submitted?: string;
  fields: DetailField[];
  note?: { label: string; text: string | null };
  decision?: RequestDecision;
  /** Shown instead of nothing when the requester withdrew it rather than HR deciding. */
  cancellation?: RequestCancellation;
  /** Extra sections: receipts, attachments, a resolution, scheduled meetings. */
  children?: React.ReactNode;
  /** The same decisions the row offers, so HR can act without closing this. */
  actions?: React.ReactNode;
}) {
  const name = employee?.name ?? "Unknown";

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="flex max-h-[90svh] max-w-[calc(100%-3rem)] flex-col gap-4 overflow-y-auto sm:max-w-lg">
        <DialogHeader className="mb-0">
          <DialogTitle className="flex flex-wrap items-center gap-2.5 pr-6">
            {title}
            <StatusBadge status={status} label={statusLabel} />
          </DialogTitle>
          <DialogDescription asChild>
            <div className="flex flex-wrap items-center gap-2 text-caption text-muted-foreground">
              {employee && (
                <>
                  <EmployeeAvatar
                    name={name}
                    photoUrl={employee.photo_url}
                    colorSeed={employee.id}
                    size="sm"
                  />
                  <span className="font-medium text-foreground">{name}</span>
                </>
              )}
              {submitted ? (
                <span>
                  {employee ? "· " : ""}Filed {formatDateTime(submitted)}
                </span>
              ) : null}
            </div>
          </DialogDescription>
        </DialogHeader>

        <DetailFields fields={fields} />

        {note && <DetailNote label={note.label} text={note.text} />}

        {decision?.at && (
          <div className="space-y-1.5">
            <p className="text-label font-semibold uppercase tracking-wide text-brand">
              {decision.label ?? "Decision"}
            </p>
            <p className="text-caption text-muted-foreground">
              {decision.by ? `${decision.by} · ` : ""}
              {formatDateTime(decision.at)}
            </p>
            {decision.note?.trim() && (
              <p className="whitespace-pre-wrap rounded-lg border border-border bg-muted/40 px-3.5 py-3 text-sm text-foreground">
                {decision.note}
              </p>
            )}
          </div>
        )}

        {cancellation?.at && (
          <div className="space-y-1.5">
            <p className="text-label font-semibold uppercase tracking-wide text-danger">
              Withdrawn
            </p>
            <p className="text-caption text-muted-foreground">
              {cancellation.by ? `${cancellation.by} · ` : ""}
              {formatDateTime(cancellation.at)}
            </p>
            {cancellation.reason?.trim() && (
              <p className="whitespace-pre-wrap rounded-lg border border-border bg-muted/40 px-3.5 py-3 text-sm text-foreground">
                {cancellation.reason}
              </p>
            )}
          </div>
        )}

        {children}

        {actions && <DialogFooter className="mt-0 flex-wrap">{actions}</DialogFooter>}
      </DialogContent>
    </Dialog>
  );
}
