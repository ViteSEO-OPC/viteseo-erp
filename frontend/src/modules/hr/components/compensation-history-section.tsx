"use client";

import { useState } from "react";
import { AlertTriangle, Pencil, Plus, Trash2 } from "lucide-react";
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
import { formatPeso } from "@/core/lib/format";
import { cn } from "@/lib/utils";
import { DatePicker } from "@/modules/hr/ui/date-picker";
import { SimpleSelect } from "@/modules/hr/ui/select";
import { DeleteConfirmDialog } from "@/modules/hr/components/delete-confirm-dialog";
import {
  useSalaryHistory,
  useCreateSalaryHistory,
  useUpdateSalaryHistory,
  useDeleteSalaryHistory,
} from "@/modules/hr/hooks/use-employees";
import { salaryChangeLabel, type SalaryHistoryEntry } from "@/modules/hr/types/employee";

const CHANGE_TYPES = [
  { value: "merit", label: "Merit increase" },
  { value: "promotion", label: "Promotion" },
  { value: "adjustment", label: "Adjustment" },
  { value: "hire", label: "Initial salary" },
] as const;

const MONTH = (iso: string) =>
  new Date(iso).toLocaleDateString("en-PH", { month: "short", year: "numeric" });

/**
 * Pull the server's message out of a 422, and say WHICH field it landed on.
 *
 * The field matters here in a way it does not elsewhere: `effective_date` is the repricing question
 * (answerable by confirming), while `id` is the refusal that has no answer. Treating them the same
 * would either offer a confirm button for something that can never go through, or hide the confirm
 * for something that can.
 */
function fieldError(err: unknown): { field: string | null; message: string } {
  if (err instanceof AxiosError && err.response?.status === 422) {
    const errors: Record<string, string[]> = err.response.data?.errors ?? {};
    const [field, messages] = Object.entries(errors)[0] ?? [];
    if (field && Array.isArray(messages) && typeof messages[0] === "string") {
      return { field, message: messages[0] };
    }
    return { field: null, message: err.response.data?.message ?? "Could not save this entry." };
  }
  return { field: null, message: "Could not save this entry. Please try again." };
}

/**
 * The compensation timeline, with hand-corrections (Compensation tab).
 *
 * STILL WRITTEN AUTOMATICALLY, exactly as before: changing the base salary on this tab appends an
 * entry. These controls are for correcting what was recorded — and unlike the employment timeline,
 * a correction here is not free.
 *
 * WHY THIS ONE ASKS QUESTIONS. Payroll prices every run from these rows: an employee is paid the
 * latest entry effective on or before the cycle end. So an entry a completed run has already priced
 * against cannot simply be rewritten — the server refuses it once, names the periods, and lets it
 * through on a second call carrying an acknowledgement, raising an outstanding difference. And it
 * cannot be deleted at all, because a payslip with a corrected basis is recoverable while a payslip
 * with no basis is not. Both refusals arrive as 422s and are surfaced where HR is working.
 */
export function CompensationHistorySection({
  employeeId,
  canEdit,
}: {
  employeeId: number;
  canEdit: boolean;
}) {
  const { data: history, isLoading } = useSalaryHistory(employeeId);
  const [editing, setEditing] = useState<SalaryHistoryEntry | null>(null);
  const [adding, setAdding] = useState(false);
  const [removing, setRemoving] = useState<SalaryHistoryEntry | null>(null);
  const [blocked, setBlocked] = useState<string | null>(null);

  const removeMut = useDeleteSalaryHistory();
  const rows = history ?? [];

  if (isLoading) return <p className="text-sm text-muted-foreground">Loading…</p>;

  const columns: Column<SalaryHistoryEntry>[] = [
    {
      header: "Salary",
      cell: (h) => {
        // Rows arrive newest-first, so the next one along is the older figure.
        const i = rows.findIndex((r) => r.id === h.id);
        const prev = rows[i + 1];
        const pct =
          prev && prev.base_salary
            ? Math.round(((h.base_salary - prev.base_salary) / prev.base_salary) * 100)
            : null;
        return (
          <div className="min-w-0">
            {/* Monthly, because monthly is what is stored and what payroll prices from. An
                annualised headline here was a derived figure presented as the real one. */}
            <p className="text-sm font-semibold text-foreground">
              {formatPeso(h.base_salary)}
              <span className="text-caption font-normal text-muted-foreground"> / mo</span>
            </p>
            {pct != null && h.change_type !== "hire" ? (
              <p className="truncate text-caption text-muted-foreground">
                {pct >= 0 ? "+" : ""}
                {pct}% on the entry below
              </p>
            ) : null}
          </div>
        );
      },
    },
    {
      header: "Effective",
      cell: (h) => (
        <span className="whitespace-nowrap text-caption text-muted-foreground">
          {MONTH(h.effective_date)}
        </span>
      ),
    },
    {
      header: "Reason",
      cell: (h) => (
        <div className="min-w-0">
          <span
            className={cn(
              "inline-block rounded-full px-2 py-0.5 text-xs font-semibold uppercase",
              h.change_type === "promotion"
                ? "bg-success-subtle text-success"
                : "bg-muted text-muted-foreground",
            )}
          >
            {salaryChangeLabel(h.change_type)}
          </span>
          {h.reason ? (
            <p className="truncate text-caption text-muted-foreground">{h.reason}</p>
          ) : null}
        </div>
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
                  aria-label={`Edit the ${MONTH(h.effective_date)} entry`}
                >
                  <Pencil />
                </Button>
                <Button
                  type="button"
                  size="icon-sm"
                  variant="ghost"
                  onClick={() => setRemoving(h)}
                  aria-label={`Delete the ${MONTH(h.effective_date)} entry`}
                >
                  <Trash2 />
                </Button>
              </span>
            ),
          },
        ] as Column<SalaryHistoryEntry>[])
      : []),
  ];

  return (
    <>
      <DataTable
        columns={columns}
        rows={rows}
        rowKey={(h) => h.id}
        empty="No compensation history yet."
        pageSize={5}
        searchable={rows.length > 5}
        searchPlaceholder="Search entries…"
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
        <SalaryHistoryDialog
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
        title="Delete compensation entry"
        entityName={removing ? `${formatPeso(removing.base_salary)} from ${MONTH(removing.effective_date)}` : undefined}
        description="Payroll prices each run from this history, so removing an entry changes what future runs would pay for the periods it covered."
        isPending={removeMut.isPending}
        onConfirm={async () => {
          if (!removing) return;
          try {
            await removeMut.mutateAsync(removing.id);
            toast.success("Entry deleted");
            setRemoving(null);
          } catch (err) {
            // The refusals here are reasoned and long ("this entry priced 3 completed runs…").
            // They go in their own dialog rather than a toast that disappears mid-read.
            setBlocked(fieldError(err).message);
            setRemoving(null);
          }
        }}
      />

      <Dialog open={blocked !== null} onOpenChange={(o) => !o && setBlocked(null)}>
        <DialogContent className="max-w-[calc(100%-3rem)] sm:max-w-[460px]">
          <DialogHeader>
            <DialogTitle>This entry cannot be deleted</DialogTitle>
          </DialogHeader>
          <div className="flex items-start gap-2 rounded-lg border border-warning bg-warning-subtle p-3">
            <AlertTriangle className="mt-0.5 size-4 shrink-0 text-warning" aria-hidden />
            <p className="text-xs text-foreground">{blocked}</p>
          </div>
          <DialogFooter>
            <Button type="button" onClick={() => setBlocked(null)}>
              Understood
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </>
  );
}

/**
 * Add or correct one compensation entry.
 *
 * The two-step confirm is the FIN-007 pattern: send once, and if the server comes back saying the
 * date prices completed runs, show what it said and offer to resend with the acknowledgement. HR
 * therefore never confirms in the abstract — the periods are named before they agree to anything.
 */
function SalaryHistoryDialog({
  employeeId,
  entry,
  onClose,
}: {
  employeeId: number;
  entry: SalaryHistoryEntry | null;
  onClose: () => void;
}) {
  const [salary, setSalary] = useState(entry ? String(entry.base_salary) : "");
  const [effectiveDate, setEffectiveDate] = useState(entry?.effective_date ?? "");
  const [changeType, setChangeType] = useState(entry?.change_type ?? "merit");
  const [reason, setReason] = useState(entry?.reason ?? "");
  const [error, setError] = useState<string | null>(null);
  /** Set when the server asked the repricing question — the text names the affected runs. */
  const [confirmRepricing, setConfirmRepricing] = useState<string | null>(null);

  const createMut = useCreateSalaryHistory(employeeId);
  const updateMut = useUpdateSalaryHistory();
  const pending = createMut.isPending || updateMut.isPending;

  async function submit(acknowledge = false) {
    setError(null);

    const amount = Number(salary);
    if (!salary.trim() || !Number.isFinite(amount) || amount < 0) {
      return setError("Enter the monthly salary as a number.");
    }
    if (!effectiveDate) return setError("Pick the date this takes effect.");

    const input = {
      base_salary: amount,
      effective_date: effectiveDate,
      change_type: changeType ?? "adjustment",
      reason: reason.trim() || null,
      ...(acknowledge ? { acknowledge_repricing: true } : {}),
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
      const { field, message } = fieldError(err);
      // A 422 on the date is a QUESTION, not a failure — the same shape the record's own
      // backdated-salary save uses. Anything else is a plain error.
      if (field === "effective_date" && !acknowledge) {
        setConfirmRepricing(message);
        return;
      }
      setError(message);
    }
  }

  return (
    <>
      <Dialog open onOpenChange={(o) => !o && onClose()}>
        <DialogContent className="max-h-[90svh] max-w-[calc(100%-3rem)] overflow-y-auto sm:max-w-[460px]">
          <DialogHeader>
            <DialogTitle>{entry ? "Edit compensation entry" : "Add compensation entry"}</DialogTitle>
            <DialogDescription>
              Entries are recorded automatically when the base salary changes. Use this to correct or
              backfill one.
            </DialogDescription>
          </DialogHeader>

          <div className="space-y-4">
            <div className="space-y-1.5">
              <Label htmlFor="sh-salary">Monthly base salary</Label>
              <Input
                id="sh-salary"
                type="number"
                min="0"
                step="0.01"
                value={salary}
                onChange={(e) => setSalary(e.target.value)}
                placeholder="30000"
              />
              <p className="text-xs text-muted-foreground">
                Monthly, not annual — the table above annualises it for display.
              </p>
            </div>

            <div className="space-y-1.5">
              <Label htmlFor="sh-date">Effective from</Label>
              <DatePicker
                id="sh-date"
                value={effectiveDate}
                onChange={setEffectiveDate}
                className="w-full"
              />
              <p className="text-xs text-muted-foreground">
                Payroll pays this figure for every cycle ending on or after this date, until a later
                entry takes over.
              </p>
            </div>

            <div className="space-y-1.5">
              <Label htmlFor="sh-type">Reason</Label>
              <SimpleSelect
                value={changeType ?? "merit"}
                onValueChange={setChangeType}
                options={CHANGE_TYPES.map((t) => ({ value: t.value, label: t.label }))}
              />
            </div>

            <div className="space-y-1.5">
              <Label htmlFor="sh-reason">Note (optional)</Label>
              <Input
                id="sh-reason"
                value={reason}
                onChange={(e) => setReason(e.target.value)}
                placeholder="e.g. Annual merit review"
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

      {/* Step two: the server named the periods this would reprice, so HR confirms knowing them. */}
      <Dialog open={confirmRepricing !== null} onOpenChange={(o) => !o && setConfirmRepricing(null)}>
        <DialogContent className="max-w-[calc(100%-3rem)] sm:max-w-[460px]">
          <DialogHeader>
            <DialogTitle>This period was already paid</DialogTitle>
          </DialogHeader>
          <div className="flex items-start gap-2 rounded-lg border border-warning bg-warning-subtle p-3">
            <AlertTriangle className="mt-0.5 size-4 shrink-0 text-warning" aria-hidden />
            <p className="text-xs text-foreground">{confirmRepricing}</p>
          </div>
          <DialogFooter>
            <Button
              type="button"
              variant="ghost"
              onClick={() => setConfirmRepricing(null)}
              disabled={pending}
            >
              Cancel
            </Button>
            <Button
              type="button"
              disabled={pending}
              onClick={async () => {
                setConfirmRepricing(null);
                await submit(true);
              }}
            >
              {pending ? "Saving…" : "Record it anyway"}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </>
  );
}
