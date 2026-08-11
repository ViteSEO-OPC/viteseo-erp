"use client";

import { formatPeso, formatDate } from "@/core/lib/format";
import {
  CONTRIBUTION_LABELS,
  type ContributionSetting,
} from "@/modules/hr/types/benefits";

/** Government dot colours (SSS / PhilHealth / Pag-IBIG) per the design. */
export const GOV_COLOR: Record<string, string> = {
  sss: "#a855f7",
  philhealth: "#f97316",
  pagibig: "#14b8a6",
};

/** 0.045 → "4.5%" */
export function formatContributionPct(rate: number): string {
  const pct = rate * 100;
  return `${Number.isInteger(pct) ? pct : Number(pct.toFixed(2))}%`;
}

/**
 * One statutory contribution, as a read-only card.
 *
 * Shared by the HR Company → Benefits screen and the employee's My Benefits screen so the two
 * cannot drift: the employee is being shown the rate that produced the deduction on their own
 * payslip, and a second copy of this markup would eventually disagree with the first.
 *
 * `action` is the only difference between the two views — HR passes an "Update rates" button,
 * the employee passes nothing. The card itself never edits.
 */
export function GovernmentContributionCard({
  setting,
  action,
}: {
  setting: ContributionSetting;
  /** Rendered under the figures. Omitted on the employee view, which is display-only. */
  action?: React.ReactNode;
}) {
  // Slice B3a/b — the version in force, NOT the legacy `config`. Reading `config` here meant the
  // card showed 4.5% while payroll charged 5% after a rate change.
  const current = setting.current;
  const rate = Number(current?.employee_rate ?? 0);
  const employer = Number(current?.employer_rate ?? 0);
  const label = CONTRIBUTION_LABELS[setting.type] ?? setting.type;

  // How it's charged, in one line — so the card explains itself without opening a dialog.
  const basis =
    current?.basis === "bracket"
      ? `${current.bracket_count} salary brackets`
      : current?.employee_amount_cap
        ? `max ${formatPeso(current.employee_amount_cap)}/mo`
        : current?.salary_cap
          ? `up to ${formatPeso(current.salary_cap)} salary`
          : "uncapped";

  return (
    <div className="rounded-card border border-border bg-card p-5">
      <div className="flex items-center gap-2.5">
        <span
          className="mt-1 size-3 shrink-0 rounded-full"
          style={{ backgroundColor: GOV_COLOR[setting.type] ?? "#94a3b8" }}
        />
        <div>
          <p className="font-bold text-foreground">{label}</p>
          <p className="text-xs text-muted-foreground">Government · Mandatory</p>
        </div>
      </div>
      <p className="mt-4 text-3xl font-bold text-foreground">{formatContributionPct(rate)}</p>
      <p className="mt-1 text-xs text-muted-foreground">
        Employee{employer ? ` · Employer ${formatContributionPct(employer)}` : ""} · {basis}
      </p>
      {current && (
        <p className="mt-0.5 text-xs text-muted-foreground">
          In force since {formatDate(current.effective_from)}
          {current.fixed_employer_add_on > 0 &&
            ` · +${formatPeso(current.fixed_employer_add_on)} employer`}
        </p>
      )}
      {action}
    </div>
  );
}
