"use client";

import { useState } from "react";
import { AxiosError } from "axios";
import { RefreshCw } from "lucide-react";

import { Button } from "@/components/ui/button";
import {
  Dialog,
  DialogContent,
  DialogHeader,
  DialogTitle,
  DialogDescription,
  DialogFooter,
} from "@/components/ui/dialog";
import { Input } from "@/components/ui/input";
import { notify as toast } from "@/core/lib/notify";
import { formatDate, formatPeso } from "@/core/lib/format";
import { DataTable, type Column } from "@/core/components/data-table";
import { useRerunPayrollRun, useRerunPreview } from "@/modules/hr/hooks/use-payroll";
import { payFrequencyLabel } from "@/modules/hr/types/employee";
import type { PayrollRun, RerunPreviewRow } from "@/modules/hr/types/payroll";

/** The word for each kind of row, and the colour it earns. */
const CHANGE_LABELS: Record<RerunPreviewRow["change"], { label: string; className: string }> = {
  changed: { label: "Changes", className: "text-warning" },
  added: { label: "New payslip", className: "text-brand" },
  removed: { label: "Payslip removed", className: "text-danger" },
  skipped: { label: "Skipped — paid", className: "text-muted-foreground" },
  unchanged: { label: "No change", className: "text-muted-foreground" },
};

/** Recompute a period that has already been run — after saying what that would do. */
export function RerunRunDialog({
  run,
  open,
  onOpenChange,
}: {
  /** The period to recompute. Null closes the dialog and stops the preview being fetched. */
  run: PayrollRun | null;
  open: boolean;
  onOpenChange: (open: boolean) => void;
}) {
  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="max-h-[90svh] max-w-[calc(100%-3rem)] overflow-y-auto sm:max-w-3xl">
        <DialogHeader>
          <DialogTitle>
            {run
              ? `Recompute ${formatDate(run.cycle_start)} – ${formatDate(run.cycle_end)}`
              : "Recompute this period"}
          </DialogTitle>
          <DialogDescription>
            {run
              ? `${payFrequencyLabel(run.frequency)} · run ${formatDate(run.run_at)}. This reprices the period from attendance, salaries and the rules in force for the cycle. Payslips already paid out are left alone.`
              : "Loading…"}
          </DialogDescription>
        </DialogHeader>

        {/*
         * Keyed on the run so switching periods without closing starts a fresh draft rather than
         * carrying a reason typed for a different cycle.
         */}
        {run && <RerunBody key={run.id} run={run} open={open} onClose={() => onOpenChange(false)} />}
      </DialogContent>
    </Dialog>
  );
}

function RerunBody({
  run,
  open,
  onClose,
}: {
  run: PayrollRun;
  open: boolean;
  onClose: () => void;
}) {
  const [reason, setReason] = useState("");
  const { data: preview, isLoading, error } = useRerunPreview(run.id, open);
  const rerunMut = useRerunPayrollRun();

  const meta = preview?.meta;
  const rows = preview?.rows ?? [];
  const moving = (meta?.changed ?? 0) + (meta?.added ?? 0) + (meta?.removed ?? 0);
  const canSubmit = reason.trim().length >= 5 && !!preview && !rerunMut.isPending;

  const columns: Column<RerunPreviewRow>[] = [
    {
      header: "Employee",
      cell: (r) => (
        <span className="font-semibold text-foreground">{r.employee ?? `#${r.employee_id}`}</span>
      ),
    },
    { header: "Net now", cell: (r) => (r.before ? formatPeso(r.before.net) : "—") },
    { header: "Net after", cell: (r) => (r.after ? formatPeso(r.after.net) : "—") },
    {
      header: "Difference",
      cell: (r) =>
        r.delta === 0 ? (
          <span className="text-muted-foreground">—</span>
        ) : (
          <span className={r.delta > 0 ? "font-semibold text-success" : "font-semibold text-danger"}>
            {r.delta > 0 ? "+" : "−"}
            {formatPeso(Math.abs(r.delta))}
          </span>
        ),
    },
    {
      header: "",
      align: "right",
      cell: (r) => (
        <span className={`text-caption ${CHANGE_LABELS[r.change].className}`}>
          {CHANGE_LABELS[r.change].label}
        </span>
      ),
    },
  ];

  async function submit() {
    try {
      await rerunMut.mutateAsync({ runId: run.id, reason: reason.trim() });
      toast.success(
        moving > 0
          ? `Period recomputed — ${moving} payslip(s) changed`
          : "Period recomputed — no figures moved",
      );
      onClose();
    } catch (e) {
      const err = e as AxiosError<{ message?: string; errors?: Record<string, string[]> }>;
      const first = Object.values(err.response?.data?.errors ?? {})[0]?.[0];
      toast.error(first ?? err.response?.data?.message ?? "Could not recompute the period");
    }
  }

  if (error) {
    const err = error as AxiosError<{ message?: string; errors?: Record<string, string[]> }>;
    const message =
      Object.values(err.response?.data?.errors ?? {})[0]?.[0] ??
      err.response?.data?.message ??
      "Could not work out what recomputing would change.";

    return (
      <>
        <p className="py-8 text-center text-sm text-muted-foreground">{message}</p>
        <DialogFooter>
          <Button variant="outline" onClick={onClose}>
            Close
          </Button>
        </DialogFooter>
      </>
    );
  }

  return (
    <>
      <div className="space-y-4">
        {isLoading ? (
          // A real payroll computation is happening server-side, so this is seconds rather than
          // milliseconds — the wait is named instead of shown as an anonymous spinner.
          <p className="flex items-center justify-center gap-2 py-10 text-sm text-muted-foreground">
            <RefreshCw className="size-4 animate-spin" />
            Computing what would change…
          </p>
        ) : (
          meta && (
            <>
              <div className="flex flex-wrap items-baseline justify-between gap-3 rounded-card border border-border bg-muted/40 px-4 py-3">
                <div>
                  <p className="text-sm font-medium text-foreground">
                    {moving === 0
                      ? "Nothing would change"
                      : `${moving} payslip${moving === 1 ? "" : "s"} would change`}
                  </p>
                  <p className="text-caption text-muted-foreground">
                    {meta.skipped > 0 &&
                      `${meta.skipped} already paid and left alone · `}
                    {meta.unchanged} unchanged
                    {meta.added > 0 && ` · ${meta.added} new`}
                    {meta.removed > 0 && ` · ${meta.removed} removed`}
                  </p>
                </div>
                <div className="text-right">
                  <p className="font-mono text-sm font-semibold tabular-nums text-foreground">
                    {formatPeso(meta.net_before)} → {formatPeso(meta.net_after)}
                  </p>
                  <p
                    className={`text-caption ${meta.delta === 0 ? "text-muted-foreground" : meta.delta > 0 ? "text-success" : "text-danger"}`}
                  >
                    {meta.delta === 0
                      ? "same total net"
                      : `${meta.delta > 0 ? "+" : "−"}${formatPeso(Math.abs(meta.delta))} total net`}
                  </p>
                </div>
              </div>

              {/* The second reason to recompute, and the only one the figures cannot show. */}
              {meta.clears_tax_stale && (
                <p className="rounded-card border border-warning/40 bg-warning/10 px-4 py-2 text-caption text-muted-foreground">
                  This also clears the out-of-date tax mark on this run — its withholding was settled
                  against year-to-date figures that have since moved.
                </p>
              )}

              <div className="max-h-[40vh] overflow-auto">
                <DataTable
                  columns={columns}
                  rows={rows}
                  rowKey={(r) => r.employee_id}
                  pageSize={0}
                  searchable={false}
                  filterable={false}
                  empty="This period has no payslips."
                />
              </div>
            </>
          )
        )}

        <div className="space-y-1.5">
          <label htmlFor="rerun-reason" className="text-caption font-medium">
            Why is this being recomputed?{" "}
            <span className="text-muted-foreground">(required — it goes on the record)</span>
          </label>
          <Input
            id="rerun-reason"
            value={reason}
            onChange={(e) => setReason(e.target.value)}
            placeholder="e.g. August attendance was corrected after the cutoff"
          />
        </div>
      </div>

      <DialogFooter>
        <Button variant="outline" onClick={onClose}>
          Cancel
        </Button>
        <Button
          className="bg-warning text-white hover:bg-warning/90"
          disabled={!canSubmit}
          onClick={submit}
        >
          {rerunMut.isPending ? "Recomputing…" : "Recompute this period"}
        </Button>
      </DialogFooter>
    </>
  );
}
