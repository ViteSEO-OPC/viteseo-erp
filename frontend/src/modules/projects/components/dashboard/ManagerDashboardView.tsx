"use client";

import { useMemo, useState } from "react";
import { Button, Card, PlusIcon, PriorityBadge, StatCard, Tabs } from "@/core/ui";
import { CountBubble } from "../shared/Pills";
import { isOverdue, OverdueBadge } from "../../overdue";
import { useOpenTask } from "../tasks/useTaskModal";
import { NotesCard } from "../notes/NotesCard";
import type { NoteTarget } from "../../notes/types";
import { WorkspaceCreateTaskModal } from "../workspaces/WorkspaceViews";
import {
  DashboardProjectCard,
  DashboardTaskTable,
  MyTasksTab,
  formatDate,
  useDashboardData,
} from "./DashboardShared";
import Link from "next/dist/client/link";

// Representative activity feed — no activity API yet, so it's templated over
// real recent tasks and each entry opens that task in the global modal.
/** The dashboard card and the task-view panel render this same note. */
const USER_NOTE: NoteTarget = { scope: "user" };

const ACTIVITY_TEMPLATES: Array<{ who: string; verb: string; when: string }> = [
  { who: "Sarah L.", verb: "commented on", when: "12m ago" },
  { who: "System", verb: "moved", when: "1h ago" },
  { who: "Mike A.", verb: "updated", when: "4h ago" },
];

/** PM/Admin dashboard — org-wide Overview + a personal "My Tasks" tab. */
export function ManagerDashboardView(): React.ReactElement {
  const [tab, setTab] = useState<"overview" | "mine">("overview");
  const [createOpen, setCreateOpen] = useState(false);
  const {
    projects,
    tasks,
    users,
    countsFor,
    loading,
    usersLoading,
    activeTasks,
    completedTasks,
  } = useDashboardData();

  // Most urgent non-done tasks (earliest deadline first, so overdue lead).
  const upcoming = useMemo(
    () =>
      tasks
        .filter((t) => t.deadline && t.status?.key !== "done")
        .sort(
          (a, b) =>
            new Date(a.deadline as string).getTime() -
            new Date(b.deadline as string).getTime(),
        )
        .slice(0, 4),
    [tasks],
  );

  // Unassigned, unfinished work that needs an owner (no assignee, no group).
  const unassigned = useMemo(
    () =>
      tasks.filter(
        (t) => !t.assignee_id && !t.group_id && t.status?.key !== "done",
      ),
    [tasks],
  );

  // Project name/color lookups for the unassigned-tasks list-view table.
  const projectName = useMemo(
    () => new Map(projects.map((p) => [p.id, p.name])),
    [projects],
  );
  const projectColor = useMemo(
    () => new Map(projects.map((p) => [p.id, p.color])),
    [projects],
  );

  const openTask = useOpenTask();

  // Templated activity over the most recent real tasks so each entry opens a
  // real task in the global modal (there's no activity API yet).
  const activity = useMemo(
    () =>
      tasks.slice(0, ACTIVITY_TEMPLATES.length).map((t, i) => ({
        ...ACTIVITY_TEMPLATES[i],
        taskId: t.id,
        title: t.title,
      })),
    [tasks],
  );

  return (
    <div className="px-4 py-4 sm:px-6 lg:px-8">
      <h1 className="text-2xl font-bold text-ink sm:text-3xl lg:text-4xl">
        Overview
      </h1>

      {/* Tab bar with "Add Task" pinned to its right, so a PM/Admin can create a
          task from either tab rather than only from the board toolbar. */}
      <div className="mt-6 flex flex-wrap items-end justify-between gap-3">
        <Tabs
          className="min-w-0 flex-1"
          tabs={[
            { key: "overview", label: "Overview" },
            { key: "mine", label: "All Tasks" },
          ]}
          active={tab}
          onChange={(k) => setTab(k as "overview" | "mine")}
        />
        {projects.length > 0 && (
          <Button className="mb-2" onClick={() => setCreateOpen(true)}>
            <PlusIcon className="h-4 w-4" />
            Add Task
          </Button>
        )}
      </div>

      {tab === "mine" ? (
        <MyTasksTab projects={projects} users={users} />
      ) : (
        <>
          {/* Stats */}
          <div className="mt-8 grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4 lg:gap-5">
            <StatCard
              label="Total Projects"
              value={loading ? "—" : projects.length}
              href="/sprintly/projects"
            />
            <StatCard
              label="Active Tasks"
              value={loading ? "—" : activeTasks}
              onClick={() => setTab("mine")}
            />
            <StatCard
              label="Completed"
              value={loading ? "—" : completedTasks}
              onClick={() => setTab("mine")}
            />
            <StatCard
              label="Team Members"
              value={usersLoading ? "—" : users.length}
              href="/sprintly/team"
            />
          </div>

          {/* Everything below the stats splits into main content + a notes
              rail, so the eye-path is status → notes. The rail folds under the
              content below xl (and becomes a sheet launcher on phones). */}
          <div className="mt-2 grid grid-cols-1 items-start gap-6 xl:grid-cols-[minmax(0,1fr)_360px]">
            <div className="min-w-0">
          {/* All projects */}
          <div className="flex items-center justify-between mt-8 text-sm ">
            <h2 className="font-bold uppercase tracking-wide text-ink">
              Projects
            </h2>
            {projects.length > 3 && (
              <Link
                href="/sprintly/projects"
                className="block px-3 py-2 text-sm font-semibold text-ink-faint underline underline-offset-2 hover:text-brand-hover"
              >
                View all Projects
              </Link>
            )}
          </div>

          {loading ? (
            <p className="mt-4 text-ink-soft">Loading projects…</p>
          ) : projects.length === 0 ? (
            <Card className="mt-4 p-8 text-center">
              <p className="text-ink-soft">No projects yet.</p>
            </Card>
          ) : (
            <div className="mt-4 grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3 lg:gap-5">
              {projects.slice(0, 3).map((p) => (
                <DashboardProjectCard
                  key={p.id}
                  project={p}
                  counts={countsFor(p.id)}
                />
              ))}
            </div>
          )}

          {/* Upcoming + Activity */}
          <div className="mt-10 grid grid-cols-1 gap-6 lg:grid-cols-2 lg:gap-8">
            <section>
              <h2 className="text-lg font-bold text-ink">Upcoming Deadlines</h2>
              <div className="mt-4 space-y-4">
                {loading ? (
                  <p className="text-sm text-ink-soft">Loading…</p>
                ) : upcoming.length === 0 ? (
                  <p className="text-sm text-ink-soft">
                    No upcoming deadlines.
                  </p>
                ) : (
                  upcoming.map((t, i) => {
                    const overdue = isOverdue(t.deadline, t.status?.key);
                    return (
                      <button
                        key={t.id}
                        type="button"
                        onClick={() => openTask(t.id)}
                        className="flex w-full items-center justify-between gap-3 rounded-r-lg border-l-2 py-1 pl-3 pr-2 text-left transition-colors hover:bg-black/[0.03]"
                        style={{
                          borderColor: overdue
                            ? "#ef4444"
                            : i < 2
                              ? "#4fdbd7"
                              : "#d1d5db",
                        }}
                      >
                        <div className="min-w-0">
                          <p className="truncate font-bold text-ink">{t.title}</p>
                          <p
                            className={`text-sm ${
                              overdue ? "font-semibold text-danger" : "text-ink-soft"
                            }`}
                          >
                            due {formatDate(t.deadline)}
                          </p>
                        </div>
                        <div className="flex shrink-0 items-center gap-2">
                          <PriorityBadge priority={t.priority} />
                          {overdue && <OverdueBadge />}
                        </div>
                      </button>
                    );
                  })
                )}
              </div>
            </section>

            <section>
              <h2 className="text-lg font-bold text-ink">Activity Feed</h2>
              <div className="mt-4 space-y-1">
                {loading ? (
                  <p className="text-sm text-ink-soft">Loading…</p>
                ) : activity.length === 0 ? (
                  <p className="text-sm text-ink-soft">No recent activity.</p>
                ) : (
                  activity.map((a) => (
                    <button
                      key={a.taskId}
                      type="button"
                      onClick={() => openTask(a.taskId)}
                      className="flex w-full items-start gap-3 rounded-lg px-2 py-2 text-left transition-colors hover:bg-black/[0.03]"
                    >
                      <span className="mt-0.5 h-8 w-8 shrink-0 rounded-full bg-ink" />
                      <div className="min-w-0">
                        <p className="text-sm text-ink">
                          <span className="font-bold">{a.who}</span> {a.verb}{" "}
                          <span className="font-semibold">
                            &lsquo;{a.title}&rsquo;
                          </span>
                        </p>
                        <p className="text-xs text-ink-faint">{a.when}</p>
                      </div>
                    </button>
                  ))
                )}
              </div>
            </section>
          </div>

          {/* Unassigned tasks needing an owner */}
          <section className="mt-10">
            <div className="flex items-center gap-2 w-full bg-danger-soft px-4 py-2 rounded-lg">
              <h2 className="text-lg font-bold text-ink">Unassigned Tasks</h2>
              <CountBubble tone="danger">{unassigned.length}</CountBubble>
            </div>
            {loading ? (
              <Card className="mt-4">
                <p className="px-5 py-4 text-sm text-ink-soft">Loading…</p>
              </Card>
            ) : unassigned.length === 0 ? (
              <Card className="mt-4">
                <p className="px-5 py-8 text-center text-sm text-ink-soft">
                  Every task has an owner. 🎉
                </p>
              </Card>
            ) : (
              <DashboardTaskTable
                items={unassigned}
                projectName={projectName}
                projectColor={projectColor}
                onOpen={openTask}
              />
            )}
          </section>
            </div>

            {/* Notes rail — sticky so it stays reachable while the feed scrolls. */}
            <NotesCard
              target={USER_NOTE}
              uiName="dashboard-card"
              className="mt-8 xl:sticky xl:top-20"
            />
          </div>
        </>
      )}

      {createOpen && (
        <WorkspaceCreateTaskModal
          projects={projects}
          onClose={() => setCreateOpen(false)}
        />
      )}
    </div>
  );
}
