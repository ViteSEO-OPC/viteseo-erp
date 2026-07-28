"use client";

import { useMemo, useState } from "react";
import { useAuth } from "@/core/auth/AuthProvider";
import { Card, StatCard, Tabs } from "@/core/ui";
import { useOpenTask } from "../tasks/useTaskModal";
import {
  DashboardProjectCard,
  DashboardTaskTable,
  MyTasksTab,
  useDashboardData,
} from "./DashboardShared";
import Link from "next/dist/client/link";

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
    activeTasks,
    completedTasks,
  } = useDashboardData();

  // The member's own non-done tasks with a deadline, earliest first.
  const upcoming = useMemo(
    () =>
      tasks
        .filter(
          (t) =>
            t.assignee_id === user?.id &&
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
          </div>

          {/* My projects */}
          <div className="flex items-center justify-between mt-10 text-sm ">
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
        </>
      )}
    </div>
  );
}
