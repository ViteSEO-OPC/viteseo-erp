"use client";

import { ChevronDown } from "lucide-react";

import type { PolicySnapshot } from "@/modules/hr/types/payroll";

/**
 * "Rules used for this run" (Slice 8) — the settings that were in force when a payroll run
 * executed, so an old payslip can always be explained.
 *
 * It is a RECORD, not a replay: re-running always uses the current rules. Locking a run
 * (Slice 5) is what actually stops an old cycle being re-priced.
 */

/** Field name → the words HR uses. Anything unlisted falls back to a de-slugged label. */
const LABELS: Record<string, string> = {
  late_deduction_mode: "Late deduction",
  late_penalty_enabled: "Full-day penalty per N lates",
  lates_per_deduction: "Lates per deduction",
  late_minutes_from: "Late counted from",
  undertime_deduction_mode: "Undertime deduction",
  overtime_mode: "Overtime",
  overtime_threshold_minutes: "Minimum overtime (min)",
  premium_rest_day_pct: "Rest day worked",
  premium_special_day_pct: "Special day worked",
  premium_regular_holiday_pct: "Regular holiday worked",
  overtime_ordinary_pct: "Overtime — ordinary day",
  overtime_rest_special_pct: "Overtime — rest / special day",
  overtime_regular_holiday_pct: "Overtime — regular holiday",
  night_diff_pct: "Night differential",
  daily_rate_basis: "Daily rate basis",
  annual_divisor: "Annual divisor",
  hours_per_day_divisor: "Hours per day",
  deduction_rounding: "Rounding",
  max_break_minutes_per_day: "Break cap (min)",
  frequency: "Pay frequency",
  deduction_timing: "Deductions land on",
  tax_timing: "Tax lands on",
};

/** Percent-valued fields, shown as "125%" rather than a bare number. */
const PERCENT = /_pct$/;

function label(key: string): string {
  return LABELS[key] ?? key.replace(/_/g, " ").replace(/^./, (c) => c.toUpperCase());
}

function display(key: string, value: unknown): string {
  if (value === null || value === undefined || value === "") return "—";
  if (typeof value === "boolean") return value ? "On" : "Off";
  if (PERCENT.test(key)) return `${value}%`;
  if (typeof value === "object") return JSON.stringify(value);
  return String(value).replace(/_/g, " ");
}

function Row({ name, value }: { name: string; value: string }) {
  return (
    <div className="flex items-baseline justify-between gap-4 border-b border-border/60 py-1.5 last:border-0">
      <span className="text-caption text-muted-foreground">{name}</span>
      <span className="text-caption font-medium text-foreground">{value}</span>
    </div>
  );
}

export function RunRulesSnapshot({ snapshot }: { snapshot?: PolicySnapshot | null }) {
  // Runs from before Slice 8 have no snapshot (or the flat v1 shape) — say so plainly
  // rather than render a misleading empty panel.
  if (!snapshot) return null;

  const policy = snapshot.attendance_policy ?? {};
  const setting = snapshot.payroll_setting ?? {};
  const contributions = snapshot.contributions ?? [];
  const isLegacy = (snapshot.version ?? 1) < 2;

  // The fields worth surfacing, in the order HR reads them. Everything else stays in the
  // stored JSON — this panel explains a payslip, it isn't a settings dump.
  const shown = Object.keys(LABELS).filter((k) => k in policy);

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
        {isLegacy ? (
          <p className="text-caption text-muted-foreground">
            This run predates the full snapshot — only some deduction settings were recorded.
          </p>
        ) : (
          <div className="grid gap-6 sm:grid-cols-2">
            <div>
              <h4 className="mb-2 text-caption font-semibold text-foreground">Attendance &amp; rates</h4>
              {shown
                .filter((k) => k in policy)
                .map((k) => (
                  <Row key={k} name={label(k)} value={display(k, policy[k])} />
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
                contributions.map((c) => (
                  <Row
                    key={c.type}
                    name={c.type.toUpperCase()}
                    value={
                      c.is_active
                        ? `${((Number(c.config?.rate ?? 0) * 100).toFixed(2))}% employee`
                        : "Inactive"
                    }
                  />
                ))
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
