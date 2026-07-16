'use client';

import { useValidatedForm, workspaceSchema } from '@/core/forms';
import { Button, Field, Input, Modal, Textarea } from '@/core/ui';
import { useUpdateWorkspace } from '../hooks';
import type { Workspace } from '../types';

/**
 * Edit a workspace's name/description (Admin/PM). Render only while open so the
 * form mounts fresh with the workspace's values as defaults.
 */
export function EditWorkspaceModal({
  workspace,
  onClose,
}: {
  workspace: Workspace;
  onClose: () => void;
}) {
  const update = useUpdateWorkspace();
  const {
    register,
    handleSubmit,
    formState: { errors },
  } = useValidatedForm(workspaceSchema, {
    defaultValues: {
      name: workspace.name,
      description: workspace.description ?? '',
    },
  });

  const submit = handleSubmit(async (data) => {
    await update.mutateAsync({
      id: workspace.id,
      payload: { name: data.name, description: data.description || null },
    });
    onClose();
  });

  return (
    <Modal
      open
      onClose={onClose}
      title="Edit Workspace"
      footer={
        <>
          <Button variant="outline" onClick={onClose} type="button">
            Cancel
          </Button>
          <Button onClick={submit} disabled={update.isPending} type="button">
            {update.isPending ? 'Saving…' : 'Save changes'}
          </Button>
        </>
      }
    >
      <form onSubmit={submit} className="space-y-4">
        <Field label="Name" error={errors.name?.message}>
          <Input placeholder="e.g. Design Team" {...register('name')} />
        </Field>
        <Field label="Description" error={errors.description?.message}>
          <Textarea rows={3} placeholder="What is this workspace for?" {...register('description')} />
        </Field>
        {update.isError && (
          <p className="text-sm text-danger">Could not save the workspace. Please try again.</p>
        )}
      </form>
    </Modal>
  );
}
