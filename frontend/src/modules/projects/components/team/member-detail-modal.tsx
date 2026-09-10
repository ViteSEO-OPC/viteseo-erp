"use client";

import Link from "next/link";
import type { User } from "@/core/types";
import { Avatar, Badge, Button, Modal, PriorityBadge } from "@/core/ui";
import type { Priority } from "@/modules/projects/types/common";
import type { Group, GroupProjectRef } from "@/modules/projects/types/group";
import { RoleBadge } from "@/modules/projects/components/team/team-shared";
import { UserWorkloadBadge } from "@/modules/projects/components/shared/workload-badges";

/** Every group `userId` belongs to — as its leader or as a member. */
export function groupsForUser(groups: Group[], userId: number): Group[] {
  return groups.filter(
    (g) =>
      g.leader?.id === userId ||
      (g.members ?? []).some((m) => m.id === userId),
  );
}

/** The API's lowercase priority, in the casing `PriorityBadge` expects. */
function toPriority(p: GroupProjectRef["priority"]): Priority | null {
  if (!p) return null;
  const v = (p.charAt(0).toUpperCase() + p.slice(1)) as Priority;
  return v === "Low" || v === "Medium" || v === "High" ? v : null;
}

/**
 * Member detail modal — opened by clicking a row in the Members table.
 *
 * Projects are nested under the group they came through rather than pooled into
 * one list: in this module a person reaches a project THROUGH a group, and the
 * same project can arrive via two of them.
 *
 * Reads only the `groups` the Team page already fetched (the list endpoint
 * eager-loads leader, members and projects), so opening it costs no request.
 */
export function MemberDetailModal({
  member,
  groups,
  onClose,
}: {
  /** The clicked member; `null` keeps the modal closed. */
  member: User | null;
  /** All groups, unfiltered — the member's are picked out here. */
  groups: Group[];
  onClose: () => void;
}) {
  const memberGroups = member ? groupsForUser(groups, member.id) : [];

  // A project reached through two groups is still one project.
  const projectCount = new Set(
    memberGroups.flatMap((g) => (g.projects ?? []).map((p) => p.id)),
  ).size;

  return (
    <Modal
      open={member !== null}
      onClose={onClose}
      title="Member details"
      width={560}
      footer={
        <div className="flex justify-end">
          <Button variant="ghost" onClick={onClose}>
            Close
          </Button>
        </div>
      }
    >
      {member && (
        <>
          <div className="flex items-start gap-4">
            <Avatar name={member.name} src={member.avatar} size={56} />
            <div className="min-w-0 flex-1">
              <p className="truncate text-base font-bold text-ink">
                {member.name}
              </p>
              <p className="truncate text-sm text-ink-soft">{member.email}</p>
              <div className="mt-2 flex flex-wrap items-center gap-x-3 gap-y-2">
                <RoleBadge role={member.role} />
                {/* The table says "Workload" in a column header; here the
                    number needs to say it itself. */}
                <span className="flex items-center gap-2">
                  <span className="text-2xs font-bold uppercase tracking-wide text-ink-faint">
                    Workload
                  </span>
                  <UserWorkloadBadge userId={member.id} />
                </span>
              </div>
            </div>
          </div>

          {memberGroups.length > 0 && (
            <p className="mt-4 border-t border-line pt-4 text-sm text-ink-soft">
              In{" "}
              <span className="font-bold text-ink">
                {memberGroups.length}{" "}
                {memberGroups.length === 1 ? "group" : "groups"}
              </span>{" "}
              across{" "}
              <span className="font-bold text-ink">
                {projectCount} {projectCount === 1 ? "project" : "projects"}
              </span>
              .
            </p>
          )}

          {memberGroups.length === 0 ? (
            // Membership is how someone reaches a project here, so the empty
            // state says what to do about it rather than just "none".
            <div className="mt-4 rounded-xl border border-dashed border-line p-6 text-center">
              <p className="text-sm font-bold text-ink">No groups</p>
              <p className="mt-1 text-xs text-ink-soft">
                Add {member.name.split(" ")[0]} to a group to bring them onto
                its projects.
              </p>
            </div>
          ) : (
            <div className="mt-4 space-y-3">
              {memberGroups.map((g) => (
                <GroupBlock
                  key={g.id}
                  group={g}
                  isLead={g.leader?.id === member.id}
                  onNavigate={onClose}
                />
              ))}
            </div>
          )}
        </>
      )}
    </Modal>
  );
}

/** One group the member belongs to, with the projects that group works on. */
function GroupBlock({
  group,
  isLead,
  onNavigate,
}: {
  group: Group;
  isLead: boolean;
  onNavigate: () => void;
}) {
  const projects = group.projects ?? [];
  const memberCount = group.members_count ?? group.members?.length ?? 0;

  return (
    <div className="rounded-xl border border-line p-4">
      <div className="flex flex-wrap items-center gap-2">
        <span
          className="h-2.5 w-2.5 shrink-0 rounded-full"
          style={{ backgroundColor: group.color }}
          aria-hidden
        />
        <h3 className="min-w-0 flex-1 truncate text-sm font-bold text-ink">
          {group.name}
        </h3>
        {isLead && <Badge tone="accent">Lead</Badge>}
        <span className="shrink-0 text-xs text-ink-soft">
          {memberCount} {memberCount === 1 ? "member" : "members"}
        </span>
      </div>

      <p className="mt-3 text-2xs font-bold uppercase tracking-wide text-ink-faint">
        Projects
      </p>
      {projects.length === 0 ? (
        <p className="mt-1 text-sm text-ink-soft">
          This group isn&apos;t on a project yet.
        </p>
      ) : (
        <div className="mt-2 space-y-2">
          {projects.map((p) => (
            <Link
              key={p.id}
              href={`/sprintly/projects/${p.id}`}
              onClick={onNavigate}
              className="flex items-center gap-2 rounded-lg border border-line px-3 py-2 outline-none transition-colors hover:border-brand hover:bg-black/[0.02] focus-visible:ring-[3px] focus-visible:ring-ring/50"
            >
              <span
                title={p.name}
                className="min-w-0 flex-1 truncate text-sm font-semibold text-ink"
              >
                {p.name}
              </span>
              {p.status === "done" && (
                <Badge tone="success" className="shrink-0">
                  Done
                </Badge>
              )}
              {toPriority(p.priority) && (
                <PriorityBadge priority={toPriority(p.priority) as Priority} />
              )}
            </Link>
          ))}
        </div>
      )}
    </div>
  );
}
