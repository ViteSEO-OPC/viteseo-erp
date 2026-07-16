"use client";

import { useState } from "react";
import { useAuth, useIsManager } from "@/core/auth/AuthProvider";
import { useRouter } from "next/navigation";
import { useEffect } from "react";
import {
  Button,
  Card,
  Badge,
  PriorityBadge,
  PagePlaceholder,
} from "@/core/ui";
import {
  useArchivedWorkspaces,
  useArchivedProjects,
  useArchivedTasks,
  useArchivedSubtasks,
  useRestoreWorkspace,
  useRestoreProject,
  useRestoreTask,
  useRestoreSubtask,
} from "@/modules/projects/hooks";
import type {
  ArchivedWorkspace,
  ArchivedProject,
  ArchivedTask,
  ArchivedSubtask,
} from "@/modules/projects/types";

type TabKey = "workspaces" | "projects" | "tasks" | "subtasks";

const TABS: { key: TabKey; label: string }[] = [
  { key: "workspaces", label: "Workspaces" },
  { key: "projects", label: "Projects" },
  { key: "tasks", label: "Tasks" },
  { key: "subtasks", label: "Subtasks" },
];

function fmtDate(iso: string | null | undefined): string {
  if (!iso) return "—";
  return new Date(iso).toLocaleDateString("en-US", {
    year: "numeric",
    month: "short",
    day: "2-digit",
    hour: "2-digit",
    minute: "2-digit",
  });
}

export default function ArchivesPage() {
  const { isAuthenticated, isLoading } = useAuth();
  const isManager = useIsManager();
  const router = useRouter();
  const [tab, setTab] = useState<TabKey>("workspaces");

  useEffect(() => {
    if (!isLoading && !isAuthenticated) {
      router.push("/login");
    }
  }, [isAuthenticated, isLoading, router]);

  if (isLoading) {
    return <div className="p-8 text-ink-soft">Loading...</div>;
  }

  if (!isAuthenticated) {
    return null;
  }

  if (!isManager) {
    return (
      <PagePlaceholder
        title="Access Denied"
        blurb="Only Admins and PMs can access the archives."
      />
    );
  }

  return (
    <div className="px-6 py-6 max-w-[1400px] mx-autos">
      <div className="mb-6">
        <h1 className="text-2xl font-bold text-ink">Archives</h1>
        <p className="mt-1 text-ink-soft">
          View and restore soft-deleted items.
        </p>
      </div>

      <div className="mb-6 inline-flex rounded-lg bg-surface p-1">
        {TABS.map((t) => (
          <button
            key={t.key}
            type="button"
            onClick={() => setTab(t.key)}
            className={`rounded-md px-4 py-1.5 text-sm font-bold transition-colors ${
              tab === t.key
                ? "bg-brand text-ink"
                : "text-ink-soft hover:text-ink"
            }`}
          >
            {t.label}
          </button>
        ))}
      </div>

      {tab === "workspaces" && <ArchivedWorkspacesList />}
      {tab === "projects" && <ArchivedProjectsList />}
      {tab === "tasks" && <ArchivedTasksList />}
      {tab === "subtasks" && <ArchivedSubtasksList />}
    </div>
  );
}

function ArchivedWorkspacesList() {
  const { data, isLoading, isError } = useArchivedWorkspaces();
  const restore = useRestoreWorkspace();

  if (isLoading) return <p className="text-ink-soft">Loading archives...</p>;
  if (isError) return <p className="text-danger">Failed to load archives.</p>;

  const items = data?.data ?? [];

  if (items.length === 0) {
    return (
      <Card className="p-10 text-center">
        <p className="text-lg font-bold text-ink">No archived workspaces</p>
        <p className="mt-1 text-ink-soft">
          Soft-deleted workspaces will appear here.
        </p>
      </Card>
    );
  }

  return (
    <div className="overflow-hidden rounded-xl border border-line">
      <table className="w-full">
        <thead>
          <tr className="border-b border-line bg-[#f9fafb]">
            <th className="px-5 py-3 text-left text-xs font-bold uppercase tracking-wide text-ink-soft">
              Name
            </th>
            <th className="px-5 py-3 text-left text-xs font-bold uppercase tracking-wide text-ink-soft">
              Description
            </th>
            <th className="px-5 py-3 text-left text-xs font-bold uppercase tracking-wide text-ink-soft">
              Deleted At
            </th>
            <th className="px-5 py-3 text-right text-xs font-bold uppercase tracking-wide text-ink-soft">
              Action
            </th>
          </tr>
        </thead>
        <tbody className="divide-y divide-line">
          {items.map((ws: ArchivedWorkspace) => (
            <tr key={ws.id} className="hover:bg-black/[0.01]">
              <td className="px-5 py-3 text-sm font-medium text-ink">
                {ws.name}
              </td>
              <td className="max-w-xs truncate px-5 py-3 text-sm text-ink-soft">
                {ws.description ?? "—"}
              </td>
              <td className="px-5 py-3 text-sm text-ink-soft">
                {fmtDate(ws.deleted_at)}
              </td>
              <td className="px-5 py-3 text-right">
                <Button
                  size="sm"
                  variant="outline"
                  disabled={restore.isPending}
                  onClick={() => restore.mutate(ws.id)}
                >
                  Restore
                </Button>
              </td>
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  );
}

function ArchivedProjectsList() {
  const { data, isLoading, isError } = useArchivedProjects();
  const restore = useRestoreProject();

  if (isLoading) return <p className="text-ink-soft">Loading archives...</p>;
  if (isError) return <p className="text-danger">Failed to load archives.</p>;

  const items = data?.data ?? [];

  if (items.length === 0) {
    return (
      <Card className="p-10 text-center">
        <p className="text-lg font-bold text-ink">No archived projects</p>
        <p className="mt-1 text-ink-soft">
          Soft-deleted projects will appear here.
        </p>
      </Card>
    );
  }

  return (
    <div className="overflow-hidden rounded-xl border border-line">
      <table className="w-full">
        <thead>
          <tr className="border-b border-line bg-[#f9fafb]">
            <th className="px-5 py-3 text-left text-xs font-bold uppercase tracking-wide text-ink-soft">
              Name
            </th>
            <th className="px-5 py-3 text-left text-xs font-bold uppercase tracking-wide text-ink-soft">
              Workspace
            </th>
            <th className="px-5 py-3 text-left text-xs font-bold uppercase tracking-wide text-ink-soft">
              Priority
            </th>
            <th className="px-5 py-3 text-left text-xs font-bold uppercase tracking-wide text-ink-soft">
              Deleted At
            </th>
            <th className="px-5 py-3 text-right text-xs font-bold uppercase tracking-wide text-ink-soft">
              Action
            </th>
          </tr>
        </thead>
        <tbody className="divide-y divide-line">
          {items.map((p: ArchivedProject) => (
            <tr key={p.id} className="hover:bg-black/[0.01]">
              <td className="px-5 py-3">
                <div className="flex items-center gap-2">
                  {p.color && (
                    <span
                      className="h-2.5 w-2.5 shrink-0 rounded-full"
                      style={{ backgroundColor: p.color }}
                    />
                  )}
                  <span className="text-sm font-medium text-ink">
                    {p.name}
                  </span>
                </div>
              </td>
              <td className="px-5 py-3 text-sm text-ink-soft">
                {p.workspace_name ?? "—"}
              </td>
              <td className="px-5 py-3">
                {p.priority ? (
                  <Badge tone={p.priority === "high" ? "danger" : p.priority === "medium" ? "warning" : "info"}>
                    {p.priority}
                  </Badge>
                ) : (
                  <span className="text-sm text-ink-soft">—</span>
                )}
              </td>
              <td className="px-5 py-3 text-sm text-ink-soft">
                {fmtDate(p.deleted_at)}
              </td>
              <td className="px-5 py-3 text-right">
                <Button
                  size="sm"
                  variant="outline"
                  disabled={restore.isPending}
                  onClick={() => restore.mutate(p.id)}
                >
                  Restore
                </Button>
              </td>
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  );
}

function ArchivedTasksList() {
  const { data, isLoading, isError } = useArchivedTasks();
  const restore = useRestoreTask();

  if (isLoading) return <p className="text-ink-soft">Loading archives...</p>;
  if (isError) return <p className="text-danger">Failed to load archives.</p>;

  const items = data?.data ?? [];

  if (items.length === 0) {
    return (
      <Card className="p-10 text-center">
        <p className="text-lg font-bold text-ink">No archived tasks</p>
        <p className="mt-1 text-ink-soft">
          Soft-deleted tasks will appear here.
        </p>
      </Card>
    );
  }

  return (
    <div className="overflow-hidden rounded-xl border border-line">
      <table className="w-full">
        <thead>
          <tr className="border-b border-line bg-[#f9fafb]">
            <th className="px-5 py-3 text-left text-xs font-bold uppercase tracking-wide text-ink-soft">
              Title
            </th>
            <th className="px-5 py-3 text-left text-xs font-bold uppercase tracking-wide text-ink-soft">
              Project
            </th>
            <th className="px-5 py-3 text-left text-xs font-bold uppercase tracking-wide text-ink-soft">
              Assignee / Group
            </th>
            <th className="px-5 py-3 text-left text-xs font-bold uppercase tracking-wide text-ink-soft">
              Priority
            </th>
            <th className="px-5 py-3 text-left text-xs font-bold uppercase tracking-wide text-ink-soft">
              Status
            </th>
            <th className="px-5 py-3 text-left text-xs font-bold uppercase tracking-wide text-ink-soft">
              Deleted At
            </th>
            <th className="px-5 py-3 text-right text-xs font-bold uppercase tracking-wide text-ink-soft">
              Action
            </th>
          </tr>
        </thead>
        <tbody className="divide-y divide-line">
          {items.map((t: ArchivedTask) => (
            <tr key={t.id} className="hover:bg-black/[0.01]">
              <td className="px-5 py-3 text-sm font-medium text-ink">
                {t.title}
              </td>
              <td className="px-5 py-3 text-sm text-ink-soft">
                {t.project_name ?? "—"}
              </td>
              <td className="px-5 py-3 text-sm text-ink-soft">
                {t.assignee?.name ?? t.group_name ?? "—"}
              </td>
              <td className="px-5 py-3">
                <PriorityBadge priority={t.priority} />
              </td>
              <td className="px-5 py-3">
                {t.status ? (
                  <span className="inline-flex items-center rounded-full bg-black/5 px-2.5 py-0.5 text-xs font-semibold text-ink-soft">
                    {t.status.name}
                  </span>
                ) : (
                  <span className="text-sm text-ink-soft">—</span>
                )}
              </td>
              <td className="px-5 py-3 text-sm text-ink-soft">
                {fmtDate(t.deleted_at)}
              </td>
              <td className="px-5 py-3 text-right">
                <Button
                  size="sm"
                  variant="outline"
                  disabled={restore.isPending}
                  onClick={() => restore.mutate(t.id)}
                >
                  Restore
                </Button>
              </td>
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  );
}

function ArchivedSubtasksList() {
  const { data, isLoading, isError } = useArchivedSubtasks();
  const restore = useRestoreSubtask();

  if (isLoading) return <p className="text-ink-soft">Loading archives...</p>;
  if (isError) return <p className="text-danger">Failed to load archives.</p>;

  const items = data?.data ?? [];

  if (items.length === 0) {
    return (
      <Card className="p-10 text-center">
        <p className="text-lg font-bold text-ink">No archived subtasks</p>
        <p className="mt-1 text-ink-soft">
          Soft-deleted subtasks will appear here.
        </p>
      </Card>
    );
  }

  return (
    <div className="overflow-hidden rounded-xl border border-line">
      <table className="w-full">
        <thead>
          <tr className="border-b border-line bg-[#f9fafb]">
            <th className="px-5 py-3 text-left text-xs font-bold uppercase tracking-wide text-ink-soft">
              Name
            </th>
            <th className="px-5 py-3 text-left text-xs font-bold uppercase tracking-wide text-ink-soft">
              Task
            </th>
            <th className="px-5 py-3 text-left text-xs font-bold uppercase tracking-wide text-ink-soft">
              Project
            </th>
            <th className="px-5 py-3 text-left text-xs font-bold uppercase tracking-wide text-ink-soft">
              Assignee
            </th>
            <th className="px-5 py-3 text-left text-xs font-bold uppercase tracking-wide text-ink-soft">
              Priority
            </th>
            <th className="px-5 py-3 text-left text-xs font-bold uppercase tracking-wide text-ink-soft">
              Status
            </th>
            <th className="px-5 py-3 text-left text-xs font-bold uppercase tracking-wide text-ink-soft">
              Deleted At
            </th>
            <th className="px-5 py-3 text-right text-xs font-bold uppercase tracking-wide text-ink-soft">
              Action
            </th>
          </tr>
        </thead>
        <tbody className="divide-y divide-line">
          {items.map((st: ArchivedSubtask) => (
            <tr key={st.id} className="hover:bg-black/[0.01]">
              <td className="px-5 py-3 text-sm font-medium text-ink">
                {st.name}
              </td>
              <td className="px-5 py-3 text-sm text-ink-soft">
                {st.task_name ?? "—"}
              </td>
              <td className="px-5 py-3 text-sm text-ink-soft">
                {st.project_name ?? "—"}
              </td>
              <td className="px-5 py-3 text-sm text-ink-soft">
                {st.assignee?.name ?? "—"}
              </td>
              <td className="px-5 py-3">
                <PriorityBadge priority={st.priority} />
              </td>
              <td className="px-5 py-3">
                {st.status ? (
                  <span className="inline-flex items-center rounded-full bg-black/5 px-2.5 py-0.5 text-xs font-semibold text-ink-soft">
                    {st.status.name}
                  </span>
                ) : (
                  <span className="text-sm text-ink-soft">—</span>
                )}
              </td>
              <td className="px-5 py-3 text-sm text-ink-soft">
                {fmtDate(st.deleted_at)}
              </td>
              <td className="px-5 py-3 text-right">
                <Button
                  size="sm"
                  variant="outline"
                  disabled={restore.isPending}
                  onClick={() => restore.mutate(st.id)}
                >
                  Restore
                </Button>
              </td>
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  );
}
