/**
 * A bulleted list of toast notes, rendered as PHRASING content.
 *
 * The shared toast card (`core/lib/notify`) types its `description` as ReactNode but renders it
 * inside a <p>, and a <p> may only contain phrasing content. A <ul> nested there is invalid HTML,
 * which React reports as a hydration error in the console. Spans read the same on screen — the
 * `flex` is CSS, not element nesting, so the markup stays legal — and keeps the fix inside the HR
 * module instead of in shared core.
 */
export function ToastNoteList({ notes }: { notes: string[] }) {
  return (
    <span className="mt-1 flex flex-col gap-1">
      {notes.map((note, i) => (
        <span key={i} className="flex gap-1.5">
          <span aria-hidden="true">•</span>
          <span>{note}</span>
        </span>
      ))}
    </span>
  );
}
