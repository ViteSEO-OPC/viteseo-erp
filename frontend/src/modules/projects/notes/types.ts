import type { JSONContent } from "@tiptap/react";

/** Where a note lives. Both scopes are private to their author. */
export type NoteScope = "user" | "project";

/** A note as returned by `GET .../notes/me`. */
export interface Note {
  id: string;
  scope: NoteScope;
  /** Public project uuid; null on a user-scoped note. */
  project_id: string | null;
  content: JSONContent;
  /** Optimistic-concurrency token — sent back as `base_version` on save. */
  version: number;
  last_saved_at: string | null;
  updated_at: string | null;
}

/** The acknowledgement of a successful save. */
export interface NoteSaveResult {
  version: number;
  last_saved_at: string | null;
}

/** What the server sends with a 409 so the client can rebase without refetching. */
export interface NoteConflict {
  version: number;
  content: JSONContent;
  last_saved_at: string | null;
}

/**
 * Autosave state, surfaced by the badge:
 *  saved    — server confirmed
 *  dirty    — typed, debounce window open
 *  saving   — request in flight
 *  offline  — request failed; draft kept locally, retrying
 *  conflict — 409; the same user edited elsewhere
 */
export type SaveStatus = "saved" | "dirty" | "saving" | "offline" | "conflict";

/** The localStorage crash/offline layer (§5.2 of the spec). */
export interface NoteDraft {
  content: JSONContent;
  /** Server version this draft was built on. */
  baseVersion: number;
  /** Epoch ms of the local write. */
  savedAt: number;
  /** True until a 2xx confirms it. */
  dirty: boolean;
}

/** Identifies one note for storage keys, query keys, and broadcast channels. */
export interface NoteTarget {
  scope: NoteScope;
  /** Required when scope is "project". */
  projectId?: string;
}

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
