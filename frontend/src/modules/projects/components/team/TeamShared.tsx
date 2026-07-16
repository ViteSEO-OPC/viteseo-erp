"use client";

import type { ReactNode } from "react";
import type { User, UserRole } from "@/core/types";
import type { Group } from "../../types";
import {
  Avatar,
  AvatarStack,
  Badge,
  Card,
  ChevronDownIcon,
  ChevronLeftIcon,
  ChevronRightIcon,
} from "@/core/ui";
import { GroupWorkloadBadge, UserWorkloadBadge } from "../tasks/shared";

export const PAGE_SIZE = 8;
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
 */
export function RoleBadge({ role }: { role?: UserRole }) {
  if (role === "Admin") {
    return (
      <Badge tone="danger" className="rounded-full px-2.5 py-1">
        Admin
      </Badge>
    );
  }
  return (
    <Badge className="rounded-full border border-brand bg-white px-2.5 py-1 text-[#0f8f8b]">
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

/** Compact "Label: [value ▾]" filter dropdown used in the Team toolbar. */
export function FilterSelect({
  label,
  value,
  onChange,
  children,
}: {
  label: string;
  value: string;
  onChange: (value: string) => void;
  children: ReactNode;
}) {
  return (
    <div className="flex items-center gap-2 rounded-[10px] border border-line bg-white px-3 py-2">
      <span className="whitespace-nowrap text-xs text-ink-soft">{label}</span>
      <div className="relative">
        <select
          value={value}
          onChange={(e) => onChange(e.target.value)}
          className="appearance-none rounded-md bg-brand-soft/60 py-1 pl-2.5 pr-7 text-xs font-semibold text-ink focus:outline-none"
        >
          {children}
        </select>
        <ChevronDownIcon className="pointer-events-none absolute right-2 top-1/2 h-3 w-3 -translate-y-1/2 text-ink-soft" />
      </div>
    </div>
  );
}

/**
 * Members table (header + rows). Columns: Member · Role · Group · Job Role.
 * Group is derived from group membership; Job Role has no backing field yet so
 * it degrades to an em dash.
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
    <div className="overflow-x-auto">
      <div className="min-w-[560px]">
      <div className="border-b border-line pb-4">
        <h2 className="text-lg font-bold text-ink">Members</h2>
        <div className="mt-3 grid grid-cols-[2fr_1fr_1fr_1fr] gap-4">
          <span className="text-xs font-bold uppercase tracking-wide text-ink-soft">
            Member
          </span>
          <span className="text-xs font-bold uppercase tracking-wide text-ink-soft">
            Role
          </span>
          <span className="text-xs font-bold uppercase tracking-wide text-ink-soft">
            Group
          </span>
          <span className="text-xs font-bold uppercase tracking-wide text-ink-soft">
            Workload
          </span>
        </div>
      </div>

      {isLoading ? (
        <p className="py-10 text-center text-ink-soft">Loading members...</p>
      ) : rows.length === 0 ? (
        <p className="py-10 text-center text-ink-soft">
          No members match your filters.
        </p>
      ) : (
        <div className="divide-y divide-line">
          {rows.map((u, idx) => (
            <div
              key={u.id}
              className={`grid grid-cols-[2fr_1fr_1fr_1fr] items-center gap-4 py-4 ${
                idx % 2 === 1 ? "bg-[#FAFAF0]" : ""
              }`}
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
              <div>
                <RoleBadge role={u.role} />
              </div>
              <MemberGroupTags
                tags={groupsByUser.get(u.id) ?? []}
                emptyDash
                max={3}
              />
              <UserWorkloadBadge userId={u.id} />
            </div>
          ))}
        </div>
      )}
      </div>
    </div>
  );
}

/** Builds the page-number sequence with ellipses (e.g. 1 2 3 … 12). */
function pageItems(total: number, current: number): Array<number | "dots"> {
  if (total <= 7) return Array.from({ length: total }, (_, i) => i + 1);
  const range: number[] = [];
  for (
    let i = Math.max(2, current - 1);
    i <= Math.min(total - 1, current + 1);
    i++
  ) {
    range.push(i);
  }
  const items: Array<number | "dots"> = [1];
  if (range[0] > 2) items.push("dots");
  items.push(...range);
  if (range[range.length - 1] < total - 1) items.push("dots");
  items.push(total);
  return items;
}

/** Pagination footer with range summary + numbered bubbles. `page` is 0-indexed. */
export function Pagination({
  page,
  pageCount,
  onPage,
  summary,
}: {
  page: number;
  pageCount: number;
  onPage: (page: number) => void;
  summary: string;
}) {
  return (
    <div className="mt-4 flex items-center justify-between border-t border-line pt-4">
      <p className="text-[13px] text-ink-soft">{summary}</p>
      <div className="flex items-center gap-2">
        <button
          type="button"
          disabled={page === 0}
          onClick={() => onPage(Math.max(0, page - 1))}
          className="flex h-9 w-9 items-center justify-center rounded-full border border-line transition-colors hover:bg-black/5 disabled:opacity-50"
          aria-label="Previous page"
        >
          <ChevronLeftIcon className="h-4 w-4" />
        </button>
        <div className="flex items-center gap-1">
          {pageItems(pageCount, page + 1).map((item, i) =>
            item === "dots" ? (
              <span
                key={`dots-${i}`}
                className="flex h-8 w-8 items-center justify-center text-[13px] text-ink-soft"
              >
                …
              </span>
            ) : (
              <button
                key={item}
                type="button"
                onClick={() => onPage(item - 1)}
                className={`flex h-8 w-8 items-center justify-center rounded-full text-[13px] font-semibold transition-colors ${
                  page + 1 === item
                    ? "bg-ink text-white"
                    : "bg-white text-ink-soft hover:bg-black/5"
                }`}
              >
                {item}
              </button>
            ),
          )}
        </div>
        <button
          type="button"
          disabled={page >= pageCount - 1}
          onClick={() => onPage(Math.min(pageCount - 1, page + 1))}
          className="flex h-9 w-9 items-center justify-center rounded-full border border-line transition-colors hover:bg-black/5 disabled:opacity-50"
          aria-label="Next page"
        >
          <ChevronRightIcon className="h-4 w-4" />
        </button>
      </div>
    </div>
  );
}

/** Read-only group card: name, member-count pill, lead, avatar stack. */
export function GroupCard({ group }: { group: Group }) {
  return (
    <Card className="rounded-xl p-5">
      <div className="flex items-center justify-between gap-2">
        <h3 className="min-w-0 truncate text-lg font-bold text-ink">
          {group.name}
        </h3>
        <div className="flex shrink-0 items-center gap-2">
          <GroupWorkloadBadge groupId={group.id} />
          <Badge className="rounded-full bg-ink px-2.5 py-1 text-white">
            {group.members_count ?? group.members?.length ?? 0} members
          </Badge>
        </div>
      </div>
      <div className="mt-3 flex items-center gap-2">
        {group.leader && (
          <Avatar
            name={group.leader.name}
            src={group.leader.avatar}
            size={20}
          />
        )}
        <p className="text-[13px] text-ink-soft">
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
