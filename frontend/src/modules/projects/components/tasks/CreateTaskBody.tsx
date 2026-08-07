'use client';

import { useEffect, useMemo, useRef, useState } from 'react';
import { createTaskSchema, useValidatedForm } from '@/core/forms';
import { useAuth, useIsManager } from '@/core/auth/AuthProvider';
import { Button, Field, Input, PlusIcon, PriorityBadge, SegmentedControl, SegmentedItem, Select, Textarea, TrashIcon } from '@/core/ui';
import type { CreateTaskPayload, Priority, ProjectStatus } from '../../types';
import { projectsApi } from '../../api';
import { useCreateTask, useGroup, useGroups, useProject } from '../../hooks';
import {
  AiBanner,
  CoAssigneePanel,
  CollapsiblePanel,
  DetailsPanel,
  EffortSlider,
  groupRoster,
  PRIORITIES,
  readonlyField,
  sectionLabel,
  GroupWorkloadIndicator,
  SubtaskFields,
  TEAL,
  TEAL_OUTLINE,
  WorkloadIndicator,
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
  // Members don't create work directly — they propose it. The backend forces the
  // proposal to be individual + self-assigned, so the form matches that shape
  // instead of offering choices the server would override.
  const isManager = useIsManager();
  const { user } = useAuth();
  const isProposal = !isManager;
  // Assignees are restricted to this project's members — not the whole user
  // directory. (Group members come from the selected group, see below.)
  const projectQuery = useProject(projectId);
  const groupsQuery = useGroups(projectId);
  const users = useMemo(() => projectQuery.data?.members ?? [], [projectQuery.data]);
  const groups = groupsQuery.data?.data ?? [];
  // The proposer's own roster entry, for the advisory workload indicator.
  const selfMember = useMemo(
    () => users.find((u) => u.id === user?.id) ?? null,
    [users, user?.id],
  );

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

  const assignmentType = isProposal ? 'individual' : watch('assignment_type');
  const startValue = watch('start_date');
  const now = new Date();
  const todayStr = `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}-${String(now.getDate()).padStart(2, '0')}`;
  const assigneeIdValue = watch('assignee_id');
  const selectedAssignee =
    users.find((u) => String(u.id) === String(assigneeIdValue)) ?? null;
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
  // On the proposal path every sub-task is yours too, so the picker offers only you.
  const subtaskAssigneeOptions = isProposal
    ? selfMember
      ? [selfMember]
      : []
    : assignmentType === 'group' && roster.length
      ? roster
      : users;

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
        assignee_id: isProposal ? (user?.id ?? null) : stAssignee ? Number(stAssignee) : null,
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

  // --- Draft links: composed here, attached right after the task on submit. ---
  const [links, setLinks] = useState<{ key: string; url: string }[]>([]);
  const [linkOpen, setLinkOpen] = useState(false);
  const [linkUrl, setLinkUrl] = useState('');
  const [linkError, setLinkError] = useState('');
  const linkKey = useRef(0);

  const addDraftLink = () => {
    const url = linkUrl.trim();
    if (!url) return;
    // Validate up front so a bad URL fails here, not after the task is created.
    try {
      new URL(url);
    } catch {
      setLinkError('Enter a valid URL (including https://).');
      return;
    }
    setLinks((prev) => [...prev, { key: `l${linkKey.current++}`, url }]);
    setLinkUrl('');
    setLinkError('');
    setLinkOpen(false);
  };

  const removeDraftLink = (key: string) =>
    setLinks((prev) => prev.filter((l) => l.key !== key));

  const submit = handleSubmit(async (data) => {
    setSubtaskError('');
    const payload: CreateTaskPayload = {
      title: data.title,
      description: data.description || undefined,
      assignment_type: data.assignment_type,
      status_id: data.status_id,
      priority: data.priority,
      deadline: data.deadline || undefined,
      start_date: data.start_date || undefined,
    };
    if (isProposal) {
      // The server overrides these anyway; send the honest values so the form
      // and the stored proposal agree.
      payload.assignment_type = 'individual';
      payload.assignee_id = user?.id ?? null;
      payload.effort_score = effort;
    } else if (data.assignment_type === 'individual') {
      // Empty assignee → unassigned task.
      payload.assignee_id = data.assignee_id ? Number(data.assignee_id) : null;
      payload.effort_score = effort;
    } else {
      payload.group_id = data.group_id;
    }
    // Create the task first, then attach each draft sub-task and link to it.
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
      for (const l of links) {
        await projectsApi.addTaskLink(newTask.id, l.url);
      }
    } catch {
      // The task exists; surface the partial failure and keep the modal open so
      // the remaining drafts aren't silently lost.
      setSubtaskError('The task was created, but a sub-task or link could not be added.');
      return;
    }
    reset();
    setEffort(5);
    setSubtasks([]);
    setLinks([]);
    onClose();
  });

  return (
    <form onSubmit={submit} className="grid grid-cols-1 gap-6 p-4 sm:p-6 md:grid-cols-[1fr_320px] md:gap-8">
      {/* LEFT */}
      <div className="min-w-0 space-y-5">
        {projectSelector}

        <AiBanner />

        {isProposal && (
          <div className="rounded-xl border border-warning/40 bg-warning/10 px-4 py-3 text-sm text-ink">
            This is a <span className="font-semibold">proposal</span>. It is
            assigned to you and stays private until a PM approves it — only then
            does it appear on the board and count toward your workload.
          </div>
        )}

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
            <Field label="Start Date" error={errors.start_date?.message}>
              <Input type="date" min={todayStr} {...register('start_date')} />
            </Field>
            <Field label="Due Date" error={errors.deadline?.message}>
              {/* Can't be in the past, nor before the start date. */}
              <Input
                type="date"
                min={startValue && startValue > todayStr ? startValue : todayStr}
                {...register('deadline')}
              />
            </Field>
          </div>

          {/* Once subtasks are added the parent's own effort is disregarded, so
              hide the slider (workload will come from the subtasks). */}
          {assignmentType === 'individual' && subtasks.length === 0 && (
            <div>
              <p className={`mb-2 ${sectionLabel}`}>Effort Score</p>
              <EffortSlider value={effort} onChange={setEffort} />
            </div>
          )}

          {/* Links — composed now, attached to the task on submit. */}
          <div>
            <p className={`mb-2 ${sectionLabel}`}>Links</p>
            {linkOpen ? (
              <div className="flex items-center gap-2">
                <Input
                  autoFocus
                  value={linkUrl}
                  onChange={(e) => {
                    setLinkUrl(e.target.value);
                    if (linkError) setLinkError('');
                  }}
                  placeholder="https://…"
                  onKeyDown={(e) => {
                    if (e.key === 'Enter') {
                      e.preventDefault();
                      addDraftLink();
                    }
                  }}
                />
                <Button type="button" size="sm" className={TEAL} onClick={addDraftLink}>
                  Add
                </Button>
                <Button
                  type="button"
                  size="sm"
                  variant="ghost"
                  onClick={() => {
                    setLinkUrl('');
                    setLinkError('');
                    setLinkOpen(false);
                  }}
                >
                  Cancel
                </Button>
              </div>
            ) : (
              <button
                type="button"
                onClick={() => setLinkOpen(true)}
                className="flex w-full items-center justify-center gap-2 rounded-lg border border-dashed border-line py-3 text-sm font-semibold text-ink-soft hover:border-brand hover:text-ink"
              >
                <PlusIcon className="h-4 w-4" /> Add Link
              </button>
            )}
            {linkError && <p className="mt-1 text-sm text-danger">{linkError}</p>}
            {links.length > 0 && (
              <ul className="mt-3 space-y-2">
                {links.map((l) => (
                  <li key={l.key} className="flex items-center gap-2 text-sm">
                    <a
                      href={l.url}
                      target="_blank"
                      rel="noreferrer"
                      className="min-w-0 flex-1 truncate text-brand underline hover:text-brand-hover"
                    >
                      {l.url}
                    </a>
                    <button
                      type="button"
                      onClick={() => removeDraftLink(l.key)}
                      aria-label="Remove link"
                      className="shrink-0 text-ink-faint transition-colors hover:text-danger"
                    >
                      <TrashIcon className="h-4 w-4" />
                    </button>
                  </li>
                ))}
              </ul>
            )}
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
                parentDeadline={watch('deadline')}
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
          <p className="text-sm text-danger">
            Could not {isProposal ? 'submit the proposal' : 'create the task'}. Please try again.
          </p>
        )}
        {subtaskError && <p className="text-sm text-danger">{subtaskError}</p>}

        {/* Footer */}
        <div className="flex items-center justify-end gap-3 border-t border-line pt-4">
          <Button type="submit" className={TEAL} disabled={create.isPending}>
            {create.isPending
              ? isProposal
                ? 'Submitting…'
                : 'Creating…'
              : isProposal
                ? subtasks.length > 0
                  ? `Submit Task + ${subtasks.length} Subtask${subtasks.length > 1 ? 's' : ''} for Approval`
                  : 'Submit for Approval'
                : subtasks.length > 0
                  ? `Create Task + ${subtasks.length} Subtask${subtasks.length > 1 ? 's' : ''}`
                  : 'Create Task'}
          </Button>
        </div>
      </div>

      {/* RIGHT */}
      <div className="space-y-5">
        {isProposal ? (
          <>
            <div>
              <p className={sectionLabel}>Assignee</p>
              {/* A proposal is always your own work — the server enforces this,
                  so there is nothing to choose. */}
              <p className={`mt-2 ${readonlyField}`}>{user?.name ?? 'You'}</p>
              <div className="mt-3">
                <WorkloadIndicator user={selfMember ?? null} />
              </div>
            </div>
            <div>
              <p className={sectionLabel}>Review</p>
              <p className="mt-1 text-sm text-ink-soft">
                Goes to a PM for approval before it reaches the board.
              </p>
            </div>
          </>
        ) : (
          <>
        {/* Assignment target */}
        <SegmentedControl>
          {(['individual', 'group'] as const).map((t) => (
            <SegmentedItem
              key={t}
              active={assignmentType === t}
              onClick={() => setValue('assignment_type', t)}
              className="capitalize"
            >
              {t}
            </SegmentedItem>
          ))}
        </SegmentedControl>

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
            {/* Live workload for the chosen assignee (advisory). */}
            <div className="mt-3">
              <WorkloadIndicator user={selectedAssignee} />
            </div>
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
              {/* Live workload for the chosen group (advisory). */}
              <div className="mt-3">
                <GroupWorkloadIndicator group={selectedGroup ?? null} />
              </div>
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
          </>
        )}
      </div>
    </form>
  );
}
