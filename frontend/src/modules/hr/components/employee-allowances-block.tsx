"use client";

import { useState } from "react";
import { AxiosError } from "axios";

import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { formatPeso, formatDate } from "@/core/lib/format";
import { notify as toast } from "@/core/lib/notify";
import {
  useEmployeeAllowances,
  useSetEmployeeAllowance,
} from "@/modules/hr/hooks/use-benefit-programs";
import { frequencyLabel, type EmployeeAllowance } from "@/modules/hr/types/benefits";

/** What this employee is paid per allowance, and the one place to give them a different amount — */
export function EmployeeAllowancesBlock({ employeeId }: { employeeId: number }) {
  const { data: rows, isLoading, isError } = useEmployeeAllowances(employeeId);

  return (
    <div className="border-t border-border pt-6">
      <p className="mb-1 text-label font-semibold uppercase tracking-wide text-brand">
        Allowances
      </p>
      <p className="mb-4 text-caption text-muted-foreground">
        Set in <strong>Company &rarr; Benefits</strong> and paid to everyone the allowance covers.
        Give this employee a different amount only where there is a real arrangement — anyone left
        following the programme moves automatically when the amount there changes.
      </p>

      {isLoading ? (
        <p className="text-sm text-muted-foreground">Loading…</p>
      ) : isError ? (
        <p className="text-sm text-destructive">Could not load allowances.</p>
      ) : (rows ?? []).length === 0 ? (
        <p className="rounded-lg border border-dashed border-border px-3 py-4 text-center text-sm text-muted-foreground">
          No allowance covers this employee.
        </p>
      ) : (
        <div className="space-y-2">
          {(rows ?? []).map((row) => (
            <AllowanceRow key={row.program_id} employeeId={employeeId} row={row} />
          ))}
        </div>
      )}
    </div>
  );
}

function AllowanceRow({ employeeId, row }: { employeeId: number; row: EmployeeAllowance }) {
  const set = useSetEmployeeAllowance(employeeId);
  const [editing, setEditing] = useState(false);
  const [value, setValue] = useState(String(row.amount ?? row.program_amount));

  const overridden = row.amount !== null;
  const paid = overridden ? row.amount! : row.program_amount;

  async function save(amount: number | null) {
    try {
      await set.mutateAsync({ programId: row.program_id, amount });
      setEditing(false);
      toast.success(
        amount === null
          ? `${row.name} follows the programme again`
          : `${row.name} set to ${formatPeso(amount)} for this employee`,
      );
    } catch (err) {
      const errors = err instanceof AxiosError ? err.response?.data?.errors : null;
      toast.error(errors ? String(Object.values(errors)[0]) : "Could not save the amount.");
    }
  }

  return (
    <div className="rounded-lg border border-border bg-card px-3 py-2.5">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <div className="flex items-center gap-2.5">
          <span
            className="size-2.5 shrink-0 rounded-full"
            style={{ backgroundColor: row.color ?? "#94a3b8" }}
          />
          <div>
            <p className="text-sm font-semibold text-foreground">{row.name}</p>
            <p className="text-caption text-muted-foreground">
              {frequencyLabel(row.frequency)}
              {row.tax_treatment === "taxable" ? " · Taxable" : " · Tax-free"}
              {overridden && ` · programme pays ${formatPeso(row.program_amount)}`}
            </p>
          </div>
        </div>

        {editing ? (
          <div className="flex items-center gap-2">
            <Input
              type="number"
              min="0"
              step="0.01"
              className="h-9 w-32"
              value={value}
              onChange={(e) => setValue(e.target.value)}
            />
            <Button size="sm" disabled={set.isPending} onClick={() => save(Number(value))}>
              Save
            </Button>
            <Button
              size="sm"
              variant="outline"
              disabled={set.isPending}
              onClick={() => {
                setValue(String(row.amount ?? row.program_amount));
                setEditing(false);
              }}
            >
              Cancel
            </Button>
          </div>
        ) : (
          <div className="flex items-center gap-3">
            <span
              className={
                overridden
                  ? "text-sm font-bold text-warning"
                  : "text-sm font-bold text-foreground"
              }
            >
              {formatPeso(paid)}
            </span>
            <Button size="sm" variant="outline" onClick={() => setEditing(true)}>
              {overridden ? "Change" : "Set a different amount"}
            </Button>
            {overridden && (
              <Button
                size="sm"
                variant="ghost"
                disabled={set.isPending}
                onClick={() => save(null)}
              >
                Follow programme
              </Button>
            )}
          </div>
        )}
      </div>

      {/*
       * Provenance is the whole difference between a deliberate arrangement and a leftover —
       * B6 had 56 rows nobody could tell apart, and that is why they were all cleared.
       */}
      {overridden && row.set_by && (
        <p className="mt-2 text-caption text-muted-foreground">
          Own amount set by {row.set_by}
          {row.set_at && ` on ${formatDate(row.set_at)}`} — edits to the allowance leave this
          employee unchanged.
        </p>
      )}
    </div>
  );
}
