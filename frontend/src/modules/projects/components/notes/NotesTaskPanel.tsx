"use client";

import { NotebookPen } from "lucide-react";
import { XIcon } from "@/core/ui";
import { MD_UP, useMediaQuery, XL_UP } from "../../notes/use-media-query";
import type { NoteTarget } from "../../notes/types";
import {
  useNoteAutosave,
  useNoteHasUnsavedDraft,
} from "../../notes/use-note-autosave";
import { NotesBody, NotesHeader } from "./NotesBody";
import { NotesSheet } from "./NotesSheet";

/**
 * The "Notes" toggle that sits beside Filter in the board toolbar.
 *
 * Carries an unsaved dot when the panel is closed but a draft is pending, so a
 * user who navigated away mid-sentence can see there's something to come back
 * to. Collapses to icon-only on phones, where the label costs more than it says.
 */
export function NotesToggleButton({
  target,
  open,
  onToggle,
}: {
  target: NoteTarget;
  open: boolean;
  onToggle: () => void;
}): React.ReactElement {
  const hasUnsaved = useNoteHasUnsavedDraft(target);

  return (
    <button
      type="button"
      onClick={onToggle}
      aria-pressed={open}
      aria-label="Notes"
      className={`relative flex h-10 items-center gap-2 rounded-lg border bg-surface px-3 text-sm font-bold transition-colors ${
        open ? "border-brand text-ink" : "border-line text-ink-soft hover:text-ink"
      }`}
    >
      <NotebookPen className="h-4 w-4" />
      <span className="hidden sm:inline">Notes</span>
      {hasUnsaved && !open && (
        <span
          className="absolute -right-1 -top-1 h-2.5 w-2.5 rounded-full bg-warning ring-2 ring-surface"
          title="Unsaved changes"
          aria-label="Unsaved changes"
        />
      )}
    </button>
  );
}

/**
 * The notes surface for the task boards.
 *
 * ≥xl there's width to spare, so the panel is a flex sibling that *pushes* the
 * board — the task list is the work surface and must not be covered. Between md
 * and xl the content is too narrow to push, so it becomes an overlay drawer
 * with a scrim. Below md it's the shared bottom sheet.
 */
export function NotesTaskPanel({
  target,
  open,
  onClose,
}: {
  target: NoteTarget;
  open: boolean;
  onClose: () => void;
}): React.ReactElement | null {
  const autosave = useNoteAutosave(target);
  const isXlUp = useMediaQuery(XL_UP);
  const isMdUp = useMediaQuery(MD_UP);

  const close = (): void => {
    autosave.flush();
    onClose();
  };

  if (!open) return null;

  if (!isMdUp) {
    return (
      <NotesSheet
        open={open}
        onClose={onClose}
        autosave={autosave}
        scope={target.scope}
      />
    );
  }

  const header = (
    <NotesHeader
      autosave={autosave}
      scope={target.scope}
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
  );

  // xl and up: an in-flow column. The board shrinks beside it, and the panel
  // sticks so the note stays reachable while the page scrolls — same behaviour
  // as the dashboard's notes rail.
  if (isXlUp) {
    return (
      <aside className="sticky top-4 flex max-h-[calc(100dvh-2rem)] w-[380px] shrink-0 flex-col rounded-xl border border-line bg-surface p-4">
        {header}
        <div className="mt-2 flex min-h-0 flex-1 flex-col">
          <NotesBody
            autosave={autosave}
            scope={target.scope}
            editorClassName="min-h-[16rem] max-h-[60vh]"
          />
        </div>
      </aside>
    );
  }

  // md–lg: overlay drawer.
  return (
    <div className="fixed inset-0 z-40 flex justify-end" role="dialog" aria-modal="true" aria-label="My notes">
      <button
        type="button"
        aria-label="Close notes"
        onClick={close}
        className="absolute inset-0 bg-black/30"
      />
      <aside className="relative flex h-full w-[380px] max-w-[85%] flex-col border-l border-line bg-surface p-4 shadow-xl">
        {header}
        <div className="mt-2 flex min-h-0 flex-1 flex-col">
          <NotesBody autosave={autosave} scope={target.scope} />
        </div>
      </aside>
    </div>
  );
}
