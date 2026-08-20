"use client";

import { useState } from "react";
import { Pencil, Plus, Trash2 } from "lucide-react";
import { AxiosError } from "axios";
import { notify as toast } from "@/core/lib/notify";

import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from "@/components/ui/dialog";
import { DataTable, type Column } from "@/core/components/data-table";
import { cn } from "@/lib/utils";
import { DatePicker } from "@/modules/hr/ui/date-picker";
import { SimpleSelect } from "@/modules/hr/ui/select";
import { DeleteConfirmDialog } from "@/modules/hr/components/delete-confirm-dialog";
import {
  useCreateEmploymentHistory,
  useUpdateEmploymentHistory,
  useDeleteEmploymentHistory,
} from "@/modules/hr/hooks/use-employees";
import type { EmploymentHistoryEntry } from "@/modules/hr/types/employee";

/** How a role ended. `current` is not offered — it is implied by leaving the end date empty. */
const END_REASONS = [
  { value: "role_change", label: "Role change" },
  { value: "promoted", label: "Promoted" },
  { value: "transferred", label: "Transferred" },
  { value: "lateral", label: "Lateral move" },
  { value: "separated", label: "Left the company" },
] as const;

const MONTH = (iso: string) =>
  new Date(iso).toLocaleDateString("en-PH", { month: "short", year: "numeric" });

function statusLabel(status: string): string {
  if (status === "current") return "Current";
  return END_REASONS.find((r) => r.value === status)?.label ?? status.replace(/_/g, " ");
}

/** The server's message for a field, rather than a generic failure — its rules are specific. */
function serverMessage(err: unknown, fallback: string): string {
  if (err instanceof AxiosError && err.response?.status === 422) {
    const errors = err.response.data?.errors ?? {};
    const first = Object.values(errors)[0];
    if (Array.isArray(first) && typeof first[0] === "string") return first[0];
    return err.response.data?.message ?? fallback;
  }
  return fallback;
}

/** The employment timeline, with hand-corrections (Job tab). */
export function EmploymentHistorySection({
  employeeId,
  entries,
  canEdit,
}: {
  employeeId: number;
  entries: EmploymentHistoryEntry[];
  canEdit: boolean;
}) {
  const [editing, setEditing] = useState<EmploymentHistoryEntry | null>(null);
  const [adding, setAdding] = useState(false);
  const [removing, setRemoving] = useState<EmploymentHistoryEntry | null>(null);

  const removeMut = useDeleteEmploymentHistory();

  const columns: Column<EmploymentHistoryEntry>[] = [
    {
      header: "Role",
      cell: (h) => (
        <div className="min-w-0">
          <p className="truncate text-sm font-semibold text-foreground">{h.title}</p>
          {h.reason ? (
            <p className="truncate text-caption text-muted-foreground">{h.reason}</p>
          ) : null}
        </div>
      ),
    },
    {
      header: "Period",
      cell: (h) => (
        <span className="whitespace-nowrap text-caption text-muted-foreground">
          {MONTH(h.start_date)} – {h.end_date ? MONTH(h.end_date) : "Present"}
        </span>
      ),
    },
    {
      header: "Status",
      cell: (h) => (
        <span
          className={cn(
            "inline-block shrink-0 rounded-full px-2 py-0.5 text-xs font-semibold uppercase",
            h.status === "current"
              ? "bg-success-subtle text-success"
              : "bg-muted text-muted-foreground",
          )}
        >
          {statusLabel(h.status)}
        </span>
      ),
    },
    ...(canEdit
      ? ([
          {
            header: "",
            align: "right",
            className: "w-24",
            cell: (h) => (
              <span className="inline-flex gap-1">
                <Button
                  type="button"
                  size="icon-sm"
                  variant="ghost"
                  onClick={() => setEditing(h)}
                  aria-label={`Edit ${h.title}`}
                >
                  <Pencil />
                </Button>
                <Button
                  type="button"
                  size="icon-sm"
                  variant="ghost"
                  onClick={() => setRemoving(h)}
                  aria-label={`Delete ${h.title}`}
                >
                  <Trash2 />
                </Button>
              </span>
            ),
          },
        ] as Column<EmploymentHistoryEntry>[])
      : []),
  ];

  return (
    <>
      <DataTable
        columns={columns}
        rows={entries}
        rowKey={(h) => h.id}
        empty="No employment history yet."
        // Paginated: a long-serving employee accumulates a row per promotion, and the tab
        // should not grow without bound.
        pageSize={5}
        searchable={entries.length > 5}
        searchPlaceholder="Search roles…"
        filterable={false}
        titleRight={
          canEdit ? (
            <Button type="button" size="sm" variant="outline" onClick={() => setAdding(true)}>
              <Plus />
              Add entry
            </Button>
          ) : undefined
        }
      />

      {(adding || editing) && (
        <EmploymentHistoryDialog
          employeeId={employeeId}
          entry={editing}
          onClose={() => {
            setAdding(false);
            setEditing(null);
          }}
        />
      )}

      <DeleteConfirmDialog
        open={removing !== null}
        onOpenChange={(o) => !o && setRemoving(null)}
        title="Delete timeline entry"
        entityName={removing?.title}
        description="It is removed from the employment history. This does not change the employee's current job title."
        isPending={removeMut.isPending}
        onConfirm={async () => {
          if (!removing) return;
          try {
            await removeMut.mutateAsync(removing.id);
            toast.success("Entry deleted");
            setRemoving(null);
          } catch (err) {
            toast.error(serverMessage(err, "Could not delete this entry."));
            throw err; // keeps the dialog open so the message stays in context
          }
        }}
      />
    </>
  );
}

/** Add or correct one role. `entry === null` means add. */
function EmploymentHistoryDialog({
  employeeId,
  entry,
  onClose,
}: {
  employeeId: number;
  entry: EmploymentHistoryEntry | null;
  onClose: () => void;
}) {
  const [title, setTitle] = useState(entry?.title ?? "");
  const [startDate, setStartDate] = useState(entry?.start_date ?? "");
  const [endDate, setEndDate] = useState(entry?.end_date ?? "");
  const [ongoing, setOngoing] = useState(entry ? entry.end_date === null : false);
  const [status, setStatus] = useState(
    entry && entry.status !== "current" ? entry.status : "role_change",
  );
  const [reason, setReason] = useState(entry?.reason ?? "");
  const [error, setError] = useState<string | null>(null);

  const createMut = useCreateEmploymentHistory(employeeId);
  const updateMut = useUpdateEmploymentHistory();
  const pending = createMut.isPending || updateMut.isPending;

  async function submit() {
    setError(null);

    if (!title.trim()) return setError("Give the role a title.");
    if (!startDate) return setError("Pick a start date.");
    if (!ongoing && !endDate) return setError("Pick an end date, or tick “still in this role”.");

    const input = {
      title: title.trim(),
      start_date: startDate,
      end_date: ongoing ? null : endDate,
      status: ongoing ? undefined : status,
      reason: reason.trim() || null,
    };

    try {
      if (entry) {
        await updateMut.mutateAsync({ id: entry.id, input });
        toast.success("Entry updated");
      } else {
        await createMut.mutateAsync(input);
        toast.success("Entry added");
      }
      onClose();
    } catch (err) {
      // The server's rules are specific ("X is already open with no end date…") — show them
      // in the dialog rather than as a toast the user has to remember while fixing the form.
      setError(serverMessage(err, "Could not save this entry."));
    }
  }

  return (
    <Dialog open onOpenChange={(o) => !o && onClose()}>
      <DialogContent className="max-h-[90svh] max-w-[calc(100%-3rem)] overflow-y-auto sm:max-w-[460px]">
        <DialogHeader>
          <DialogTitle>{entry ? "Edit timeline entry" : "Add timeline entry"}</DialogTitle>
          <DialogDescription>
            Roles are recorded automatically when the job title changes. Use this to correct or
            backfill one.
          </DialogDescription>
        </DialogHeader>

        <div className="space-y-4">
          <div className="space-y-1.5">
            <Label htmlFor="eh-title">Job title</Label>
            <Input
              id="eh-title"
              value={title}
              onChange={(e) => setTitle(e.target.value)}
              placeholder="Software Engineer"
            />
          </div>

          <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
            <div className="space-y-1.5">
              <Label htmlFor="eh-start">Started</Label>
              <DatePicker
                id="eh-start"
                value={startDate}
                onChange={setStartDate}
                className="w-full"
              />
            </div>
            <div className="space-y-1.5">
              <Label htmlFor="eh-end">Ended</Label>
              <DatePicker
                id="eh-end"
                value={endDate}
                onChange={setEndDate}
                min={startDate || undefined}
                disabled={ongoing}
                className="w-full"
              />
            </div>
          </div>

          <label className="flex items-center gap-2 text-sm text-foreground">
            <input
              type="checkbox"
              className="size-4 rounded border-input accent-brand"
              checked={ongoing}
              onChange={(e) => setOngoing(e.target.checked)}
            />
            Still in this role
          </label>

          {!ongoing && (
            <div className="space-y-1.5">
              <Label htmlFor="eh-status">How it ended</Label>
              <SimpleSelect
                value={status}
                onValueChange={setStatus}
                options={END_REASONS.map((r) => ({ value: r.value, label: r.label }))}
              />
            </div>
          )}

          <div className="space-y-1.5">
            <Label htmlFor="eh-reason">Note (optional)</Label>
            <Input
              id="eh-reason"
              value={reason}
              onChange={(e) => setReason(e.target.value)}
              placeholder="e.g. Promoted after Q2 review"
            />
          </div>

          {error && (
            <div className="rounded-lg border border-destructive/40 bg-destructive/10 p-3">
              <p className="text-xs text-foreground">{error}</p>
            </div>
          )}
        </div>

        <DialogFooter>
          <Button type="button" variant="ghost" onClick={onClose} disabled={pending}>
            Cancel
          </Button>
          <Button type="button" onClick={() => submit()} disabled={pending}>
            {pending ? "Saving…" : entry ? "Save changes" : "Add entry"}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}
