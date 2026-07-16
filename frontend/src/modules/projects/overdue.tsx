import * as React from 'react';

/**
 * A task/subtask is overdue when its deadline has passed (date-only comparison,
 * so a task due today is not yet overdue) and it isn't in a "done" status.
 */
export function isOverdue(
  deadline: string | null | undefined,
  statusKey?: string | null,
): boolean {
  if (!deadline || statusKey === 'done') return false;
  const due = new Date(deadline);
  if (Number.isNaN(due.getTime())) return false;
  due.setHours(0, 0, 0, 0);
  const today = new Date();
  today.setHours(0, 0, 0, 0);
  return due.getTime() < today.getTime();
}

/** Small red "Overdue" chip shown next to a past-due task's deadline. */
export function OverdueBadge({ className = '' }: { className?: string }) {
  return (
    <span
      className={`inline-flex items-center gap-1 rounded-full bg-danger-soft px-2 py-0.5 text-[11px] font-bold text-danger ${className}`}
    >
      <span aria-hidden>⚠</span> Overdue
    </span>
  );
}
