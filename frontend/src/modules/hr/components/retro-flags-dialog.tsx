"use client";

import { useState } from "react";
import { AxiosError } from "axios";
import { ArrowDownRight, ArrowUpRight, Check, CircleAlert } from "lucide-react";

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
import { cn } from "@/lib/utils";
import { notify as toast } from "@/core/lib/notify";
import { formatDate } from "@/core/lib/format";
import { useRetroFlags, useResolveRetroFlag, useDeductionArrears } from "@/modules/hr/hooks/use-payroll";
import type { RetroFlag } from "@/modules/hr/types/payroll";
import { formatPeso } from "@/core/lib/format";

/** Retroactive pay differences waiting to be settled. */
export function RetroFlagsDialog({
  open,
  onOpenChange,
  onSettleWithAdjustment,
}: {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  /** Opens the adjustment-run dialog prefilled for this employee — the usual way to clear `owed`. */
  onSettleWithAdjustment?: (flag: RetroFlag) => void;
}) {
  const { data: flags = [], isLoading } = useRetroFlags();
  const { data: arrears = [] } = useDeductionArrears();
  const resolveMut = useResolveRetroFlag();
  const [resolving, setResolving] = useState<RetroFlag | null>(null);
  const [note, setNote] = useState("");

  const owed = flags.filter((f) => f.direction === "owed");
  const overpaid = flags.filter((f) => f.direction === "overpaid");

  async function submitResolution() {
    if (!resolving) return;
    try {
      await resolveMut.mutateAsync({ id: resolving.id, note });
      toast.success("Difference marked settled");
      setResolving(null);
      setNote("");
    } catch (e) {
      const err = e as AxiosError<{ message?: string; errors?: Record<string, string[]> }>;
      toast.error(err.response?.data?.errors?.note?.[0] ?? err.response?.data?.message ?? "Could not settle it");
    }
  }

  function Row({ flag }: { flag: RetroFlag }) {
    const isOwed = flag.direction === "owed";

    return (
      <li className="flex flex-col gap-2 rounded-lg border border-border p-3 sm:flex-row sm:items-start sm:justify-between">
        <div className="min-w-0">
          <p className="text-body font-medium">{flag.employee.name ?? "Unknown employee"}</p>
          <p className="text-caption text-muted-foreground">{flag.reason}</p>
          {flag.period && (
            <p className="mt-0.5 text-caption text-muted-foreground">Period: {flag.period}</p>
          )}
        </div>

        <div className="flex shrink-0 items-center gap-2">
          {isOwed && onSettleWithAdjustment && (
            <Button size="sm" variant="outline" onClick={() => onSettleWithAdjustment(flag)}>
              Pay by adjustment
            </Button>
          )}
          <Button size="sm" variant="ghost" onClick={() => { setResolving(flag); setNote(""); }}>
            <Check />
            Mark settled
          </Button>
        </div>
      </li>
    );
  }

  return (
    <>
      <Dialog open={open} onOpenChange={onOpenChange}>
        <DialogContent className="max-w-2xl">
          <DialogHeader>
            <DialogTitle>Outstanding payroll items</DialogTitle>
            <DialogDescription>
              Everything payroll could not settle on its own. Pay corrections come from decisions made
              after a period was paid; uncollected deductions come from payslips too thin to cover
              what was owed.
            </DialogDescription>
          </DialogHeader>

          {isLoading ? (
            <p className="py-6 text-center text-caption text-muted-foreground">Loading…</p>
          ) : flags.length === 0 && arrears.length === 0 ? (
            <p className="py-6 text-center text-caption text-muted-foreground">
              Nothing outstanding. Items appear here when a decision lands on a period already paid,
              or when a payslip could not cover everything owed.
            </p>
          ) : (
            <div className="max-h-[60vh] space-y-5 overflow-y-auto">
              {owed.length > 0 && (
                <section>
                  <h3 className="mb-2 flex items-center gap-1.5 text-caption font-semibold uppercase tracking-wide text-muted-foreground">
                    <ArrowUpRight className="size-3.5" />
                    Owed to the employee ({owed.length})
                  </h3>
                  <ul className="space-y-2">
                    {owed.map((f) => <Row key={f.id} flag={f} />)}
                  </ul>
                </section>
              )}

              {arrears.length > 0 && (
                <section>
                  <h3 className="mb-2 flex items-center gap-1.5 text-caption font-semibold uppercase tracking-wide text-muted-foreground">
                    <CircleAlert className="size-3.5" />
                    Uncollected deductions ({arrears.length})
                  </h3>
                  {/*
                   * Recovery is automatic, so say so — otherwise this reads as a to-do list and HR
                   * chases amounts the next run will take by itself.
                   */}
                  <p className="mb-2 rounded-md bg-muted px-3 py-2 text-caption text-muted-foreground">
                    The next payroll run with enough room recovers these automatically, statutory
                    contributions first. Only the ones marked <strong>separated</strong> need chasing
                    by hand — there is no future payslip to take them from.
                  </p>
                  <ul className="space-y-2">
                    {arrears.map((a) => (
                      <li
                        key={a.employee.id}
                        className="flex flex-col gap-1 rounded-lg border border-border p-3 sm:flex-row sm:items-start sm:justify-between"
                      >
                        <div className="min-w-0">
                          <p className="text-body font-medium">
                            {a.employee.name ?? "Unknown employee"}
                            {a.employee.separated && (
                              <span className="ml-2 rounded-full bg-warning px-1.5 text-caption font-semibold text-white">
                                separated
                              </span>
                            )}
                          </p>
                          <p className="text-caption text-muted-foreground">
                            {a.obligations.map((o) => `${o.label} ${formatPeso(o.outstanding)}`).join(" · ")}
                          </p>
                        </div>
                        <span className="shrink-0 text-body font-semibold">
                          {formatPeso(a.total_outstanding)}
                        </span>
                      </li>
                    ))}
                  </ul>
                </section>
              )}

              {overpaid.length > 0 && (
                <section>
                  <h3 className="mb-2 flex items-center gap-1.5 text-caption font-semibold uppercase tracking-wide text-muted-foreground">
                    <ArrowDownRight className="size-3.5" />
                    Overpaid ({overpaid.length})
                  </h3>
                  {/* Stated plainly: this is deliberately not automated. */}
                  <p className="mb-2 rounded-md bg-muted px-3 py-2 text-caption text-muted-foreground">
                    Recovering money already paid is a wage deduction and needs the employee&rsquo;s
                    written consent. Handle these with the employee directly, then mark them settled.
                  </p>
                  <ul className="space-y-2">
                    {overpaid.map((f) => <Row key={f.id} flag={f} />)}
                  </ul>
                </section>
              )}
            </div>
          )}

          <DialogFooter>
            <DialogClose asChild>
              <Button variant="outline">Close</Button>
            </DialogClose>
          </DialogFooter>
        </DialogContent>
      </Dialog>

      {/* Settling requires saying how — "resolved" with no explanation is the dead end this replaced. */}
      <Dialog open={resolving !== null} onOpenChange={(o) => !o && setResolving(null)}>
        <DialogContent className="max-w-md">
          <DialogHeader>
            <DialogTitle>Mark this settled</DialogTitle>
            <DialogDescription>
              Record how it was settled — an adjustment run, a manual payment, or an agreement with
              the employee. This is the audit trail for the correction.
            </DialogDescription>
          </DialogHeader>

          <div className="space-y-1.5">
            <label htmlFor="retro-note" className="text-caption font-medium">
              How was it settled?
            </label>
            <Input
              id="retro-note"
              value={note}
              onChange={(e) => setNote(e.target.value)}
              placeholder="e.g. Paid on the 1 Apr adjustment run"
              autoFocus
            />
            <p className={cn("text-caption", note.trim().length < 5 ? "text-muted-foreground" : "invisible")}>
              At least 5 characters.
            </p>
          </div>

          <DialogFooter>
            <Button variant="outline" onClick={() => setResolving(null)}>
              Cancel
            </Button>
            <Button
              disabled={note.trim().length < 5 || resolveMut.isPending}
              onClick={submitResolution}
            >
              {resolveMut.isPending ? "Saving…" : "Mark settled"}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </>
  );
}
