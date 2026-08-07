"use client";

import Link from "next/link";

import { useState } from "react";
import { PlayCircle, Zap, Eye, EyeOff, Check, History, Gift, FileDown, Lock, LockOpen, Wrench, AlertTriangle, Banknote, Percent, BookOpen } from "lucide-react";
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
import { PayslipDocument } from "@/modules/hr/components/payslip-document";
import { RunRulesSnapshot } from "@/modules/hr/components/run-rules-snapshot";
import { ReopenAction } from "@/modules/hr/components/reopen-action";
import { AdjustmentRunDialog } from "@/modules/hr/components/adjustment-run-dialog";
import { RetroFlagsDialog } from "@/modules/hr/components/retro-flags-dialog";
import { TaxTableDialog } from "@/modules/hr/components/tax-table-dialog";
import { JournalEntryDialog } from "@/modules/hr/components/journal-entry-dialog";
import { PaymentBatchDialog } from "@/modules/hr/components/payment-batch-dialog";
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
  useReopenPayslip,
  usePayrollSettings,
  useUpdatePayrollSettings,
  useRetroFlags,
  useDeductionArrears,
  useThirteenthMonth,
  useThirteenthMonthPreview,
  useRunThirteenthMonth,
  useHrPayslip,
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
  const reopenPayslipMut = useReopenPayslip();
  const { data: thirteenth } = useThirteenthMonth();
  const runThirteenthMut = useRunThirteenthMonth();

  const [confirmOpen, setConfirmOpen] = useState(false);
  const [historyOpen, setHistoryOpen] = useState(false);
  const [thirteenthOpen, setThirteenthOpen] = useState(false);
  // FIN-010 / FIN-009 — off-cycle corrections and the queue of differences awaiting one.
  const [adjustmentOpen, setAdjustmentOpen] = useState(false);
  const [retroOpen, setRetroOpen] = useState(false);
  const [taxTableOpen, setTaxTableOpen] = useState(false);
  const [journalRunId, setJournalRunId] = useState<number | null>(null);
  const [payOpen, setPayOpen] = useState(false);
  const [adjustmentPrefill, setAdjustmentPrefill] = useState<{ employeeId: number | null; reason: string | null }>({
    employeeId: null,
    reason: null,
  });
  const { data: retroFlags = [] } = useRetroFlags();
  const { data: deductionArrears = [] } = useDeductionArrears();
  // FIN-009 + FIN-005 — one badge for everything payroll could not settle by itself. Two separate
  // counters would have HR checking two places for the same class of problem.
  const outstandingCount = retroFlags.length + deductionArrears.length;
  // FIN-025 — payroll settings are versioned, so a change needs a date. Blank means today, which
  // is what every change used to be recorded as whether or not that was true.
  const [settingsEffectiveFrom, setSettingsEffectiveFrom] = useState("");
  const [payThirteenthOpen, setPayThirteenthOpen] = useState(false);
  // Slice B17 — what the RUN would pay, which differs from the projection above whenever the year
  // is partial. Declared after `thirteenthOpen` because it reads it; fetched only while open.
  const { data: thirteenthPay } = useThirteenthMonthPreview(undefined, thirteenthOpen);
  const [viewRunId, setViewRunId] = useState<number | null>(null);
  // The payslip opened from a breakdown row — the same document the employee sees.
  const [payslipId, setPayslipId] = useState<number | null>(null);
  /**
   * FIN-043 — SHOULDER-SURFING PROTECTION, and the UI says so.
   *
   * This hides amounts in the RENDER only. The figures are already in the fetched payload, so
   * anyone with devtools sees them regardless — it is not an access control and must not be
   * presented as one. The audit downgraded it to informational for the right reason: the role
   * looking at this page is authorised to receive these amounts, so there is no boundary being
   * crossed. What was wrong was the eye icon implying confidentiality it does not provide.
   *
   * Omitting amounts server-side would be the real control, and belongs with the restricted-role
   * work in Slice 6 — not here, where it would only mean the same authorised person sees less.
   */
  const [masked, setMasked] = useState(false);
  const [selectedFreq, setSelectedFreq] = useState<string>();

  const { data: openPayslip } = useHrPayslip(payslipId ?? undefined);

  const peso = (v: number) => (masked ? "₱ ••••" : formatPeso(v));

  // Pay groups (per-employee frequencies). HR runs one group at a time.
  const payGroups = settings?.pay_groups ?? [];
  const activeGroup = payGroups.find((g) => g.frequency === selectedFreq) ?? payGroups[0];
  const period = activeGroup?.current_period;
  const cycle =
    activeGroup && period
      ? { frequency: activeGroup.frequency, cycle_start: period.start, cycle_end: period.end }
      : null;

  async function saveSettings(patch: {
    frequency?: string;
    deduction_timing?: string;
    tax_timing?: string;
    minimum_daily_wage?: number | null;
  }) {
    if (!settings) return;
    try {
      await updateSettingsMut.mutateAsync({
        frequency: settings.frequency,
        deduction_timing: settings.deduction_timing,
        tax_timing: settings.tax_timing,
        // FIN-025 — which cycles this change governs. Payroll resolves settings as of the cycle
        // end, so without a date every change silently starts today and can never be recorded as
        // having taken effect earlier.
        effective_from: settingsEffectiveFrom || undefined,
        ...patch,
      });
      toast.success(
        settingsEffectiveFrom
          ? `Payroll settings updated, effective ${formatDate(settingsEffectiveFrom)}`
          : "Payroll settings updated",
      );
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

  // Built per run, not once: the same table renders the current run and a past one from
  // the history modal, and whether a disbursement can be undone depends on that run's lock.
  const makeColumns = (locked: boolean): Column<PayrollBreakdownRow>[] => [
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
        // The row itself opens the payslip, so the controls inside it must not.
        <div
          className="flex items-center justify-end gap-2"
          onClick={(e) => e.stopPropagation()}
        >
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
            <>
              <span className="inline-flex items-center gap-1 text-caption font-medium text-success">
                <Check className="size-3.5" /> Disbursed
              </span>
              {locked ? null : (
                <ReopenAction
                  size="xs"
                  label="Undo"
                  title="Reverse this disbursement?"
                  confirmLabel="Reverse payslip"
                  successMessage="Disbursement reversed"
                  subject={`${r.employee}'s ${peso(r.net)} payslip`}
                  description={`${r.employee}'s ${peso(r.net)} payslip goes back to computed — it counts as unpaid again.`}
                  consequence="Any reimbursements it settled go back to scheduled, and the run can be recomputed."
                  onReopen={(reason) => reopenPayslipMut.mutateAsync({ id: r.id, reason })}
                />
              )}
            </>
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
              title={
                masked
                  ? "Show amounts"
                  : "Hide amounts on screen — for privacy from onlookers, not an access control"
              }
            >
              {masked ? <EyeOff /> : <Eye />}
              <span className="sr-only">{masked ? "Show amounts" : "Hide amounts"}</span>
            </Button>
            {/* FIN-009 — corrections owed. Badged, because an empty queue and an unseen queue
                look identical otherwise, and being unseen is what this replaced. */}
            <Button variant="outline" onClick={() => setRetroOpen(true)}>
              <AlertTriangle className={outstandingCount > 0 ? "text-warning" : undefined} />
              Outstanding
              {outstandingCount > 0 && (
                <span className="ml-0.5 rounded-full bg-warning px-1.5 text-caption font-semibold text-white">
                  {outstandingCount}
                </span>
              )}
            </Button>
            {/* FIN-010 — pay a correction without reopening a closed period. */}
            <Button
              variant="outline"
              onClick={() => {
                setAdjustmentPrefill({ employeeId: null, reason: null });
                setAdjustmentOpen(true);
              }}
            >
              <Wrench />
              Adjustment
            </Button>
            {/* FIN-015 — the disbursement layer. Only a completed run has anything to pay,
                and the dialog is where the bank file comes from. */}
            <Button
              variant="outline"
              onClick={() => setPayOpen(true)}
              disabled={!currentRun || currentRun.status !== "completed"}
              title={
                currentRun?.status === "completed"
                  ? "Build the payment batch and bank file for this run"
                  : "Run this period first — only a completed run can be paid"
              }
            >
              <Banknote />
              Pay run
            </Button>
            <Button variant="outline" onClick={() => setThirteenthOpen(true)}>
              <Gift />
              13th-month
            </Button>
            {/* FIN-027 — the tax bands are data so they can be checked; this is where you check them. */}
            <Button variant="outline" onClick={() => setTaxTableOpen(true)}>
              <Percent />
              Tax table
            </Button>
            {/* FIN-043 / FIN-017 — the chart of accounts and the two exports. Reached from here
                because `src/core/nav.ts` is a shared file: adding a sidebar entry for Accounting
                needs the CTO's sign-off, and the page must be reachable meanwhile. */}
            <Button variant="outline" asChild>
              <Link href="/teamora/finance/accounting">
                <BookOpen />
                Accounting
              </Link>
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

      {/* FIN-043 — visible while masking is on, because a tooltip nobody hovers is not a
          correction. Someone hiding amounts under the impression it is a security control needs to
          be told at the moment they are relying on it, not when they go looking. */}
      {masked && (
        <p className="mt-4 rounded-card border border-border bg-muted/40 px-4 py-2 text-caption text-muted-foreground">
          Amounts are hidden on screen only — for privacy from people nearby. They are still in the
          page data, so this is not an access control.
        </p>
      )}

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
        {/* FIN-025 — settings are dated. Payroll prices each period with the settings in force
            FOR that period, so a change made today does not reach back into cycles already run
            unless HR says it should. */}
        {/* FIN-030 — the regional minimum daily wage. Blank warns about nothing: the floor is
            regional and seeding a figure would be guessing at a legal threshold. */}
        <div className="space-y-1.5">
          <p className="text-label font-bold uppercase tracking-wide text-muted-foreground">
            Minimum daily wage
          </p>
          <input
            type="number"
            min="0"
            step="0.01"
            aria-label="Regional minimum daily wage"
            className="h-9 w-full rounded-md border border-input bg-transparent px-2 text-body"
            placeholder="Not set"
            defaultValue={settings?.minimum_daily_wage ?? ""}
            disabled={!settings || updateSettingsMut.isPending}
            onBlur={(e) => {
              const raw = e.target.value.trim();
              const next = raw === "" ? null : Number(raw);
              if (next !== (settings?.minimum_daily_wage ?? null)) {
                saveSettings({ minimum_daily_wage: next });
              }
            }}
          />
          <p className="text-caption text-muted-foreground">
            From your DOLE wage order. Leave blank to skip the check.
          </p>
        </div>
        <div className="space-y-1.5 sm:col-span-3">
          <p className="text-label font-bold uppercase tracking-wide text-muted-foreground">
            These settings take effect from
          </p>
          <input
            type="date"
            aria-label="Settings effective from"
            className="h-9 w-full max-w-xs rounded-md border border-input bg-transparent px-2 text-body sm:w-auto"
            value={settingsEffectiveFrom}
            onChange={(e) => setSettingsEffectiveFrom(e.target.value)}
          />
          <p className="text-caption text-muted-foreground">
            Leave blank for today. Set an earlier date only if the change genuinely applied then —
            periods already run keep the settings they were priced with.
          </p>
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
            <DataTable
              columns={makeColumns(!!detail.is_locked)}
              rows={detail.breakdown}
              rowKey={(_r, i) => i}
              onRowClick={(r) => setPayslipId(r.id)}
            />
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
                <DataTable
                  columns={makeColumns(!!viewRun.is_locked)}
                  rows={viewRun.breakdown}
                  rowKey={(_r, i) => i}
                  onRowClick={(r) => setPayslipId(r.id)}
                />
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
            {/* FIN-043 — this run's general-ledger entry. Lives on the run breakdown because a
                journal is a property of a run, not of the payroll page as a whole. */}
            {viewRun && (
              <Button variant="outline" onClick={() => setJournalRunId(viewRun.id)}>
                <BookOpen />
                Ledger entry
              </Button>
            )}
            <DialogClose asChild>
              <Button variant="outline">Close</Button>
            </DialogClose>
          </DialogFooter>
        </DialogContent>
      </Dialog>

      {/* One employee's payslip — opened by clicking a breakdown row. Identical to what the
          employee sees, plus the employer share (which their own copy omits). */}
      <Dialog open={payslipId != null} onOpenChange={(o) => !o && setPayslipId(null)}>
        <DialogContent className="max-h-[90svh] max-w-[calc(100%-3rem)] overflow-y-auto p-0 sm:max-w-3xl">
          <DialogHeader className="sr-only">
            <DialogTitle>
              {openPayslip ? `Payslip · ${openPayslip.employee.name}` : "Payslip"}
            </DialogTitle>
            <DialogDescription>
              The payslip as the employee receives it, with the employer share added.
            </DialogDescription>
          </DialogHeader>
          {openPayslip ? (
            <>
              <PayslipDocument payslip={openPayslip} variant="hr" />
              <div className="flex justify-end gap-2 border-t border-border px-6 py-4">
                <Button variant="outline" onClick={() => onPayslipPdf(openPayslip.id)}>
                  <FileDown />
                  Download PDF
                </Button>
                <DialogClose asChild>
                  <Button variant="outline">Close</Button>
                </DialogClose>
              </div>
            </>
          ) : (
            <p className="py-16 text-center text-sm text-muted-foreground">Loading payslip…</p>
          )}
        </DialogContent>
      </Dialog>

      {/* 13th-month accrual — opened from the header button */}
      <Dialog open={thirteenthOpen} onOpenChange={setThirteenthOpen}>
        <DialogContent className="max-h-[90svh] max-w-[calc(100%-3rem)] overflow-y-auto sm:max-w-2xl">
          <DialogHeader>
            <DialogTitle>13th-Month Pay{thirteenth ? ` · ${thirteenth.year}` : ""}</DialogTitle>
            <DialogDescription>
              Total basic salary earned this year ÷ 12.{" "}
              {thirteenthPay?.meta.already_run
                ? `Paid on ${formatDate(thirteenthPay.meta.run_at ?? "")} — see run history.`
                : "Projected; nothing has been paid yet."}
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
            {/* Slice B17 — paying it lives HERE rather than in the page header: this is already
                the screen that answers "what does 13th month look like this year", and the header
                carries five actions as it is. */}
            {(thirteenthPay?.count ?? 0) > 0 && !thirteenthPay?.meta.is_locked && (
              <Button onClick={() => setPayThirteenthOpen(true)}>
                <Gift />
                {thirteenthPay?.meta.already_run ? "Recompute" : "Pay"} 13th month
              </Button>
            )}
          </DialogFooter>
        </DialogContent>
      </Dialog>

      {/* Slice B17 — a payroll run, so it names its consequence rather than asking "are you
          sure", and it quotes the figures the RUN produces, not the projection behind it. */}
      <Dialog open={payThirteenthOpen} onOpenChange={setPayThirteenthOpen}>
        <DialogContent className="max-h-[90svh] max-w-[calc(100%-3rem)] overflow-y-auto sm:max-w-lg">
          <DialogHeader>
            <DialogTitle>
              {thirteenthPay?.meta.already_run ? "Recompute" : "Pay"} 13th month
              {thirteenthPay ? ` · ${thirteenthPay.meta.year}` : ""}
            </DialogTitle>
            <DialogDescription>
              {thirteenthPay?.meta.already_run
                ? "This recomputes the existing run in place — it does not pay anyone twice."
                : "This creates a payroll run and pays the year's 13th month."}
            </DialogDescription>
          </DialogHeader>

          <div className="space-y-2 rounded-lg border border-border bg-card p-4 text-sm">
            <Row label="Employees" value={String(thirteenthPay?.count ?? 0)} />
            <Row label="Gross" value={peso(thirteenthPay?.gross ?? 0)} />
            <Row
              label="Withholding tax"
              value={peso(thirteenthPay?.tax ?? 0)}
              hint={
                (thirteenthPay?.tax ?? 0) === 0
                  ? "Nobody is over the ₱90,000 exemption"
                  : "Only the amount over the ₱90,000 exemption is taxed"
              }
            />
            <div className="border-t border-border pt-2">
              <Row
                label="Net to pay"
                value={peso((thirteenthPay?.gross ?? 0) - (thirteenthPay?.tax ?? 0))}
              />
            </div>
          </div>

          <p className="text-caption text-muted-foreground">
            13th month is total basic salary earned this year ÷ 12. It carries no SSS, PhilHealth
            or Pag-IBIG — those are charged on monthly pay. The ₱90,000 exemption is shared with
            any allowance paid above its own tax-free ceiling.
          </p>

          <DialogFooter>
            <DialogClose asChild>
              <Button variant="outline">Cancel</Button>
            </DialogClose>
            <Button
              disabled={runThirteenthMut.isPending}
              onClick={async () => {
                try {
                  await runThirteenthMut.mutateAsync(
                    thirteenthPay?.meta.year ?? new Date().getFullYear(),
                  );
                  toast.success(
                    thirteenthPay?.meta.already_run
                      ? "13th month recomputed"
                      : `13th month paid — ${peso(thirteenthPay?.gross ?? 0)} across ${thirteenthPay?.count ?? 0} employees`,
                  );
                  setPayThirteenthOpen(false);
                  setThirteenthOpen(false);
                } catch {
                  toast.error("Could not run 13th month.");
                }
              }}
            >
              {runThirteenthMut.isPending
                ? "Running…"
                : thirteenthPay?.meta.already_run
                  ? "Recompute"
                  : "Pay 13th month"}
            </Button>
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

      {/* FIN-009 — the queue of pay differences awaiting settlement. */}
      <TaxTableDialog open={taxTableOpen} onOpenChange={setTaxTableOpen} />

      <JournalEntryDialog
        runId={journalRunId}
        runLabel={
          viewRun
            ? `${formatDate(viewRun.cycle_start)} – ${formatDate(viewRun.cycle_end)}`
            : "this run"
        }
        open={journalRunId != null}
        onOpenChange={(o) => !o && setJournalRunId(null)}
      />

      <RetroFlagsDialog
        open={retroOpen}
        onOpenChange={setRetroOpen}
        onSettleWithAdjustment={(flag) => {
          // Straight from the difference into the run that settles it, carrying the employee and
          // the reason across so HR does not retype what the system already knows.
          setAdjustmentPrefill({ employeeId: flag.employee.id, reason: flag.reason });
          setRetroOpen(false);
          setAdjustmentOpen(true);
        }}
      />

      {/* FIN-010 — off-cycle correction. */}
      <PaymentBatchDialog
        open={payOpen}
        onOpenChange={setPayOpen}
        payrollRunId={currentRun?.id ?? null}
        runTotalNet={currentRun?.total_net ?? 0}
        runLabel={
          currentRun ? `${currentRun.cycle_start} to ${currentRun.cycle_end}` : "This period"
        }
      />

      <AdjustmentRunDialog
        open={adjustmentOpen}
        onOpenChange={setAdjustmentOpen}
        prefillEmployeeId={adjustmentPrefill.employeeId}
        prefillReason={adjustmentPrefill.reason}
      />
    </div>
  );
}

/** A label/value line in the 13th-month confirmation (Slice B17). */
function Row({ label, value, hint }: { label: string; value: string; hint?: string }) {
  return (
    <div className="flex items-start justify-between gap-4">
      <div>
        <span className="text-muted-foreground">{label}</span>
        {hint && <p className="text-caption text-muted-foreground">{hint}</p>}
      </div>
      <span className="font-semibold text-foreground">{value}</span>
    </div>
  );
}
