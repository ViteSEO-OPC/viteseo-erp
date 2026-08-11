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

  // Task list — checkbox on the same line as its text, aligned to the first line.
  //
  // Anchored on the LIST, not the item: TaskList is rendered from renderHTML so
  // its data-type is always present, whereas TaskItem builds its <li> in a node
  // view that copies only its declared attributes. Keying the item styles off
  // `li[data-type="taskItem"]` made every rule below a no-op, which left the
  // block-level <label> and <div> stacking — the checkbox on its own line with
  // the text beneath it. (NoteEditor now also restores that attribute; this
  // selector means the layout no longer depends on it.)
  '[&_.tiptap_ul[data-type="taskList"]]:list-none [&_.tiptap_ul[data-type="taskList"]]:pl-0.5',
  '[&_.tiptap_ul[data-type="taskList"]>li]:flex [&_.tiptap_ul[data-type="taskList"]>li]:items-start',
  '[&_.tiptap_ul[data-type="taskList"]>li]:gap-2',
  // The label is given the height of one line box (font-size x leading-relaxed)
  // so the checkbox centres against the first line of its label rather than
  // hanging off the top of it — a fixed top margin drifts as the type scales.
  '[&_.tiptap_ul[data-type="taskList"]>li>label]:h-[1.625em]',
  '[&_.tiptap_ul[data-type="taskList"]>li>label]:shrink-0',
  '[&_.tiptap_ul[data-type="taskList"]>li>label]:flex',
  '[&_.tiptap_ul[data-type="taskList"]>li>label]:items-center',
  '[&_.tiptap_ul[data-type="taskList"]>li>div]:min-w-0',
  '[&_.tiptap_ul[data-type="taskList"]>li>div]:flex-1',
  // The item's text is a <p> inside the content <div>, one level deeper than the
  // `li>p` reset above reaches. Left with its own margin it pushes the first line
  // below the checkbox and pads every row.
  '[&_.tiptap_ul[data-type="taskList"]>li>div>p]:my-0',
  '[&_.tiptap_ul[data-type="taskList"]>li[data-checked="true"]>div]:text-ink-faint',
  '[&_.tiptap_ul[data-type="taskList"]>li[data-checked="true"]>div]:line-through',
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
