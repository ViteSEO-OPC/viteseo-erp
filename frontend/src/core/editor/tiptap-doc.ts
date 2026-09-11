import type { JSONContent } from "@tiptap/react";

/**
 * Generic Tiptap document helpers, shared by any module that mounts an editor.
 *
 * Mirrors the two non-Projects-specific halves of `modules/projects/lib/note-doc.ts`. That file also
 * exports `noteTargetId`, which is Projects' scope addressing and stays there; it is left untouched.
 */

/**
 * An empty Tiptap document.
 *
 * One empty paragraph, not zero nodes: ProseMirror renders a childless doc as literally nothing,
 * leaving no line to place the caret on and no node for the placeholder to attach to.
 *
 * Matches `TiptapDocument::empty()` on the backend — the two must agree, or a note created on one
 * side mounts as an uneditable-looking editor on the other.
 */
export const EMPTY_DOC: JSONContent = {
  type: "doc",
  content: [{ type: "paragraph" }],
};

/**
 * Guard a document arriving from an API or from storage. A payload with no children — a legacy row,
 * a hand-written body, anything predating the empty-doc rule — would otherwise mount an editor with
 * nothing to type into.
 */
export function withEditableRoot(doc: JSONContent | null | undefined): JSONContent {
  if (!doc || doc.type !== "doc" || !doc.content?.length) return EMPTY_DOC;
  return doc;
}

/**
 * Wrap plain text as a minimal Tiptap document, one paragraph per line.
 *
 * Used to open a legacy plain-text note in the editor. Blank lines are dropped rather than becoming
 * empty paragraphs, and text with no content at all yields {@link EMPTY_DOC}.
 *
 * A paragraph carries no `content` array when its line is empty, because ProseMirror rejects a text
 * node with an empty string.
 */
export function plainTextToDoc(text: string): JSONContent {
  const lines = text.split(/\r?\n/).filter((line) => line.trim() !== "");
  if (lines.length === 0) return EMPTY_DOC;

  return {
    type: "doc",
    content: lines.map((line) => ({
      type: "paragraph",
      content: [{ type: "text", text: line }],
    })),
  };
}
