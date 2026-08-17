/**
 * Prose styling for the candidate-email editor, as Tailwind arbitrary variants on the
 * editor's wrapper. `.tiptap` is the class Tiptap puts on its editable root.
 *
 * Kept local to HR rather than shared: the rules are chosen for what an EMAIL can contain
 * — which is the backend sanitiser's allow-list, not everything a rich-text editor can
 * produce — and they must not leak onto any other surface.
 *
 * The same string styles the read-only rendering of a sent email in the history view, so
 * what HR typed and what HR later reads back look the same.
 */
export const EMAIL_PROSE_CLASSES = [
  "[&_.tiptap]:outline-none",
  "[&_.tiptap]:text-sm",
  "[&_.tiptap]:leading-relaxed",
  "[&_.tiptap]:text-ink",
  // A pasted meeting link is one unbroken token; it must wrap, not widen the dialog.
  "[&_.tiptap]:wrap-break-word",

  // Blocks
  "[&_.tiptap_p]:my-2",
  "[&_.tiptap_h1]:mt-4 [&_.tiptap_h1]:mb-2 [&_.tiptap_h1]:text-lg [&_.tiptap_h1]:font-bold",
  "[&_.tiptap_h2]:mt-4 [&_.tiptap_h2]:mb-2 [&_.tiptap_h2]:text-base [&_.tiptap_h2]:font-bold",
  "[&_.tiptap_h3]:mt-3 [&_.tiptap_h3]:mb-1.5 [&_.tiptap_h3]:text-sm [&_.tiptap_h3]:font-bold",
  "[&_.tiptap_blockquote]:my-2 [&_.tiptap_blockquote]:border-l-2 [&_.tiptap_blockquote]:border-line",
  "[&_.tiptap_blockquote]:pl-3 [&_.tiptap_blockquote]:text-ink-soft",
  "[&_.tiptap_hr]:my-4 [&_.tiptap_hr]:border-line",

  // Lists
  "[&_.tiptap_ul]:my-2 [&_.tiptap_ul]:list-disc [&_.tiptap_ul]:pl-5",
  "[&_.tiptap_ol]:my-2 [&_.tiptap_ol]:list-decimal [&_.tiptap_ol]:pl-5",
  "[&_.tiptap_li]:my-0.5",
  "[&_.tiptap_li>p]:my-0",

  // Inline
  "[&_.tiptap_a]:text-brand [&_.tiptap_a]:underline [&_.tiptap_a]:underline-offset-2",
  "[&_.tiptap_code]:rounded [&_.tiptap_code]:bg-black/5 [&_.tiptap_code]:px-1",
  "[&_.tiptap_code]:py-0.5 [&_.tiptap_code]:text-[0.85em]",

  // Placeholder (the extension tags the empty first block with the text)
  "[&_.tiptap_p.is-editor-empty:first-child::before]:content-[attr(data-placeholder)]",
  "[&_.tiptap_p.is-editor-empty:first-child::before]:pointer-events-none",
  "[&_.tiptap_p.is-editor-empty:first-child::before]:float-left",
  "[&_.tiptap_p.is-editor-empty:first-child::before]:h-0",
  "[&_.tiptap_p.is-editor-empty:first-child::before]:text-ink-faint",
].join(" ");

/**
 * The same prose rules for HTML rendered outside the editor (the sent-email history),
 * where there is no `.tiptap` root to hang them off.
 */
export const EMAIL_PREVIEW_CLASSES = [
  "text-sm leading-relaxed text-ink wrap-break-word",
  "[&_p]:my-2",
  "[&_h1]:my-2 [&_h1]:text-lg [&_h1]:font-bold",
  "[&_h2]:my-2 [&_h2]:text-base [&_h2]:font-bold",
  "[&_h3]:my-1.5 [&_h3]:text-sm [&_h3]:font-bold",
  "[&_blockquote]:my-2 [&_blockquote]:border-l-2 [&_blockquote]:border-line [&_blockquote]:pl-3",
  "[&_ul]:my-2 [&_ul]:list-disc [&_ul]:pl-5",
  "[&_ol]:my-2 [&_ol]:list-decimal [&_ol]:pl-5",
  "[&_li]:my-0.5",
  "[&_a]:text-brand [&_a]:underline [&_a]:underline-offset-2",
  "[&_hr]:my-3 [&_hr]:border-line",
].join(" ");
