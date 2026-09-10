"use client";

import { EditorContent, useEditor, type JSONContent } from "@tiptap/react";
import { StarterKit } from "@tiptap/starter-kit";
import { TaskItem, TaskList } from "@tiptap/extension-list";
import { Highlight } from "@tiptap/extension-highlight";
import { RICH_TEXT_PROSE_CLASSES } from "@/core/editor/rich-text-styles";
import { cn } from "@/modules/crm/lib/utils";

/**
 * A saved note's body, in whichever of the two formats it was stored in.
 *
 *   doc === null  → legacy or imported note; the plain-text renderer this component has always used
 *   doc !== null  → the Tiptap document, rendered READ-ONLY
 *
 * Both branches exist permanently. Nullability is the discriminator, so no note is ever migrated and
 * a plain-text note keeps rendering byte-for-byte as it did before rich text existed.
 *
 * ── Why an editor instance rather than HTML ──
 * The document is handed to ProseMirror, which builds the DOM node by node from the JSON. There is no
 * `dangerouslySetInnerHTML`, no `generateHTML`, and no HTML string anywhere in this path — so a
 * stored document cannot inject markup even if it somehow reached the database carrying a node type
 * the server's validation should have refused.
 *
 * The extension list MUST stay a subset of the composer's (`note-rich-editor.tsx`) and of the
 * server's allow-list (`TiptapDocument::ALLOWED_NODES`/`ALLOWED_MARKS`). A node type the renderer
 * does not know is dropped silently by the schema — which fails safe, but also means content
 * disappears, so the three lists are kept deliberately aligned. Placeholder and CharacterCount are
 * omitted: neither has meaning for something that cannot be typed into.
 */
export function NoteBody({
  text,
  doc,
  className,
}: {
  text: string;
  doc: JSONContent | null;
  className?: string;
}): React.ReactElement {
  if (doc === null) {
    return (
      <div
        className={cn(
          // `whitespace-pre-wrap` keeps the newlines a plain-text note was typed with, but it says
          // nothing about a run that contains no spaces to break at — so this branch had no wrapping
          // rule whatsoever and a long unbroken string painted straight out of the card. Matching the
          // rich branch's `wrap-anywhere` gives it the same wrapping AND the same min-content
          // collapse, so neither kind of note can size the grid track it sits in.
          "wrap-anywhere whitespace-pre-wrap text-sm leading-relaxed text-foreground",
          className,
        )}
      >
        {text}
      </div>
    );
  }

  return <RichNoteBody doc={doc} className={className} />;
}

/**
 * Split out so the `useEditor` hook is never called conditionally — the legacy branch above returns
 * before any hook runs, and this component only mounts when there is a document to render.
 */
function RichNoteBody({
  doc,
  className,
}: {
  doc: JSONContent;
  className?: string;
}): React.ReactElement {
  const editor = useEditor({
    immediatelyRender: false,
    editable: false,
    extensions: [
      StarterKit.configure({
        heading: { levels: [1, 2, 3] },
        link: { openOnClick: false, autolink: false },
      }),
      TaskList,
      // Same reason as the composer: TaskItem renders through a node view that bypasses renderHTML,
      // so without this the live <li> carries no data-type and every task-item style misses.
      TaskItem.configure({ nested: true, HTMLAttributes: { "data-type": "taskItem" } }),
      Highlight,
    ],
    content: doc,
    editorProps: {
      // A saved note is content, not a control: it must not be reachable by Tab, and it must not
      // announce itself as a textbox.
      attributes: { tabindex: "-1" },
    },
  });

  return (
    <div className={cn(RICH_TEXT_PROSE_CLASSES, className)}>
      <EditorContent editor={editor} />
    </div>
  );
}
