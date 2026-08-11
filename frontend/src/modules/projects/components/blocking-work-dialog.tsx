"use client";

import { Suspense, useState } from "react";
import { useSearchParams } from "next/navigation";
import { Button, Modal } from "@/core/ui";
import { TASK_PARAM, useOpenTask } from "@/modules/projects/hooks/use-task-modal";
import type { BlockingWork } from "@/modules/projects/types/project";

export interface BlockingWorkDialogProps {
  work: BlockingWork | null;
  onClose: () => void;
  /** Re-attempt the removal once the work has been reassigned. */
  onRetry?: (userId: number) => void | Promise<void>;
}

/**
 * Why a member could not be removed — and the way to fix it.
 *
 * Removing someone who still owns work would leave it assigned to a non-member:
 * off their board, still in their workload, and out of reach of the open-task
 * pool (which only lists work with NO assignee). So the server refuses and
 * returns the list; every row here opens the task so the PM can reassign it —
 * or release it back to the pool — without going looking for it.
 *
 * The list SURVIVES that trip. A PM with four items to hand over should not have
 * to re-trigger the removal to see the rest of them, so the work stays in state
 * and this only steps out of the way while a task is on screen (see below).
 */
function BlockingWorkDialogInner({
  work,
  onClose,
  onRetry,
}: BlockingWorkDialogProps): React.ReactElement | null {
  const searchParams = useSearchParams();
  const openTask = useOpenTask();
  const [retrying, setRetrying] = useState(false);

  // The task modal is a plain fixed overlay rather than a portal, so this Radix
  // dialog would paint on top of it. Hiding while `?task=` is set keeps one
  // modal on screen at a time — and brings the list straight back when the task
  // is closed, because `work` never left state.
  const taskIsOpen = searchParams.get(TASK_PARAM) !== null;

  if (!work || taskIsOpen) return null;

  const total = work.tasks.length + work.subtasks.length;

  const retry = async () => {
    if (!onRetry) return;
    setRetrying(true);
    try {
      await onRetry(work.member.id);
    } finally {
      setRetrying(false);
    }
  };

  const rowClass =
    "flex w-full items-center justify-between gap-3 rounded-lg px-3 py-2 text-left transition-colors hover:bg-black/[0.03]";

  return (
    <Modal
      open
      onClose={onClose}
      title={`${work.member.name} still has work here`}
      width={560}
      footer={
        <div className="flex justify-end gap-3">
          <Button variant="outline" onClick={onClose} disabled={retrying}>
            Close
          </Button>
          {onRetry && (
            <Button onClick={retry} disabled={retrying}>
              {retrying ? "Checking…" : "Try removing again"}
            </Button>
          )}
        </div>
      }
    >
      <p className="text-sm text-ink-soft">
        {total === 1 ? "This item is" : `These ${total} items are`} still
        assigned to them. Reassign each one — or put it back on the open tasks
        list — then try the removal again. Click a row to open it.
      </p>

      {work.tasks.length > 0 && (
        <div className="mt-4">
          <p className="text-xs font-bold uppercase tracking-wide text-ink-soft">
            Tasks ({work.tasks.length})
          </p>
          <div className="mt-1 divide-y divide-line">
            {work.tasks.map((task) => (
              <button
                key={task.id}
                type="button"
                onClick={() => openTask(task.id)}
                className={rowClass}
              >
                <span className="min-w-0 flex-1 truncate font-semibold text-ink">
                  {task.title}
                </span>
                <span className="shrink-0 text-xs text-ink-faint">
                  {task.status ?? "No status"}
                </span>
              </button>
            ))}
          </div>
        </div>
      )}

      {work.subtasks.length > 0 && (
        <div className="mt-4">
          <p className="text-xs font-bold uppercase tracking-wide text-ink-soft">
            Sub-tasks ({work.subtasks.length})
          </p>
          <div className="mt-1 divide-y divide-line">
            {work.subtasks.map((subtask) => (
              <button
                key={subtask.id}
                type="button"
                // A sub-task is reassigned through its parent task's modal.
                onClick={() => subtask.task_id && openTask(subtask.task_id)}
                disabled={!subtask.task_id}
                className={rowClass}
              >
                <span className="min-w-0 flex-1">
                  <span className="block truncate font-semibold text-ink">
                    {subtask.name}
                  </span>
                  {subtask.task_title && (
                    <span className="block truncate text-xs text-ink-faint">
                      in {subtask.task_title}
                    </span>
                  )}
                </span>
                <span className="shrink-0 text-xs text-ink-faint">
                  {subtask.status ?? "No status"}
                </span>
              </button>
            ))}
          </div>
        </div>
      )}
    </Modal>
  );
}

export function BlockingWorkDialog(
  props: BlockingWorkDialogProps,
): React.ReactElement {
  // useSearchParams requires a Suspense boundary during static rendering.
  return (
    <Suspense fallback={null}>
      <BlockingWorkDialogInner {...props} />
    </Suspense>
  );
}
