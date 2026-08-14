"use client";

import { useEffect, useState } from "react";
import { useRouter } from "next/navigation";
import { useAuth, useIsManager } from "@/core/auth/AuthProvider";
import {
  Badge,
  Button,
  Input,
  PagePlaceholder,
  PriorityBadge,
  SearchIcon,
  SegmentedControl,
  SegmentedItem,
} from "@/core/ui";
import type { Paginated } from "@/core/types";
import {
  useArchivedProjects,
  useArchivedSubtasks,
  useArchivedTasks,
  useArchivedWorkspaces,
  useRestoreProject,
  useRestoreSubtask,
  useRestoreTask,
  useRestoreWorkspace,
} from "@/modules/projects/hooks/use-archives";
import type {
  ArchivedProject,
  ArchivedSubtask,
  ArchivedTask,
  ArchivedWorkspace,
} from "@/modules/projects/types/archive";
import {
  AdminTable,
  type AdminColumn,
  type AdminQuery,
} from "@/modules/projects/components/review/admin-table";
import {
  SkeletonPage,
  SkeletonTableRows,
} from "@/modules/projects/components/shared/skeletons";
import { TABLE_FRAME } from "@/modules/projects/components/shared/data-table";

type TabKey = "workspaces" | "projects" | "tasks" | "subtasks";

const TABS: { key: TabKey; label: string }[] = [
  { key: "workspaces", label: "Workspaces" },
  { key: "projects", label: "Projects" },
  { key: "tasks", label: "Tasks" },
  { key: "subtasks", label: "Subtasks" },
];

/** Rows per page — the archive lists are paginated server-side. */
const PER_PAGE = 10;

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

/** Status pill shared by the task / subtask tables. */
function StatusPill({ name }: { name?: string | null }) {
  if (!name) return <span className="text-ink-soft">—</span>;
  return <Badge>{name}</Badge>;
}

/** Query state passed from the page down into whichever tab is mounted. */
type ArchiveQuery = AdminQuery;

export function ArchivesView() {
  const { isAuthenticated, isLoading } = useAuth();
  const isManager = useIsManager();
  const router = useRouter();

  const [tab, setTab] = useState<TabKey>("workspaces");
  const [search, setSearch] = useState("");
  const [debounced, setDebounced] = useState("");
  const [page, setPage] = useState(1);

  // Debounce the search term, and drop back to page 1 whenever it changes —
  // a narrowed result set may no longer have the page we were on.
  useEffect(() => {
    const t = setTimeout(() => {
      setDebounced(search);
      setPage(1);
    }, 250);
    return () => clearTimeout(t);
  }, [search]);

  useEffect(() => {
    if (!isLoading && !isAuthenticated) {
      router.push("/login");
    }
  }, [isAuthenticated, isLoading, router]);

  if (isLoading) {
    return (
      <SkeletonPage>
        <SkeletonTableRows
          rows={6}
          className={TABLE_FRAME}
        />
      </SkeletonPage>
    );
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

  const switchTab = (key: TabKey) => {
    setTab(key);
    setSearch("");
    setDebounced("");
    setPage(1);
  };

  const params: Record<string, unknown> = { page, per_page: PER_PAGE };
  if (debounced.trim()) params.search = debounced.trim();

  const query: ArchiveQuery = { params, search, page, onPage: setPage };

  return (
    <div className="px-4 py-6 sm:px-6 lg:px-8">
      {/* Header */}
      <h1 className="text-2xl font-bold tracking-display text-ink sm:text-3xl">Archives</h1>
      <p className="mt-2 text-base text-ink-soft">
        View and restore soft-deleted items.
      </p>

      {/* Toolbar — the segmented control is the same one used by the workspace
          view switcher and the task modal's Individual / Group toggle. */}
      <div className="mt-8 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <SegmentedControl className="w-full overflow-x-auto sm:w-auto">
          {TABS.map((t) => (
            <SegmentedItem
              key={t.key}
              active={tab === t.key}
              onClick={() => switchTab(t.key)}
              className="flex-1 sm:flex-none"
            >
              {t.label}
            </SegmentedItem>
          ))}
        </SegmentedControl>

        <div className="relative w-full sm:w-[275px]">
          <SearchIcon className="pointer-events-none absolute left-4 top-1/2 h-4 w-4 -translate-y-1/2 text-ink-faint" />
          <Input
            value={search}
            onChange={(e) => setSearch(e.target.value)}
            placeholder="Search"
            aria-label={`Search archived ${tab}`}
            className="h-10 rounded-[10px] pl-10"
          />
        </div>
      </div>

      <div className="mt-6">
        {tab === "workspaces" && <ArchivedWorkspacesList query={query} />}
        {tab === "projects" && <ArchivedProjectsList query={query} />}
        {tab === "tasks" && <ArchivedTasksList query={query} />}
        {tab === "subtasks" && <ArchivedSubtasksList query={query} />}
      </div>
    </div>
  );
}

type Column<T> = AdminColumn<T>;

/**
 * The archive table: the shared management-surface shell with a Restore action
 * per row. Each tab differs only in its columns and its data/restore hooks.
 */
function ArchiveTable<T extends { id: string }>({
  label,
  columns,
  query,
  result,
  onRestore,
  isRestoring,
}: {
  /** Plural noun used in the empty state and the pagination summary. */
  label: string;
  columns: Column<T>[];
  query: AdminQuery;
  result: { data?: Paginated<T>; isLoading: boolean; isError: boolean };
  onRestore: (id: string) => void;
  isRestoring: boolean;
}) {
  return (
    <AdminTable
      label={`archived ${label}`}
      columns={columns}
      query={query}
      result={result}
      summaryVerb="archived"
      emptyMessage={`No archived ${label}. Soft-deleted ${label} will appear here.`}
      searchEmptyMessage={`No archived ${label} match your search.`}
      actions={(row) => (
        <Button
          size="xs"
          disabled={isRestoring}
          onClick={() => onRestore(row.id)}
        >
          Restore
        </Button>
      )}
    />
  );
}

function ArchivedWorkspacesList({ query }: { query: ArchiveQuery }) {
  const result = useArchivedWorkspaces(query.params);
  const restore = useRestoreWorkspace();

  const columns: Column<ArchivedWorkspace>[] = [
    {
      key: "name",
      label: "Workspaces",
      className: "w-[220px]",
      render: (ws) => <span className="font-semibold text-ink">{ws.name}</span>,
    },
    {
      key: "description",
      label: "Description",
      render: (ws) => (
        <span className="line-clamp-1">{ws.description ?? "—"}</span>
      ),
    },
    {
      key: "deleted_at",
      label: "Deleted At",
      className: "w-[200px] text-center",
      render: (ws) => (
        <span className="font-medium">{fmtDate(ws.deleted_at)}</span>
      ),
    },
  ];

  return (
    <ArchiveTable
      label="workspaces"
      columns={columns}
      query={query}
      result={result}
      onRestore={(id) => restore.mutate(id)}
      isRestoring={restore.isPending}
    />
  );
}

function ArchivedProjectsList({ query }: { query: ArchiveQuery }) {
  const result = useArchivedProjects(query.params);
  const restore = useRestoreProject();

  const columns: Column<ArchivedProject>[] = [
    {
      key: "name",
      label: "Projects",
      className: "w-[220px]",
      render: (p) => (
        <div className="flex items-center gap-2">
          {p.color && (
            <span
              className="h-2.5 w-2.5 shrink-0 rounded-full"
              style={{ backgroundColor: p.color }}
            />
          )}
          <span className="font-semibold text-ink">{p.name}</span>
        </div>
      ),
    },
    {
      key: "workspace",
      label: "Workspace",
      render: (p) => p.workspace_name ?? "—",
    },
    {
      key: "priority",
      label: "Priority",
      className: "w-[140px]",
      render: (p) =>
        p.priority ? (
          <Badge
            tone={
              p.priority === "high"
                ? "danger"
                : p.priority === "medium"
                  ? "warning"
                  : "info"
            }
          >
            {p.priority}
          </Badge>
        ) : (
          <span className="text-ink-soft">—</span>
        ),
    },
    {
      key: "deleted_at",
      label: "Deleted At",
      className: "w-[200px] text-center",
      render: (p) => (
        <span className="font-medium">{fmtDate(p.deleted_at)}</span>
      ),
    },
  ];

  return (
    <ArchiveTable
      label="projects"
      columns={columns}
      query={query}
      result={result}
      onRestore={(id) => restore.mutate(id)}
      isRestoring={restore.isPending}
    />
  );
}

function ArchivedTasksList({ query }: { query: ArchiveQuery }) {
  const result = useArchivedTasks(query.params);
  const restore = useRestoreTask();

  const columns: Column<ArchivedTask>[] = [
    {
      key: "title",
      label: "Tasks",
      className: "w-[220px]",
      render: (t) => <span className="font-semibold text-ink">{t.title}</span>,
    },
    {
      key: "project",
      label: "Project",
      render: (t) => t.project_name ?? "—",
    },
    {
      key: "assignee",
      label: "Assignee / Group",
      className: "w-[180px]",
      render: (t) => t.assignee?.name ?? t.group_name ?? "—",
    },
    {
      key: "priority",
      label: "Priority",
      className: "w-[120px]",
      render: (t) => <PriorityBadge priority={t.priority} />,
    },
    {
      key: "status",
      label: "Status",
      className: "w-[140px]",
      render: (t) => <StatusPill name={t.status?.name} />,
    },
    {
      key: "deleted_at",
      label: "Deleted At",
      className: "w-[200px] text-center",
      render: (t) => (
        <span className="font-medium">{fmtDate(t.deleted_at)}</span>
      ),
    },
  ];

  return (
    <ArchiveTable
      label="tasks"
      columns={columns}
      query={query}
      result={result}
      onRestore={(id) => restore.mutate(id)}
      isRestoring={restore.isPending}
    />
  );
}

function ArchivedSubtasksList({ query }: { query: ArchiveQuery }) {
  const result = useArchivedSubtasks(query.params);
  const restore = useRestoreSubtask();

  const columns: Column<ArchivedSubtask>[] = [
    {
      key: "name",
      label: "Subtasks",
      className: "w-[200px]",
      render: (st) => <span className="font-semibold text-ink">{st.name}</span>,
    },
    {
      key: "task",
      label: "Task",
      render: (st) => st.task_name ?? "—",
    },
    {
      key: "project",
      label: "Project",
      className: "w-[160px]",
      render: (st) => st.project_name ?? "—",
    },
    {
      key: "assignee",
      label: "Assignee",
      className: "w-[160px]",
      render: (st) => st.assignee?.name ?? "—",
    },
    {
      key: "priority",
      label: "Priority",
      className: "w-[120px]",
      render: (st) => <PriorityBadge priority={st.priority} />,
    },
    {
      key: "status",
      label: "Status",
      className: "w-[140px]",
      render: (st) => <StatusPill name={st.status?.name} />,
    },
    {
      key: "deleted_at",
      label: "Deleted At",
      className: "w-[200px] text-center",
      render: (st) => (
        <span className="font-medium">{fmtDate(st.deleted_at)}</span>
      ),
    },
  ];

  return (
    <ArchiveTable
      label="subtasks"
      columns={columns}
      query={query}
      result={result}
      onRestore={(id) => restore.mutate(id)}
      isRestoring={restore.isPending}
    />
  );
}
