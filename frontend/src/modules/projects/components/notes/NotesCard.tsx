"use client";

import { useState } from "react";
import { Maximize2 } from "lucide-react";
import { useAuth } from "@/core/auth/AuthProvider";
import { usePersistentState } from "@/core/use-persistent-state";
import { Card, ChevronDownIcon, Modal } from "@/core/ui";
import { MD_UP, useMediaQuery } from "../../notes/use-media-query";
import { plainTextOf, type NoteScope, type NoteTarget } from "../../notes/types";
import { useNoteAutosave } from "../../notes/use-note-autosave";
import { NotesBody, NotesHeader } from "./NotesBody";
import { NotesSheet } from "./NotesSheet";

export interface NotesCardProps {
  target: NoteTarget;
  /** Distinguishes persisted collapse state between surfaces. */
  uiName: string;
  className?: string;
  /** Show the collapse chevron (the project sidebar wants to reclaim space). */
  collapsible?: boolean;
}

/**
 * The persistent "My Notes" card — the dashboard right rail and the project
 * detail sidebar.
 *
 * Desktop/tablet: edit inline, with "Expand" for long-form writing. Mobile: the
 * card collapses to a one-line preview and tapping opens the bottom sheet,
 * because editing rich text in a phone-width card is not worth doing.
 */
export function NotesCard({
  target,
  uiName,
  className = "",
  collapsible = false,
}: NotesCardProps): React.ReactElement {
  const { user } = useAuth();
  const autosave = useNoteAutosave(target);
  const isMdUp = useMediaQuery(MD_UP);

  const [collapsed, setCollapsed] = usePersistentState<boolean>(
    user ? `pmnote:ui:${user.id}:${uiName}` : null,
    false,
  );
  const [expanded, setExpanded] = useState(false);
  const [sheetOpen, setSheetOpen] = useState(false);

  const scope: NoteScope = target.scope;
  const preview = plainTextOf(autosave.getCurrent());

  // Below md the card is a launcher for the sheet, never an editor.
  if (!isMdUp) {
    return (
      <>
        <Card className={`gap-2 p-4 ${className}`}>
          <NotesHeader autosave={autosave} scope={scope} />
          <button
            type="button"
            onClick={() => setSheetOpen(true)}
            className="w-full rounded-lg border border-line px-3 py-2.5 text-left text-sm text-ink-soft transition-colors hover:border-brand"
          >
            <span className="line-clamp-1">
              {preview ||
                (scope === "project"
                  ? "Add a private note for this project…"
                  : "Jot something down…")}
            </span>
          </button>
        </Card>

        <NotesSheet
          open={sheetOpen}
          onClose={() => setSheetOpen(false)}
          autosave={autosave}
          scope={scope}
        />
      </>
    );
  }

  return (
    <>
      <Card className={`gap-2 p-4 ${className}`}>
        <NotesHeader
          autosave={autosave}
          scope={scope}
          actions={
            <>
              {!collapsed && (
                <button
                  type="button"
                  onClick={() => setExpanded(true)}
                  aria-label="Expand notes"
                  title="Expand"
                  className="rounded-md p-1 text-ink-faint transition-colors hover:bg-black/5 hover:text-ink"
                >
                  <Maximize2 className="h-3.5 w-3.5" />
                </button>
              )}
              {collapsible && (
                <button
                  type="button"
                  onClick={() => setCollapsed(!collapsed)}
                  aria-expanded={!collapsed}
                  aria-label={collapsed ? "Expand notes card" : "Collapse notes card"}
                  className="rounded-md p-1 text-ink-faint transition-colors hover:bg-black/5 hover:text-ink"
                >
                  <ChevronDownIcon
                    className={`h-4 w-4 transition-transform ${collapsed ? "-rotate-90" : ""}`}
                  />
                </button>
              )}
            </>
          }
        />

        {collapsed ? (
          <p className="line-clamp-1 text-xs text-ink-faint">
            {preview || "No notes yet."}
          </p>
        ) : (
          // Expanding to the modal unmounts this editor so only one editor per
          // note is ever live; the modal opens on the same in-memory document.
          !expanded && (
            <NotesBody
              autosave={autosave}
              scope={scope}
              editorClassName="min-h-[13rem] max-h-[60vh]"
            />
          )
        )}
      </Card>

      {expanded && (
        <Modal
          open
          onClose={() => {
            autosave.flush();
            setExpanded(false);
          }}
          title="My Notes"
          width={860}
        >
          {/* The editor area is `flex-1 min-h-0` already — giving it a height
              here too (min-h-full) would make it demand the full box PLUS the
              toolbar, overflowing the dialog into a second scrollbar. */}
          <div className="flex h-[70vh] flex-col">
            <NotesBody
              autosave={autosave}
              scope={scope}
              alwaysShowToolbar
              autoFocus
            />
          </div>
        </Modal>
      )}
    </>
  );
}
