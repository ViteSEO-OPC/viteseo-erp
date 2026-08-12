"use client";

import Link from "next/link";
import { useMemo, useState } from "react";
import {
  Badge,
  Card,
  ConfirmDialog,
  Input,
  PencilIcon,
  PriorityBadge,
  SearchIcon,
  TrashIcon,
} from "@/core/ui";
import { useIsManager } from "@/core/auth/AuthProvider";
import type { Priority } from "@/modules/projects/types/common";
import type { Project } from "@/modules/projects/types/project";
import {
  useDeleteProject,
  useProjects,
} from "@/modules/projects/hooks/use-projects";
import { useWorkspaces } from "@/modules/projects/hooks/use-workspaces";
import {
  EditProjectModal,
} from "@/modules/projects/components/edit-project-modal";
import {
  PAGE_SIZE,
  Pagination,
} from "@/modules/projects/components/team-shared";

/** Capitalises the API's lowercase priority into the PriorityBadge's enum. */
function toPriority(p: string | null): Priority | null {
  if (!p) return null;
  const v = (p.charAt(0).toUpperCase() + p.slice(1)) as Priority;
  return v === "Low" || v === "Medium" || v === "High" ? v : null;
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

/**
 * All Projects — striped list of every project across workspaces.
 * Implemented from the Figma "project-list" screen, backed by real data.
 */
export function AllProjectsView() {
  const projectsQuery = useProjects();
  const workspacesQuery = useWorkspaces();

  const projects = useMemo(
    () => projectsQuery.data?.data ?? [],
    [projectsQuery.data],
  );
  const workspaceName = useMemo(() => {
    const map = new Map<string, string>();
    for (const w of workspacesQuery.data?.data ?? []) map.set(w.id, w.name);
    return map;
  }, [workspacesQuery.data]);

  const [search, setSearch] = useState("");
  const [showDone, setShowDone] = useState(false);
  const [page, setPage] = useState(0);
  const [toDelete, setToDelete] = useState<Project | null>(null);
  const [toEdit, setToEdit] = useState<Project | null>(null);
  const isManager = useIsManager();
  const del = useDeleteProject();

  // Done projects are hidden by default (they drop out of the working views);
  // the toggle reveals them so they can still be reopened/managed.
  const doneCount = useMemo(
    () => projects.filter((p) => p.status === "done").length,
    [projects],
  );

  const confirmDelete = async () => {
    if (!toDelete) return;
    try {
      await del.mutateAsync(toDelete.id);
    } catch {
      // Keep the dialog open so the action is still there to retry — closing it
      // would look like the delete had worked.
      return;
    }
    setToDelete(null);
  };

  const isLoading = projectsQuery.isLoading;
  const isError = projectsQuery.isError;

  const filtered = useMemo(() => {
    const base = showDone
      ? projects
      : projects.filter((p) => p.status !== "done");
    const q = search.trim().toLowerCase();
    if (!q) return base;
    return base.filter(
      (p) =>
        p.name.toLowerCase().includes(q) ||
        (p.description ?? "").toLowerCase().includes(q) ||
        (workspaceName.get(p.workspace_id) ?? "").toLowerCase().includes(q),
    );
  }, [projects, search, showDone, workspaceName]);

  const pageCount = Math.max(1, Math.ceil(filtered.length / PAGE_SIZE));
  const safePage = Math.min(page, pageCount - 1);
  const rows = filtered.slice(
    safePage * PAGE_SIZE,
    safePage * PAGE_SIZE + PAGE_SIZE,
  );
  const rangeStart = filtered.length === 0 ? 0 : safePage * PAGE_SIZE + 1;
  const rangeEnd = safePage * PAGE_SIZE + rows.length;

  return (
    <div className="px-4 py-4 sm:px-6 lg:px-8">
      {/* Header */}
      <h1 className="text-2xl font-bold text-ink sm:text-[32px]">All Projects</h1>

      {/* Search + completed toggle */}
      <div className="mt-6 flex flex-col gap-3 sm:flex-row sm:items-center">
        <div className="relative w-full max-w-md">
          <SearchIcon className="pointer-events-none absolute left-4 top-1/2 h-4 w-4 -translate-y-1/2 text-ink-faint" />
          <Input
            value={search}
            onChange={(e) => {
              setSearch(e.target.value);
              setPage(0);
            }}
            placeholder="Search projects..."
            className="h-10 rounded-[10px] pl-10"
          />
        </div>
        <label className="flex shrink-0 cursor-pointer items-center gap-2 text-sm font-semibold text-ink-soft select-none">
          <input
            type="checkbox"
            checked={showDone}
            onChange={(e) => {
              setShowDone(e.target.checked);
              setPage(0);
            }}
            className="h-4 w-4 accent-brand"
          />
          Show completed{doneCount > 0 ? ` (${doneCount})` : ""}
        </label>
      </div>

      {isError ? (
        <Card className="mt-6 p-8 text-center">
          <p className="font-semibold text-danger">
            Couldn&apos;t load projects.
          </p>
          <p className="mt-1 text-sm text-ink-soft">
            Make sure the API is running and you&apos;re signed in.
          </p>
        </Card>
      ) : (
        <Card className="mt-6 overflow-hidden rounded-xl p-0">
          <div className="overflow-x-auto">
            <div className="min-w-[760px]">
          {/* Column headers */}
          <div
            className={`grid ${
              isManager
                ? "grid-cols-[2fr_2.5fr_1.2fr_1fr_1fr_76px]"
                : "grid-cols-[2fr_2.5fr_1.2fr_1fr_1fr]"
            } gap-4 border-b border-line px-6 py-4`}
          >
            {[
              "Project Name",
              "Description",
              "Workspace",
              "Priority",
              "Created",
            ].map((h) => (
              <span
                key={h}
                className="text-xs font-bold uppercase tracking-wide text-ink-soft"
              >
                {h}
              </span>
            ))}
            {isManager && <span className="sr-only">Actions</span>}
          </div>

          {isLoading ? (
            <p className="py-10 text-center text-ink-soft">
              Loading projects...
            </p>
          ) : rows.length === 0 ? (
            <p className="py-10 text-center text-ink-soft">
              No projects match your search.
            </p>
          ) : (
            rows.map((p, idx) => (
              <Link
                key={p.id}
                href={`/sprintly/projects/${p.id}`}
                className={`grid ${
                  isManager
                    ? "grid-cols-[2fr_2.5fr_1.2fr_1fr_1fr_76px]"
                    : "grid-cols-[2fr_2.5fr_1.2fr_1fr_1fr]"
                } items-center gap-4 px-6 py-4 transition-colors hover:bg-brand/5 ${
                  idx % 2 === 1 ? "bg-surface-warm" : ""
                }`}
              >
                <div className="flex min-w-0 items-center gap-2">
                  <span
                    className="h-2.5 w-2.5 shrink-0 rounded-full"
                    style={{ backgroundColor: p.color ?? "#4fdbd7" }}
                  />
                  <span className="truncate font-semibold text-ink">
                    {p.name}
                  </span>
                  {p.status === "done" && (
                    <Badge tone="success" className="shrink-0">
                      Done
                    </Badge>
                  )}
                </div>
                <span className="truncate text-sm text-ink-soft">
                  {p.description ?? "—"}
                </span>
                <span className="truncate text-sm text-ink-soft">
                  {workspaceName.get(p.workspace_id) ?? "—"}
                </span>
                <span>
                  {toPriority(p.priority) ? (
                    <PriorityBadge
                      priority={toPriority(p.priority) as Priority}
                    />
                  ) : (
                    <span className="text-sm text-ink-soft">—</span>
                  )}
                </span>
                <span className="text-sm text-ink-soft">
                  {formatDate(p.created_at)}
                </span>
                {isManager && (
                  <span className="flex items-center justify-center gap-1">
                    <button
                      type="button"
                      aria-label={`Edit ${p.name}`}
                      onClick={(e) => {
                        e.preventDefault();
                        e.stopPropagation();
                        setToEdit(p);
                      }}
                      className="rounded-md p-1.5 text-ink-faint transition-colors hover:bg-brand/10 hover:text-brand"
                    >
                      <PencilIcon className="h-4 w-4" />
                    </button>
                    <button
                      type="button"
                      aria-label={`Delete ${p.name}`}
                      onClick={(e) => {
                        e.preventDefault();
                        e.stopPropagation();
                        setToDelete(p);
                      }}
                      className="rounded-md p-1.5 text-ink-faint transition-colors hover:bg-danger/10 hover:text-danger"
                    >
                      <TrashIcon className="h-4 w-4" />
                    </button>
                  </span>
                )}
              </Link>
            ))
          )}
            </div>
          </div>

          {/* Pagination */}
          {!isLoading && filtered.length > 0 && (
            <div className="px-6 pb-4">
              <Pagination
                page={safePage}
                pageCount={pageCount}
                onPage={setPage}
                summary={`Showing ${rangeStart}-${rangeEnd} of ${filtered.length} projects`}
              />
            </div>
          )}
        </Card>
      )}

      <ConfirmDialog
        open={toDelete !== null}
        onClose={() => setToDelete(null)}
        onConfirm={confirmDelete}
        title="Delete project"
        confirmLabel="Delete project"
        confirmationText={toDelete?.name}
        isPending={del.isPending}
        description={
          <>
            This will archive the project{" "}
            <span className="font-bold text-ink">{toDelete?.name}</span>. You can restore it later
            from the Archives page.
          </>
        }
      />

      {toEdit && (
        <EditProjectModal project={toEdit} onClose={() => setToEdit(null)} />
      )}
    </div>
  );
}
