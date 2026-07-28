"use client";

import { useCallback, useEffect, useRef, useState } from "react";
import { EditorContent, useEditor, type JSONContent } from "@tiptap/react";
import { StarterKit } from "@tiptap/starter-kit";
import { TaskItem, TaskList } from "@tiptap/extension-list";
import { Highlight } from "@tiptap/extension-highlight";
import { CharacterCount, Placeholder } from "@tiptap/extensions";
import { notify } from "@/core/lib/notify";
import { EDITOR_PROSE_CLASSES } from "../../notes/editor-styles";
import type { NoteAutosave } from "../../notes/use-note-autosave";
import type { NoteScope } from "../../notes/types";
import { NoteToolbar } from "./NoteToolbar";

/** Hard ceiling on note length — CharacterCount refuses input past it. */
const CHARACTER_LIMIT = 50_000;

const PLACEHOLDERS: Record<NoteScope, string> = {
  project: "Your private notes for this project — only you can see this…",
  user: "Jot down anything — synced across your dashboard and tasks…",
};

export interface NoteEditorProps {
  autosave: NoteAutosave;
  scope: NoteScope;
  /** The document to mount with — hydration has already chosen server vs draft. */
  initialContent: JSONContent;
  /** Toolbar is revealed on focus by default, keeping ambient surfaces quiet. */
  alwaysShowToolbar?: boolean;
  className?: string;
  /** Applied to the scrolling editor area (e.g. a max height per surface). */
  editorClassName?: string;
  autoFocus?: boolean;
}

/**
 * The rich-text editor itself — identical on every surface (dashboard card,
 * task-view panel, project sidebar, mobile sheet). Only its shell changes.
 *
 * It owns no persistence: every change is handed to the autosave engine, and
 * content pushed back from the engine (conflict resolution, another tab) is
 * applied without re-triggering a save.
 */
export function NoteEditor({
  autosave,
  scope,
  initialContent,
  alwaysShowToolbar = false,
  className = "",
  editorClassName = "",
  autoFocus = false,
}: NoteEditorProps): React.ReactElement {
  const { onChange, flush, setFocused } = autosave;
  const limitWarnedRef = useRef(false);
  const appliedTokenRef = useRef<number | null>(null);
  // Drives the toolbar's reveal-on-focus. Toolbar buttons suppress mousedown,
  // so pressing one never blurs the editor and never hides the bar mid-use.
  const [isFocused, setIsFocused] = useState(autoFocus);

  const editor = useEditor({
    // Tiptap must not render during SSR — the document doesn't exist yet.
    immediatelyRender: false,
    autofocus: autoFocus ? "end" : false,
    extensions: [
      StarterKit.configure({
        heading: { levels: [1, 2, 3] },
        link: { openOnClick: false, autolink: true },
      }),
      TaskList,
      TaskItem.configure({ nested: true }),
      Highlight,
      Placeholder.configure({ placeholder: PLACEHOLDERS[scope] }),
      CharacterCount.configure({ limit: CHARACTER_LIMIT }),
    ],
    content: initialContent,
    editorProps: {
      attributes: {
        role: "textbox",
        "aria-multiline": "true",
        "aria-label": scope === "project" ? "Project notes" : "My notes",
      },
    },
    onUpdate: ({ editor: instance }) => {
      onChange(instance.getJSON());

      const used = instance.storage.characterCount.characters();
      if (used >= CHARACTER_LIMIT) {
        if (!limitWarnedRef.current) {
          limitWarnedRef.current = true;
          notify.warning("Note limit reached", "This note can't grow any further.");
        }
      } else {
        limitWarnedRef.current = false;
      }
    },
    onFocus: () => {
      setFocused(true);
      setIsFocused(true);
    },
    onBlur: () => {
      setFocused(false);
      setIsFocused(false);
      // Leaving the editor with pending work is a flush trigger.
      flush();
    },
  });

  // Content pushed in from outside: a resolved conflict, a restored draft, or
  // another tab's confirmed save. `emitUpdate: false` keeps it from looking
  // like the user typed it (which would mark the note dirty and re-save).
  const { externalContent } = autosave;
  useEffect(() => {
    if (!editor || !externalContent) return;
    if (appliedTokenRef.current === externalContent.token) return;
    appliedTokenRef.current = externalContent.token;
    editor.commands.setContent(externalContent.content, { emitUpdate: false });
  }, [editor, externalContent]);

  // Cmd/Ctrl+S is muscle memory — intercept it so the browser's save dialog
  // never appears, and give the user the "Saved" acknowledgement they expect.
  const onKeyDown = useCallback(
    (event: React.KeyboardEvent) => {
      if ((event.metaKey || event.ctrlKey) && event.key.toLowerCase() === "s") {
        event.preventDefault();
        flush();
      }
    },
    [flush],
  );

  return (
    <div className={`flex min-h-0 flex-col ${className}`} onKeyDown={onKeyDown}>
      {(alwaysShowToolbar || isFocused) && (
        <div className="shrink-0 border-b border-line pb-2">
          <NoteToolbar editor={editor} />
        </div>
      )}

      <div
        className={`min-h-0 flex-1 overflow-y-auto pt-2 ${EDITOR_PROSE_CLASSES} ${editorClassName}`}
      >
        <EditorContent editor={editor} />
      </div>
    </div>
  );
}
