"use client";

import { projectSchema, useValidatedForm } from "@/core/forms";
import { Button, Field, Input, Modal, Select, Textarea } from "@/core/ui";
import { useUpdateProject } from "@/modules/projects/hooks/use-projects";
import type { Project } from "@/modules/projects/types/project";

const PRIORITY_OPTIONS = [
  { value: 'low', label: 'Low' },
  { value: 'medium', label: 'Medium' },
  { value: 'high', label: 'High' },
];

const PRESET_COLORS = [
  '#6366f1',
  '#ec4899',
  '#10b981',
  '#3b82f6',
  '#f59e0b',
  '#8b5cf6',
  '#14b8a6',
  '#f97316',
  '#ef4444',
  '#06b6d4',
];

/**
 * Edit a project's name/description/priority/color (Admin/PM). Render only while
 * open so the form mounts fresh with the project's values as defaults.
 */
export function EditProjectModal({
  project,
  onClose,
}: {
  project: Project;
  onClose: () => void;
}): React.ReactElement {
  const update = useUpdateProject();
  const {
    register,
    handleSubmit,
    setValue,
    watch,
    formState: { errors },
  } = useValidatedForm(projectSchema, {
    defaultValues: {
      name: project.name,
      description: project.description ?? '',
      priority: project.priority ?? 'medium',
      color: project.color ?? '#6366f1',
    },
  });

  const selectedColor = watch('color');

  const submit = handleSubmit(async (data) => {
    await update.mutateAsync({
      id: project.id,
      payload: {
        name: data.name,
        description: data.description || null,
        priority: data.priority,
        color: data.color,
      },
    });
    onClose();
  });

  return (
    <Modal
      open
      onClose={onClose}
      title="Edit Project"
      width={560}
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
          <Input placeholder="e.g. Mobile App Redesign" {...register('name')} />
        </Field>

        <Field label="Description" error={errors.description?.message}>
          <Textarea rows={3} placeholder="What is this project about?" {...register('description')} />
        </Field>

        <div className="grid grid-cols-2 gap-4">
          <Field label="Priority" error={errors.priority?.message}>
            <Select {...register('priority')}>
              {PRIORITY_OPTIONS.map((opt) => (
                <option key={opt.value} value={opt.value}>
                  {opt.label}
                </option>
              ))}
            </Select>
          </Field>

          <Field label="Color">
            <div className="space-y-2">
              <div className="flex items-center gap-2">
                <div
                  className="h-8 w-8 rounded-md border border-line"
                  style={{ backgroundColor: selectedColor }}
                />
                <Input
                  type="color"
                  value={selectedColor}
                  onChange={(e) => setValue('color', e.target.value)}
                  className="h-8 w-14 cursor-pointer border-0 p-0"
                />
                <Input
                  type="text"
                  value={selectedColor}
                  onChange={(e) => setValue('color', e.target.value)}
                  placeholder="#6366f1"
                  className="flex-1"
                />
              </div>
              <div className="flex flex-wrap gap-1.5">
                {PRESET_COLORS.map((c) => (
                  <button
                    key={c}
                    type="button"
                    onClick={() => setValue('color', c)}
                    className={`h-6 w-6 rounded-md border-2 transition-all ${
                      selectedColor === c ? 'border-ink scale-110' : 'border-transparent hover:scale-105'
                    }`}
                    style={{ backgroundColor: c }}
                  />
                ))}
              </div>
            </div>
          </Field>
        </div>

        {update.isError && (
          <p className="text-sm text-danger">Could not save the project. Please try again.</p>
        )}
      </form>
    </Modal>
  );
}
