"use client";

import { formatPeso } from "@/core/lib/format";
import { useMyBenefits } from "@/modules/hr/hooks/use-benefit-programs";

/** The caller's own allowances, read-only — the employee's half of `EmployeeAllowancesBlock`. */
export function MyAllowancesBlock() {
  const { data, isLoading } = useMyBenefits();
  const allowances = (data ?? []).filter((b) => b.kind === "allowance");

  return (
    <div className="border-t border-border pt-6">
      <p className="mb-1 text-label font-semibold uppercase tracking-wide text-brand">Allowances</p>
      <p className="mb-4 text-caption text-muted-foreground">
        What you are paid on top of your salary each month. These also appear on your payslip.
      </p>

      {isLoading ? (
        <p className="text-sm text-muted-foreground">Loading…</p>
      ) : allowances.length === 0 ? (
        <p className="rounded-lg border border-dashed border-border px-3 py-4 text-center text-sm text-muted-foreground">
          No allowances apply to you.
        </p>
      ) : (
        <div className="space-y-2">
          {allowances.map((a) => (
            <div
              key={a.id}
              className="flex flex-wrap items-center justify-between gap-3 rounded-lg border border-border bg-card px-3 py-2.5"
            >
              <div className="flex items-center gap-2.5">
                <span
                  className="size-2.5 shrink-0 rounded-full"
                  style={{ backgroundColor: a.color ?? "#94a3b8" }}
                />
                <div>
                  <p className="text-sm font-semibold text-foreground">{a.name}</p>
                  <p className="text-caption text-muted-foreground">
                    {a.tax_treatment === "taxable" ? "Taxable" : "Tax-free"}
                  </p>
                </div>
              </div>
              <span className="text-sm font-bold text-foreground">
                {formatPeso(a.monthly_amount ?? 0)}
                <span className="text-caption font-normal text-muted-foreground"> / mo</span>
              </span>
            </div>
          ))}
        </div>
      )}
    </div>
  );
}
