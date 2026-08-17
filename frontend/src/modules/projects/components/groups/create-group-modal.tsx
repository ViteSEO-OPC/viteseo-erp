"use client";

import { useMemo, useState } from "react";
import { groupSchema, useValidatedForm } from "@/core/forms";
import { Avatar, Button, Field, Input, Modal, SearchIcon, Select } from "@/core/ui";
import { useCreateGroup } from "@/modules/projects/hooks/use-groups";
import { useUsers } from "@/modules/projects/hooks/use-users";
import {
  SkeletonList,
} from "@/modules/projects/components/shared/skeletons";

// Preset swatches the user picks from; the chosen hex is stored on the form.
const COLOR_PRESETS = [
  '#4fdbd7',
  '#6366f1',
  '#f59e0b',
  '#ef4444',
  '#10b981',
  '#8b5cf6',
  '#ec4899',
  '#0ea5e9',
];

export interface CreateGroupModalProps {
  open: boolean;
  onClose: () => void;
}

export function CreateGroupModal({ open, onClose }: CreateGroupModalProps): React.ReactElement {
  const create = useCreateGroup();

  // Members are picked from the full user directory. A group isn't tied to a
  // project — it joins a project automatically once that project holds every one
  // of the group's members.
  const usersQuery = useUsers();
  const users = useMemo(() => usersQuery.data ?? [], [usersQuery.data]);

  const [search, setSearch] = useState('');

  const {
    register,
    handleSubmit,
    reset,
    watch,
    setValue,
    formState: { errors },
  } = useValidatedForm(groupSchema, {
    defaultValues: {
      name: '',
      color: COLOR_PRESETS[0],
      leader_id: '',
      member_ids: [],
    },
  });

  const color = watch('color');
  const watchedMemberIds = watch('member_ids');
  const memberIds = useMemo(() => watchedMemberIds ?? [], [watchedMemberIds]);
  const leaderId = watch('leader_id');

  // The leader dropdown only offers the currently-selected members.
  const selectedMembers = useMemo(
    () => users.filter((u) => memberIds.includes(String(u.id))),
    [users, memberIds],
  );

  const filteredUsers = useMemo(() => {
    const q = search.trim().toLowerCase();
    if (!q) return users;
    return users.filter(
      (u) => u.name.toLowerCase().includes(q) || u.email.toLowerCase().includes(q),
    );
  }, [users, search]);

  const toggleMember = (id: number): void => {
    const key = String(id);
    const next = memberIds.includes(key)
      ? memberIds.filter((m) => m !== key)
      : [...memberIds, key];
    setValue('member_ids', next, { shouldValidate: true });
    // If the removed member was the leader, clear the now-invalid leader.
    if (leaderId === key && !next.includes(key)) {
      setValue('leader_id', '', { shouldValidate: true });
    }
  };

  const submit = handleSubmit(async (data) => {
    try {
      await create.mutateAsync({
        name: data.name,
        color: data.color,
        leader_id: Number(data.leader_id),
        member_ids: data.member_ids.map(Number),
      });
    } catch {
      // The hook's onError has already explained the failure; this stops the
      // form from closing and resetting as though the save had gone through.
      return;
    }
    reset();
    setSearch('');
    onClose();
  });

  const close = (): void => {
    reset();
    setSearch('');
    onClose();
  };

  return (
    <Modal
      open={open}
      onClose={close}
      title="Create New Group"
      width={520}
      footer={
        <>
          <Button variant="outline" onClick={close} type="button">
            Cancel
          </Button>
          <Button onClick={submit} disabled={create.isPending} type="button">
            {create.isPending ? 'Creating…' : 'Create'}
          </Button>
        </>
      }
    >
      <form onSubmit={submit} className="space-y-4">
        <Field label="Name" error={errors.name?.message}>
          <Input placeholder="e.g. Frontend POD" {...register('name')} />
        </Field>

        <Field label="Color" error={errors.color?.message}>
          <div className="flex flex-wrap gap-2">
            {COLOR_PRESETS.map((hex) => {
              const active = color?.toLowerCase() === hex.toLowerCase();
              return (
                <button
                  key={hex}
                  type="button"
                  onClick={() => setValue('color', hex, { shouldValidate: true })}
                  style={{ backgroundColor: hex }}
                  aria-label={`Select color ${hex}`}
                  aria-pressed={active}
                  className={`h-8 w-8 rounded-full transition-transform ${
                    active ? 'ring-2 ring-brand ring-offset-2 scale-110' : 'hover:scale-105'
                  }`}
                />
              );
            })}
          </div>
        </Field>

        <Field
          label={`Members${memberIds.length ? ` (${memberIds.length})` : ''}`}
          error={errors.member_ids?.message}
        >
          <div className="relative mb-2">
            <SearchIcon className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-ink-faint" />
            <Input
              value={search}
              onChange={(e) => setSearch(e.target.value)}
              placeholder="Search by name or email…"
              className="pl-9"
            />
          </div>
          <div className="max-h-56 space-y-1 overflow-y-auto rounded-lg border border-line p-1">
            {usersQuery.isLoading ? (
              <SkeletonList rows={4} className="px-3 py-4" />
            ) : filteredUsers.length === 0 ? (
              <p className="px-3 py-6 text-center text-sm text-ink-soft">No users found.</p>
            ) : (
              filteredUsers.map((u) => {
                const checked = memberIds.includes(String(u.id));
                return (
                  <button
                    key={u.id}
                    type="button"
                    onClick={() => toggleMember(u.id)}
                    className={`flex w-full items-center gap-3 rounded-lg px-3 py-2 text-left transition-colors ${
                      checked ? 'bg-brand-soft' : 'hover:bg-black/5'
                    }`}
                  >
                    <Avatar name={u.name} src={u.avatar} size={32} />
                    <div className="min-w-0 flex-1">
                      <p className="truncate text-sm font-bold text-ink">{u.name}</p>
                      <p className="truncate text-xs text-ink-soft">{u.role ?? u.email}</p>
                    </div>
                    <span
                      className={`flex h-5 w-5 items-center justify-center rounded border text-xs ${
                        checked ? 'border-brand bg-brand text-ink' : 'border-line'
                      }`}
                    >
                      {checked && '✓'}
                    </span>
                  </button>
                );
              })
            )}
          </div>
        </Field>

        <Field label="Leader" error={errors.leader_id?.message}>
          <Select {...register('leader_id')} disabled={selectedMembers.length === 0}>
            <option value="">
              {selectedMembers.length === 0 ? 'Select members first…' : 'Select a leader…'}
            </option>
            {selectedMembers.map((m) => (
              <option key={m.id} value={String(m.id)}>
                {m.name}
              </option>
            ))}
          </Select>
        </Field>

        {create.isError && (
          <p className="text-sm text-danger">Could not create the group. Please try again.</p>
        )}
      </form>
    </Modal>
  );
}
