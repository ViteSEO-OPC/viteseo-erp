"use client";

import type { SaveStatus } from "../../notes/types";

const LABELS: Record<SaveStatus, string> = {
  saved: "Saved",
  dirty: "Unsaved changes",
  saving: "Saving…",
  offline: "Offline — draft kept locally",
  conflict: "Sync conflict",
};

const TONES: Record<SaveStatus, string> = {
  saved: "text-ink-faint",
  dirty: "text-ink-soft",
  saving: "text-ink-soft",
  offline: "text-danger",
  conflict: "text-warning",
};

/**
 * The autosave indicator. "Saved" fades out after a couple of seconds so a
 * quiet editor stays quiet; every other state persists while it applies.
 *
 * Announced politely so a screen-reader user learns their work was stored
 * without the message stealing focus mid-sentence.
 */
export function SaveStatusBadge({
  status,
  className = "",
}: {
  status: SaveStatus;
  className?: string;
}): React.ReactElement {
  return (
    <span
      // Re-keying on the status restarts the fade, so every fresh "Saved"
      // shows before it dissolves. The fade is pure CSS — no timer, no state.
      key={status}
      aria-live="polite"
      className={`flex items-center gap-1.5 text-xs font-semibold ${TONES[status]} ${
        status === "saved"
          ? "animate-out fade-out-0 fill-mode-forwards delay-2000 duration-700"
          : ""
      } ${className}`}
    >
      {status === "dirty" && (
        <span className="h-1.5 w-1.5 rounded-full bg-current" aria-hidden />
      )}
      {status === "saving" && (
        <span className="h-1.5 w-1.5 animate-pulse rounded-full bg-current" aria-hidden />
      )}
      {LABELS[status]}
    </span>
  );
}
