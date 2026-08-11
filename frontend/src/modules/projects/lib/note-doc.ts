import type { JSONContent } from "@tiptap/react";
import type { NoteTarget } from "@/modules/projects/types/note";

/** Stable string id for a target, e.g. "user" or "project:<uuid>". */
export function noteTargetId(target: NoteTarget): string {
  return target.scope === "user" ? "user" : `project:${target.projectId ?? ""}`;
}

/**
 * An empty Tiptap document.
 *
 * One empty paragraph, not zero nodes: ProseMirror renders a childless doc as
 * nothing at all, leaving no line to click into and no node for the placeholder
 * to attach to.
 */
export const EMPTY_DOC: JSONContent = {
  type: "doc",
  content: [{ type: "paragraph" }],
};

/**
 * Guard a document coming from the API. Notes created before the empty-doc fix
 * (and any hand-written payload) can arrive with no children, which would mount
 * an uneditable-looking editor.
 */
export function withEditableRoot(doc: JSONContent | null | undefined): JSONContent {
  if (!doc || doc.type !== "doc" || !doc.content?.length) return EMPTY_DOC;
  return doc;
}

/** True when a document has no text and no block content worth keeping. */
export function isEmptyDoc(doc: JSONContent | undefined | null): boolean {
  if (!doc?.content?.length) return true;
  return !plainTextOf(doc).trim();
}

/** Plain-text projection — used for the collapsed one-line preview. */
export function plainTextOf(doc: JSONContent | undefined | null): string {
  if (!doc) return "";
  const parts: string[] = [];
  const walk = (node: JSONContent): void => {
    if (node.type === "text" && typeof node.text === "string") parts.push(node.text);
    else if (node.content) parts.push(" ");
    node.content?.forEach(walk);
  };
  walk(doc);
  return parts.join("").replace(/\s+/g, " ").trim();
}
