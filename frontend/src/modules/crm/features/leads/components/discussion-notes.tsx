"use client";

import { useEffect, useRef, useState, useSyncExternalStore } from "react";
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
import type { Note } from "@/modules/crm/features/leads/types/lead-detail-types";

/** `crm_notes.body` is `max:5000` in both StoreNoteRequest and UpdateNoteRequest. */
const BODY_MAX = 5000;
/** Below this many characters remaining the counter stops being decoration and starts being a warning. */
const COUNTER_WARN_AT = 500;

/**
 * The modifier key to NAME in the hint. The handler accepts both (`metaKey || ctrlKey`) exactly as
 * the Projects editor and global search do; only the label is platform-specific.
 *
 * Read through useSyncExternalStore rather than branching in render or setting state in an effect:
 * the server has no `navigator`, so a render-time check would hydrate one label over another, and
 * the effect form is what `react-hooks/set-state-in-effect` refuses. The server snapshot is "Ctrl",
 * the safer first paint — this CRM's users are predominantly on Windows.
 */
function useModifierLabel(): string {
  return useSyncExternalStore(subscribeToNothing, getModifierLabel, () => "Ctrl");
}

/** The platform never changes mid-session, so there is nothing to subscribe to. */
function subscribeToNothing(): () => void {
  return () => {};
}

/**
 * Cached: useSyncExternalStore re-reads the snapshot on every render and loops if the value is not
 * referentially stable. `userAgent` rather than the deprecated `navigator.platform`.
 */
let modifierLabel: string | null = null;
function getModifierLabel(): string {
  modifierLabel ??= /Mac|iPhone|iPad|iPod/.test(navigator.userAgent) ? "⌘" : "Ctrl";
  return modifierLabel;
}

/**
 * The note input, shared by the composer and the inline editor so the two cannot drift apart.
 *
 * Auto-grows from `minRows` up to `max-h-64`, after which it scrolls: a note is capped at 5000
 * characters, and a textarea that grows to all of them would push the Add button off-screen and
 * bury the note history above it.
 *
 * ⌘/Ctrl+Enter submits; plain Enter inserts a newline. That is the inverse of what this composer
 * used to do — it was an `<input type="text">` where Enter submitted, so a 5000-character field
 * could not hold a second line at all.
 */
function NoteTextarea({
  value,
  onChange,
  onSubmit,
  disabled,
  placeholder,
  ariaLabel,
  minRows = 3,
  autoFocus,
}: {
  value: string;
  onChange: (next: string) => void;
  onSubmit: () => void;
  disabled?: boolean;
  placeholder?: string;
  ariaLabel: string;
  minRows?: number;
  autoFocus?: boolean;
}) {
  const ref = useRef<HTMLTextAreaElement>(null);
  const modifier = useModifierLabel();

  // Reset to `auto` before reading scrollHeight: without it the element can only ever grow, because
  // its own previous height becomes the floor for the next measurement.
  useEffect(() => {
    const el = ref.current;
    if (!el) return;
    el.style.height = "auto";
    el.style.height = `${el.scrollHeight}px`;
  }, [value]);

  const remaining = BODY_MAX - value.length;
  const nearLimit = remaining <= COUNTER_WARN_AT;

  return (
    <div className="flex flex-col gap-1.5">
      <textarea
        ref={ref}
        value={value}
        onChange={(e) => onChange(e.target.value)}
        onKeyDown={(e) => {
          if ((e.metaKey || e.ctrlKey) && e.key === "Enter") {
            e.preventDefault();
            onSubmit();
          }
        }}
        rows={minRows}
        maxLength={BODY_MAX}
        disabled={disabled}
        placeholder={placeholder}
        autoFocus={autoFocus}
        aria-label={ariaLabel}
        className="max-h-64 w-full resize-none overflow-y-auto rounded-lg border border-border bg-card px-3.5 py-2.5 text-sm leading-relaxed text-foreground placeholder:text-muted-foreground focus:outline-none focus:ring-2 focus:ring-ring disabled:cursor-not-allowed disabled:opacity-60"
      />
      <div className="flex items-center justify-between gap-3 text-xs text-muted-foreground">
        <span>
          <kbd className="rounded border border-border px-1 py-0.5 font-sans text-[11px]">{modifier}</kbd>
          <span className="mx-1">+</span>
          <kbd className="rounded border border-border px-1 py-0.5 font-sans text-[11px]">Enter</kbd>
          <span className="ml-1.5">to save</span>
        </span>
        {value.length > 0 && (
          <span className={cn("tabular-nums", nearLimit && "font-medium text-amber-700 dark:text-amber-400")}>
            {value.length.toLocaleString()} / {BODY_MAX.toLocaleString()}
          </span>
        )}
      </div>
    </div>
  );
}

type NoteItemProps = {
  note: Note;
  /** Whether the current user may edit/delete (author, or Admin/SA). */
  canManage: boolean;
  onSave: (body: string) => void;
  onDelete: () => void;
  isBusy: boolean;
};

function NoteItem({ note, canManage, onSave, onDelete, isBusy }: NoteItemProps) {
  const [editing, setEditing] = useState(false);
  const [draft, setDraft] = useState(note.text);

  function save() {
    const body = draft.trim();
    if (!body || body === note.text) {
      setEditing(false);
      return;
    }
    onSave(body);
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
                onClick={() => {
                  setDraft(note.text);
                  setEditing(true);
                }}
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
            <NoteTextarea
              value={draft}
              onChange={setDraft}
              onSubmit={save}
              disabled={isBusy}
              ariaLabel="Edit note"
              autoFocus
            />
            <div className="flex items-center gap-2">
              <Button size="sm" onClick={save} disabled={isBusy || !draft.trim()}>
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
          <div
            className={cn(
              "mt-1.5 whitespace-pre-wrap rounded-lg px-4 py-3 text-sm leading-relaxed text-foreground",
              note.self ? "bg-[var(--note-bg-self)]" : "bg-[var(--note-bg-other)]",
            )}
          >
            {note.text}
          </div>
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

  const [value, setValue] = useState("");

  function submit() {
    const body = value.trim();
    if (!body) return;
    createNote.mutate(body, { onSuccess: () => setValue("") });
  }

  return (
    <div className="rounded-2xl border border-border bg-card p-6 shadow-card">
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
            <div className="flex flex-col gap-5">
              {notes.map((note) => (
                <NoteItem
                  key={note.id}
                  note={note}
                  canManage={note.self || isManager}
                  onSave={(body) => updateNote.mutate({ id: note.id, body })}
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
        <NoteTextarea
          value={value}
          onChange={setValue}
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
            disabled={!value.trim() || createNote.isPending}
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
