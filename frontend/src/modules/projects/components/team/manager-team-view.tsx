"use client";

import { useMemo, useState } from "react";
import Link from "next/link";
import { UserCog } from "lucide-react";
import type { User, UserRole } from "@/core/types";
import { Button, Card, Input, SearchIcon } from "@/core/ui";
import { useGroups } from "@/modules/projects/hooks/use-groups";
import { useUsers } from "@/modules/projects/hooks/use-users";
import {
  CreateGroupModal,
} from "@/modules/projects/components/groups/create-group-modal";
import {
  MemberDetailModal,
} from "@/modules/projects/components/team/member-detail-modal";
import {
  GroupsPanel,
  MembersTable,
  TeamFilterPopover,
  buildGroupsByUser,
} from "@/modules/projects/components/team/team-shared";
import { PAGE_SIZE, Pagination } from "@/modules/projects/components/shared/pagination";

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
  const [createGroupOpen, setCreateGroupOpen] = useState(false);

  // The member whose detail modal is open.
  const [selectedMember, setSelectedMember] = useState<User | null>(null);

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
    <div className="px-4 py-6 sm:px-6 lg:px-8">
      {/* Header. This list shows who is already here; creating the sign-in that
          puts someone ON it is the org-wide screen behind this button — the only
          route to it, since the rail stops at Team. */}
      <div className="flex flex-wrap items-center justify-between gap-3">
        <h1 className="text-2xl font-bold text-ink sm:text-3xl">Team</h1>
        <Button asChild>
          <Link href="/sprintly/team/accounts">
            <UserCog />
            Team &amp; Roles
          </Link>
        </Button>
      </div>

      {/* Stat cards — 2-up on phones so they stay glanceable instead of a
          three-card vertical stack pushing the table below the fold. */}
      <div className="mt-4 grid grid-cols-2 gap-3 sm:gap-4 lg:grid-cols-3">
        <Card className="rounded-xl p-4">
          <p className="text-sm text-ink-soft">Total Members</p>
          <p className="mt-2 text-2xl font-extrabold text-ink">
            {users.length}
          </p>
        </Card>
        <Card className="rounded-xl p-4">
          <p className="text-sm text-ink-soft">Managers</p>
          <p className="mt-2 text-2xl font-extrabold text-ink">
            {managerCount}
          </p>
        </Card>
        <Card className="col-span-2 rounded-xl p-4 lg:col-span-1">
          <p className="text-sm text-ink-soft">Groups</p>
          <p className="mt-2 text-2xl font-extrabold text-ink">
            {groups.length}
          </p>
        </Card>
      </div>

      {/* Filters */}
      <div className="mt-6 flex flex-col gap-3 sm:flex-row sm:items-center">
        <div className="relative min-w-0 flex-1">
          <SearchIcon className="pointer-events-none absolute left-4 top-1/2 h-4 w-4 -translate-y-1/2 text-ink-faint" />
          <Input
            value={search}
            onChange={(e) => resetPage(() => setSearch(e.target.value))}
            placeholder="Search by name or email..."
            className="h-10 rounded-[10px] pl-10"
          />
        </div>
        <TeamFilterPopover
          roleFilter={roleFilter}
          onRoleChange={(v) => resetPage(() => setRoleFilter(v))}
          groupFilter={groupFilter}
          onGroupChange={(v) => resetPage(() => setGroupFilter(v))}
          groups={groups}
        />
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
        // Rows stretch (the grid default) so the groups panel fills the same
        // height as the members card — GroupsPanel takes itself out of flow at
        // lg so only the card decides what that height is.
        //
        // The panel's track is `min(380px, 34%)`, not a flat 380px: it holds a
        // name, two pills and an avatar stack, while the table beside it has
        // four columns to fit, so the panel is the one that should give up
        // width first. 34% resolves to 380px at the design's 1440px and shrinks
        // from there. A percentage rather than a vw so a collapsed sidebar
        // hands the width back.
        <div className="mt-6 grid grid-cols-1 gap-6 lg:grid-cols-[minmax(0,1fr)_min(380px,34%)]">
          {/* LEFT — Member List. The table draws its own frame, so it sits
              straight on the canvas rather than inside a Card, and its heading
              lines up with the Groups heading beside it. */}
          <div className="min-w-0">
            <div className="flex min-h-9 items-center">
              <h2 className="text-lg font-bold text-ink">
                Members
              </h2>
            </div>
            <div className="mt-4">
              <MembersTable
                rows={pageRows}
                groupsByUser={groupsByUser}
                isLoading={isLoading}
                onSelect={setSelectedMember}
              />
            </div>
            <Pagination
              page={safePage}
              pageCount={pageCount}
              onPage={setPage}
              summary={`Showing ${rangeStart}-${rangeEnd} of ${filtered.length} members`}
            />
          </div>

          {/* RIGHT — Groups */}
          <GroupsPanel
            groups={groups}
            isLoading={isLoading}
            onCreate={() => setCreateGroupOpen(true)}
          />
        </div>
      )}

      <MemberDetailModal
        member={selectedMember}
        groups={groups}
        onClose={() => setSelectedMember(null)}
      />

      {/* Create Group modal */}
      <CreateGroupModal
        open={createGroupOpen}
        onClose={() => setCreateGroupOpen(false)}
      />
    </div>
  );
}
