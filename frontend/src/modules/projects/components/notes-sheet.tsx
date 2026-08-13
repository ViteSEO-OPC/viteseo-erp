"use client";

import { useEffect, useRef } from "react";
import { XIcon } from "@/core/ui";
import type { NoteAutosave } from "@/modules/projects/hooks/use-note-autosave";
import type { NoteScope } from "@/modules/projects/types/note";
import {
  NotesBody,
  NotesHeader,
} from "@/modules/projects/components/notes-body";
import { cn } from "@/core/lib/utils";
import {
  OVERLAY_ENTER,
  SHEET_FROM_BOTTOM_ENTER,
} from "@/modules/projects/lib/motion";

/**
 * The mobile bottom sheet — one gesture model for all three surfaces.
 *
 * Rich-text editing needs room, and a phone doesn't have it inline, so tapping
 * notes anywhere on mobile opens this instead of expanding a cramped card. The
 * toolbar is pinned above the on-screen keyboard, and closing flushes.
 */
export function NotesSheet({
  open,
  onClose,
  autosave,
  scope,
}: {
  open: boolean;
  onClose: () => void;
  autosave: NoteAutosave;
  scope: NoteScope;
}): React.ReactElement | null {
  const panelRef = useRef<HTMLDivElement>(null);
  const { flush } = autosave;

  // Closing the sheet is a flush trigger (§5.1).
  const close = (): void => {
    flush();
    onClose();
  };

  useEffect(() => {
    if (!open) return;

    const onKeyDown = (event: KeyboardEvent): void => {
      if (event.key === "Escape") {
        flush();
        onClose();
      }
    };
    document.addEventListener("keydown", onKeyDown);

    // Lock the page behind the sheet so a scroll gesture stays in the note.
    const previousOverflow = document.body.style.overflow;
    document.body.style.overflow = "hidden";

    panelRef.current?.focus();

    return () => {
      document.removeEventListener("keydown", onKeyDown);
      document.body.style.overflow = previousOverflow;
    };
  }, [open, onClose, flush]);

  if (!open) return null;

  return (
    <div className="fixed inset-0 z-50 flex items-end" role="dialog" aria-modal="true" aria-label="My notes">
      <button
        type="button"
        aria-label="Close notes"
        onClick={close}
        className={cn("absolute inset-0 bg-black/40", OVERLAY_ENTER)}
      />
      <div
        ref={panelRef}
        tabIndex={-1}
        className={cn(
          "relative flex h-[90dvh] w-full flex-col rounded-t-2xl bg-surface pb-[env(safe-area-inset-bottom)] shadow-2xl outline-none",
          SHEET_FROM_BOTTOM_ENTER,
        )}
      >
        {/* Drag handle — affordance only; tapping the scrim closes. */}
        <div className="flex justify-center pt-2" aria-hidden>
          <span className="h-1 w-10 rounded-full bg-black/15" />
        </div>

        <div className="flex items-center gap-2 px-4 py-3">
          <NotesHeader
            autosave={autosave}
            scope={scope}
            actions={
              <button
                type="button"
                onClick={close}
                aria-label="Close notes"
                className="rounded-md p-1 text-ink-faint transition-colors hover:bg-black/5 hover:text-ink"
              >
                <XIcon className="h-4 w-4" />
              </button>
            }
          />
        </div>

        <div className="flex min-h-0 flex-1 flex-col px-4 pb-4">
          {/* The toolbar stays put while the keyboard is up. */}
          <NotesBody
            autosave={autosave}
            scope={scope}
            alwaysShowToolbar
            autoFocus
            editorClassName="pb-24"
          />
        </div>
      </div>
    </div>
  );
}
