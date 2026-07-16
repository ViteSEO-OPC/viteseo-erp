'use client';

import { useMemo } from 'react';
import type { ProjectStatus, Task } from '../../types';
import { useProject } from '../../hooks';
import { ModalShell } from './shared';
import { CreateTaskBody } from './CreateTaskBody';
import { ViewTaskBody } from './ViewTaskBody';

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
