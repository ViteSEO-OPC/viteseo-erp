"use client";

import type { FieldValues, Path, PathValue, UseFormReturn } from "react-hook-form";

import { Input } from "@/components/ui/input";
import { formatPeso } from "@/core/lib/format";
import type { Employee } from "@/modules/hr/types/employee";

/** The DERIVED daily rate, and the regional floor when one is configured. */
export function MinimumWageBlock<T extends FieldValues>({
  employee,
  editing,
  form,
}: {
  employee: Employee;
  editing: boolean;
  form: UseFormReturn<T>;
}) {
  const check = employee.minimum_wage_check;

  // Absent on list responses (the check is opt-in server-side) and null when no rate could be
  // derived at all — a schedule problem payroll itself reports far better than this block could.
  if (!check) return null;

  const exemptField = "minimum_wage_exempt" as Path<T>;
  const reasonField = "minimum_wage_exempt_reason" as Path<T>;
  // While editing, the block has to track what is TYPED — ticking the box should make the warning
  // stand down before saving, or the tick reads as having done nothing.
  const exempt = editing ? !!form.watch(exemptField) : check.exempt;
  const flagged = check.below && !exempt;

  const basisNote =
    check.basis === "fixed_divisor"
      ? `Salary × 12 ÷ ${check.divisor} — the salary covers every day of the year, including rest days and holidays.`
      : `This period's pay ÷ its ${check.workdays} scheduled workdays — the salary covers workdays only.`;

  return (
    <div className="border-t border-border pt-6">
      <p className="mb-1 text-label font-semibold uppercase tracking-wide text-brand">
        Daily rate
      </p>
      <p className="mb-4 text-caption text-muted-foreground">
        What this salary works out to per day — the figure a wage order applies to. Set in{" "}
        <strong>Company &rarr; Settings</strong>.
      </p>

      <div className="flex flex-wrap items-baseline gap-x-6 gap-y-1">
        <span className="text-h3 font-semibold text-foreground">
          {formatPeso(check.daily_rate)}
          <span className="ml-1 text-body font-normal text-muted-foreground">a day</span>
        </span>
        {check.minimum !== null && (
          <span className="text-body text-muted-foreground">
            Regional minimum {formatPeso(check.minimum)}
          </span>
        )}
      </div>
      <p className="mt-1 text-caption text-muted-foreground">{basisNote}</p>

      {flagged && (
        <div className="mt-4 rounded-card border border-warning/40 bg-warning/10 p-3">
          <p className="text-sm font-medium text-foreground">
            {formatPeso(check.shortfall ?? 0)} a day below the regional minimum
          </p>
          <p className="mt-0.5 text-caption text-muted-foreground">
            {check.compliant_salary !== null && (
              <>
                A base salary of {formatPeso(check.compliant_salary)} / mo would clear it.{" "}
              </>
            )}
            Saving is still allowed — part-time and exempt arrangements legitimately fall below the
            floor — but a wage order cannot be waived by contract, so check the one that applies.
          </p>
        </div>
      )}

      {!editing && check.below && exempt && (
        <p className="mt-4 rounded-card border border-border bg-muted/40 p-3 text-caption text-muted-foreground">
          Below the floor, but excluded from the check
          {check.exempt_reason ? ` — ${check.exempt_reason}` : ""}.
        </p>
      )}

      {editing && (
        <div className="mt-4 space-y-2">
          <label className="flex items-start gap-2 text-sm text-foreground">
            <input
              type="checkbox"
              className="mt-0.5 h-4 w-4 rounded border-input accent-brand"
              checked={exempt}
              onChange={(e) =>
                form.setValue(exemptField, e.target.checked as PathValue<T, Path<T>>, {
                  shouldDirty: true,
                })
              }
            />
            <span>
              Exclude this employee from the minimum-wage check
              <span className="block text-caption text-muted-foreground">
                For arrangements the check cannot see — part-time, or a granted exemption. It stops
                the warning and the pre-run block for this person only.
              </span>
            </span>
          </label>
          {exempt && (
            <Input
              {...form.register(reasonField)}
              placeholder="Why — e.g. part-time, 3 days a week; BMBE exemption No. 1234"
              aria-label="Reason for the minimum-wage exemption"
            />
          )}
        </div>
      )}
    </div>
  );
}
