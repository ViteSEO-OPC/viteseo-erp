"use client";

import { AlertTriangle, CornerDownRight } from "lucide-react";
import { PriorityBadge, Select } from "@/core/ui";
import { cn } from "@/core/lib/utils";
import type {
  ImportMode,
  ImportOptions,
  ImportPreviewSubtask,
  ImportPreviewTask,
  UnresolvedField,
} from "@/modules/projects/types/task-import";

/**
 * The parsed plan, as a tree the PM reads top to bottom.
 *
 * A list rather than a table on purpose: a task owns its sub-tasks, and the
 * board's own List view renders the same shape. Only rows that need something
 * expose editors — a clean plan should read as a plan, not a form.
 */
export interface ImportPreviewTreeProps {
  tasks: ImportPreviewTask[];
  options: ImportOptions;
  /**
   * On the `proposal` path every row is the author's own work by construction, so
   * the tree shows who it belongs to but offers no way to change it — matching the
   * server, which re-forces the assignment on commit regardless of what is sent.
   */
  mode: ImportMode;
  onPatchTask: (ref: string, patch: Partial<ImportPreviewTask>) => void;
  onPatchSubtask: (ref: string, patch: Partial<ImportPreviewSubtask>) => void;
}

/** A row is blocked while anything is unresolved or invalid. */
export function rowIsBlocked(
  row: ImportPreviewTask | ImportPreviewSubtask,
): boolean {
  return row.errors.length > 0 || row.unresolved_fields.length > 0;
}

export function ImportPreviewTree({
  tasks,
  options,
  mode,
  onPatchTask,
  onPatchSubtask,
}: ImportPreviewTreeProps) {
  return (
    <ul className="divide-y divide-line rounded-lg border border-line">
      {tasks.map((task) => (
        <li key={task.ref}>
          <TaskRow task={task} options={options} mode={mode} onPatch={onPatchTask} />
          {task.subtasks.length > 0 && (
            <ul className="border-t border-line/60 bg-canvas">
              {task.subtasks.map((subtask) => (
                <li key={subtask.ref} className="border-b border-line/40 last:border-b-0">
                  <SubtaskRow
                    subtask={subtask}
                    options={options}
                    mode={mode}
                    onPatch={onPatchSubtask}
                  />
                </li>
              ))}
            </ul>
          )}
        </li>
      ))}
    </ul>
  );
}

function TaskRow({
  task,
  options,
  mode,
  onPatch,
}: {
  task: ImportPreviewTask;
  options: ImportOptions;
  mode: ImportMode;
  onPatch: (ref: string, patch: Partial<ImportPreviewTask>) => void;
}) {
  const isProposal = mode === "proposal";
  const blocked = rowIsBlocked(task);
  const needs = (field: UnresolvedField) =>
    task.unresolved_fields.includes(field);

  /**
   * Assignment is one control, not two. The file can name both an assignee and
   * a group (which is invalid) or neither, and a single picker makes every one
   * of those states reachable and mutually exclusive by construction.
   */
  const assignmentValue =
    task.assignment_type === "group"
      ? task.group_id
        ? `group:${task.group_id}`
        : ""
      : task.assignee_id != null
        ? `user:${task.assignee_id}`
        : "unassigned";

  return (
    <div className={cn("px-3 py-2.5", blocked && "bg-warning-subtle/40")}>
      <div className="flex flex-wrap items-center gap-x-3 gap-y-1.5">
        <span className="min-w-0 flex-1 truncate text-sm font-semibold text-ink">
          {task.title || (
            <span className="font-normal text-danger italic">Untitled task</span>
          )}
        </span>

        {task.priority && (
          <PriorityBadge priority={task.priority} />
        )}
        {task.status_id && !needs("status_id") && (
          <span className="text-xs font-medium text-ink-soft">
            {labelFor(options.statuses, task.status_id)}
          </span>
        )}
        {task.deadline && (
          <span className="text-xs text-ink-faint">due {task.deadline}</span>
        )}
        {task.effort_score != null && (
          <span className="text-xs text-ink-faint">effort {task.effort_score}</span>
        )}
        {!blocked && (
          <span className="text-xs text-ink-soft">
            {isProposal ? "You" : assignmentLabel(task, options)}
          </span>
        )}
      </div>

      {blocked && (
        <div className="mt-2 space-y-2">
          <Problems errors={task.errors} />

          <div className="flex flex-wrap gap-2">
            {needs("status_id") && (
              <Editor label="Status">
                <Select
                  value={task.status_id ?? ""}
                  onChange={(e) =>
                    onPatch(task.ref, { status_id: e.target.value || null })
                  }
                >
                  <option value="">Pick a status…</option>
                  {options.statuses.map((o) => (
                    <option key={String(o.value)} value={String(o.value)}>
                      {o.label}
                    </option>
                  ))}
                </Select>
              </Editor>
            )}

            {needs("priority") && (
              <Editor label="Priority">
                <Select
                  value={task.priority ?? ""}
                  onChange={(e) =>
                    onPatch(task.ref, {
                      priority: (e.target.value || null) as ImportPreviewTask["priority"],
                    })
                  }
                >
                  <option value="">Pick a priority…</option>
                  {options.priorities.map((o) => (
                    <option key={String(o.value)} value={String(o.value)}>
                      {o.label}
                    </option>
                  ))}
                </Select>
              </Editor>
            )}

            {!isProposal &&
              (needs("assignee_id") ||
                needs("group_id") ||
                task.errors.some((e) => e.includes("both an assignee"))) && (
              <Editor label="Assigned to">
                <Select
                  value={assignmentValue}
                  onChange={(e) =>
                    onPatch(task.ref, assignmentPatch(e.target.value))
                  }
                >
                  <option value="">Pick one…</option>
                  <option value="unassigned">Unassigned (open pool)</option>
                  {options.members.map((o) => (
                    <option key={`u${o.value}`} value={`user:${o.value}`}>
                      {o.label}
                    </option>
                  ))}
                  {options.groups.map((o) => (
                    <option key={`g${o.value}`} value={`group:${o.value}`}>
                      {o.label} (group)
                    </option>
                  ))}
                </Select>
              </Editor>
            )}

            {task.errors.some((e) => e.includes("due date")) && (
              <>
                <Editor label="Start date">
                  <DateInput
                    value={task.start_date}
                    onChange={(v) => onPatch(task.ref, { start_date: v })}
                  />
                </Editor>
                <Editor label="Due date">
                  <DateInput
                    value={task.deadline}
                    onChange={(v) => onPatch(task.ref, { deadline: v })}
                  />
                </Editor>
              </>
            )}

            {(needs("effort_score") ||
              task.errors.some((e) => e.includes("effort score"))) && (
              <Editor label="Effort">
                <EffortInput
                  value={task.effort_score}
                  onChange={(v) => onPatch(task.ref, { effort_score: v })}
                />
              </Editor>
            )}

            {task.errors.some((e) => e.includes("title")) && (
              <Editor label="Title" wide>
                <input
                  className="h-9 w-full rounded-md border border-input bg-transparent px-3 text-sm"
                  value={task.title ?? ""}
                  placeholder="Give this task a title"
                  onChange={(e) =>
                    onPatch(task.ref, { title: e.target.value || null })
                  }
                />
              </Editor>
            )}
          </div>
        </div>
      )}
    </div>
  );
}

function SubtaskRow({
  subtask,
  options,
  mode,
  onPatch,
}: {
  subtask: ImportPreviewSubtask;
  options: ImportOptions;
  mode: ImportMode;
  onPatch: (ref: string, patch: Partial<ImportPreviewSubtask>) => void;
}) {
  const isProposal = mode === "proposal";
  const blocked = rowIsBlocked(subtask);
  const needs = (field: UnresolvedField) =>
    subtask.unresolved_fields.includes(field);

  return (
    <div className={cn("py-2 pr-3 pl-8", blocked && "bg-warning-subtle/40")}>
      <div className="flex flex-wrap items-center gap-x-3 gap-y-1.5">
        <CornerDownRight className="h-3.5 w-3.5 shrink-0 text-ink-faint" />
        <span className="min-w-0 flex-1 truncate text-sm text-ink">
          {subtask.name || (
            <span className="text-danger italic">Untitled sub-task</span>
          )}
        </span>
        {subtask.priority && (
          <PriorityBadge priority={subtask.priority} />
        )}
        {subtask.status_id && !needs("status_id") && (
          <span className="text-xs font-medium text-ink-soft">
            {labelFor(options.statuses, subtask.status_id)}
          </span>
        )}
        {subtask.deadline && (
          <span className="text-xs text-ink-faint">due {subtask.deadline}</span>
        )}
        {subtask.effort_score != null && (
          <span className="text-xs text-ink-faint">
            effort {subtask.effort_score}
          </span>
        )}
        {subtask.assignee_id != null && !needs("assignee_id") && (
          <span className="text-xs text-ink-soft">
            {isProposal ? "You" : labelFor(options.members, subtask.assignee_id)}
          </span>
        )}
      </div>

      {blocked && (
        <div className="mt-2 space-y-2 pl-6">
          <Problems errors={subtask.errors} />

          <div className="flex flex-wrap gap-2">
            {needs("status_id") && (
              <Editor label="Status">
                <Select
                  value={subtask.status_id ?? ""}
                  onChange={(e) =>
                    onPatch(subtask.ref, { status_id: e.target.value || null })
                  }
                >
                  <option value="">Pick a status…</option>
                  {options.statuses.map((o) => (
                    <option key={String(o.value)} value={String(o.value)}>
                      {o.label}
                    </option>
                  ))}
                </Select>
              </Editor>
            )}

            {needs("priority") && (
              <Editor label="Priority">
                <Select
                  value={subtask.priority ?? ""}
                  onChange={(e) =>
                    onPatch(subtask.ref, {
                      priority: (e.target.value ||
                        null) as ImportPreviewSubtask["priority"],
                    })
                  }
                >
                  <option value="">Pick a priority…</option>
                  {options.priorities.map((o) => (
                    <option key={String(o.value)} value={String(o.value)}>
                      {o.label}
                    </option>
                  ))}
                </Select>
              </Editor>
            )}

            {!isProposal && needs("assignee_id") && (
              <Editor label="Assignee">
                <Select
                  value={subtask.assignee_id != null ? String(subtask.assignee_id) : ""}
                  onChange={(e) =>
                    onPatch(subtask.ref, {
                      assignee_id: e.target.value ? Number(e.target.value) : null,
                    })
                  }
                >
                  <option value="">Unassigned</option>
                  {options.members.map((o) => (
                    <option key={String(o.value)} value={String(o.value)}>
                      {o.label}
                    </option>
                  ))}
                </Select>
              </Editor>
            )}

            {(needs("effort_score") ||
              subtask.errors.some((e) => e.includes("effort score"))) && (
              <Editor label="Effort">
                <EffortInput
                  value={subtask.effort_score}
                  onChange={(v) => onPatch(subtask.ref, { effort_score: v })}
                />
              </Editor>
            )}

            {subtask.errors.some((e) => e.includes("due date")) && (
              <Editor label="Due date">
                <DateInput
                  value={subtask.deadline}
                  onChange={(v) => onPatch(subtask.ref, { deadline: v })}
                />
              </Editor>
            )}

            {subtask.errors.some((e) => e.includes("name")) && (
              <Editor label="Name" wide>
                <input
                  className="h-9 w-full rounded-md border border-input bg-transparent px-3 text-sm"
                  value={subtask.name ?? ""}
                  placeholder="Give this sub-task a name"
                  onChange={(e) =>
                    onPatch(subtask.ref, { name: e.target.value || null })
                  }
                />
              </Editor>
            )}
          </div>
        </div>
      )}
    </div>
  );
}

function Problems({ errors }: { errors: string[] }) {
  if (errors.length === 0) return null;
  return (
    <ul className="space-y-1">
      {errors.map((error) => (
        <li
          key={error}
          className="flex items-start gap-1.5 text-xs font-medium text-warning"
        >
          <AlertTriangle className="mt-px h-3.5 w-3.5 shrink-0" />
          <span>{error}</span>
        </li>
      ))}
    </ul>
  );
}

function Editor({
  label,
  wide,
  children,
}: {
  label: string;
  wide?: boolean;
  children: React.ReactNode;
}) {
  return (
    <label className={cn("block", wide ? "w-full" : "w-44")}>
      <span className="mb-1 block text-[11px] font-semibold tracking-wide text-ink-faint uppercase">
        {label}
      </span>
      {children}
    </label>
  );
}

function DateInput({
  value,
  onChange,
}: {
  value: string | null;
  onChange: (value: string | null) => void;
}) {
  return (
    <input
      type="date"
      className="h-9 w-full rounded-md border border-input bg-transparent px-3 text-sm"
      value={value ?? ""}
      onChange={(e) => onChange(e.target.value || null)}
    />
  );
}

function EffortInput({
  value,
  onChange,
}: {
  value: number | null;
  onChange: (value: number | null) => void;
}) {
  return (
    <input
      type="number"
      min={0}
      max={10}
      className="h-9 w-full rounded-md border border-input bg-transparent px-3 text-sm"
      value={value ?? ""}
      placeholder="0-10"
      onChange={(e) =>
        onChange(e.target.value === "" ? null : Number(e.target.value))
      }
    />
  );
}

/** Turn the single assignment picker's value back into the three fields it drives. */
function assignmentPatch(value: string): Partial<ImportPreviewTask> {
  if (value.startsWith("user:")) {
    return {
      assignment_type: "individual",
      assignee_id: Number(value.slice(5)),
      group_id: null,
    };
  }
  if (value.startsWith("group:")) {
    return {
      assignment_type: "group",
      group_id: value.slice(6),
      assignee_id: null,
    };
  }
  if (value === "unassigned") {
    return { assignment_type: "individual", assignee_id: null, group_id: null };
  }
  return {};
}

function assignmentLabel(
  task: ImportPreviewTask,
  options: ImportOptions,
): string | null {
  if (task.assignment_type === "group" && task.group_id) {
    return `${labelFor(options.groups, task.group_id)} (group)`;
  }
  if (task.assignee_id != null) {
    return labelFor(options.members, task.assignee_id);
  }
  return "Unassigned";
}

function labelFor(
  options: ImportOptions[keyof ImportOptions],
  value: string | number,
): string {
  return (
    options.find((o) => String(o.value) === String(value))?.label ??
    String(value)
  );
}

