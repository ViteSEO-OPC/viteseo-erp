'use client';

import { useEffect, useState } from 'react';
import { Avatar, Badge, Field, Input, Select, XIcon } from '@/core/ui';
import type { User } from '@/core/types';
import type { ActivityLog, Group, Priority, ProjectStatus, StatusKey } from '../../types';

export const PRIORITIES: Priority[] = ['Low', 'Medium', 'High'];

export const sectionLabel = 'text-xs font-bold uppercase tracking-wide text-ink-soft';

// --- Figma task-modal redesign tokens ---
export const TEAL = 'bg-[#0f766e] text-white hover:bg-[#0d655e]';
export const TEAL_OUTLINE = 'border border-[#0f766e] bg-white text-[#0f766e] hover:bg-[#0f766e]/5';

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

/** Human-readable one-line summary of an activity-log entry. */
export function activityLine(log: ActivityLog): string {
  const who = log.user?.name ?? 'Someone';
  if (log.field_changed === 'status_id' || log.action === 'status_changed') {
    return `${who} changed status`;
  }
  const field = log.field_changed ? log.field_changed.replace(/_id$/, '').replace(/_/g, ' ') : '';
  const verb = log.action.replace(/_/g, ' ');
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
        <div className="flex items-center justify-between border-b border-line px-4 py-3 sm:px-6 sm:py-4">
          <span className="inline-flex items-center rounded-full bg-[#e8f1fb] px-3.5 py-1.5 text-sm font-semibold text-[#005cb9]">
            {projectName}
          </span>
          <button
            type="button"
            onClick={onClose}
            className="flex h-8 w-8 items-center justify-center rounded-full text-ink-faint outline-none transition-colors hover:bg-black/5 hover:text-ink focus-visible:ring-[3px] focus-visible:ring-ring/50"
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

/** Cyan→orange effort-score slider (0–10). */
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
  const track = `linear-gradient(to right, #4fdbd7 0%, #f59e0b ${pct}%, #f6e7c9 ${pct}%, #f6e7c9 100%)`;
  return (
    <div>
      <style>{`
        .effort-range { -webkit-appearance: none; appearance: none; height: 8px; border-radius: 9999px; }
        .effort-range::-webkit-slider-thumb { -webkit-appearance: none; appearance: none; width: 18px; height: 18px; border-radius: 9999px; background: #ffffff; border: 3px solid #f59e0b; cursor: pointer; box-shadow: 0 1px 2px rgba(0,0,0,0.2); }
        .effort-range::-moz-range-thumb { width: 18px; height: 18px; border-radius: 9999px; background: #ffffff; border: 3px solid #f59e0b; cursor: pointer; }
        .effort-range:disabled::-webkit-slider-thumb { cursor: default; }
      `}</style>
      <input
        type="range"
        min={0}
        max={10}
        step={1}
        value={value}
        disabled={disabled}
        onChange={(e) => onChange?.(Number(e.target.value))}
        style={{ background: track }}
        className="effort-range w-full outline-none"
      />
      <div className="mt-1 flex justify-between text-[11px] font-semibold text-ink-faint">
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

/** PLACEHOLDER — workload signal (no backend yet). */
export function WorkloadWarning({ label }: { label: string }) {
  return (
    <div className="flex items-center gap-2 rounded-xl bg-danger-soft px-4 py-3 text-sm font-semibold text-danger">
      <span
        aria-hidden
        className="flex h-5 w-5 shrink-0 items-center justify-center rounded-full bg-danger text-[11px] text-white"
      >
        !
      </span>
      {label}
    </div>
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
    <div className="overflow-hidden rounded-xl border border-[#e4e4d8]">
      <button
        type="button"
        onClick={() => setOpen((o) => !o)}
        className="flex w-full items-center justify-between bg-[#eeeee2] px-4 py-2.5 text-sm font-bold text-ink-soft"
      >
        {title}
        <span className={`transition-transform ${open ? 'rotate-180' : ''}`}>▾</span>
      </button>
      {open && <div className="space-y-5 bg-[#fbfbef] p-4">{children}</div>}
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
}) {
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
          <Input type="date" value={deadline} onChange={(e) => onDeadline(e.target.value)} />
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
