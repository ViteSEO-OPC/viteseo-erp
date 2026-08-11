"use client";

import { useState } from "react";
import { AxiosError } from "axios";
import { notify as toast } from "@/core/lib/notify";

import { Button } from "@/components/ui/button";
import {
  Dialog,
  DialogContent,
  DialogHeader,
  DialogTitle,
  DialogDescription,
  DialogFooter,
  DialogClose,
} from "@/components/ui/dialog";
import { Input } from "@/components/ui/input";
import { formatPeso, formatDate } from "@/core/lib/format";
import { DatePicker } from "@/modules/hr/ui/date-picker";
import { useMinimumWageCheck, useApplyMinimumWage } from "@/modules/hr/hooks/use-payroll";

/**
 * Everyone this run would pay below the regional floor, and the raise that clears it (FIN-030b).
 *
 * WHY THE SYSTEM PROPOSES AND A PERSON COMMITS. A wage order raises what the company MUST pay; it
 * does not raise anybody's salary by itself. Salary is a contractual term, so the change needs an
 * approver, a date it takes effect from and a record — which is what routing it through the normal
 * salary-change path buys: the same history row, the same audit entry, the same repricing guard as
 * any other raise. A job that rewrote pay on its own would leave nobody able to answer who decided
 * it, and would bump the part-timers and exempt arrangements the check cannot see.
 *
 * So this screen does the arithmetic nobody can do in their head — the monthly figure whose derived
 * daily rate clears the floor — and stops there. Selection is explicit; nothing is applied to a
 * list that was not read.
 */
export function MinimumWageDialog({
  open,
  onOpenChange,
  cycle,
}: {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  cycle: { frequency: string; cycle_start: string; cycle_end: string } | null;
}) {
  const { data, isLoading } = useMinimumWageCheck(open ? cycle : null);
  const applyMut = useApplyMinimumWage();

  /**
   * Held as the EXCLUSIONS, not the selections. Everyone flagged is meant to be fixed, so "all
   * ticked" is the resting state — and storing it that way means the list arriving, or changing
   * after an apply, needs no effect to re-seed a selection that would otherwise go stale.
   */
  const [deselected, setDeselected] = useState<number[]>([]);
  const [effectiveDate, setEffectiveDate] = useState("");
  const [reason, setReason] = useState("");

  const rows = data?.rows ?? [];
  // Exempt people are listed so the exclusion stays visible and reviewable, but they are never
  // selectable: raising someone who has a recorded reason to be below the floor undoes a decision
  // somebody already made.
  const fixable = rows.filter((r) => !r.exempt);
  const selected = fixable
    .map((r) => r.employee.id)
    .filter((id) => !deselected.includes(id));

  function toggle(id: number) {
    setDeselected((s) => (s.includes(id) ? s.filter((x) => x !== id) : [...s, id]));
  }

  function close(next: boolean) {
    if (!next) {
      setDeselected([]);
      setReason("");
    }
    onOpenChange(next);
  }

  async function apply(acknowledgeRepricing = false) {
    if (!effectiveDate || selected.length === 0) return;
    try {
      const result = await applyMut.mutateAsync({
        employee_ids: selected,
        effective_date: effectiveDate,
        reason: reason.trim() || undefined,
        acknowledge_repricing: acknowledgeRepricing,
      });

      toast.success(
        `${result.applied.length} salar${result.applied.length === 1 ? "y" : "ies"} raised, effective ${formatDate(effectiveDate)}` +
          (result.back_pay_flagged > 0
            ? ` — ${result.back_pay_flagged} period${result.back_pay_flagged === 1 ? "" : "s"} already paid, recorded as back pay owed`
            : ""),
      );
      if (result.skipped.length > 0) {
        toast.info(`${result.skipped.length} skipped — already at or above the floor.`);
      }
      close(false);
    } catch (err) {
      // FIN-007 — the date reaches into completed runs. That refusal is a QUESTION, so it is asked
      // rather than shown as a dead end: those periods were paid at the old salary, and the
      // difference becomes back pay rather than a silent repricing.
      const backdated =
        err instanceof AxiosError
          ? err.response?.data?.errors?.salary_effective_date?.[0]
          : undefined;

      if (backdated && !acknowledgeRepricing && window.confirm(`${backdated}\n\nRecord it anyway?`)) {
        await apply(true);
        return;
      }

      toast.error(
        err instanceof AxiosError
          ? (err.response?.data?.message ?? "Could not apply the adjustments.")
          : "Could not apply the adjustments.",
      );
    }
  }

  const minimum = data?.meta.minimum ?? null;

  return (
    <Dialog open={open} onOpenChange={close}>
      <DialogContent className="max-h-[90svh] max-w-[calc(100%-3rem)] overflow-y-auto sm:max-w-2xl">
        <DialogHeader>
          <DialogTitle>Minimum wage check</DialogTitle>
          <DialogDescription>
            {minimum === null ? (
              <>
                No regional minimum daily wage is set, so nothing is being checked. Enter the figure
                from your DOLE wage order in Payroll settings — it is deliberately left blank rather
                than guessed.
              </>
            ) : (
              <>
                Against {formatPeso(minimum)} a day, for{" "}
                {data?.meta.cycle.start ? formatDate(data.meta.cycle.start) : ""} –{" "}
                {data?.meta.cycle.end ? formatDate(data.meta.cycle.end) : ""}. The daily rate is
                derived from the salary and the basis in Company &rarr; Rules, so it is only ever as
                right as that setting.
              </>
            )}
          </DialogDescription>
        </DialogHeader>

        {isLoading && <p className="text-sm text-muted-foreground">Checking…</p>}

        {!isLoading && minimum !== null && rows.length === 0 && (
          <p className="rounded-card border border-border bg-muted/40 p-3 text-sm text-muted-foreground">
            All {data?.meta.checked ?? 0} employees in this pay group are at or above the floor.
          </p>
        )}

        {rows.length > 0 && (
          <div className="space-y-2">
            {rows.map((row) => (
              <label
                key={row.employee.id}
                className={
                  "flex items-start gap-3 rounded-card border p-3 " +
                  (row.exempt ? "border-border bg-muted/40" : "border-warning/40 bg-warning/10")
                }
              >
                <input
                  type="checkbox"
                  className="mt-1 h-4 w-4 rounded border-input accent-brand"
                  checked={selected.includes(row.employee.id)}
                  disabled={row.exempt}
                  onChange={() => toggle(row.employee.id)}
                />
                <div className="min-w-0 flex-1">
                  <p className="text-sm font-medium text-foreground">
                    {row.employee.name ?? "—"}
                    <span className="ml-2 text-caption font-normal text-muted-foreground">
                      {row.employee.job_title ?? ""}
                    </span>
                  </p>
                  <p className="text-caption text-muted-foreground">
                    {formatPeso(row.base_salary)} / mo works out to {formatPeso(row.daily_rate)} a
                    day — {formatPeso(row.shortfall ?? 0)} under.
                  </p>
                  {row.exempt ? (
                    <p className="mt-1 text-caption text-muted-foreground">
                      Excluded from the check
                      {row.exempt_reason ? ` — ${row.exempt_reason}` : ""}.
                    </p>
                  ) : (
                    row.compliant_salary !== null && (
                      <p className="mt-1 text-caption text-foreground">
                        Raise to <strong>{formatPeso(row.compliant_salary)} / mo</strong> to clear
                        it.
                      </p>
                    )
                  )}
                </div>
              </label>
            ))}
          </div>
        )}

        {fixable.length > 0 && (
          <div className="space-y-3 border-t border-border pt-4">
            <div className="space-y-1.5">
              <p className="text-label font-bold uppercase tracking-wide text-muted-foreground">
                Takes effect from
              </p>
              <DatePicker
                value={effectiveDate}
                onChange={setEffectiveDate}
                aria-label="Date the adjustment takes effect"
                className="w-full sm:w-auto"
              />
              <p className="text-caption text-muted-foreground">
                The wage order&apos;s date, not today. Periods already paid before this date become
                back pay owed rather than being silently repriced.
              </p>
            </div>
            <div className="space-y-1.5">
              <p className="text-label font-bold uppercase tracking-wide text-muted-foreground">
                Reason
              </p>
              <Input
                value={reason}
                onChange={(e) => setReason(e.target.value)}
                placeholder="e.g. Wage Order NCR-27"
                aria-label="Reason recorded on the salary change"
              />
            </div>
          </div>
        )}

        <DialogFooter>
          <DialogClose asChild>
            <Button variant="outline">Close</Button>
          </DialogClose>
          {fixable.length > 0 && (
            <Button
              disabled={applyMut.isPending || selected.length === 0 || !effectiveDate}
              onClick={() => apply()}
            >
              {applyMut.isPending
                ? "Applying…"
                : `Raise ${selected.length} salar${selected.length === 1 ? "y" : "ies"}`}
            </Button>
          )}
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}
