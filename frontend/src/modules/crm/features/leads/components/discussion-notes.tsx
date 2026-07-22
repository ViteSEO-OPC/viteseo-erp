"use client";

import { useState } from "react";
import { AvatarInitials } from "@/modules/crm/components/shared/avatar-initials/avatar-initials";
import { Button } from "@/modules/crm/components/ui/button";
import { Plus, Pencil, Trash2, Check, X } from "lucide-react";
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
      <AvatarInitials initials={note.initials} size="sm" />
      <div className="min-w-0 flex-1">
        <div className="flex items-center justify-between gap-2">
          <p className="text-sm font-semibold text-card-foreground">
            {note.author}
            <span className="ml-2 text-xs font-normal text-muted-foreground">
              {formatActivityTimestamp(note.timestamp)}
            </span>
          </p>
          {canManage && !editing && (
            <div className="flex items-center gap-1 opacity-0 transition-opacity group-hover:opacity-100">
              <button
                type="button"
                onClick={() => {
                  setDraft(note.text);
                  setEditing(true);
                }}
                className="rounded p-1 text-muted-foreground hover:text-foreground"
                aria-label="Edit note"
              >
                <Pencil className="h-3.5 w-3.5" />
              </button>
              <button
                type="button"
                onClick={onDelete}
                disabled={isBusy}
                className="rounded p-1 text-muted-foreground hover:text-destructive"
                aria-label="Delete note"
              >
                <Trash2 className="h-3.5 w-3.5" />
              </button>
            </div>
          )}
        </div>

        {editing ? (
          <div className="mt-1.5 flex flex-col gap-2">
            <textarea
              value={draft}
              onChange={(e) => setDraft(e.target.value)}
              rows={3}
              maxLength={5000}
              className="w-full rounded-lg border border-border bg-card px-3.5 py-2 text-sm text-foreground focus:outline-none focus:ring-2 focus:ring-ring"
              aria-label="Edit note"
            />
            <div className="flex items-center gap-2">
              <Button size="sm" onClick={save} disabled={isBusy || !draft.trim()}>
                <Check className="h-4 w-4" />
                Save
              </Button>
              <Button size="sm" variant="ghost" onClick={() => setEditing(false)}>
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
            <EmptyState message="No notes yet" />
          )}
        </>
      )}

      <div className="mt-5 flex items-center gap-2">
        <input
          type="text"
          value={value}
          onChange={(e) => setValue(e.target.value)}
          onKeyDown={(e) => {
            if (e.key === "Enter") submit();
          }}
          maxLength={5000}
          placeholder="Add an internal note…"
          className="h-10 flex-1 rounded-lg border border-border bg-card px-3.5 text-sm text-foreground placeholder:text-muted-foreground focus:outline-none focus:ring-2 focus:ring-ring"
          aria-label="Add an internal note"
        />
        <Button
          variant="default"
          size="sm"
          onClick={submit}
          disabled={!value.trim() || createNote.isPending}
        >
          <Plus className="h-4 w-4" />
          Add note
        </Button>
      </div>
    </div>
  );
}
