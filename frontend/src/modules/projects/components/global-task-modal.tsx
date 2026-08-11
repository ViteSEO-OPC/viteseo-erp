"use client";

import { Suspense } from "react";
import { usePathname, useRouter, useSearchParams } from "next/navigation";
import { useStatuses } from "@/modules/projects/hooks/use-statuses";
import { useTask } from "@/modules/projects/hooks/use-tasks";
import { TaskModal } from "@/modules/projects/components/task-modal";
import { TASK_PARAM } from "@/modules/projects/hooks/use-task-modal";

/**
 * App-wide task-view modal. Mounted once (in the projects layout) so any page
 * can open a task simply by putting `?task={uuid}` on the URL — via
 * {@link useOpenTask}, a plain link, or a pasted/bookmarked deep-link. When the
 * param is absent it renders nothing.
 */
function GlobalTaskModalInner(): React.ReactElement | null {
  const searchParams = useSearchParams();
  const router = useRouter();
  const pathname = usePathname();
  const taskId = searchParams.get(TASK_PARAM);

  const close = (): void => {
    const params = new URLSearchParams(searchParams.toString());
    params.delete(TASK_PARAM);
    const qs = params.toString();
    router.push(qs ? `${pathname}?${qs}` : pathname, { scroll: false });
  };

  if (!taskId) return null;
  return <TaskModalLoader taskId={taskId} onClose={close} />;
}

/** Fetches the task (+ its project's statuses) and renders the view modal. */
function TaskModalLoader({
  taskId,
  onClose,
}: {
  taskId: string;
  onClose: () => void;
}): React.ReactElement {
  const taskQuery = useTask(taskId);
  const task = taskQuery.data;
  const statusesQuery = useStatuses(task?.project_id ?? "");
  const statuses = statusesQuery.data ?? [];

  if (!task) {
    return (
      <div className="fixed inset-0 z-50 flex items-center justify-center p-4">
        <div
          className="absolute inset-0 bg-black/50"
          onClick={onClose}
          aria-hidden
        />
        <div className="relative z-10 rounded-2xl bg-surface px-8 py-6 text-sm font-semibold text-ink-soft shadow-xl">
          {taskQuery.isError ? "Couldn't load this task." : "Loading task…"}
        </div>
      </div>
    );
  }

  return (
    <TaskModal
      open
      onClose={onClose}
      projectId={task.project_id}
      statuses={statuses}
      mode="view"
      task={task}
    />
  );
}

export function GlobalTaskModal(): React.ReactElement {
  // useSearchParams requires a Suspense boundary during static rendering.
  return (
    <Suspense fallback={null}>
      <GlobalTaskModalInner />
    </Suspense>
  );
}
