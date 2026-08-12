"use client";

import { useState } from "react";
import { Button, Modal, Textarea } from "@/core/ui";

/**
 * Rejecting a proposal always carries a reason — the author reads it and revises
 * against it, so the backend requires one (min 3 chars) and so do we.
 */
export function RejectReasonDialog({
  open,
  subject,
  isPending,
  error,
  onCancel,
  onConfirm,
}: {
  open: boolean;
  /** What is being rejected, shown in the prompt (a task or sub-task name). */
  subject: string;
  isPending: boolean;
  /** Server-side failure message, if the last attempt failed. */
  error?: string | null;
  onCancel: () => void;
  onConfirm: (reason: string) => void;
}) {
  const [reason, setReason] = useState("");
  const [touched, setTouched] = useState(false);

  // Each open starts from a clean slate — a reason is per-decision. Reset during
  // render (React's recommended pattern), as ConfirmDialog does.
  const [wasOpen, setWasOpen] = useState(open);
  if (open !== wasOpen) {
    setWasOpen(open);
    if (open) {
      setReason("");
      setTouched(false);
    }
  }

  const trimmed = reason.trim();
  const tooShort = trimmed.length < 3;

  const submit = () => {
    setTouched(true);
    if (tooShort) return;
    onConfirm(trimmed);
  };

  return (
    <Modal
      open={open}
      onClose={onCancel}
      title="Reject proposal"
      width={520}
      footer={
        <div className="flex justify-end gap-3">
          <Button variant="outline" onClick={onCancel} disabled={isPending}>
            Cancel
          </Button>
          <Button
            onClick={submit}
            disabled={isPending}
            className="bg-danger text-white hover:bg-danger/90"
          >
            {isPending ? "Rejecting…" : "Reject"}
          </Button>
        </div>
      }
    >
      <p className="text-sm text-ink-soft">
        Rejecting <span className="font-semibold text-ink">{subject}</span>. The
        author keeps it and can revise it, so tell them what needs to change.
      </p>
      <label className="mt-4 block text-sm font-bold text-ink">
        Reason <span className="text-danger">*</span>
      </label>
      <Textarea
        autoFocus
        rows={4}
        className="mt-1.5"
        placeholder="e.g. Duplicate of the link-audit task — fold it into that one."
        value={reason}
        onChange={(e) => setReason(e.target.value)}
      />
      {touched && tooShort && (
        <p className="mt-1 text-sm text-danger">
          Give the author at least a short reason.
        </p>
      )}
      {error && <p className="mt-1 text-sm text-danger">{error}</p>}
    </Modal>
  );
}
