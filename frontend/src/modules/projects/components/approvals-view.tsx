"use client";

import { useEffect, useState } from "react";
import { useRouter } from "next/navigation";
import { useAuth, useIsManager } from "@/core/auth/AuthProvider";
import {
  Badge,
  Button,
  ConfirmDialog,
  Input,
  PagePlaceholder,
  PriorityBadge,
  SearchIcon,
  SegmentedControl,
  SegmentedItem,
} from "@/core/ui";
import {
  useApproveSubtask,
  useApproveTask,
  useBulkApproveSubtasks,
  useBulkApproveTasks,
  usePendingSubtasks,
  usePendingTasks,
  useRejectSubtask,
  useRejectTask,
} from "@/modules/projects/hooks/use-approvals";
import type {
  BulkApproveResult,
  PendingSubtask,
  PendingTask,
} from "@/modules/projects/types/approval";
import type { ApprovalStatus } from "@/modules/projects/types/common";
import { useOpenTask } from "@/modules/projects/hooks/use-task-modal";
import {
  AdminTable,
  type AdminColumn,
  type AdminQuery,
  type AdminSelection,
} from "@/modules/projects/components/admin-table";
import { CountBubble } from "@/modules/projects/components/pills";
import {
  RejectReasonDialog,
} from "@/modules/projects/components/reject-reason-dialog";

type TabKey = "tasks" | "subtasks";
type StateKey = "pending" | "rejected" | "all";

const TABS: { key: TabKey; label: string }[] = [
  { key: "tasks", label: "Tasks" },
  { key: "subtasks", label: "Sub-tasks" },
];

const STATES: { key: StateKey; label: string }[] = [
  { key: "pending", label: "Pending" },
  { key: "rejected", label: "Rejected" },
  { key: "all", label: "All" },
];

/** Rows per page — the queues are paginated server-side. */
const PER_PAGE = 10;

function fmtDate(iso: string | null | undefined): string {
  if (!iso) return "—";
  return new Date(iso).toLocaleDateString("en-US", {
    year: "numeric",
    month: "short",
    day: "2-digit",
  });
}

function ReviewStateBadge({
  state,
}: {
  state: PendingTask["approval_status"];
}) {
  if (state === "rejected") return <Badge tone="danger">Rejected</Badge>;
  return <Badge tone="warning">Pending</Badge>;
}

/**
 * The PM/Admin review queue for member-submitted work.
 *
 * Editing happens in the ordinary task modal: "Edit" opens `?task={uuid}`, where
 * a reviewer already has full-field access. This page only carries the decision.
 */
export function ApprovalsView() {
  const { isAuthenticated, isLoading } = useAuth();
  const isManager = useIsManager();
  const router = useRouter();

  const [tab, setTab] = useState<TabKey>("tasks");
  const [state, setState] = useState<StateKey>("pending");
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

  // How much is actually waiting on a reviewer, per queue.
  //
  // Always the PENDING count, whatever the state filter is set to — "needs
  // approval" doesn't change meaning because you're currently looking at the
  // rejected list, and a number that shifted under the filter would be a worse
  // signal, not a better one.
  //
  // `per_page: 1` because only `meta.total` is read; the rows are thrown away.
  // Both share the `approvalKeys` prefixes that invalidateAfterReview() already
  // busts, so approving or rejecting anything re-counts these for free.
  //
  // Declared above the early returns below — hooks can't sit behind a branch.
  const countParams = { state: "pending", per_page: 1 } as const;
  const pendingTaskCount = usePendingTasks(countParams, isManager).data?.meta
    .total;
  const pendingSubtaskCount = usePendingSubtasks(countParams, isManager).data
    ?.meta.total;
  const pendingCounts: Record<TabKey, number | undefined> = {
    tasks: pendingTaskCount,
    subtasks: pendingSubtaskCount,
  };

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
        blurb="Only Admins and PMs can review submitted work."
      />
    );
  }

  const reset = () => {
    setSearch("");
    setDebounced("");
    setPage(1);
  };

  const params: Record<string, unknown> = { page, per_page: PER_PAGE, state };
  if (debounced.trim()) params.search = debounced.trim();

  const query: AdminQuery = { params, search, page, onPage: setPage };

  return (
    <div className="px-4 py-4 sm:px-6 lg:px-8">
      <h1 className="text-2xl font-bold text-ink sm:text-[32px]">Approvals</h1>
      <p className="mt-2 text-[15px] text-ink-soft">
        Work submitted by members. Edit it if it needs scoping, then approve it
        onto the board or send it back with a reason.
      </p>

      <div className="mt-8 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <div className="flex flex-col gap-3 sm:flex-row sm:items-center">
          <SegmentedControl className="w-full overflow-x-auto sm:w-auto">
            {TABS.map((t) => {
              const count = pendingCounts[t.key];
              return (
                <SegmentedItem
                  key={t.key}
                  active={tab === t.key}
                  onClick={() => {
                    setTab(t.key);
                    reset();
                  }}
                  className="flex-1 sm:flex-none"
                  title={
                    count === undefined
                      ? undefined
                      : `${count} ${t.label.toLowerCase()} awaiting review`
                  }
                >
                  {t.label}
                  {/* Rendered only once counted, so the control doesn't jolt
                      wider on load. A zero is still worth showing here — "this
                      queue is clear" is the answer a reviewer opens the page
                      for — but it drops to a flat grey so only real work pulls
                      the eye. */}
                  {count !== undefined && (
                    <CountBubble
                      className={
                        count === 0 ? "bg-black/5 text-ink-faint" : undefined
                      }
                    >
                      {count}
                    </CountBubble>
                  )}
                </SegmentedItem>
              );
            })}
          </SegmentedControl>

          <SegmentedControl className="w-full overflow-x-auto sm:w-auto">
            {STATES.map((s) => (
              <SegmentedItem
                key={s.key}
                active={state === s.key}
                onClick={() => {
                  setState(s.key);
                  setPage(1);
                }}
                className="flex-1 sm:flex-none"
              >
                {s.label}
              </SegmentedItem>
            ))}
          </SegmentedControl>
        </div>

        <div className="relative w-full sm:w-[275px]">
          <SearchIcon className="pointer-events-none absolute left-4 top-1/2 h-4 w-4 -translate-y-1/2 text-ink-faint" />
          <Input
            value={search}
            onChange={(e) => setSearch(e.target.value)}
            placeholder="Search"
            aria-label={`Search submitted ${tab}`}
            className="h-10 rounded-[10px] pl-10"
          />
        </div>
      </div>

      <div className="mt-6">
        {tab === "tasks" ? (
          <PendingTasksList query={query} />
        ) : (
          <PendingSubtasksList query={query} />
        )}
      </div>
    </div>
  );
}

/**
 * Row selection for a bulk approval, scoped to the rows currently on screen.
 *
 * `resetKey` changes whenever the tab, state filter, search or page changes:
 * the selection is cleared then, because ids the reviewer can no longer see
 * must not ride along into a bulk action they didn't mean to take.
 */
function useRowSelection(resetKey: string) {
  const [selected, setSelected] = useState<Set<string>>(new Set());
  const [lastKey, setLastKey] = useState(resetKey);

  // Reset during render (React's recommended pattern) rather than in an effect,
  // so a stale selection is never briefly live.
  if (resetKey !== lastKey) {
    setLastKey(resetKey);
    if (selected.size > 0) setSelected(new Set());
  }

  const toggle = (id: string) =>
    setSelected((prev) => {
      const next = new Set(prev);
      if (next.has(id)) next.delete(id);
      else next.add(id);
      return next;
    });

  const toggleMany = (ids: string[], select: boolean) =>
    setSelected((prev) => {
      const next = new Set(prev);
      for (const id of ids) {
        if (select) next.add(id);
        else next.delete(id);
      }
      return next;
    });

  const clear = () => setSelected(new Set());

  return { selected, toggle, toggleMany, clear };
}

/**
 * The bar above the table once something is ticked: what's selected, and the
 * one bulk action. Rejecting stays per-row — it needs a reason written for that
 * specific submission, and one blanket reason for a batch is not feedback.
 */
function BulkBar({
  count,
  noun,
  isPending,
  outcome,
  onClear,
  onApprove,
}: {
  count: number;
  noun: string;
  isPending: boolean;
  /** Result of the last batch, so a partial run doesn't pass silently. */
  outcome: BulkApproveResult | null;
  onClear: () => void;
  onApprove: () => void;
}) {
  const [confirm, setConfirm] = useState(false);

  if (count === 0) {
    return outcome ? (
      <p className="mb-3 text-sm text-ink-soft">
        Approved {outcome.approved} {outcome.approved === 1 ? noun : `${noun}s`}
        {outcome.skipped > 0 &&
          ` — ${outcome.skipped} skipped (already decided by someone else)`}
        .
      </p>
    ) : null;
  }

  const label = count === 1 ? noun : `${noun}s`;

  return (
    <div className="mb-3 flex flex-wrap items-center gap-3 rounded-xl border border-brand/40 bg-brand/10 px-4 py-3">
      <span className="text-sm font-bold text-ink">
        {count} {label} selected
      </span>
      <Button
        size="sm"
        disabled={isPending}
        onClick={() => setConfirm(true)}
        className="rounded-lg border border-line bg-brand px-3 py-1.5 text-xs font-bold text-ink hover:bg-brand-hover"
      >
        {isPending ? "Approving…" : `Approve ${count} selected`}
      </Button>
      <Button size="sm" variant="ghost" disabled={isPending} onClick={onClear}>
        Clear
      </Button>

      <ConfirmDialog
        open={confirm}
        onClose={() => setConfirm(false)}
        onConfirm={() => {
          onApprove();
          setConfirm(false);
        }}
        title={`Approve ${count} ${label}?`}
        description={
          <>
            All {count}&nbsp;go onto their boards, start counting toward their
            assignees&apos; workloads, and become visible to their whole
            projects. Their authors are notified.
          </>
        }
        confirmLabel={`Approve ${count}`}
        destructive={false}
        isPending={isPending}
      />
    </div>
  );
}

/**
 * Approve / Reject buttons plus their dialogs, shared by both tabs.
 *
 * Reject is offered only while the row is still pending: one already sent back
 * cannot be rejected again — the next move is the author's, who revises and
 * re-submits. Approve stays available so a reviewer can let a rejected item
 * through after editing it themselves.
 */
function ReviewActions({
  subject,
  state,
  isPending,
  onApprove,
  onReject,
  rejectError,
  onEdit,
}: {
  subject: string;
  state: ApprovalStatus;
  isPending: boolean;
  onApprove: () => void;
  onReject: (reason: string) => void;
  rejectError?: string | null;
  onEdit: () => void;
}) {
  const [confirmApprove, setConfirmApprove] = useState(false);
  const [confirmReject, setConfirmReject] = useState(false);

  return (
    <div className="flex items-center justify-center gap-2">
      <Button size="xs" variant="outline" onClick={onEdit}>
        Edit
      </Button>
      <Button
        size="xs"
        disabled={isPending}
        onClick={() => setConfirmApprove(true)}
        className="rounded-lg border border-line bg-brand px-3 py-1.5 text-xs font-bold text-ink hover:bg-brand-hover"
      >
        Approve
      </Button>
      {state === "pending" && (
        <Button
          size="xs"
          variant="outline"
          disabled={isPending}
          onClick={() => setConfirmReject(true)}
          className="text-danger"
        >
          Reject
        </Button>
      )}

      <ConfirmDialog
        open={confirmApprove}
        onClose={() => setConfirmApprove(false)}
        onConfirm={() => {
          onApprove();
          setConfirmApprove(false);
        }}
        title="Approve this submission?"
        description={
          <>
            <span className="font-semibold text-ink">{subject}</span>&nbsp;goes onto
            the board, starts counting toward its assignee&apos;s workload, and
            becomes visible to the whole project.
          </>
        }
        confirmLabel="Approve"
        destructive={false}
        isPending={isPending}
      />

      <RejectReasonDialog
        open={confirmReject}
        subject={subject}
        isPending={isPending}
        error={rejectError}
        onCancel={() => setConfirmReject(false)}
        onConfirm={(reason) => {
          onReject(reason);
          setConfirmReject(false);
        }}
      />
    </div>
  );
}

function PendingTasksList({ query }: { query: AdminQuery }) {
  const result = usePendingTasks(query.params);
  const approve = useApproveTask();
  const reject = useRejectTask();
  const bulkApprove = useBulkApproveTasks();
  const openTask = useOpenTask();

  const rows = result.data?.data ?? [];
  const { selected, toggle, toggleMany, clear } = useRowSelection(
    JSON.stringify(query.params),
  );
  const selection: AdminSelection<PendingTask> = {
    selected,
    onToggle: toggle,
    onToggleAll: (select) =>
      toggleMany(
        rows.map((r) => r.id),
        select,
      ),
    itemLabel: "task",
  };

  const columns: AdminColumn<PendingTask>[] = [
    {
      key: "title",
      label: "Task",
      className: "w-[240px]",
      render: (t) => (
        <div>
          <span className="font-semibold text-ink">{t.title}</span>
          {t.approval_status === "rejected" && t.rejection_reason && (
            <p className="mt-0.5 line-clamp-1 text-xs text-ink-soft">
              {t.rejection_reason}
            </p>
          )}
        </div>
      ),
    },
    {
      key: "project",
      label: "Project",
      render: (t) => t.project_name ?? "—",
    },
    {
      key: "submitted_by",
      label: "Submitted By",
      className: "w-[160px]",
      render: (t) => t.submitted_by?.name ?? "—",
    },
    {
      key: "state",
      label: "State",
      className: "w-[110px]",
      render: (t) => <ReviewStateBadge state={t.approval_status} />,
    },
    {
      key: "priority",
      label: "Priority",
      className: "w-[110px]",
      render: (t) => <PriorityBadge priority={t.priority} />,
    },
    {
      key: "subtasks",
      label: "Sub-tasks",
      className: "w-[100px] text-center",
      render: (t) => t.subtasks_count ?? 0,
    },
    {
      key: "deadline",
      label: "Due",
      className: "w-[130px]",
      render: (t) => fmtDate(t.deadline),
    },
    {
      key: "created_at",
      label: "Submitted",
      className: "w-[130px]",
      render: (t) => fmtDate(t.created_at),
    },
  ];

  return (
    <>
      <BulkBar
        count={selected.size}
        noun="task"
        isPending={bulkApprove.isPending}
        outcome={bulkApprove.data ?? null}
        onClear={clear}
        onApprove={() =>
          bulkApprove.mutate([...selected], { onSuccess: () => clear() })
        }
      />
      {bulkApprove.isError && (
        <p className="mb-3 text-sm text-danger">
          Could not approve the selection. Please try again.
        </p>
      )}
      <AdminTable
        label="submissions"
        columns={columns}
        query={query}
        result={result}
        selection={selection}
        summaryVerb="submitted"
        actionColumnClassName="w-[230px]"
        actionLabel="Review"
        emptyMessage="Nothing to review. Tasks members submit will appear here."
        searchEmptyMessage="No submitted tasks match your search."
        actions={(row) => (
          <ReviewActions
            subject={row.title}
            state={row.approval_status}
            isPending={approve.isPending || reject.isPending}
            rejectError={
              reject.isError ? "Could not reject. Please try again." : null
            }
            onEdit={() => openTask(row.id)}
            onApprove={() => approve.mutate(row.id)}
            onReject={(reason) => reject.mutate({ id: row.id, reason })}
          />
        )}
      />
    </>
  );
}

function PendingSubtasksList({ query }: { query: AdminQuery }) {
  const result = usePendingSubtasks(query.params);
  const approve = useApproveSubtask();
  const reject = useRejectSubtask();
  const bulkApprove = useBulkApproveSubtasks();
  const openTask = useOpenTask();

  const rows = result.data?.data ?? [];
  const { selected, toggle, toggleMany, clear } = useRowSelection(
    JSON.stringify(query.params),
  );
  const selection: AdminSelection<PendingSubtask> = {
    selected,
    onToggle: toggle,
    onToggleAll: (select) =>
      toggleMany(
        rows.map((r) => r.id),
        select,
      ),
    itemLabel: "sub-task",
  };

  const columns: AdminColumn<PendingSubtask>[] = [
    {
      key: "name",
      label: "Sub-task",
      className: "w-[240px]",
      render: (s) => (
        <div>
          <span className="font-semibold text-ink">{s.name}</span>
          {s.approval_status === "rejected" && s.rejection_reason && (
            <p className="mt-0.5 line-clamp-1 text-xs text-ink-soft">
              {s.rejection_reason}
            </p>
          )}
        </div>
      ),
    },
    {
      key: "task",
      label: "Parent Task",
      render: (s) => s.task_name ?? "—",
    },
    {
      key: "project",
      label: "Project",
      className: "w-[160px]",
      render: (s) => s.project_name ?? "—",
    },
    {
      key: "submitted_by",
      label: "Submitted By",
      className: "w-[160px]",
      render: (s) => s.submitted_by?.name ?? "—",
    },
    {
      key: "state",
      label: "State",
      className: "w-[110px]",
      render: (s) => <ReviewStateBadge state={s.approval_status} />,
    },
    {
      key: "priority",
      label: "Priority",
      className: "w-[110px]",
      render: (s) => <PriorityBadge priority={s.priority} />,
    },
    {
      key: "created_at",
      label: "Submitted",
      className: "w-[130px]",
      render: (s) => fmtDate(s.created_at),
    },
  ];

  return (
    <>
      <BulkBar
        count={selected.size}
        noun="sub-task"
        isPending={bulkApprove.isPending}
        outcome={bulkApprove.data ?? null}
        onClear={clear}
        onApprove={() =>
          bulkApprove.mutate([...selected], { onSuccess: () => clear() })
        }
      />
      {bulkApprove.isError && (
        <p className="mb-3 text-sm text-danger">
          Could not approve the selection. Please try again.
        </p>
      )}
      <AdminTable
        label="submissions"
        columns={columns}
        query={query}
        result={result}
        selection={selection}
        summaryVerb="submitted"
        actionColumnClassName="w-[230px]"
        actionLabel="Review"
        emptyMessage="Nothing to review. Sub-tasks members submit will appear here."
        searchEmptyMessage="No submitted sub-tasks match your search."
        actions={(row) => (
          <ReviewActions
            subject={row.name}
            state={row.approval_status}
            isPending={approve.isPending || reject.isPending}
            rejectError={
              reject.isError ? "Could not reject. Please try again." : null
            }
            // A sub-task is reviewed inside its parent task's modal.
            onEdit={() => row.task_id && openTask(row.task_id)}
            onApprove={() => approve.mutate(row.id)}
            onReject={(reason) => reject.mutate({ id: row.id, reason })}
          />
        )}
      />
    </>
  );
}
