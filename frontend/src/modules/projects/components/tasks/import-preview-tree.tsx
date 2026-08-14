"use client";

import { AlertTriangle, CornerDownRight } from "lucide-react";
import { PriorityBadge, Select } from "@/core/ui";
import {
  TABLE_FRAME,
  TABLE_HEAD,
  TABLE_HEAD_TEXT,
} from "@/modules/projects/components/shared/data-table";
import { StatusPill } from "@/modules/projects/components/board/list-view";
import { cn } from "@/core/lib/utils";
import type {
  ImportMode,
  ImportOptions,
  ImportStatusOption,
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

/**
 * One grid, used by the header, task rows and sub-task rows alike — which is the
 * only way the columns actually line up.
 *
 * It engages at `md`, not `sm`: the fixed columns need ~545px before the title
 * has room left, and below that the row falls back to wrapping chips (the shape
 * the phone layout already uses).
 */
const ROW_GRID =
  "md:grid md:grid-cols-[minmax(0,1fr)_80px_120px_92px_52px_116px] md:items-center";

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
    <div className={cn(TABLE_FRAME, "overflow-hidden")}>
      {/* Column headers earn their place only once the grid is on; below md the
          rows wrap and each value carries its own inline label instead. The
          padding is tighter than the module's tables: this one lives inside a
          modal beside a form, not on a page of its own. */}
      <div
        className={cn(
          "hidden gap-x-3 px-3 py-2",
          TABLE_HEAD,
          TABLE_HEAD_TEXT,
          ROW_GRID,
        )}
      >
        <span>Task</span>
        <span>Priority</span>
        <span>Status</span>
        <span>Due</span>
        <span>Effort</span>
        <span>Assigned to</span>
      </div>

      <ul className="divide-y divide-line">
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
    </div>
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
      <div className={cn("flex flex-wrap items-center gap-x-3 gap-y-1.5", ROW_GRID)}>
        <span className="w-full min-w-0 truncate text-sm font-semibold text-ink md:w-auto">
          {task.title || (
            <span className="font-normal text-danger italic">Untitled task</span>
          )}
        </span>

        <Cell empty={!task.priority}>
          {task.priority && <PriorityBadge priority={task.priority} />}
        </Cell>
        <Cell empty={!task.status_id || needs("status_id")}>
          {task.status_id && !needs("status_id") && (
            <StatusPillFor id={task.status_id} options={options} />
          )}
        </Cell>
        <Cell empty={!task.deadline} className="text-xs text-ink-faint">
          {task.deadline && (
            <>
              <span className="md:hidden">due </span>
              {task.deadline}
            </>
          )}
        </Cell>
        <Cell empty={task.effort_score == null} className="text-xs text-ink-faint">
          {task.effort_score != null && (
            <>
              <span className="md:hidden">effort </span>
              {task.effort_score}
            </>
          )}
        </Cell>
        <Cell empty={blocked} className="truncate text-xs text-ink-soft">
          {!blocked && (isProposal ? "You" : assignmentLabel(task, options))}
        </Cell>
      </div>

      {blocked && (
        <div className="mt-2 space-y-2">
          <Problems errors={task.errors} />

          <div className="flex flex-wrap gap-2">
            {needs("status_id") && (
              <Editor label="Status">
                <Select
                  className="h-10 sm:h-9"
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
                  className="h-10 sm:h-9"
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
                  className="h-10 sm:h-9"
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
                  className="h-10 w-full rounded-md border border-input bg-transparent px-3 text-sm sm:h-9"
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
    // Same horizontal padding as a task row: the nesting is expressed inside the
    // title cell, so a sub-task's other columns stay flush with its parent's.
    <div className={cn("px-3 py-2", blocked && "bg-warning-subtle/40")}>
      <div className={cn("flex flex-wrap items-center gap-x-3 gap-y-1.5", ROW_GRID)}>
        <span className="flex w-full min-w-0 items-center gap-2 pl-2 md:w-auto md:pl-5">
          <CornerDownRight className="h-4 w-4 shrink-0 text-ink-faint" />
          <span className="min-w-0 truncate text-sm text-ink">
            {subtask.name || (
              <span className="text-danger italic">Untitled sub-task</span>
            )}
          </span>
        </span>
        <Cell empty={!subtask.priority}>
          {subtask.priority && <PriorityBadge priority={subtask.priority} />}
        </Cell>
        <Cell empty={!subtask.status_id || needs("status_id")}>
          {subtask.status_id && !needs("status_id") && (
            <StatusPillFor id={subtask.status_id} options={options} />
          )}
        </Cell>
        <Cell empty={!subtask.deadline} className="text-xs text-ink-faint">
          {subtask.deadline && (
            <>
              <span className="md:hidden">due </span>
              {subtask.deadline}
            </>
          )}
        </Cell>
        <Cell empty={subtask.effort_score == null} className="text-xs text-ink-faint">
          {subtask.effort_score != null && (
            <>
              <span className="md:hidden">effort </span>
              {subtask.effort_score}
            </>
          )}
        </Cell>
        <Cell
          empty={subtask.assignee_id == null || needs("assignee_id")}
          className="truncate text-xs text-ink-soft"
        >
          {subtask.assignee_id != null &&
            !needs("assignee_id") &&
            (isProposal ? "You" : labelFor(options.members, subtask.assignee_id))}
        </Cell>
      </div>

      {blocked && (
        <div className="mt-2 space-y-2 sm:pl-6">
          <Problems errors={subtask.errors} />

          <div className="flex flex-wrap gap-2">
            {needs("status_id") && (
              <Editor label="Status">
                <Select
                  className="h-10 sm:h-9"
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
                  className="h-10 sm:h-9"
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
                  className="h-10 sm:h-9"
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
                  className="h-10 w-full rounded-md border border-input bg-transparent px-3 text-sm sm:h-9"
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

/**
 * The status, rendered exactly as the board renders it.
 *
 * This deliberately reuses the List view's own StatusPill rather than restyling
 * a lookalike: the preview is a table of tasks, and a task's status should not
 * read one way here and another way the moment it lands on the board. The pill
 * needs the status key (to tint the default columns) and the status colour (for
 * a custom one), which is why the import options carry both.
 */
function StatusPillFor({
  id,
  options,
}: {
  id: string;
  options: ImportOptions;
}) {
  const status: ImportStatusOption | undefined = options.statuses.find(
    (o) => String(o.value) === String(id),
  );

  return (
    <StatusPill
      statusKey={status?.key ?? null}
      statusName={status?.label ?? null}
      statusColor={status?.color ?? null}
    />
  );
}

/**
 * One column of a row.
 *
 * An empty cell still has to exist at `md` and up or the grid closes the gap and
 * every column to its right slides left — which is exactly what made the rows
 * look ragged. Below `md` the row wraps instead, so an empty cell is hidden to
 * avoid leaving a stray gap between chips.
 */
function Cell({
  empty,
  className,
  children,
}: {
  empty?: boolean;
  className?: string;
  children?: React.ReactNode;
}) {
  return (
    <span className={cn(empty && "hidden md:block", "min-w-0", className)}>
      {children}
    </span>
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
    <label className={cn("block", wide ? "w-full" : "w-full sm:w-44")}>
      <span className="mb-1 block text-2xs font-semibold tracking-wide text-ink-faint uppercase">
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
      className="h-10 w-full rounded-md border border-input bg-transparent px-3 text-sm sm:h-9"
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
      inputMode="numeric"
      className="h-10 w-full rounded-md border border-input bg-transparent px-3 text-sm sm:h-9"
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

