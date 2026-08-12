"use client";

import { editGroupSchema, useValidatedForm } from "@/core/forms";
import { Button, Field, Input, Modal, Select } from "@/core/ui";
import type { Group } from "@/modules/projects/types/group";
import { useUpdateGroup } from "@/modules/projects/hooks/use-groups";

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
 * Edit a group's name / color / leader (Admin/PM). Members are managed
 * separately (Add Member), so the leader is chosen from the group's current
 * members. Render only while editing so the form mounts fresh with defaults.
 */
export function EditGroupModal({
  group,
  onClose,
}: {
  group: Group;
  onClose: () => void;
}): React.ReactElement {
  const update = useUpdateGroup();
  const members = group.members ?? [];

  const {
    register,
    handleSubmit,
    watch,
    setValue,
    formState: { errors },
  } = useValidatedForm(editGroupSchema, {
    defaultValues: {
      name: group.name,
      color: group.color,
      leader_id: group.leader ? String(group.leader.id) : '',
    },
  });

  const color = watch('color');

  const submit = handleSubmit(async (data) => {
    try {
      await update.mutateAsync({
        id: group.id,
        payload: {
          name: data.name,
          color: data.color,
          leader_id: Number(data.leader_id),
        },
      });
    } catch {
      // The hook's onError has already explained the failure; this stops the
      // form from closing and resetting as though the save had gone through.
      return;
    }
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
          <Select {...register('leader_id')} disabled={members.length === 0}>
            <option value="">
              {members.length === 0 ? 'No members in this group' : 'Select a leader…'}
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
