"use client";

import { useEffect, useRef, useState } from "react";
import { EditorContent, useEditor, type Editor } from "@tiptap/react";
import { StarterKit } from "@tiptap/starter-kit";
import { Placeholder } from "@tiptap/extensions";
import { RichTextToolbar, type RichTextControl } from "@/core/editor/rich-text-toolbar";
import { RICH_TEXT_PROSE_CLASSES } from "@/core/editor/rich-text-styles";
import { EMPTY_DOC } from "@/core/editor/tiptap-doc";
import { hasContent } from "@/modules/crm/features/leads/lib/note-persistence";
import { cn } from "@/modules/crm/lib/utils";

/**
 * The formatting an email reply may carry — and exactly what the server accepts
 * (`EmailDocument::NODES` / `::MARKS`): bold, italic, underline, strikethrough, bulleted and numbered
 * lists, plus undo/redo. The toolbar and the editor's schema are kept to the same set, so there is no
 * button for something the send would refuse, and no way to produce it by pasting either.
 */
export const EMAIL_TOOLBAR_CONTROLS: readonly RichTextControl[] = [
  "bold",
  "italic",
  "underline",
  "strike",
  "bulletList",
  "orderedList",
  "undo",
  "redo",
];

/**
 * The Lead Detail email reply composer: a Tiptap editor restricted to email-safe formatting.
 *
 * Deliberately NOT the Internal Notes editor with options: a note is a different document (headings,
 * task lists, highlight, links, a 5000-character limit and a "to save" hint), and email is the
 * stricter case. What IS shared is the toolbar — the one implementation, given the email subset —
 * the prose styles, and the empty-draft predicate, so a reply looks and behaves like a note wherever
 * the two overlap.
 *
 * Like the notes editor it owns no persistence: it hands its instance up and the caller reads the
 * JSON and text at send time. The schema is what keeps it email-safe — headings, quotes, code, rules,
 * hard breaks and links are not registered at all, so pasted HTML carrying them is reduced to plain
 * paragraphs by ProseMirror before it ever reaches the document.
 *
 * ⌘/Ctrl+Enter sends; Enter is a new paragraph, as it is everywhere in a rich-text surface.
 */
export function EmailRichEditor({
  onEditorReady,
  onEmptyChange,
  onSubmit,
  disabled = false,
  placeholder,
  ariaLabel,
  className,
}: {
  /** Receives the live instance (null on teardown). Must be referentially stable — pass a setter. */
  onEditorReady: (editor: Editor | null) => void;
  /** Told when the draft crosses between empty and non-empty. Must be referentially stable. */
  onEmptyChange?: (hasText: boolean) => void;
  onSubmit: () => void;
  disabled?: boolean;
  placeholder?: string;
  ariaLabel: string;
  className?: string;
}): React.ReactElement {
  const [focused, setFocused] = useState(false);

  // Tiptap captures `editorProps` ONCE, when the editor is created, so a keyboard handler that called
  // `onSubmit` directly would call the first render's copy forever — with that render's subject, and
  // before the editor even existed. The ref always holds the latest one.
  const submitRef = useRef(onSubmit);
  useEffect(() => {
    submitRef.current = onSubmit;
  }, [onSubmit]);

  const editor = useEditor({
    immediatelyRender: false,
    // Same reason as the notes editor: the toolbar reads `isActive()` at render, and mark toggles
    // change no text length, so without this the buttons would not track the selection.
    shouldRerenderOnTransaction: true,
    editable: !disabled,
    extensions: [
      StarterKit.configure({
        heading: false,
        blockquote: false,
        codeBlock: false,
        code: false,
        horizontalRule: false,
        // Shift+Enter would otherwise insert a hard break, which the server does not accept.
        hardBreak: false,
        // No approved link-entry flow, and an href is exactly what an email must not gain silently.
        link: false,
      }),
      Placeholder.configure({ placeholder: placeholder ?? "" }),
    ],
    content: EMPTY_DOC,
    editorProps: {
      attributes: { role: "textbox", "aria-multiline": "true", "aria-label": ariaLabel },
      handleKeyDown: (_view, event) => {
        if ((event.metaKey || event.ctrlKey) && event.key === "Enter") {
          event.preventDefault();
          submitRef.current();
          return true;
        }
        return false;
      },
    },
    onFocus: () => setFocused(true),
    onBlur: () => setFocused(false),
  });

  useEffect(() => {
    onEditorReady(editor ?? null);
    return () => onEditorReady(null);
  }, [editor, onEditorReady]);

  useEffect(() => {
    editor?.setEditable(!disabled);
  }, [editor, disabled]);

  const hasText = hasContent(editor);
  useEffect(() => {
    onEmptyChange?.(hasText);
  }, [hasText, onEmptyChange]);

  return (
    <div className={cn("flex min-w-0 flex-col gap-1.5", className)}>
      <div
        className={cn(
          "overflow-hidden rounded-lg border bg-card transition-colors",
          focused ? "border-ring ring-2 ring-ring" : "border-border",
          disabled && "opacity-60",
        )}
      >
        <RichTextToolbar
          editor={editor}
          controls={EMAIL_TOOLBAR_CONTROLS}
          className="border-b border-border-subtle bg-muted/40 px-2 py-1.5"
        />
        {/* `min-h-24` keeps the writing area the size the four-row textarea was; `max-h-72` then
            scrolls a long reply inside the box rather than growing the card without limit. */}
        <div
          className={cn(
            "max-h-72 overflow-y-auto px-3.5 py-2.5 [&_.tiptap]:min-h-24",
            RICH_TEXT_PROSE_CLASSES,
          )}
        >
          <EditorContent editor={editor} />
        </div>
      </div>
      <p className="text-xs text-muted-foreground">
        <kbd className="rounded border border-border px-1 py-0.5 font-sans text-[11px]">⌘/Ctrl</kbd>
        <span className="mx-1">+</span>
        <kbd className="rounded border border-border px-1 py-0.5 font-sans text-[11px]">Enter</kbd>
        <span className="ml-1.5">to send</span>
      </p>
    </div>
  );
}
