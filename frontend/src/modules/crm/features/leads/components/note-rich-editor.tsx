"use client";

import { useEffect, useState } from "react";
import { EditorContent, useEditor, type Editor, type JSONContent } from "@tiptap/react";
import { StarterKit } from "@tiptap/starter-kit";
import { TaskItem, TaskList } from "@tiptap/extension-list";
import { Highlight } from "@tiptap/extension-highlight";
import { CharacterCount, Placeholder } from "@tiptap/extensions";
import { RichTextToolbar } from "@/core/editor/rich-text-toolbar";
import { RICH_TEXT_PROSE_CLASSES } from "@/core/editor/rich-text-styles";
import { hasContent } from "@/modules/crm/features/leads/lib/note-persistence";
import { cn } from "@/modules/crm/lib/utils";

/**
 * Hard ceiling on a CRM internal note, matching `crm_notes.body` (`max:5000` in both
 * StoreNoteRequest and UpdateNoteRequest).
 *
 * CharacterCount measures the document's TEXT, not its JSON, which is the same quantity the server
 * validates — so the editor refuses the 5001st character rather than letting the user compose a note
 * the API will reject.
 */
export const NOTE_CHARACTER_LIMIT = 5000;

/** Below this many characters remaining the counter stops being decoration and starts warning. */
const COUNTER_WARN_AT = 500;

/**
 * The CRM internal-notes rich-text editor.
 *
 * ── What this deliberately is NOT ──
 * Projects' `NoteEditor` is the visual reference, not the implementation. It is bound to
 * `NoteAutosave` — one long-lived document per scope, with versioning, conflict resolution, realtime
 * broadcast and local draft storage. CRM notes are many short append-only notes per lead, each saved
 * by an explicit button. None of that engine applies, so none of it was ported: this component owns
 * no persistence at all and simply hands its editor instance to the caller on demand.
 *
 * ⌘/Ctrl+Enter saves. Enter behaves natively — a new paragraph — because in a rich-text surface
 * Enter is structural, and binding it to submit would make lists and multi-paragraph notes
 * impossible to type.
 */
export function NoteRichEditor({
  initialContent,
  onEditorReady,
  onSubmit,
  onEmptyChange,
  disabled = false,
  placeholder,
  ariaLabel,
  autoFocus = false,
  className,
}: {
  /** Document to mount with. Legacy plain text is converted by the caller via `plainTextToDoc`. */
  initialContent: JSONContent;
  /**
   * Hands the live instance up so the caller can read text/JSON at save time and reset after.
   * Called with `null` on teardown.
   */
  onEditorReady: (editor: Editor | null) => void;
  onSubmit: () => void;
  /**
   * Told whenever the editor crosses between empty and non-empty, so a caller can enable its Save
   * button without mirroring the draft into React state.
   *
   * This exists because the editor instance is referentially STABLE: publishing it upward once (see
   * `onEditorReady`) gives the parent a handle, but nothing about typing re-renders the parent, so a
   * parent that read `editor.getText()` during its own render would read it exactly once — when the
   * composer mounted empty — and never again. That is what disabled the Add-note button forever
   * after the first save. A boolean pushed on transition restores the pre-rich-text behaviour
   * (button live as soon as there is content) with the editor still owning the draft.
   */
  onEmptyChange?: (hasText: boolean) => void;
  disabled?: boolean;
  placeholder?: string;
  ariaLabel: string;
  autoFocus?: boolean;
  className?: string;
}): React.ReactElement {
  // Mirrors the editor's text length into React state purely so the counter re-renders. The editor
  // itself remains the source of truth; this is a projection, never an input.
  const [used, setUsed] = useState(0);
  const [focused, setFocused] = useState(false);

  const editor = useEditor({
    // Tiptap must not render during SSR — there is no document yet.
    immediatelyRender: false,
    // Re-render this component on every transaction.
    //
    // Tiptap v3 defaults this to FALSE, which is the toolbar bug: `RichTextToolbar` reads
    // `editor.isActive(...)` at render, so it can only be right if something re-renders it. The only
    // thing that did was `setUsed(getText().length)` — and bold, italic, underline, strike,
    // highlight, headings and lists all leave the text LENGTH untouched, so React bailed out of the
    // update and every button kept its previous pressed state. Caret moves between formatted and
    // plain text produce no transaction of their own either, so the bar never tracked the selection.
    //
    // Projects' editor does not need this only because its autosave sets a `status` state on every
    // document change, which re-renders it as a side effect. This editor has no autosave to piggyback
    // on, so it asks Tiptap directly.
    shouldRerenderOnTransaction: true,
    autofocus: autoFocus ? "end" : false,
    editable: !disabled,
    extensions: [
      StarterKit.configure({
        heading: { levels: [1, 2, 3] },
        // Autolink still marks pasted URLs; the toolbar exposes no link control (see the toolbar's
        // own note) so a note cannot gain an arbitrary href by hand.
        link: { openOnClick: false, autolink: true },
      }),
      TaskList,
      // `data-type` is hardcoded in TaskItem's renderHTML, but renderHTML is bypassed when an
      // extension supplies a node view — and TaskItem does. Its node view copies only
      // `options.HTMLAttributes` plus declared attributes (just `checked`), so without this the live
      // <li> carries no data-type and every task-item style silently misses.
      TaskItem.configure({ nested: true, HTMLAttributes: { "data-type": "taskItem" } }),
      Highlight,
      Placeholder.configure({ placeholder: placeholder ?? "" }),
      CharacterCount.configure({ limit: NOTE_CHARACTER_LIMIT }),
    ],
    content: initialContent,
    editorProps: {
      attributes: { role: "textbox", "aria-multiline": "true", "aria-label": ariaLabel },
      handleKeyDown: (_view, event) => {
        if ((event.metaKey || event.ctrlKey) && event.key === "Enter") {
          event.preventDefault();
          onSubmit();
          return true;
        }
        return false;
      },
    },
    onCreate: ({ editor: instance }) => setUsed(instance.getText().length),
    onUpdate: ({ editor: instance }) => setUsed(instance.getText().length),
    onFocus: () => setFocused(true),
    onBlur: () => setFocused(false),
  });

  // Publish the instance upward. `onEditorReady` is in the deps rather than held in a ref, which
  // means it MUST be referentially stable — both call sites pass a `useState` setter, which is.
  // An inline arrow here would tear the editor down and republish it on every render.
  useEffect(() => {
    onEditorReady(editor ?? null);
    return () => onEditorReady(null);
  }, [editor, onEditorReady]);

  // `editable` is set at construction, so a later disabled flip (a save in flight) has to be pushed.
  useEffect(() => {
    editor?.setEditable(!disabled);
  }, [editor, disabled]);

  // Derived during render, which is only safe because `shouldRerenderOnTransaction` above guarantees
  // this component re-renders on every transaction — so the value is never stale. `hasContent` is the
  // same predicate the save handlers use, imported rather than re-implemented so the button's enabled
  // state and the decision to send a request can never disagree.
  const hasText = hasContent(editor);

  // Pushed from an effect rather than called inside `onCreate`/`onUpdate`: `onCreate` can fire while
  // the editor is still being constructed during render, and calling a parent's setState from there
  // is a cross-component update during render. An effect defers it to after commit.
  useEffect(() => {
    onEmptyChange?.(hasText);
  }, [hasText, onEmptyChange]);

  const remaining = NOTE_CHARACTER_LIMIT - used;
  const nearLimit = remaining <= COUNTER_WARN_AT;

  return (
    // `min-w-0` for the same reason the notes card carries one: the composer must take its width
    // from the column it sits in, never from the longest thing typed into it.
    <div className={cn("flex min-w-0 flex-col gap-1.5", className)}>
      <div
        className={cn(
          "overflow-hidden rounded-lg border bg-card transition-colors",
          // The ring lives on this wrapper, not the contenteditable: Tiptap's root gets
          // `outline-none`, so focusing it would otherwise show nothing at all.
          focused ? "border-ring ring-2 ring-ring" : "border-border",
          disabled && "opacity-60",
        )}
      >
        <RichTextToolbar
          editor={editor}
          className="border-b border-border-subtle bg-muted/40 px-2 py-1.5"
        />
        <div className={cn("max-h-64 overflow-y-auto px-3.5 py-2.5", RICH_TEXT_PROSE_CLASSES)}>
          <EditorContent editor={editor} />
        </div>
      </div>

      <div className="flex items-center justify-between gap-3 text-xs text-muted-foreground">
        <span>
          <kbd className="rounded border border-border px-1 py-0.5 font-sans text-[11px]">
            {modifierLabel()}
          </kbd>
          <span className="mx-1">+</span>
          <kbd className="rounded border border-border px-1 py-0.5 font-sans text-[11px]">Enter</kbd>
          <span className="ml-1.5">to save</span>
        </span>
        {used > 0 && (
          <span
            className={cn("tabular-nums", nearLimit && "font-medium text-amber-700 dark:text-amber-400")}
          >
            {used.toLocaleString()} / {NOTE_CHARACTER_LIMIT.toLocaleString()}
          </span>
        )}
      </div>
    </div>
  );
}

/**
 * The modifier key to NAME in the hint; the handler accepts both, as the Projects editor and global
 * search do. Read lazily at render on the client only — this component never renders on the server
 * (`immediatelyRender: false`), so there is no snapshot to mismatch.
 */
let cachedModifier: string | null = null;
function modifierLabel(): string {
  if (typeof navigator === "undefined") return "Ctrl";
  cachedModifier ??= /Mac|iPhone|iPad|iPod/.test(navigator.userAgent) ? "⌘" : "Ctrl";
  return cachedModifier;
}
