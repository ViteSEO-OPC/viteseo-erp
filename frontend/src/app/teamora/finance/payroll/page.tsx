"use client";

import { useState } from "react";
import { PlayCircle, Zap, Eye, EyeOff, Check, History, Gift, FileDown, Lock, LockOpen } from "lucide-react";
import { notify as toast } from "@/core/lib/notify";
import { AxiosError } from "axios";

import { Button } from "@/components/ui/button";
import {
  Dialog,
  DialogContent,
  DialogHeader,
  DialogTitle,
  DialogDescription,
  DialogFooter,
  DialogClose,
} from "@/components/ui/dialog";
import { cn } from "@/lib/utils";
import { PageHeader } from "@/modules/hr/components/page-header";
import { RunRulesSnapshot } from "@/modules/hr/components/run-rules-snapshot";
import { StatCard } from "@/core/components/stat-card";
import { StatusBadge } from "@/core/components/status-badge";
import { DataTable, type Column } from "@/core/components/data-table";
import { formatPeso, formatDate } from "@/core/lib/format";
import { downloadHrPayslipPdf, downloadPayrollRunCsv } from "@/modules/hr/api/payroll";
import {
  usePayrollRuns,
  usePayrollRun,
  useSetPayrollRunLock,
  useRunPayroll,
  useMarkPayslipDone,
  usePayrollSettings,
  useUpdatePayrollSettings,
  useThirteenthMonth,
} from "@/modules/hr/hooks/use-payroll";
import type {
  PayrollBreakdownRow,
  PayrollRun,
  ThirteenthMonthRow,
} from "@/modules/hr/types/payroll";
import { PAY_FREQUENCIES, payFrequencyLabel } from "@/modules/hr/types/employee";
import { SimpleSelect } from "@/modules/hr/ui/select";

const TIMING_OPTIONS = [
  { value: "every", label: "Every cutoff (split)" },
  { value: "first", label: "First cutoff only" },
  { value: "last", label: "Last cutoff only" },
];

export default function PayrollPage() {
  const { data: settings } = usePayrollSettings();
  const updateSettingsMut = useUpdatePayrollSettings();
  const { data: runs } = usePayrollRuns();
  const runMut = useRunPayroll();
  const markDoneMut = useMarkPayslipDone();
  const { data: thirteenth } = useThirteenthMonth();

  const [confirmOpen, setConfirmOpen] = useState(false);
  const [historyOpen, setHistoryOpen] = useState(false);
  const [thirteenthOpen, setThirteenthOpen] = useState(false);
  const [viewRunId, setViewRunId] = useState<number | null>(null);
  const [masked, setMasked] = useState(false);
  const [selectedFreq, setSelectedFreq] = useState<string>();

  const peso = (v: number) => (masked ? "₱ ••••" : formatPeso(v));

  // Pay groups (per-employee frequencies). HR runs one group at a time.
  const payGroups = settings?.pay_groups ?? [];
  const activeGroup = payGroups.find((g) => g.frequency === selectedFreq) ?? payGroups[0];
  const period = activeGroup?.current_period;
  const cycle =
    activeGroup && period
      ? { frequency: activeGroup.frequency, cycle_start: period.start, cycle_end: period.end }
      : null;

  async function saveSettings(patch: { frequency?: string; deduction_timing?: string; tax_timing?: string }) {
    if (!settings) return;
    try {
      await updateSettingsMut.mutateAsync({
        frequency: settings.frequency,
        deduction_timing: settings.deduction_timing,
        tax_timing: settings.tax_timing,
        ...patch,
      });
      toast.success("Payroll settings updated");
    } catch {
      toast.error("Could not update payroll settings.");
    }
  }

  async function onMarkDone(row: PayrollBreakdownRow) {
    try {
      await markDoneMut.mutateAsync(row.id);
      toast.success(`${row.employee} — marked disbursed`);
    } catch {
      toast.error("Could not update the payslip.");
    }
  }

  async function onPayslipPdf(id: number) {
    try {
      await downloadHrPayslipPdf(id);
    } catch {
      toast.error("Could not download the payslip PDF.");
    }
  }

  async function onExportCsv(runId: number) {
    try {
      await downloadPayrollRunCsv(runId);
    } catch {
      toast.error("Could not export the run CSV.");
    }
  }

  // Has THIS group's current period been run yet?
  const currentRun = cycle
    ? runs?.find(
        (r) =>
          r.frequency === cycle.frequency &&
          r.cycle_start === cycle.cycle_start &&
          r.cycle_end === cycle.cycle_end,
      )
    : undefined;
  const alreadyRun = !!currentRun;
  const { data: detail } = usePayrollRun(currentRun?.id);
  // Slice 5 — a re-run skips these, and a run can be locked once they are all disbursed.
  const disbursedCount =
    detail?.breakdown?.filter((r) => r.status === "disbursed").length ?? 0;
  const lockMut = useSetPayrollRunLock();
  const history = (runs ?? []).filter((r) => r.id !== currentRun?.id);
  // A past run selected from the history modal → its per-employee breakdown.
  const { data: viewRun } = usePayrollRun(viewRunId ?? undefined);

  async function onRun() {
    if (!cycle) return;
    try {
      await runMut.mutateAsync(cycle);
      toast.success("Payroll computed — payslips generated");
      setConfirmOpen(false);
    } catch (err) {
      const msg =
        err instanceof AxiosError
          ? (err.response?.data?.errors?.cycle_start?.[0] ??
            err.response?.data?.message ??
            "Payroll run failed.")
          : "Payroll run failed.";
      toast.error(msg);
    }
  }

  const columns: Column<PayrollBreakdownRow>[] = [
    { header: "Employee", cell: (r) => <span className="font-semibold text-foreground">{r.employee}</span> },
    { header: "Department", cell: (r) => r.department ?? "—" },
    { header: "Gross", cell: (r) => peso(r.gross) },
    { header: "Deductions", cell: (r) => <span className="text-danger">−{peso(r.deductions)}</span> },
    { header: "Net Pay", cell: (r) => <span className="font-semibold">{peso(r.net)}</span> },
    { header: "Status", cell: (r) => <StatusBadge status={r.status} /> },
    {
      header: "Actions",
      align: "right",
      cell: (r) => (
        <div className="flex items-center justify-end gap-2">
          <Button
            size="xs"
            variant="ghost"
            title="Download payslip PDF"
            onClick={() => onPayslipPdf(r.id)}
          >
            <FileDown className="size-3.5" />
            <span className="sr-only">Download payslip PDF</span>
          </Button>
          {r.status === "disbursed" ? (
            <span className="inline-flex items-center gap-1 text-caption font-medium text-success">
              <Check className="size-3.5" /> Disbursed
            </span>
          ) : (
            <Button size="xs" variant="outline" disabled={markDoneMut.isPending} onClick={() => onMarkDone(r)}>
              Mark as disbursed
            </Button>
          )}
        </div>
      ),
    },
  ];

  const historyColumns: Column<PayrollRun>[] = [
    {
      header: "Cycle",
      cell: (r) => (
        <span className="font-medium text-foreground">
          {formatDate(r.cycle_start)} – {formatDate(r.cycle_end)}
        </span>
      ),
    },
    { header: "Schedule", cell: (r) => payFrequencyLabel(r.frequency) },
    { header: "Employees", cell: (r) => r.employee_count },
    { header: "Net", cell: (r) => peso(r.total_net) },
    { header: "Status", align: "right", cell: () => <StatusBadge status="completed" label="Completed" /> },
  ];

  const thirteenthColumns: Column<ThirteenthMonthRow>[] = [
    { header: "Employee", cell: (r) => <span className="font-semibold text-foreground">{r.employee ?? "—"}</span> },
    { header: "Department", cell: (r) => r.department ?? "—" },
    { header: "Periods paid", cell: (r) => r.periods },
    { header: "YTD Basic", cell: (r) => peso(r.ytd_basic) },
    {
      header: "13th-Month",
      align: "right",
      cell: (r) => <span className="font-semibold text-brand">{peso(r.thirteenth_month)}</span>,
    },
  ];

  const thirteenthTotal = (thirteenth?.rows ?? []).reduce((s, r) => s + r.thirteenth_month, 0);

  return (
    <div className="p-4 sm:p-8">
      <PageHeader
        title="Payroll"
        subtitle={
          activeGroup && period
            ? `${payFrequencyLabel(activeGroup.frequency)} · ${formatDate(period.start)} – ${formatDate(period.end)} · pay date ${formatDate(period.pay_date)}`
            : "Loading pay schedule…"
        }
        actions={
          <div className="flex items-center gap-2">
            <Button
              variant="outline"
              size="icon-sm"
              onClick={() => setMasked((m) => !m)}
              title={masked ? "Show amounts" : "Hide amounts"}
            >
              {masked ? <EyeOff /> : <Eye />}
              <span className="sr-only">{masked ? "Show amounts" : "Hide amounts"}</span>
            </Button>
            <Button variant="outline" onClick={() => setThirteenthOpen(true)}>
              <Gift />
              13th-month
            </Button>
            <Button variant="outline" onClick={() => setHistoryOpen(true)}>
              <History />
              Show run history
              {history.length > 0 && (
                <span className="ml-0.5 rounded-full bg-muted px-1.5 text-caption font-semibold text-muted-foreground">
                  {history.length}
                </span>
              )}
            </Button>
            {/* Slice 5 — seal the cycle once every payslip is paid out. */}
            {detail?.can_lock && (
              <Button
                variant="outline"
                disabled={lockMut.isPending}
                title="Seal this cycle so it can never be recomputed"
                onClick={() => lockMut.mutate({ id: detail.id, lock: true })}
              >
                <Lock />
                {lockMut.isPending ? "Locking…" : "Lock this run"}
              </Button>
            )}
            {detail?.is_locked && (
              <Button
                variant="outline"
                disabled={lockMut.isPending}
                title="Re-open this cycle so it can be recomputed (audit-logged)"
                onClick={() => lockMut.mutate({ id: detail.id, lock: false })}
              >
                <LockOpen />
                {lockMut.isPending ? "Unlocking…" : "Unlock"}
              </Button>
            )}
            <Button
              className="bg-warning text-white hover:bg-warning/90 disabled:opacity-50"
              disabled={!cycle || detail?.is_locked}
              title={
                detail?.is_locked
                  ? "This run is locked — unlock it first"
                  : alreadyRun
                    ? "Recompute this period with the latest data"
                    : undefined
              }
              onClick={() => setConfirmOpen(true)}
            >
              <PlayCircle />
              {alreadyRun ? "Re-run / refresh" : "Run this pay group"}
            </Button>
          </div>
        }
      />

      <div className="mt-6 flex items-start gap-3 rounded-card border border-warning/40 bg-warning-subtle px-4 py-3">
        <Zap className="mt-0.5 size-5 shrink-0 text-warning" fill="currentColor" />
        <p className="text-sm text-foreground">
          <span className="font-semibold">Computation only — no money moves.</span>{" "}
          Running a pay group calculates gross, PH deductions and net for its employees
          and generates payslips so HR can disburse manually.
        </p>
      </div>

      {/* Payroll settings — company default schedule + deduction/tax timing. */}
      <div className="mt-6 grid grid-cols-1 gap-4 rounded-card border border-border bg-card p-4 sm:grid-cols-3">
        <div className="space-y-1.5">
          <p className="text-label font-bold uppercase tracking-wide text-muted-foreground">Default pay schedule</p>
          <SimpleSelect
            value={settings?.frequency ?? "semi_monthly"}
            disabled={!settings || updateSettingsMut.isPending}
            onValueChange={(v) => saveSettings({ frequency: v })}
            options={PAY_FREQUENCIES.map((f) => ({ value: f.value, label: f.label }))}
          />
          <p className="text-caption text-muted-foreground">Used for employees without their own frequency.</p>
        </div>
        <div className="space-y-1.5">
          <p className="text-label font-bold uppercase tracking-wide text-muted-foreground">Government contributions</p>
          <SimpleSelect
            value={settings?.deduction_timing ?? "every"}
            disabled={!settings || updateSettingsMut.isPending}
            onValueChange={(v) => saveSettings({ deduction_timing: v })}
            options={TIMING_OPTIONS.map((t) => ({ value: t.value, label: t.label }))}
          />
          <p className="text-caption text-muted-foreground">When SSS / PhilHealth / Pag-IBIG are deducted.</p>
        </div>
        <div className="space-y-1.5">
          <p className="text-label font-bold uppercase tracking-wide text-muted-foreground">Withholding tax</p>
          <SimpleSelect
            value={settings?.tax_timing ?? "every"}
            disabled={!settings || updateSettingsMut.isPending}
            onValueChange={(v) => saveSettings({ tax_timing: v })}
            options={TIMING_OPTIONS.map((t) => ({ value: t.value, label: t.label }))}
          />
          <p className="text-caption text-muted-foreground">When income tax is withheld.</p>
        </div>
      </div>

      {/* Pay group selector */}
      {payGroups.length > 0 && (
        <div className="mt-6 flex flex-wrap items-center gap-2">
          <span className="text-sm font-medium text-foreground">Pay group:</span>
          {payGroups.map((g) => (
            <button
              key={g.frequency}
              type="button"
              onClick={() => setSelectedFreq(g.frequency)}
              className={cn(
                "rounded-full border px-3 py-1 text-sm font-medium transition-colors",
                g.frequency === activeGroup?.frequency
                  ? "border-brand bg-brand text-brand-foreground"
                  : "border-input text-muted-foreground hover:bg-muted",
              )}
            >
              {payFrequencyLabel(g.frequency)} · {g.employee_count}
            </button>
          ))}
        </div>
      )}

      {/* Current period for the selected group — blank until run */}
      <div className="mt-6 grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <StatCard label="Total Gross" value={currentRun ? peso(currentRun.total_gross) : "—"} highlight />
        <StatCard label="Total Deductions" value={currentRun ? peso(currentRun.total_deductions) : "—"} />
        <StatCard label="Net Payout" value={currentRun ? peso(currentRun.total_net) : "—"} />
        <StatCard
          label="This Period"
          value={alreadyRun ? "Computed" : "Not run yet"}
          subNote={
            alreadyRun
              ? `${currentRun?.employee_count} employees · ${formatDate(currentRun?.run_at)}`
              : `${activeGroup?.employee_count ?? 0} employees in this group`
          }
        />
      </div>

      {/* Breakdown OR the not-run prompt */}
      <div className="mt-6">
        <div className="mb-3 flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
          <h2 className="text-h3 font-bold text-foreground">Payroll Breakdown</h2>
          {currentRun && detail?.breakdown && (
            <Button variant="outline" size="sm" onClick={() => onExportCsv(currentRun.id)}>
              <FileDown />
              Export CSV
            </Button>
          )}
        </div>
        {detail?.breakdown ? (
          <>
            <DataTable columns={columns} rows={detail.breakdown} rowKey={(_r, i) => i} />
            {/* Slice 8 — what these amounts were computed with. */}
            <RunRulesSnapshot snapshot={detail.policy_snapshot} />
          </>
        ) : (
          <div className="flex flex-col items-center gap-2 rounded-card border border-dashed border-border bg-card py-16 text-center">
            <PlayCircle className="size-8 text-muted-foreground" />
            <p className="text-sm font-medium text-foreground">This period hasn’t been run yet.</p>
            <p className="max-w-sm text-caption text-muted-foreground">
              Click “Run this pay group” to compute deductions and net pay for its{" "}
              {activeGroup?.employee_count ?? ""} employees.
            </p>
          </div>
        )}
      </div>

      {/* Run history — opened from the header button */}
      <Dialog open={historyOpen} onOpenChange={setHistoryOpen}>
        <DialogContent className="max-h-[90svh] max-w-[calc(100%-3rem)] overflow-y-auto sm:max-w-2xl">
          <DialogHeader>
            <DialogTitle>Run History</DialogTitle>
            <DialogDescription>
              Past payroll runs across all pay groups, most recent first. Select a run to
              see its per-employee breakdown.
            </DialogDescription>
          </DialogHeader>
          {history.length > 0 ? (
            <div className="max-h-[60vh] overflow-y-auto">
              <DataTable
                columns={historyColumns}
                rows={history}
                rowKey={(r) => r.id}
                onRowClick={(r) => setViewRunId(r.id)}
              />
            </div>
          ) : (
            <div className="flex flex-col items-center gap-2 py-10 text-center">
              <History className="size-8 text-muted-foreground" />
              <p className="text-sm font-medium text-foreground">No past runs yet.</p>
              <p className="max-w-xs text-caption text-muted-foreground">
                Completed pay cycles from other periods will appear here.
              </p>
            </div>
          )}
          <DialogFooter>
            <DialogClose asChild>
              <Button variant="outline">Close</Button>
            </DialogClose>
          </DialogFooter>
        </DialogContent>
      </Dialog>

      {/* Past-run breakdown — drilled into from the Run History modal */}
      <Dialog open={viewRunId != null} onOpenChange={(o) => !o && setViewRunId(null)}>
        <DialogContent className="max-h-[90svh] max-w-[calc(100%-3rem)] overflow-y-auto sm:max-w-4xl">
          <DialogHeader>
            <DialogTitle>
              {viewRun
                ? `Run · ${formatDate(viewRun.cycle_start)} – ${formatDate(viewRun.cycle_end)}`
                : "Run breakdown"}
            </DialogTitle>
            <DialogDescription>
              {viewRun
                ? `${payFrequencyLabel(viewRun.frequency)} · ${viewRun.employee_count} employees · run ${formatDate(viewRun.run_at)}${viewRun.run_by ? ` by ${viewRun.run_by}` : ""}`
                : "Loading…"}
            </DialogDescription>
          </DialogHeader>
          {viewRun?.breakdown ? (
            <>
              <div className="grid grid-cols-1 gap-3 sm:grid-cols-3">
                <StatCard label="Total Gross" value={peso(viewRun.total_gross)} highlight />
                <StatCard label="Total Deductions" value={peso(viewRun.total_deductions)} />
                <StatCard label="Net Payout" value={peso(viewRun.total_net)} />
              </div>
              <div className="max-h-[55vh] overflow-auto">
                <DataTable columns={columns} rows={viewRun.breakdown} rowKey={(_r, i) => i} />
              </div>
            </>
          ) : (
            <p className="py-10 text-center text-sm text-muted-foreground">Loading breakdown…</p>
          )}
          <DialogFooter>
            {viewRun && (
              <Button variant="outline" onClick={() => onExportCsv(viewRun.id)}>
                <FileDown />
                Export CSV
              </Button>
            )}
            <DialogClose asChild>
              <Button variant="outline">Close</Button>
            </DialogClose>
          </DialogFooter>
        </DialogContent>
      </Dialog>

      {/* 13th-month accrual — opened from the header button */}
      <Dialog open={thirteenthOpen} onOpenChange={setThirteenthOpen}>
        <DialogContent className="max-h-[90svh] max-w-[calc(100%-3rem)] overflow-y-auto sm:max-w-2xl">
          <DialogHeader>
            <DialogTitle>13th-Month Pay{thirteenth ? ` · ${thirteenth.year}` : ""}</DialogTitle>
            <DialogDescription>
              Accrued per employee — total basic salary earned this year ÷ 12. Projected;
              finalizes at year-end.
            </DialogDescription>
          </DialogHeader>
          {(thirteenth?.rows ?? []).length > 0 ? (
            <>
              <div className="flex items-center justify-between rounded-lg border border-brand/30 bg-brand-subtle px-4 py-2.5">
                <span className="text-sm font-medium text-foreground">Total accrued</span>
                <span className="text-lg font-bold text-brand">{peso(thirteenthTotal)}</span>
              </div>
              <div className="max-h-[55vh] overflow-y-auto">
                <DataTable
                  columns={thirteenthColumns}
                  rows={thirteenth?.rows ?? []}
                  rowKey={(r) => r.employee_id}
                />
              </div>
            </>
          ) : (
            <div className="flex flex-col items-center gap-2 py-10 text-center">
              <Gift className="size-8 text-muted-foreground" />
              <p className="text-sm font-medium text-foreground">Nothing accrued yet.</p>
              <p className="max-w-xs text-caption text-muted-foreground">
                Run payroll for this year and each employee&apos;s 13th-month accrual will
                build up here.
              </p>
            </div>
          )}
          <DialogFooter>
            <DialogClose asChild>
              <Button variant="outline">Close</Button>
            </DialogClose>
          </DialogFooter>
        </DialogContent>
      </Dialog>

      <Dialog open={confirmOpen} onOpenChange={setConfirmOpen}>
        <DialogContent className="max-h-[90svh] max-w-[calc(100%-3rem)] overflow-y-auto sm:max-w-lg">
          <DialogHeader>
            <DialogTitle>{alreadyRun ? "Re-run this pay group?" : "Run this pay group?"}</DialogTitle>
            <DialogDescription>
              This {alreadyRun ? "recomputes" : "computes"} payslips for the{" "}
              {activeGroup ? payFrequencyLabel(activeGroup.frequency).toLowerCase() : ""} group
              {period ? ` (${formatDate(period.start)} – ${formatDate(period.end)})` : ""} with the
              latest salaries, attendance and reimbursements. It only computes — no payments are sent.
            </DialogDescription>
          </DialogHeader>
          {/* Slice 5: be explicit that paid-out payslips are left alone. */}
          {disbursedCount > 0 && (
            <p className="rounded-card border border-border bg-muted/40 p-3 text-sm text-muted-foreground">
              <span className="font-medium text-foreground">
                {disbursedCount} already-disbursed payslip{disbursedCount === 1 ? "" : "s"}
              </span>{" "}
              will be skipped — money already paid out is never recomputed.
            </p>
          )}
          <DialogFooter>
            <DialogClose asChild>
              <Button variant="outline">Cancel</Button>
            </DialogClose>
            <Button
              className="bg-warning text-white hover:bg-warning/90"
              disabled={runMut.isPending}
              onClick={onRun}
            >
              {runMut.isPending ? "Running…" : "Run payroll"}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </div>
  );
}
