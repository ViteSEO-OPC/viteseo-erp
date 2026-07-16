"use client";

import { useMemo, useState } from "react";
import type { User, UserRole } from "@/core/types";
import { Button, Card, Input, SearchIcon } from "@/core/ui";
import { useGroups, useUsers } from "../../hooks";
import { CreateGroupModal } from "../groups/CreateGroupModal";
import { MemberPickerModal } from "./MemberPickerModal";
import {
  FilterSelect,
  GroupCard,
  MembersTable,
  PAGE_SIZE,
  Pagination,
  ROLE_OPTIONS,
  buildGroupsByUser,
  roleLabel,
} from "./TeamShared";

/**
 * Manager Team View — full management view for PM/Admin users.
 * Implemented from the Figma "Admin View › team-management" screen.
 */
export function ManagerTeamView() {
  const usersQuery = useUsers();
  const groupsQuery = useGroups();

  const users = useMemo<User[]>(() => usersQuery.data ?? [], [usersQuery.data]);
  const groups = useMemo(
    () => groupsQuery.data?.data ?? [],
    [groupsQuery.data],
  );
  const groupsByUser = useMemo(() => buildGroupsByUser(groups), [groups]);

  const [search, setSearch] = useState("");
  const [roleFilter, setRoleFilter] = useState<"all" | UserRole>("all");
  const [groupFilter, setGroupFilter] = useState("all");
  const [page, setPage] = useState(0);
  const [assignOpen, setAssignOpen] = useState(false);
  const [createGroupOpen, setCreateGroupOpen] = useState(false);

  const isLoading = usersQuery.isLoading || groupsQuery.isLoading;
  const isError = usersQuery.isError || groupsQuery.isError;

  const filtered = useMemo(() => {
    const q = search.trim().toLowerCase();
    return users.filter((u) => {
      const matchesSearch =
        !q ||
        u.name.toLowerCase().includes(q) ||
        u.email.toLowerCase().includes(q);
      const matchesRole = roleFilter === "all" || u.role === roleFilter;
      const matchesGroup =
        groupFilter === "all" ||
        (groupsByUser.get(u.id) ?? []).some((t) => t.name === groupFilter);
      return matchesSearch && matchesRole && matchesGroup;
    });
  }, [users, search, roleFilter, groupFilter, groupsByUser]);

  const pageCount = Math.max(1, Math.ceil(filtered.length / PAGE_SIZE));
  const safePage = Math.min(page, pageCount - 1);
  const pageRows = filtered.slice(
    safePage * PAGE_SIZE,
    safePage * PAGE_SIZE + PAGE_SIZE,
  );
  const rangeStart = filtered.length === 0 ? 0 : safePage * PAGE_SIZE + 1;
  const rangeEnd = safePage * PAGE_SIZE + pageRows.length;

  // Real metric: count of managers (Admin or PM) among loaded users.
  const managerCount = users.filter(
    (u) => u.role === "Admin" || u.role === "PM",
  ).length;

  const resetPage = (fn: () => void) => {
    fn();
    setPage(0);
  };

  return (
    <div className="mx-auto max-w-[1400px] px-4 py-8 sm:px-6 lg:px-10 lg:py-10">
      {/* Header */}
      <div className="flex flex-wrap items-center justify-between gap-3">
        <h1 className="text-2xl font-bold text-ink sm:text-[32px]">Team</h1>
        <Button
          onClick={() => setAssignOpen(true)}
          className="rounded-[10px] px-5 py-2.5"
        >
          <span className="text-sm font-bold">+ Add Member</span>
        </Button>
      </div>

      {/* Stat cards */}
      <div className="mt-4 grid grid-cols-1 gap-4 sm:grid-cols-3">
        <Card className="rounded-xl p-4">
          <p className="text-[13px] text-ink-soft">Total Members</p>
          <p className="mt-2 text-[24px] font-extrabold text-ink">
            {users.length}
          </p>
        </Card>
        <Card className="rounded-xl p-4">
          <p className="text-[13px] text-ink-soft">Managers</p>
          <p className="mt-2 text-[24px] font-extrabold text-ink">
            {managerCount}
          </p>
        </Card>
        <Card className="rounded-xl p-4">
          <p className="text-[13px] text-ink-soft">Groups</p>
          <p className="mt-2 text-[24px] font-extrabold text-ink">
            {groups.length}
          </p>
        </Card>
      </div>

      {/* Filters */}
      <div className="mt-6 flex flex-col gap-3 sm:flex-row sm:items-center">
        <div className="relative flex-1">
          <SearchIcon className="pointer-events-none absolute left-4 top-1/2 h-4 w-4 -translate-y-1/2 text-ink-faint" />
          <Input
            value={search}
            onChange={(e) => resetPage(() => setSearch(e.target.value))}
            placeholder="Search by name or email..."
            className="h-10 rounded-[10px] pl-10"
          />
        </div>
        <FilterSelect
          label="Job Roles:"
          value={roleFilter}
          onChange={(v) =>
            resetPage(() => setRoleFilter(v as "all" | UserRole))
          }
        >
          <option value="all">All</option>
          {ROLE_OPTIONS.map((r) => (
            <option key={r} value={r}>
              {roleLabel(r)}
            </option>
          ))}
        </FilterSelect>
        <FilterSelect
          label="Groups:"
          value={groupFilter}
          onChange={(v) => resetPage(() => setGroupFilter(v))}
        >
          <option value="all">All</option>
          {groups.map((g) => (
            <option key={g.id} value={g.name}>
              {g.name}
            </option>
          ))}
        </FilterSelect>
      </div>

      {/* Body */}
      {isError ? (
        <Card className="mt-6 p-8 text-center">
          <p className="font-semibold text-danger">
            Couldn&apos;t load the team.
          </p>
          <p className="mt-1 text-sm text-ink-soft">
            Make sure the API is running and you&apos;re signed in.
          </p>
        </Card>
      ) : (
        <div className="mt-6 grid grid-cols-1 gap-6 lg:grid-cols-[1fr_380px]">
          {/* LEFT — Member List */}
          <Card className="rounded-xl p-6">
            <MembersTable
              rows={pageRows}
              groupsByUser={groupsByUser}
              isLoading={isLoading}
            />
            <Pagination
              page={safePage}
              pageCount={pageCount}
              onPage={setPage}
              summary={`Showing ${rangeStart}-${rangeEnd} of ${filtered.length} members`}
            />
          </Card>

          {/* RIGHT — Groups */}
          <div>
            <div className="flex items-center justify-between">
              <h2 className="text-lg font-bold text-ink">Groups</h2>
              <button
                type="button"
                onClick={() => setCreateGroupOpen(true)}
                className="inline-flex items-center gap-1.5 rounded-lg border border-brand bg-white px-3 py-1.5 text-sm font-bold text-[#0f766e] transition-colors hover:bg-brand/10"
              >
                <span className="text-base">+</span>
                Create Group
              </button>
            </div>

            <div className="mt-4 space-y-4">
              {isLoading ? (
                <p className="text-ink-soft">Loading groups...</p>
              ) : groups.length === 0 ? (
                <Card className="rounded-xl p-6 text-center">
                  <p className="text-sm font-bold text-ink">No groups yet</p>
                  <p className="mt-1 text-xs text-ink-soft">
                    Create a group to organize members.
                  </p>
                  <Button
                    variant="outline"
                    size="sm"
                    className="mt-4"
                    onClick={() => setCreateGroupOpen(true)}
                  >
                    + Create Group
                  </Button>
                </Card>
              ) : (
                groups.map((g) => <GroupCard key={g.id} group={g} />)
              )}
            </div>
          </div>
        </div>
      )}

      {/* Assign Member modal */}
      <MemberPickerModal
        open={assignOpen}
        onClose={() => setAssignOpen(false)}
        title="Add Member"
        candidates={users}
        selectedIds={[]}
        onAdd={() => {}}
        onRemove={() => {}}
      />

      {/* Create Group modal */}
      <CreateGroupModal
        open={createGroupOpen}
        onClose={() => setCreateGroupOpen(false)}
      />
    </div>
  );
}
