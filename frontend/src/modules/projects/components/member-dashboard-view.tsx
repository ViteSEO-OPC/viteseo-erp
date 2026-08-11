"use client";

import { useMemo, useState } from "react";
import { useAuth } from "@/core/auth/AuthProvider";
import { Card, StatCard, Tabs } from "@/core/ui";
import { useOpenTask } from "@/modules/projects/hooks/use-task-modal";
import { NotesCard } from "@/modules/projects/components/notes-card";
import type { NoteTarget } from "@/modules/projects/types/note";
import {
  DashboardProjectCard,
  DashboardTaskTable,
  MyTasksTab,
  useDashboardData,
} from "@/modules/projects/components/dashboard-shared";
import Link from "next/dist/client/link";

/** The dashboard card and the task-view panel render this same note. */
const USER_NOTE: NoteTarget = { scope: "user" };

/** Member dashboard — the projects they're on (Overview) + their "My Tasks" tab. */
export function MemberDashboardView(): React.ReactElement {
  const { user } = useAuth();
  const [tab, setTab] = useState<"overview" | "mine">("overview");
  const {
    projects,
    tasks,
    users,
    countsFor,
    loading,
  } = useDashboardData();
  // The member's own workload, counted card-for-card with what their "My Tasks"
  // board draws — otherwise the number and the screen it links to disagree.
  //
  // `useDashboardData` counts every task across their projects, which answers
  // "how big is the project" rather than "how much is on me".
  const myCounts = useMemo(() => {
    let active = 0;
    let completed = 0;
    const tally = (isDone: boolean): void => {
      if (isDone) completed += 1;
      else active += 1;
    };

    for (const t of tasks) {
      // Only ever populated with subtasks assigned to the caller.
      const mySubtasks = t.my_subtasks ?? [];
      // A group task is this member's when they lead the group that owns it —
      // the leader owns the parent, while regular members work through their
      // own subtasks and never see the parent as a card.
      const leadsGroup = t.leads_my_group ?? false;

      for (const s of mySubtasks) tally(s.status?.key === "done");

      // Holding subtasks replaces the parent for a regular member, but not for
      // the leader: they own the parent as well as whatever subtask is theirs.
      const parentCounts =
        (mySubtasks.length === 0 || leadsGroup) &&
        (t.assignee_id === user?.id || leadsGroup);

      if (parentCounts) tally(t.status?.key === "done");
    }

    return { active, completed };
  }, [tasks, user?.id]);
  // The member's own non-done tasks with a deadline, earliest first. A group
  // task counts as theirs when they lead the group that owns it.
  const upcoming = useMemo(
    () =>
      tasks
        .filter(
          (t) =>
            (t.assignee_id === user?.id || t.leads_my_group) &&
            t.deadline &&
            t.status?.key !== "done",
        )
        .sort(
          (a, b) =>
            new Date(a.deadline as string).getTime() -
            new Date(b.deadline as string).getTime(),
        ),
    [tasks, user?.id],
  );

  // Project name/color lookups for the upcoming-deadlines table.
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
      <h1 className="text-4xl font-bold text-ink">Dashboard</h1>

      <div className="mt-6">
        <Tabs
          tabs={[
            { key: "overview", label: "Overview" },
            { key: "mine", label: "My Tasks" },
          ]}
          active={tab}
          onChange={(k) => setTab(k as "overview" | "mine")}
        />
      </div>

      {tab === "mine" ? (
        user && (
          <MyTasksTab projects={projects} users={users} userId={user.id} />
        )
      ) : (
        <>
          {/* Stats */}   
          <div className="mt-8 grid grid-cols-1 gap-5 sm:grid-cols-3">
            <StatCard
              label="My Projects"
              value={loading ? "—" : projects.length}
              href="/sprintly/projects"
            />
            {/* Scoped to this member — these sit beside "My Projects" and both
                jump to the My Tasks board, so counting the whole project would
                never match what the click leads to. */}
            <StatCard
              label="Active Tasks"
              value={loading ? "—" : myCounts.active}
              onClick={() => setTab("mine")}
            />
            <StatCard
              label="Completed"
              value={loading ? "—" : myCounts.completed}
              onClick={() => setTab("mine")}
            />
          </div>

          {/* Content + notes rail (status → notes eye-path). Folds to one
              column below xl; a sheet launcher on phones. */}
          <div className="mt-2 grid grid-cols-1 items-start gap-6 xl:grid-cols-[minmax(0,1fr)_360px]">
            <div className="min-w-0">
          {/* My projects */}
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
              <p className="text-ink-soft">
                You haven&apos;t been added to any projects yet.
              </p>
            </Card>
          ) : (
            <div className="mt-4 grid grid-cols-1 gap-5 sm:grid-cols-2 lg:grid-cols-3">
              {projects.slice(0, 3).map((p) => (
                <DashboardProjectCard
                  key={p.id}
                  project={p}
                  counts={countsFor(p.id)}
                />
              ))}
            </div>
          )}

          {/* My upcoming deadlines */}
          <section className="mt-10">
            <h2 className="text-lg font-bold text-ink">
              My Upcoming Deadlines
            </h2>
            {loading ? (
              <Card className="mt-4">
                <p className="px-5 py-4 text-sm text-ink-soft">Loading…</p>
              </Card>
            ) : upcoming.length === 0 ? (
              <Card className="mt-4">
                <p className="px-5 py-8 text-center text-sm text-ink-soft">
                  No upcoming deadlines.
                </p>
              </Card>
            ) : (
              <DashboardTaskTable
                items={upcoming}
                projectName={projectName}
                projectColor={projectColor}
                onOpen={openTask}
              />
            )}
          </section>
            </div>

            <NotesCard
              target={USER_NOTE}
              uiName="dashboard-card"
              className="mt-8 xl:sticky xl:top-4"
            />
          </div>
        </>
      )}
    </div>
  );
}
