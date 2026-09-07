"use client";

import { Fragment, type ReactNode } from "react";

import { cn } from "@/lib/utils";
import { useCompanyProfile } from "@/modules/hr/hooks/use-company";
import { payFrequencyLabel } from "@/modules/hr/types/employee";
import { CONTRIBUTION_LABELS } from "@/modules/hr/types/benefits";
import type {
  Payslip,
  PayslipAmendment,
  PayslipDeductionKey,
  PayslipDeductionRow,
  PayslipLine,
  PayslipMonthSummary,
  TaxAnnualisationBasis,
} from "@/modules/hr/types/payroll";

/** Column name → what the employee should see it called. */
const DEDUCTION_LABELS: Record<PayslipDeductionKey, string> = {
  sss: "SSS",
  philhealth: "PhilHealth",
  pagibig: "Pag-IBIG",
  withholding_tax: "Withholding Tax",
  other_deductions: "Other Deductions",
};

/**
 * The payslip, as a document. One component for all three audiences: the employee's own page,
 * HR's modal, and (mirrored in `hr::payslip`) the PDF.
 */

/**
 * No hardcoded letterhead.
 *
 * This used to fall back to the PROVIDER's legal name and Makati address, to avoid a blank
 * flash before the profile loaded. That was safe while the product served one company. It is
 * not now: the fallback puts the vendor's legal entity on a client's payslip, and a payslip
 * is a legal document. A blank line for a moment is a smaller wrong than the wrong company's
 * name for good.
 */

/** Bare grouped amount — no ₱. The document states its currency once, on the net pay bar. */
function amount(n: number): string {
  return new Intl.NumberFormat("en-PH", {
    minimumFractionDigits: 2,
    maximumFractionDigits: 2,
  }).format(n);
}

/** "July 15, 2026" — the long form the document uses throughout. */
function longDate(iso: string | null | undefined): string {
  if (!iso) return "—";
  return new Date(iso).toLocaleDateString("en-PH", {
    timeZone: "Asia/Manila",
    year: "numeric",
    month: "long",
    day: "numeric",
  });
}

/** "July 14, 2026 · 10:45 AM" for the issued stamp. */
function stamp(iso: string | null | undefined): string {
  if (!iso) return "—";
  const d = new Date(iso);
  const date = d.toLocaleDateString("en-PH", {
    timeZone: "Asia/Manila",
    year: "numeric",
    month: "long",
    day: "numeric",
  });
  const time = d.toLocaleTimeString("en-PH", {
    timeZone: "Asia/Manila",
    hour: "numeric",
    minute: "2-digit",
  });
  return `${date} · ${time}`;
}

/** Two peso figures that differ by more than rounding. */
function differs(a: number, b: number): boolean {
  return Math.abs(a - b) > 0.005;
}

/** A header rate, or an em dash when the employee is not paid on one. */
function rate(n: number): string | null {
  return n > 0 ? amount(n) : null;
}

/** TURNING THE DOCUMENT INTO THE FORM. */
export interface PayslipEditMode {
  /** The line currently open for correction. */
  selected: string | null;
  onSelect: (key: string | null) => void;
  /** Rendered immediately beneath the selected line. */
  editor: ReactNode;
  /** Rendered at the foot of the Compensation table — "add a line the engine never priced". */
  addEarning?: ReactNode;
  /** The same at the foot of the Deductions table. */
  addDeduction?: ReactNode;
}

/** WHAT THE ENGINE SAID, on a line HR has since corrected. */
function Struck({ children }: { children: ReactNode }) {
  return (
    <span className="mr-1.5 font-normal text-muted-foreground line-through decoration-muted-foreground/70">
      {children}
    </span>
  );
}

/** The corrections HR has made, keyed by the line each one names. */
function correctionsByLine(p: Payslip): Record<string, PayslipAmendment> {
  const out: Record<string, PayslipAmendment> = {};

  for (const amendment of p.amendments ?? []) {
    if (amendment.target_key) out[amendment.target_key] = amendment;
  }

  return out;
}

export function PayslipDocument({
  payslip,
  variant = "employee",
  className,
  edit,
}: {
  payslip: Payslip;
  /** HR additionally sees the employer's counterpart contributions. */
  variant?: "employee" | "hr";
  className?: string;
  /**
   * Present only when HR is correcting this payslip. Absent — which is every employee-facing
   * render — the document has no clickable line and no editor in it at all.
   */
  edit?: PayslipEditMode;
}) {
  const p = payslip;
  // The letterhead, from Company → Settings — the same row `hr::payslip` prints from, so the
  // document on screen and the PDF of it can never disagree about who the employer is.
  const { data: company } = useCompanyProfile();
  const companyName = company?.legal_name ?? "";
  const companyAddress = company?.address ?? "";
  const companyLogo = company?.logo_url ?? null;
  // Derived by the API, not here — the PDF renders the same figures and the arithmetic is
  // subtle enough (see PayslipResource::documentTotals) that two implementations would drift.
  const t = p.totals;
  const month = p.month_summary;

  const employerTotal =
    (p.employer_sss ?? 0) + (p.employer_philhealth ?? 0) + (p.employer_pagibig ?? 0);
  const showEmployer = variant === "hr" && employerTotal > 0;
  // Snapshotted on the payslip at run time, not read from the profile now: the
  // override may since have been cleared, and this payslip still charged what it charged.
  const overridden = p.contribution_overrides ?? [];
  // The government lines are printed from their columns rather than from a breakdown, so their
  // corrections are looked up rather than carried on the row.
  const fixes = correctionsByLine(p);

  /**
   * What a line needs to be selectable, or nothing at all when this is not an edit.
   *
   * Clicking the open line CLOSES it, so the same click both opens and dismisses the editor — a
   * document where every row is a control needs a way out that is not hunting for a Cancel button.
   */
  const pick = (key?: string) =>
    edit && key && !isAddedLine(key)
      ? {
          onSelect: () => edit.onSelect(edit.selected === key ? null : key),
          active: edit.selected === key,
        }
      : {};

  /** The editor itself, rendered beneath the line it belongs to and nowhere else. */
  const editorFor = (key?: string) => (edit && key && edit.selected === key ? edit.editor : null);
  // On the month's last cutoff the walk continues from the MONTH's figures, not this period's —
  // that is the whole point of the summary. Everywhere else the two are the same thing.
  const initialNet = month ? month.initial_net : t.initial_net;
  const netLabel = p.cutoff?.label ? `Total Net (${p.cutoff.label})` : "Total Net";

  return (
    <div
      className={cn(
        "flex w-full flex-col gap-8 rounded-xl bg-card p-6 text-foreground sm:p-12",
        className,
      )}
    >
      {/* Header */}
      <div className="flex flex-col items-center gap-2">
        {companyLogo && (
          // eslint-disable-next-line @next/next/no-img-element -- signed, short-lived URL off the private disk; not an optimizable static asset
          <img src={companyLogo} alt="" className="mb-1 h-10 object-contain" />
        )}
        <p className="text-center text-lg font-extrabold uppercase tracking-tight sm:text-xl">
          {companyName}
        </p>
        {companyAddress && (
          <p className="max-w-[36rem] text-center text-caption text-muted-foreground">
            {companyAddress}
          </p>
        )}
      </div>

      {/* Title + period */}
      <div className="flex flex-col gap-4">
        <div className="flex flex-wrap items-end justify-between gap-2">
          <p className="font-mono text-[32px] font-extrabold leading-none">PAYSLIP</p>
          <Meta label="Pay Date" align="right">
            <span className="text-base font-bold">{longDate(p.pay_date)}</span>
          </Meta>
        </div>
        <div className="border-t border-border" />
        <div className="flex flex-wrap gap-8">
          {/*
           * The server-resolved label. `frequency` on a final-pay run is
           * "final_pay:<employee id>", an internal key with an id in it.
           */}
          <Meta label="Pay Type">{p.run_type_label || payFrequencyLabel(p.frequency)}</Meta>
          <Meta label="Pay Period">
            {longDate(p.cycle_start)} – {longDate(p.cycle_end)}
          </Meta>
        </div>
      </div>

      {/* Who this is for */}
      <div className="flex flex-col gap-6 rounded-lg border border-border p-6">
        <div className="flex flex-col gap-6 sm:flex-row">
          <Info label="Employee Name" value={p.employee.name} />
          <Info label="Job Role" value={p.employee.job_title} />
        </div>
        <div className="border-t border-border" />
        {/*
         * The three rates the deduction arithmetic below is quoted in. Per-hour follows the run's
         * own hours-per-day divisor, so it reconciles with the late/undertime charge.
         */}
        <div className="grid grid-cols-3 gap-6">
          <Info label="Per Day" value={rate(p.rates.daily)} mono />
          <Info label="Per Hour" value={rate(p.rates.hourly)} mono />
          <Info label="Half Day" value={rate(p.rates.half_day)} mono />
        </div>
      </div>

      {/* The money */}
      <div className="flex flex-col gap-6">
        <Table heading="Compensation">
          {earningRows(p).map((line) => (
            <Fragment key={line.key ?? line.name}>
              <Row
                label={line.name}
                note={line.note ?? undefined}
                value={amount(line.amount)}
                originalValue={line.original_amount == null ? null : amount(line.original_amount)}
                originalLabel={line.original_name}
                {...pick(line.key)}
              />
              {editorFor(line.key)}
            </Fragment>
          ))}
          {edit?.addEarning}
          {/*
           * The total is NOT selectable, here or anywhere. It is the sum of the lines above it,
           * and a payslip whose parts do not add up to its own total is the one error a payslip
           * cannot make — so there is no control that could produce one.
           */}
          <Row label="Total Compensation" value={amount(t.earnings)} total />
        </Table>

        <Table heading="Deductions">
          {deductionRows(p).map((row, i) => (
            <Fragment key={`${row.key}-${row.name}-${i}`}>
              <DeductionRow row={row} {...pick(row.key)} />
              {editorFor(row.key)}
            </Fragment>
          ))}
          {edit?.addDeduction}
          <Row label="Total Deduction" value={amount(t.deductions)} total />
        </Table>

        <HighlightBar label="Total Current Amount" value={amount(t.current)} />

        {/* The month, restated. Only on its last cutoff, and only when an earlier one ran. */}
        {month && <SalarySummary summary={month} />}

        <Table heading="Government Benefits & Tax">
          {/*
           * Each statutory line carries the employer's counterpart directly beneath it.
           * Beside the employee's own figure it means something — ₱875 of their pay went to
           * SSS and the company put ₱1,765 in alongside it — which is why it lives here
           * rather than in a detached block of its own. Never in the total.
           */}
          {(
            [
              ["sss", "SSS", p.sss, p.employer_sss],
              ["philhealth", "PhilHealth", p.philhealth, p.employer_philhealth],
              ["pagibig", "Pag-IBIG", p.pagibig, p.employer_pagibig],
            ] as const
          ).map(([key, label, employee, employer]) => (
            <Fragment key={key}>
              <Row
                label={fixes[key]?.label_override ?? label}
                value={amount(employee)}
                originalValue={
                  fixes[key]?.original_amount == null
                    ? null
                    : amount(fixes[key]!.original_amount as number)
                }
                originalLabel={fixes[key]?.label_override ? label : null}
                pair={(employer ?? 0) > 0}
                {...pick(key)}
              />
              {(employer ?? 0) > 0 && (
                <SubRow label="+ employer share" value={amount(employer ?? 0)} />
              )}
              {editorFor(key)}
            </Fragment>
          ))}
          <Row
            label={fixes.withholding_tax?.label_override ?? "Withholding Tax"}
            value={amount(p.withholding_tax)}
            originalValue={
              fixes.withholding_tax?.original_amount == null
                ? null
                : amount(fixes.withholding_tax.original_amount as number)
            }
            originalLabel={fixes.withholding_tax?.label_override ? "Withholding Tax" : null}
            {...pick("withholding_tax")}
          />
          {editorFor("withholding_tax")}
          {/*
           * December folds the year's correction into the line above, and on the
           * common case it goes NEGATIVE and net pay exceeds gross. Naming the adjustment is
           * not enough: a lone "refund ₱5,432" is the same opacity moved somewhere else. These
           * are the figures it was computed from, so the employee can add it up — and they are
           * the same ones BIR 2316 will show them.
           */}
          <TaxSettlement basis={p.tax_annualisation_basis} amount={amount} />
          {/*
           * When the month's contributions did not all land here, BOTH figures are printed: the
           * rows above sum to the first, and the second is what the walk below continues from.
           * One row labelled "monthly" sitting under rows that do not add up to it would be the
           * one error a payslip cannot make.
           */}
          {month && differs(month.government, t.government) && (
            <Row label="Total Benefits (This Cut-Off)" value={amount(t.government)} total />
          )}
          <Row
            label={month ? "Total Monthly Benefits" : "Total Benefits"}
            value={amount(month ? month.government : t.government)}
            total
          />
        </Table>

        <HighlightBar label="Initial Net Salary" value={amount(initialNet)} />

        <Table heading="Non-Taxable Allowance">
          {p.earnings_breakdown
            .filter((line) => line.kind === "allowance")
            .map((line) => (
              <Fragment key={line.key ?? line.name}>
                <Row
                  label={line.name}
                  value={amount(line.amount)}
                  originalValue={line.original_amount == null ? null : amount(line.original_amount)}
                  originalLabel={line.original_name}
                  {...pick(line.key)}
                />
                {editorFor(line.key)}
              </Fragment>
            ))}
          {t.allowances === 0 && !month && <Row label="None" value={amount(0)} />}
          {month && differs(month.allowances, t.allowances) && (
            <Row label="Total Allowance (This Cut-Off)" value={amount(t.allowances)} total />
          )}
          <Row
            label={month ? "Total Monthly Allowance" : "Total Allowance"}
            value={amount(month ? month.allowances : t.allowances)}
            total
          />
        </Table>

        {/*
         * Disclosure, not a term in the total. The figures above are what was actually
         * collected, so this is no longer credited back — it is shown because the employee is
         * entitled to know a deduction they owe went unpaid, and which one.
         */}
        {t.uncollected > 0 && (
          <div className="flex items-center justify-between gap-4 rounded-lg border border-warning bg-warning-subtle px-4 py-3">
            <div>
              <p className="text-sm font-semibold">Not collected this period</p>
              <p className="text-caption text-muted-foreground">
                Pay did not cover the full deductions, so this amount was not taken. The figures
                above show what was collected.
              </p>
              {Object.keys(t.uncollected_breakdown ?? {}).length > 0 && (
                <p className="text-caption text-muted-foreground pt-1">
                  {Object.entries(t.uncollected_breakdown)
                    .map(
                      ([key, value]) =>
                        `${DEDUCTION_LABELS[key as PayslipDeductionKey] ?? key} ${amount(value)}`,
                    )
                    .join(" · ")}
                </p>
              )}
            </div>
            <p className="shrink-0 font-mono text-sm font-bold tabular-nums">
              {amount(t.uncollected)}
            </p>
          </div>
        )}

        <NetBar label={netLabel} value={amount(p.net_pay)} />
        {/*
         * The month total sits BELOW this cutoff's net, not instead of it: the employee is owed
         * both answers — what lands in their account now, and what the month came to.
         */}
        {month && <NetBar label="Total Month Salary" value={amount(month.total_month)} />}

        {/* WHAT WAS ACTUALLY TRANSFERRED, when the net above is no longer that figure. */}
        <SettlementNote payslip={p} />


        {/*
         * HR keeps ONE extra thing the employee has no use for: the employer TOTAL, which is
         * what gets filed. The three components are already inline above for both audiences,
         * so a standalone "Employer Share (for remittance)" table would repeat them.
         */}
        {showEmployer && (
          <p className="px-1 text-caption text-muted-foreground">
            Total employer share for remittance:{" "}
            <span className="font-mono font-semibold text-foreground tabular-nums">
              {amount(employerTotal)}
            </span>
          </p>
        )}
      </div>

      {/* SUPPORTING DETAIL — the one thing left that is a warning rather than a figure. */}
      <div className="flex flex-col gap-6">
        {/*
         * An override replaces the EMPLOYEE half only, so the pair above belongs to
         * no single salary bracket and cannot be filed as it stands. HR-only: the employee
         * cannot act on it, and it must not appear on the copy they receive.
         */}
        {variant === "hr" && overridden.length > 0 && (
          <div className="rounded-lg border border-warning bg-warning-subtle px-4 py-3">
            <p className="text-sm font-semibold">
              {overridden.map((t) => CONTRIBUTION_LABELS[t] ?? t).join(", ")} came from a
              per-employee amount, not the government rates
            </p>
            <p className="mt-0.5 text-caption text-muted-foreground">
              Only the employee&rsquo;s half was replaced — the employer share above is still
              calculated, so the two do not correspond to one salary bracket. Check the override on
              this employee&rsquo;s profile before filing this remittance.
            </p>
          </div>
        )}
      </div>

      {/* Provenance */}
      <div className="flex flex-wrap items-end justify-between gap-8 pt-6">
        <div className="flex flex-col gap-3">
          <Meta label="Issued Date">
            <span className="text-xs font-normal">{stamp(p.issued_at)}</span>
          </Meta>
          <Meta label="Verified By">
            <span className="text-xs font-normal">System Automated Check</span>
          </Meta>
          <AmendedNote amendments={p.amendments ?? []} />
        </div>
        <Signature name={p.processed_by} role="HR Manager" />
        <Signature name={p.employee.name} role="Employee" />
      </div>
    </div>
  );
}

/**
 * The Compensation table's rows, in the API's own order.
 *
 * The rows an ordinary period shows even at zero come from the API as REAL LINES — keys and all —
 * rather than being painted in here. Synthesising them client-side gave them no key, which is why
 * only Basic Pay used to be correctable; and matching them to the API's lines BY NAME made a line
 * HR had renamed fail to match, so it printed twice: once as an empty placeholder and again with
 * its new name.
 */
function earningRows(p: Payslip): PayslipLine[] {
  return p.earnings_breakdown.filter((line) => line.kind !== "allowance");
}

/**
 * The Deductions table's rows, as the API grouped them.
 *
 * Zero rows are kept on an ordinary period for the same reason the earnings skeleton is, and
 * dropped on every other run type for the same reason it is: there was no attendance to charge.
 */
function deductionRows(p: Payslip): PayslipDeductionRow[] {
  const rows = p.deductions_breakdown ?? [];

  return p.run_type === "regular" ? rows : rows.filter((row) => row.amount !== 0);
}

/** One reason the period was charged for: what it was, how many, what it cost. */
function DeductionRow({
  row,
  onSelect,
  active = false,
}: {
  row: PayslipDeductionRow;
  /** Present only while HR is correcting this payslip — see PayslipEditMode. */
  onSelect?: () => void;
  active?: boolean;
}) {
  const Cell = onSelect ? "button" : "div";

  return (
    <Cell
      {...(onSelect ? { type: "button" as const, onClick: onSelect } : {})}
      className={cn(
        "flex w-full items-center justify-between gap-4 border-b border-border px-4 py-3 text-left text-sm",
        onSelect &&
          "transition-colors hover:bg-muted focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring",
        active && "bg-muted",
      )}
    >
      <span className="flex items-center gap-2">
        <span>
          {row.original_name && <Struck>{row.original_name}</Struck>}
          {row.name}
        </span>
        {row.count > 0 && (
          <span className="inline-flex h-[18px] min-w-[18px] items-center justify-center rounded bg-destructive px-1 font-mono text-[11px] font-semibold text-white tabular-nums">
            {row.count}
          </span>
        )}
      </span>
      <span className="shrink-0 font-mono font-medium tabular-nums">
        {row.original_amount != null && <Struck>{amount(row.original_amount)}</Struck>}
        {amount(row.amount)}
      </span>
    </Cell>
  );
}

/** THE MONTH, RESTATED — the table that makes the last cutoff a picture of the whole month. */
function SalarySummary({ summary }: { summary: PayslipMonthSummary }) {
  return (
    <Table heading="Salary Summary">
      {summary.periods.map((period, i) => (
        <Row
          key={`${period.cycle_start}-${i}`}
          label={`${period.is_current ? "Current Salary" : "Last Salary"} (${period.label})`}
          value={amount(period.current_amount)}
        />
      ))}
      <Row label="Total Amount" value={amount(summary.total_current)} total />
    </Table>
  );
}

function Table({
  heading,
  children,
  className,
}: {
  heading: string;
  children: ReactNode;
  className?: string;
}) {
  return (
    <div className={cn("flex flex-col", className)}>
      {/*
       * Section titles carry the document's structure, so they sit a shade larger and heavier
       * than the rows beneath them — enough to scan by, not enough to compete with the net-pay
       * bar. Matched to the PDF's headings so the two surfaces read the same.
       */}
      <div className="flex items-center justify-between border-b border-border bg-muted px-4 py-2.5 font-mono text-sm font-extrabold uppercase tracking-wide">
        <span>{heading}</span>
        <span>Amount</span>
      </div>
      {children}
    </div>
  );
}

/**
 * A subtotal the walk pauses on — quieter than the net bars, louder than a table row.
 *
 * Total Current Amount and Initial Net Salary are not tables and not conclusions: they are the two
 * points where the document stops and states where the money has got to.
 */
function HighlightBar({ label, value }: { label: string; value: string }) {
  return (
    <div className="flex flex-wrap items-center justify-between gap-2 rounded-lg bg-muted px-4 py-3.5">
      <p className="font-mono text-sm font-extrabold uppercase tracking-wide">{label}</p>
      <p className="font-mono text-base font-extrabold tabular-nums">{value}</p>
    </div>
  );
}

/** A conclusion: what was actually paid. */
function NetBar({ label, value }: { label: string; value: string }) {
  return (
    <div className="flex flex-wrap items-center justify-between gap-2 rounded-lg bg-foreground px-6 py-5 text-background">
      <p className="font-mono text-lg font-bold uppercase">{label}</p>
      <p className="font-mono text-2xl font-extrabold tabular-nums">PHP {value}</p>
    </div>
  );
}

/** What was transferred versus what the payslip now says — printed only when they differ. */
function SettlementNote({ payslip }: { payslip: Payslip }) {
  const paid = payslip.disbursed_amount;
  const outstanding = payslip.amount_outstanding;
  if (paid === null || !differs(paid, payslip.net_pay)) return null;

  // Positive is the ordinary direction: a correction added money after the transfer. Negative would
  // mean the transfer exceeded the net, which is an overpayment — stated, never netted off.
  const overpaid = outstanding < 0;

  return (
    <div className="flex flex-wrap items-center justify-between gap-4 rounded-lg border border-warning bg-warning-subtle px-4 py-3">
      <div>
        <p className="text-sm font-semibold">
          {overpaid ? "Paid more than this payslip now shows" : "Still to be paid"}
        </p>
        <p className="text-caption text-muted-foreground">
          {amount(paid)} was paid out on {longDate(payslip.disbursed_at)}. This payslip was corrected
          afterwards, so the net above is not the amount that was transferred.
          {overpaid
            ? " The difference was overpaid and is not recovered automatically."
            : " The difference is still owed."}
        </p>
      </div>
      <p className="shrink-0 font-mono text-sm font-bold tabular-nums">
        {amount(Math.abs(outstanding))}
      </p>
    </div>
  );
}

/**
 * THAT THIS DOCUMENT WAS AMENDED, AND WHEN — one sentence, in with the rest of the provenance.
 *
 * Counts every amendment, not only the ones that changed a line that was already here. A payslip
 * that had a line ADDED to it has been amended just as much, and used to carry no stamp at all.
 */
function AmendedNote({ amendments }: { amendments: Payslip["amendments"] }) {
  const all = amendments ?? [];

  if (all.length === 0) return null;

  const corrected = all.filter((a) => a.target_key !== null).length;
  const added = all.length - corrected;
  const latest = all.reduce((a, b) => ((a.issued_on ?? "") > (b.issued_on ?? "") ? a : b));

  // Said as what actually happened: three lines corrected and one added is not "4 lines corrected".
  const what = [
    corrected > 0 && `${corrected} line${corrected === 1 ? "" : "s"} corrected`,
    added > 0 && `${added} line${added === 1 ? "" : "s"} added`,
  ]
    .filter(Boolean)
    .join(" · ");

  return (
    <Meta label="Amended">
      <span className="text-xs font-normal">
        {what}
        {latest.issued_on ? ` · ${longDate(latest.issued_on)}` : null}
        {latest.issued_by ? ` · ${latest.issued_by}` : null}
      </span>
    </Meta>
  );
}

/**
 * A line an amendment ADDED, rather than one it corrected — on either side of the document.
 *
 * Not correctable, and the API says so both times. A correction replaces a figure the ENGINE
 * produced, measured against the payslip's engine snapshot; an added line is not in that snapshot
 * by definition. An added earning is refused as "That is not a line on a payslip" (its
 * `amendment:` key is not in the line catalogue), an added deduction as "This payslip has no such
 * line". Offering the control anyway is how HR reached both. Not selectable is the honest state
 * until an added line has an edit-and-remove control of its own.
 */
function isAddedLine(key: string): boolean {
  return key.startsWith("amendment:") || key.startsWith("other:amend-");
}

function Row({
  label,
  note,
  value,
  originalValue,
  originalLabel,
  onSelect,
  active = false,
  total = false,
  pair = false,
}: {
  label: string;
  /** Qualifier shown beside the label, e.g. the hours behind an overtime figure. */
  note?: string;
  value: string;
  /** The engine's own figure, struck through beside the corrected one. */
  originalValue?: string | null;
  /** The line's own wording, when HR renamed it as well as re-priced it. */
  originalLabel?: string | null;
  /** Present only while HR is correcting this payslip — see PayslipEditMode. */
  onSelect?: () => void;
  active?: boolean;
  total?: boolean;
  /** A `SubRow` follows: drop this row's rule so the two read as one unit. */
  pair?: boolean;
}) {
  const body = (
    <>
      <span>
        {originalLabel && <Struck>{originalLabel}</Struck>}
        {label}
        {note && <span className="ml-1.5 text-muted-foreground">({note})</span>}
      </span>
      <span className={cn("shrink-0 font-mono tabular-nums", total ? "font-bold" : "font-medium")}>
        {originalValue && <Struck>{originalValue}</Struck>}
        {value}
      </span>
    </>
  );

  const shape = cn(
    "flex w-full items-center justify-between gap-4 px-4 py-3 text-left text-sm",
    !total && !pair && "border-b border-border",
    pair && "pb-0",
    total && "font-bold",
  );

  // A row is a BUTTON only while HR is correcting the payslip. On the employee's copy — and on
  // every total — it stays a div, so there is nothing to tab onto and nothing to click.
  if (!onSelect) {
    return <div className={shape}>{body}</div>;
  }

  return (
    <button
      type="button"
      onClick={onSelect}
      className={cn(
        shape,
        "transition-colors hover:bg-muted focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring",
        active && "bg-muted",
      )}
    >
      {body}
    </button>
  );
}

/** The year-end tax settlement, shown as its derivation rather than as one figure. */
function TaxSettlement({
  basis,
  amount,
}: {
  basis?: TaxAnnualisationBasis | null;
  amount: (n: number) => string;
}) {
  if (!basis) return null;

  const refund = basis.settled_this_period < 0;

  return (
    <div className="border-b border-border px-4 pb-3 pt-2 pl-9">
      <p className="text-caption font-semibold text-foreground">
        Year-end tax settlement · {basis.year}
      </p>
      <SettlementLine label="Taxable pay for the year" value={amount(basis.year_taxable)} />
      <SettlementLine label="Tax due on that" value={amount(basis.annual_tax_due)} />
      <SettlementLine label="Already withheld this year" value={amount(basis.previously_withheld)} />
      <SettlementLine
        label={refund ? "Refunded on this payslip" : "Collected on this payslip"}
        value={amount(Math.abs(basis.settled_this_period))}
        strong
      />
      <p className="pt-1 text-[0.6875rem] text-muted-foreground">
        {refund
          ? "Tax is withheld each period on an estimate of the year. Yours came out higher than the year needed, so the difference is paid back here."
          : "Tax is withheld each period on an estimate of the year. Yours came out lower than the year needed, so the difference is collected here."}
      </p>
    </div>
  );
}

function SettlementLine({
  label,
  value,
  strong,
}: {
  label: string;
  value: string;
  strong?: boolean;
}) {
  return (
    <div className="flex items-baseline justify-between gap-4 text-caption">
      <span className={strong ? "font-medium text-foreground" : "text-muted-foreground"}>
        {label}
      </span>
      <span
        className={cn(
          "shrink-0 font-mono tabular-nums",
          strong ? "font-semibold text-foreground" : "text-muted-foreground",
        )}
      >
        {value}
      </span>
    </div>
  );
}

/** A note ON the row above, not a row of its own — the employer's counterpart contribution. */
/**
 * The dated occurrences behind one earnings line: what the day was, the rate it paid, and what
 * it was worth. One row per day rather than a single joined string — unlike a deduction's dates,
 * each of these carries its own money, and running them together would leave the reader dividing
 * a total by an unknown number of unequal parts.
 */
function SubRow({ label, value }: { label: string; value: string }) {
  return (
    <div className="flex items-center justify-between gap-4 border-b border-border px-4 pb-2.5 pl-9 text-caption italic text-muted-foreground">
      <span>{label}</span>
      <span className="shrink-0 font-mono tabular-nums">{value}</span>
    </div>
  );
}

/** A small uppercase caption over its value — the document's label style. */
function Meta({
  label,
  children,
  align = "left",
}: {
  label: string;
  children: ReactNode;
  align?: "left" | "right";
}) {
  return (
    <div className={cn("flex flex-col gap-1", align === "right" && "items-end text-right")}>
      <span className="font-mono text-[10px] uppercase text-muted-foreground">{label}</span>
      <span className="text-sm font-semibold">{children}</span>
    </div>
  );
}

function Info({
  label,
  value,
  mono = false,
}: {
  label: string;
  value: string | null;
  /** Rates and figures are read by column, so they get the tabular face. */
  mono?: boolean;
}) {
  return (
    <div className="min-w-0 flex-1">
      <p className="font-mono text-[10px] font-semibold uppercase text-muted-foreground">
        {label}
      </p>
      <p
        className={cn(
          "mt-1 break-words text-sm font-semibold",
          mono && "font-mono tabular-nums",
        )}
      >
        {value || "—"}
      </p>
    </div>
  );
}

function Signature({ name, role }: { name: string | null; role: string }) {
  return (
    <div className="flex flex-col items-center gap-1">
      <div className="h-10 w-[160px] border-b border-foreground" />
      <p className="text-[10px] uppercase">{name || " "}</p>
      <p className="font-mono text-[10px] uppercase text-muted-foreground">{role}</p>
    </div>
  );
}
