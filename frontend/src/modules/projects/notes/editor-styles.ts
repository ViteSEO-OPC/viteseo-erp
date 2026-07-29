/**
 * Prose styling for the Tiptap document, expressed as Tailwind arbitrary
 * variants on the editor's wrapper.
 *
 * Deliberately not a global stylesheet: keeping it here means the notes feature
 * ships without touching the shared core styles, and the rules can't leak into
 * any other surface. `.tiptap` is the class Tiptap puts on its editable root.
 */
export const EDITOR_PROSE_CLASSES = [
  // Editable root
  "[&_.tiptap]:outline-none",
  "[&_.tiptap]:text-sm",
  "[&_.tiptap]:leading-relaxed",
  "[&_.tiptap]:text-ink",
  // An unbroken paste (a long URL) must wrap, not widen the panel.
  "[&_.tiptap]:wrap-break-word",

  // Blocks
  "[&_.tiptap_p]:my-1.5",
  "[&_.tiptap_h1]:mt-4 [&_.tiptap_h1]:mb-1.5 [&_.tiptap_h1]:text-xl [&_.tiptap_h1]:font-bold",
  "[&_.tiptap_h2]:mt-3.5 [&_.tiptap_h2]:mb-1.5 [&_.tiptap_h2]:text-lg [&_.tiptap_h2]:font-bold",
  "[&_.tiptap_h3]:mt-3 [&_.tiptap_h3]:mb-1 [&_.tiptap_h3]:text-base [&_.tiptap_h3]:font-bold",
  "[&_.tiptap_blockquote]:my-2 [&_.tiptap_blockquote]:border-l-2 [&_.tiptap_blockquote]:border-line",
  "[&_.tiptap_blockquote]:pl-3 [&_.tiptap_blockquote]:text-ink-soft",
  "[&_.tiptap_hr]:my-4 [&_.tiptap_hr]:border-line",

  // Lists
  "[&_.tiptap_ul]:my-1.5 [&_.tiptap_ul]:list-disc [&_.tiptap_ul]:pl-5",
  "[&_.tiptap_ol]:my-1.5 [&_.tiptap_ol]:list-decimal [&_.tiptap_ol]:pl-5",
  "[&_.tiptap_li]:my-0.5",
  "[&_.tiptap_li>p]:my-0",

  // Task list — checkbox aligned with the first line of its label
  '[&_.tiptap_ul[data-type="taskList"]]:list-none [&_.tiptap_ul[data-type="taskList"]]:pl-0.5',
  '[&_.tiptap_li[data-type="taskItem"]]:flex [&_.tiptap_li[data-type="taskItem"]]:items-start',
  '[&_.tiptap_li[data-type="taskItem"]]:gap-2',
  '[&_.tiptap_li[data-type="taskItem"]>label]:mt-0.5 [&_.tiptap_li[data-type="taskItem"]>label]:shrink-0',
  '[&_.tiptap_li[data-type="taskItem"]>div]:min-w-0 [&_.tiptap_li[data-type="taskItem"]>div]:flex-1',
  '[&_.tiptap_li[data-type="taskItem"][data-checked="true"]>div]:text-ink-faint',
  '[&_.tiptap_li[data-type="taskItem"][data-checked="true"]>div]:line-through',
  '[&_.tiptap_input[type="checkbox"]]:accent-brand [&_.tiptap_input[type="checkbox"]]:cursor-pointer',

  // Inline
  "[&_.tiptap_a]:text-brand [&_.tiptap_a]:underline [&_.tiptap_a]:underline-offset-2",
  "[&_.tiptap_mark]:rounded [&_.tiptap_mark]:bg-warning-soft [&_.tiptap_mark]:px-0.5",
  "[&_.tiptap_mark]:text-ink",
  "[&_.tiptap_code]:rounded [&_.tiptap_code]:bg-black/5 [&_.tiptap_code]:px-1",
  "[&_.tiptap_code]:py-0.5 [&_.tiptap_code]:text-[0.85em]",
  "[&_.tiptap_pre]:my-2 [&_.tiptap_pre]:overflow-x-auto [&_.tiptap_pre]:rounded-lg",
  "[&_.tiptap_pre]:bg-black/5 [&_.tiptap_pre]:p-3 [&_.tiptap_pre]:text-[0.85em]",
  "[&_.tiptap_pre_code]:bg-transparent [&_.tiptap_pre_code]:p-0",

  // Placeholder (the extension tags the empty first block with the text)
  "[&_.tiptap_p.is-editor-empty:first-child::before]:content-[attr(data-placeholder)]",
  "[&_.tiptap_p.is-editor-empty:first-child::before]:pointer-events-none",
  "[&_.tiptap_p.is-editor-empty:first-child::before]:float-left",
  "[&_.tiptap_p.is-editor-empty:first-child::before]:h-0",
  "[&_.tiptap_p.is-editor-empty:first-child::before]:text-ink-faint",
].join(" ");
