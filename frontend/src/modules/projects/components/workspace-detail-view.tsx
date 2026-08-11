"use client";

import Link from "next/link";
import { useMemo, useState } from "react";
import type { User } from "@/core/types";
import {
  Avatar,
  AvatarStack,
  Button,
  Card,
  ChevronRightIcon,
  Input,
  PencilIcon,
  PlusIcon,
  SearchIcon,
  Tabs,
} from "@/core/ui";
import { useIsManager } from "@/core/auth/AuthProvider";
import {
  useForbiddenRedirect,
} from "@/modules/projects/hooks/use-forbidden-redirect";
import { useProjects } from "@/modules/projects/hooks/use-projects";
import { useWorkspaceStatuses } from "@/modules/projects/hooks/use-statuses";
import { useWorkspaceTasks } from "@/modules/projects/hooks/use-tasks";
import { useUsers } from "@/modules/projects/hooks/use-users";
import { useWorkspace } from "@/modules/projects/hooks/use-workspaces";
import type { StatusKey } from "@/modules/projects/types/common";
import type { Project } from "@/modules/projects/types/project";
import {
  CreateProjectModal,
} from "@/modules/projects/components/create-project-modal";
import {
  EditWorkspaceModal,
} from "@/modules/projects/components/edit-workspace-modal";
import {
  WorkspaceCreateTaskModal,
  WorkspaceViews,
} from "@/modules/projects/components/workspace-views";
import {
  ProjectStatusBadge,
} from "@/modules/projects/components/project-status-badge";
import {
  STATUS_COLORS,
  StatusDonut,
  type StatusCounts,
} from "@/modules/projects/components/status-donut";

export interface WorkspaceDetailViewProps {
  workspaceId: string;
}

function formatDate(value: string): string {
  const d = new Date(value);
  if (Number.isNaN(d.getTime())) return "";
  return d.toLocaleDateString("en-US", { month: "short", day: "2-digit" });
}

// Representative static activity feed — no activity API for workspaces yet.
const RECENT_ACTIVITY = [
  {
    name: "Sarah L.",
    text: "updated the Typography guidelines",
    time: "2h ago",
  },
  {
    name: "Mike A.",
    text: "added 4 new components to Web library",
    time: "5h ago",
  },
  {
    name: "Jimmy Dane",
    text: "removed archived 'Legacy' project",
    time: "Yesterday",
  },
  {
    name: "Emily R.",
    text: "reviewed the accessibility audit logs",
    time: "2 days ago",
  },
];

function ProjectCard({
  project,
  people,
  counts,
}: {
  project: Project;
  people: User[];
  counts: StatusCounts;
}): React.ReactElement {
  const total = counts.todo + counts.in_progress + counts.done;
  const pct = (n: number) => (total === 0 ? 0 : Math.round((n / total) * 100));
  const donePct = pct(counts.done);

  return (
    <Link href={`/sprintly/projects/${project.id}`} className="block">
      <Card className="h-full p-5 transition-shadow hover:shadow-md">
        <div className="flex items-start justify-between">
          <ProjectStatusBadge project={project} counts={counts} />
          <span className="text-xs text-ink-faint">
            {formatDate(project.created_at)}
          </span>
        </div>

        <h3 className="mt-4 line-clamp-2 text-lg font-bold text-ink">
          {project.name}
        </h3>

        <div className="mt-4 flex items-center gap-4">
          <StatusDonut counts={counts} size={96} stroke={13} />
          <div className="space-y-1.5 text-xs">
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

        <div className="mt-4">
          <div className="h-2 w-full overflow-hidden rounded-full bg-black/10">
            <div
              className="h-full rounded-full bg-brand"
              style={{ width: `${donePct}%` }}
            />
          </div>
          <p className="mt-2 text-xs text-ink-soft">
            {donePct}% Complete
            <span className="text-ink-faint"> · {total} tasks</span>
          </p>
        </div>

        {people.length > 0 && (
          <div className="mt-4">
            <AvatarStack
              people={people
                .slice(0, 4)
                .map((p) => ({ name: p.name, src: p.avatar }))}
              size={28}
            />
          </div>
        )}
      </Card>
    </Link>
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
    <div className="flex items-center gap-1.5">
      <span
        className="h-2 w-2 rounded-full"
        style={{ backgroundColor: color }}
      />
      <span className="text-ink-soft">{label}</span>
      <span className="font-bold text-ink">{pct}%</span>
    </div>
  );
}

export function WorkspaceDetailView({
  workspaceId,
}: WorkspaceDetailViewProps): React.ReactElement {
  const [tab, setTab] = useState<"summary" | "tasks">("summary");
  const [createOpen, setCreateOpen] = useState(false);
  const [createTaskOpen, setCreateTaskOpen] = useState(false);
  const [editOpen, setEditOpen] = useState(false);
  const [search, setSearch] = useState("");
  const isManager = useIsManager();

  const workspaceQuery = useWorkspace(workspaceId);
  const projectsQuery = useProjects(workspaceId);

  const workspace = workspaceQuery.data;
  const projects = useMemo(
    () => projectsQuery.data?.data ?? [],
    [projectsQuery.data],
  );
  // Done projects drop out of the summary cards (the Tasks board already hides
  // them via WorkspaceViews).
  const filteredProjects = projects.filter(
    (p) =>
      p.status !== "done" &&
      p.name.toLowerCase().includes(search.toLowerCase()),
  );

  const projectIds = useMemo(() => projects.map((p) => p.id), [projects]);
  const wsStatuses = useWorkspaceStatuses(projectIds);
  const { tasks: wsTasks } = useWorkspaceTasks(projectIds);
  const countsByProject = useMemo(() => {
    const statusKeyById = new Map<string, StatusKey>();
    for (const list of wsStatuses.values()) {
      for (const s of list) statusKeyById.set(s.id, s.key);
    }
    const map = new Map<string, StatusCounts>();
    for (const id of projectIds)
      map.set(id, { todo: 0, in_progress: 0, done: 0 });
    for (const t of wsTasks) {
      const c = map.get(t.project_id);
      if (!c) continue;
      const key = t.status?.key ?? statusKeyById.get(t.status_id ?? "") ?? "todo";
      if (key === "done") c.done += 1;
      else if (key === "in_progress") c.in_progress += 1;
      else c.todo += 1;
    }
    return map;
  }, [wsStatuses, wsTasks, projectIds]);
  const emptyCounts: StatusCounts = { todo: 0, in_progress: 0, done: 0 };
  const usersQuery = useUsers();
  const boardMembers = usersQuery.data ?? [];

  // Safety net: if the API rejects the workspace with 403, go to access-denied.
  const forbidden = useForbiddenRedirect(workspaceQuery.error);
  if (forbidden) {
    return (
      <div className="px-4 py-4 sm:px-6 lg:px-8">
        <p className="text-ink-soft">Redirecting…</p>
      </div>
    );
  }

  if (workspaceQuery.isLoading) {
    return (
      <div className="px-4 py-4 sm:px-6 lg:px-8">
        <p className="text-ink-soft">Loading workspace…</p>
      </div>
    );
  }

  if (workspaceQuery.isError || !workspace) {
    return (
      <div className="px-4 py-4 sm:px-6 lg:px-8">
        <Card className="p-8 text-center">
          <p className="font-semibold text-danger">
            Couldn&apos;t load this workspace.
          </p>
          <p className="mt-1 text-sm text-ink-soft">
            Make sure the API is running and you&apos;re signed in.
          </p>
          <Link
            href="/sprintly/workspaces"
            className="mt-4 inline-block text-sm font-bold text-brand"
          >
            Back to Work Spaces
          </Link>
        </Card>
      </div>
    );
  }

  return (
    <div className="px-4 py-4 sm:px-6 lg:px-8">
      {/* Header */}
      <div className="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
        <div className="min-w-0 flex-1">
          <nav className="flex items-center gap-1 text-sm text-ink-soft">
            <Link href="/sprintly/workspaces" className="hover:text-ink">
              Work Spaces
            </Link>
            <ChevronRightIcon className="h-4 w-4 flex-shrink-0 text-ink-faint" />
            <span className="truncate font-bold text-ink">
              {workspace.name}
            </span>
          </nav>
          <h1 className="mt-2 text-3xl font-bold text-ink sm:text-4xl">
            {workspace.name}
          </h1>
          {workspace.description && (
            <p className="mt-2 max-w-2xl text-sm text-ink-soft">
              {workspace.description}
            </p>
          )}
        </div>
        {isManager && (
          <div className="flex shrink-0 items-center gap-2 self-start sm:self-auto">
            <Button variant="outline" onClick={() => setEditOpen(true)}>
              <PencilIcon className="h-4 w-4" />
              Edit
            </Button>
            {/* The board no longer carries its own "Add Task" — it lives here so
                it's reachable from the Summary tab too. */}
            {projects.length > 0 && (
              <Button
                variant="brand-outline"
                onClick={() => setCreateTaskOpen(true)}
              >
                <PlusIcon className="h-4 w-4" />
                Add Task
              </Button>
            )}
            {(tab === "summary" || tab === "tasks") && (
              <Button onClick={() => setCreateOpen(true)}>
                <PlusIcon className="h-4 w-4" />
                Create Project
              </Button>
            )}
          </div>
        )}
      </div>

      {/* Tabs */}
      <div className="mt-6">
        <Tabs
          tabs={[
            { key: "summary", label: "Summary" },
            { key: "tasks", label: "Tasks" },
          ]}
          active={tab}
          onChange={(key) => setTab(key as "summary" | "tasks")}
        />
      </div>

      {tab === "tasks" ? (
        <WorkspaceViews projects={projects} members={boardMembers} />
      ) : (
        <>
          {/* All Projects */}
          <section className="mt-8">
            <h2 className="text-xl font-bold text-ink">All Projects</h2>

            {projects.length > 0 && (
              <div className="mt-4 flex flex-wrap items-center gap-3">
                <div className="relative w-full max-w-sm">
                  <SearchIcon className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-ink-faint" />
                  <Input
                    value={search}
                    onChange={(e) => setSearch(e.target.value)}
                    placeholder="Search projects…"
                    className="pl-9"
                  />
                </div>
              </div>
            )}

            {projectsQuery.isLoading && (
              <p className="mt-4 text-ink-soft">Loading projects…</p>
            )}

            {projectsQuery.isError && (
              <Card className="mt-4 p-6 text-center">
                <p className="font-semibold text-danger">
                  Couldn&apos;t load projects.
                </p>
              </Card>
            )}

            {!projectsQuery.isLoading &&
              !projectsQuery.isError &&
              projects.length === 0 && (
                <Card className="mt-4 p-10 text-center">
                  <p className="text-lg font-bold text-ink">No projects yet</p>
                  <p className="mt-1 text-ink-soft">
                    {isManager
                      ? "Create your first project in this workspace."
                      : "No projects in this workspace yet."}
                  </p>
                  {isManager && (
                    <Button
                      className="mt-4"
                      onClick={() => setCreateOpen(true)}
                    >
                      <PlusIcon className="h-4 w-4" />
                      Create Project
                    </Button>
                  )}
                </Card>
              )}

            {projects.length > 0 && filteredProjects.length === 0 && (
              <p className="mt-4 text-ink-soft">
                No projects match &quot;{search}&quot;.
              </p>
            )}

            {filteredProjects.length > 0 && (
              <div className="mt-4 grid grid-cols-1 gap-5 sm:grid-cols-2 lg:grid-cols-3">
                {filteredProjects.map((project) => (
                  <ProjectCard
                    key={project.id}
                    project={project}
                    people={project.members ?? []}
                    counts={countsByProject.get(project.id) ?? emptyCounts}
                  />
                ))}
              </div>
            )}
          </section>

          {/* Recent Activity (representative static feed) */}
          <section className="mt-10">
            <h2 className="text-xl font-bold text-ink">Recent Activity</h2>
            <Card className="mt-4 divide-y divide-line">
              {RECENT_ACTIVITY.map((a, i) => (
                <div key={i} className="flex items-start gap-3 px-4 py-3">
                  <Avatar name={a.name} size={32} />
                  <div className="min-w-0 flex-1">
                    <p className="text-sm text-ink">
                      <span className="font-bold">{a.name}</span>{" "}
                      <span className="text-ink-soft">{a.text}</span>
                    </p>
                    <p className="mt-0.5 text-xs text-ink-faint">{a.time}</p>
                  </div>
                </div>
              ))}
            </Card>
          </section>
        </>
      )}

      <CreateProjectModal
        open={createOpen}
        onClose={() => setCreateOpen(false)}
        workspaceId={workspaceId}
      />

      {createTaskOpen && (
        <WorkspaceCreateTaskModal
          projects={projects}
          onClose={() => setCreateTaskOpen(false)}
        />
      )}

      {editOpen && (
        <EditWorkspaceModal
          workspace={workspace}
          onClose={() => setEditOpen(false)}
        />
      )}
    </div>
  );
}
