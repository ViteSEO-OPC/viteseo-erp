"use client";

import { ChevronDown } from "lucide-react";

import { formatPeso } from "@/core/lib/format";
import type {
  PolicySnapshot,
  PolicySnapshotContributionVersion,
  RulesDriftEntry,
} from "@/modules/hr/types/payroll";

/**
 * "Rules used for this run" — the settings that were in force when a payroll run
 * executed, so an old payslip can always be explained.
 */

/** Field name → the words HR uses. Anything unlisted falls back to a de-slugged label. */
const LABELS: Record<string, string> = {
  late_deduction_mode: "Late deduction",
  late_tiers: "Late tiers",
  late_tier_recurring: "Late threshold repeats",
  late_penalty_enabled: "Full-day penalty per N lates",
  lates_per_deduction: "Lates per deduction",
  monthly_late_reset: "Late count resets monthly",
  late_minutes_from: "Late counted from",
  // Item 8 — a real pay rule that was missing here. Payroll resolves the DATED policy at cycle
  // end and prices an abandoned shift to the scheduled end using this allowance, so it genuinely
  // is one of the rules this run was computed under. (Its siblings `missing_clockout_mode` and
  // `early_arrival_mode` stay out: no engine reads either, so neither priced anything.)
  missing_timeout_allowance_minutes: "Wait after shift end before closing a missing time-out (minutes)",
  undertime_deduction_mode: "Undertime deduction",
  overtime_mode: "Overtime",
  overtime_threshold_minutes: "Minimum overtime (min)",
  auto_overtime_daily_cap_minutes: "Auto-OT cap per day (min)",
  auto_overtime_period_cap_minutes: "Auto-OT cap per period (min)",
  premium_rest_day_pct: "Rest day worked",
  premium_special_day_pct: "Special day worked",
  premium_regular_holiday_pct: "Regular holiday worked",
  overtime_ordinary_pct: "Overtime — ordinary day",
  overtime_rest_special_pct: "Overtime — rest / special day",
  overtime_regular_holiday_pct: "Overtime — regular holiday",
  night_diff_pct: "Night differential",
  night_diff_start_time: "Night differential starts",
  night_diff_end_time: "Night differential ends",
  daily_rate_basis: "Daily rate basis",
  annual_divisor: "Annual divisor",
  hours_per_day_divisor: "Hours per day",
  deduction_rounding: "Rounding",
  deduction_block_minutes: "Rounding block (min)",
  daily_deduction_cap: "Most deductible in a day",
  frequency: "Pay frequency",
  deduction_timing: "Deductions land on",
  tax_timing: "Tax lands on",
  allowance_timing: "Allowances land on",
};

/** Percent-valued fields, shown as "125%" rather than a bare number. */
const PERCENT = /_pct$/;

/**
 * Fields that only move money while a companion rule is switched on. Each is shown ONLY when
 * its gate is open — the same condition the engine itself checks.
 */
const GATES: Record<string, (p: Record<string, unknown>) => boolean> = {
  // DeductionService::applyRounding() — `exact` ignores the block entirely.
  deduction_block_minutes: (p) => p.deduction_rounding !== "exact",
  // DeductionService::tierMinutes() — the ladder is read only in `tiered` mode.
  late_tiers: (p) => p.late_deduction_mode === "tiered",
  // Same gate, same reason: outside `tiered` the flag repeats a rung nothing reads. Shown even
  // when off, because "charged once" is the fact that explains a flat deduction on the payslip.
  late_tier_recurring: (p) => p.late_deduction_mode === "tiered",
  // DeductionService:83 computes lateness — and so reads this — only in a charging mode.
  // With `none`, "Late counted from: start time" describes an unused measurement.
  late_minutes_from: (p) =>
    p.late_deduction_mode === "per_hour" || p.late_deduction_mode === "tiered",
  // PayrollService::dailyRateFor():427 — the divisor is read ONLY on the `fixed_divisor`
  // branch. Under `period_workdays` the daily rate comes from the period's own workdays and
  // 261 priced nothing.
  annual_divisor: (p) => p.daily_rate_basis === "fixed_divisor",
  // PayrollService:1653 — only the `auto_after_threshold` arm of the match reaches the
  // threshold. `approved_only` pays what was approved, whatever its length.
  overtime_threshold_minutes: (p) => p.overtime_mode === "auto_after_threshold",
  // AttendanceService:1793 gates BOTH of these on the penalty flag —
  // `if ($policy->late_penalty_enabled && $policy->lates_per_deduction > 0)`. With the penalty
  // off, "Lates per deduction: 3" describes a rule that charged nobody anything.
  lates_per_deduction: (p) => p.late_penalty_enabled === true,
  monthly_late_reset: (p) => p.late_penalty_enabled === true,
  // A 0 cap is UNCAPPED, so it changes no money and does not belong in a run's rules snapshot.
  // (Item 7's `auto_overtime_review_minutes` is absent from LABELS entirely and must stay that
  // way — it only raises a flag for HR and never moves a peso, so it is not a rule this run was
  // computed under.)
  auto_overtime_daily_cap_minutes: (p) =>
    p.overtime_mode === "auto_after_threshold" && Number(p.auto_overtime_daily_cap_minutes ?? 0) > 0,
  auto_overtime_period_cap_minutes: (p) =>
    p.overtime_mode === "auto_after_threshold" && Number(p.auto_overtime_period_cap_minutes ?? 0) > 0,
};

/** Peso-valued payroll settings — a bare "90000.00" reads as a count, not money. */
const MONEY = new Set(["other_benefits_annual_cap", "minimum_daily_wage"]);

const CONTRIBUTION_NAMES: Record<string, string> = {
  sss: "SSS",
  philhealth: "PhilHealth",
  pagibig: "Pag-IBIG",
};

function label(key: string): string {
  return LABELS[key] ?? key.replace(/_/g, " ").replace(/^./, (c) => c.toUpperCase());
}

/** The late ladder as HR wrote it: "over 30 min → 1h · over 60 min → 4h". */
function tiers(value: unknown): string {
  if (!Array.isArray(value) || value.length === 0) return "None set";

  return value
    .map((t) => `over ${t?.over ?? "?"} min → ${t?.hours ?? 0}h`)
    .join(" · ");
}

/** The same rung when it REPEATS: "every 30 min late → 1h". A different sentence, not a suffix. */
function recurringTier(value: unknown): string {
  const t = Array.isArray(value) ? value[0] : null;
  if (!t) return "None set";
  return `every ${t?.over ?? "?"} min late → ${t?.hours ?? 0}h`;
}

function display(key: string, value: unknown, policy?: Record<string, unknown>): string {
  if (key === "late_tiers") {
    return policy?.late_tier_recurring === true ? recurringTier(value) : tiers(value);
  }
  if (key === "late_tier_recurring") return value === true ? "Yes — charged per block" : "No — charged once";
  if (value === null || value === undefined || value === "") return "—";
  if (typeof value === "boolean") return value ? "On" : "Off";
  if (PERCENT.test(key)) return `${value}%`;
  if (MONEY.has(key)) return formatPeso(value as string | number);
  // A FRACTION of a day, not a peso figure — "1" alone reads as one peso or one minute.
  if (key === "daily_deduction_cap") return `${Number(value)} day's pay`;
  if (typeof value === "object") return JSON.stringify(value);
  return String(value).replace(/_/g, " ");
}

/** "0.05000" → "5%" (and "0.02500" → "2.5%") — trailing zeroes just add noise here. */
function rate(value: string | number | null | undefined): string {
  const pct = Number(value ?? 0) * 100;
  return `${Number(pct.toFixed(3))}%`;
}

/**
 * What the version in force charged. The BASIS matters: a bracket contribution (SSS) charges
 * its rate on the band's Monthly Salary Credit, not on the salary — saying "5% employee" flat
 * would misdescribe every payslip it priced.
 */
function contributionValue(v: PolicySnapshotContributionVersion): string {
  const of = v.basis === "bracket" ? "of bracket MSC" : "of salary";

  return `${rate(v.employee_rate)} employee · ${rate(v.employer_rate)} employer ${of}`;
}

/** The limits that move money, so a payslip that looks "wrong" can be explained. */
function contributionHint(v: PolicySnapshotContributionVersion): string {
  const parts: string[] = [];

  if (v.effective_from) parts.push(`in force from ${v.effective_from}`);
  if (v.basis !== "bracket") {
    if (v.salary_floor != null) parts.push(`floor ${formatPeso(v.salary_floor)}`);
    if (v.salary_cap != null) parts.push(`cap ${formatPeso(v.salary_cap)}`);
  }
  if (Number(v.employee_amount_cap ?? 0) > 0) {
    parts.push(`max ${formatPeso(v.employee_amount_cap!)} employee`);
  }
  if (Number(v.fixed_employer_add_on ?? 0) > 0) {
    parts.push(`+${formatPeso(v.fixed_employer_add_on!)} employer add-on`);
  }

  return parts.join(" · ");
}

function Row({ name, value, hint }: { name: string; value: string; hint?: string }) {
  return (
    <div className="flex items-baseline justify-between gap-4 border-b border-border/60 py-1.5 last:border-0">
      <span className="text-caption text-muted-foreground">
        {name}
        {hint ? <span className="block text-[0.6875rem] text-muted-foreground/70">{hint}</span> : null}
      </span>
      <span className="text-caption font-medium text-foreground text-right">{value}</span>
    </div>
  );
}

/** Contribution drift arrives keyed by type, not by policy field — name it as HR reads it. */
function driftLabel(entry: RulesDriftEntry): string {
  if (entry.group === "contributions") {
    return `${CONTRIBUTION_NAMES[entry.key] ?? entry.key.toUpperCase()} rate`;
  }

  return label(entry.key);
}

function driftValue(entry: RulesDriftEntry): string {
  if (entry.group === "contributions") {
    const v = entry.now as PolicySnapshotContributionVersion | null;

    return v ? contributionValue(v) : "—";
  }

  return display(entry.key, entry.now);
}

export function RunRulesSnapshot({
  snapshot,
  drift = [],
}: {
  snapshot?: PolicySnapshot | null;
  drift?: RulesDriftEntry[];
}) {
  // Runs from before have no snapshot (or the flat v1 shape) — say so plainly
  // rather than render a misleading empty panel.
  if (!snapshot) return null;

  const policy = snapshot.attendance_policy ?? {};
  const setting = snapshot.payroll_setting ?? {};
  const isLegacy = (snapshot.version ?? 1) < 2;

  // Enrolment says WHETHER a contribution was charged; the dated version says AT WHAT RATE.
  // Reading the rate off `contributions[].config` — as this panel used to — showed the dead
  //  Column, so the run's SSS read 4.5% while the version that actually priced it
  // (and the Benefits screen) said 5%.
  const versions = new Map(
    (snapshot.contribution_versions ?? []).map((v) => [v.type, v]),
  );
  const contributions = snapshot.contributions ?? [];

  // The fields worth surfacing, in the order HR reads them. Everything else stays in the
  // stored JSON — this panel explains a payslip, it isn't a settings dump. Gated fields drop
  // out unless the rule that makes them bite was actually on for this run (see GATES).
  const shown = Object.keys(LABELS).filter(
    (k) => k in policy && (GATES[k]?.(policy) ?? true),
  );

  return (
    <details className="mt-6 rounded-card border border-border bg-card">
      <summary className="flex cursor-pointer list-none items-center justify-between gap-2 p-4">
        <span>
          <span className="text-sm font-semibold text-foreground">Rules used for this run</span>
          <span className="ml-2 text-caption text-muted-foreground">
            what priced these payslips
          </span>
        </span>
        <ChevronDown className="size-4 shrink-0 text-muted-foreground" />
      </summary>

      <div className="border-t border-border p-4">
        {drift.length > 0 ? (
          <div className="mb-4 rounded-lg border border-warning/40 bg-warning-subtle p-3">
            <p className="text-caption font-semibold text-foreground">
              Company rules have changed since this run
            </p>
            <p className="mt-0.5 text-caption text-muted-foreground">
              The figures below are what actually priced these payslips. Re-running this cycle
              would now use the rules on the right.
            </p>
            <div className="mt-2">
              {drift.map((d) => (
                <Row
                  key={`${d.group}.${d.key}`}
                  name={driftLabel(d)}
                  hint={`was ${d.group === "contributions" ? "a different rate" : display(d.key, d.was)}`}
                  value={driftValue(d)}
                />
              ))}
            </div>
          </div>
        ) : null}

        {isLegacy ? (
          <p className="text-caption text-muted-foreground">
            This run predates the full snapshot — only some deduction settings were recorded.
          </p>
        ) : (
          <div className="grid gap-6 sm:grid-cols-2">
            <div>
              <h4 className="mb-2 text-caption font-semibold text-foreground">Attendance &amp; rates</h4>
              {shown.map((k) => (
                // policy is passed so the tier line can be phrased as a repeating rule; the
                // drift rows above deliberately don't get it — their `was` value predates the
                // current flag, so describing it with today's setting would misreport history.
                <Row key={k} name={label(k)} value={display(k, policy[k], policy)} />
              ))}
            </div>

            <div>
              <h4 className="mb-2 text-caption font-semibold text-foreground">Pay schedule</h4>
              {Object.entries(setting).map(([k, v]) => (
                <Row key={k} name={label(k)} value={display(k, v)} />
              ))}

              <h4 className="mt-4 mb-2 text-caption font-semibold text-foreground">
                Government contributions
              </h4>
              {contributions.length === 0 ? (
                <p className="text-caption text-muted-foreground">None configured.</p>
              ) : (
                contributions.map((c) => {
                  const version = versions.get(c.type);
                  const name = CONTRIBUTION_NAMES[c.type] ?? c.type.toUpperCase();

                  if (!c.is_active) return <Row key={c.type} name={name} value="Not operated" />;

                  // A run priced before the rate versions existed. Better to say the rate was
                  // not recorded than to print the dead config column as if it were one.
                  if (!version) {
                    return <Row key={c.type} name={name} value="Charged — rate not recorded" />;
                  }

                  return (
                    <Row
                      key={c.type}
                      name={name}
                      hint={contributionHint(version)}
                      value={contributionValue(version)}
                    />
                  );
                })
              )}
            </div>
          </div>
        )}

        <p className="mt-4 text-caption text-muted-foreground">
          A record of what was in force, not a replay — re-running a cycle always uses the
          current rules. Lock a run to stop it being re-priced.
        </p>
      </div>
    </details>
  );
}
