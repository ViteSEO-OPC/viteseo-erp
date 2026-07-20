"use client";

import { useEffect, useState } from "react";
import { Plus, Trash2 } from "lucide-react";
import { AxiosError } from "axios";
import { notify as toast } from "@/core/lib/notify";

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
import { StatusBadge } from "@/core/components/status-badge";
import { formatDate } from "@/core/lib/format";
import { CHANGE_FIELDS } from "@/modules/hr/types/request";
import {
  useCreateChangeRequest,
  useMyChangeRequests,
} from "@/modules/hr/hooks/use-requests";

type Row = { field: string; value: string };

const selectClass =
  "h-9 w-full rounded-lg border border-input bg-transparent px-2.5 text-sm outline-none focus-visible:border-ring";

const newRow = (): Row => ({ field: CHANGE_FIELDS[0].value, value: "" });

/**
 * Employee self-service: request changes to several personal/bank fields at once.
 * Each field submits as its own request so HR can approve/reject them individually.
 */
export function ChangeRequestModal({
  open,
  onOpenChange,
  employee,
}: {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  employee: Record<string, unknown> | undefined;
}) {
  const createMut = useCreateChangeRequest();
  const { data: history } = useMyChangeRequests();
  const [rows, setRows] = useState<Row[]>([newRow()]);

  useEffect(() => {
    if (open) setRows([newRow()]);
  }, [open]);

  function currentValue(field: string): string {
    const v = employee?.[field];
    return v === null || v === undefined || v === "" ? "—" : String(v);
  }
  function update(i: number, patch: Partial<Row>) {
    setRows((rs) => rs.map((r, idx) => (idx === i ? { ...r, ...patch } : r)));
  }
  function remove(i: number) {
    setRows((rs) => (rs.length === 1 ? [newRow()] : rs.filter((_, idx) => idx !== i)));
  }

  const valid = rows.filter((r) => r.value.trim() !== "");

  async function submit() {
    if (!valid.length) return;
    try {
      await createMut.mutateAsync({
        changes: valid.map((r) => ({ field: r.field, new_value: r.value.trim() })),
      });
      toast.success(
        `Submitted ${valid.length} change ${valid.length === 1 ? "request" : "requests"}`,
        "HR reviews each change individually.",
      );
      onOpenChange(false);
    } catch (err) {
      const msg =
        err instanceof AxiosError
          ? (err.response?.data?.message ?? "Could not submit. Please try again.")
          : "Could not submit. Please try again.";
      toast.error(msg);
    }
  }

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="max-h-[90svh] max-w-[calc(100%-3rem)] overflow-y-auto sm:max-w-lg">
        <DialogHeader>
          <DialogTitle>Request profile changes</DialogTitle>
          <DialogDescription>
            Propose updates to your details. Each change is reviewed by HR individually.
          </DialogDescription>
        </DialogHeader>

        <div className="max-h-[60vh] space-y-4 overflow-y-auto px-0.5">
          <div className="space-y-3">
            {rows.map((r, i) => (
              <div key={i} className="rounded-lg border border-border p-3">
                <div className="flex items-center gap-2">
                  <select
                    className={selectClass}
                    value={r.field}
                    onChange={(e) => update(i, { field: e.target.value })}
                  >
                    {CHANGE_FIELDS.map((f) => (
                      <option key={f.value} value={f.value}>{f.label}</option>
                    ))}
                  </select>
                  <button
                    type="button"
                    onClick={() => remove(i)}
                    className="inline-flex size-9 shrink-0 items-center justify-center rounded-lg text-muted-foreground transition-colors hover:bg-muted hover:text-danger"
                    aria-label="Remove change"
                  >
                    <Trash2 className="size-4" />
                  </button>
                </div>
                <p className="mt-2 text-caption text-muted-foreground">
                  Current: <span className="text-foreground">{currentValue(r.field)}</span>
                </p>
                <Input
                  className="mt-2"
                  placeholder="New value"
                  value={r.value}
                  onChange={(e) => update(i, { value: e.target.value })}
                />
              </div>
            ))}
          </div>

          <Button type="button" variant="ghost" size="sm" onClick={() => setRows((rs) => [...rs, newRow()])}>
            <Plus />
            Add another change
          </Button>

          {/* Recent change-request history */}
          {(history ?? []).length > 0 && (
            <div className="border-t border-border pt-3">
              <p className="mb-2 text-label font-bold uppercase tracking-wide text-muted-foreground">
                Recent requests
              </p>
              <div className="space-y-2">
                {(history ?? []).slice(0, 5).map((c) => (
                  <div key={c.id} className="flex items-center justify-between gap-2 text-sm">
                    <div className="min-w-0">
                      <p className="font-medium text-foreground">{c.field_label}</p>
                      <p className="truncate text-caption text-muted-foreground">
                        {c.old_value || "—"} → {c.new_value} · {formatDate(c.created_at)}
                      </p>
                    </div>
                    <StatusBadge status={c.status} />
                  </div>
                ))}
              </div>
            </div>
          )}
        </div>

        <DialogFooter>
          <DialogClose asChild>
            <Button variant="outline">Cancel</Button>
          </DialogClose>
          <Button disabled={!valid.length || createMut.isPending} onClick={submit}>
            {createMut.isPending ? "Submitting…" : `Submit ${valid.length || ""} change${valid.length === 1 ? "" : "s"}`.trim()}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}
