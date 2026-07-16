'use client';

import { groupSchema, useValidatedForm } from '@/core/forms';
import { Button, Field, Input, Modal, Select } from '@/core/ui';
import type { Group } from '../types';
import { useProject, useUpdateGroup } from '../hooks';

// Same preset swatches as CreateGroupModal; the chosen hex is stored on the form.
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

/**
 * Edit a group's name / color / leader (Admin/PM). A group's project is
 * immutable, so it's shown read-only. Render only while editing so the form
 * mounts fresh with the group's values as defaults.
 */
export function EditGroupModal({
  group,
  onClose,
}: {
  group: Group;
  onClose: () => void;
}): React.ReactElement {
  const update = useUpdateGroup();
  // Leader options come from the group's project members.
  const projectQuery = useProject(group.project_id);
  const members = projectQuery.data?.members ?? [];

  const {
    register,
    handleSubmit,
    watch,
    setValue,
    formState: { errors },
  } = useValidatedForm(groupSchema, {
    defaultValues: {
      project_id: group.project_id,
      name: group.name,
      color: group.color,
      leader_id: group.leader ? String(group.leader.id) : '',
    },
  });

  const color = watch('color');

  const submit = handleSubmit(async (data) => {
    await update.mutateAsync({
      id: group.id,
      payload: {
        name: data.name,
        color: data.color,
        leader_id: Number(data.leader_id),
      },
    });
    onClose();
  });

  return (
    <Modal
      open
      onClose={onClose}
      title="Edit Group"
      width={520}
      footer={
        <>
          <Button variant="outline" onClick={onClose} type="button">
            Cancel
          </Button>
          <Button onClick={submit} disabled={update.isPending} type="button">
            {update.isPending ? 'Saving…' : 'Save Changes'}
          </Button>
        </>
      }
    >
      <form onSubmit={submit} className="space-y-4">
        <Field label="Project">
          <Input value={projectQuery.data?.name ?? '—'} disabled readOnly />
        </Field>

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

        <Field label="Leader" error={errors.leader_id?.message}>
          <Select {...register('leader_id')} disabled={projectQuery.isLoading}>
            <option value="">
              {projectQuery.isLoading
                ? 'Loading members…'
                : members.length === 0
                  ? 'No members on this project'
                  : 'Select a leader…'}
            </option>
            {members.map((m) => (
              <option key={m.id} value={String(m.id)}>
                {m.name}
              </option>
            ))}
          </Select>
        </Field>

        {update.isError && (
          <p className="text-sm text-danger">Could not save the group. Please try again.</p>
        )}
      </form>
    </Modal>
  );
}
