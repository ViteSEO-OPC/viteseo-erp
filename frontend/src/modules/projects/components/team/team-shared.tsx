"use client";

import { useState } from "react";
import Link from "next/link";
import type { User, UserRole } from "@/core/types";
import type { Group } from "@/modules/projects/types/group";
import {
  Avatar,
  AvatarStack,
  Badge,
  Button,
  Card,
  ChevronDownIcon,
  FilterIcon,
  PlusIcon,
} from "@/core/ui";
import {
  TABLE_EMPTY,
  TABLE_HEAD,
  TABLE_HEAD_PAD,
  TABLE_HEAD_TEXT,
  TableFrame,
} from "@/modules/projects/components/shared/data-table";
import { CountBubble } from "@/modules/projects/components/shared/pills";
import { GroupWorkloadBadge, UserWorkloadBadge } from "@/modules/projects/components/shared/workload-badges";
import {
  SkeletonList,
} from "@/modules/projects/components/shared/skeletons";

export const ROLE_OPTIONS: UserRole[] = ["Admin", "PM", "Member"];

/** Human label for a role — expands the `PM` shorthand to match the Figma design. */
export function roleLabel(role?: UserRole): string {
  if (role === "PM") return "Project Manager";
  return role ?? "Member";
}

/**
 * Role pill. Admin renders as a soft red badge; PM/Member render as an
 * outlined teal badge (per the Figma "Team" design). The Badge component
 * upper-cases the label for us.
 *
 * Only the colours are overridden — size, radius and type come from `Badge`,
 * so this sits at the same height as every other pill in the row (workload,
 * effort, member count). Base `Badge` already carries a transparent border, so
 * the outline here doesn't change the box.
 */
export function RoleBadge({ role }: { role?: UserRole }) {
  if (role === "Admin") {
    return <Badge tone="danger">Admin</Badge>;
  }
  return (
    <Badge className="border-brand bg-white text-brand-mid">
      {roleLabel(role)}
    </Badge>
  );
}

export type GroupTag = { id: string; name: string; color: string };

/** Maps every user id to ALL groups they belong to (leader or member), with color. */
export function buildGroupsByUser(groups: Group[]): Map<number, GroupTag[]> {
  const map = new Map<number, GroupTag[]>();
  const add = (userId: number, g: Group) => {
    const tag: GroupTag = { id: g.id, name: g.name, color: g.color };
    const existing = map.get(userId);
    if (!existing) map.set(userId, [tag]);
    else if (!existing.some((t) => t.id === g.id)) existing.push(tag);
  };
  for (const g of groups) {
    if (g.leader) add(g.leader.id, g);
    for (const m of g.members ?? []) add(m.id, g);
  }
  return map;
}

/** A member's group memberships as colored dot + name tags. */
export function MemberGroupTags({
  tags,
  className,
  emptyDash = false,
  max,
}: {
  tags: GroupTag[];
  className?: string;
  /** Render an em dash when the member is in no group (for table cells). */
  emptyDash?: boolean;
  /**
   * Cap the number of visible tags. When set and exceeded, the first `max`
   * tags render followed by a compact "+N more" pill. Omit to show all.
   */
  max?: number;
}) {
  if (tags.length === 0)
    return emptyDash ? <span className="text-sm text-ink-soft">—</span> : null;
  const visible = max != null ? tags.slice(0, max) : tags;
  const overflow = tags.length - visible.length;
  return (
    <div className={`flex flex-wrap items-center gap-x-2 gap-y-1 ${className ?? ""}`}>
      {visible.map((t) => (
        <span
          key={t.id}
          className="inline-flex items-center gap-1 text-xs font-medium text-ink-soft"
        >
          <span
            className="h-2 w-2 shrink-0 rounded-full"
            style={{ backgroundColor: t.color }}
          />
          {t.name}
        </span>
      ))}
      {overflow > 0 && (
        <span className="text-xs font-medium text-ink-soft">+{overflow} more</span>
      )}
    </div>
  );
}

/** Shared row styling for the filter popover's option buttons. */
const FILTER_ROW =
  "flex w-full items-center gap-2 rounded-md px-2 py-1.5 text-left text-sm text-ink transition-colors hover:bg-black/5";

/**
 * Team filters (job role + group) in one popover, matching the task board's
 * Filters control: a single trigger with an active-constraint badge, sections
 * inside, and Clear all. Keeping both filters behind one button is what lets
 * the toolbar stay on a single row down to phone widths.
 *
 * "all" is the unset value for both filters — it never counts toward the badge.
 */
export function TeamFilterPopover({
  roleFilter,
  onRoleChange,
  groupFilter,
  onGroupChange,
  groups,
}: {
  roleFilter: "all" | UserRole;
  onRoleChange: (role: "all" | UserRole) => void;
  groupFilter: string;
  onGroupChange: (groupName: string) => void;
  groups: Group[];
}) {
  const [open, setOpen] = useState(false);

  const activeCount =
    (roleFilter === "all" ? 0 : 1) + (groupFilter === "all" ? 0 : 1);

  const clearAll = () => {
    onRoleChange("all");
    onGroupChange("all");
  };

  return (
    <div className="relative shrink-0">
      <button
        type="button"
        onClick={() => setOpen((v) => !v)}
        aria-expanded={open}
        className={`flex h-10 w-full items-center gap-2 rounded-lg border bg-surface px-3 text-sm font-bold transition-colors sm:w-auto ${
          activeCount > 0
            ? "border-brand text-ink"
            : "border-line text-ink-soft hover:text-ink"
        }`}
      >
        <FilterIcon className="h-4 w-4" />
        Filters
        {activeCount > 0 && (
          <CountBubble>{activeCount}</CountBubble>
        )}
        <ChevronDownIcon className="ml-auto h-4 w-4 sm:ml-0" />
      </button>

      {open && (
        <>
          <div
            className="fixed inset-0 z-10"
            onClick={() => setOpen(false)}
            aria-hidden
          />
          {/* Opens rightward on mobile (the trigger is full-width there) and
              right-aligned from sm up, where it sits at the end of the row. */}
          <div className="absolute left-0 top-full z-20 mt-2 w-72 rounded-xl border border-line bg-surface p-3 shadow-lg sm:left-auto sm:right-0">
            <div className="flex items-center justify-between px-1 pb-2">
              <span className="text-xs font-bold uppercase tracking-wide text-ink-faint">
                Filters
              </span>
              {activeCount > 0 && (
                <button
                  type="button"
                  onClick={clearAll}
                  className="text-xs font-bold text-brand-strong hover:underline"
                >
                  Clear all
                </button>
              )}
            </div>

            <div className="px-1">
              <p className="mb-1 text-2xs font-bold uppercase tracking-wide text-ink-faint">
                Job role
              </p>
              <div className="space-y-0.5">
                <FilterOptionRow
                  label="All roles"
                  selected={roleFilter === "all"}
                  onClick={() => onRoleChange("all")}
                />
                {ROLE_OPTIONS.map((r) => (
                  <FilterOptionRow
                    key={r}
                    label={roleLabel(r)}
                    selected={roleFilter === r}
                    onClick={() => onRoleChange(r)}
                  />
                ))}
              </div>
            </div>

            <div className="mt-2 border-t border-line px-1 pt-2">
              <p className="mb-1 text-2xs font-bold uppercase tracking-wide text-ink-faint">
                Group
              </p>
              <div className="max-h-40 space-y-0.5 overflow-y-auto">
                <FilterOptionRow
                  label="All groups"
                  selected={groupFilter === "all"}
                  onClick={() => onGroupChange("all")}
                />
                {groups.length === 0 ? (
                  <p className="px-2 py-1 text-xs text-ink-faint">No groups</p>
                ) : (
                  groups.map((g) => (
                    <FilterOptionRow
                      key={g.id}
                      label={g.name}
                      color={g.color}
                      selected={groupFilter === g.name}
                      onClick={() => onGroupChange(g.name)}
                    />
                  ))
                )}
              </div>
            </div>
          </div>
        </>
      )}
    </div>
  );
}

function FilterOptionRow({
  label,
  color,
  selected,
  onClick,
}: {
  label: string;
  color?: string;
  selected: boolean;
  onClick: () => void;
}) {
  return (
    <button type="button" onClick={onClick} className={FILTER_ROW}>
      {color && (
        <span
          className="h-2.5 w-2.5 shrink-0 rounded-full"
          style={{ backgroundColor: color }}
        />
      )}
      <span className="min-w-0 flex-1 truncate">{label}</span>
      {selected && <span className="text-xs font-bold text-brand">✓</span>}
    </button>
  );
}

/** Column template — one source of truth for the header and every row. */
const MEMBER_COLS = "md:grid-cols-[2fr_1fr_1.2fr_1fr]";

/**
 * Height of a full page of rows, so the frame doesn't shrink to fit a short
 * page. Without it a page holding one member makes the whole grid row one
 * member tall, and the groups panel beside it — which is sized by that row —
 * collapses with it, so the panel's height jumped around as you paged or
 * filtered.
 *
 * 40px header band + 1px rule, then `PAGE_SIZE` (8) rows of 68px (a 40px
 * avatar inside `py-3.5`) with a hairline between them: 41 + 8x68 + 7 = 592.
 * It's a floor rather than a hard height so a row whose group tags wrap can
 * still grow instead of being clipped.
 */
const MEMBERS_TABLE_MIN_H = "md:min-h-[592px]";

/**
 * Members list. Columns: Member · Role · Group · Workload (group is derived
 * from group membership).
 *
 * Below `md` each member is a stacked card — identity on top, then role,
 * groups and workload wrapping beneath — so the list never needs a sideways
 * scroll on a phone. From `md` up the same markup becomes the four-column
 * table via `md:contents` on the secondary-fields wrapper.
 *
 * The frame is the list's own (`TableFrame`), so callers place it directly on
 * the page rather than inside a `<Card>` — two nested white boxes on the warm
 * canvas only ever read as a rendering mistake. The "Members" heading is the
 * caller's too, so it sits on the canvas beside the "Groups" heading it pairs
 * with, and the band inside the frame carries column labels only.
 */
export function MembersTable({
  rows,
  groupsByUser,
  isLoading,
}: {
  rows: User[];
  groupsByUser: Map<number, GroupTag[]>;
  isLoading: boolean;
}) {
  return (
    <TableFrame
      scroll={false}
      className={`min-w-0 ${MEMBERS_TABLE_MIN_H}`}
    >
      {/* Column labels are meaningless once the rows stack, so below md the
          band collapses away entirely. */}
      <div
        className={`hidden gap-4 md:grid ${MEMBER_COLS} ${TABLE_HEAD} ${TABLE_HEAD_PAD} ${TABLE_HEAD_TEXT}`}
      >
        {["Member", "Role", "Group", "Workload"].map((h) => (
          <span key={h}>{h}</span>
        ))}
      </div>

      {isLoading ? (
        <SkeletonList rows={5} className="p-5" />
      ) : rows.length === 0 ? (
        <p className={TABLE_EMPTY}>No members match your filters.</p>
      ) : (
        // Separate cards below md; one divided table from md up.
        <div className="space-y-3 p-4 md:space-y-0 md:p-0 md:divide-y md:divide-line">
          {rows.map((u) => (
            <div
              key={u.id}
              // Padding is stated per breakpoint rather than reused from
              // TABLE_GRID_ROW: below md this is a card with its own inset, and
              // `p-4` + `px-5 py-3.5` on one element resolves by stylesheet
              // order, not by which was written last.
              className={`flex flex-col gap-3 rounded-xl border border-line p-4 transition-colors md:grid md:items-center md:gap-4 md:rounded-none md:border-0 md:px-5 md:py-3.5 md:hover:bg-black/[0.02] ${MEMBER_COLS}`}
            >
              <div className="flex min-w-0 items-center gap-3">
                <Avatar name={u.name} src={u.avatar} size={40} />
                <div className="min-w-0">
                  <p className="truncate text-sm font-semibold text-ink">
                    {u.name}
                  </p>
                  <p className="truncate text-xs text-ink-soft">{u.email}</p>
                </div>
              </div>
              {/* Below md each field is a labelled row, so it reads without the
                  column headers and every card comes out the same shape. From
                  md up `md:contents` dissolves this box and the three fields
                  become the grid's own columns. */}
              <div className="flex flex-col gap-2 border-t border-line pt-3 md:contents">
                <MemberField label="Role">
                  <RoleBadge role={u.role} />
                </MemberField>
                <MemberField label="Group">
                  <MemberGroupTags
                    tags={groupsByUser.get(u.id) ?? []}
                    emptyDash
                    max={3}
                  />
                </MemberField>
                <MemberField label="Workload">
                  <UserWorkloadBadge userId={u.id} />
                </MemberField>
              </div>
            </div>
          ))}
        </div>
      )}
    </TableFrame>
  );
}

/**
 * One member field. Below md it's a `label · value` row with the label in a
 * fixed gutter so the values line up down the card; from md up the label is
 * dropped (the column header says it) and this wrapper becomes a grid cell.
 */
function MemberField({
  label,
  children,
}: {
  label: string;
  children: React.ReactNode;
}) {
  return (
    <div className="flex items-start gap-3 md:block">
      <span className="w-20 shrink-0 pt-0.5 text-xs font-bold uppercase tracking-wide text-ink-faint md:hidden">
        {label}
      </span>
      <div className="min-w-0 flex-1 md:flex-none">{children}</div>
    </div>
  );
}

/**
 * Groups side panel, sized to match the members table it sits beside.
 *
 * From `lg` the inner column is absolutely positioned inside the grid cell, so
 * the groups contribute nothing to the row's height — the members table alone
 * decides how tall the row is, and the list fills exactly that and scrolls the
 * overflow. Below `lg` the panel is full-width with nothing beside it, so it
 * flows normally under a plain height cap. "View all groups" is the escape
 * hatch to the full Groups page either way.
 *
 * Pass `onCreate` to get the manager-only Create Group affordances.
 */
export function GroupsPanel({
  groups,
  isLoading,
  onCreate,
}: {
  groups: Group[];
  isLoading: boolean;
  onCreate?: () => void;
}) {
  return (
    <section className="min-w-0 lg:relative">
      <div className="flex flex-col lg:absolute lg:inset-0">
        <div className="flex flex-wrap items-center justify-between gap-2">
          <h2 className="text-lg font-bold text-ink">Groups</h2>
          <div className="flex items-center gap-2">
            <Button asChild variant="link" size="sm">
              <Link href="/sprintly/groups">View all groups</Link>
            </Button>
            {onCreate && (
              <Button variant="brand-outline" size="sm" onClick={onCreate}>
                <PlusIcon />
                Create Group
              </Button>
            )}
          </div>
        </div>

        {isLoading ? (
          <SkeletonList rows={4} withAvatar={false} className="mt-4" />
        ) : groups.length === 0 ? (
          <Card className="mt-4 rounded-xl p-6 text-center">
            <p className="text-sm font-bold text-ink">No groups yet</p>
            <p className="mt-1 text-xs text-ink-soft">
              {onCreate
                ? "Create a group to organize members."
                : "Groups will appear here once they're created."}
            </p>
            {/* Same action as the header's, so the same button. */}
            {onCreate && (
              <Button
                variant="brand-outline"
                size="sm"
                className="mt-4"
                onClick={onCreate}
              >
                <PlusIcon />
                Create Group
              </Button>
            )}
          </Card>
        ) : (
          // `min-h-0` lets the flex child shrink below its content so the
          // overflow actually scrolls; `pr-1` keeps the cards' border clear of
          // the scrollbar. Below lg the panel is full-width, so cards sit 2-up.
          <div className="mt-4 grid max-h-[640px] grid-cols-1 gap-4 overflow-y-auto pr-1 sm:grid-cols-2 lg:max-h-none lg:min-h-0 lg:flex-1 lg:grid-cols-1">
            {groups.map((g) => (
              <GroupCard key={g.id} group={g} />
            ))}
          </div>
        )}
      </div>
    </section>
  );
}

/**
 * Read-only group card: name, workload + member-count pills, lead, avatar stack.
 *
 * The name gets a line of its own rather than sharing one with two `shrink-0`
 * pills — in a ~380px card (the side panel's width, and roughly a phone's) that
 * arrangement squeezed the name down to "Frontend …" while the pills kept their
 * full size. The pills wrap underneath instead.
 */
export function GroupCard({ group }: { group: Group }) {
  const memberCount = group.members_count ?? group.members?.length ?? 0;

  return (
    <Card className="rounded-xl p-6">
      <h3
        className="truncate text-lg font-bold text-ink"
        title={group.name}
      >
        {group.name}
      </h3>
      <div className="mt-2 flex flex-wrap items-center gap-2">
        <GroupWorkloadBadge groupId={group.id} />
        <Badge className="bg-ink text-white">
          {memberCount} {memberCount === 1 ? "member" : "members"}
        </Badge>
      </div>

      <div className="mt-3 flex min-w-0 items-center gap-2">
        {group.leader && (
          <Avatar
            name={group.leader.name}
            src={group.leader.avatar}
            size={20}
          />
        )}
        <p className="min-w-0 truncate text-sm text-ink-soft">
          Lead: {group.leader?.name ?? "—"}
        </p>
      </div>

      {group.members && group.members.length > 0 && (
        <div className="mt-4">
          <AvatarStack
            people={group.members.map((m) => ({ name: m.name, src: m.avatar }))}
            max={4}
            size={28}
          />
        </div>
      )}
    </Card>
  );
}
