import type { Editor, JSONContent } from "@tiptap/react";

/**
 * The one place that knows how a composed note becomes a stored note.
 *
 * A note is persisted as TWO columns and they are not independent:
 *
 *   body      TEXT, never null — the canonical readable text. Search, exports, the spreadsheet
 *                               importer and the 5000-character rule all read this.
 *   body_doc  JSON, nullable  — the Tiptap document, or NULL for a legacy/imported plain-text note.
 *
 * Both fields are sent on every write, but **only `body_doc` is authoritative**. When it is present
 * the server discards the submitted `body` and re-derives it with `TiptapDocument::extractText()`
 * (see `LeadNoteCreator::attributesFor()`). `body` is still transmitted because it is `required` — a
 * plain-text client must keep working, and the column is never null — not because the server trusts
 * it. A caller cannot post innocuous text alongside a rich document and have the two disagree.
 *
 * Server-side validation runs bytes → depth → disallowed types → text length, in that order. The
 * ordering is a security requirement, not a style choice: `disallowedTypes()` shares a walk that
 * stops at `MAX_DEPTH`, so a disallowed node buried deeper is invisible to it, and depth must be
 * rejected first. See `ValidatesNoteDocument`.
 */
/**
 * The string to send as `body`.
 *
 * `getText()` — not `getJSON()` — because `body` is a plain TEXT column and the 5000-character limit
 * is defined over readable text, never over the serialized document. A JSON blob can sit far under
 * 512 KB while its text runs well past 5000 characters, so measuring the wrong one silently retires
 * the limit.
 *
 * This is the client's projection and is deliberately advisory: the server recomputes it from the
 * document. Sending it keeps the plain-text contract intact and gives a `body_doc`-less client
 * (the importer, the Create Lead wizard) exactly the behaviour it had before rich text existed.
 */
export function documentToBody(editor: Editor): string {
  return editor.getText().trim();
}

/**
 * Whether the editor holds anything worth saving.
 *
 * Checked against text rather than `editor.isEmpty`: a document can be structurally non-empty —
 * an empty heading, an empty list item, a stray hard break — while reading as blank. Those must not
 * create a note, and the server would reject them anyway (`required` runs after TrimStrings).
 */
export function hasContent(editor: Editor | null): boolean {
  return editor !== null && editor.getText().trim().length > 0;
}

/**
 * The document to send as `body_doc`.
 *
 * Always the editor's full JSON — there is no "is it worth storing rich" heuristic, because a note
 * with no formatting still round-trips more faithfully as its own document than as reparsed text,
 * and a conditional would make the stored format depend on content in a way nothing else could
 * predict.
 */
export function documentToBodyDoc(editor: Editor): JSONContent {
  return editor.getJSON();
}
