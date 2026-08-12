"use client";

import { Fragment, type ReactNode } from "react";

import { cn } from "@/lib/utils";
import { payFrequencyLabel } from "@/modules/hr/types/employee";
import { CONTRIBUTION_LABELS } from "@/modules/hr/types/benefits";
import { formatPeso } from "@/core/lib/format";
import type {
  ContributionBasis,
  Payslip,
  PayslipDeductionKey,
  PayslipLine,
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
 * WHAT IT SHOWS. Every factor that moved the money, not just the headline figures: the full
 * period basic with the absence charged against it, each allowance component by name, all four
 * premium lines, every statutory and non-statutory deduction, and the counts and rates the
 * peso figures were derived from.
 *
 * Company details are literals because there is no company-settings table — the same reason
 * the PDF has always hardcoded them.
 */

const COMPANY_NAME = "VITE SEO";
const COMPANY_ADDRESS = "Makati City, Metro Manila, Philippines";

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

function hours(minutes: number): string {
  return (minutes / 60).toFixed(1);
}

/** Trims trailing zeros off a day count: 1.00 → "1", 1.50 → "1.5". */
function days(n: number): string {
  return String(Number(n.toFixed(2)));
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

  const employerTotal =
    (p.employer_sss ?? 0) + (p.employer_philhealth ?? 0) + (p.employer_pagibig ?? 0);
  const showEmployer = variant === "hr" && employerTotal > 0;
  // Slice B7 — snapshotted on the payslip at run time, not read from the profile now: the
  // override may since have been cleared, and this payslip still charged what it charged.
  const overridden = p.contribution_overrides ?? [];
  // Slice B19 — the monthly salary each contribution was charged on, snapshot at run time. Shown
  // only when the three do NOT agree: with every contribution on the basic basis they are all the
  // same figure and printing it three times would be noise. They diverge once one is moved onto
  // actual earnings, and that is exactly when a remittance query needs to see it.
  const bases = p.contribution_bases ?? null;
  const baseValues = bases ? Object.values(bases) : [];
  const basesDiffer =
    baseValues.length > 0 && new Set(baseValues.map((v) => Number(v).toFixed(2))).size > 1;

  return (
    <div
      className={cn(
        "flex w-full flex-col gap-8 rounded-xl bg-card p-6 text-foreground sm:p-12",
        className,
      )}
    >
      {/* Header */}
      <div className="flex flex-col items-center gap-2">
        <p className="text-2xl font-extrabold tracking-tight">{COMPANY_NAME}</p>
        <p className="text-center text-xs text-muted-foreground">{COMPANY_ADDRESS}</p>
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
          <Info label="Employee ID" value={p.employee.employee_no} />
        </div>
        <div className="flex flex-col gap-6 sm:flex-row">
          <Info label="Job Role" value={p.employee.job_title} />
          <Info label="Department" value={p.employee.department} />
        </div>
        <Info label="Address" value={p.employee.address} />
        <div className="border-t border-border" />
        <div className="grid grid-cols-2 gap-6 sm:grid-cols-4">
          <Info label="TIN" value={p.employee.tin} />
          <Info label="SSS #" value={p.employee.sss_number} />
          <Info label="PhilHealth" value={p.employee.philhealth_number} />
          <Info label="Pag-IBIG" value={p.employee.pagibig_number} />
        </div>
      </div>

      {/* The money */}
      <div className="flex flex-col gap-6">
        <Table heading="Compensation">
          {/* FIN-011 — one loop over `earnings_breakdown`, built by PayslipResource. The old
              hardcoded list was an ordinary period's earnings, so a 13th-month, adjustment or
              final-pay slip rendered "Basic Pay ₱0" and no line for what it actually paid. */}
          {p.earnings_breakdown.map((line: PayslipLine) => (
            <Row
              key={line.name}
              label={line.name}
              note={line.note ?? undefined}
              value={amount(line.amount)}
            />
          ))}
          <Row label="Total Compensation" value={amount(t.compensation)} total />
        </Table>

        <ComputationBasis payslip={p} />

        <div className="flex flex-col gap-6 lg:flex-row">
          <Table heading="Deductions" className="flex-1">
            {t.absences > 0 && <Row label="Absences" value={amount(t.absences)} />}
            {t.late_undertime > 0 && (
              <Row label="Late / Undertime" value={amount(t.late_undertime)} />
            )}
            {p.other_deductions_breakdown.map((line: PayslipLine) => (
              <Row key={line.name} label={line.name} value={amount(line.amount)} />
            ))}
            {t.deductions === 0 && <Row label="None" value={amount(0)} />}
            <Row label="Total Deductions" value={amount(t.deductions)} total />
          </Table>

          <Table heading="Gov Contributions & Tax" className="flex-1">
            {/* Each statutory line carries the employer's counterpart directly beneath it.
                Beside the employee's own figure it means something — ₱875 of their pay went to
                SSS and the company put ₱1,765 in alongside it — which is why it lives here
                rather than in a detached block of its own. Never in Total Gov. */}
            {(
              [
                ["SSS Contribution", "sss", p.sss, p.employer_sss],
                ["PhilHealth", "philhealth", p.philhealth, p.employer_philhealth],
                ["Pag-IBIG", "pagibig", p.pagibig, p.employer_pagibig],
              ] as const
            ).map(([label, type, employee, employer]) => (
              <Fragment key={label}>
                <Row label={label} value={amount(employee)} pair={(employer ?? 0) > 0} />
                {(employer ?? 0) > 0 && (
                  <SubRow label="+ employer share" value={amount(employer ?? 0)} />
                )}
                {/* FIN-049 — the rate alone does not explain the figure: 2% of a ₱95,000 salary
                    is not ₱200, because the rate is applied to a ₱10,000 cap. This names the
                    number the rate multiplied. */}
                <BasisNote basis={p.contribution_basis?.[type]} />
              </Fragment>
            ))}
            <Row label="Withholding Tax" value={amount(p.withholding_tax)} />
            {/* FIN-054 — December folds the year's correction into the line above, and on the
                common case it goes NEGATIVE and net pay exceeds gross. Naming the adjustment is
                not enough: a lone "refund ₱5,432" is the same opacity moved somewhere else. These
                are the figures it was computed from, so the employee can add it up — and they are
                the same ones BIR 2316 will show them. */}
            <TaxSettlement basis={p.tax_annualisation_basis} amount={amount} />
            <Row label="Total Gov" value={amount(t.government)} total />
          </Table>
        </div>

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
                    .map(([key, value]) => `${DEDUCTION_LABELS[key as PayslipDeductionKey] ?? key} ${amount(value)}`)
                    .join(" · ")}
                </p>
              )}
            </div>
            <p className="shrink-0 font-mono text-sm font-bold tabular-nums">
              {amount(t.uncollected)}
            </p>
          </div>
        )}

        <div className="flex flex-wrap items-center justify-between gap-2 rounded-lg bg-foreground px-6 py-5 text-background">
          <p className="font-mono text-lg font-bold uppercase">Total Net Pay</p>
          <p className="font-mono text-2xl font-extrabold tabular-nums">
            PHP {amount(p.net_pay)}
          </p>
        </div>

        {/* HR keeps ONE extra thing the employee has no use for: the employer TOTAL, which is
            what gets filed. The three components are already inline above for both audiences,
            so the old standalone "Employer Share (for remittance)" table was repeating them. */}
        {showEmployer && (
          <p className="px-1 text-caption text-muted-foreground">
            Total employer share for remittance:{" "}
            <span className="font-mono font-semibold text-foreground tabular-nums">
              {amount(employerTotal)}
            </span>
          </p>
        )}

        {/* Slice B19 — WHAT each contribution was charged on. Not derivable after the fact once a
            contribution follows actual earnings: the figure depends on this period's attendance and
            premium lines, and on a per-allowance switch HR can change later. HR-only: it is
            remittance workflow, not something the employee can act on. */}
        {variant === "hr" && basesDiffer && bases && (
          <Table heading="Charged on (monthly salary)">
            {(["sss", "philhealth", "pagibig"] as const).map((type) =>
              bases[type] === undefined ? null : (
                <Row
                  key={type}
                  label={CONTRIBUTION_LABELS[type] ?? type}
                  value={amount(bases[type] as number)}
                />
              ),
            )}
          </Table>
        )}

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
        <Signature name={p.processed_by} role="Human Resource" />
        <Signature name={p.employee.name} role="Employee" />
      </div>
    </div>
  );
}

/**
 * The counts and rates behind the peso figures — how many days the period had, what a day is
 * worth, and the attendance that moved it. Kept out of the money tables precisely because none
 * of these are amounts.
 */
function ComputationBasis({ payslip: p }: { payslip: Payslip }) {
  // Payslips that predate the attendance columns, and management staff, have nothing to show.
  if (p.working_days === 0) return null;

  return (
    <Table heading="Computation Basis">
      <Row label="Working days in period" value={String(p.working_days)} />
      <Row label="Daily rate" value={amount(p.daily_rate)} />
      {p.unpaid_days > 0 && <Row label="Unpaid days" value={days(p.unpaid_days)} />}
      {p.late_minutes > 0 && <Row label="Late" value={`${p.late_minutes} min`} />}
      {p.undertime_minutes > 0 && (
        <Row label="Undertime" value={`${p.undertime_minutes} min`} />
      )}
      {p.late_penalty_days > 0 && (
        <Row label="Tardiness penalty" value={`${days(p.late_penalty_days)} day`} />
      )}
      {p.overtime_minutes > 0 && (
        <Row label="Overtime rendered" value={`${hours(p.overtime_minutes)} hrs`} />
      )}
      {p.night_diff_minutes > 0 && (
        <Row label="Night hours" value={`${hours(p.night_diff_minutes)} hrs`} />
      )}
      {p.rest_day_minutes > 0 && (
        <Row label="Rest day worked" value={`${hours(p.rest_day_minutes)} hrs`} />
      )}
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
 * A note ON the row above, not a row of its own — the employer's counterpart contribution.
 *
 * Indented, smaller, italic and muted: four signals at once that this belongs to the line above
 * and is not another deduction. It is never in `Total Gov` — that figure is `totals.government`,
 * computed by the API from the employee's own deductions, so there is no path by which this
 * reaches it. The styling says so; the arithmetic enforces it.
 */
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

/**
 * How a statutory deduction was arrived at, in one line (FIN-049).
 *
 * WHY IT IS WORTH THE ROW. The three rates are public knowledge and still do not explain the
 * figures: Pag-IBIG's 2% is charged on a ₱10,000 cap, so a ₱95,000 earner pays ₱200 rather than
 * ₱1,900, and SSS multiplies a bracket's Monthly Salary Credit rather than the salary at all.
 * Both read as errors until someone names the amount the rate was applied to.
 *
 * Renders nothing when the payslip predates the snapshot, rather than inventing a derivation.
 */
function BasisNote({ basis }: { basis?: ContributionBasis }) {
  if (!basis) return null;

  const note = basis.overridden
    ? "Fixed amount set on this employee — the government rate was not applied"
    : describeBasis(basis);

  if (!note) return null;

  return (
    <div className="border-b border-border px-4 pb-2.5 pl-9 text-caption text-muted-foreground">
      {note}
    </div>
  );
}

function describeBasis(b: ContributionBasis): string | null {
  if (b.rate == null || b.charged_on == null) return null;

  // Trailing zeroes on a rate read as false precision — 2.5% and 2%, not 2.500% and 2.000%.
  const rate = `${Number((b.rate * 100).toFixed(3))}%`;
  const on = formatPeso(b.charged_on);

  if (b.basis === "bracket") {
    return `${rate} of ${on} — the salary bracket for ${formatPeso(b.salary ?? 0)}`;
  }

  if (b.capped) {
    return `${rate} of ${on} — the maximum this contribution is charged on`;
  }

  if (b.floored) {
    return `${rate} of ${on} — the minimum this contribution is charged on`;
  }

  return `${rate} of ${on}`;
}

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

function Info({ label, value }: { label: string; value: string | null }) {
  return (
    <div className="min-w-0 flex-1">
      <p className="font-mono text-[10px] font-semibold uppercase text-muted-foreground">
        {label}
      </p>
      <p className="mt-1 break-words text-sm font-semibold">{value || "—"}</p>
    </div>
  );
}

function Signature({ name, role }: { name: string | null; role: string }) {
  return (
    <div className="flex flex-col items-center gap-1">
      <div className="h-10 w-[160px] border-b border-foreground" />
      <p className="text-[10px] uppercase">{name || "\u00a0"}</p>
      <p className="font-mono text-[10px] uppercase text-muted-foreground">{role}</p>
    </div>
  );
}
