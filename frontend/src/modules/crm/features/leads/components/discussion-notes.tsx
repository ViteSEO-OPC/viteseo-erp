"use client";

import { useState } from "react";
import { AvatarInitials } from "@/modules/crm/components/shared/avatar-initials/avatar-initials";
import { Button } from "@/modules/crm/components/ui/button";
import { Plus, Pencil, Trash2, Check, X, Loader2 } from "lucide-react";
import { formatActivityTimestamp } from "@/modules/crm/lib/formatters";
import { cn } from "@/modules/crm/lib/utils";
import { EmptyState } from "@/modules/crm/components/shared/empty-state/empty-state";
import { ErrorState } from "@/modules/crm/components/shared/error-state/error-state";
import { usePermissions } from "@/modules/crm/providers/permissions-provider";
import {
  useLeadNotes,
  useCreateNote,
  useUpdateNote,
  useDeleteNote,
} from "@/modules/crm/features/leads/hooks/use-lead-notes";
import type { Editor } from "@tiptap/react";
import { NoteRichEditor } from "@/modules/crm/features/leads/components/note-rich-editor";
import { EMPTY_DOC, plainTextToDoc, withEditableRoot } from "@/core/editor/tiptap-doc";
import { NoteBody } from "@/modules/crm/features/leads/components/note-body";
import {
  documentToBody,
  documentToBodyDoc,
} from "@/modules/crm/features/leads/lib/note-persistence";
import type { Note } from "@/modules/crm/features/leads/types/lead-detail-types";

type NoteItemProps = {
  note: Note;
  /** Whether the current user may edit/delete (author, or Admin/SA). */
  canManage: boolean;
  onSave: (payload: { body: string; bodyDoc: ReturnType<typeof documentToBodyDoc> }) => void;
  onDelete: () => void;
  isBusy: boolean;
};

function NoteItem({ note, canManage, onSave, onDelete, isBusy }: NoteItemProps) {
  const [editing, setEditing] = useState(false);
  // The editor owns the draft; this only holds the handle needed to read it at save time. A string
  // mirror would fight the editor for authorship on every keystroke.
  const [editor, setEditor] = useState<Editor | null>(null);
  // Whether the editor currently holds anything. Pushed up by the editor on each empty↔non-empty
  // transition — the parent cannot read it during its own render, because typing does not re-render
  // the parent (the editor instance it holds is referentially stable).
  const [canSave, setCanSave] = useState(false);

  function save() {
    if (!editor) return;
    const body = documentToBody(editor);
    // Empty → close without a pointless request or Activity row. An unchanged TEXT body is NOT
    // treated as unchanged any more: formatting can differ while the text is identical (bolding a
    // word, turning lines into a list), and that is a real edit worth saving.
    if (body === "") {
      setEditing(false);
      return;
    }
    onSave({ body, bodyDoc: documentToBodyDoc(editor) });
    setEditing(false);
  }

  return (
    <div className="group flex gap-3">
      <AvatarInitials src={note.avatarUrl} initials={note.initials} size="sm" />
      <div className="min-w-0 flex-1">
        <div className="flex items-center justify-between gap-2">
          <p className="text-sm font-semibold text-card-foreground">
            {note.author}
            <span className="ml-2 text-xs font-normal text-muted-foreground">
              {formatActivityTimestamp(note.timestamp)}
            </span>
          </p>
          {/* Hover-only revealed nothing on a touch screen and nothing to a keyboard user, so the
              edit/delete a user already has permission for were effectively undiscoverable. They are
              now always visible below `sm`, and on pointer devices any focus within the row reveals
              them — hover remains the quiet default on a long note list. */}
          {canManage && !editing && (
            <div className="flex items-center gap-1 transition-opacity sm:opacity-0 sm:group-hover:opacity-100 sm:group-focus-within:opacity-100">
              <button
                type="button"
                onClick={() => setEditing(true)}
                className="rounded p-1 text-muted-foreground transition-colors hover:text-foreground focus-visible:opacity-100 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                aria-label={`Edit note by ${note.author}`}
              >
                <Pencil className="h-3.5 w-3.5" />
              </button>
              <button
                type="button"
                onClick={onDelete}
                disabled={isBusy}
                className="rounded p-1 text-muted-foreground transition-colors hover:text-destructive focus-visible:opacity-100 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring disabled:opacity-50"
                aria-label={`Delete note by ${note.author}`}
              >
                <Trash2 className="h-3.5 w-3.5" />
              </button>
            </div>
          )}
        </div>

        {editing ? (
          <div className="mt-1.5 flex flex-col gap-2">
            {/* A rich note opens from its own stored document; a legacy plain-text note is wrapped
                into one so it can be edited in the same editor — and saving it promotes it to rich
                without any migration. `withEditableRoot` guards a stored document that somehow has
                no children, which would otherwise mount an uneditable-looking editor. */}
            <NoteRichEditor
              initialContent={note.doc ? withEditableRoot(note.doc) : plainTextToDoc(note.text)}
              onEditorReady={setEditor}
              onEmptyChange={setCanSave}
              onSubmit={save}
              disabled={isBusy}
              ariaLabel="Edit note"
              autoFocus
            />
            <div className="flex items-center gap-2">
              <Button size="sm" onClick={save} disabled={isBusy || !canSave}>
                {isBusy ? <Loader2 className="h-4 w-4 animate-spin" /> : <Check className="h-4 w-4" />}
                {isBusy ? "Saving…" : "Save"}
              </Button>
              <Button size="sm" variant="ghost" onClick={() => setEditing(false)} disabled={isBusy}>
                <X className="h-4 w-4" />
                Cancel
              </Button>
            </div>
          </div>
        ) : (
          <NoteBody
            text={note.text}
            doc={note.doc}
            className={cn(
              "mt-1.5 rounded-lg px-4 py-3",
              note.self ? "bg-[var(--note-bg-self)]" : "bg-[var(--note-bg-other)]",
            )}
          />
        )}
      </div>
    </div>
  );
}

type Props = {
  leadId: string;
};

export function DiscussionNotes({ leadId }: Props) {
  const { notes, isLoading, isError } = useLeadNotes(leadId);
  const createNote = useCreateNote(leadId);
  const updateNote = useUpdateNote(leadId);
  const deleteNote = useDeleteNote(leadId);
  const { hasMinRole } = usePermissions();
  const isManager = hasMinRole("crm_admin");

  const [editor, setEditor] = useState<Editor | null>(null);
  // The composer's live emptiness, reported by the editor. This is what restores the original Leads
  // behaviour — Add note goes live the moment there is content, and dies again the moment there is
  // not, including straight after a successful save clears the editor, so the next note can be typed
  // and saved immediately.
  const [canSubmit, setCanSubmit] = useState(false);

  function submit() {
    if (!editor) return;
    const body = documentToBody(editor);
    if (body === "") return;
    createNote.mutate(
      { body, bodyDoc: documentToBodyDoc(editor) },
      { onSuccess: () => editor.commands.clearContent(true) },
    );
  }

  return (
    // `min-w-0`: this card is a grid item in Lead Detail's `[71fr_29fr]` row, where the default
    // `min-width: auto` resolves to the min-content of everything inside. The wrapping rules above
    // already keep that small, and this is the belt to their braces — no note, however it is
    // formatted, can push the track wider than its share and start a horizontal page scroll.
    <div className="min-w-0 rounded-2xl border border-border bg-card p-6 shadow-card">
      <h3 className="mb-5 text-lg font-bold text-card-foreground">Internal Notes</h3>

      {isLoading && (
        <div className="flex flex-col gap-4" aria-hidden>
          {[0, 1].map((i) => (
            <div key={i} className="flex gap-3">
              <div className="h-8 w-8 shrink-0 animate-pulse rounded-full bg-muted" />
              <div className="h-16 flex-1 animate-pulse rounded-lg bg-muted" />
            </div>
          ))}
        </div>
      )}

      {isError && <ErrorState message="Failed to load notes" />}

      {!isLoading && !isError && (
        <>
          {notes && notes.length > 0 ? (
            // The saved-notes LIST is the ONLY scroll boundary in the note history — deliberately
            // one, not two. An individual note is NOT capped and never scrolls inside its own
            // bubble: a note renders at its natural height and long ones are simply scrolled past
            // here, which reads as one continuous history instead of a stack of little viewports
            // each with its own scrollbar. Both problems a cap would solve — one enormous note, or
            // very many notes — are handled by this single container.
            //
            // It goes on this element and not on the card, which is the point of the split: the
            // heading above and the composer and Add-note button below are SIBLINGS of this div, so
            // they stay pinned in place while only the history scrolls. Putting the overflow on the
            // card would scroll the composer out of reach exactly when a busy lead needs it most.
            //
            // `max-h-96` (24rem / 384px) shows roughly four notes at the usual size — enough that a
            // quiet lead never scrolls at all and the list keeps its natural height, few enough that
            // the Notes card stays a readable panel beside the Active Call console it shares a row
            // with. Being a scroll container also pins its min-content contribution at zero, which
            // is the property that keeps the `fr` grid track from being inflated now that the note
            // bodies are no longer scroll containers themselves.
            //
            // From xl the window is `max-h-44` (11rem / 176px, about two notes): there Notes sits in
            // one top-aligned row beside Call (~455px) and the Activity Timeline (~410px), and this cap
            // is what keeps a busy Notes card near ~470px instead of ~675px. Below xl the cards stack,
            // so the roomier 384px window stays.
            <div className="flex max-h-96 min-w-0 flex-col gap-5 overflow-y-auto xl:max-h-44">
              {notes.map((note) => (
                <NoteItem
                  key={note.id}
                  note={note}
                  canManage={note.self || isManager}
                  onSave={(payload) => updateNote.mutate({ id: note.id, ...payload })}
                  onDelete={() => deleteNote.mutate(note.id)}
                  isBusy={updateNote.isPending || deleteNote.isPending}
                />
              ))}
            </div>
          ) : (
            <EmptyState message="No notes yet — add the first one below. Notes are internal and only visible to your team." />
          )}
        </>
      )}

      <div className="mt-5 flex flex-col gap-2 border-t border-border-subtle pt-5">
        <NoteRichEditor
          initialContent={EMPTY_DOC}
          onEditorReady={setEditor}
          onEmptyChange={setCanSubmit}
          onSubmit={submit}
          disabled={createNote.isPending}
          placeholder="Add an internal note… What happened, what was agreed, what happens next."
          ariaLabel="Add an internal note"
        />
        <div className="flex justify-end">
          <Button
            variant="default"
            size="sm"
            onClick={submit}
            disabled={!canSubmit || createNote.isPending}
          >
            {createNote.isPending ? (
              <Loader2 className="h-4 w-4 animate-spin" />
            ) : (
              <Plus className="h-4 w-4" />
            )}
            {createNote.isPending ? "Adding…" : "Add note"}
          </Button>
        </div>
      </div>
    </div>
  );
}
