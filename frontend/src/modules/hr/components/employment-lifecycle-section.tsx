"use client";

import { useState } from "react";
import { UserMinus, UserCheck, UserPlus, AlertTriangle } from "lucide-react";
import { AxiosError } from "axios";
import { notify as toast } from "@/core/lib/notify";

import { Button } from "@/components/ui/button";
import { Label } from "@/components/ui/label";
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from "@/components/ui/dialog";
import { SimpleSelect } from "@/modules/hr/ui/select";
import { DatePicker } from "@/modules/hr/ui/date-picker";
import { manilaToday } from "@/modules/hr/lib/manila-date";
import {
  useSeparateEmployee,
  useReinstateEmployee,
  useRehireEmployee,
} from "@/modules/hr/hooks/use-employees";
import type { Employee } from "@/modules/hr/types/employee";

type Mode = "separate" | "reinstate" | "rehire" | null;

/** Turn a 422 into the field message the server actually sent, rather than a generic failure. */
function serverMessage(err: unknown, fallback: string): string {
  if (err instanceof AxiosError && err.response?.status === 422) {
    const errors = err.response.data?.errors ?? {};
    const first = Object.values(errors)[0];
    if (Array.isArray(first) && typeof first[0] === "string") return first[0];
    return err.response.data?.message ?? fallback;
  }
  return fallback;
}

/**
 * Employment lifecycle — separate, reinstate, rehire (MF-18).
 *
 * WHY REINSTATE AND REHIRE ARE TWO BUTTONS AND NOT ONE. They are different events with different
 * consequences, and the UI has to make choosing between them a deliberate act:
 *
 *   · Reinstate — the separation should not have happened (withdrawn resignation, reversed
 *     dismissal). Service is continuous: hire date, leave credits and loan authorisations all stand.
 *   · Rehire — a new employment relationship. Tenure restarts, leave is seeded fresh for the year,
 *     and any outstanding loan goes back to needing the employee's consent (Art. 113).
 *
 * A single "bring back" button would have to guess, and guessing wrong either resets the tenure of
 * someone whose dismissal was overturned or gifts a rehire credits they no longer hold. So each
 * dialog states what it will do to leave and loans before HR confirms.
 *
 * Separating is also where `separation_date` gets written — the last working day payroll prorates
 * against and the field final pay refuses to run without.
 */
export function EmploymentLifecycleSection({ employee }: { employee: Employee }) {
  const [mode, setMode] = useState<Mode>(null);
  const [separationType, setSeparationType] = useState<"voluntary" | "involuntary">("voluntary");
  const [lastDay, setLastDay] = useState(manilaToday());
  const [rehireDate, setRehireDate] = useState(manilaToday());
  const [reason, setReason] = useState("");

  const separateMut = useSeparateEmployee();
  const reinstateMut = useReinstateEmployee();
  const rehireMut = useRehireEmployee();

  const pending = separateMut.isPending || reinstateMut.isPending || rehireMut.isPending;

  function close() {
    setMode(null);
    setReason("");
  }

  async function confirm() {
    try {
      if (mode === "separate") {
        await separateMut.mutateAsync({
          id: employee.id,
          input: {
            separation_type: separationType,
            separation_date: lastDay,
            reason: reason.trim() || undefined,
          },
        });
        toast.success(
          `${employee.name} separated`,
          "Their final pay can now be run from the payroll page.",
        );
      } else if (mode === "reinstate") {
        await reinstateMut.mutateAsync({ id: employee.id, reason: reason.trim() || undefined });
        toast.success(`${employee.name} reinstated`, "Service treated as continuous.");
      } else if (mode === "rehire") {
        await rehireMut.mutateAsync({
          id: employee.id,
          input: { hire_date: rehireDate, reason: reason.trim() || undefined },
        });
        toast.success(
          `${employee.name} rehired`,
          "Tenure restarts. Any outstanding loan needs the employee's authorisation again.",
        );
      }
      close();
    } catch (err) {
      toast.error(serverMessage(err, "Something went wrong. Please try again."));
    }
  }

  return (
    <section className="rounded-lg border border-border bg-card p-4 sm:p-5">
      <div className="flex flex-wrap items-start justify-between gap-3">
        <div>
          <h3 className="text-h4 font-bold text-foreground">Employment</h3>
          {employee.is_separated ? (
            <p className="mt-1 text-caption text-muted-foreground">
              Left on{" "}
              <strong className="text-foreground">
                {employee.separation_date ?? employee.separated_at ?? "an unrecorded date"}
              </strong>
              {employee.separation_type ? ` · ${employee.separation_type}` : null}
              {employee.previous_hire_date
                ? ` · first joined ${employee.previous_hire_date}`
                : null}
            </p>
          ) : (
            <p className="mt-1 text-caption text-muted-foreground">
              Employed since <strong className="text-foreground">{employee.hire_date}</strong>
              {employee.previous_hire_date
                ? ` · rehired, first joined ${employee.previous_hire_date}`
                : null}
            </p>
          )}
        </div>

        <div className="flex flex-wrap gap-2">
          {employee.is_separated ? (
            <>
              <Button variant="outline" onClick={() => setMode("reinstate")}>
                <UserCheck />
                Reinstate
              </Button>
              <Button variant="outline" onClick={() => setMode("rehire")}>
                <UserPlus />
                Rehire
              </Button>
            </>
          ) : (
            <Button variant="outline" onClick={() => setMode("separate")}>
              <UserMinus />
              End employment
            </Button>
          )}
        </div>
      </div>

      <Dialog open={mode !== null} onOpenChange={(o) => !o && close()}>
        <DialogContent className="max-h-[90svh] max-w-[calc(100%-3rem)] overflow-y-auto sm:max-w-[460px]">
          <DialogHeader>
            <DialogTitle>
              {mode === "separate"
                ? "End employment"
                : mode === "reinstate"
                  ? "Reinstate employee"
                  : "Rehire employee"}
            </DialogTitle>
            <DialogDescription>
              {mode === "separate"
                ? "Records their last working day and archives the record. Their final pay is run separately."
                : mode === "reinstate"
                  ? "Use this when the separation should not have happened."
                  : "Use this when they are being taken on again as a new employment."}
            </DialogDescription>
          </DialogHeader>

          <div className="space-y-4">
            {mode === "separate" && (
              <>
                <div className="space-y-1.5">
                  <Label htmlFor="sep-type">Reason for leaving</Label>
                  <SimpleSelect
                    value={separationType}
                    onValueChange={(v) => setSeparationType(v as "voluntary" | "involuntary")}
                    options={[
                      { value: "voluntary", label: "Voluntary (resigned)" },
                      { value: "involuntary", label: "Involuntary (dismissed, redundant, closure)" },
                    ]}
                  />
                </div>
                <div className="space-y-1.5">
                  <Label htmlFor="sep-date">Last working day</Label>
                  <DatePicker
                    id="sep-date"
                    value={lastDay}
                    onChange={(v) => setLastDay(v)}
                    className="w-full"
                  />
                  <p className="text-xs text-muted-foreground">
                    Payroll prorates their final cycle against this date, and leave stops accruing
                    after that month. It is not the date you are filling this in.
                  </p>
                </div>
                <div className="rounded-lg border border-warning bg-warning-subtle p-3">
                  <p className="text-xs text-foreground">
                    Whether any separation pay is taxable depends on the cause and is asked on the
                    final-pay form, not here.
                  </p>
                </div>
              </>
            )}

            {mode === "reinstate" && (
              <div className="rounded-lg border border-border p-3">
                <p className="text-xs text-foreground">
                  <strong>Service will be treated as continuous.</strong> Their hire date
                  {employee.hire_date ? ` (${employee.hire_date})` : ""} stays as it is, leave
                  balances are left untouched, and any salary deduction they had already authorised
                  keeps running.
                </p>
              </div>
            )}

            {mode === "rehire" && (
              <>
                <div className="space-y-1.5">
                  <Label htmlFor="rehire-date">New start date</Label>
                  <DatePicker
                    id="rehire-date"
                    value={rehireDate}
                    onChange={(v) => setRehireDate(v)}
                    min={employee.separation_date ?? undefined}
                    className="w-full"
                  />
                  <p className="text-xs text-muted-foreground">
                    Must be after their last day
                    {employee.separation_date ? ` (${employee.separation_date})` : ""}.
                  </p>
                </div>
                <div className="rounded-lg border border-warning bg-warning-subtle p-3 space-y-2">
                  <p className="flex items-start gap-2 text-xs text-foreground">
                    <AlertTriangle className="mt-0.5 size-3.5 shrink-0 text-warning" aria-hidden />
                    <span>
                      <strong>Tenure restarts from the new date.</strong> Their original hire date is
                      kept on the record for reference.
                    </span>
                  </p>
                  <p className="text-xs text-foreground">
                    Leave credits for this year are set from the defaults for their employment status
                    — days already taken or paid out are not handed back.
                  </p>
                  <p className="text-xs text-foreground">
                    <strong>Any loan still owing will stop being collected</strong> until the employee
                    authorises the deduction again. Consent given for their previous employment does
                    not carry over.
                  </p>
                </div>
              </>
            )}

            <div className="space-y-1.5">
              <Label htmlFor="lifecycle-reason">Note (optional)</Label>
              <textarea
                id="lifecycle-reason"
                rows={2}
                value={reason}
                onChange={(e) => setReason(e.target.value)}
                className="w-full rounded-lg border border-input bg-transparent px-2.5 py-1.5 text-sm outline-none focus-visible:border-ring focus-visible:ring-3 focus-visible:ring-ring/50"
                placeholder="Recorded in the audit trail"
              />
            </div>
          </div>

          <DialogFooter>
            <Button type="button" variant="ghost" onClick={close} disabled={pending}>
              Cancel
            </Button>
            {/* Arrow function, not a bare reference — React would pass the click event as arg 1. */}
            <Button type="button" onClick={() => confirm()} disabled={pending}>
              {pending
                ? "Saving…"
                : mode === "separate"
                  ? "End employment"
                  : mode === "reinstate"
                    ? "Reinstate"
                    : "Rehire"}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </section>
  );
}
