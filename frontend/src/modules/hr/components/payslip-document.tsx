"use client";

import { Fragment, type ReactNode } from "react";

import { cn } from "@/lib/utils";
import { payFrequencyLabel } from "@/modules/hr/types/employee";
import { CONTRIBUTION_LABELS } from "@/modules/hr/types/benefits";
import { formatPeso } from "@/core/lib/format";
import type {
  Payslip,
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
 *
 * IT IS PURELY PRESENTATIONAL. No fetching, no mutations, no buttons — the surrounding page
 * owns those. That is what lets the same markup serve a page, a modal and a print target
 * without any of them leaking into the others.
 *
 * THE WALK IT PRINTS (the 2026-08 redesign). The document now moves in four steps rather than
 * settling everything at once, because that is the order the money actually happens in:
 *
 *     earnings − deductions   → Total Current Amount
 *     less contributions & tax → Initial Net Salary
 *     plus allowances          → Total Net
 *
 * Allowances sit BELOW the contributions rather than among the earnings, which is what the
 * separation is for: they are paid on top of a salary that was already taxed, and printing them
 * inside Compensation made them look like part of the base the deductions were taken from.
 *
 * THE MONTH. With HR's timing rules set to `last`, a month's contributions, tax and allowances all
 * land on its final cutoff and no single slip is a picture of the month. That slip therefore
 * carries `month_summary`: the earlier cutoffs restated, totalled, and walked through the same
 * four steps, ending in TOTAL MONTH SALARY. Every figure in it is summed across the month's slips
 * by the API — never inferred from this one, which would be right only under `last`.
 *
 * WHAT IT DELIBERATELY DOES NOT SHOW (trimmed 2026-08-14, Joshua's call). The identity block's
 * government numbers and address, the computation-basis table, and the one-line derivation under
 * each statutory contribution — "2% of ₱10,000.00, the maximum this contribution is charged on" —
 * are all gone. The document states what was earned, what was taken and what was paid; the
 * workings live on the screens that own them.
 *
 * The withholding derivation and the year-end settlement stay, because they are not workings in
 * the same sense: December's tax line can go NEGATIVE and make net pay exceed gross, and nothing
 * else on the page would account for that. So does the disclosure when pay could not cover a
 * deduction, which is a debt the employee still owes.
 *
 * Company details are literals because there is no company-settings table — the same reason
 * the PDF has always hardcoded them.
 */

const COMPANY_NAME = "VITE SEO DIGITAL ADVERTISING OPC";
const COMPANY_ADDRESS =
  "26L Burgundy Corporate Tower, 252 Sen. Gil Puyat Avenue, Pio Del Pilar 1230 City of Makati NCR, Fourth District Philippines";

/**
 * The rows the Compensation table shows even at zero, in the order it shows them.
 *
 * A reader checking a payslip is checking a list they already expect. A missing "Overtime" row is
 * indistinguishable from overtime that was worked and silently dropped, so on an ordinary period
 * the skeleton is printed whole and anything else the run paid is appended after it.
 */
const EARNING_SKELETON = ["Basic Pay", "Overtime", "Night Differential", "Rest Day", "Holiday"];

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

export function PayslipDocument({
  payslip,
  variant = "employee",
  className,
}: {
  payslip: Payslip;
  /** HR additionally sees the employer's counterpart contributions. */
  variant?: "employee" | "hr";
  className?: string;
}) {
  const p = payslip;
  // Derived by the API, not here — the PDF renders the same figures and the arithmetic is
  // subtle enough (see PayslipResource::documentTotals) that two implementations would drift.
  const t = p.totals;
  const month = p.month_summary;

  const employerTotal =
    (p.employer_sss ?? 0) + (p.employer_philhealth ?? 0) + (p.employer_pagibig ?? 0);
  const showEmployer = variant === "hr" && employerTotal > 0;
  // Slice B7 — snapshotted on the payslip at run time, not read from the profile now: the
  // override may since have been cleared, and this payslip still charged what it charged.
  const overridden = p.contribution_overrides ?? [];
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
        <p className="text-center text-lg font-extrabold uppercase tracking-tight sm:text-xl">
          {COMPANY_NAME}
        </p>
        <p className="max-w-[36rem] text-center text-caption text-muted-foreground">
          {COMPANY_ADDRESS}
        </p>
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
          {/* FIN-011 — the server-resolved label. `frequency` on a final-pay run is
              "final_pay:<employee id>", an internal key with an id in it. */}
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
        {/* The three rates the deduction arithmetic below is quoted in. Per-hour follows the run's
            own hours-per-day divisor, so it reconciles with the late/undertime charge.

            EM DASHES, NOT ZEROES, when there is no daily rate. Management staff are not paid by
            the day and their payslips carry `daily_rate` 0 — printing "Per Day 0.00" on one states
            that a day of their time is worth nothing, rather than that the figure does not apply
            to them. Payslips predating the attendance columns are in the same position. */}
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
            <Row
              key={line.name}
              label={line.name}
              note={line.note ?? undefined}
              value={amount(line.amount)}
            />
          ))}
          <Row label="Total Compensation" value={amount(t.earnings)} total />
        </Table>

        <Table heading="Deductions">
          {deductionRows(p).map((row, i) => (
            <DeductionRow key={`${row.key}-${row.name}-${i}`} row={row} />
          ))}
          <Row label="Total Deduction" value={amount(t.deductions)} total />
        </Table>

        <HighlightBar label="Total Current Amount" value={amount(t.current)} />

        {/* The month, restated. Only on its last cutoff, and only when an earlier one ran. */}
        {month && <SalarySummary summary={month} />}

        <Table heading="Government Benefits & Tax">
          {/* Each statutory line carries the employer's counterpart directly beneath it.
              Beside the employee's own figure it means something — ₱875 of their pay went to
              SSS and the company put ₱1,765 in alongside it — which is why it lives here
              rather than in a detached block of its own. Never in the total. */}
          {(
            [
              ["SSS", p.sss, p.employer_sss],
              ["PhilHealth", p.philhealth, p.employer_philhealth],
              ["Pag-IBIG", p.pagibig, p.employer_pagibig],
            ] as const
          ).map(([label, employee, employer]) => (
            <Fragment key={label}>
              <Row label={label} value={amount(employee)} pair={(employer ?? 0) > 0} />
              {(employer ?? 0) > 0 && (
                <SubRow label="+ employer share" value={amount(employer ?? 0)} />
              )}
            </Fragment>
          ))}
          <Row label="Withholding Tax" value={amount(p.withholding_tax)} pair={!!p.tax_basis} />
          {/* FIN-055 — the least checkable figure on the document: an annualised projection of
              one period, net of contributions, against a dated bracket table. Four steps, none
              of them visible, ending in a peso amount the reader was expected to accept. */}
          <TaxBasisNote basis={p.tax_basis} />
          {/* FIN-054 — December folds the year's correction into the line above, and on the
              common case it goes NEGATIVE and net pay exceeds gross. Naming the adjustment is
              not enough: a lone "refund ₱5,432" is the same opacity moved somewhere else. These
              are the figures it was computed from, so the employee can add it up — and they are
              the same ones BIR 2316 will show them. */}
          <TaxSettlement basis={p.tax_annualisation_basis} amount={amount} />
          {/* When the month's contributions did not all land here, BOTH figures are printed: the
              rows above sum to the first, and the second is what the walk below continues from.
              One row labelled "monthly" sitting under rows that do not add up to it would be the
              one error a payslip cannot make. */}
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
              <Row key={line.name} label={line.name} value={amount(line.amount)} />
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
          Disclosure, not a term in the total (FIN-053). The figures above are what was actually
          collected, so this is no longer credited back — it is shown because the employee is
          entitled to know a deduction they owe went unpaid, and which one.
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
        {/* The month total sits BELOW this cutoff's net, not instead of it: the employee is owed
            both answers — what lands in their account now, and what the month came to. */}
        {month && <NetBar label="Total Month Salary" value={amount(month.total_month)} />}

        {/*
          WHAT WAS ACTUALLY TRANSFERRED, when the net above is no longer that figure.

          Silent on an ordinary payslip, where the net IS what was paid. It appears when a
          correction landed after payment: the bar above then states an amount larger than the one
          in the employee's account, and without this the document would be quietly wrong about the
          only fact the reader can verify against their bank.
        */}
        <SettlementNote payslip={p} />

        {/* The corrections themselves — each with its date, so an employee who has already read
            this payslip once can see what changed since. */}
        <AmendmentHistory amendments={p.amendments ?? []} />

        {/* HR keeps ONE extra thing the employee has no use for: the employer TOTAL, which is
            what gets filed. The three components are already inline above for both audiences,
            so a standalone "Employer Share (for remittance)" table would repeat them. */}
        {showEmployer && (
          <p className="px-1 text-caption text-muted-foreground">
            Total employer share for remittance:{" "}
            <span className="font-mono font-semibold text-foreground tabular-nums">
              {amount(employerTotal)}
            </span>
          </p>
        )}
      </div>

      {/*
        SUPPORTING DETAIL — the one thing left that is a warning rather than a figure.

        The computation-basis table and the per-contribution derivations that used to sit here were
        removed on 2026-08-14 at Joshua's request: the document states what was paid and taken, and
        the workings belong on the screens that own them. What survives is not a derivation — it is
        a statement that a figure above cannot be filed as it stands.
      */}
      <div className="flex flex-col gap-6">
        {/* Slice B7 — an override replaces the EMPLOYEE half only, so the pair above belongs to
            no single salary bracket and cannot be filed as it stands. HR-only: the employee
            cannot act on it, and it must not appear on the copy they receive. */}
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
        </div>
        <Signature name={p.processed_by} role="HR Manager" />
        <Signature name={p.employee.name} role="Employee" />
      </div>
    </div>
  );
}

/**
 * The Compensation table's rows: the ordinary period's skeleton, then whatever else this run paid.
 *
 * Only an ordinary period HAS a skeleton. A 13th-month, adjustment or final-pay run has no basic
 * pay and no overtime, and printing five zero rows above the one line that carries the money would
 * describe the slip as an ordinary period that paid nothing — which is the exact defect FIN-011
 * fixed by introducing `earnings_breakdown` in the first place.
 */
function earningRows(p: Payslip): PayslipLine[] {
  const earnings = p.earnings_breakdown.filter((line) => line.kind !== "allowance");

  if (p.run_type !== "regular") {
    return earnings;
  }

  const skeleton = EARNING_SKELETON.map(
    (name) => earnings.find((line) => line.name === name) ?? { name, amount: 0, note: null },
  );

  return [...skeleton, ...earnings.filter((line) => !EARNING_SKELETON.includes(line.name))];
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

/**
 * One reason the period was charged for, with the dates behind it.
 *
 * The count badge and the date line are the whole point of the row: "Absences 1,316.00" is a
 * figure the reader has to take on trust, and "Absences ②, Aug 5, Aug 10" is one they can check
 * against their own memory of the month.
 */
function DeductionRow({ row }: { row: PayslipDeductionRow }) {
  const dates = row.dates ?? [];
  const isTime = row.minutes !== null && row.minutes !== undefined;

  return (
    <>
      <div
        className={cn(
          "flex items-center justify-between gap-4 px-4 py-3 text-sm",
          dates.length === 0 && "border-b border-border",
          dates.length > 0 && "pb-1",
        )}
      >
        <span className="flex items-center gap-2">
          {row.name}
          {row.count > 0 && (
            <span className="inline-flex h-[18px] min-w-[18px] items-center justify-center rounded bg-destructive px-1 font-mono text-[11px] font-semibold text-white tabular-nums">
              {row.count}
            </span>
          )}
        </span>
        <span className="shrink-0 font-mono font-medium tabular-nums">{amount(row.amount)}</span>
      </div>
      {dates.length > 0 && (
        <div className="flex items-baseline justify-between gap-4 border-b border-border px-4 pb-2.5 text-caption text-muted-foreground">
          <span>{describeDates(row)}</span>
          {/* The minute total belongs beside the dates, not on the money row — it is what the
              dates add up to, and the money row already carries what the minutes cost. */}
          {isTime && (row.minutes ?? 0) > 0 && (
            <span className="shrink-0 font-mono tabular-nums">{row.minutes} min</span>
          )}
        </div>
      )}
    </>
  );
}

/** "Aug 5, Aug 10" for a day charge; "Aug 1 180m · Aug 5 120m" once minutes are what varies. */
function describeDates(row: PayslipDeductionRow): string {
  const isTime = row.minutes !== null && row.minutes !== undefined;

  return (row.dates ?? [])
    .map((d) => {
      const day = d.date ? shortDay(d.date) : "—";
      if (!isTime) return day;
      const minutes = d.detail?.match(/^(\d+)\s*min/)?.[1];
      return minutes ? `${day} ${minutes}m` : day;
    })
    .join(isTime ? " · " : ", ");
}

/**
 * THE MONTH, RESTATED — the table that makes the last cutoff a picture of the whole month.
 *
 * Each earlier cutoff is restated by its TOTAL CURRENT AMOUNT rather than by its net pay. The two
 * are equal only on a cutoff that took no contributions, which is precisely the case this must not
 * silently depend on: under HR's `every` timing the earlier slips take contributions of their own,
 * and adding a net to a pre-contribution subtotal would double-count them out of the month total.
 */
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
      {/* Section titles carry the document's structure, so they sit a shade larger and heavier
          than the rows beneath them — enough to scan by, not enough to compete with the net-pay
          bar. Matched to the PDF's headings so the two surfaces read the same. */}
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

/**
 * What was transferred versus what the payslip now says — printed only when they differ.
 *
 * THE CASE THIS EXISTS FOR. A payslip is paid, and a correction is applied to it afterwards. Its net
 * rises, but the transfer already happened at the old figure; the amount in the employee's account
 * is the one thing on this document they can independently check, and the net bar above no longer
 * matches it. So both figures are stated, each with its date, and the difference is named as a
 * balance rather than left to be inferred from two numbers that disagree.
 *
 * Silent on an unpaid payslip (there is nothing to reconcile yet) and on a paid one that was never
 * corrected, which is nearly all of them — `disbursed_amount` equals `net_pay` there, and a bar
 * repeating the figure above it would be noise on every payslip the company issues.
 */
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
 * Every correction on this payslip, in the order they were issued.
 *
 * The amounts are already in the Compensation table as their own lines — this is the provenance:
 * when each was issued, why, and by whom. An employee re-reading a payslip whose figures have moved
 * is entitled to see that it was corrected rather than to wonder whether they misread it the first
 * time.
 */
function AmendmentHistory({ amendments }: { amendments: Payslip["amendments"] }) {
  if (!amendments || amendments.length === 0) return null;

  return (
    <div className="rounded-lg border border-border px-4 py-3">
      <p className="text-sm font-semibold">
        Corrections to this payslip ({amendments.length})
      </p>
      <ul className="mt-2 flex flex-col gap-2">
        {amendments.map((a) => (
          <li key={a.id} className="flex flex-wrap items-baseline justify-between gap-2">
            <span className="text-caption text-muted-foreground">
              <span className="font-medium text-foreground">{longDate(a.issued_on)}</span>
              {" — "}
              {a.note ?? a.reason}
              {` · ${a.kind_label}`}
              {a.issued_by && ` · ${a.issued_by}`}
            </span>
            {/* A correction can take money as well as give it, so the sign is printed rather than
                assumed — and a reversal's tax comes BACK, which is the opposite footnote. */}
            <span className="font-mono text-caption tabular-nums">
              {a.amount < 0 ? "−" : "+"}
              {amount(Math.abs(a.amount))}
              {a.tax > 0 && (
                <span className="text-muted-foreground"> less {amount(a.tax)} tax</span>
              )}
              {a.tax < 0 && (
                <span className="text-muted-foreground">
                  {" "}
                  plus {amount(Math.abs(a.tax))} tax refunded
                </span>
              )}
            </span>
          </li>
        ))}
      </ul>
    </div>
  );
}

function Row({
  label,
  note,
  value,
  total = false,
  pair = false,
}: {
  label: string;
  /** Qualifier shown beside the label, e.g. the hours behind an overtime figure. */
  note?: string;
  value: string;
  total?: boolean;
  /** A `SubRow` follows: drop this row's rule so the two read as one unit. */
  pair?: boolean;
}) {
  return (
    <div
      className={cn(
        "flex items-center justify-between gap-4 px-4 py-3 text-sm",
        !total && !pair && "border-b border-border",
        pair && "pb-0",
        total && "font-bold",
      )}
    >
      <span>
        {label}
        {note && <span className="ml-1.5 text-muted-foreground">({note})</span>}
      </span>
      <span className={cn("shrink-0 font-mono tabular-nums", total ? "font-bold" : "font-medium")}>
        {value}
      </span>
    </div>
  );
}

/**
 * The year-end tax settlement, shown as its derivation rather than as one figure (FIN-054).
 *
 * Every period withholds by projecting that period across the year, which is only right if every
 * period is identical — they never are. December settles the difference. The result is usually a
 * REFUND, which turns Withholding Tax negative and makes net pay larger than gross; without this
 * block the employee sees both and has nothing to reconcile them against.
 *
 * Renders only on the payslip that closes a year. Payslips written before the figures were stored
 * show nothing rather than a reconstruction, which would change as later runs alter the year.
 */
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

/** "Aug 5" — the payslip's dates are all inside one period, so the year is noise. */
function shortDay(iso: string): string {
  const [y, m, d] = iso.split("-").map(Number);
  return new Date(y, (m ?? 1) - 1, d ?? 1).toLocaleDateString("en-PH", {
    month: "short",
    day: "numeric",
  });
}

/**
 * How the withholding figure was reached, in two lines the reader can follow with a calculator.
 *
 * The projection first (what a year of this period would be), then the bracket it landed in.
 * Both are needed: the annual figure alone does not say why the rate is what it is, and the
 * bracket alone does not say what it was applied to.
 */
function TaxBasisNote({ basis }: { basis: Payslip["tax_basis"] }) {
  if (!basis) return null;

  const rate = basis.band ? `${Number((basis.band.rate * 100).toFixed(3))}%` : null;

  return (
    <div className="space-y-0.5 border-b border-border px-4 pb-2.5 pl-9 text-caption text-muted-foreground">
      <p>
        ({formatPeso(basis.taxable_monthly)} taxable − {formatPeso(basis.contributions_monthly)}{" "}
        contributions) × 12 = {formatPeso(basis.annual_taxable)} a year
      </p>
      {basis.band && (
        <p>
          {rate} over {formatPeso(basis.band.min)}
          {basis.band.base > 0 ? ` plus ${formatPeso(basis.band.base)}` : ""} ={" "}
          {formatPeso(basis.annual_tax)} a year ÷ 12
          {basis.timing_factor !== 1 ? ` × ${basis.timing_factor}` : ""}
        </p>
      )}
    </div>
  );
}

/**
 * A note ON the row above, not a row of its own — the employer's counterpart contribution.
 *
 * Indented, smaller, italic and muted: four signals at once that this belongs to the line above
 * and is not another deduction. It is never in the government total — that figure is
 * `totals.government`, computed by the API from the employee's own deductions, so there is no path
 * by which this reaches it. The styling says so; the arithmetic enforces it.
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
