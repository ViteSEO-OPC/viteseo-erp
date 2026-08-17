"use client";

import { useState } from "react";
import {
  Badge,
  Button,
  ConfirmDialog,
  Field,
  Input,
  Modal,
  Select,
  TrashIcon,
} from "@/core/ui";
import {
  useCreateStatus,
  useDeleteStatus,
  useStatuses,
} from "@/modules/projects/hooks/use-statuses";
import type { Project, ProjectStatus } from "@/modules/projects/types/project";
import {
  SkeletonList,
} from "@/modules/projects/components/shared/skeletons";

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

const HEX_RE = /^#[0-9A-Fa-f]{6}$/;

/** Fixed colors used for the 3 protected defaults (mirrors the board columns). */
const DEFAULT_COLORS: Record<string, string> = {
  todo: '#6b7280',
  in_progress: '#6366f1',
  done: '#10b981',
};

function statusColor(s: ProjectStatus): string {
  return s.color ?? DEFAULT_COLORS[s.key] ?? '#6b7280';
}

export interface StatusManagerModalProps {
  open: boolean;
  onClose: () => void;
  projects: Project[];
  defaultProjectId?: string;
}

/**
 * Admin/PM tool to manage a project's statuses: add custom statuses with colors
 * (they auto-populate the Kanban/List views) and delete custom ones. The 3
 * protected defaults (To Do / In Progress / Done) cannot be deleted.
 */
export function StatusManagerModal({
  open,
  onClose,
  projects,
  defaultProjectId,
}: StatusManagerModalProps) {
  const [projectId, setProjectId] = useState(
    defaultProjectId ?? projects[0]?.id ?? '',
  );
  const [name, setName] = useState('');
  const [color, setColor] = useState('#6366f1');
  const [toDelete, setToDelete] = useState<ProjectStatus | null>(null);

  const statusesQuery = useStatuses(projectId);
  const statuses = statusesQuery.data ?? [];
  const create = useCreateStatus(projectId);
  const del = useDeleteStatus(projectId);

  const trimmed = name.trim();
  const nameTaken = statuses.some(
    (s) => s.name.toLowerCase() === trimmed.toLowerCase(),
  );
  const canAdd =
    trimmed.length > 0 && HEX_RE.test(color) && !nameTaken && !create.isPending;

  const submit = async () => {
    if (!canAdd) return;
    try {
      await create.mutateAsync({ name: trimmed, color });
    } catch {
      // Keep the typed name and colour so the status can be resubmitted.
      return;
    }
    setName('');
    setColor('#6366f1');
  };

  const confirmDelete = async () => {
    if (!toDelete) return;
    try {
      await del.mutateAsync(toDelete.id);
    } catch {
      return;
    }
    setToDelete(null);
  };

  return (
    <>
      <Modal
        open={open}
        onClose={onClose}
        title="Manage statuses"
        footer={
          <Button type="button" onClick={onClose}>
            Done
          </Button>
        }
      >
        <div className="space-y-5">
          {projects.length > 1 && (
            <Field label="Project">
              <Select
                value={projectId}
                onChange={(e) => setProjectId(e.target.value)}
              >
                {projects.map((p) => (
                  <option key={p.id} value={p.id}>
                    {p.name}
                  </option>
                ))}
              </Select>
            </Field>
          )}

          {/* Existing statuses */}
          <div>
            <p className="mb-2 text-xs font-bold uppercase tracking-wide text-ink-soft">
              Statuses
            </p>
            {statusesQuery.isLoading ? (
              <SkeletonList rows={3} withAvatar={false} />
            ) : (
              <ul className="space-y-1.5">
                {statuses.map((s) => (
                  <li
                    key={s.id}
                    className="flex items-center gap-3 rounded-lg border border-line px-3 py-2"
                  >
                    <span
                      className="h-3 w-3 shrink-0 rounded-full"
                      style={{ backgroundColor: statusColor(s) }}
                    />
                    <span className="min-w-0 flex-1 truncate text-sm font-semibold text-ink">
                      {s.name}
                    </span>
                    {s.is_protected ? (
                      <Badge>Default</Badge>
                    ) : (
                      <button
                        type="button"
                        aria-label={`Delete ${s.name}`}
                        onClick={() => setToDelete(s)}
                        className="flex h-7 w-7 items-center justify-center rounded-lg border border-line text-ink-soft transition-colors hover:border-danger hover:text-danger"
                      >
                        <TrashIcon className="h-3.5 w-3.5" />
                      </button>
                    )}
                  </li>
                ))}
              </ul>
            )}
          </div>

          {/* Add custom status */}
          <div className="space-y-3 rounded-xl border border-line bg-canvas p-4">
            <p className="text-xs font-bold uppercase tracking-wide text-ink-soft">
              Add custom status
            </p>
            <Field label="Name" error={nameTaken ? 'A status with this name already exists.' : undefined}>
              <Input
                value={name}
                onChange={(e) => setName(e.target.value)}
                placeholder="e.g. In Review"
                onKeyDown={(e) => {
                  if (e.key === 'Enter') {
                    e.preventDefault();
                    submit();
                  }
                }}
              />
            </Field>
            <Field label="Color">
              <div className="space-y-2">
                <div className="flex items-center gap-2">
                  <div
                    className="h-8 w-8 rounded-md border border-line"
                    style={{ backgroundColor: HEX_RE.test(color) ? color : '#ffffff' }}
                  />
                  <Input
                    type="color"
                    value={HEX_RE.test(color) ? color : '#6366f1'}
                    onChange={(e) => setColor(e.target.value)}
                    className="h-8 w-14 cursor-pointer border-0 p-0"
                  />
                  <Input
                    type="text"
                    value={color}
                    onChange={(e) => setColor(e.target.value)}
                    placeholder="#6366f1"
                    className="flex-1"
                  />
                </div>
                <div className="flex flex-wrap gap-1.5">
                  {PRESET_COLORS.map((c) => (
                    <button
                      key={c}
                      type="button"
                      onClick={() => setColor(c)}
                      className={`h-6 w-6 rounded-md border-2 transition-all ${
                        color.toLowerCase() === c
                          ? 'scale-110 border-ink'
                          : 'border-transparent hover:scale-105'
                      }`}
                      style={{ backgroundColor: c }}
                    />
                  ))}
                </div>
              </div>
            </Field>
            {create.isError && (
              <p className="text-sm text-danger">Could not add the status. Please try again.</p>
            )}
            <div className="flex justify-end">
              <Button type="button" onClick={submit} disabled={!canAdd}>
                {create.isPending ? 'Adding…' : 'Add status'}
              </Button>
            </div>
          </div>
        </div>
      </Modal>

      <ConfirmDialog
        open={toDelete !== null}
        onClose={() => setToDelete(null)}
        onConfirm={confirmDelete}
        title="Delete status"
        confirmLabel="Delete status"
        isPending={del.isPending}
        description={
          <>
            Delete the status{' '}
            <span className="font-bold text-ink">{toDelete?.name}</span>? Any tasks or sub-tasks
            still on it will fall back to <span className="font-bold text-ink">no status</span>.
          </>
        }
      />
    </>
  );
}
