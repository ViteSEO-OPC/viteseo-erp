"use client";

import Link from "next/link";
import { useMemo, useState } from "react";
import {
  Avatar,
  Badge,
  Button,
  CalendarIcon,
  Card,
  ChevronRightIcon,
  ConfirmDialog,
  PencilIcon,
  PlusIcon,
} from "@/core/ui";
import { useIsManager } from "@/core/auth/AuthProvider";
import type { StatusKey } from "../../types";
import { useProjectChannel } from "../../useProjectChannel";
import {
  useGroups,
  useProject,
  useProjectMembers,
  useStatuses,
  useTasks,
  useUpdateProject,
  useUsers,
  useWorkspace,
} from "../../hooks";
import { EditProjectModal } from "./EditProjectModal";
import { MemberPickerModal } from "../team/MemberPickerModal";
import { buildGroupsByUser, MemberGroupTags } from "../team/TeamShared";
import { STATUS_COLORS, StatusDonut } from "../status/StatusDonut";

export interface ProjectDetailViewProps {
  projectId: string;
}

function formatDate(value?: string | null): string {
  if (!value) return "—";
  const d = new Date(value);
  if (Number.isNaN(d.getTime())) return "—";
  return d.toLocaleDateString("en-US", {
    month: "short",
    day: "numeric",
    year: "numeric",
  });
}

export function ProjectDetailView({ projectId }: ProjectDetailViewProps) {
  // Live updates for this project (tasks, statuses, comments, subtasks).
  useProjectChannel(projectId);

  const projectQuery = useProject(projectId);
  const statusesQuery = useStatuses(projectId);
  const tasksQuery = useTasks(projectId);
  const isManager = useIsManager();

  const project = projectQuery.data;
  const workspaceId = project?.workspace_id ?? "";
  const workspaceQuery = useWorkspace(workspaceId);
  const workspace = workspaceQuery.data;
  const usersQuery = useUsers();
  const projectMembers = useProjectMembers(projectId);

  const [pickerOpen, setPickerOpen] = useState(false);
  const [editOpen, setEditOpen] = useState(false);
  const [confirmDone, setConfirmDone] = useState(false);
  const updateProject = useUpdateProject();
  const isDone = project?.status === "done";

  const toggleDone = async () => {
    if (!project) return;
    await updateProject.mutateAsync({
      id: project.id,
      payload: { status: isDone ? "active" : "done" },
    });
    setConfirmDone(false);
  };

  // Per-member group tags come from ALL groups (across every project), so the
  // picker/member list shows every pod a person belongs to — not just this
  // project's pods (a candidate you're adding usually isn't in one yet).
  const groupsQuery = useGroups();
  const allGroups = useMemo(
    () => groupsQuery.data?.data ?? [],
    [groupsQuery.data],
  );
  const groupsByUser = useMemo(
    () => buildGroupsByUser(allGroups),
    [allGroups],
  );
  // Every group (across projects) with its full roster — the picker surfaces a
  // group in its "Groups" section once every one of its members is selected, so
  // the admin/PM sees which complete groups are now in the project.
  const pickerGroups = useMemo(
    () =>
      allGroups.map((g) => ({
        id: g.id,
        name: g.name,
        color: g.color,
        memberIds: [
          ...new Set(
            [g.leader?.id, ...(g.members ?? []).map((m) => m.id)].filter(
              (id): id is number => id != null,
            ),
          ),
        ],
      })),
    [allGroups],
  );

  // Map status id -> key so we can bucket tasks even when the task payload omits
  // the embedded status object.
  const statusKeyById = useMemo(() => {
    const map = new Map<string, StatusKey>();
    for (const s of statusesQuery.data ?? []) map.set(s.id, s.key);
    return map;
  }, [statusesQuery.data]);

  const counts = useMemo(() => {
    const acc = { todo: 0, in_progress: 0, done: 0 };
    for (const t of tasksQuery.data?.data ?? []) {
      const key = t.status?.key ?? statusKeyById.get(t.status_id ?? "") ?? "todo";
      if (key === "done") acc.done += 1;
      else if (key === "in_progress") acc.in_progress += 1;
      else acc.todo += 1; // todo + custom bucket to "To Do"
    }
    return acc;
  }, [tasksQuery.data, statusKeyById]);

  const total = counts.todo + counts.in_progress + counts.done;
  const pct = (n: number) => (total === 0 ? 0 : Math.round((n / total) * 100));
  const donePct = pct(counts.done);

  const projectStatus = isDone
    ? "Done"
    : total === 0
      ? "Not Started"
      : counts.done === total
        ? "Completed"
        : counts.in_progress > 0 || counts.done > 0
          ? "In Progress"
          : "Not Started";

  const members = useMemo(() => project?.members ?? [], [project]);
  const memberIds = useMemo(() => members.map((m) => m.id), [members]);
  const users = usersQuery.data ?? [];
  const memberBusy =
    projectMembers.add.isPending || projectMembers.remove.isPending;

  const isError =
    projectQuery.isError || statusesQuery.isError || tasksQuery.isError;

  if (isError) {
    return (
      <div className="mx-auto max-w-[1400px] px-4 py-6 sm:px-6 lg:px-8 lg:py-8">
        <Card className="p-8 text-center">
          <p className="font-semibold text-danger">
            Couldn&apos;t load this project.
          </p>
          <p className="mt-1 text-sm text-ink-soft">
            Make sure the API is running and you&apos;re signed in.
          </p>
        </Card>
      </div>
    );
  }

  return (
    <div className="mx-auto max-w-[1400px] px-4 py-6 sm:px-6 lg:px-8 lg:py-8">
      {/* Header */}
      {project && (
        <Link
          href={`/sprintly/workspaces/${project.workspace_id}`}
          className="inline-flex items-center gap-1 text-sm font-semibold text-ink-soft hover:text-ink"
        >
          <span className="rotate-180">
            <ChevronRightIcon className="h-4 w-4" />
          </span>
          Back to workspace
        </Link>
      )}
      <div className="mt-1 flex flex-wrap items-start justify-between gap-3">
        <div>
          <div className="flex items-center gap-3">
            <h1 className="text-2xl font-bold text-ink sm:text-[32px]">
              {project?.name ?? "Project"}
            </h1>
            {isDone && <Badge tone="success">Done</Badge>}
          </div>
          {project?.description && (
            <p className="mt-1 max-w-2xl text-ink-soft">{project.description}</p>
          )}
        </div>
        {isManager && project && (
          <div className="flex shrink-0 items-center gap-2">
            <Button variant="outline" onClick={() => setEditOpen(true)}>
              <PencilIcon className="h-4 w-4" />
              Edit
            </Button>
            <Button
              variant={isDone ? "outline" : "default"}
              onClick={() => setConfirmDone(true)}
            >
              {isDone ? "Reopen project" : "Mark as Done"}
            </Button>
          </div>
        )}
      </div>

      <div className="mt-8 grid grid-cols-1 items-start gap-6 lg:grid-cols-[1fr_400px]">
        {/* LEFT COLUMN */}
        <div className="space-y-6">
          {/* Details card */}
          <Card className="rounded-2xl p-6 sm:p-8">
            <div className="grid grid-cols-1 gap-6 sm:grid-cols-2">
              <div>
                <p className="text-sm font-bold text-ink">Workspace</p>
                <div className="mt-2 rounded-lg border border-line px-4 py-2.5 text-sm text-ink">
                  {workspace?.name ?? "—"}
                </div>
              </div>
              <div>
                <p className="text-sm font-bold text-ink">Project Status</p>
                <div className="mt-2">
                  <Badge
                    tone="info"
                    className="rounded-md px-2.5 py-1 normal-case"
                  >
                    {projectStatus}
                  </Badge>
                </div>
              </div>
            </div>

            <p className="mt-6 text-sm font-bold text-ink">
              Project Timeline (Duration)
            </p>
            <div className="mt-2 grid grid-cols-1 gap-4 sm:grid-cols-2">
              <div>
                <p className="text-xs text-ink-soft">Start Date</p>
                <div className="mt-1 flex items-center gap-2 rounded-lg border border-line px-4 py-2.5 text-sm text-ink">
                  <CalendarIcon className="h-4 w-4 text-ink-soft" />
                  {formatDate(project?.created_at)}
                </div>
              </div>
              <div>
                <p className="text-xs text-ink-soft">Due Date</p>
                <div className="mt-1 flex items-center gap-2 rounded-lg border border-line px-4 py-2.5 text-sm text-ink">
                  <CalendarIcon className="h-4 w-4 text-ink-soft" />
                  {formatDate(project?.updated_at)}
                </div>
              </div>
            </div>
          </Card>

          {/* Overall Progress card */}
          <Card className="rounded-2xl p-6 sm:p-8">
            <h2 className="text-2xl font-bold text-ink">Overall Progress</h2>

            <div className="mt-6 flex flex-col items-center gap-8 sm:flex-row sm:items-center">
              <StatusDonut counts={counts} />
              <div className="space-y-3">
                <LegendRow
                  color={STATUS_COLORS.done}
                  label="Done"
                  pct={pct(counts.done)}
                />
                <LegendRow
                  color={STATUS_COLORS.in_progress}
                  label="In Progress"
                  pct={pct(counts.in_progress)}
                />
                <LegendRow
                  color={STATUS_COLORS.todo}
                  label="To Do"
                  pct={pct(counts.todo)}
                />
              </div>
            </div>

            <div className="mt-8">
              <div className="flex items-center justify-between">
                <p className="text-sm font-bold text-ink">Completed Tasks</p>
                <p className="text-sm font-bold text-ink">{donePct}%</p>
              </div>
              <div className="mt-2 h-3 w-full overflow-hidden rounded-full bg-black/10">
                <div
                  className="h-full rounded-full bg-brand"
                  style={{ width: `${donePct}%` }}
                />
              </div>
              <p className="mt-2 text-xs text-ink-soft">
                {counts.done} of {total} tasks completed
              </p>
            </div>

            <p className="mt-8 text-sm font-bold text-ink">Task Breakdown</p>
            <div className="mt-3 flex gap-10">
              <BreakdownStat value={counts.todo} label="To Do" />
              <BreakdownStat
                value={counts.in_progress}
                label="In Progress"
                color="text-brand"
              />
              <BreakdownStat value={counts.done} label="Done" />
            </div>
          </Card>
        </div>

        {/* RIGHT COLUMN — Team Members */}
        <Card className="rounded-2xl p-6 sm:p-8">
          <h2 className="text-2xl font-bold text-ink">Team Members</h2>
          <div className="mt-6 space-y-1">
            {members.length === 0 ? (
              <p className="py-4 text-sm text-ink-soft">
                No members assigned yet.
              </p>
            ) : (
              members.map((m) => (
                <div
                  key={m.id}
                  className="flex items-center gap-3 border-b border-line py-3 last:border-0"
                >
                  <Avatar name={m.name} src={m.avatar} size={40} />
                  <div className="min-w-0 flex-1">
                    <p className="truncate font-bold text-ink">{m.name}</p>
                    <p className="truncate text-sm text-ink-soft">
                      {m.role ?? m.email}
                    </p>
                    <MemberGroupTags
                      tags={groupsByUser.get(m.id) ?? []}
                      className="mt-1"
                    />
                  </div>
                </div>
              ))
            )}
          </div>
          {isManager && (
            <button
              type="button"
              onClick={() => setPickerOpen(true)}
              className="mt-4 inline-flex items-center gap-1.5 rounded-lg border border-brand bg-white px-4 py-2 text-sm font-bold text-[#0f766e] transition-colors hover:bg-brand/10"
            >
              <PlusIcon className="h-4 w-4" />
              Add Member
            </button>
          )}
        </Card>
      </div>

      {isManager && (
        <MemberPickerModal
          open={pickerOpen}
          onClose={() => setPickerOpen(false)}
          title={`Add members to ${project?.name ?? "project"}`}
          candidates={users}
          selectedIds={memberIds}
          onAdd={(id) => projectMembers.add.mutate(id)}
          onRemove={(id) => projectMembers.remove.mutate(id)}
          busy={memberBusy}
          memberTags={groupsByUser}
          groups={pickerGroups}
        />
      )}

      {editOpen && project && (
        <EditProjectModal project={project} onClose={() => setEditOpen(false)} />
      )}

      <ConfirmDialog
        open={confirmDone}
        onClose={() => setConfirmDone(false)}
        onConfirm={toggleDone}
        title={isDone ? "Reopen project" : "Mark project as done"}
        confirmLabel={isDone ? "Reopen project" : "Mark as Done"}
        destructive={false}
        isPending={updateProject.isPending}
        description={
          isDone ? (
            <>
              Reopen <span className="font-bold text-ink">{project?.name}</span>? Its tasks will
              show again in All Tasks, My Tasks, and the board views.
            </>
          ) : (
            <>
              Mark <span className="font-bold text-ink">{project?.name}</span> as done? Its tasks
              will be hidden from All Tasks, My Tasks, and the board views. Task statuses are kept,
              so you can reopen the project anytime.
            </>
          )
        }
      />
    </div>
  );
}

function LegendRow({
  color,
  label,
  pct,
}: {
  color: string;
  label: string;
  pct: number;
}) {
  return (
    <div className="flex items-center gap-2 text-sm">
      <span
        className="h-2.5 w-2.5 rounded-full"
        style={{ backgroundColor: color }}
      />
      <span className="text-ink-soft">{label}</span>
      <span className="font-bold text-ink">{pct}%</span>
    </div>
  );
}

function BreakdownStat({
  value,
  label,
  color = "text-ink",
}: {
  value: number;
  label: string;
  color?: string;
}) {
  return (
    <div>
      <p className={`text-3xl font-bold leading-none ${color}`}>{value}</p>
      <p className="mt-1 text-sm text-ink-soft">{label}</p>
    </div>
  );
}