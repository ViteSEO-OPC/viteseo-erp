"use client";

import { useMemo, useState } from "react";
import { Button, Card, PlusIcon, PriorityBadge, StatCard, Tabs } from "@/core/ui";
import { CountBubble } from "@/modules/projects/components/pills";
import { isOverdue, OverdueBadge } from "@/modules/projects/lib/overdue";
import { useOpenTask } from "@/modules/projects/hooks/use-task-modal";
import { ActivityFeed } from "@/modules/projects/components/activity-feed";
import { NotesCard } from "@/modules/projects/components/notes-card";
import type { NoteTarget } from "@/modules/projects/types/note";
import {
  WorkspaceCreateTaskModal,
} from "@/modules/projects/components/workspace-views";
import {
  DashboardProjectCard,
  DashboardTaskTable,
  MyTasksTab,
  formatDate,
  useDashboardData,
} from "@/modules/projects/components/dashboard-shared";
import {
  SkeletonCardGrid,
  SkeletonList,
} from "@/modules/projects/components/skeletons";
import Link from "next/dist/client/link";

/** The dashboard card and the task-view panel render this same note. */
const USER_NOTE: NoteTarget = { scope: "user" };

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
            <SkeletonCardGrid className="mt-4" count={3} />
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
                  <SkeletonList rows={3} />
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
              {/* Platform-wide: task and sub-task events from every project. */}
              <ActivityFeed className="mt-4 -mx-4" limit={6} showProject />
            </section>
          </div>

          {/* Unassigned tasks needing an owner */}
          <section className="mt-10">
            {/* Amber, not red: work without an owner needs attention, but it
                isn't a failure — and red here competed with the genuine OVERDUE
                badges further up the page. */}
            <div className="flex w-full items-center gap-2 rounded-lg bg-warning-soft px-4 py-2">
              <h2 className="text-lg font-bold text-ink">Unassigned Tasks</h2>
              <CountBubble tone="warning">{unassigned.length}</CountBubble>
            </div>
            {loading ? (
              <Card className="mt-4">
                <SkeletonList rows={3} className="px-5 py-4" />
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
              // Below xl the card sits in normal flow, so it keeps the compact
              // default. From xl it is pinned to a full-height rail, and a 13rem
              // pad left ~570px of that rail empty — so it fills the space it is
              // already occupying, scrolling internally once the note outgrows it.
              editorClassName="min-h-[13rem] max-h-[60vh] xl:min-h-[calc(100dvh-13rem)] xl:max-h-[calc(100dvh-13rem)]"
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
