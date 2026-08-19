"use client";

import { useState } from "react";
import { PlayCircle, Zap, Check, History, Gift, FileDown, Lock, LockOpen, Wrench, AlertTriangle, Eye, Banknote, Percent, Scale, MoreHorizontal, RefreshCw } from "lucide-react";
import { notify as toast } from "@/core/lib/notify";
import { AxiosError } from "axios";

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
import { cn } from "@/lib/utils";
import { PageHeader } from "@/modules/hr/components/page-header";
import { PayslipDocument } from "@/modules/hr/components/payslip-document";
import { RunRulesSnapshot } from "@/modules/hr/components/run-rules-snapshot";
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuSeparator,
  DropdownMenuShortcut,
  DropdownMenuTrigger,
} from "@/modules/hr/ui/dropdown-menu";
import { ReopenAction } from "@/modules/hr/components/reopen-action";
import { PayslipAmendmentDialog } from "@/modules/hr/components/payslip-amendment-dialog";
import { RerunRunDialog } from "@/modules/hr/components/rerun-run-dialog";
import { RetroFlagsDialog } from "@/modules/hr/components/retro-flags-dialog";
import { TaxTableDialog } from "@/modules/hr/components/tax-table-dialog";
import { PaymentBatchDialog } from "@/modules/hr/components/payment-batch-dialog";
import { MinimumWageDialog } from "@/modules/hr/components/minimum-wage-dialog";
import { StatCard } from "@/core/components/stat-card";
import { MoneyStatCard } from "@/modules/hr/components/money-stat-card";
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
  useRetroFlags,
  useDeductionArrears,
  useThirteenthMonth,
  useThirteenthMonthPreview,
  useRunThirteenthMonth,
  useHrPayslip,
  useAttendanceExceptions,
  useMinimumWageCheck,
} from "@/modules/hr/hooks/use-payroll";
import type {
  PayrollBreakdownRow,
  PayrollRun,
  ThirteenthMonthRow,
} from "@/modules/hr/types/payroll";
import { payFrequencyLabel } from "@/modules/hr/types/employee";

/** The three headline totals, each with its own show/hide (FIN-043). */
type MoneyTotal = "gross" | "deductions" | "net";

export default function PayrollPage() {
  const { data: settings } = usePayrollSettings();
  const { data: runs } = usePayrollRuns();
  const runMut = useRunPayroll();
  const markDoneMut = useMarkPayslipDone();
  const reopenPayslipMut = useReopenPayslip();
  const { data: thirteenth } = useThirteenthMonth();
  const runThirteenthMut = useRunThirteenthMonth();

  const [confirmOpen, setConfirmOpen] = useState(false);
  const [historyOpen, setHistoryOpen] = useState(false);
  const [thirteenthOpen, setThirteenthOpen] = useState(false);
  // FIN-010 / FIN-009 — payslip corrections and the queue of differences awaiting one.
  const [amendOpen, setAmendOpen] = useState(false);
  const [retroOpen, setRetroOpen] = useState(false);
  const [taxTableOpen, setTaxTableOpen] = useState(false);
  const [payOpen, setPayOpen] = useState(false);
  // FIN-030b — the minimum-wage review, and the one-off override that gets a flagged run through.
  const [minWageOpen, setMinWageOpen] = useState(false);
  const [minWageAck, setMinWageAck] = useState(false);
  const [minWageReason, setMinWageReason] = useState("");
  // The period whose recompute is being previewed. Held as the run itself, not an id: the dialog
  // names the cycle in its own title, and the row it came from already has it.
  const [rerunRun, setRerunRun] = useState<PayrollRun | null>(null);
  // A correction now targets a PERIOD, so the prefill carries the run as well as the employee.
  const [amendPrefill, setAmendPrefill] = useState<{
    runId: number | null;
    employeeId: number | null;
    reason: string | null;
  }>({ runId: null, employeeId: null, reason: null });
  const { data: retroFlags = [] } = useRetroFlags();
  const { data: deductionArrears = [] } = useDeductionArrears();
  // FIN-009 + FIN-005 — one badge for everything payroll could not settle by itself. Two separate
  // counters would have HR checking two places for the same class of problem.
  const outstandingCount = retroFlags.length + deductionArrears.length;
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
   *
   * Now per-card rather than one page-wide switch: the three headline totals are what a passer-by
   * reads from across a desk, and they are hidden independently so a gross figure can be shown
   * while net stays covered.
   */
  const [maskedTotals, setMaskedTotals] = useState<Record<MoneyTotal, boolean>>({
    gross: false,
    deductions: false,
    net: false,
  });
  const anyMasked = Object.values(maskedTotals).some(Boolean);
  const toggleTotal = (key: MoneyTotal) =>
    setMaskedTotals((prev) => ({ ...prev, [key]: !prev[key] }));
  const [selectedFreq, setSelectedFreq] = useState<string>();

  const { data: openPayslip } = useHrPayslip(payslipId ?? undefined);

  // Masking is per-card now, so everything outside the three totals formats plainly.
  const peso = (v: number) => formatPeso(v);
  /** A headline total. MoneyStatCard does the masking itself. */
  const total = (v: number | undefined) => (v === undefined ? "—" : formatPeso(v));

  // Pay groups (per-employee frequencies). HR runs one group at a time.
  const payGroups = settings?.pay_groups ?? [];
  const activeGroup = payGroups.find((g) => g.frequency === selectedFreq) ?? payGroups[0];
  const period = activeGroup?.current_period;
  const cycle =
    activeGroup && period
      ? { frequency: activeGroup.frequency, cycle_start: period.start, cycle_end: period.end }
      : null;

  // FIN-030b — asked BEFORE the run, over exactly the population the run would pay. A band on a
  // profile page is seen by whoever opens that profile, which for a compliant-looking employee is
  // nobody; this is the moment the money is about to be committed for the period.
  const { data: minWageCheck } = useMinimumWageCheck(cycle);

  // Item 12 — what is wrong with this cycle's ATTENDANCE, asked here because here is where it
  // becomes money. Each of these already has a screen; none of them was ever put in front of the
  // person about to run the payroll.
  const { data: attendanceExceptions } = useAttendanceExceptions(period?.start, period?.end);
  const exceptions = attendanceExceptions ?? [];
  const blockingExceptions = exceptions.filter((e) => e.blocking);
  const minWageFlagged = minWageCheck?.meta.flagged_count ?? 0;
  const minWageNames = (minWageCheck?.rows ?? [])
    .filter((r) => !r.exempt)
    .map((r) => r.employee.name)
    .filter(Boolean);

  async function onMarkDone(row: PayrollBreakdownRow) {
    // The same action settles two different things: a payslip that has never been paid, and the
    // balance left on one that was corrected after payment. The toast says which.
    const balance = row.disbursed != null && row.outstanding > 0;
    try {
      await markDoneMut.mutateAsync(row.id);
      toast.success(
        balance
          ? `${row.employee} — ${peso(row.outstanding)} balance paid`
          : `${row.employee} — marked disbursed`,
      );
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
      const run = await runMut.mutateAsync({
        ...cycle,
        // FIN-030b — only sent when there is something to acknowledge. The server refuses the run
        // otherwise, which is the point: paying below the floor may well be correct, but doing it
        // with nothing on the record is not.
        ...(minWageAck ? { minimum_wage_ack: true, minimum_wage_ack_reason: minWageReason } : {}),
      });

      // FIN-051 — a re-run REFUSES to touch payslips that were already disbursed, which is
      // correct but was silent: the run reported success, the figures did not move, and nothing
      // said which ones were skipped or why. A warning rather than a success, because "computed"
      // on its own is the sentence that sends someone hunting for a bug that isn't there.
      const skipped = run?.skipped_disbursed ?? 0;
      if (skipped > 0) {
        toast.warning(
          `Payroll computed — ${skipped} payslip${skipped === 1 ? "" : "s"} left unchanged`,
          `Already disbursed, so ${skipped === 1 ? "it was" : "they were"} not recomputed. Undo the disbursement on ${skipped === 1 ? "that row" : "those rows"} first if ${skipped === 1 ? "it needs" : "they need"} to change.`,
        );
      } else {
        toast.success("Payroll computed — payslips generated");
      }
      setConfirmOpen(false);
      setMinWageAck(false);
      setMinWageReason("");
    } catch (err) {
      const msg =
        err instanceof AxiosError
          ? (err.response?.data?.errors?.minimum_wage_ack?.[0] ??
            err.response?.data?.errors?.cycle_start?.[0] ??
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
    {
      header: "Net Pay",
      cell: (r) => (
        <div className="flex flex-col">
          <span className="font-semibold">{peso(r.net)}</span>
          {/* Only when the two figures have come apart — a payslip corrected after it was paid.
              Everywhere else the net IS what was transferred, and restating it under itself on
              every row would bury the handful where it matters. */}
          {r.disbursed != null && r.outstanding > 0 && (
            <span className="text-caption text-warning">
              {peso(r.disbursed)} paid · {peso(r.outstanding)} to pay
            </span>
          )}
          {/* The other direction: a correction TOOK money off a payslip that was already paid, so
              the company is out of pocket. No button here — recovering paid wages needs the
              employee's written consent, so this states it for a person to arrange. */}
          {r.disbursed != null && r.outstanding < 0 && (
            <span className="text-caption text-warning">
              {peso(r.disbursed)} paid · {peso(-r.outstanding)} overpaid
            </span>
          )}
        </div>
      ),
    },
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
              {/* A payslip corrected after payment is disbursed AND owes money. The button pays
                  just the balance — the status does not move, because it is already true. */}
              {r.outstanding > 0 ? (
                <Button
                  size="xs"
                  variant="outline"
                  className="border-warning text-warning hover:bg-warning/10"
                  disabled={markDoneMut.isPending}
                  onClick={() => onMarkDone(r)}
                >
                  Pay {peso(r.outstanding)} balance
                </Button>
              ) : (
                <span className="inline-flex items-center gap-1 text-caption font-medium text-success">
                  <Check className="size-3.5" /> Disbursed
                </span>
              )}
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
    {
      header: "Status",
      align: "right",
      cell: (r) => (
        <div className="flex flex-col items-end gap-0.5">
          <StatusBadge status="completed" label="Completed" />
          {/* An earlier period of this tax year was corrected after this run withheld its tax, so
              this run's figures are settled against a year-to-date that has since moved. Silent
              would mean December quietly comes out wrong. */}
          {r.is_tax_stale && (
            <span
              className="inline-flex items-center gap-1 text-caption text-warning"
              title={r.tax_stale_reason ?? undefined}
            >
              <AlertTriangle className="size-3" />
              Tax needs a re-run
            </span>
          )}
        </div>
      ),
    },
    {
      header: "",
      align: "right",
      cell: (r) => (
        // The row opens the breakdown, so the control inside it must not.
        <div onClick={(e) => e.stopPropagation()}>
          {/* Only an ordinary cycle: a 13th month is recomputed from its own screen and a final pay
              from the employee it belongs to, because neither is keyed by these dates. */}
          {r.type === "regular" && (
            <Button
              size="xs"
              variant="outline"
              disabled={r.is_locked}
              title={
                r.is_locked
                  ? "This run is locked — unlock it before recomputing"
                  : "See what recomputing this period would change"
              }
              onClick={() => setRerunRun(r)}
            >
              <RefreshCw className="size-3.5" />
              Re-run
            </Button>
          )}
        </div>
      ),
    },
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
        /*
          This header carries ~10 actions, far more than the one or two most TeamOra pages have.
          The shared `sm:flex-row` would put them beside the title from 640px up, leaving a tablet
          about half a row of width and stacking them 3–4 deep. Holding the column layout until
          `lg` gives the button row the full width on tablet.
        */
        className="sm:flex-col lg:flex-row"
        title="Payroll"
        subtitle={
          activeGroup && period
            ? `${payFrequencyLabel(activeGroup.frequency)} · ${formatDate(period.start)} – ${formatDate(period.end)} · pay date ${formatDate(period.pay_date)}`
            : "Loading pay schedule…"
        }
        actions={
          /*
            THE TWO VERBS THAT CARRY THE WORKFLOW, then everything else behind `⋯`.

            This was nine same-weight outline buttons in a row. They were undifferentiated, so
            nothing said that running the group is the point of the page while the tax table is
            a lookup — and `PageHeader` lays `actions` out as `flex items-center gap-2` with NO
            wrap (page-header.tsx:29), so the row squashed and ran off the edge on narrow
            screens. An older comment here claimed that container wrapped; it does not, and
            trusting it is what let the row grow this far.

            The two badged queues moved OUT to the alert strip below — see PayrollAlerts. They
            also stay in the menu, so an EMPTY queue is still reachable: the strip is the shout
            when something is owed, the menu is the permanent address.
          */
          <>
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

            <DropdownMenu>
              <DropdownMenuTrigger asChild>
                <Button variant="outline" size="icon" title="More payroll tools">
                  <MoreHorizontal />
                  <span className="sr-only">More payroll tools</span>
                </Button>
              </DropdownMenuTrigger>
              <DropdownMenuContent>
                {/* FIN-009 / FIN-030b — the two queues. Kept here as well as in the strip so a
                    zero count is still openable; an empty queue and an unchecked one are not
                    the same thing, which is the whole reason they are counted. */}
                <DropdownMenuItem onSelect={() => setRetroOpen(true)}>
                  <AlertTriangle
                    className={outstandingCount > 0 ? "text-warning" : undefined}
                  />
                  Outstanding corrections
                  <DropdownMenuShortcut>{outstandingCount}</DropdownMenuShortcut>
                </DropdownMenuItem>
                <DropdownMenuItem onSelect={() => setMinWageOpen(true)}>
                  <Scale className={minWageFlagged > 0 ? "text-warning" : undefined} />
                  Minimum wage check
                  <DropdownMenuShortcut>{minWageFlagged}</DropdownMenuShortcut>
                </DropdownMenuItem>

                <DropdownMenuSeparator />

                {/* FIN-010 — correct a period on its own payslips, without reopening it. Opens on
                    the period being looked at; any other unlocked run can be chosen inside. */}
                <DropdownMenuItem
                  onSelect={() => {
                    setAmendPrefill({
                      runId: currentRun?.id ?? null,
                      employeeId: null,
                      reason: null,
                    });
                    setAmendOpen(true);
                  }}
                >
                  <Wrench />
                  Correct a payslip
                </DropdownMenuItem>
                <DropdownMenuItem onSelect={() => setThirteenthOpen(true)}>
                  <Gift />
                  13th-month
                </DropdownMenuItem>
                {/* FIN-027 — the tax bands are data so they can be checked; this is where
                    you check them. */}
                <DropdownMenuItem onSelect={() => setTaxTableOpen(true)}>
                  <Percent />
                  Tax table
                </DropdownMenuItem>
                <DropdownMenuItem onSelect={() => setHistoryOpen(true)}>
                  <History />
                  Run history
                  <DropdownMenuShortcut>{history.length}</DropdownMenuShortcut>
                </DropdownMenuItem>

                {/* Slice 5 — seal the cycle once every payslip is paid out. */}
                {(detail?.can_lock || detail?.is_locked) && <DropdownMenuSeparator />}
                {detail?.can_lock && (
                  <DropdownMenuItem
                    disabled={lockMut.isPending}
                    onSelect={() => lockMut.mutate({ id: detail.id, lock: true })}
                  >
                    <Lock />
                    {lockMut.isPending ? "Locking…" : "Lock this run"}
                  </DropdownMenuItem>
                )}
                {detail?.is_locked && (
                  <DropdownMenuItem
                    disabled={lockMut.isPending}
                    onSelect={() => lockMut.mutate({ id: detail.id, lock: false })}
                  >
                    <LockOpen />
                    {lockMut.isPending ? "Unlocking…" : "Unlock this run"}
                  </DropdownMenuItem>
                )}
              </DropdownMenuContent>
            </DropdownMenu>

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
          </>
        }
      />

      {/* WHAT IS OWED, said out loud. A badge on a permanently-lit button reads as decoration —
          it looks the same whether it says 0 or 3. This renders ONLY when something is actually
          outstanding, so its presence IS the signal, and it disappears entirely when clear. */}
      {(outstandingCount > 0 || minWageFlagged > 0) && (
        <div className="mt-4 flex flex-wrap items-center gap-x-4 gap-y-2 rounded-card border border-warning/40 bg-warning-subtle px-4 py-3">
          <AlertTriangle className="size-5 shrink-0 text-warning" />
          <p className="min-w-0 flex-1 text-sm text-foreground">
            {[
              outstandingCount > 0 &&
                `${outstandingCount} correction${outstandingCount === 1 ? "" : "s"} owed`,
              minWageFlagged > 0 &&
                `${minWageFlagged} employee${minWageFlagged === 1 ? "" : "s"} below minimum wage`,
            ]
              .filter(Boolean)
              .join(" · ")}
          </p>
          {outstandingCount > 0 && (
            <Button variant="outline" size="sm" onClick={() => setRetroOpen(true)}>
              Review corrections
            </Button>
          )}
          {minWageFlagged > 0 && (
            <Button variant="outline" size="sm" onClick={() => setMinWageOpen(true)}>
              Review wages
            </Button>
          )}
        </div>
      )}

      {/* Item 12 — attendance problems this cycle, gathered from the queues that already own them.
          Same rule as the strip above: it renders only when something actually fired, so its
          presence is the signal. Amber only when something is BLOCKING; the merely-odd entries are
          neutral, because five permanent yellow bars is how a warning stops being read. */}
      {exceptions.length > 0 && (
        <div
          className={cn(
            "mt-4 rounded-card border px-4 py-3",
            blockingExceptions.length > 0
              ? "border-warning/40 bg-warning-subtle"
              : "border-border bg-muted/40",
          )}
        >
          <div className="flex items-start gap-3">
            {blockingExceptions.length > 0 ? (
              <AlertTriangle className="mt-0.5 size-5 shrink-0 text-warning" />
            ) : (
              <Eye className="mt-0.5 size-5 shrink-0 text-muted-foreground" />
            )}
            <div className="min-w-0 flex-1">
              <p className="text-sm font-semibold text-foreground">
                {blockingExceptions.length > 0
                  ? "Check the attendance for this period before running it"
                  : "Attendance notes for this period"}
              </p>
              <ul className="mt-2 space-y-1.5">
                {exceptions.map((e) => (
                  <li key={e.key} className="text-sm text-foreground">
                    <span className="font-semibold">{e.count}</span> · {e.label}
                    <span className="block text-caption text-muted-foreground">
                      {e.detail} <span className="italic">{e.where}</span>
                    </span>
                  </li>
                ))}
              </ul>
              <p className="mt-2 text-caption text-muted-foreground">
                None of these stops the run — a cutoff that cannot close is worse than one that
                closes with a known problem on the record.
              </p>
            </div>
          </div>
        </div>
      )}

      {/* FIN-043 — visible while masking is on, because a tooltip nobody hovers is not a
          correction. Someone hiding amounts under the impression it is a security control needs to
          be told at the moment they are relying on it, not when they go looking. */}
      {anyMasked && (
        <p className="mt-4 rounded-card border border-border bg-muted/40 px-4 py-2 text-caption text-muted-foreground">
          Amounts are hidden on screen only — for privacy from people nearby. They are still in the
          page data, so this is not an access control.
        </p>
      )}

      {/* A PERMANENT EXPLAINER, NOT AN ALERT (FIN-052).
          This carried the same amber that the outstanding/minimum-wage strip above uses. Two
          identical yellow bars stacked meant the one that actually needs action read as
          decoration — and this one is on screen every single visit, which is exactly what teaches
          people to skip the colour. Warning styling is now reserved for things that want you. */}
      <div className="mt-6 flex items-start gap-3 rounded-card border border-border bg-muted/40 px-4 py-3">
        <Zap className="mt-0.5 size-5 shrink-0 text-muted-foreground" fill="currentColor" />
        <p className="text-sm text-muted-foreground">
          <span className="font-semibold text-foreground">Computation only — no money moves.</span>{" "}
          Running a pay group calculates gross, PH deductions and net for its employees
          and generates payslips so HR can disburse manually.
        </p>
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
        <MoneyStatCard
          label="Total Gross"
          value={total(currentRun?.total_gross)}
          hidden={maskedTotals.gross}
          onToggle={() => toggleTotal("gross")}
        />
        <MoneyStatCard
          label="Total Deductions"
          value={total(currentRun?.total_deductions)}
          hidden={maskedTotals.deductions}
          onToggle={() => toggleTotal("deductions")}
        />
        <MoneyStatCard
          label="Net Payout"
          value={total(currentRun?.total_net)}
          hidden={maskedTotals.net}
          onToggle={() => toggleTotal("net")}
        />
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
            {/* What a correction left behind: a balance owed, or tax settled against figures that
                have since moved. */}
            <RunCorrectionNotices run={detail} onRerun={setRerunRun} />
            <DataTable
              columns={makeColumns(!!detail.is_locked)}
              rows={detail.breakdown}
              rowKey={(_r, i) => i}
              onRowClick={(r) => setPayslipId(r.id)}
            />
            {/* Slice 8 — what these amounts were computed with. */}
            <RunRulesSnapshot snapshot={detail.policy_snapshot} drift={detail.rules_drift} />
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
                <MoneyStatCard
                  label="Total Gross"
                  value={total(viewRun.total_gross)}
                  hidden={maskedTotals.gross}
                  onToggle={() => toggleTotal("gross")}
                />
                <MoneyStatCard
                  label="Total Deductions"
                  value={total(viewRun.total_deductions)}
                  hidden={maskedTotals.deductions}
                  onToggle={() => toggleTotal("deductions")}
                />
                <MoneyStatCard
                  label="Net Payout"
                  value={total(viewRun.total_net)}
                  hidden={maskedTotals.net}
                  onToggle={() => toggleTotal("net")}
                />
              </div>
              <RunCorrectionNotices
                run={viewRun}
                onRerun={(run) => {
                  setViewRunId(null);
                  setRerunRun(run);
                }}
              />
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
            {/* Correcting an OLD period is the point of the amendment flow, so the entry point is
                here as well as in the header menu — with this run already chosen. The dialog closes
                behind it, following the corrections queue: two stacked modals fight for focus. */}
            {viewRun && !viewRun.is_locked && (
              <Button
                variant="outline"
                onClick={() => {
                  setAmendPrefill({ runId: viewRun.id, employeeId: null, reason: null });
                  setViewRunId(null);
                  setAmendOpen(true);
                }}
              >
                <Wrench />
                Correct a payslip
              </Button>
            )}
            {/* The same recompute the history row offers, for whoever is already looking at the
                figures rather than the list of periods. */}
            {viewRun && viewRun.type === "regular" && !viewRun.is_locked && (
              <Button
                variant="outline"
                onClick={() => {
                  const run = viewRun;
                  setViewRunId(null);
                  setRerunRun(run);
                }}
              >
                <RefreshCw />
                Re-run
              </Button>
            )}
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
          {/* FIN-030b — the run stops ONCE on a minimum-wage breach, and any answer gets past it.
              Not a wall: everything the check cannot see (a granted exemption, a part-time
              schedule, an order that does not apply) is still a legitimate reason to proceed. What
              it refuses is proceeding with nothing on the record — underpayment creates wage
              differentials and, under RA 8188, double indemnity. */}
          {minWageFlagged > 0 && (
            <div className="space-y-2 rounded-card border border-warning/40 bg-warning/10 p-3">
              <p className="text-sm font-medium text-foreground">
                {minWageFlagged} employee{minWageFlagged === 1 ? " is" : "s are"} paid below the
                regional minimum daily wage
              </p>
              <p className="text-caption text-muted-foreground">
                {minWageNames.slice(0, 4).join(", ")}
                {minWageNames.length > 4 ? ` and ${minWageNames.length - 4} more` : ""}. Raise them
                to the floor, mark them exempt, or say why this run should go ahead.
              </p>
              <Button
                size="xs"
                variant="outline"
                onClick={() => {
                  setConfirmOpen(false);
                  setMinWageOpen(true);
                }}
              >
                Review and fix
              </Button>
              <label className="flex items-start gap-2 pt-1 text-sm text-foreground">
                <input
                  type="checkbox"
                  className="mt-0.5 h-4 w-4 rounded border-input accent-brand"
                  checked={minWageAck}
                  onChange={(e) => setMinWageAck(e.target.checked)}
                />
                Run anyway — I have a reason
              </label>
              {minWageAck && (
                <Input
                  placeholder="e.g. part-time staff, wage order does not cover this branch"
                  aria-label="Reason for running past the minimum-wage warning"
                  value={minWageReason}
                  onChange={(e) => setMinWageReason(e.target.value)}
                />
              )}
            </div>
          )}
          <DialogFooter>
            <DialogClose asChild>
              <Button variant="outline">Cancel</Button>
            </DialogClose>
            <Button
              className="bg-warning text-white hover:bg-warning/90"
              disabled={
                runMut.isPending ||
                // The reason is what makes the override a record rather than a second click.
                (minWageFlagged > 0 && (!minWageAck || minWageReason.trim() === ""))
              }
              onClick={onRun}
            >
              {runMut.isPending ? "Running…" : "Run payroll"}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>

      {/* FIN-030b — the review, and the raise that clears the floor. */}
      <MinimumWageDialog open={minWageOpen} onOpenChange={setMinWageOpen} cycle={cycle} />

      {/* FIN-009 — the queue of pay differences awaiting settlement. */}
      <TaxTableDialog open={taxTableOpen} onOpenChange={setTaxTableOpen} />

      <RetroFlagsDialog
        open={retroOpen}
        onOpenChange={setRetroOpen}
        onSettleWithAdjustment={(flag) => {
          // Straight from the difference onto the payslip that owes it, carrying the period, the
          // employee and the reason across so HR does not retype what the system already knows.
          setAmendPrefill({
            runId: flag.payroll_run_id,
            employeeId: flag.employee.id,
            reason: flag.reason,
          });
          setRetroOpen(false);
          setAmendOpen(true);
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

      <PayslipAmendmentDialog
        open={amendOpen}
        onOpenChange={setAmendOpen}
        prefillRunId={amendPrefill.runId}
        prefillEmployeeId={amendPrefill.employeeId}
        prefillReason={amendPrefill.reason}
      />

      {/* Recompute a settled period, after showing what that would change. */}
      <RerunRunDialog
        run={rerunRun}
        open={rerunRun !== null}
        onOpenChange={(o) => !o && setRerunRun(null)}
      />
    </div>
  );
}

/**
 * What a correction left behind on a run: money still owed, and tax that no longer adds up.
 *
 * Both are consequences of amending a period rather than re-running it, and neither is visible in
 * the run's own totals — an amended run reports a larger net and looks entirely settled. Rendered
 * above the breakdown for the current run and inside the past-run dialog, because a correction on an
 * old period is the case this exists for.
 */
function RunCorrectionNotices({
  run,
  onRerun,
}: {
  run: PayrollRun;
  /** Offered on the stale-tax notice, which is the one thing here a recompute actually fixes. */
  onRerun?: (run: PayrollRun) => void;
}) {
  const owing = (run.breakdown ?? []).filter((r) => r.disbursed != null && r.outstanding > 0);
  const owed = owing.reduce((s, r) => s + r.outstanding, 0);
  // The mirror image: corrections that TOOK money off payslips already paid out.
  const overpaid = (run.breakdown ?? []).filter((r) => r.disbursed != null && r.outstanding < 0);
  const overpaidTotal = overpaid.reduce((s, r) => s - r.outstanding, 0);

  if (owing.length === 0 && overpaid.length === 0 && !run.is_tax_stale) return null;

  return (
    <div className="mb-3 flex flex-col gap-2">
      {overpaid.length > 0 && (
        <div className="rounded-card border border-warning/40 bg-warning/10 px-4 py-3">
          <p className="text-sm font-medium text-foreground">
            {overpaid.length} payslip{overpaid.length === 1 ? "" : "s"} in this run{" "}
            {overpaid.length === 1 ? "was" : "were"} overpaid by {formatPeso(overpaidTotal)}
          </p>
          <p className="text-caption text-muted-foreground">
            A correction reduced what {overpaid.length === 1 ? "it" : "they"} should have paid, after
            the money had already been transferred. Nothing collects it back automatically —
            recovering wages already paid needs the employee&rsquo;s written consent, so arrange it
            with {overpaid.length === 1 ? "them" : "each of them"}.
          </p>
        </div>
      )}
      {owing.length > 0 && (
        <div className="rounded-card border border-warning/40 bg-warning/10 px-4 py-3">
          <p className="text-sm font-medium text-foreground">
            {owing.length} payslip{owing.length === 1 ? "" : "s"} in this run{" "}
            {owing.length === 1 ? "was" : "were"} corrected after being paid —{" "}
            {formatPeso(owed)} still to pay
          </p>
          <p className="text-caption text-muted-foreground">
            The correction did not change what was transferred. Pay the balance on{" "}
            {owing.length === 1 ? "that row" : "those rows"}; the run cannot be locked until it is
            settled.
          </p>
        </div>
      )}
      {run.is_tax_stale && (
        <div className="rounded-card border border-warning/40 bg-warning/10 px-4 py-3">
          <p className="text-sm font-medium text-foreground">
            This run&rsquo;s withholding tax is out of date
          </p>
          <p className="text-caption text-muted-foreground">
            {run.tax_stale_reason ??
              "An earlier period of this tax year was corrected after this run computed its tax."}{" "}
            Re-run this period to settle it against the year&rsquo;s current figures.
          </p>
          {onRerun && run.type === "regular" && !run.is_locked && (
            <Button size="xs" variant="outline" className="mt-2" onClick={() => onRerun(run)}>
              <RefreshCw className="size-3.5" />
              Re-run this period
            </Button>
          )}
        </div>
      )}
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
