"use client";

import { useMemo, useState } from "react";
import type { User, UserRole } from "@/core/types";
import { Card, Input, SearchIcon } from "@/core/ui";
import { useGroups, useUsers } from "../hooks";
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
 * Member Team View — read-only team directory for regular members.
 * Implemented from the Figma "Members View › team-management" screen: two wide
 * stat cards (no "Active Now"), Job Roles + Groups filters, read-only members
 * table and groups panel. No management actions, no profile card.
 */
export function MemberTeamView() {
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

  const resetPage = (fn: () => void) => {
    fn();
    setPage(0);
  };

  return (
    <div className="mx-auto max-w-[1400px] px-4 py-8 sm:px-6 lg:px-10 lg:py-10">
      {/* Header */}
      <div className="flex items-center justify-between">
        <h1 className="text-2xl font-bold text-ink sm:text-[32px]">Team</h1>
      </div>

      {/* Stat cards — two wide cards (Total Members, Groups) */}
      <div className="mt-4 grid grid-cols-1 gap-4 sm:grid-cols-2">
        <Card className="rounded-xl p-4">
          <p className="text-[13px] text-ink-soft">Total Members</p>
          <p className="mt-2 text-[24px] font-extrabold text-ink">
            {users.length}
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
          {/* LEFT — Member List (read-only) */}
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

          {/* RIGHT — Groups (read-only) */}
          <div>
            <h2 className="text-lg font-bold text-ink">Groups</h2>
            <div className="mt-4 space-y-4">
              {isLoading ? (
                <p className="text-ink-soft">Loading groups...</p>
              ) : groups.length === 0 ? (
                <Card className="rounded-xl p-6 text-center">
                  <p className="text-sm font-bold text-ink">No groups yet</p>
                  <p className="mt-1 text-xs text-ink-soft">
                    Groups will appear here once they&apos;re created.
                  </p>
                </Card>
              ) : (
                groups.map((g) => <GroupCard key={g.id} group={g} />)
              )}
            </div>
          </div>
        </div>
      )}
    </div>
  );
}
