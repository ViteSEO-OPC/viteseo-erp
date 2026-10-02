"use client";

import { useState } from "react";
import { UserMinus, UserCheck, UserPlus, AlertTriangle, GraduationCap } from "lucide-react";
import { AxiosError } from "axios";
import { notify as toast } from "@/core/lib/notify";

import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
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
import { RepricingConfirmDialog } from "@/modules/hr/components/repricing-confirm-dialog";
import {
  useSeparateEmployee,
  useReinstateEmployee,
  useRehireEmployee,
  useConvertIntern,
} from "@/modules/hr/hooks/use-employees";
import {
  EMPLOYMENT_STATUSES,
  statusLabel,
  type Employee,
} from "@/modules/hr/types/employee";

type Mode = "separate" | "reinstate" | "rehire" | "convert" | null;

/** What an intern can become. Anything but another internship. */
const CONVERSION_STATUSES = EMPLOYMENT_STATUSES.filter((s) => s !== "ojt");

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
 * The server's "this date reaches into payroll that already ran — confirm?" on a conversion.
 * It arrives as a 422 on `effective_date`. The only other way that field can fail — a date
 * before the hire date — is caught before the request is sent, so a 422 here is the question.
 */
function repricingQuestion(err: unknown): string | null {
  if (err instanceof AxiosError && err.response?.status === 422) {
    const message = err.response.data?.errors?.effective_date?.[0];
    return typeof message === "string" ? message : null;
  }
  return null;
}

/** Employment lifecycle — separate, reinstate, rehire. */
export function EmploymentLifecycleSection({ employee }: { employee: Employee }) {
  const [mode, setMode] = useState<Mode>(null);
  const [separationType, setSeparationType] = useState<"voluntary" | "involuntary">("voluntary");
  const [lastDay, setLastDay] = useState(manilaToday());
  const [rehireDate, setRehireDate] = useState(manilaToday());
  const [reason, setReason] = useState("");
  // Conversion — the end of an internship.
  const [convertStatus, setConvertStatus] = useState("regular");
  const [convertDate, setConvertDate] = useState(manilaToday());
  const [convertSalary, setConvertSalary] = useState("");
  const [convertTitle, setConvertTitle] = useState("");
  const [salaryError, setSalaryError] = useState<string | null>(null);
  const [dateError, setDateError] = useState<string | null>(null);
  /** Set when the server asked the repricing question — the text names the affected runs. */
  const [confirmRepricing, setConfirmRepricing] = useState<string | null>(null);

  const separateMut = useSeparateEmployee();
  const reinstateMut = useReinstateEmployee();
  const rehireMut = useRehireEmployee();
  const convertMut = useConvertIntern();

  const isIntern = employee.employment_status === "ojt";
  const pending =
    separateMut.isPending ||
    reinstateMut.isPending ||
    rehireMut.isPending ||
    convertMut.isPending;

  function close() {
    setMode(null);
    setReason("");
    setSalaryError(null);
    setDateError(null);
    setConfirmRepricing(null);
  }

  function openConvert() {
    setConvertStatus("regular");
    setConvertDate(manilaToday());
    setConvertSalary("");
    setConvertTitle(employee.job_title ?? "");
    setSalaryError(null);
    setDateError(null);
    setMode("convert");
  }

  async function confirm(acknowledgeRepricing = false) {
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
      } else if (mode === "convert") {
        const salary = Number(convertSalary);
        const salaryInvalid = convertSalary === "" || !Number.isFinite(salary) || salary < 0;
        // ISO dates compare correctly as strings.
        const beforeHire = !!employee.hire_date && convertDate < employee.hire_date;
        setSalaryError(salaryInvalid ? "Enter their starting salary" : null);
        setDateError(
          beforeHire ? `Cannot take effect before their hire date (${employee.hire_date})` : null,
        );
        if (salaryInvalid || beforeHire) return;

        await convertMut.mutateAsync({
          id: employee.id,
          input: {
            employment_status: convertStatus,
            effective_date: convertDate,
            base_salary: salary,
            job_title: convertTitle.trim() || undefined,
            reason: reason.trim() || undefined,
            ...(acknowledgeRepricing ? { acknowledge_repricing: true } : {}),
          },
        });
        toast.success(
          `${employee.name} is now on ${statusLabel(convertStatus)}`,
          "Their internship is closed on the timeline and leave credits are set for the new type.",
        );
      }
      close();
    } catch (err) {
      // A back-dated conversion into finished payroll is a question, not a failure.
      const question = mode === "convert" && !acknowledgeRepricing ? repricingQuestion(err) : null;
      if (question) {
        setConfirmRepricing(question);
        return;
      }
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
              {isIntern ? "Interning since " : "Employed since "}
              <strong className="text-foreground">{employee.hire_date}</strong>
              {isIntern && employee.ojt_required_hours != null
                ? ` · ${employee.ojt_required_hours} hours required`
                : null}
              {employee.previous_hire_date
                ? ` · rehired, first joined ${employee.previous_hire_date}`
                : null}
            </p>
          )}
        </div>

        <div className="flex flex-wrap gap-2">
          {employee.is_separated ? (
            <>
              <Button type="button" variant="outline" onClick={() => setMode("reinstate")}>
                <UserCheck />
                Reinstate
              </Button>
              <Button type="button" variant="outline" onClick={() => setMode("rehire")}>
                <UserPlus />
                Rehire
              </Button>
            </>
          ) : (
            /*
             * type="button" matters: this section renders inside the Job tab's <form>, and a
             * bare button would default to submit — opening the dialog AND silently saving.
             */
            <>
              {/* An intern's exit from the internship that isn't leaving: they get hired. */}
              {isIntern && (
                <Button type="button" onClick={openConvert}>
                  <GraduationCap />
                  Convert to employee
                </Button>
              )}
              <Button type="button" variant="outline" onClick={() => setMode("separate")}>
                <UserMinus />
                End employment
              </Button>
            </>
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
                  : mode === "rehire"
                    ? "Rehire employee"
                    : "Convert to employee"}
            </DialogTitle>
            <DialogDescription>
              {mode === "separate"
                ? "Records their last working day and archives the record. Their final pay is run separately."
                : mode === "reinstate"
                  ? "Use this when the separation should not have happened."
                  : mode === "rehire"
                    ? "Use this when they are being taken on again as a new employment."
                    : "The end of the internship — they stay on as staff. Tenure is continuous."}
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
                    Leave credits for this year are set from the defaults for their employment type
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

            {mode === "convert" && (
              <>
                <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                  <div className="space-y-1.5">
                    <Label htmlFor="convert-status">New employment type</Label>
                    <SimpleSelect
                      id="convert-status"
                      value={convertStatus}
                      onValueChange={setConvertStatus}
                      options={CONVERSION_STATUSES.map((s) => ({
                        value: s,
                        label: statusLabel(s),
                      }))}
                    />
                  </div>
                  <div className="space-y-1.5">
                    <Label htmlFor="convert-date">Effective date</Label>
                    <DatePicker
                      id="convert-date"
                      value={convertDate}
                      onChange={(v) => {
                        setConvertDate(v);
                        setDateError(null);
                      }}
                      min={employee.hire_date ?? undefined}
                      className="w-full"
                    />
                    {dateError && <p className="text-xs text-destructive">{dateError}</p>}
                  </div>
                </div>
                <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                  <div className="space-y-1.5">
                    <Label htmlFor="convert-salary">Starting monthly salary</Label>
                    <Input
                      id="convert-salary"
                      type="number"
                      min="0"
                      step="0.01"
                      placeholder="32000.00"
                      value={convertSalary}
                      aria-invalid={!!salaryError}
                      onChange={(e) => setConvertSalary(e.target.value)}
                    />
                    {salaryError && <p className="text-xs text-destructive">{salaryError}</p>}
                  </div>
                  <div className="space-y-1.5">
                    <Label htmlFor="convert-title">Job title</Label>
                    <Input
                      id="convert-title"
                      placeholder="Junior Software Engineer"
                      value={convertTitle}
                      onChange={(e) => setConvertTitle(e.target.value)}
                    />
                  </div>
                </div>
                <div className="rounded-lg border border-border p-3 space-y-2">
                  <p className="text-xs text-foreground">
                    <strong>Tenure is continuous.</strong> Their hire date
                    {employee.hire_date ? ` (${employee.hire_date})` : ""} stays as it is — the
                    internship is closed on the employment timeline and the new role opens on the
                    effective date.
                  </p>
                  <p className="text-xs text-foreground">
                    Leave credits are raised to the allotment for the new status. Credits already
                    granted by hand are never reduced.
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
                    : mode === "rehire"
                      ? "Rehire"
                      : "Convert"}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>

      {/* Step two of a back-dated conversion: the server named the runs it would reprice. */}
      <RepricingConfirmDialog
        message={confirmRepricing}
        pending={pending}
        onCancel={() => setConfirmRepricing(null)}
        onConfirm={async () => {
          setConfirmRepricing(null);
          await confirm(true);
        }}
      />
    </section>
  );
}
