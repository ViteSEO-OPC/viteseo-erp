"use client";

import { useMemo, useState } from "react";
import {
  Avatar,
  Badge,
  Button,
  Card,
  ChevronRightIcon,
  ConfirmDialog,
  Input,
  PencilIcon,
  PlusIcon,
  SearchIcon,
  TrashIcon,
} from "@/core/ui";
import { useIsManager } from "@/core/auth/AuthProvider";
import type { Group } from "../../types";
import {
  useDeleteGroup,
  useGroup,
  useGroupMembers,
  useGroups,
  useGroupWorkload,
  useUsers,
  useWorkspaceTasks,
} from "../../hooks";
import { GroupWorkloadBadge } from "../tasks/shared";
import { CreateGroupModal } from "./CreateGroupModal";
import { EditGroupModal } from "./EditGroupModal";
import { MemberPickerModal } from "../team/MemberPickerModal";

/** Round colored avatar standing in for the group icon (Figma uses a solid
 *  color circle). Uses the group's stored hex as the background. */
function GroupIcon({
  group,
  size = 40,
}: {
  group: Group;
  size?: number;
}): React.ReactElement {
  return (
    <span
      style={{
        backgroundColor: group.color,
        width: size,
        height: size,
        fontSize: size * 0.4,
      }}
      className="inline-flex shrink-0 items-center justify-center rounded-full font-bold text-white"
      aria-hidden
    >
      {group.name.charAt(0).toUpperCase()}
    </span>
  );
}

/** Formats an ISO date to a "Mon YYYY" label (e.g. "Jan 2024"). */
function monthYear(dateStr?: string): string | null {
  if (!dateStr) return null;
  const d = new Date(dateStr);
  if (Number.isNaN(d.getTime())) return null;
  return d.toLocaleDateString("en-US", { month: "short", year: "numeric" });
}

export function GroupsView(): React.ReactElement {
  const groupsQuery = useGroups();
  const usersQuery = useUsers();

  const groups = useMemo(
    () => groupsQuery.data?.data ?? [],
    [groupsQuery.data],
  );
  const users = usersQuery.data ?? [];

  const [search, setSearch] = useState("");
  const [selectedId, setSelectedId] = useState<string | null>(null);
  const [createOpen, setCreateOpen] = useState(false);
  const [pickerOpen, setPickerOpen] = useState(false);
  const [editOpen, setEditOpen] = useState(false);
  const [toDelete, setToDelete] = useState<Group | null>(null);
  const isManager = useIsManager();
  const deleteGroup = useDeleteGroup();

  // Derive the effective selection (defaults to the first group) rather than
  // setting state in an effect.
  const effectiveSelectedId = selectedId ?? groups[0]?.id ?? "";

  const filtered = useMemo(() => {
    const q = search.trim().toLowerCase();
    return q ? groups.filter((g) => g.name.toLowerCase().includes(q)) : groups;
  }, [groups, search]);

  const detailQuery = useGroup(effectiveSelectedId);
  const selected = detailQuery.data;
  const groupWorkload = useGroupWorkload(effectiveSelectedId || null);
  const groupMembers = useGroupMembers(effectiveSelectedId);

  // The group's projects (it can belong to several). Used by the detail pane's
  // Projects section and to scope the group's tasks across all of them.
  const groupProjects = useMemo(() => selected?.projects ?? [], [selected]);
  const projectIds = useMemo(
    () => groupProjects.map((p) => p.id),
    [groupProjects],
  );

  const memberIds = useMemo(
    () => (selected?.members ?? []).map((m) => m.id),
    [selected],
  );
  const memberBusy =
    groupMembers.add.isPending || groupMembers.remove.isPending;

  const membersCount =
    selected?.members?.length ?? selected?.members_count ?? 0;

  // Active Tasks / Completed — this group's tasks across ALL its projects.
  const { tasks: projectTasks, isLoading: tasksLoadingRaw } =
    useWorkspaceTasks(projectIds);
  const groupTasks = useMemo(
    () => (selected ? projectTasks.filter((t) => t.group_id === selected.id) : []),
    [projectTasks, selected],
  );
  const tasksLoading = !!selected && projectIds.length > 0 && tasksLoadingRaw;
  const activeTasks = groupTasks.filter((t) => t.status?.key !== "done").length;
  const completed = groupTasks.filter((t) => t.status?.key === "done").length;

  return (
    <div className="mx-auto max-w-[1400px] px-4 py-6 sm:px-6 lg:px-8 lg:py-8">
      {/* Header */}
      <div className="flex flex-wrap items-center justify-between gap-3">
        <h1 className="text-2xl font-bold text-ink sm:text-[32px]">Groups</h1>
        {isManager && (
          <Button onClick={() => setCreateOpen(true)}>
            <PlusIcon className="h-4 w-4" />
            Create New Group
          </Button>
        )}
      </div>

      {groupsQuery.isError ? (
        <Card className="mt-6 p-8 text-center">
          <p className="font-semibold text-danger">
            Couldn&apos;t load groups.
          </p>
          <p className="mt-1 text-sm text-ink-soft">
            Make sure the API is running and you&apos;re signed in.
          </p>
        </Card>
      ) : (
        <div className="mt-6 grid grid-cols-1 gap-6 lg:grid-cols-[minmax(320px,420px)_1fr]">
          {/* LEFT: search + list */}
          <div>
            <div className="relative">
              <SearchIcon className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-ink-faint" />
              <Input
                value={search}
                onChange={(e) => setSearch(e.target.value)}
                placeholder="Search groups…"
                className="pl-9"
              />
            </div>

            <div className="mt-4 space-y-3">
              {groupsQuery.isLoading && (
                <p className="text-ink-soft">Loading groups…</p>
              )}

              {!groupsQuery.isLoading && filtered.length === 0 && (
                <Card className="p-8 text-center">
                  <p className="font-bold text-ink">No groups found</p>
                  <p className="mt-1 text-sm text-ink-soft">
                    {search
                      ? "Try a different search."
                      : "Create your first group to get started."}
                  </p>
                </Card>
              )}

              {filtered.map((g) => {
                const isSelected = g.id === effectiveSelectedId;
                return (
                  <button
                    key={g.id}
                    type="button"
                    onClick={() => setSelectedId(g.id)}
                    className={`flex w-full items-center gap-3 rounded-xl border bg-surface p-4 text-left transition-colors ${
                      isSelected
                        ? "border-brand ring-1 ring-brand"
                        : "border-line hover:border-ink-faint"
                    }`}
                  >
                    <GroupIcon group={g} />
                    <div className="min-w-0 flex-1">
                      <p
                        className={`truncate font-bold ${isSelected ? "text-brand" : "text-ink"}`}
                      >
                        {g.name}
                      </p>
                      <p className="mt-0.5 truncate text-sm text-ink-soft">
                        {g.members_count ?? 0} Members • {g.projects_count ?? 0}{" "}
                        {(g.projects_count ?? 0) === 1 ? "Project" : "Projects"}
                      </p>
                    </div>
                    <GroupWorkloadBadge groupId={g.id} />
                    {!g.archived && <Badge tone="accent">Active</Badge>}
                    <span className="flex h-7 w-7 shrink-0 items-center justify-center rounded-full border border-line">
                      <ChevronRightIcon className="h-4 w-4 text-ink-soft" />
                    </span>
                  </button>
                );
              })}
            </div>
          </div>

          {/* RIGHT: detail */}
          <div className="space-y-6">
            {effectiveSelectedId === "" ||
            (detailQuery.isLoading && !selected) ? (
              <Card className="p-10 text-center text-ink-soft">
                {groups.length === 0 && !groupsQuery.isLoading
                  ? "No group selected."
                  : "Loading group…"}
              </Card>
            ) : detailQuery.isError || !selected ? (
              <Card className="p-8 text-center">
                <p className="font-semibold text-danger">
                  Couldn&apos;t load this group.
                </p>
              </Card>
            ) : (
              <>
                {/* Overview card */}
                <Card className="p-6">
                  <div className="flex items-start gap-4">
                    <GroupIcon group={selected} size={56} />
                    <div className="min-w-0 flex-1">
                      <div className="flex items-center gap-3">
                        <h2 className="text-2xl font-bold text-ink">
                          {selected.name}
                        </h2>
                        {!selected.archived && (
                          <Badge tone="accent">Active</Badge>
                        )}
                      </div>
                      {monthYear(selected.created_at) && (
                        <p className="mt-1 text-sm text-ink-soft">
                          Since {monthYear(selected.created_at)}
                        </p>
                      )}
                      {selected.leader && (
                        <p className="mt-0.5 text-sm text-ink-soft">
                          Led by {selected.leader.name}
                        </p>
                      )}
                    </div>

                    {isManager && (
                      <div className="flex shrink-0 items-center gap-1">
                        <button
                          type="button"
                          onClick={() => setEditOpen(true)}
                          aria-label={`Edit ${selected.name}`}
                          className="rounded-md p-2 text-ink-faint transition-colors hover:bg-brand/10 hover:text-brand"
                        >
                          <PencilIcon className="h-4 w-4" />
                        </button>
                        <button
                          type="button"
                          onClick={() => setToDelete(selected)}
                          aria-label={`Delete ${selected.name}`}
                          className="rounded-md p-2 text-ink-faint transition-colors hover:bg-danger/10 hover:text-danger"
                        >
                          <TrashIcon className="h-4 w-4" />
                        </button>
                      </div>
                    )}
                  </div>

                  {/* Groups have no description field. Summarize reach instead. */}
                  {groupProjects.length > 0 && (
                    <p className="mt-4 text-sm leading-relaxed text-ink-soft">
                      A dedicated POD collaborating across{" "}
                      {groupProjects.length}{" "}
                      {groupProjects.length === 1 ? "project" : "projects"}.
                    </p>
                  )}

                  <div className="mt-5 grid grid-cols-2 gap-4 lg:grid-cols-4">
                    <Card className="p-4">
                      <p className="text-sm text-ink-soft">Members</p>
                      <p className="mt-2 text-3xl font-bold leading-none text-ink">
                        {membersCount}
                      </p>
                    </Card>
                    <Card className="p-4">
                      <p className="text-sm text-ink-soft">Active Tasks</p>
                      <p className="mt-2 text-3xl font-bold leading-none text-ink">
                        {tasksLoading ? "—" : activeTasks}
                      </p>
                    </Card>
                    <Card className="p-4">
                      <p className="text-sm text-ink-soft">Completed</p>
                      <p className="mt-2 text-3xl font-bold leading-none text-ink">
                        {tasksLoading ? "—" : completed}
                      </p>
                    </Card>
                    <Card className="p-4">
                      <p className="text-sm text-ink-soft">Total Effort</p>
                      <p className="mt-2 text-3xl font-bold leading-none text-ink">
                        {groupWorkload.isLoading
                          ? "—"
                          : (groupWorkload.data?.effort_score ?? 0)}
                      </p>
                    </Card>
                  </div>

                  <p className="mt-6 text-xs font-bold uppercase tracking-wide text-ink-soft">
                    Projects
                  </p>
                  <div className="mt-3 space-y-2">
                    {groupProjects.length === 0 ? (
                      <p className="text-sm text-ink-soft">
                        Not in any project yet — a group joins a project once all
                        its members are on it.
                      </p>
                    ) : (
                      groupProjects.map((p) => (
                        <div
                          key={p.id}
                          className="flex items-center justify-between rounded-lg border border-line px-4 py-3"
                        >
                          <span className="truncate font-semibold text-ink">
                            {p.name}
                          </span>
                          {p.priority && (
                            <Badge
                              tone={p.priority === "high" ? "danger" : "neutral"}
                            >
                              {p.priority}
                            </Badge>
                          )}
                        </div>
                      ))
                    )}
                  </div>
                </Card>

                {/* Members card */}
                <Card className="p-6">
                  <div className="flex items-center justify-between">
                    <h3 className="text-xl font-bold text-ink">
                      Members ({membersCount})
                    </h3>
                    {isManager && (
                      <button
                        type="button"
                        onClick={() => setPickerOpen(true)}
                        className="inline-flex items-center gap-1 text-sm font-bold text-brand hover:text-brand-hover"
                      >
                        <PlusIcon className="h-4 w-4" />
                        Add Member
                      </button>
                    )}
                  </div>

                  <div className="mt-4 space-y-1">
                    {(selected.members ?? []).length === 0 ? (
                      <p className="py-4 text-sm text-ink-soft">
                        No members yet.
                      </p>
                    ) : (
                      (selected.members ?? []).map((m) => (
                        <div
                          key={m.id}
                          className="flex items-center gap-3 rounded-lg px-2 py-2 hover:bg-black/5"
                        >
                          <Avatar name={m.name} src={m.avatar} size={40} />
                          <div className="min-w-0 flex-1">
                            <p className="truncate font-bold text-ink">
                              {m.name}
                            </p>
                            <p className="truncate text-sm text-ink-soft">
                              {m.role ?? m.email}
                              {selected.leader?.id === m.id ? " • Leader" : ""}
                            </p>
                          </div>
                          {monthYear(m.created_at) && (
                            <span className="shrink-0 text-sm text-ink-soft">
                              {monthYear(m.created_at)}
                            </span>
                          )}
                          {isManager && (
                            <button
                              type="button"
                              disabled={memberBusy}
                              onClick={() => groupMembers.remove.mutate(m.id)}
                              aria-label={`Remove ${m.name}`}
                              className="flex h-7 w-7 items-center justify-center rounded-full text-danger transition-colors hover:bg-danger-soft disabled:opacity-50"
                            >
                              ✕
                            </button>
                          )}
                        </div>
                      ))
                    )}
                  </div>
                </Card>
              </>
            )}
          </div>
        </div>
      )}

      <CreateGroupModal
        open={createOpen}
        onClose={() => setCreateOpen(false)}
      />

      {selected && (
        <MemberPickerModal
          open={pickerOpen}
          onClose={() => setPickerOpen(false)}
          title={`Add members to ${selected.name}`}
          candidates={users}
          selectedIds={memberIds}
          onAdd={(id) => groupMembers.add.mutate(id)}
          onRemove={(id) => groupMembers.remove.mutate(id)}
          busy={memberBusy}
        />
      )}

      {editOpen && selected && (
        <EditGroupModal group={selected} onClose={() => setEditOpen(false)} />
      )}

      <ConfirmDialog
        open={toDelete !== null}
        onClose={() => setToDelete(null)}
        onConfirm={async () => {
          if (!toDelete) return;
          await deleteGroup.mutateAsync(toDelete.id);
          // Deleted group may have been the selection — fall back to the first.
          setSelectedId(null);
          setToDelete(null);
        }}
        title="Delete group"
        confirmLabel="Delete group"
        confirmationText={toDelete?.name}
        isPending={deleteGroup.isPending}
        description={
          <>
            This will permanently delete the group{" "}
            <span className="font-bold text-ink">{toDelete?.name}</span>. Its tasks
            will remain but become unassigned from the group. This can&apos;t be undone.
          </>
        }
      />
    </div>
  );
}
