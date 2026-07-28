"use client";

import Link from "next/link";
import { useMemo, useState } from "react";
import {
  Avatar,
  Button,
  Card,
  ChevronRightIcon,
  ConfirmDialog,
  PencilIcon,
  PlusIcon,
} from "@/core/ui";
import { useIsManager } from "@/core/auth/AuthProvider";
import type { StatusKey } from "../../types";
import { useProjectChannel } from "../../useProjectChannel";
import { useForbiddenRedirect } from "../../useForbiddenRedirect";
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
import { NotesCard } from "../notes/NotesCard";
import { ProjectStatusBadge } from "../status/ProjectStatusBadge";
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

  const members = useMemo(() => project?.members ?? [], [project]);
  const memberIds = useMemo(() => members.map((m) => m.id), [members]);
  const users = usersQuery.data ?? [];
  const memberBusy =
    projectMembers.add.isPending || projectMembers.remove.isPending;

  // A member requesting a project they aren't assigned to gets a 403 from the
  // API — send them to the access-denied screen instead of the generic error.
  const forbidden = useForbiddenRedirect(projectQuery.error);

  const isError =
    projectQuery.isError || statusesQuery.isError || tasksQuery.isError;

  if (forbidden) {
    return (
      <div className="px-4 py-4 sm:px-6 lg:px-8">
        <p className="text-ink-soft">Redirecting…</p>
      </div>
    );
  }

  // Don't render the detail scaffold with placeholder data while the project is
  // still loading — otherwise an unauthorized member sees a blank project page
  // for a moment before the 403 arrives and redirects them.
  if (projectQuery.isLoading) {
    return (
      <div className="px-4 py-4 sm:px-6 lg:px-8">
        <p className="text-ink-soft">Loading project…</p>
      </div>
    );
  }

  if (isError) {
    return (
      <div className="px-4 py-4 sm:px-6 lg:px-8">
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
    <div className="px-4 py-4 sm:px-6 lg:px-8">
      {/* Header — "Back to workspace" only for managers; members can't open the
          workspace detail view, so the link would dead-end for them. */}
      {isManager && project && (
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
        {/* `min-w-0` + `wrap-break-word` so an unbroken run in the name or the
            description breaks instead of spilling across the header. */}
        <div className="min-w-0 flex-1">
          <div className="flex flex-wrap items-center gap-2">
            <h1 className="min-w-0 text-2xl font-bold text-ink wrap-break-word sm:text-3xl">
              {project?.name ?? "Project"}
            </h1>
            {/* Status belongs beside the name, not buried in a card below. */}
            <ProjectStatusBadge project={project} counts={counts} />
          </div>
          {project?.description && (
            <p className="mt-1.5 max-w-2xl text-sm text-ink-soft wrap-break-word">
              {project.description}
            </p>
          )}
          {/* Facts that were previously four bordered boxes in their own card.
              They're reference data, so they read as one quiet line here. */}
          <dl className="mt-3 flex flex-wrap items-center gap-x-5 gap-y-1 text-xs">
            <MetaItem label="Workspace" value={workspace?.name ?? "—"} />
            <MetaItem label="Created" value={formatDate(project?.created_at)} />
            <MetaItem label="Updated" value={formatDate(project?.updated_at)} />
            {project?.priority && (
              <MetaItem
                label="Priority"
                value={
                  project.priority.charAt(0).toUpperCase() +
                  project.priority.slice(1)
                }
              />
            )}
          </dl>
        </div>
        {isManager && project && (
          // Full-width and split evenly once the header wraps, so the actions
          // read as a deliberate row instead of two buttons stranded under the
          // title. Content-width again from sm up.
          <div className="flex w-full shrink-0 items-center gap-2 sm:w-auto">
            <Button
              variant="outline"
              onClick={() => setEditOpen(true)}
              className="flex-1 sm:flex-none"
            >
              <PencilIcon className="h-4 w-4" />
              Edit
            </Button>
            <Button
              variant={isDone ? "outline" : "default"}
              onClick={() => setConfirmDone(true)}
              className="flex-1 sm:flex-none"
            >
              {isDone ? "Reopen project" : "Mark as Done"}
            </Button>
          </div>
        )}
      </div>

      {/* The right column carries member rows (avatar + name + group tags) and
          the notes editor, both of which want room — it widens again at xl
          where the page has the space to give. */}
      <div className="mt-6 grid grid-cols-1 items-start gap-5 lg:grid-cols-[minmax(0,1fr)_400px] xl:grid-cols-[minmax(0,1fr)_460px]">
        {/* LEFT — counts, progress, then the groups working on this project.
            The tiles live inside this column (not spanning the page) so the
            right-hand column starts level with them instead of half a card
            lower. */}
        <div className="space-y-5">
          {/* At-a-glance counts. These were three oversized numbers buried at
              the bottom of the progress card; up here they answer "how big is
              this project and where does it stand" before any scrolling. */}
          <div className="grid grid-cols-2 gap-3 sm:grid-cols-4">
            <StatTile label="Total tasks" value={total} />
            <StatTile
              label="To Do"
              value={counts.todo}
              dot={STATUS_COLORS.todo}
            />
            <StatTile
              label="In Progress"
              value={counts.in_progress}
              dot={STATUS_COLORS.in_progress}
            />
            <StatTile
              label="Done"
              value={counts.done}
              dot={STATUS_COLORS.done}
            />
          </div>

          {/* Deliberately small: the tiles above already carry the counts, so
              this card only has to show the split and how far along the project
              is. The legend is percentages only — repeating the counts here is
              what made it oversized. */}
          {/* A container query, not a viewport breakpoint: this card is full
              width on small screens but sits in the narrow left column at lg,
              so viewport size doesn't tell us whether the donut and its metrics
              fit side by side. Measuring the card itself does. */}
          <Card className="@container/progress gap-3 p-5">
            <div className="flex items-center justify-between gap-3">
              <h2 className="text-lg font-bold text-ink">Overall Progress</h2>
              <span className="text-sm font-bold text-ink">
                {donePct}% complete
              </span>
            </div>

            {/* The chart is the point of this card, so it gets the size. The
                legend AND the completion bar share the column beside it, which
                fills the donut's height instead of stacking below it — a bigger
                chart without a taller card. */}
            <div className="flex flex-col items-center gap-5 @md/progress:flex-row @md/progress:gap-6">
              <StatusDonut counts={counts} size={168} stroke={20} />
              <div className="w-full min-w-0 space-y-2 @md/progress:flex-1">
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

                <div className="pt-2">
                  <div className="h-2 w-full overflow-hidden rounded-full bg-black/10">
                    <div
                      className="h-full rounded-full bg-brand transition-[width] duration-500"
                      style={{ width: `${donePct}%` }}
                    />
                  </div>
                  <p className="mt-1.5 text-xs text-ink-soft">
                    {counts.done} of {total} tasks completed
                  </p>
                </div>
              </div>
            </div>
          </Card>

          {/* Groups — auto-attached when all of a group's members are on this
              project. Read-only here; managed on the Groups page. */}
          <Card className="gap-3 p-5">
            <h2 className="text-lg font-bold text-ink">Groups</h2>
            {(project?.groups ?? []).length === 0 ? (
              <p className="text-sm text-ink-soft">
                No groups yet. A group joins automatically once every one of its
                members is on this project.
              </p>
            ) : (
              // Two-up on wider screens: these rows are short, and a single
              // column left half the card empty.
              <div className="grid grid-cols-1 gap-2 sm:grid-cols-2">
                {(project?.groups ?? []).map((g) => (
                  <div
                    key={g.id}
                    className="flex items-center gap-2.5 rounded-lg border border-line px-3 py-2"
                  >
                    <span
                      className="inline-flex h-7 w-7 shrink-0 items-center justify-center rounded-full text-xs font-bold text-white"
                      style={{ backgroundColor: g.color }}
                      aria-hidden
                    >
                      {g.name.charAt(0).toUpperCase()}
                    </span>
                    <div className="min-w-0 flex-1">
                      <p className="truncate text-sm font-bold text-ink">
                        {g.name}
                      </p>
                      <p className="truncate text-xs text-ink-soft">
                        {g.members_count ?? g.members?.length ?? 0} members
                        {g.leader ? ` · ${g.leader.name}` : ""}
                      </p>
                    </div>
                  </div>
                ))}
              </div>
            )}
          </Card>
        </div>

        {/* RIGHT — people, then the viewer's private notes */}
        <div className="space-y-5">

          {/* Private to the viewer — collapsible so the space can be reclaimed. */}
          {projectId && (
            <NotesCard
              target={{ scope: "project", projectId }}
              uiName={`project-card:${projectId}`}
              collapsible
            />
          )}
          <Card className="gap-3 p-5">
            <div className="flex items-center justify-between gap-2">
              <h2 className="text-lg font-bold text-ink">Team Members</h2>
              <span className="text-xs font-bold text-ink-faint">
                {members.length}
              </span>
            </div>

            {members.length === 0 ? (
              <p className="text-sm text-ink-soft">No members assigned yet.</p>
            ) : (
              /* Two-up between sm and lg, where this card spans the full page
                 width; back to one column inside the narrow lg rail. */
              <div className="grid grid-cols-1 gap-x-5 sm:grid-cols-2 lg:grid-cols-1">
                {members.map((m) => (
                  <div
                    key={m.id}
                    className="flex items-center gap-2.5 border-b border-line py-2.5 last:border-0"
                  >
                    <Avatar name={m.name} src={m.avatar} size={34} />
                    <div className="min-w-0 flex-1">
                      <p className="truncate text-sm font-bold text-ink">
                        {m.name}
                      </p>
                      <p className="truncate text-xs text-ink-soft">
                        {m.role ?? m.email}
                      </p>
                      <MemberGroupTags
                        tags={groupsByUser.get(m.id) ?? []}
                        className="mt-1"
                      />
                    </div>
                  </div>
                ))}
              </div>
            )}

            {isManager && (
              <button
                type="button"
                onClick={() => setPickerOpen(true)}
                className="inline-flex items-center gap-1.5 self-start rounded-lg border border-brand px-3 py-1.5 text-sm font-bold text-[#0f766e] transition-colors hover:bg-brand/10"
              >
                <PlusIcon className="h-4 w-4" />
                Add Member
              </button>
            )}
          </Card>
        </div>
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

/** One fact in the header's meta line — label above, value below. */
function MetaItem({ label, value }: { label: string; value: string }) {
  return (
    <div className="min-w-0">
      <dt className="text-[10px] font-bold uppercase tracking-wide text-ink-faint">
        {label}
      </dt>
      <dd className="truncate text-xs font-semibold text-ink">{value}</dd>
    </div>
  );
}

/** A compact count tile for the row under the header. */
function StatTile({
  label,
  value,
  dot,
}: {
  label: string;
  value: number;
  dot?: string;
}) {
  return (
    <Card className="gap-1 p-4">
      <div className="flex items-center gap-1.5">
        {dot && (
          <span
            className="h-2 w-2 shrink-0 rounded-full"
            style={{ backgroundColor: dot }}
            aria-hidden
          />
        )}
        <p className="truncate text-xs font-semibold text-ink-soft">{label}</p>
      </div>
      <p className="text-2xl font-bold leading-none text-ink">{value}</p>
    </Card>
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
        className="h-2.5 w-2.5 shrink-0 rounded-full"
        style={{ backgroundColor: color }}
      />
      <span className="min-w-0 flex-1 truncate text-ink-soft">{label}</span>
      <span className="font-bold text-ink">{pct}%</span>
    </div>
  );
}