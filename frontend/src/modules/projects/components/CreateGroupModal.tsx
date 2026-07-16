'use client';

import { useEffect } from 'react';
import { groupSchema, useValidatedForm } from '@/core/forms';
import { Button, Field, Input, Modal, Select } from '@/core/ui';
import { useCreateGroup, useProject, useProjects } from '../hooks';

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
  /** Optional: pre-select and lock the group to a specific project. */
  projectId?: string;
}

export function CreateGroupModal({ open, onClose, projectId: fixedProjectId }: CreateGroupModalProps): React.ReactElement {
  const create = useCreateGroup();
  const projectsQuery = useProjects();
  const projects = projectsQuery.data?.data ?? [];

  const {
    register,
    handleSubmit,
    reset,
    watch,
    setValue,
    formState: { errors },
  } = useValidatedForm(groupSchema, {
    defaultValues: { project_id: fixedProjectId ?? '', name: '', color: COLOR_PRESETS[0], leader_id: '' },
  });

  const projectId = watch('project_id');
  const color = watch('color');

  // Leader options come from the selected project's members — a group is a pod
  // within the project, so its leader must be on the project.
  const projectQuery = useProject(projectId);
  const members = projectQuery.data?.members ?? [];

  // Reset the leader whenever the project changes so a stale id is never submitted.
  useEffect(() => {
    setValue('leader_id', '');
  }, [projectId, setValue]);

  const submit = handleSubmit(async (data) => {
    await create.mutateAsync({
      project_id: data.project_id,
      name: data.name,
      color: data.color,
      leader_id: Number(data.leader_id),
    });
    reset();
    onClose();
  });

  const close = (): void => {
    reset();
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
        {!fixedProjectId && (
          <Field label="Project" error={errors.project_id?.message}>
            <Select {...register('project_id')}>
              <option value="">Select a project…</option>
              {projects.map((p) => (
                <option key={p.id} value={String(p.id)}>
                  {p.name}
                </option>
              ))}
            </Select>
          </Field>
        )}

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
          <Select {...register('leader_id')} disabled={!projectId || projectQuery.isLoading}>
            <option value="">
              {!projectId
                ? 'Select a project first…'
                : projectQuery.isLoading
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

        {create.isError && (
          <p className="text-sm text-danger">Could not create the group. Please try again.</p>
        )}
      </form>
    </Modal>
  );
}
