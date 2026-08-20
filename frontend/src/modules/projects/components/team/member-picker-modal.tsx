"use client";

import { useEffect, useRef, useState } from "react";
import type { User } from "@/core/types";
import { Avatar, Button, ConfirmDialog, Input, Modal, SearchIcon } from "@/core/ui";
import { GroupPill } from "@/modules/projects/components/shared/pills";
import { MemberGroupTags, type GroupTag } from "@/modules/projects/components/team/team-shared";

/**
 * Reusable "Assign Members" modal. Selection is staged locally — toggling a user
 * only updates the draft; the changes are committed (via onAdd/onRemove for the
 * diff vs. the current members) when the user clicks Done. Closing/Cancel discards.
 * If Done would drop existing members, a confirmation naming them is shown first.
 */
export function MemberPickerModal({
  open,
  onClose,
  title = 'Assign Members',
  candidates,
  selectedIds,
  onAdd,
  onRemove,
  busy,
  memberTags,
  groups,
}: {
  open: boolean;
  onClose: () => void;
  title?: string;
  candidates: User[];
  selectedIds: number[];
  onAdd: (userId: number) => void;
  onRemove: (userId: number) => void;
  busy?: boolean;
  /**
   * Per-user group memberships (across ALL groups) for the per-row group
   * indicator dots next to each member.
   */
  memberTags?: Map<number, GroupTag[]>;
  /**
   * Groups (with their full rosters) used for the read-only group indicator
   * pills — a pill shows only once EVERY member of that group is selected.
   */
  groups?: { id: string; name: string; color: string; memberIds: number[] }[];
}) {
  const [search, setSearch] = useState('');
  // Staged selection — edits stay local until Done commits them.
  const [draft, setDraft] = useState<Set<number>>(() => new Set(selectedIds));
  const [confirmRemoval, setConfirmRemoval] = useState(false);

  // Snapshot the current members into the draft each time the modal opens
  // (only on the closed→open transition, so mid-edit re-renders don't reset it).
  const wasOpen = useRef(false);
  useEffect(() => {
    if (open && !wasOpen.current) {
      setDraft(new Set(selectedIds));
      setConfirmRemoval(false);
    }
    wasOpen.current = open;
  }, [open, selectedIds]);

  const toggle = (id: number) =>
    setDraft((prev) => {
      const next = new Set(prev);
      if (next.has(id)) next.delete(id);
      else next.add(id);
      return next;
    });

  // Members that Done would drop — de-selected since the modal opened.
  const removedIds = selectedIds.filter((id) => !draft.has(id));
  const removedNames = removedIds.map(
    (id) => candidates.find((u) => u.id === id)?.name ?? `User #${id}`,
  );

  // Commit the draft ↔ server diff, then close.
  const commit = () => {
    const original = new Set(selectedIds);
    for (const id of draft) if (!original.has(id)) onAdd(id);
    for (const id of removedIds) onRemove(id);
    setConfirmRemoval(false);
    onClose();
  };

  // Removals are destructive (they revoke access), so confirm them first;
  // pure additions commit straight away.
  const handleDone = () => {
    if (removedIds.length > 0) setConfirmRemoval(true);
    else commit();
  };

  // Groups whose entire roster is staged — shown as read-only pills.
  const completeGroups = (groups ?? []).filter(
    (g) => g.memberIds.length > 0 && g.memberIds.every((id) => draft.has(id)),
  );

  const filtered = candidates.filter(
    (u) =>
      u.name.toLowerCase().includes(search.toLowerCase()) ||
      u.email.toLowerCase().includes(search.toLowerCase()),
  );

  return (
    <>
      <Modal
        open={open}
        onClose={onClose}
        title={title}
        width={480}
        footer={
          <div className="flex justify-end gap-2">
            <Button variant="ghost" onClick={onClose}>
              Cancel
            </Button>
            <Button onClick={handleDone} disabled={busy}>
              Done
            </Button>
          </div>
        }
      >
        <div className="relative mb-3">
          <SearchIcon className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-ink-faint" />
          <Input
            value={search}
            onChange={(e) => setSearch(e.target.value)}
            placeholder="Search by name or email…"
            className="pl-9"
          />
        </div>

        <p className="mb-2 text-xs font-bold uppercase tracking-wide text-ink-soft">Select members</p>
        <div className="max-h-80 space-y-1 overflow-y-auto">
          {filtered.map((u) => {
            const isMember = draft.has(u.id);
            return (
              <button
                key={u.id}
                type="button"
                onClick={() => toggle(u.id)}
                className={`flex w-full items-center gap-3 rounded-lg px-3 py-2 text-left transition-colors ${
                  isMember ? 'bg-brand-soft' : 'hover:bg-black/5'
                }`}
              >
                <Avatar name={u.name} src={u.avatar} size={32} />
                <div className="min-w-0 flex-1">
                  <p className="truncate text-sm font-bold text-ink">{u.name}</p>
                  <p className="truncate text-xs text-ink-soft">{u.role ?? u.email}</p>
                  <MemberGroupTags
                    tags={memberTags?.get(u.id) ?? []}
                    max={4}
                    className="mt-1"
                  />
                </div>
                <span
                  className={`flex h-5 w-5 items-center justify-center rounded border ${
                    isMember ? 'border-brand bg-brand text-ink' : 'border-line'
                  }`}
                >
                  {isMember && '✓'}
                </span>
              </button>
            );
          })}
          {filtered.length === 0 && <p className="px-3 py-6 text-center text-sm text-ink-soft">No users found.</p>}
        </div>

        {completeGroups.length > 0 && (
          <div className="mt-4 border-t border-line pt-4">
            <p className="mb-2 text-xs font-bold uppercase tracking-wide text-ink-soft">Groups</p>
            <div className="flex flex-wrap gap-2">
              {completeGroups.map((g) => (
                <GroupPill key={g.id} name={g.name} color={g.color} />
              ))}
            </div>
          </div>
        )}
      </Modal>

      <ConfirmDialog
        open={open && confirmRemoval}
        onClose={() => setConfirmRemoval(false)}
        onConfirm={commit}
        title={removedIds.length === 1 ? 'Remove member' : 'Remove members'}
        confirmLabel={
          removedIds.length === 1 ? 'Remove member' : `Remove ${removedIds.length} members`
        }
        isPending={busy}
        description={
          <>
            <span className="font-bold text-ink">{removedNames.join(', ')}</span> will lose access.
            Group–project links are re-checked afterwards, so a group may leave a project if its
            roster no longer fits. Any other changes you made are saved at the same time.
          </>
        }
      />
    </>
  );
}
