'use client';

import { useEffect, useMemo, useRef, useState } from 'react';
import { createTaskSchema, useValidatedForm } from '@/core/forms';
import { Button, Field, Input, PlusIcon, PriorityBadge, Select, Textarea, TrashIcon } from '@/core/ui';
import type { CreateTaskPayload, Priority, ProjectStatus } from '../../types';
import { projectsApi } from '../../api';
import { useCreateTask, useGroup, useGroups, useUsers } from '../../hooks';
import {
  AiBanner,
  CoAssigneePanel,
  CollapsiblePanel,
  DetailsPanel,
  EffortSlider,
  groupRoster,
  PRIORITIES,
  sectionLabel,
  SubtaskFields,
  TEAL,
  TEAL_OUTLINE,
  WorkloadWarning,
  type SubtaskDraft,
} from './shared';

export function CreateTaskBody({
  projectId,
  statuses,
  onClose,
  projectSelector,
}: {
  projectId: string;
  statuses: ProjectStatus[];
  onClose: () => void;
  projectSelector?: React.ReactNode;
}) {
  const create = useCreateTask(projectId);
  const usersQuery = useUsers();
  const groupsQuery = useGroups(projectId);
  const users = usersQuery.data ?? [];
  const groups = groupsQuery.data?.data ?? [];

  const [effort, setEffort] = useState(5);

  const {
    register,
    handleSubmit,
    watch,
    setValue,
    reset,
    formState: { errors },
  } = useValidatedForm(createTaskSchema, {
    defaultValues: {
      assignment_type: 'individual',
      priority: 'Medium',
      status_id: statuses[0] ? String(statuses[0].id) : '',
    },
  });

  useEffect(() => {
    if (statuses[0]) setValue('status_id', String(statuses[0].id));
  }, [statuses, setValue]);

  const assignmentType = watch('assignment_type');
  const groupIdValue = watch('group_id');
  const selectedGroupId = assignmentType === 'group' && groupIdValue ? groupIdValue : '';
  const groupQuery = useGroup(selectedGroupId);
  const selectedGroup = groupQuery.data;

  // --- Draft sub-tasks: composed here, created right after the task on submit. ---
  const firstStatusId = statuses[0] ? String(statuses[0].id) : '';
  const [subtasks, setSubtasks] = useState<SubtaskDraft[]>([]);
  const [showSubtaskForm, setShowSubtaskForm] = useState(false);
  const [stName, setStName] = useState('');
  const [stAssignee, setStAssignee] = useState('');
  const [stStatus, setStStatus] = useState('');
  const [stPriority, setStPriority] = useState<Priority>('Medium');
  const [stEffort, setStEffort] = useState('');
  const [stDeadline, setStDeadline] = useState('');
  const [stError, setStError] = useState('');
  const [subtaskError, setSubtaskError] = useState('');
  const draftKey = useRef(0);

  // Subtask assignees mirror the view-mode rule: group members for a group task,
  // otherwise any user (unassigned is always allowed).
  const roster = useMemo(() => groupRoster(selectedGroup), [selectedGroup]);
  const subtaskAssigneeOptions = assignmentType === 'group' && roster.length ? roster : users;

  const resetSubtaskForm = () => {
    setStName('');
    setStAssignee('');
    setStStatus('');
    setStPriority('Medium');
    setStEffort('');
    setStDeadline('');
    setStError('');
  };

  const addDraftSubtask = () => {
    const statusId = stStatus || firstStatusId;
    if (!stName.trim() || !statusId || stEffort === '') {
      setStError('Name, status, and effort score are required.');
      return;
    }
    setSubtasks((prev) => [
      ...prev,
      {
        key: `d${draftKey.current++}`,
        name: stName.trim(),
        assignee_id: stAssignee ? Number(stAssignee) : null,
        status_id: statusId,
        priority: stPriority,
        effort_score: Number(stEffort),
        deadline: stDeadline || null,
      },
    ]);
    resetSubtaskForm();
    setShowSubtaskForm(false);
  };

  const removeDraftSubtask = (key: string) =>
    setSubtasks((prev) => prev.filter((s) => s.key !== key));

  const submit = handleSubmit(async (data) => {
    setSubtaskError('');
    const payload: CreateTaskPayload = {
      title: data.title,
      description: data.description || undefined,
      assignment_type: data.assignment_type,
      status_id: data.status_id,
      priority: data.priority,
      deadline: data.deadline || undefined,
    };
    if (data.assignment_type === 'individual') {
      // Empty assignee → unassigned task.
      payload.assignee_id = data.assignee_id ? Number(data.assignee_id) : null;
      payload.effort_score = effort;
    } else {
      payload.group_id = data.group_id;
    }
    // Create the task first, then attach each draft sub-task to the new task.
    const newTask = await create.mutateAsync(payload);
    try {
      for (const s of subtasks) {
        await projectsApi.createSubtask(newTask.id, {
          name: s.name,
          assignee_id: s.assignee_id,
          status_id: s.status_id,
          priority: s.priority,
          effort_score: s.effort_score,
          deadline: s.deadline,
        });
      }
    } catch {
      // The task exists; surface the partial failure and keep the modal open so
      // the remaining drafts aren't silently lost.
      setSubtaskError('The task was created, but a sub-task could not be added.');
      return;
    }
    reset();
    setEffort(5);
    setSubtasks([]);
    onClose();
  });

  return (
    <form onSubmit={submit} className="grid grid-cols-1 gap-6 p-4 sm:p-6 md:grid-cols-[1fr_320px] md:gap-8">
      {/* LEFT */}
      <div className="min-w-0 space-y-5">
        {projectSelector}

        <AiBanner />

        <div>
          <label className="mb-1.5 block text-sm font-bold text-ink">
            Task Name <span className="text-danger">*</span>
          </label>
          <Input
            placeholder="e.g. Redesign onboarding flow"
            className="text-lg font-semibold"
            {...register('title')}
          />
          {errors.title && <p className="mt-1 text-sm text-danger">{errors.title.message}</p>}
        </div>

        <div>
          <label className="mb-1.5 block text-sm font-bold text-ink">Description</label>
          <Textarea rows={4} placeholder="Task Description…" {...register('description')} />
          {errors.description && <p className="mt-1 text-sm text-danger">{errors.description.message}</p>}
        </div>

        <DetailsPanel>
          <div className="grid grid-cols-2 gap-4">
            <Field label="Status" error={errors.status_id?.message}>
              <Select {...register('status_id')}>
                {statuses.map((s) => (
                  <option key={s.id} value={String(s.id)}>
                    {s.name}
                  </option>
                ))}
              </Select>
            </Field>
            <Field label="Priority" error={errors.priority?.message}>
              <Select {...register('priority')}>
                {PRIORITIES.map((p) => (
                  <option key={p} value={p}>
                    {p}
                  </option>
                ))}
              </Select>
            </Field>
          </div>

          <div className="grid grid-cols-2 gap-4">
            <div>
              <span className={`mb-1.5 block ${sectionLabel}`}>Start Date</span>
              <Input type="date" title="Not tracked yet — placeholder" />
            </div>
            <Field label="Due Date">
              <Input type="date" {...register('deadline')} />
            </Field>
          </div>

          {assignmentType === 'individual' && (
            <div>
              <p className={`mb-2 ${sectionLabel}`}>Effort Score</p>
              <EffortSlider value={effort} onChange={setEffort} />
            </div>
          )}

          {/* Links attach after the task exists. */}
          <div>
            <p className={`mb-2 ${sectionLabel}`}>Links</p>
            <button
              type="button"
              disabled
              title="Add links after creating the task"
              className="flex w-full items-center justify-center gap-2 rounded-lg border border-dashed border-[#c9c9ba] py-3 text-sm font-semibold text-ink-soft"
            >
              <PlusIcon className="h-4 w-4" /> Add Link
            </button>
          </div>
        </DetailsPanel>

        {/* Sub-tasks — composed now, created together with the task on submit. */}
        <CollapsiblePanel title="Sub-tasks">
          {subtasks.length > 0 ? (
            <ul className="space-y-2">
              {subtasks.map((s) => (
                <li
                  key={s.key}
                  className="flex items-center gap-3 rounded-xl border border-line bg-surface px-4 py-3"
                >
                  <span className="min-w-0 flex-1 truncate font-semibold text-ink">{s.name}</span>
                  <PriorityBadge priority={s.priority} />
                  <button
                    type="button"
                    onClick={() => removeDraftSubtask(s.key)}
                    aria-label="Remove sub-task"
                    className="shrink-0 text-ink-faint transition-colors hover:text-danger"
                  >
                    <TrashIcon className="h-4 w-4" />
                  </button>
                </li>
              ))}
            </ul>
          ) : (
            <p className="text-sm text-ink-faint">No sub-tasks yet.</p>
          )}

          {!showSubtaskForm && (
            <Button
              type="button"
              size="sm"
              className={TEAL_OUTLINE}
              onClick={() => setShowSubtaskForm(true)}
            >
              <PlusIcon className="h-4 w-4" /> Add Subtask
            </Button>
          )}

          {showSubtaskForm && (
            <div className="space-y-3 rounded-xl border border-line bg-canvas p-4">
              <SubtaskFields
                name={stName}
                assignee={stAssignee}
                status={stStatus || firstStatusId}
                priority={stPriority}
                effort={stEffort}
                deadline={stDeadline}
                onName={setStName}
                onAssignee={setStAssignee}
                onStatus={setStStatus}
                onPriority={setStPriority}
                onEffort={setStEffort}
                onDeadline={setStDeadline}
                statuses={statuses}
                assigneeOptions={subtaskAssigneeOptions}
                isGroupTask={assignmentType === 'group'}
              />
              {stError && <p className="text-sm text-danger">{stError}</p>}
              <div className="flex justify-end gap-2">
                <Button
                  type="button"
                  size="sm"
                  variant="outline"
                  onClick={() => {
                    resetSubtaskForm();
                    setShowSubtaskForm(false);
                  }}
                >
                  Cancel
                </Button>
                <Button type="button" size="sm" className={TEAL} onClick={addDraftSubtask}>
                  <PlusIcon className="h-4 w-4" /> Add Subtask
                </Button>
              </div>
            </div>
          )}
        </CollapsiblePanel>

        {create.isError && (
          <p className="text-sm text-danger">Could not create the task. Please try again.</p>
        )}
        {subtaskError && <p className="text-sm text-danger">{subtaskError}</p>}

        {/* Footer */}
        <div className="flex items-center justify-end gap-3 border-t border-line pt-4">
          <Button type="submit" className={TEAL} disabled={create.isPending}>
            {create.isPending
              ? 'Creating…'
              : subtasks.length > 0
                ? `Create Task + ${subtasks.length} Subtask${subtasks.length > 1 ? 's' : ''}`
                : 'Create Task'}
          </Button>
        </div>
      </div>

      {/* RIGHT */}
      <div className="space-y-5">
        {/* Assignment target */}
        <div className="inline-flex rounded-lg border border-line p-1">
          {(['individual', 'group'] as const).map((t) => (
            <button
              key={t}
              type="button"
              onClick={() => setValue('assignment_type', t)}
              className={`rounded-md px-4 py-1.5 text-sm font-bold capitalize transition-colors ${
                assignmentType === t
                  ? 'bg-white text-[#0f766e] shadow-sm'
                  : 'text-ink-soft hover:text-ink'
              }`}
            >
              {t}
            </button>
          ))}
        </div>

        <WorkloadWarning
          label={assignmentType === 'group' ? 'This group has a heavy workload' : 'This assignee has a heavy workload'}
        />

        {assignmentType === 'individual' ? (
          <div>
            <p className={sectionLabel}>Assignee</p>
            <Select defaultValue="" className="mt-2" {...register('assignee_id')}>
              <option value="">Unassigned</option>
              {users.map((u) => (
                <option key={u.id} value={String(u.id)}>
                  {u.name}
                </option>
              ))}
            </Select>
            {errors.assignee_id && <p className="mt-1 text-sm text-danger">{errors.assignee_id.message}</p>}
          </div>
        ) : (
          <>
            <div>
              <p className={sectionLabel}>Group Assignee</p>
              <Select defaultValue="" className="mt-2" {...register('group_id')}>
                <option value="" disabled>
                  Select a group…
                </option>
                {groups.map((g) => (
                  <option key={g.id} value={String(g.id)}>
                    {g.name}
                  </option>
                ))}
              </Select>
              {errors.group_id && <p className="mt-1 text-sm text-danger">{errors.group_id.message}</p>}
            </div>
            <div>
              <p className={sectionLabel}>Members</p>
              <CoAssigneePanel group={selectedGroup}/>
            </div>
          </>
        )}

        <div>
          <p className={sectionLabel}>Assigned By</p>
          {/* PLACEHOLDER — the backend records the creator implicitly; no field yet. */}
          <p className="mt-1 text-sm text-ink-soft">You (recorded on create)</p>
        </div>
      </div>
    </form>
  );
}
