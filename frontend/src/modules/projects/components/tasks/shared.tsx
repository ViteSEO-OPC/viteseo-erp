'use client';

import { useEffect, useState } from 'react';
import { Avatar, Badge, ChevronDownIcon, Field, Input, Select, UsersIcon, XIcon } from '@/core/ui';
import type { User } from '@/core/types';
import { NamePill } from '../shared/Pills';
import { useGroupWorkload, useUserWorkload } from '../../hooks';
import type { ActivityLog, Group, Priority, ProjectStatus, StatusKey, WorkloadLevel } from '../../types';

export const PRIORITIES: Priority[] = ['Low', 'Medium', 'High'];

export const sectionLabel = 'text-xs font-bold uppercase tracking-wide text-ink-soft';

// --- Figma task-modal redesign tokens ---
export const TEAL = 'bg-[#0f766e] text-white hover:bg-[#0d655e]';
export const TEAL_OUTLINE = 'border border-[#0f766e] bg-white text-[#0f766e] hover:bg-[#0f766e]/5';

/**
 * The details panel renders its status / priority selects as tinted pills
 * rather than bordered inputs (Figma 801:24639). Compose with `statusPill` or
 * `priorityPill` for the tint.
 */
export const PILL_SELECT =
  'h-auto rounded-lg border-0 py-2 pl-2.5 pr-7 text-xs font-bold uppercase tracking-wide shadow-none';

export const statusPill = 'bg-ink-soft/10 text-ink-soft';

/** Priority → pill tint, matching the PriorityBadge tones. */
export function priorityPill(priority: Priority): string {
  if (priority === 'High') return 'bg-danger-soft text-danger';
  if (priority === 'Medium') return 'bg-warning-soft text-warning';
  return 'bg-success-soft text-success';
}

/**
 * Read-only field box in the details panel — white, hairline, rounded (Figma
 * 801:24655). Steps down a point below `sm`: the details grid stays two columns
 * on a phone, and at 14px a full date ("Jul 28, 2026") wrapped onto a second
 * line inside the box.
 */
export const readonlyField =
  'flex h-10 items-center gap-2 rounded-lg border border-line bg-surface px-2.5 text-[13px] text-ink sm:gap-2.5 sm:px-3 sm:text-sm';

export function formatDate(value?: string | null): string {
  if (!value) return '—';
  const d = new Date(value);
  if (Number.isNaN(d.getTime())) return value;
  return d.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
}

/** Normalise a date value to a YYYY-MM-DD string for <input type="date">. */
export function toDateInput(value?: string | null): string {
  if (!value) return '';
  return value.slice(0, 10);
}

/** Compact relative-ish timestamp for activity/comment lines. */
export function formatDateTime(value?: string | null): string {
  if (!value) return '';
  const d = new Date(value);
  if (Number.isNaN(d.getTime())) return value;
  return d.toLocaleDateString('en-US', {
    month: 'short',
    day: 'numeric',
    hour: 'numeric',
    minute: '2-digit',
  });
}

/** Map a status key to a badge tone. */
export function statusTone(key?: StatusKey): 'neutral' | 'info' | 'success' {
  if (key === 'in_progress') return 'info';
  if (key === 'done') return 'success';
  return 'neutral';
}

/** Ring colour for the subtask effort-score badge (by priority). */
export function effortRing(priority: Priority): string {
  if (priority === 'High') return 'border-danger text-danger';
  if (priority === 'Medium') return 'border-warning text-warning';
  return 'border-success text-success';
}

/**
 * Every action the backend writes, mapped to the phrase that follows the actor's
 * name in the History list. Actions are namespaced `entity.verb` (see
 * TaskService / SubtaskService), which the old generic formatter rendered raw —
 * "Marco Cruz task.created".
 */
const ACTIVITY_PHRASES: Record<string, string> = {
  'task.created': 'created this task',
  'task.status_changed': 'changed the status',
  'subtask.created': 'added a sub-task',
  'subtask.status_changed': 'changed a sub-task status',
};

/** Human-readable one-line summary of an activity-log entry. */
export function activityLine(log: ActivityLog): string {
  const who = log.user?.name ?? 'Someone';

  const phrase = ACTIVITY_PHRASES[log.action];
  if (phrase) return `${who} ${phrase}`;

  // Unknown action (an older row, or one this build predates). Never surface the
  // raw key: drop the `entity.` namespace and humanise what's left.
  if (log.field_changed === 'status_id') return `${who} changed the status`;

  const verb = (log.action.split('.').pop() ?? log.action).replace(/_/g, ' ');
  const field = log.field_changed?.replace(/_id$/, '').replace(/_/g, ' ');
  return field ? `${who} ${verb} ${field}` : `${who} ${verb}`;
}

/** Standalone 900px modal shell with the project-name pill header. */
export function ModalShell({
  open,
  onClose,
  projectName,
  children,
}: {
  open: boolean;
  onClose: () => void;
  projectName: string;
  children: React.ReactNode;
}) {
  useEffect(() => {
    if (!open) return;
    const onKey = (e: KeyboardEvent) => e.key === 'Escape' && onClose();
    window.addEventListener('keydown', onKey);
    return () => window.removeEventListener('keydown', onKey);
  }, [open, onClose]);

  if (!open) return null;

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center p-4">
      <div className="absolute inset-0 bg-black/50" onClick={onClose} aria-hidden />
      <div
        role="dialog"
        aria-modal="true"
        aria-label={projectName}
        className="relative z-10 flex max-h-[90vh] w-full max-w-[900px] flex-col overflow-hidden rounded-2xl bg-surface shadow-xl"
      >
        <div className="flex items-center justify-between gap-3 border-b border-line px-4 py-3 sm:px-6 sm:py-4">
          <NamePill tone="info" title={projectName}>
            {projectName}
          </NamePill>
          <button
            type="button"
            onClick={onClose}
            className="flex h-8 w-8 shrink-0 items-center justify-center rounded-full text-ink-faint outline-none transition-colors hover:bg-black/5 hover:text-ink focus-visible:ring-[3px] focus-visible:ring-ring/50"
            aria-label="Close"
          >
            <XIcon className="h-4 w-4" />
          </button>
        </div>
        <div className="overflow-y-auto">{children}</div>
      </div>
    </div>
  );
}

/**
 * Amber effort-score slider (0–10) — a soft amber pill holding a ticked track
 * whose filled portion runs to the current value (Figma 801:24680). The native
 * range input sits on top, transparent, so it stays keyboard-accessible.
 */
export function EffortSlider({
  value,
  onChange,
  disabled,
}: {
  value: number;
  onChange?: (n: number) => void;
  disabled?: boolean;
}) {
  const pct = Math.max(0, Math.min(100, (value / 10) * 100));
  return (
    <div>
      <style>{`
        .effort-range { -webkit-appearance: none; appearance: none; background: transparent; }
        .effort-range::-webkit-slider-thumb { -webkit-appearance: none; appearance: none; width: 16px; height: 16px; border-radius: 9999px; background: #ffffff; border: 3px solid #f0a608; cursor: pointer; box-shadow: 0 1px 2px rgba(0,0,0,0.2); }
        .effort-range::-moz-range-thumb { width: 16px; height: 16px; border-radius: 9999px; background: #ffffff; border: 3px solid #f0a608; cursor: pointer; }
        .effort-range:disabled::-webkit-slider-thumb { cursor: default; }
      `}</style>
      <div className="relative h-[22px] rounded-full bg-warning/10">
        {/* Track, fill and the 0–10 ticks all inset by half a thumb so the
            thumb's travel lines up with the tick it is sitting on. */}
        <div className="pointer-events-none absolute inset-x-2 top-1/2 h-1 -translate-y-1/2 rounded-full bg-warning/20" />
        <div
          className="pointer-events-none absolute left-2 top-1/2 h-1.5 -translate-y-1/2 rounded-full bg-warning"
          style={{ width: `calc((100% - 1rem) * ${pct / 100})` }}
        />
        <div className="pointer-events-none absolute inset-x-2 top-1/2 flex -translate-y-1/2 justify-between">
          {Array.from({ length: 11 }, (_, i) => (
            <span
              key={i}
              className={`h-1.5 w-px ${i <= value ? 'bg-warning' : 'bg-warning/35'}`}
            />
          ))}
        </div>
        <input
          type="range"
          min={0}
          max={10}
          step={1}
          value={value}
          disabled={disabled}
          onChange={(e) => onChange?.(Number(e.target.value))}
          className="effort-range absolute inset-0 h-full w-full outline-none"
        />
      </div>
      <div className="mt-1 flex justify-between text-[11px] font-semibold text-warning">
        <span>0</span>
        <span>{value}</span>
        <span>10</span>
      </div>
    </div>
  );
}

/** PLACEHOLDER — AI scheduling suggestion (no backend yet). */
export function AiBanner() {
  return (
    <div className="rounded-xl border border-[#e4e4d8] bg-[#fbfbef] p-4">
      <div className="flex items-start gap-2.5">
        <span
          aria-hidden
          className="mt-0.5 flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-[#f59e0b] text-sm font-bold text-white"
        >
          !
        </span>
        <div>
          <p className="text-base font-bold text-ink">Lock in between, Mon, July 12 and Wed, July 14</p>
          <p className="mt-1 text-sm text-ink-soft">
            Optimal 3-day window to complete the workload safely ahead of the Friday deadline.
          </p>
        </div>
      </div>
      <p className="mt-2 text-[10px] font-bold uppercase tracking-wide text-ink-faint">
        Suggested • AI scheduling coming soon
      </p>
    </div>
  );
}

/** Level → badge tone + human description (per the Member Workload spec). */
const WORKLOAD_META: Record<
  WorkloadLevel,
  { tone: 'success' | 'warning' | 'danger'; label: string; description: string }
> = {
  light: {
    tone: 'success',
    label: 'Light',
    description: 'Light workload — can take on higher-effort tasks.',
  },
  moderate: {
    tone: 'warning',
    label: 'Moderate',
    description:
      'Balanced workload — can still take on medium- to high-effort tasks.',
  },
  heavy: {
    tone: 'danger',
    label: 'Heavy',
    description:
      'Heavy workload — review current assignments before delegating more.',
  },
};

/** Group-phrased descriptions (same levels, different subject). */
const GROUP_DESCRIPTION: Record<WorkloadLevel, string> = {
  light: 'Light workload — this group can take on more work.',
  moderate: 'Balanced workload — this group can still take on more work.',
  heavy: 'Heavy workload — review this group’s assignments before adding more.',
};

/**
 * Presentational workload card shared by the member and group indicators:
 * an avatar/swatch, the subject name, its aggregated active effort, a
 * Light/Moderate/Heavy badge, and a one-line description. Advisory only.
 */
function WorkloadCard({
  name,
  avatar,
  color,
  isGroup,
  level,
  score,
  isLoading,
  isError,
}: {
  name: string;
  avatar?: string | null;
  color?: string | null;
  isGroup?: boolean;
  level?: WorkloadLevel;
  score?: number;
  isLoading: boolean;
  isError: boolean;
}) {
  const meta = level ? WORKLOAD_META[level] : null;
  return (
    <div className="rounded-xl border border-line bg-surface p-3">
      <div className="flex items-center gap-2.5">
        {isGroup ? (
          <span
            className="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg text-white"
            style={{ backgroundColor: color ?? '#6366f1' }}
          >
            <UsersIcon className="h-4 w-4" />
          </span>
        ) : (
          <Avatar name={name} src={avatar} size={32} />
        )}
        <div className="min-w-0 flex-1">
          <p className="truncate text-sm font-bold text-ink">{name}</p>
          <p className="text-xs text-ink-soft">
            {isGroup ? 'Group workload' : 'Workload'}:{' '}
            <span className="font-bold text-ink">
              {isLoading ? '…' : isError ? '—' : (score ?? '—')}
            </span>
          </p>
        </div>
        {meta && <Badge tone={meta.tone}>{meta.label}</Badge>}
      </div>
      {meta && level && (
        <p className="mt-2 text-xs leading-relaxed text-ink-soft">
          {isGroup ? GROUP_DESCRIPTION[level] : meta.description}
        </p>
      )}
      {isError && (
        <p className="mt-2 text-xs text-ink-faint">Couldn’t load workload.</p>
      )}
    </div>
  );
}

/**
 * Live workload indicator for the selected member, shown in the task modal
 * during assignment. Fetches on demand and classifies Light / Moderate / Heavy.
 * Advisory only. Renders nothing when no member is selected.
 */
export function WorkloadIndicator({ user }: { user?: User | null }) {
  const { data, isLoading, isError } = useUserWorkload(user?.id);
  if (!user) return null;
  return (
    <WorkloadCard
      name={user.name}
      avatar={user.avatar}
      level={data?.level}
      score={data?.effort_score}
      isLoading={isLoading}
      isError={isError}
    />
  );
}

/**
 * Live workload indicator for a group — same aggregation, scoped to the group.
 * Shown when a task is assigned to a group. Renders nothing without a group.
 */
export function GroupWorkloadIndicator({
  group,
}: {
  group?: { id: string; name: string; color?: string | null } | null;
}) {
  const { data, isLoading, isError } = useGroupWorkload(group?.id);
  if (!group) return null;
  return (
    <WorkloadCard
      name={group.name}
      color={group.color}
      isGroup
      level={data?.level}
      score={data?.effort_score}
      isLoading={isLoading}
      isError={isError}
    />
  );
}

/**
 * Compact group workload pill (score coloured by level) for list rows such as
 * the Groups page. Renders nothing until loaded.
 */
export function GroupWorkloadBadge({ groupId }: { groupId: string }) {
  const { data } = useGroupWorkload(groupId);
  if (!data) return null;
  const meta = WORKLOAD_META[data.level];
  return (
    <Badge tone={meta.tone}>
      <span title={`Total active effort: ${data.effort_score} (${meta.label})`}>
        {data.effort_score} effort
      </span>
    </Badge>
  );
}

/**
 * Compact member workload cell (score + level badge) for directory lists such
 * as the Team page. Fetches on demand; advisory only.
 */
export function UserWorkloadBadge({ userId }: { userId: number }) {
  const { data, isLoading, isError } = useUserWorkload(userId);
  if (isLoading) return <span className="text-sm text-ink-faint">…</span>;
  if (isError || !data) return <span className="text-sm text-ink-soft">—</span>;
  const meta = WORKLOAD_META[data.level];
  return (
    <span className="flex items-center gap-2">
      <span className="text-sm font-bold text-ink">{data.effort_score}</span>
      <Badge tone={meta.tone}>{meta.label}</Badge>
    </span>
  );
}

/**
 * Collapsible cream panel (the "Expand More Details" look) with a custom title.
 * `defaultOpen` controls the initial state (open by default).
 */
export function CollapsiblePanel({
  title,
  defaultOpen = true,
  children,
}: {
  title: string;
  defaultOpen?: boolean;
  children: React.ReactNode;
}) {
  const [open, setOpen] = useState(defaultOpen);
  return (
    <div className="rounded-xl bg-[#FAFAF7] p-3">
      <button
        type="button"
        onClick={() => setOpen((o) => !o)}
        className="flex w-full items-center justify-between rounded-md bg-ink-soft/10 px-2 py-1.5 text-xs font-bold text-ink-soft transition-colors hover:bg-ink-soft/15"
      >
        {title}
        <ChevronDownIcon
          className={`h-3.5 w-3.5 transition-transform ${open ? 'rotate-180' : ''}`}
        />
      </button>
      {open && <div className="space-y-6 px-1 pt-4">{children}</div>}
    </div>
  );
}

/** Collapsible "Expand More Details" cream panel wrapping the secondary fields. */
export function DetailsPanel({ children }: { children: React.ReactNode }) {
  return <CollapsiblePanel title="Expand More Details">{children}</CollapsiblePanel>;
}

/** A subtask being composed but not yet persisted (used in the create-task flow). */
export interface SubtaskDraft {
  key: string;
  name: string;
  assignee_id: number | null;
  status_id: string;
  priority: Priority;
  effort_score: number;
  deadline: string | null;
}

/**
 * The shared name/assignee/status/priority/effort/deadline fields used by both
 * the view-mode add-subtask form and the create-task draft-subtask form. Fully
 * controlled so each caller owns the state and submit behaviour.
 */
export function SubtaskFields({
  name,
  assignee,
  status,
  priority,
  effort,
  deadline,
  onName,
  onAssignee,
  onStatus,
  onPriority,
  onEffort,
  onDeadline,
  statuses,
  assigneeOptions,
  isGroupTask,
  parentDeadline,
}: {
  name: string;
  assignee: string;
  status: string;
  priority: Priority;
  effort: string;
  deadline: string;
  onName: (v: string) => void;
  onAssignee: (v: string) => void;
  onStatus: (v: string) => void;
  onPriority: (v: Priority) => void;
  onEffort: (v: string) => void;
  onDeadline: (v: string) => void;
  statuses: ProjectStatus[];
  assigneeOptions: User[];
  isGroupTask?: boolean;
  /** The parent task's due date — a subtask can't be due after it. */
  parentDeadline?: string | null;
}) {
  const now = new Date();
  const todayStr = `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}-${String(now.getDate()).padStart(2, '0')}`;
  return (
    <>
      <Field label="Subtask name">
        <Input
          value={name}
          onChange={(e) => onName(e.target.value)}
          placeholder="e.g. Export the design tokens"
        />
      </Field>
      <div className="grid grid-cols-2 gap-3">
        <Field label="Assignee">
          <Select value={assignee} onChange={(e) => onAssignee(e.target.value)}>
            <option value="">Unassigned</option>
            {assigneeOptions.map((u) => (
              <option key={u.id} value={String(u.id)}>
                {u.name}
              </option>
            ))}
          </Select>
          {isGroupTask && (
            <span className="mt-1 block text-[11px] text-ink-faint">
              Group task — choose from the group&apos;s members.
            </span>
          )}
        </Field>
        <Field label="Status">
          <Select value={status} onChange={(e) => onStatus(e.target.value)}>
            {statuses.map((s) => (
              <option key={s.id} value={String(s.id)}>
                {s.name}
              </option>
            ))}
          </Select>
        </Field>
        <Field label="Priority">
          <Select value={priority} onChange={(e) => onPriority(e.target.value as Priority)}>
            {PRIORITIES.map((p) => (
              <option key={p} value={p}>
                {p}
              </option>
            ))}
          </Select>
        </Field>
        <Field label="Effort Score">
          <Input
            type="number"
            min={0}
            max={10}
            value={effort}
            onChange={(e) => onEffort(e.target.value)}
            placeholder="Required"
          />
        </Field>
        <Field label="Deadline">
          {/* Not in the past, and no later than the parent task's due date. */}
          <Input
            type="date"
            value={deadline}
            min={todayStr}
            max={parentDeadline ? toDateInput(parentDeadline) : undefined}
            onChange={(e) => onDeadline(e.target.value)}
          />
        </Field>
      </div>
    </>
  );
}

/** Leader + members of a group, deduped (leader first). */
export function groupRoster(group?: Group): User[] {
  if (!group) return [];
  const map = new Map<number, User>();
  if (group.leader) map.set(group.leader.id, group.leader);
  for (const m of group.members ?? []) if (!map.has(m.id)) map.set(m.id, m);
  return [...map.values()];
}

/**
 * Co-assignees for a group-assigned task. The group's leader is the automatic
 * co-assignee; additional co-assignees are picked from the group's members.
 * (Co-assignees are derived per the spec, so the selection is not persisted.)
 */
export function CoAssigneePanel({
  group,
  // onToggle,
}: {
  group?: Group;
  // onToggle: (id: number) => void;
}) {
  if (!group) {
    return <p className="mt-1 text-xs text-ink-faint">Assign the task to a group to set co-assignees.</p>;
  }
  const leader = group.leader;
  const others = (group.members ?? []).filter((m) => m.id !== leader?.id);

  return (
    <div className="mt-2 space-y-2">
      {leader && (
        <div className="flex items-center gap-2">
          <Avatar name={leader.name} src={leader.avatar} size={28} />
          <p className="min-w-0 flex-1 truncate text-sm font-bold text-ink">{leader.name}</p>
          <Badge tone="accent">Leader</Badge>
        </div>
      )}
      {others.map((m) => {
        return (
          <div
            key={m.id}
            // type="button"
            // onClick={() => onToggle(m.id)}
            className={`flex w-full items-center gap-2 rounded-lg px-2 py-1.5 text-left transition-colors $`}
          >
            <Avatar name={m.name} src={m.avatar} size={28} />
            <span className="min-w-0 flex-1 truncate text-sm font-semibold text-ink">{m.name}</span>
          </div>
        );
      })}
      {others.length === 0 && <p className="text-xs text-ink-faint">No other members in this group.</p>}
      <p className="text-[11px] text-ink-faint">
        The leader automatically is the co-assignee.
      </p>
    </div>
  );
}
