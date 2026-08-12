"use client";

import { useMemo } from "react";
import type { ProjectStatus } from "@/modules/projects/types/project";
import type { Task } from "@/modules/projects/types/task";
import { useProject } from "@/modules/projects/hooks/use-projects";
import { ModalShell } from "@/modules/projects/components/task-shared";
import { CreateTaskBody } from "@/modules/projects/components/create-task-body";
import { ViewTaskBody } from "@/modules/projects/components/view-task-body";

export interface TaskModalProps {
  open: boolean;
  onClose: () => void;
  projectId: string;
  statuses: ProjectStatus[];
  mode: 'create' | 'view';
  task?: Task;
  /** Optional control rendered at the top of the create form (e.g. a project picker). */
  projectSelector?: React.ReactNode;
}

export function TaskModal({ open, onClose, projectId, statuses, mode, task, projectSelector }: TaskModalProps) {
  const projectQuery = useProject(projectId);
  const projectName = projectQuery.data?.name ?? 'Project';

  // Force a fresh form/state per open by keying the body (also re-keys when the
  // create-mode project changes, so the status default resets).
  const bodyKey = useMemo(
    () => `${mode}-${task?.id ?? 'new'}-${projectId}-${open ? 'open' : 'closed'}`,
    [mode, task?.id, projectId, open],
  );

  return (
    <ModalShell open={open} onClose={onClose} projectName={projectName}>
      {mode === 'view' && task ? (
        <ViewTaskBody key={bodyKey} projectId={projectId} statuses={statuses} task={task} onClose={onClose} />
      ) : (
        <CreateTaskBody
          key={bodyKey}
          projectId={projectId}
          statuses={statuses}
          onClose={onClose}
          projectSelector={projectSelector}
        />
      )}
    </ModalShell>
  );
}
