"use client";

import { useEffect, useState } from "react";
import { Avatar, Badge, Button, ChevronDownIcon, Field, Input, PencilIcon, Select, Textarea, UsersIcon, XIcon } from "@/core/ui";
import type { User } from "@/core/types";
import { NamePill } from "@/modules/projects/components/shared/pills";
import { useUpdateComment } from "@/modules/projects/hooks/use-comments";
import {
  useGroupWorkload,
  useUserWorkload,
} from "@/modules/projects/hooks/use-workload";
import { relativeTime } from "@/modules/projects/lib/notifications";
import type { ActivityLog } from "@/modules/projects/types/activity-log";
import type { Comment } from "@/modules/projects/types/comment";
import type {
  ApprovalStatus,
  Priority,
  StatusKey,
} from "@/modules/projects/types/common";
import type { Group } from "@/modules/projects/types/group";
import type { ProjectStatus } from "@/modules/projects/types/project";
import type { WorkloadLevel } from "@/modules/projects/types/workload";
import { cn } from "@/core/lib/utils";
import { Dialog, DialogContent, DialogTitle } from "@/core/ui/dialog";
import { Skeleton } from "@/modules/projects/components/shared/skeletons";
import { WORKLOAD_META } from "@/modules/projects/components/shared/workload-badges";

export const PRIORITIES: Priority[] = ['Low', 'Medium', 'High'];

export const sectionLabel = 'text-xs font-bold uppercase tracking-wide text-ink-soft';

/*
 * TEAL / TEAL_OUTLINE used to live here — a dark-teal filled button and its
 * outlined pair, applied to the task modal's buttons via `className`. They gave
 * this one surface a primary that no other screen in the module used: the modal
 * saved in #0f766e while every page-level CTA saved in brand cyan.
 *
 * Both are now variants of the shared Button — `<Button>` and
 * `<Button variant="brand-outline">` — so the modal is on the same ladder as
 * the rest of PMS. Nothing here should re-introduce a button colour by
 * className; if a new emphasis is genuinely needed, it belongs in
 * `core/ui/button.tsx` as a variant.
 */

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
 * 801:24655). Steps down below `sm`: the details grid stays two columns on a
 * phone, and at 14px a full date ("Jul 28, 2026") wrapped onto a second line
 * inside the box. That step used to be an off-ramp `text-[13px]`; `text-xs` is
 * the scale's own step below `text-sm` and still fits the date.
 */
export const readonlyField =
  'flex h-10 items-center gap-2 rounded-lg border border-line bg-surface px-2.5 text-xs text-ink sm:gap-2.5 sm:px-3 sm:text-sm';

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

/**
 * Standalone 900px modal shell with the project-name pill header.
 *
 * Built on the shared Radix Dialog, and it has to be. This was a hand-rolled
 * `fixed inset-0` div, which made it the one modal in the module outside
 * Radix's layer stack — and the sub-dialogs it contains (ConfirmDialog, on
 * save / delete task / delete sub-task) ARE Radix. A Radix modal layer sets
 * `document.body { pointer-events: none }` and exempts only its own layer
 * nodes, so an open ConfirmDialog killed every click target in this shell,
 * including its own close button. When the layer's teardown then raced the
 * parent unmount (`handleDeleteTask` closed both in one tick), the body could
 * be left unclickable with the modal still on screen — nothing to click, no way
 * out but Escape. As a real layer, the sub-dialog stacks above this one and
 * hands pointer-events back on close.
 *
 * Escape is Radix's now too, which fixes a smaller bug on the way: the old
 * window-level listener closed BOTH this shell and the confirm dialog on top of
 * it. Radix dismisses the highest layer only.
 *
 * `loading` swaps the header pill for a placeholder and marks the dialog busy,
 * so the shell can mount before its contents are known — see the global task
 * modal, which opens straight into this with a skeleton inside.
 */
export function ModalShell({
  open,
  onClose,
  projectName,
  loading = false,
  children,
}: {
  open: boolean;
  onClose: () => void;
  projectName: string;
  loading?: boolean;
  children: React.ReactNode;
}) {
  // Belt and braces for the failure above. Radix restores the body's
  // pointer-events from a module-scoped variable shared by every layer, and a
  // teardown that loses its race leaves the whole page unclickable — a state no
  // amount of clicking can recover from. On the way out, if this was the last
  // dialog on screen, make sure the page can be clicked again.
  useEffect(
    () => () => {
      if (typeof document === 'undefined') return;
      const anotherLayerRemains = document.querySelector(
        '[data-slot="dialog-content"]',
      );
      if (!anotherLayerRemains && document.body.style.pointerEvents === 'none') {
        document.body.style.pointerEvents = '';
      }
    },
    [],
  );

  return (
    <Dialog open={open} onOpenChange={(next) => !next && onClose()}>
      <DialogContent
        // The header carries its own close button.
        showCloseButton={false}
        aria-busy={loading || undefined}
        // No description in this layout; without this Radix logs a warning.
        aria-describedby={undefined}
        className={cn(
          "flex max-h-[90vh] w-full flex-col gap-0 overflow-hidden rounded-2xl border-0 bg-surface p-0 shadow-elevated sm:max-w-[900px]",
          // Matches the module's own overlays rather than the shared Dialog's
          // 200ms — see lib/motion.ts.
          "duration-100",
          // While loading, hold the panel AT its cap instead of letting a
          // short skeleton size it. ViewTaskBody's content runs past its own
          // `max-h-[70vh]` for any task with a details panel and sub-tasks, so
          // the loaded panel lands on this same 90vh — pinning it here is what
          // makes the two states the same height, not just the same width.
          loading && "h-[90vh]",
        )}
      >
        {/* The accessible name. The visible header is a pill, not a heading,
            and Radix requires a Title on every dialog. */}
        <DialogTitle className="sr-only">
          {loading ? 'Loading task' : projectName}
        </DialogTitle>
        <div className="flex items-center justify-between gap-3 border-b border-line px-4 py-3 sm:px-6 sm:py-4">
          {loading ? (
            // The project name arrives with the task, so there is nothing to
            // put in the pill yet — a placeholder of the pill's own size keeps
            // the header from resizing when the real name lands.
            <Skeleton className="h-[22px] w-40 rounded-full" />
          ) : (
            <NamePill tone="info" title={projectName}>
              {projectName}
            </NamePill>
          )}
          <button
            type="button"
            onClick={onClose}
            className="flex h-8 w-8 shrink-0 items-center justify-center rounded-full text-ink-faint outline-none transition-colors hover:bg-black/5 hover:text-ink focus-visible:ring-[3px] focus-visible:ring-ring/50"
            aria-label="Close"
          >
            <XIcon />
          </button>
        </div>
        <div className={cn("overflow-y-auto", loading && "flex-1")}>
          {children}
        </div>
      </DialogContent>
    </Dialog>
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
      <div className="mt-1 flex justify-between text-xs font-semibold text-warning">
        <span>0</span>
        <span>{value}</span>
        <span>10</span>
      </div>
    </div>
  );
}

/** Effort scores run on a fixed 0–10 scale (see EffortSlider). */
export const EFFORT_MIN = 0;
export const EFFORT_MAX = 10;

/**
 * Sanitise a typed effort score. `min`/`max` on <input type="number"> only
 * constrain the spinner buttons — a user can still type "-4" or "42" — so the
 * raw string is stripped of everything but digits and clamped to 0–10 before it
 * reaches state. Returns '' for an empty field so "not filled in yet" survives.
 */
export function clampEffortInput(raw: string): string {
  const digits = raw.replace(/\D/g, '');
  if (digits === '') return '';
  return String(Math.min(EFFORT_MAX, Math.max(EFFORT_MIN, Number(digits))));
}

/** Number input bound to the 0–10 effort scale, used by the subtask forms. */
export function EffortScoreInput({
  value,
  onChange,
  placeholder,
}: {
  value: number | string;
  onChange: (v: string) => void;
  placeholder?: string;
}) {
  return (
    <Input
      type="number"
      inputMode="numeric"
      min={EFFORT_MIN}
      max={EFFORT_MAX}
      step={1}
      value={value}
      placeholder={placeholder}
      onChange={(e) => onChange(clampEffortInput(e.target.value))}
      // type=number accepts these characters but none are valid on a 0–10
      // integer scale; blocking them keeps the field from going blank mid-typing.
      onKeyDown={(e) => {
        if (['e', 'E', '+', '-', '.', ','].includes(e.key)) e.preventDefault();
      }}
    />
  );
}

/** Level → badge tone + human description (per the Member Workload spec). */
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
            <UsersIcon />
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
    <div className="rounded-xl bg-surface-warm-alt p-3">
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
      <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
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
            <span className="mt-1 block text-xs text-ink-faint">
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
          <EffortScoreInput value={effort} onChange={onEffort} placeholder="Required (0–10)" />
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
      <p className="text-xs text-ink-faint">
        The leader automatically is the co-assignee.
      </p>
    </div>
  );
}

/**
 * Review-state chip for a proposal. Renders nothing for approved work, which is
 * the overwhelming majority — an "Approved" pill on every row would be noise.
 */
export function ApprovalBadge({ status }: { status?: ApprovalStatus }) {
  if (!status || status === 'approved') return null;
  return status === 'rejected' ? (
    <Badge tone="danger">Rejected</Badge>
  ) : (
    <Badge tone="warning">Pending</Badge>
  );
}

/**
 * The banner at the top of an unapproved task/sub-task, explaining why it is
 * read-only and (when rejected) what the reviewer asked for.
 */
export function ApprovalBanner({
  status,
  reason,
  noun = 'task',
}: {
  status?: ApprovalStatus;
  reason?: string | null;
  /** What is under review, for the copy ("task" / "sub-task"). */
  noun?: string;
}) {
  if (!status || status === 'approved') return null;

  if (status === 'rejected') {
    return (
      <div className="rounded-xl border border-danger/40 bg-danger-soft px-4 py-3">
        <p className="text-sm font-bold text-danger">Rejected</p>
        {reason && <p className="mt-1 text-sm text-ink">{reason}</p>}
        <p className="mt-1 text-xs text-ink-soft">
          Revise the {noun} and re-submit it for approval.
        </p>
      </div>
    );
  }

  return (
    <div className="rounded-xl border border-warning/40 bg-warning-soft px-4 py-3">
      <p className="text-sm font-bold text-warning">Pending approval</p>
      <p className="mt-1 text-sm text-ink">
        This {noun} is waiting on a PM. It stays off the board and out of
        workload totals until it is approved.
      </p>
    </div>
  );
}

/**
 * One comment in a thread.
 *
 * An author may revise their own comment, but the thread stays honest about it:
 * an edited comment carries an "Edited …" marker and a View Original toggle
 * that EVERY reader gets, not just the author. Deleting is deliberately not
 * offered — a comment others have replied to shouldn't vanish from under them.
 */
export function CommentRow({
  comment,
  canEdit,
  update,
}: {
  comment: Comment;
  canEdit: boolean;
  update: ReturnType<typeof useUpdateComment>;
}) {
  const [editing, setEditing] = useState(false);
  const [draft, setDraft] = useState(comment.content);
  const [showOriginal, setShowOriginal] = useState(false);

  const wasEdited = comment.edited_at !== null && comment.original_content !== null;
  const body = showOriginal ? (comment.original_content ?? comment.content) : comment.content;

  const cancel = () => {
    setDraft(comment.content);
    setEditing(false);
  };

  const save = () => {
    const text = draft.trim();
    // An unchanged (or emptied) comment isn't an edit — don't stamp it as one.
    if (!text || text === comment.content) {
      cancel();
      return;
    }
    update.mutate(
      { id: comment.id, content: text },
      { onSuccess: () => setEditing(false) },
    );
  };

  return (
    <li className="flex gap-2">
      {comment.author && (
        <Avatar name={comment.author.name} src={comment.author.avatar} size={28} />
      )}
      <div className="min-w-0 flex-1">
        <div className="flex items-start gap-2">
          <p className="min-w-0 flex-1 text-sm">
            <span className="font-bold text-brand">{comment.author?.name ?? 'User'}</span>{' '}
            <span className="text-xs text-ink-faint">{relativeTime(comment.created_at)}</span>
          </p>
          {canEdit && !editing && (
            <button
              type="button"
              onClick={() => {
                setDraft(comment.content);
                setShowOriginal(false);
                setEditing(true);
              }}
              aria-label="Edit comment"
              className="flex h-6 w-6 shrink-0 items-center justify-center rounded-md border border-line text-ink-soft outline-none transition-colors hover:border-brand hover:text-ink focus-visible:ring-[3px] focus-visible:ring-ring/50"
            >
              <PencilIcon />
            </button>
          )}
        </div>

        {editing ? (
          <div className="mt-1 space-y-2">
            <Textarea
              autoFocus
              rows={3}
              value={draft}
              onChange={(e) => setDraft(e.target.value)}
              onKeyDown={(e) => {
                if (e.key === 'Escape') cancel();
                // Enter saves; Shift+Enter keeps the newline.
                if (e.key === 'Enter' && !e.shiftKey) {
                  e.preventDefault();
                  save();
                }
              }}
            />
            <div className="flex justify-end gap-2">
              <Button type="button" size="xs" variant="outline" onClick={cancel}>
                Cancel
              </Button>
              <Button
                type="button"
                size="xs"
                disabled={update.isPending}
                onClick={save}
              >
                {update.isPending ? 'Saving…' : 'Save'}
              </Button>
            </div>
            {update.isError && (
              <p className="text-sm text-danger">Could not save the edit. Please try again.</p>
            )}
          </div>
        ) : (
          <p className="text-sm whitespace-pre-wrap text-ink-soft">{body}</p>
        )}

        {/* Edited marker + the original toggle — visible to every reader. */}
        {wasEdited && !editing && (
          <div className="mt-1 flex items-center justify-between gap-3">
            <span className="text-xs text-ink-faint">
              {showOriginal
                ? `Original ${relativeTime(comment.created_at)}`
                : `Edited ${relativeTime(comment.edited_at!)}`}
            </span>
            <button
              type="button"
              onClick={() => setShowOriginal((v) => !v)}
              className="text-xs font-semibold text-muted-foreground underline underline-offset-2 outline-none hover:text-brand-hover focus-visible:ring-[3px] focus-visible:ring-ring/50"
            >
              {showOriginal ? 'Hide Original' : 'View Original'}
            </button>
          </div>
        )}
      </div>
    </li>
  );
}
