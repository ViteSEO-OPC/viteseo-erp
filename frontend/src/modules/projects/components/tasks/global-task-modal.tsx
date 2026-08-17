"use client";

import { Suspense } from "react";
import { usePathname, useRouter, useSearchParams } from "next/navigation";
import { useStatuses } from "@/modules/projects/hooks/use-statuses";
import { useTask } from "@/modules/projects/hooks/use-tasks";
import { TaskModal } from "@/modules/projects/components/tasks/task-modal";
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

/**
 * Fetches the task (+ its project's statuses) and renders the view modal.
 *
 * One `<TaskModal>` for every state — loading, failed, loaded. It used to
 * return a hand-built overlay while fetching and switch to `<TaskModal>` on
 * arrival, which is a different element type in the same slot: React tore the
 * first dialog down and mounted a second, so the open animation played twice
 * and the panel jumped from placeholder size to full size. Keeping the element
 * type stable means the shell mounts once, animates once, and only its body
 * changes underneath.
 */
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

  return (
    <TaskModal
      open
      onClose={onClose}
      // Empty until the task names its project; `useProject` stays disabled and
      // the header shows its placeholder pill until then.
      projectId={task?.project_id ?? ""}
      statuses={statuses}
      mode="view"
      task={task}
      loading={!task && !taskQuery.isError}
      loadError={taskQuery.isError ? "Couldn't load this task." : undefined}
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
