"use client";

import { Lock, NotebookPen } from "lucide-react";
import type { NoteAutosave } from "../../notes/use-note-autosave";
import type { NoteScope } from "../../notes/types";
import { NoteEditor } from "./NoteEditor";
import { SaveStatusBadge } from "./SaveStatusBadge";

export interface NotesBodyProps {
  autosave: NoteAutosave;
  scope: NoteScope;
  /** Extra classes for the scrolling editor area (per-surface height limits). */
  editorClassName?: string;
  alwaysShowToolbar?: boolean;
  autoFocus?: boolean;
}

/**
 * Everything inside a notes surface below its title: the sync banners and the
 * editor. Shared verbatim by the dashboard card, the project sidebar, the task
 * panel, and the mobile sheet, so editing feels identical everywhere.
 */
export function NotesBody({
  autosave,
  scope,
  editorClassName = "",
  alwaysShowToolbar = false,
  autoFocus = false,
}: NotesBodyProps): React.ReactElement {
  const { isLoading, initialContent, conflict, restorableDraft } = autosave;

  if (isLoading || initialContent === null) {
    return (
      <div className="space-y-2 py-2" aria-busy>
        <div className="h-3 w-2/3 animate-pulse rounded bg-black/5" />
        <div className="h-3 w-1/2 animate-pulse rounded bg-black/5" />
        <div className="h-3 w-3/4 animate-pulse rounded bg-black/5" />
      </div>
    );
  }

  return (
    <div className="flex min-h-0 flex-1 flex-col">
      {conflict && (
        <Banner tone="warning">
          <p className="font-bold">This note changed on another device.</p>
          <p className="mt-0.5 text-ink-soft">
            Autosave is paused until you choose which version to keep.
          </p>
          <div className="mt-2 flex flex-wrap gap-2">
            <BannerButton onClick={() => autosave.resolveConflict("server")}>
              Load latest
            </BannerButton>
            <BannerButton onClick={() => autosave.resolveConflict("mine")}>
              Keep mine
            </BannerButton>
          </div>
        </Banner>
      )}

      {restorableDraft && !conflict && (
        <Banner tone="info">
          <p className="font-bold">Unsaved changes from an earlier session.</p>
          <p className="mt-0.5 text-ink-soft">
            They were written against an older version of this note.
          </p>
          <div className="mt-2 flex flex-wrap gap-2">
            <BannerButton onClick={autosave.restoreDraft}>
              Restore local draft
            </BannerButton>
            <BannerButton onClick={autosave.dismissDraft}>Discard</BannerButton>
          </div>
        </Banner>
      )}

      {autosave.draftsUnavailable && (
        <Banner tone="info">
          <p className="text-ink-soft">
            Offline drafts unavailable — this browser refused local storage.
            Notes still save to the server.
          </p>
        </Banner>
      )}

      <NoteEditor
        // The latest document, not the hydrated one: when this body replaces
        // another editor for the same note (Expand modal, mobile sheet), the
        // new editor must open on the newest text, saved or not.
        initialContent={autosave.getCurrent() ?? initialContent}
        autosave={autosave}
        scope={scope}
        editorClassName={editorClassName}
        alwaysShowToolbar={alwaysShowToolbar}
        autoFocus={autoFocus}
        className="min-h-0 flex-1"
      />
    </div>
  );
}

/** Title row shared by the card and panel shells. */
export function NotesHeader({
  autosave,
  scope,
  actions,
}: {
  autosave: NoteAutosave;
  scope: NoteScope;
  actions?: React.ReactNode;
}): React.ReactElement {
  return (
    // A pale brand band sets the header off from the writing area below without
    // adding a hard rule. `brand-subtle` is the kit's lightest tint, so it reads
    // as a tinted surface rather than a coloured block, and it sits inside the
    // surface's own padding so every shell (card, panel, sheet) gets it right.
    <div className="flex items-center gap-2 rounded-lg bg-brand-subtle px-3 py-2">
      <NotebookPen className="h-3.5 w-3.5 shrink-0 text-brand" aria-hidden />
      <h2 className="text-sm font-bold text-ink">My Notes</h2>
      {scope === "project" && (
        <span
          className="flex items-center gap-1 text-[11px] font-semibold text-ink-faint"
          title="Only you can see notes on this project"
        >
          <Lock className="h-3 w-3" />
          Private to you
        </span>
      )}
      <div className="ml-auto flex items-center gap-2">
        <SaveStatusBadge status={autosave.status} />
        {actions}
      </div>
    </div>
  );
}

function Banner({
  tone,
  children,
}: {
  tone: "warning" | "info";
  children: React.ReactNode;
}): React.ReactElement {
  return (
    <div
      role="status"
      className={`mb-2 rounded-lg border px-3 py-2 text-xs ${
        tone === "warning"
          ? "border-warning/40 bg-warning-soft text-ink"
          : "border-line bg-black/[0.02] text-ink"
      }`}
    >
      {children}
    </div>
  );
}

function BannerButton({
  onClick,
  children,
}: {
  onClick: () => void;
  children: React.ReactNode;
}): React.ReactElement {
  return (
    <button
      type="button"
      onClick={onClick}
      className="rounded-md border border-line bg-surface px-2 py-1 text-xs font-bold text-ink transition-colors hover:border-brand hover:text-brand"
    >
      {children}
    </button>
  );
}
