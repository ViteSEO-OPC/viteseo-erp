"use client";

import { useEffect, useRef, useState } from "react";
import { Plus, Trash2, LogOut } from "lucide-react";

import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import {
  Dialog,
  DialogContent,
  DialogHeader,
  DialogTitle,
  DialogDescription,
  DialogFooter,
  DialogClose,
} from "@/components/ui/dialog";
import type { DayTask } from "@/modules/hr/types/attendance";

const emptyTask = (): DayTask => ({ text: "", done: true });

/**
 * Time-out confirmation modal. Before clocking out the employee logs the tasks they
 * did today (text + a "done" checkbox); these are saved on the attendance record and
 * shown to HR in the attendance detail modal.
 */
export function TimeOutDialog({
  open,
  onOpenChange,
  onConfirm,
  pending,
}: {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  onConfirm: (tasks: DayTask[]) => Promise<void>;
  pending: boolean;
}) {
  const [tasks, setTasks] = useState<DayTask[]>([emptyTask()]);
  // Errors only appear once they've tried to confirm — not while first typing.
  const [showErrors, setShowErrors] = useState(false);
  const inputs = useRef<(HTMLInputElement | null)[]>([]);

  // Reset the list each time the modal opens (Radix keeps it mounted while closed).
  useEffect(() => {
    if (open) {
      setTasks([emptyTask()]);
      setShowErrors(false);
    }
  }, [open]);

  const isBlank = (t: DayTask) => t.text.trim() === "";
  const firstBlank = tasks.findIndex(isBlank);
  const hasBlank = firstBlank !== -1;

  function update(i: number, patch: Partial<DayTask>) {
    setTasks((rows) => rows.map((r, idx) => (idx === i ? { ...r, ...patch } : r)));
  }
  function add() {
    setTasks((rows) => [...rows, emptyTask()]);
  }
  function remove(i: number) {
    setTasks((rows) => (rows.length === 1 ? [emptyTask()] : rows.filter((_, idx) => idx !== i)));
  }

  async function confirm() {
    // Every field must say something — previously blanks were quietly dropped,
    // which let you clock out having logged nothing at all.
    if (hasBlank) {
      setShowErrors(true);
      inputs.current[firstBlank]?.focus();
      return;
    }
    await onConfirm(tasks.map((t) => ({ text: t.text.trim(), done: t.done })));
  }

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="max-h-[90svh] max-w-[calc(100%-3rem)] overflow-y-auto">
        <DialogHeader>
          <DialogTitle className="flex items-center gap-2">
            <span className="flex size-8 items-center justify-center rounded-lg bg-danger-subtle text-danger">
              <LogOut className="size-4" />
            </span>
            Time out for the day?
          </DialogTitle>
          <DialogDescription>
            Log what you worked on today, then confirm your time out. Your time out is
            recorded at the current time.
          </DialogDescription>
        </DialogHeader>

        <div className="space-y-2">
          <p className="text-label font-bold uppercase tracking-wide text-muted-foreground">
            Tasks done today
          </p>
          <div className="space-y-2">
            {tasks.map((t, i) => (
              <div key={i} className="flex items-center gap-2">
                <input
                  type="checkbox"
                  className="size-4 shrink-0 rounded border-input accent-brand"
                  checked={t.done}
                  onChange={(e) => update(i, { done: e.target.checked })}
                  aria-label="Task done"
                />
                <Input
                  ref={(el) => {
                    inputs.current[i] = el;
                  }}
                  className="flex-1"
                  placeholder={`Task ${i + 1} — e.g. Finished the payroll report`}
                  value={t.text}
                  aria-invalid={showErrors && isBlank(t)}
                  onChange={(e) => update(i, { text: e.target.value })}
                />
                <button
                  type="button"
                  onClick={() => remove(i)}
                  className="inline-flex size-8 shrink-0 items-center justify-center rounded-lg text-muted-foreground transition-colors hover:bg-muted hover:text-danger"
                  aria-label="Remove task"
                >
                  <Trash2 className="size-4" />
                </button>
              </div>
            ))}
          </div>
          {showErrors && hasBlank && (
            <p className="text-xs text-destructive">
              {tasks.length === 1
                ? "Log at least one task before timing out."
                : "Every task needs a description — fill it in or remove the row."}
            </p>
          )}

          <Button type="button" variant="ghost" size="sm" onClick={add}>
            <Plus />
            Add task
          </Button>
        </div>

        <DialogFooter>
          <DialogClose asChild>
            <Button variant="outline">Cancel</Button>
          </DialogClose>
          <Button
            className="bg-danger text-white hover:bg-danger/90"
            disabled={pending}
            onClick={confirm}
          >
            {pending ? "Clocking out…" : "Confirm time out"}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}
