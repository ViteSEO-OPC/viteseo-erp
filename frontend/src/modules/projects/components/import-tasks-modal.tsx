"use client";

import { useCallback, useMemo, useRef, useState } from "react";
import {
  AlertTriangle,
  CheckCircle2,
  Download,
  FileJson,
  Loader2,
  UploadCloud,
} from "lucide-react";
import { Button, Modal, Select } from "@/core/ui";
import { useIsManager } from "@/core/auth/AuthProvider";
import { cn } from "@/core/lib/utils";
import { notify as toast } from "@/core/lib/notify";
import { downloadImportBrief } from "@/modules/projects/api/task-import";
import {
  useCommitTaskImport,
  usePreviewTaskImport,
  useRevalidateTaskImport,
} from "@/modules/projects/hooks/use-task-import";
import {
  ImportPreviewTree,
  rowIsBlocked,
} from "@/modules/projects/components/import-preview-tree";
import type {
  ImportPlan,
  ImportPreviewSubtask,
  ImportPreviewTask,
  ImportWorkload,
  UnresolvedField,
  UnresolvedValue,
} from "@/modules/projects/types/task-import";

interface ImportTasksModalProps {
  open: boolean;
  onClose: () => void;
  projectId: string;
  projectName?: string;
}

/** Which row field an unresolved entry fills in. */
const FIELD_TO_KEY: Record<UnresolvedValue["field"], UnresolvedField> = {
  status: "status_id",
  group: "group_id",
  assignee: "assignee_id",
  priority: "priority",
  effort_score: "effort_score",
};

/**
 * Bulk task import: brief → assistant → upload → preview → create.
 *
 * The PM plans in an LLM chat, so the file arriving here was written by a model
 * and its mistakes are systematic rather than scattered — one invented status
 * lands on every row it touched. That shapes the whole screen: unresolved values
 * are fixed once, in aggregate, and only the leftovers are fixed per row.
 */
export function ImportTasksModal({
  open,
  onClose,
  projectId,
  projectName,
}: ImportTasksModalProps) {
  const [plan, setPlan] = useState<ImportPlan | null>(null);
  const [rows, setRows] = useState<ImportPreviewTask[]>([]);
  const [progress, setProgress] = useState(0);
  const [parseError, setParseError] = useState<string | null>(null);
  const [downloading, setDownloading] = useState(false);
  const fileInput = useRef<HTMLInputElement>(null);

  // Before a file exists there is no plan to read the mode off, but the brief and
  // the intro copy already differ — so the first screen goes by the viewer's role.
  // Once a plan arrives the server's own answer (`plan.mode`) takes over.
  const isProposalBrief = !useIsManager();

  const preview = usePreviewTaskImport(projectId);
  const revalidate = useRevalidateTaskImport(projectId);
  const commit = useCommitTaskImport(projectId);

  const reset = useCallback(() => {
    setPlan(null);
    setRows([]);
    setProgress(0);
    setParseError(null);
  }, []);

  const close = useCallback(() => {
    reset();
    onClose();
  }, [onClose, reset]);

  // ---------------------------------------------------------------- editing

  /** Drop any unresolved field this patch has just filled in. */
  const clearResolved = <T extends ImportPreviewTask | ImportPreviewSubtask>(
    row: T,
    patch: Partial<T>,
  ): UnresolvedField[] =>
    row.unresolved_fields.filter((field) => {
      const next = (patch as Record<string, unknown>)[field];
      return next === undefined || next === null || next === "";
    });

  const patchTask = useCallback(
    (ref: string, patch: Partial<ImportPreviewTask>) => {
      setRows((current) =>
        current.map((task) =>
          task.ref === ref
            ? {
                ...task,
                ...patch,
                unresolved_fields: clearResolved(task, patch),
              }
            : task,
        ),
      );
    },
    [],
  );

  const patchSubtask = useCallback(
    (ref: string, patch: Partial<ImportPreviewSubtask>) => {
      setRows((current) =>
        current.map((task) => ({
          ...task,
          subtasks: task.subtasks.map((subtask) =>
            subtask.ref === ref
              ? {
                  ...subtask,
                  ...patch,
                  unresolved_fields: clearResolved(subtask, patch),
                }
              : subtask,
          ),
        })),
      );
    },
    [],
  );

  /** Apply one remap to every row the value appeared on. */
  const applyRemap = useCallback(
    (entry: UnresolvedValue, raw: string) => {
      if (raw === "") return;
      const key = FIELD_TO_KEY[entry.field];
      const targets = new Set(entry.refs);

      const value: string | number | null =
        key === "assignee_id"
          ? Number(raw)
          : key === "effort_score"
            ? Number(raw)
            : raw;

      const patchFor = (
        row: ImportPreviewTask | ImportPreviewSubtask,
      ): Partial<ImportPreviewTask> => {
        // Setting a group also flips the task's assignment side; leaving
        // assignee_id set alongside it is exactly the conflict we're fixing.
        if (key === "group_id") {
          return {
            group_id: String(value),
            assignment_type: "group",
            assignee_id: null,
          };
        }
        if (key === "assignee_id" && row.kind === "task") {
          return {
            assignee_id: Number(value),
            assignment_type: "individual",
            group_id: null,
          };
        }
        return { [key]: value } as Partial<ImportPreviewTask>;
      };

      setRows((current) =>
        current.map((task) => {
          const next = targets.has(task.ref)
            ? {
                ...task,
                ...patchFor(task),
                unresolved_fields: task.unresolved_fields.filter(
                  (f) => f !== key,
                ),
              }
            : task;

          return {
            ...next,
            subtasks: next.subtasks.map((subtask) =>
              targets.has(subtask.ref)
                ? {
                    ...subtask,
                    ...(patchFor(subtask) as Partial<ImportPreviewSubtask>),
                    unresolved_fields: subtask.unresolved_fields.filter(
                      (f) => f !== key,
                    ),
                  }
                : subtask,
            ),
          };
        }),
      );
    },
    [],
  );

  // ---------------------------------------------------------------- derived

  // A proposer submits their own work for review; a manager puts work on the board.
  // Every difference in this dialog's wording hangs off this one flag.
  const isProposal = plan?.mode === "proposal";

  // Before a plan exists there is no server answer to read, so the dialog's own
  // chrome falls back to the viewer's role; once a plan arrives, `plan.mode` is
  // authoritative and wins.
  const proposing = plan ? isProposal : isProposalBrief;

  const allRows = useMemo(
    () => rows.flatMap((task) => [task, ...task.subtasks]),
    [rows],
  );

  const blocked = useMemo(() => allRows.filter(rowIsBlocked).length, [allRows]);

  const subtaskCount = useMemo(
    () => rows.reduce((total, task) => total + task.subtasks.length, 0),
    [rows],
  );

  /**
   * Recomputed locally as the PM edits, so the number tracks what they are about
   * to submit rather than what they uploaded. Mirrors WorkloadService's rule: a
   * task with sub-tasks contributes nothing and only its sub-tasks count.
   */
  const workload = useMemo<ImportWorkload | null>(() => {
    if (!plan?.workload) return null;
    const adds = rows.reduce((total, task) => {
      if (task.subtasks.length > 0) {
        return (
          total +
          task.subtasks.reduce((sum, s) => sum + (s.effort_score ?? 0), 0)
        );
      }
      return total + (task.effort_score ?? 0);
    }, 0);
    const projected = plan.workload.current + adds;
    const { light, moderate } = plan.workload.thresholds;

    return {
      ...plan.workload,
      adds,
      projected,
      // Re-derived, not carried over: the server's level was for the plan as
      // uploaded, and an edit that changes the total has to move the band with it
      // or the panel keeps showing the old colour. Same cut-offs as
      // WorkloadService::level().
      projected_level:
        projected <= light ? "light" : projected <= moderate ? "moderate" : "heavy",
    };
  }, [plan, rows]);

  /**
   * An unresolved entry stays on screen only while a row it names still needs
   * that field — so fixing rows individually retires the aggregate row too.
   */
  const openUnresolved = useMemo(() => {
    if (!plan) return [];
    const byRef = new Map(allRows.map((row) => [row.ref, row]));
    return plan.unresolved.filter((entry) =>
      entry.refs.some((ref) =>
        byRef.get(ref)?.unresolved_fields.includes(FIELD_TO_KEY[entry.field]),
      ),
    );
  }, [plan, allRows]);

  // ----------------------------------------------------------------- upload

  const onFile = useCallback(
    async (file: File | null | undefined) => {
      if (!file) return;
      reset();
      setProgress(0);
      try {
        const result = await preview.mutateAsync({
          file,
          onUploadProgress: setProgress,
        });
        setPlan(result);
        setRows(result.tasks);
      } catch (error) {
        setParseError(readError(error));
      }
    },
    [preview, reset],
  );

  const onRevalidate = useCallback(async () => {
    try {
      const errors = await revalidate.mutateAsync(rows);
      setRows((current) =>
        current.map((task) => ({
          ...task,
          errors: errors[task.ref] ?? [],
          subtasks: task.subtasks.map((subtask) => ({
            ...subtask,
            errors: errors[subtask.ref] ?? [],
          })),
        })),
      );
    } catch (error) {
      toast.error("Could not re-check the plan", readError(error));
    }
  }, [revalidate, rows]);

  const onImport = useCallback(async () => {
    try {
      const result = await commit.mutateAsync(rows);
      const withSubs =
        result.created_subtasks > 0
          ? ` and ${result.created_subtasks} ${plural(result.created_subtasks, "sub-task")}`
          : "";
      if (isProposal) {
        toast.success(
          `Submitted ${result.created_tasks} ${plural(result.created_tasks, "task")} for review`,
          `Your project manager reviews${withSubs ? ` these${withSubs}` : " them"} before they reach the board.`,
        );
      } else {
        toast.success(
          `Imported ${result.created_tasks} ${plural(result.created_tasks, "task")}`,
          withSubs ? withSubs.replace(/^ and /, "and ") : undefined,
        );
      }
      close();
    } catch (error) {
      toast.error("Import failed", readError(error));
    }
  }, [commit, rows, close, isProposal]);

  const onDownloadBrief = useCallback(async () => {
    setDownloading(true);
    try {
      await downloadImportBrief(projectId);
    } catch (error) {
      toast.error("Could not download the brief", readError(error));
    } finally {
      setDownloading(false);
    }
  }, [projectId]);

  // ------------------------------------------------------------------ views

  const analysing = preview.isPending;

  return (
    <Modal
      open={open}
      onClose={close}
      title={proposing ? "Import my tasks" : "Import tasks"}
      width={plan ? 920 : 560}
      footer={
        plan ? (
          // Stacks on a phone: the status line reads first, then the actions as a
          // full-width block with the primary spanning both columns so the thing
          // you actually came here to press is the biggest target on screen.
          <div className="flex w-full flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
            <span className="text-xs font-medium text-ink-soft">
              {blocked > 0
                ? `${blocked} ${plural(blocked, "row")} still need${blocked === 1 ? "s" : ""} attention`
                : isProposal
                  ? "Ready to send for review"
                  : "Everything checks out"}
            </span>
            <div className="grid grid-cols-2 gap-2 sm:flex sm:flex-wrap">
              <Button
                variant="ghost"
                className="h-10 sm:h-9"
                onClick={close}
              >
                Cancel
              </Button>
              <Button
                variant="outline"
                className="h-10 sm:h-9"
                onClick={onRevalidate}
                disabled={revalidate.isPending}
              >
                {revalidate.isPending && (
                  <Loader2 className="h-4 w-4 animate-spin" />
                )}
                Re-check
              </Button>
              <Button
                className="col-span-2 h-10 sm:col-span-1 sm:h-9"
                onClick={onImport}
                disabled={blocked > 0 || commit.isPending}
              >
                {commit.isPending && (
                  <Loader2 className="h-4 w-4 animate-spin" />
                )}
                <span className="truncate">
                  {isProposal
                    ? `Submit ${rows.length} ${plural(rows.length, "task")} for review`
                    : `Import ${rows.length} ${plural(rows.length, "task")}`}
                  {!isProposal &&
                    subtaskCount > 0 &&
                    ` · ${subtaskCount} ${plural(subtaskCount, "sub-task")}`}
                </span>
              </Button>
            </div>
          </div>
        ) : undefined
      }
    >
      {!plan ? (
        <div className="space-y-5">
          <section className="space-y-2">
            <h3 className="text-sm font-bold text-ink">
              1. Get the brief for this project
            </h3>
            <p className="text-sm text-ink-soft">
              {isProposalBrief ? (
                <>
                  It lists {projectName ? `${projectName}'s` : "this project's"}{" "}
                  statuses and the rules Sprintly enforces. Give it to your AI
                  assistant, plan your own work with it, and save the JSON file it
                  produces. Everything you import is assigned to you and goes to
                  your project manager for review.
                </>
              ) : (
                <>
                  It lists {projectName ? `${projectName}'s` : "this project's"}{" "}
                  statuses, groups and members, and the rules Sprintly enforces.
                  Give it to your AI assistant, plan the project with it, and save
                  the JSON file it produces.
                </>
              )}
            </p>
            <Button
              variant="brand-outline"
              onClick={onDownloadBrief}
              disabled={downloading}
            >
              {downloading ? (
                <Loader2 className="h-4 w-4 animate-spin" />
              ) : (
                <Download className="h-4 w-4" />
              )}
              Download brief
            </Button>
          </section>

          <section className="space-y-2">
            <h3 className="text-sm font-bold text-ink">
              2. Upload what it produced
            </h3>

            <button
              type="button"
              onClick={() => fileInput.current?.click()}
              disabled={analysing}
              className="flex w-full flex-col items-center gap-2 rounded-xl border-2 border-dashed border-line px-6 py-8 text-center transition-colors hover:border-brand hover:bg-brand-subtle/40 disabled:opacity-60"
            >
              {analysing ? (
                <>
                  <Loader2 className="h-6 w-6 animate-spin text-brand" />
                  <span className="text-sm font-semibold text-ink">
                    {progress < 100
                      ? `Uploading… ${progress}%`
                      : "Reading and checking the plan…"}
                  </span>
                </>
              ) : (
                <>
                  <UploadCloud className="h-6 w-6 text-ink-faint" />
                  <span className="text-sm font-semibold text-ink">
                    Choose the .json file
                  </span>
                  <span className="text-xs text-ink-faint">
                    Nothing is created until you review it
                  </span>
                </>
              )}
            </button>

            <input
              ref={fileInput}
              type="file"
              accept=".json,application/json,.txt"
              className="hidden"
              onChange={(e) => {
                void onFile(e.target.files?.[0]);
                // Reset so picking the same file twice fires again.
                e.target.value = "";
              }}
            />

            {parseError && (
              <p className="flex items-start gap-2 rounded-lg bg-danger-subtle px-3 py-2 text-sm font-medium text-danger">
                <AlertTriangle className="mt-0.5 h-4 w-4 shrink-0" />
                <span>{parseError}</span>
              </p>
            )}
          </section>
        </div>
      ) : (
        <div className="space-y-4">
          <div className="flex items-center gap-3">
            <FileJson className="h-4 w-4 shrink-0 text-ink-faint" />
            <Summary
              tasks={rows.length}
              subtasks={subtaskCount}
              blocked={blocked}
              isProposal={isProposal}
            />
          </div>

          {isProposal && workload && <WorkloadPanel workload={workload} />}

          {isProposal && (
            <p className="rounded-lg bg-brand-subtle/50 px-3 py-2 text-xs font-medium text-ink-soft">
              Everything here is assigned to you. Your project manager reviews it
              before any of it reaches the board — a task and its sub-tasks are
              reviewed together as one item.
            </p>
          )}

          {openUnresolved.length > 0 && (
            <section className="space-y-2 rounded-xl border border-warning bg-warning-subtle/50 p-3">
              <h3 className="text-sm font-bold text-ink">
                Fix these once, and every row they touch is fixed
              </h3>
              {openUnresolved.map((entry) => (
                <RemapRow
                  key={`${entry.field}|${entry.raw ?? ""}`}
                  entry={entry}
                  onApply={(value) => applyRemap(entry, value)}
                />
              ))}
            </section>
          )}

          {/* One scroll region on a phone, not two: the dialog itself is already
              capped at 90vh and scrolls, and a nested scroller inside it is the
              thing that makes a long list feel stuck under a thumb. The inner
              cap only comes back once there is room for it to help. */}
          <div className="sm:max-h-[46vh] sm:overflow-y-auto">
            <ImportPreviewTree
              tasks={rows}
              options={plan.options}
              mode={plan.mode}
              onPatchTask={patchTask}
              onPatchSubtask={patchSubtask}
            />
          </div>
        </div>
      )}
    </Modal>
  );
}

/**
 * What this submission does to the author's own load.
 *
 * `current` counts approved work only, so a pending submission adds nothing to it
 * until a PM approves — the panel says "if approved" rather than implying the load
 * has already moved. Advisory: going over never blocks the submit, it just makes
 * the trade-off visible to the person making it (and their reviewer).
 */
function WorkloadPanel({ workload }: { workload: ImportWorkload }) {
  const { current, adds, projected, thresholds, projected_level } = workload;
  const over = projected > thresholds.max;
  const heavy = projected_level === "heavy";

  // Bar is drawn against the configured top of the effort range, so it lines up
  // with the bands on the Workload Analysis page.
  const scale = Math.max(thresholds.max, projected, 1);
  const pct = (value: number) => `${Math.min(100, (value / scale) * 100)}%`;

  return (
    <section
      className={cn(
        "space-y-2 rounded-xl border p-3",
        over || heavy ? "border-warning bg-warning-subtle/50" : "border-line bg-canvas",
      )}
    >
      <div className="flex flex-wrap items-baseline justify-between gap-2">
        <h3 className="text-sm font-bold text-ink">Your workload, if approved</h3>
        <span
          className={cn(
            "text-xs font-semibold",
            over || heavy ? "text-warning" : "text-ink-soft",
          )}
        >
          {projected} of {thresholds.max}
          {over ? " — over capacity" : heavy ? " — heavy" : ""}
        </span>
      </div>

      <div className="relative h-2.5 w-full overflow-hidden rounded-full bg-black/10">
        <div
          className="absolute inset-y-0 left-0 bg-brand"
          style={{ width: pct(current) }}
        />
        <div
          className={cn(
            "absolute inset-y-0",
            over || heavy ? "bg-warning" : "bg-brand/45",
          )}
          style={{ left: pct(current), width: pct(adds) }}
        />
      </div>

      <p className="text-xs text-ink-soft">
        You&rsquo;re carrying <strong className="text-ink">{current}</strong> right
        now.
        This adds <strong className="text-ink">{adds}</strong>.{" "}
        {over
          ? "That puts you past your capacity — consider trimming this plan or splitting it across sprints. Your PM sees this when reviewing."
          : "Pending work doesn't count against you until your PM approves it."}
      </p>
    </section>
  );
}

function Summary({
  tasks,
  subtasks,
  blocked,
  isProposal,
}: {
  tasks: number;
  subtasks: number;
  blocked: number;
  isProposal: boolean;
}) {
  return (
    <div className="flex min-w-0 flex-wrap items-center gap-x-4 gap-y-1 text-sm">
      <span className="font-semibold text-ink">
        {tasks} {plural(tasks, "task")}
        {subtasks > 0 && ` · ${subtasks} ${plural(subtasks, "sub-task")}`}
      </span>
      {blocked === 0 ? (
        <span className="flex items-center gap-1.5 font-medium text-success">
          <CheckCircle2 className="h-4 w-4" />
          {isProposal ? "Ready to send for review" : "Ready to import"}
        </span>
      ) : (
        <span className="flex items-center gap-1.5 font-medium text-warning">
          <AlertTriangle className="h-4 w-4" />
          {blocked} {plural(blocked, "row")} need attention
        </span>
      )}
    </div>
  );
}

function RemapRow({
  entry,
  onApply,
}: {
  entry: UnresolvedValue;
  onApply: (value: string) => void;
}) {
  const [value, setValue] = useState("");

  return (
    // Three things that don't fit one phone row: what's wrong, what to replace it
    // with, and the action. The description gets its own row; the control and its
    // button stay paired below it, since they're one gesture.
    <div className="flex flex-col gap-2 sm:flex-row sm:flex-wrap sm:items-end">
      {/* A floor on the description's width, so at the awkward middle sizes it
          wraps onto its own row (the container wraps) rather than being squeezed
          to one word per line beside the control. */}
      <div className="min-w-0 sm:min-w-[14rem] sm:flex-1">
        <p className="text-sm font-medium text-ink">{entry.label}</p>
        <p className="text-xs text-ink-soft">
          used on {entry.count} {plural(entry.count, "row")}
        </p>
      </div>

      <div className="flex items-end gap-2">
        {entry.kind === "number" ? (
          <input
            type="number"
            inputMode="numeric"
            min={0}
            max={10}
            placeholder="0-10"
            value={value}
            onChange={(e) => setValue(e.target.value)}
            className="h-10 w-full min-w-0 rounded-md border border-input bg-surface px-3 text-sm sm:h-9 sm:w-28"
          />
        ) : (
          <div className="min-w-0 flex-1 sm:w-52 sm:flex-none">
            <Select
              className="h-10 sm:h-9"
              value={value}
              onChange={(e) => setValue(e.target.value)}
            >
              <option value="">Choose…</option>
              {entry.candidates.map((option) => (
                <option key={String(option.value)} value={String(option.value)}>
                  {option.label}
                </option>
              ))}
            </Select>
          </div>
        )}

        <Button
          variant="outline"
          className="h-10 shrink-0 sm:h-8"
          disabled={value === ""}
          onClick={() => {
            onApply(value);
            setValue("");
          }}
        >
          Apply to all
        </Button>
      </div>
    </div>
  );
}

function plural(count: number, word: string): string {
  return count === 1 ? word : `${word}s`;
}

/** Pull the useful sentence out of a Laravel error envelope. */
function readError(error: unknown): string {
  const response = (
    error as {
      response?: { data?: { message?: string; errors?: Record<string, string[]> } };
    }
  )?.response;

  const first = response?.data?.errors
    ? Object.values(response.data.errors)[0]?.[0]
    : undefined;

  return first ?? response?.data?.message ?? "Something went wrong.";
}
