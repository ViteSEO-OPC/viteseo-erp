'use client';

import { useMemo, useState } from 'react';
import { ArrowUpIcon, Avatar, Badge, Button, CalendarIcon, ConfirmDialog, Field, Input, LinkIcon, PencilIcon, PlusIcon, PriorityBadge, SegmentedControl, SegmentedItem, Select, Textarea, TrashIcon } from '@/core/ui';
import { useAuth, useIsManager } from '@/core/auth/AuthProvider';
import type { User } from '@/core/types';
import type { AssignmentType, CreateTaskPayload, Priority, ProjectStatus, Subtask, Task } from '../../types';
import { isOverdue } from '../../overdue';
import {
  useAddComment,
  useAddLink,
  useApproveSubtask,
  useApproveTask,
  useCreateSubtask,
  useDeleteSubtask,
  useDeleteTask,
  useGroup,
  useGroups,
  useProject,
  useRejectSubtask,
  useRejectTask,
  useResubmitSubtask,
  useResubmitTask,
  useUpdateSubtask,
  useUpdateTask,
} from '../../hooks';
import { RejectReasonDialog } from '../approvals/RejectReasonDialog';
import {
  activityLine,
  ApprovalBadge,
  ApprovalBanner,
  CoAssigneePanel,
  CollapsiblePanel,
  DetailsPanel,
  effortRing,
  EffortScoreInput,
  EffortSlider,
  formatDate,
  formatDateTime,
  groupRoster,
  PILL_SELECT,
  PRIORITIES,
  priorityPill,
  readonlyField,
  sectionLabel,
  statusPill,
  statusTone,
  GroupWorkloadIndicator,
  SubtaskFields,
  TEAL,
  TEAL_OUTLINE,
  toDateInput,
  WorkloadIndicator,
} from './shared';

/**
 * A subtask in the view-mode list. Editing is RBAC-scoped:
 *  - `canFullEdit` (Admin/PM, the parent group's leader, or the author of an
 *    unapproved proposal) → edit every field.
 *  - `canEditStatus` (the above, or the subtask's own assignee) → status only,
 *    and never on a proposal: it isn't on the board yet.
 *  - `canReview` (Admin/PM on a proposal) → decide it here. Rejecting is only
 *    offered while it is still pending — one already sent back cannot be
 *    rejected again; it is the author's move now.
 *  - `canResubmit` (its author, once rejected) → send it back for review.
 *  - otherwise read-only.
 */
function SubtaskRow({
  subtask,
  statuses,
  assigneeOptions,
  canFullEdit,
  canEditStatus,
  canReview = false,
  canResubmit = false,
  update,
  onDelete,
  parentDeadline,
}: {
  subtask: Subtask;
  statuses: ProjectStatus[];
  assigneeOptions: User[];
  canFullEdit: boolean;
  canEditStatus: boolean;
  canReview?: boolean;
  canResubmit?: boolean;
  update: ReturnType<typeof useUpdateSubtask>;
  onDelete?: (subtask: Subtask) => void;
  parentDeadline?: string | null;
}) {
  const approveSubtask = useApproveSubtask();
  const rejectSubtask = useRejectSubtask();
  const resubmitSubtask = useResubmitSubtask();
  const [rejecting, setRejecting] = useState(false);
  const [editing, setEditing] = useState(false);
  const [name, setName] = useState(subtask.name);
  const [description, setDescription] = useState(subtask.description ?? '');
  const [assigneeId, setAssigneeId] = useState(
    subtask.assignee_id ? String(subtask.assignee_id) : '',
  );
  const [priority, setPriority] = useState<Priority>(subtask.priority);
  const [effort, setEffort] = useState<number>(subtask.effort_score);
  const [deadline, setDeadline] = useState(toDateInput(subtask.deadline));

  const changeStatus = (statusId: string) =>
    update.mutate({ id: subtask.id, payload: { status_id: statusId } });

  const cancel = () => {
    setName(subtask.name);
    setDescription(subtask.description ?? '');
    setAssigneeId(subtask.assignee_id ? String(subtask.assignee_id) : '');
    setPriority(subtask.priority);
    setEffort(subtask.effort_score);
    setDeadline(toDateInput(subtask.deadline));
    setEditing(false);
  };

  const save = () => {
    const payload: Record<string, unknown> = {};
    if (name.trim() && name.trim() !== subtask.name) payload.name = name.trim();
    if (description !== (subtask.description ?? '')) payload.description = description;
    const nextAssignee = assigneeId ? Number(assigneeId) : null;
    if (nextAssignee !== subtask.assignee_id) payload.assignee_id = nextAssignee;
    if (priority !== subtask.priority) payload.priority = priority;
    if (effort !== subtask.effort_score) payload.effort_score = effort;
    if ((deadline || null) !== (toDateInput(subtask.deadline) || null)) {
      payload.deadline = deadline || null;
    }
    if (Object.keys(payload).length === 0) {
      setEditing(false);
      return;
    }
    update.mutate({ id: subtask.id, payload }, { onSuccess: () => setEditing(false) });
  };

  return (
    <li className="rounded-xl border border-[#e4e4d8] bg-white px-3 py-2.5">
      <div className="flex items-start gap-3">
        <span className="shrink-0">
          {subtask.assignee ? (
            <Avatar name={subtask.assignee.name} src={subtask.assignee.avatar} size={36} />
          ) : (
            <Avatar name="?" size={36} />
          )}
        </span>
        <div className="min-w-0 flex-1">
          <p className="flex items-center gap-1.5 text-sm font-bold text-ink">
            {subtask.flag_reason && (
              <span className="shrink-0 text-danger" title={subtask.flag_reason} aria-label="Flagged">
                ⚑
              </span>
            )}
            <span className="min-w-0 break-words sm:truncate">{subtask.name}</span>
            <ApprovalBadge status={subtask.approval_status} />
          </p>
          {subtask.approval_status === 'rejected' && subtask.rejection_reason && (
            <p className="mt-1 text-xs text-danger">{subtask.rejection_reason}</p>
          )}
          <p className="mt-0.5 flex items-center gap-1.5 text-xs text-ink-faint">
            <span
              className={
                isOverdue(subtask.deadline, subtask.status?.key)
                  ? 'font-bold text-danger'
                  : ''
              }
            >
              Due: {formatDate(subtask.deadline)}
            </span>
            {isOverdue(subtask.deadline, subtask.status?.key) && (
              <span className="shrink-0 text-danger" title="Overdue" aria-label="Overdue">
                ⚠
              </span>
            )}
          </p>

          {/* Meta chips wrap under the name so nothing overlaps on narrow widths. */}
          <div className="mt-2 flex flex-wrap items-center gap-2">
            <span
              className={`flex h-7 w-7 shrink-0 items-center justify-center rounded-full border-2 text-xs font-bold ${effortRing(subtask.priority)}`}
              title={`Effort score: ${subtask.effort_score}`}
            >
              {subtask.effort_score}
            </span>
            {canEditStatus ? (
              <Select
                value={subtask.status_id ?? ''}
                onChange={(e) => changeStatus(e.target.value)}
                className="h-8 w-auto py-0 text-xs"
                aria-label="Subtask status"
              >
                {subtask.status_id == null && (
                  <option value="" disabled>
                    No status
                  </option>
                )}
                {statuses.map((s) => (
                  <option key={s.id} value={String(s.id)}>
                    {s.name}
                  </option>
                ))}
              </Select>
            ) : (
              subtask.status && <Badge tone={statusTone(subtask.status.key)}>{subtask.status.name}</Badge>
            )}
            <PriorityBadge priority={subtask.priority} />

            <RejectReasonDialog
              open={rejecting}
              subject={subtask.name}
              isPending={rejectSubtask.isPending}
              error={rejectSubtask.isError ? 'Could not reject. Please try again.' : null}
              onCancel={() => setRejecting(false)}
              onConfirm={(reason) => {
                rejectSubtask.mutate({ id: subtask.id, reason });
                setRejecting(false);
              }}
            />

            {canReview && (
              <div className="flex items-center gap-1.5">
                <Button
                  size="xs"
                  className={TEAL}
                  disabled={approveSubtask.isPending}
                  onClick={() => approveSubtask.mutate(subtask.id)}
                >
                  Approve
                </Button>
                {subtask.approval_status === 'pending' && (
                  <Button
                    size="xs"
                    variant="outline"
                    className="text-danger"
                    disabled={rejectSubtask.isPending}
                    onClick={() => setRejecting(true)}
                  >
                    Reject
                  </Button>
                )}
              </div>
            )}
            {canResubmit && (
              <Button
                size="xs"
                className={TEAL}
                disabled={resubmitSubtask.isPending}
                onClick={() => resubmitSubtask.mutate(subtask.id)}
              >
                Re-submit for approval
              </Button>
            )}

            {/* Edit / delete — right-aligned within the meta row so the name and
                due date above span the full card width (wrapping on small screens). */}
            {canFullEdit && !editing && (
              <div className="ml-auto flex items-center gap-1.5">
                <button
                  type="button"
                  onClick={() => setEditing(true)}
                  aria-label="Edit subtask"
                  className="flex h-7 w-7 items-center justify-center rounded-lg border border-line text-ink-soft outline-none transition-colors hover:border-brand hover:text-ink focus-visible:ring-[3px] focus-visible:ring-ring/50"
                >
                  <PencilIcon className="h-3.5 w-3.5" />
                </button>
                {onDelete && (
                  <button
                    type="button"
                    onClick={() => onDelete(subtask)}
                    aria-label="Delete subtask"
                    className="flex h-7 w-7 items-center justify-center rounded-lg border border-line text-ink-soft outline-none transition-colors hover:border-danger hover:text-danger focus-visible:ring-[3px] focus-visible:ring-ring/50"
                  >
                    <TrashIcon className="h-3.5 w-3.5" />
                  </button>
                )}
              </div>
            )}
          </div>
        </div>
      </div>

      {editing && canFullEdit && (
        <div className="mt-3 space-y-3 border-t border-line pt-3">
          <Field label="Subtask name">
            <Input value={name} onChange={(e) => setName(e.target.value)} />
          </Field>
          <Field label="Description">
            <Textarea rows={2} value={description} onChange={(e) => setDescription(e.target.value)} />
          </Field>
          <div className="grid grid-cols-2 gap-3">
            <Field label="Assignee">
              <Select value={assigneeId} onChange={(e) => setAssigneeId(e.target.value)}>
                <option value="">Unassigned</option>
                {assigneeOptions.map((u) => (
                  <option key={u.id} value={String(u.id)}>
                    {u.name}
                  </option>
                ))}
              </Select>
            </Field>
            <Field label="Priority">
              <Select value={priority} onChange={(e) => setPriority(e.target.value as Priority)}>
                {PRIORITIES.map((p) => (
                  <option key={p} value={p}>
                    {p}
                  </option>
                ))}
              </Select>
            </Field>
            <Field label="Effort Score">
              <EffortScoreInput
                value={effort}
                // A cleared field falls back to 0 — effort_score is required.
                onChange={(v) => setEffort(v === '' ? 0 : Number(v))}
              />
            </Field>
            <Field label="Deadline">
              <Input
                type="date"
                value={deadline}
                min={toDateInput(new Date().toISOString())}
                max={parentDeadline ? toDateInput(parentDeadline) : undefined}
                onChange={(e) => setDeadline(e.target.value)}
              />
            </Field>
          </div>
          {update.isError && (
            <p className="text-sm text-danger">Could not save the sub-task. Please try again.</p>
          )}
          <div className="flex justify-end gap-2">
            <Button type="button" size="sm" variant="ghost" onClick={cancel}>
              Cancel
            </Button>
            <Button type="button" size="sm" onClick={save} disabled={update.isPending}>
              {update.isPending ? 'Saving…' : 'Save changes'}
            </Button>
          </div>
        </div>
      )}
    </li>
  );
}

export function ViewTaskBody({
  projectId,
  statuses,
  task,
  onClose,
}: {
  projectId: string;
  statuses: ProjectStatus[];
  task: Task;
  onClose: () => void;
}) {
  const update = useUpdateTask(projectId);
  const deleteTask = useDeleteTask(projectId);
  const deleteSubtask = useDeleteSubtask(task.id);
  // Assignees are restricted to this project's members — not the whole user
  // directory. (Group tasks draw their roster from the group, see below.)
  const projectQuery = useProject(projectId);
  const users = useMemo(() => projectQuery.data?.members ?? [], [projectQuery.data]);
  // Options for the individual-assignee picker: the project's members, plus the
  // task's current assignee if they've since been removed from the project — so
  // an existing assignment still renders instead of silently blanking out.
  const individualAssigneeOptions = useMemo(() => {
    if (task.assignee && !users.some((u) => u.id === task.assignee!.id)) {
      return [task.assignee, ...users];
    }
    return users;
  }, [users, task.assignee]);

  const [confirmDeleteTask, setConfirmDeleteTask] = useState(false);
  const [subtaskToDelete, setSubtaskToDelete] = useState<Subtask | null>(null);

  const handleDeleteTask = async () => {
    await deleteTask.mutateAsync(task.id);
    setConfirmDeleteTask(false);
    onClose();
  };

  const handleDeleteSubtask = async () => {
    if (!subtaskToDelete) return;
    await deleteSubtask.mutateAsync(subtaskToDelete.id);
    setSubtaskToDelete(null);
  };

  const { user } = useAuth();
  const isManager = useIsManager();
  // Group-assigned task → subtask assignees and co-assignees come from the group.
  const isGroupTask = task.assignment_type === 'group';
  const groupQuery = useGroup(isGroupTask ? task.group_id ?? '' : '');
  const roster = useMemo(() => groupRoster(groupQuery.data), [groupQuery.data]);
  // Subtasks may be created by Admin/PM (any task) or by the group's leader
  // (group tasks). A member may PROPOSE one on work that is already theirs —
  // the parent is assigned to them, they're in its group, or it's their own
  // proposal — which then goes through the same review as a task proposal.
  const isGroupLeader = isGroupTask && groupQuery.data?.leader?.id === user?.id;
  const isGroupMember = isGroupTask && roster.some((m) => m.id === user?.id);
  const ownsThisWork =
    (task.assignee_id != null && task.assignee_id === user?.id) ||
    (task.submitted_by_id != null && task.submitted_by_id === user?.id) ||
    isGroupMember;
  const canAddSubtask = isManager || isGroupLeader || ownsThisWork;

  // RBAC for editing the task itself:
  //  - Admin/PM  → edit every field.
  //  - The individual assignee, or (on a group task) the group's leader →
  //    change the status only.
  //  - Regular group members do NOT edit the parent task — they only edit the
  //    status of the subtask assigned to them (see SubtaskRow).
  //  - Full-detail editing stays Admin/PM only.
  const isAssignee = task.assignee_id != null && task.assignee_id === user?.id;
  // Review state. An unapproved task isn't on a board yet, so nobody advances
  // its status; its author keeps full editing rights so a rejection can be
  // acted on, and a reviewer edits it before deciding.
  const isProposal = task.approval_status !== 'approved';
  const isProposalAuthor =
    task.submitted_by_id != null && task.submitted_by_id === user?.id;
  const canFullEdit = isManager || (isProposal && isProposalAuthor);
  const canEditStatus =
    !isProposal && (isManager || isAssignee || isGroupLeader);
  const canReview = isManager && isProposal;
  // A rejected proposal is already sent back — the next move is the author's,
  // so only a still-pending one can be rejected.
  const canRejectTask = canReview && task.approval_status === 'pending';
  const canResubmit = isProposal && isProposalAuthor && task.approval_status === 'rejected';
  // Group members are the valid subtask assignees (ERD R5); fall back to all users otherwise.
  const assigneeOptions = isGroupTask && roster.length ? roster : users;
  // const [coAssignees, setCoAssignees] = useState<number[]>([]);
  // const toggleCoAssignee = (id: number) =>
  //   setCoAssignees((prev) => (prev.includes(id) ? prev.filter((x) => x !== id) : [...prev, id]));

  const approveTask = useApproveTask();
  const rejectTask = useRejectTask();
  const resubmitTask = useResubmitTask();
  const [rejectingTask, setRejectingTask] = useState(false);

  const addLink = useAddLink(task.id);
  const addComment = useAddComment(task.id);
  const createSubtask = useCreateSubtask(task.id);
  const updateSubtask = useUpdateSubtask(task.id);

  // Subtask edit RBAC mirrors the backend: Admin/PM and the parent group's
  // leader may edit the full subtask; the subtask's own assignee may change the
  // status only (see SubtaskRow / SubtaskController).
  const canFullEditSubtask = isManager || isGroupLeader;

  // Subtasks, comments, links and activity come bundled in the single full-detail
  // fetch (GET /tasks/{uuid}); no separate round-trips. Mutations invalidate the
  // task-detail query, so these lists refresh after an add/change.
  const links = task.links ?? [];
  const comments = task.comments ?? [];
  const subtasks = task.subtasks ?? [];
  const activity = useMemo(
    () =>
      [...(task.activity_logs ?? [])].sort(
        (a, b) => new Date(b.created_at).getTime() - new Date(a.created_at).getTime(),
      ),
    [task.activity_logs],
  );

  const [linkOpen, setLinkOpen] = useState(false);
  const [linkUrl, setLinkUrl] = useState('');
  const [comment, setComment] = useState('');

  // Inline editing of the core task fields (left column).
  const [editing, setEditing] = useState(false);
  const [title, setTitle] = useState(task.title);
  const [description, setDescription] = useState(task.description ?? '');
  const [effort, setEffort] = useState(task.effort_score ?? 0);
  const [deadline, setDeadline] = useState(toDateInput(task.deadline));
  const [startDate, setStartDate] = useState(toDateInput(task.start_date));
  const [assigneeId, setAssigneeId] = useState(task.assignee_id ? String(task.assignee_id) : '');
  // Editable assignment (individual ↔ group) — changing it re-homes the task.
  const [assignmentType, setAssignmentType] = useState<AssignmentType>(task.assignment_type);
  const [groupId, setGroupId] = useState(task.group_id ?? '');
  const [confirmSave, setConfirmSave] = useState(false);
  const [saveError, setSaveError] = useState('');

  const editGroupsQuery = useGroups(projectId);
  const editGroups = editGroupsQuery.data?.data ?? [];

  // Today (local) as YYYY-MM-DD — the earliest selectable date for the pickers.
  const nowLocal = new Date();
  const todayStr = `${nowLocal.getFullYear()}-${String(nowLocal.getMonth() + 1).padStart(2, '0')}-${String(nowLocal.getDate()).padStart(2, '0')}`;

  const typeChanged = assignmentType !== task.assignment_type;

  // Subject whose live workload to show in the right column: while editing, the
  // individual assignee / group being picked; otherwise the task's current one.
  const workloadUser: User | null = editing
    ? assignmentType === 'individual' && !typeChanged
      ? (individualAssigneeOptions.find((u) => String(u.id) === String(assigneeId)) ?? null)
      : null
    : (task.assignee ?? null);
  const workloadGroup = editing
    ? assignmentType === 'group'
      ? (editGroups.find((g) => g.id === groupId) ?? null)
      : null
    : isGroupTask
      ? (task.group ?? null)
      : null;

  const cancelEdit = () => {
    setTitle(task.title);
    setDescription(task.description ?? '');
    setEffort(task.effort_score ?? 0);
    setDeadline(toDateInput(task.deadline));
    setStartDate(toDateInput(task.start_date));
    setAssigneeId(task.assignee_id ? String(task.assignee_id) : '');
    setAssignmentType(task.assignment_type);
    setGroupId(task.group_id ?? '');
    setSaveError('');
    setEditing(false);
  };

  const buildPayload = (): Partial<CreateTaskPayload> => {
    const payload: Partial<CreateTaskPayload> = {};
    if (title.trim() && title.trim() !== task.title) payload.title = title.trim();
    if (description !== (task.description ?? '')) payload.description = description;
    if (effort !== (task.effort_score ?? 0)) payload.effort_score = effort;
    if ((deadline || null) !== (toDateInput(task.deadline) || null)) {
      payload.deadline = deadline || null;
    }
    if ((startDate || null) !== (toDateInput(task.start_date) || null)) {
      payload.start_date = startDate || null;
    }

    if (typeChanged) {
      // Switching individual ↔ group: the server unassigns the task + its
      // subtasks. Only the group needs sending (assignee is cleared server-side).
      payload.assignment_type = assignmentType;
      if (assignmentType === 'group') payload.group_id = groupId;
    } else if (assignmentType === 'individual') {
      // Same type — allow changing (or clearing) the individual assignee.
      const nextAssignee = assigneeId ? Number(assigneeId) : null;
      if (nextAssignee !== task.assignee_id) {
        payload.assignment_type = 'individual';
        payload.assignee_id = nextAssignee;
      }
    } else if (assignmentType === 'group') {
      // Same type — allow reassigning to a different group.
      if (groupId && groupId !== task.group_id) {
        payload.assignment_type = 'group';
        payload.group_id = groupId;
      }
    }
    return payload;
  };

  // Ask for confirmation before committing a task edit.
  const requestSave = () => {
    setSaveError('');
    if (typeChanged && assignmentType === 'group' && !groupId) {
      setSaveError('Select a group for this task.');
      return;
    }
    if (Object.keys(buildPayload()).length === 0) {
      setEditing(false);
      return;
    }
    setConfirmSave(true);
  };

  const performSave = () => {
    update.mutate(
      { id: task.id, payload: buildPayload() },
      {
        onSuccess: () => {
          setConfirmSave(false);
          setEditing(false);
        },
        onError: (err: unknown) => {
          // Surface a specific validation message (e.g. a date rule) when present.
          const msg = (
            err as { response?: { data?: { errors?: Record<string, string[]> } } }
          )?.response?.data?.errors;
          setSaveError(
            msg?.deadline?.[0] ??
              msg?.start_date?.[0] ??
              'Could not save the changes. Please try again.',
          );
          setConfirmSave(false);
        },
      },
    );
  };

  // Inline "Add Subtask" form — hidden until the user clicks "Add Subtask".
  const firstStatusId = statuses[0] ? String(statuses[0].id) : '';
  const [showSubtaskForm, setShowSubtaskForm] = useState(false);
  const [stName, setStName] = useState('');
  const [stAssignee, setStAssignee] = useState('');
  // Empty until the user picks one; the Select shows `firstStatusId` as the
  // fallback and submit uses `stStatus || firstStatusId`.
  const [stStatus, setStStatus] = useState('');
  const [stPriority, setStPriority] = useState<Priority>('Medium');
  const [stEffort, setStEffort] = useState('');
  const [stDeadline, setStDeadline] = useState('');
  const [stError, setStError] = useState('');

  const resetSubtaskForm = () => {
    setStName('');
    setStAssignee('');
    setStPriority('Medium');
    setStEffort('');
    setStDeadline('');
    setStError('');
  };

  const submitLink = async () => {
    const url = linkUrl.trim();
    if (!url) return;
    await addLink.mutateAsync(url);
    setLinkUrl('');
    setLinkOpen(false);
  };

  const submitComment = async () => {
    const text = comment.trim();
    if (!text) return;
    await addComment.mutateAsync(text);
    setComment('');
  };

  const submitSubtask = async () => {
    // The Status select falls back to the first status when untouched, so use
    // the same effective value here (stStatus stays '' until the user picks one).
    const statusId = stStatus || firstStatusId;
    if (!stName.trim() || !statusId || stEffort === '') {
      setStError('Name, status, and effort score are required.');
      return;
    }
    setStError('');
    await createSubtask.mutateAsync({
      name: stName.trim(),
      // Empty assignee → unassigned subtask.
      assignee_id: stAssignee ? Number(stAssignee) : null,
      status_id: statusId,
      priority: stPriority,
      effort_score: Number(stEffort),
      deadline: stDeadline || null,
    });
    resetSubtaskForm();
    setShowSubtaskForm(false);
  };

  return (
    <div className="grid grid-cols-1 gap-6 p-4 sm:p-6 md:grid-cols-[1fr_320px] md:gap-8 max-h-[70vh] ">
      {/* LEFT */}
      <div className="min-w-0 space-y-5 pb-7">
        <ApprovalBanner
          status={task.approval_status}
          reason={task.rejection_reason}
        />

        {/* A reviewer decides right here, so they can edit the proposal above
            and approve it without going back to the queue. */}
        {(canReview || canResubmit) && (
          <div className="flex flex-wrap items-center gap-2">
            {canReview && (
              <>
                <Button
                  className={TEAL}
                  disabled={approveTask.isPending}
                  onClick={() => approveTask.mutate(task.id)}
                >
                  Approve
                </Button>
                {canRejectTask && (
                  <Button
                    variant="outline"
                    className="text-danger"
                    disabled={rejectTask.isPending}
                    onClick={() => setRejectingTask(true)}
                  >
                    Reject
                  </Button>
                )}
              </>
            )}
            {canResubmit && (
              <Button
                className={TEAL}
                disabled={resubmitTask.isPending}
                onClick={() => resubmitTask.mutate(task.id)}
              >
                Re-submit for approval
              </Button>
            )}
          </div>
        )}

        <RejectReasonDialog
          open={rejectingTask}
          subject={task.title}
          isPending={rejectTask.isPending}
          error={rejectTask.isError ? 'Could not reject. Please try again.' : null}
          onCancel={() => setRejectingTask(false)}
          onConfirm={(reason) => {
            rejectTask.mutate({ id: task.id, reason });
            setRejectingTask(false);
          }}
        />

        <div className="flex items-start justify-between gap-3">
          {editing ? (
            <Input
              className="text-lg font-bold"
              value={title}
              onChange={(e) => setTitle(e.target.value)}
              placeholder="Task name"
            />
          ) : (
            // 24px was set for the 900px modal; on a ~344px phone card it
            // dominates the panel, so the ladder starts a step lower.
            <h1 className="min-w-0 text-xl font-bold text-ink wrap-break-word sm:text-2xl md:text-3xl">
              {task.title}
            </h1>
          )}
          {canFullEdit &&
            (editing ? (
              <div className="flex shrink-0 items-center gap-2">
                <Button
                  type="button"
                  size="sm"
                  className={TEAL}
                  onClick={requestSave}
                  disabled={update.isPending}
                >
                  {update.isPending ? 'Saving…' : 'Save changes'}
                </Button>
                <Button type="button" size="sm" variant="ghost" onClick={cancelEdit}>
                  Cancel
                </Button>
              </div>
            ) : (
              <div className="flex shrink-0 items-center gap-2">
                <button
                  type="button"
                  onClick={() => setEditing(true)}
                  aria-label="Edit task"
                  className="flex h-9 w-9 items-center justify-center rounded-lg border border-line text-ink-soft outline-none transition-colors hover:border-brand hover:text-ink focus-visible:ring-[3px] focus-visible:ring-ring/50"
                >
                  <PencilIcon className="h-4 w-4" />
                </button>
                <button
                  type="button"
                  onClick={() => setConfirmDeleteTask(true)}
                  aria-label="Delete task"
                  className="flex h-9 w-9 items-center justify-center rounded-lg border border-line text-ink-soft outline-none transition-colors hover:border-danger hover:text-danger focus-visible:ring-[3px] focus-visible:ring-ring/50"
                >
                  <TrashIcon className="h-4 w-4" />
                </button>
              </div>
            ))}
        </div>

        {editing ? (
          <Field label="Description">
            <Textarea
              rows={4}
              value={description}
              onChange={(e) => setDescription(e.target.value)}
              placeholder="Task Description…"
            />
          </Field>
        ) : (
          task.description && (
            // `wrap-break-word` so an unbroken run (a pasted URL, say) breaks
            // instead of spilling across the right-hand column.
            <p className="text-sm leading-relaxed text-ink-soft wrap-break-word">
              {task.description}
            </p>
          )
        )}

        <DetailsPanel>
          <div className="grid grid-cols-2 gap-4">
            <div>
              <p className={`mb-2 ${sectionLabel}`}>Status:</p>
              {canEditStatus ? (
                <Select
                  className={`${PILL_SELECT} ${statusPill}`}
                  defaultValue={task.status_id ?? ''}
                  onChange={(e) => update.mutate({ id: task.id, payload: { status_id: e.target.value } })}
                >
                  {task.status_id == null && (
                    <option value="" disabled>
                      No status
                    </option>
                  )}
                  {statuses.map((s) => (
                    <option key={s.id} value={String(s.id)}>
                      {s.name}
                    </option>
                  ))}
                </Select>
              ) : (
                <span
                  className={`inline-flex rounded-lg px-2.5 py-2 text-xs font-bold uppercase tracking-wide ${statusPill}`}
                >
                  {task.status?.name ?? 'No status'}
                </span>
              )}
            </div>
            <div>
              <p className={`mb-2 ${sectionLabel}`}>Priority:</p>
              {canFullEdit ? (
                <Select
                  className={`${PILL_SELECT} ${priorityPill(task.priority)}`}
                  defaultValue={task.priority}
                  onChange={(e) =>
                    update.mutate({ id: task.id, payload: { priority: e.target.value as Priority } })
                  }
                >
                  {PRIORITIES.map((p) => (
                    <option key={p} value={p}>
                      {p}
                    </option>
                  ))}
                </Select>
              ) : (
                <div className="flex items-center">
                  <PriorityBadge priority={task.priority} />
                </div>
              )}
            </div>
          </div>

          <div className="grid grid-cols-2 gap-4">
            <div>
              <p className={`mb-2 ${sectionLabel}`}>Start Date:</p>
              {editing ? (
                <Input
                  type="date"
                  value={startDate}
                  min={todayStr}
                  onChange={(e) => setStartDate(e.target.value)}
                />
              ) : (
                <div className={readonlyField}>
                  <CalendarIcon className="h-4 w-4 shrink-0 text-ink-faint" />
                  {formatDate(task.start_date) === '—'
                    ? formatDate(task.created_at)
                    : formatDate(task.start_date)}
                </div>
              )}
            </div>
            <div>
              <p className={`mb-2 ${sectionLabel}`}>Due Date:</p>
              {editing ? (
                // Can't be before the start date (min); the past is enforced server-side.
                <Input
                  type="date"
                  value={deadline}
                  min={startDate && startDate > todayStr ? startDate : todayStr}
                  onChange={(e) => setDeadline(e.target.value)}
                />
              ) : (
                <div
                  className={`${readonlyField}`}
                >
                  <CalendarIcon
                    className={`h-4 w-4 shrink-0 ${
                      isOverdue(task.deadline, task.status?.key) ? 'text-danger' : 'text-ink-faint'
                    }`}
                  />
                 <p className={`${
                    isOverdue(task.deadline, task.status?.key)
                      ? 'border-danger/40 font-semibold text-danger'
                      : ''
                  }`}>{formatDate(task.deadline)}</p>
                </div>
              )}
            </div>
          </div>

          {/* A parent task's own effort is disregarded once it has subtasks — its
              workload is derived from the subtasks, so the score is hidden. */}
          <div>
            <p className={`mb-2 ${sectionLabel}`}>Effort Score:</p>
            {subtasks.length > 0 ? (
              <p className="text-sm text-ink-faint">
                Derived from sub-tasks — this task’s own effort is not counted.
              </p>
            ) : (
              <EffortSlider
                value={editing ? effort : (task.effort_score ?? 0)}
                onChange={setEffort}
                disabled={!editing}
              />
            )}
          </div>

          {update.isError && (
            <p className="text-sm text-danger">Could not save the changes. Please try again.</p>
          )}

          {/* Links */}
          <div>
            <p className={`mb-2 ${sectionLabel}`}>Links</p>
            {linkOpen ? (
              <div className="flex items-center gap-2">
                <Input
                  autoFocus
                  value={linkUrl}
                  onChange={(e) => setLinkUrl(e.target.value)}
                  placeholder="https://…"
                  onKeyDown={(e) => e.key === 'Enter' && submitLink()}
                />
                <Button type="button" size="sm" onClick={submitLink} disabled={addLink.isPending}>
                  {addLink.isPending ? 'Saving…' : 'Save'}
                </Button>
                <Button type="button" size="sm" variant="ghost" onClick={() => setLinkOpen(false)}>
                  Cancel
                </Button>
              </div>
            ) : (
              <button
                type="button"
                onClick={() => setLinkOpen(true)}
                className="flex w-full items-center justify-center gap-2.5 rounded-lg border border-dashed border-ink py-2 text-sm font-semibold text-ink-soft transition-colors hover:bg-ink/5 hover:text-ink"
              >
                <PlusIcon className="h-4 w-4" /> Add Link
              </button>
            )}
            {links.length > 0 && (
              <ul className="mt-3 space-y-2">
                {links.map((l) => (
                  <li key={l.id} className="flex items-center gap-3 text-sm">
                    {l.user && <Avatar name={l.user.name} src={l.user.avatar} size={28} />}
                    <LinkIcon className="h-4 w-4 shrink-0 text-ink-faint" />
                    <a
                      href={l.url}
                      target="_blank"
                      rel="noreferrer"
                      className="truncate text-[#0f766e] underline hover:text-[#0d655e]"
                    >
                      {l.url}
                    </a>
                  </li>
                ))}
              </ul>
            )}
          </div>
        </DetailsPanel>

        {/* Sub-tasks — collapsible panel (open by default); the add form is
            revealed only when the user clicks "Add Subtask". */}
        <CollapsiblePanel title="Sub-tasks">
          {subtasks.length > 0 ? (
            <ul className="space-y-2">
              {subtasks.map((s) => (
                <SubtaskRow
                  key={s.id}
                  subtask={s}
                  statuses={statuses}
                  assigneeOptions={assigneeOptions}
                  canFullEdit={
                    canFullEditSubtask ||
                    (s.approval_status !== 'approved' && s.submitted_by_id === user?.id)
                  }
                  canEditStatus={
                    s.approval_status === 'approved' &&
                    (canFullEditSubtask || s.assignee_id === user?.id)
                  }
                  canReview={isManager && s.approval_status !== 'approved'}
                  canResubmit={
                    s.approval_status === 'rejected' && s.submitted_by_id === user?.id
                  }
                  update={updateSubtask}
                  onDelete={setSubtaskToDelete}
                  parentDeadline={task.deadline}
                />
              ))}
            </ul>
          ) : (
            <p className="text-sm text-ink-faint">No sub-tasks yet.</p>
          )}

          {/* Add-subtask trigger — Admin/PM, or the group's leader on group tasks. */}
          {canAddSubtask && !showSubtaskForm && (
            <Button
              type="button"
              size="sm"
              className={TEAL_OUTLINE}
              onClick={() => setShowSubtaskForm(true)}
            >
              <PlusIcon className="h-4 w-4" /> Add Subtask
            </Button>
          )}

          {/* Inline add-subtask form — shown only after clicking "Add Subtask". */}
          {canAddSubtask && showSubtaskForm && (
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
              assigneeOptions={assigneeOptions}
              isGroupTask={isGroupTask}
              parentDeadline={task.deadline}
            />
            {stError && <p className="text-sm text-danger">{stError}</p>}
            {createSubtask.isError && (
              <p className="text-sm text-danger">Could not add the sub-task. Please try again.</p>
            )}
            <div className="flex justify-end gap-2">
              <Button
                type="button"
                size="sm"
                variant="outline"
                onClick={() => {
                  resetSubtaskForm();
                  setShowSubtaskForm(false);
                }}
                disabled={createSubtask.isPending}
              >
                Cancel
              </Button>
              <Button
                type="button"
                size="sm"
                className={TEAL}
                onClick={submitSubtask}
                disabled={createSubtask.isPending}
              >
                <PlusIcon className="h-4 w-4" /> {createSubtask.isPending ? 'Adding…' : 'Add Subtask'}
              </Button>
            </div>
          </div>
          )}
        </CollapsiblePanel>
      </div>

      {/* RIGHT */}
      <div className="space-y-5">
        {workloadUser ? (
          <WorkloadIndicator user={workloadUser} />
        ) : (
          <GroupWorkloadIndicator group={workloadGroup} />
        )}

        {editing ? (
          /* Edit mode: assignment controls live here on the right, mirroring the
             create-task modal's layout. */
          <div className="space-y-3">
            <p className={sectionLabel}>Assignment</p>
            <SegmentedControl>
              {(['individual', 'group'] as const).map((t) => (
                <SegmentedItem
                  key={t}
                  active={assignmentType === t}
                  onClick={() => setAssignmentType(t)}
                  className="capitalize"
                >
                  {t}
                </SegmentedItem>
              ))}
            </SegmentedControl>

            {assignmentType === 'individual' ? (
              <Field label="Assignee">
                <Select
                  value={typeChanged ? '' : assigneeId}
                  disabled={typeChanged}
                  onChange={(e) => setAssigneeId(e.target.value)}
                >
                  <option value="">Unassigned</option>
                  {individualAssigneeOptions.map((u) => (
                    <option key={u.id} value={String(u.id)}>
                      {u.name}
                    </option>
                  ))}
                </Select>
              </Field>
            ) : (
              <Field label="Group">
                <Select value={groupId} onChange={(e) => setGroupId(e.target.value)}>
                  <option value="">Select a group…</option>
                  {editGroups.map((g) => (
                    <option key={g.id} value={g.id}>
                      {g.name}
                    </option>
                  ))}
                </Select>
              </Field>
            )}

            {typeChanged && (
              <p className="rounded-lg bg-warning-soft px-3 py-2 text-xs font-semibold text-warning">
                Changing the assignment type will unassign this task and all its sub-tasks.
              </p>
            )}
            {saveError && <p className="text-sm text-danger">{saveError}</p>}
          </div>
        ) : (
          <div>
            <p className={sectionLabel}>{isGroupTask ? 'Group Assignee' : 'Assignee'}</p>
            {task.assignee ? (
              <div className="mt-2 flex items-center gap-2">
                <Avatar name={task.assignee.name} src={task.assignee.avatar} size={36} />
                <div>
                  <p className="text-sm font-bold text-ink">{task.assignee.name}</p>
                  <p className="text-xs text-ink-soft">{task.assignee.role ?? task.assignee.email}</p>
                </div>
              </div>
            ) : task.group ? (
              <p className="mt-2 text-sm font-semibold text-ink">Group: {task.group.name}</p>
            ) : (
              <p className="mt-2 text-sm text-ink-faint">Unassigned</p>
            )}
          </div>
        )}

        <div>
          <p className={sectionLabel}>Assigned By</p>
          {task.assigned_by ? (
            <div className="mt-2 flex items-center gap-2">
              <Avatar name={task.assigned_by.name} src={task.assigned_by.avatar} size={28} />
              <p className="text-sm font-semibold text-ink">{task.assigned_by.name}</p>
            </div>
          ) : (
            <p className="mt-1 text-sm text-ink-faint">—</p>
          )}
        </div>

        <div>
          <p className={sectionLabel}>Co-Assignees</p>
          <CoAssigneePanel
            group={isGroupTask ? groupQuery.data : undefined}
            // onToggle={toggleCoAssignee}
          />
        </div>

        <div className="border-t border-line py-4">
          <p className={`mb-3 ${sectionLabel}`}>Comments</p>
          <div className="flex items-center gap-2">
            <Input
              value={comment}
              onChange={(e) => setComment(e.target.value)}
              placeholder="Add a comment…"
              onKeyDown={(e) => e.key === 'Enter' && submitComment()}
            />
            <button
              type="button"
              onClick={submitComment}
              disabled={addComment.isPending}
              aria-label="Send comment"
              className="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-brand text-ink outline-none transition-colors hover:bg-brand-hover focus-visible:ring-[3px] focus-visible:ring-ring/50 disabled:opacity-50"
            >
              <ArrowUpIcon className="h-4 w-4" />
            </button>
          </div>

          {/* Capped, not fixed — a short or empty thread takes only the room it
              needs instead of reserving the full scroll height. */}
          <ul className="mt-4 max-h-64 space-y-4 overflow-y-auto pr-1">
            {comments.map((c) => (
              <li key={c.id} className="flex gap-2">
                {c.author && <Avatar name={c.author.name} src={c.author.avatar} size={28} />}
                <div className="min-w-0">
                  <p className="text-sm">
                    <span className="font-bold text-brand">{c.author?.name ?? 'User'}</span>{' '}
                    <span className="text-xs text-ink-faint">{formatDate(c.created_at)}</span>
                  </p>
                  <p className="text-sm text-ink-soft">{c.content}</p>
                </div>
              </li>
            ))}
            {comments.length === 0 && (
              <li className="text-sm text-ink-faint">No comments yet.</li>
            )}
          </ul>
        </div>

        {/* History / activity log */}
        <div className="border-t border-line pt-4 pb-10">
          <p className={`mb-3 ${sectionLabel}`}>History</p>
          <ul className="max-h-30 space-y-3 overflow-y-auto">
            {activity.map((log) => (
              <li key={log.id} className="flex items-start gap-2 text-sm">
                <span aria-hidden className="mt-1.5 h-1.5 w-1.5 shrink-0 rounded-full bg-brand" />
                <div className="min-w-0">
                  <p className="text-ink-soft">{activityLine(log)}</p>
                  <p className="text-xs text-ink-faint">{formatDateTime(log.created_at)}</p>
                </div>
              </li>
            ))}
            {activity.length === 0 && (
              <li className="text-sm text-ink-faint">No history yet.</li>
            )}
          </ul>
        </div>
      </div>

      <ConfirmDialog
        open={confirmSave}
        onClose={() => setConfirmSave(false)}
        onConfirm={performSave}
        title="Save changes"
        confirmLabel="Save changes"
        destructive={false}
        isPending={update.isPending}
        description={
          typeChanged ? (
            <>
              You changed this task to{' '}
              <span className="font-bold text-ink">{assignmentType}</span>. Saving will unassign the
              task and <span className="font-bold text-ink">all its sub-tasks</span>. Continue?
            </>
          ) : (
            <>Save your changes to this task?</>
          )
        }
      />

      <ConfirmDialog
        open={confirmDeleteTask}
        onClose={() => setConfirmDeleteTask(false)}
        onConfirm={handleDeleteTask}
        title="Delete task"
        confirmLabel="Delete task"
        isPending={deleteTask.isPending}
        description={
          <>
            This will archive the task{' '}
            <span className="font-bold text-ink">{task.title}</span>. You can restore it later from
            the Archives page.
          </>
        }
      />

      <ConfirmDialog
        open={subtaskToDelete !== null}
        onClose={() => setSubtaskToDelete(null)}
        onConfirm={handleDeleteSubtask}
        title="Delete sub-task"
        confirmLabel="Delete sub-task"
        isPending={deleteSubtask.isPending}
        description={
          <>
            This will archive the sub-task{' '}
            <span className="font-bold text-ink">{subtaskToDelete?.name}</span>. You can restore it
            later from the Archives page.
          </>
        }
      />
    </div>
  );
}
