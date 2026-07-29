"use client";

import type { ReactNode } from "react";

import { cn } from "@/lib/utils";
import { payFrequencyLabel } from "@/modules/hr/types/employee";
import { CONTRIBUTION_LABELS } from "@/modules/hr/types/benefits";
import type { Payslip, PayslipLine } from "@/modules/hr/types/payroll";

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
          <Meta label="Pay Type">{payFrequencyLabel(p.frequency)}</Meta>
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
          <Row label="Basic Pay" value={amount(p.period_base)} />
          {p.allowances_breakdown.map((line: PayslipLine) => (
            <Row key={line.name} label={line.name} value={amount(line.amount)} />
          ))}
          {p.overtime_pay > 0 && (
            <Row
              label="Overtime"
              note={`${hours(p.overtime_minutes)} hrs`}
              value={amount(p.overtime_pay)}
            />
          )}
          {p.night_diff_pay > 0 && (
            <Row
              label="Night Differential"
              note={`${hours(p.night_diff_minutes)} hrs`}
              value={amount(p.night_diff_pay)}
            />
          )}
          {p.holiday_premium > 0 && <Row label="Holiday" value={amount(p.holiday_premium)} />}
          {p.rest_day_premium > 0 && (
            <Row
              label="Rest Day"
              note={`${hours(p.rest_day_minutes)} hrs`}
              value={amount(p.rest_day_premium)}
            />
          )}
          {p.reimbursements > 0 && (
            <Row label="Reimbursement" value={amount(p.reimbursements)} />
          )}
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
            <Row label="SSS Contribution" value={amount(p.sss)} />
            <Row label="PhilHealth" value={amount(p.philhealth)} />
            <Row label="Pag-IBIG" value={amount(p.pagibig)} />
            <Row label="Withholding Tax" value={amount(p.withholding_tax)} />
            <Row label="Total Gov" value={amount(t.government)} total />
          </Table>
        </div>

        {t.uncollected > 0 && (
          <div className="flex items-center justify-between gap-4 rounded-lg border border-warning bg-warning-subtle px-4 py-3">
            <div>
              <p className="text-sm font-semibold">Uncollected this period</p>
              <p className="text-caption text-muted-foreground">
                Pay did not cover the full deductions, so this amount was not taken.
              </p>
            </div>
            <p className="shrink-0 font-mono text-sm font-bold tabular-nums">
              + {amount(t.uncollected)}
            </p>
          </div>
        )}

        <div className="flex flex-wrap items-center justify-between gap-2 rounded-lg bg-foreground px-6 py-5 text-background">
          <p className="font-mono text-lg font-bold uppercase">Total Net Pay</p>
          <p className="font-mono text-2xl font-extrabold tabular-nums">
            PHP {amount(p.net_pay)}
          </p>
        </div>

        {showEmployer && (
          <Table heading="Employer Share (for remittance)">
            <Row label="SSS — employer" value={amount(p.employer_sss ?? 0)} />
            <Row label="PhilHealth — employer" value={amount(p.employer_philhealth ?? 0)} />
            <Row label="Pag-IBIG — employer" value={amount(p.employer_pagibig ?? 0)} />
            <Row label="Total Employer Share" value={amount(employerTotal)} total />
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
      <div className="flex items-center justify-between bg-muted px-4 py-2.5 font-mono text-xs font-bold uppercase">
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
}: {
  label: string;
  /** Qualifier shown beside the label, e.g. the hours behind an overtime figure. */
  note?: string;
  value: string;
  total?: boolean;
}) {
  return (
    <div
      className={cn(
        "flex items-center justify-between gap-4 px-4 py-3 text-sm",
        !total && "border-b border-border",
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
