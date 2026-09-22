/**
 * Prose styling for a Tiptap document, as Tailwind arbitrary variants on the editor's wrapper.
 *
 * Not a global stylesheet: scoping the rules to the wrapper means they cannot leak onto any other
 * surface. `.tiptap` is the class Tiptap puts on its editable root.
 *
 * ── Why this is not `modules/projects/lib/note-editor-styles.ts` ──
 * That file is the reference this was modelled on, and it is deliberately NOT imported or moved.
 * It is written in the Projects/PMS palette — `text-ink`, `text-ink-soft`, `border-line`,
 * `bg-warning-soft`, `bg-black/5`. Those tokens do resolve globally, but they are Sprintly's
 * vocabulary: reusing them inside the CRM would render grey-on-grey against the CRM's
 * `--foreground` / `--card` surfaces and would not follow the CRM's dark mode.
 *
 * This copy is expressed entirely in the shadcn base tokens defined on `:root` and
 * `[data-theme="dark"]` in globals.css — `foreground`, `muted-foreground`, `border`, `muted` — so it
 * is correct in any module and in both themes. Projects keeps its own file and its own appearance;
 * nothing there changes.
 */
export const RICH_TEXT_PROSE_CLASSES = [
  // Editable root
  "[&_.tiptap]:outline-none",
  "[&_.tiptap]:text-sm",
  "[&_.tiptap]:leading-relaxed",
  "[&_.tiptap]:text-foreground",
  // An unbroken run — a pasted URL, or a note that is 5000 "a"s — must wrap, not widen the card.
  //
  // `anywhere`, NOT `break-word`. The two wrap identically on screen, so normal notes look exactly
  // as they did; the difference is invisible until something measures the element. `break-word` is
  // ignored while a browser computes MIN-CONTENT width, so under it a note keeps reporting "as wide
  // as my longest word" to its ancestors. Lead Detail lays the Notes card out in `fr` grid tracks
  // (`lg:grid-cols-[76fr_24fr]`, `xl:grid-cols-[71fr_29fr]`) and an `fr` track's automatic minimum
  // IS min-content — so that phantom width inflated the track, widened the page and squeezed the
  // Active Call panel next to it, even though the text itself was wrapping correctly.
  // `anywhere` collapses the contribution to one character and the track stops moving.
  "[&_.tiptap]:wrap-anywhere",

  // Blocks
  "[&_.tiptap_p]:my-1.5",
  "[&_.tiptap_h1]:mt-4 [&_.tiptap_h1]:mb-1.5 [&_.tiptap_h1]:text-xl [&_.tiptap_h1]:font-bold",
  "[&_.tiptap_h2]:mt-3.5 [&_.tiptap_h2]:mb-1.5 [&_.tiptap_h2]:text-lg [&_.tiptap_h2]:font-bold",
  "[&_.tiptap_h3]:mt-3 [&_.tiptap_h3]:mb-1 [&_.tiptap_h3]:text-base [&_.tiptap_h3]:font-bold",
  "[&_.tiptap_blockquote]:my-2 [&_.tiptap_blockquote]:border-l-2 [&_.tiptap_blockquote]:border-border",
  "[&_.tiptap_blockquote]:pl-3 [&_.tiptap_blockquote]:text-muted-foreground",
  "[&_.tiptap_hr]:my-4 [&_.tiptap_hr]:border-border",

  // Lists
  "[&_.tiptap_ul]:my-1.5 [&_.tiptap_ul]:list-disc [&_.tiptap_ul]:pl-5",
  "[&_.tiptap_ol]:my-1.5 [&_.tiptap_ol]:list-decimal [&_.tiptap_ol]:pl-5",
  "[&_.tiptap_li]:my-0.5",
  "[&_.tiptap_li>p]:my-0",

  // Task list — checkbox on the same line as its text, aligned to the first line.
  //
  // Anchored on the LIST, not the item: TaskList renders through renderHTML so its `data-type` is
  // always present, whereas TaskItem builds its <li> in a node view that copies only its declared
  // attributes. Keying off `li[data-type="taskItem"]` makes every rule below a silent no-op, which
  // leaves the label and content div stacking — checkbox on its own line, text beneath it.
  '[&_.tiptap_ul[data-type="taskList"]]:list-none [&_.tiptap_ul[data-type="taskList"]]:pl-0.5',
  '[&_.tiptap_ul[data-type="taskList"]>li]:flex [&_.tiptap_ul[data-type="taskList"]>li]:items-start',
  '[&_.tiptap_ul[data-type="taskList"]>li]:gap-2',
  // One line box tall (font-size × leading-relaxed) so the checkbox centres against the first line
  // of its label rather than hanging off the top; a fixed margin drifts as the type scales.
  '[&_.tiptap_ul[data-type="taskList"]>li>label]:h-[1.625em]',
  '[&_.tiptap_ul[data-type="taskList"]>li>label]:shrink-0',
  '[&_.tiptap_ul[data-type="taskList"]>li>label]:flex',
  '[&_.tiptap_ul[data-type="taskList"]>li>label]:items-center',
  '[&_.tiptap_ul[data-type="taskList"]>li>div]:min-w-0',
  '[&_.tiptap_ul[data-type="taskList"]>li>div]:flex-1',
  // The item's text is a <p> inside the content <div>, one level deeper than the `li>p` reset above.
  '[&_.tiptap_ul[data-type="taskList"]>li>div>p]:my-0',
  '[&_.tiptap_ul[data-type="taskList"]>li[data-checked="true"]>div]:text-muted-foreground',
  '[&_.tiptap_ul[data-type="taskList"]>li[data-checked="true"]>div]:line-through',
  '[&_.tiptap_input[type="checkbox"]]:accent-teal-700 [&_.tiptap_input[type="checkbox"]]:cursor-pointer',

  // Inline
  "[&_.tiptap_a]:text-teal-700 [&_.tiptap_a]:underline [&_.tiptap_a]:underline-offset-2",
  "[&_.tiptap_a]:dark:text-teal-400",
  "[&_.tiptap_mark]:rounded [&_.tiptap_mark]:bg-amber-100 [&_.tiptap_mark]:px-0.5",
  "[&_.tiptap_mark]:text-amber-900 [&_.tiptap_mark]:dark:bg-amber-950/50 [&_.tiptap_mark]:dark:text-amber-200",
  "[&_.tiptap_code]:rounded [&_.tiptap_code]:bg-muted [&_.tiptap_code]:px-1",
  "[&_.tiptap_code]:py-0.5 [&_.tiptap_code]:text-[0.85em]",
  "[&_.tiptap_pre]:my-2 [&_.tiptap_pre]:overflow-x-auto [&_.tiptap_pre]:rounded-lg",
  "[&_.tiptap_pre]:bg-muted [&_.tiptap_pre]:p-3 [&_.tiptap_pre]:text-[0.85em]",
  "[&_.tiptap_pre_code]:bg-transparent [&_.tiptap_pre_code]:p-0",

  // Placeholder (the extension tags the empty first block with the text)
  "[&_.tiptap_p.is-editor-empty:first-child::before]:content-[attr(data-placeholder)]",
  "[&_.tiptap_p.is-editor-empty:first-child::before]:pointer-events-none",
  "[&_.tiptap_p.is-editor-empty:first-child::before]:float-left",
  "[&_.tiptap_p.is-editor-empty:first-child::before]:h-0",
  "[&_.tiptap_p.is-editor-empty:first-child::before]:text-muted-foreground",
].join(" ");
