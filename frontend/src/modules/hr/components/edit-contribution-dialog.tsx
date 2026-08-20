"use client";

import { useState } from "react";
import { AxiosError } from "axios";
import { ChevronDown } from "lucide-react";
import { notify as toast } from "@/core/lib/notify";

import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import {
  Dialog,
  DialogContent,
  DialogHeader,
  DialogTitle,
  DialogDescription,
  DialogFooter,
  DialogClose,
} from "@/components/ui/dialog";
import { formatPeso, formatDate } from "@/core/lib/format";
import {
  useContributionVersions,
  useSaveContributionVersion,
} from "@/modules/hr/hooks/use-benefits";
import type {
  ContributionBand,
  ContributionVersion,
  ScheduleImportResult,
} from "@/modules/hr/api/benefits";
import { ScheduleUpload } from "@/modules/hr/components/schedule-upload";
import { CONTRIBUTION_LABELS, SALARY_BASES } from "@/modules/hr/types/benefits";
import { cn } from "@/lib/utils";

/** Record a dated change to a government contribution's rates. */
export function EditContributionDialog({
  type,
  onOpenChange,
}: {
  type: string | null;
  onOpenChange: (open: boolean) => void;
}) {
  return (
    <Dialog open={!!type} onOpenChange={onOpenChange}>
      <DialogContent className="max-h-[90svh] max-w-[calc(100%-3rem)] overflow-y-auto sm:max-w-xl">
        {type && <ContributionForm key={type} type={type} onDone={() => onOpenChange(false)} />}
      </DialogContent>
    </Dialog>
  );
}

/** How each contribution is calculated, in HR's words. */
const GUIDE: Record<string, { how: string; fields: string }> = {
  sss: {
    how: "SSS puts each salary into a bracket and charges the rate on that bracket's Monthly Salary Credit — not on the actual salary. The employer also pays an EC amount, which the published schedule prints for every bracket.",
    fields: "brackets",
  },
  philhealth: {
    how: "PhilHealth charges a percentage of the monthly salary, with a minimum and a maximum salary it applies to. The premium is split evenly between employee and employer.",
    fields: "range",
  },
  pagibig: {
    how: "Pag-IBIG charges a percentage of the monthly salary up to a ceiling — the “fund salary” — so the contribution never exceeds that ceiling times the rate. The employer matches the employee.",
    fields: "maximum",
  },
};

const toPct = (d: number | null | undefined) =>
  d === null || d === undefined ? "" : String(Number((d * 100).toFixed(3)));
const toDecimal = (pct: string) => Number(pct || 0) / 100;
const numOrNull = (v: string) => (v.trim() === "" ? null : Number(v));

function ContributionForm({ type, onDone }: { type: string; onDone: () => void }) {
  const { data: versions } = useContributionVersions(type);
  const save = useSaveContributionVersion();

  const latest: ContributionVersion | undefined = versions?.[0];
  const label = CONTRIBUTION_LABELS[type] ?? type.toUpperCase();
  const guide = GUIDE[type] ?? { how: "", fields: "range" };

  const [effectiveFrom, setEffectiveFrom] = useState(new Date().toISOString().slice(0, 10));
  const [employeeRate, setEmployeeRate] = useState(toPct(latest?.employee_rate));
  const [employerRate, setEmployerRate] = useState(toPct(latest?.employer_rate));
  const [salaryFloor, setSalaryFloor] = useState(String(latest?.salary_floor ?? ""));
  const [salaryCap, setSalaryCap] = useState(String(latest?.salary_cap ?? ""));
  const [maxAmount, setMaxAmount] = useState(String(latest?.employee_amount_cap ?? ""));
  const [addOn, setAddOn] = useState(String(latest?.fixed_employer_add_on ?? 0));
  const [addOnMin, setAddOnMin] = useState(String(latest?.add_on_min_salary ?? ""));
  const [note, setNote] = useState("");
  // WHICH salary figure the rate applies to, as opposed to the bracket/percentage
  // choice above, which is how the charge is worked out once a figure is chosen. Defaults to
  // whatever the version in force already does, so opening this dialog and saving without
  // touching it never moves a contribution onto a different base by accident.
  const [salaryBasis, setSalaryBasis] = useState(latest?.salary_basis ?? "basic");
  // The bands in play: whatever an upload produced, else whatever this version
  // already has. Sixty rows of government figures are never retyped.
  const [imported, setImported] = useState<ScheduleImportResult | null>(null);

  // Brackets are SSS's model. The option only appears for a contribution that already uses it or
  // is the one that does — showing it on PhilHealth and Pag-IBIG invited the question "do these
  // have brackets too?", and the answer is no.
  const bracketsApply = guide.fields === "brackets" || latest?.basis === "bracket";

  const bands: ContributionBand[] = imported
    ? imported.bands.map((b) => ({
        min_salary: b.min_salary,
        msc: b.msc,
        employer_add_on: b.employer_add_on,
      }))
    : (latest?.brackets ?? []);
  const bandsValid = !bracketsApply || bands.length > 0;

  // Does the table carry its own EC? If it does, the flat add-on below is dead weight:
  // `ContributionCalculator` prefers the band's amount, so anything typed there would be ignored
  // for every band that has one.
  const ecAmounts = [
    ...new Set(
      bands.map((b) => b.employer_add_on).filter((a): a is number => a != null),
    ),
  ].sort((a, b) => a - b);
  const ecFromTable = ecAmounts.length > 0;
  const ecRange =
    ecAmounts.length === 1
      ? formatPeso(ecAmounts[0])
      : `${formatPeso(ecAmounts[0])}–${formatPeso(ecAmounts[ecAmounts.length - 1])}`;

  // A live worked example, so HR checks against the published schedule instead of the form.
  const sample = 20000;
  const high = 95000;
  const chargeableFor = (salary: number) => {
    if (bracketsApply && bands.length > 0) {
      const band = [...bands].reverse().find((b) => salary >= b.min_salary);
      return band ? band.msc : salary;
    }
    return Math.min(Number(salaryCap) || Infinity, Math.max(Number(salaryFloor) || 0, salary));
  };
  const employeePays = (salary: number) => {
    const raw = chargeableFor(salary) * toDecimal(employeeRate);
    return maxAmount.trim() === "" ? raw : Math.min(raw, Number(maxAmount));
  };
  const employerPays = (salary: number) => {
    const raw = chargeableFor(salary) * toDecimal(employerRate);
    const capped = maxAmount.trim() === "" ? raw : Math.min(raw, Number(maxAmount));
    // The band's own EC wins when the published table printed one, so the worked
    // example shows what payroll will really charge rather than the flat-rule approximation.
    const band = bracketsApply
      ? [...bands].reverse().find((b) => salary >= b.min_salary)
      : undefined;
    const ec =
      band?.employer_add_on != null
        ? band.employer_add_on
        : salary >= (Number(addOnMin) || 0)
          ? Number(addOn) || 0
          : 0;
    return capped + ec;
  };

  // The mistake this exists to catch: no ceiling and no maximum means a high earner pays the full
  // rate on their whole salary. Warn rather than block — a contribution can legitimately be
  // uncapped, and blocking would be wrong for it.
  const uncapped =
    !bracketsApply && salaryCap.trim() === "" && maxAmount.trim() === "" && toDecimal(employeeRate) > 0;

  /** Apply what the importer read: the bands, and the rates and ceiling it inferred from them. */
  function applyImport(result: ScheduleImportResult) {
    setImported(result);
    if (result.suggested.employee_rate != null) {
      setEmployeeRate(toPct(result.suggested.employee_rate));
    }
    if (result.suggested.employer_rate != null) {
      setEmployerRate(toPct(result.suggested.employer_rate));
    }
  }

  /** HR's acknowledgement that this date reaches back over completed payroll runs. */
  const [repricingWarning, setRepricingWarning] = useState<string | null>(null);

  async function submit(acknowledgeRepricing = false) {
    try {
      await save.mutateAsync({
        acknowledge_repricing: acknowledgeRepricing || undefined,
        type,
        effective_from: effectiveFrom,
        employee_rate: toDecimal(employeeRate),
        employer_rate: toDecimal(employerRate),
        // Only send what this contribution actually uses, so a stale value from another shape
        // can't linger on the version.
        salary_floor: bracketsApply ? null : numOrNull(salaryFloor),
        // For a bracket version the ceiling IS the top band's MSC, so it is derived rather than
        // typed. It is still stored, because the engine falls back to percentage-of-salary if a
        // version ever ends up with no bands (so an unfinished import can't wipe the deduction) —
        // and with no ceiling that fallback would charge a ₱160,000 earner 5% of the lot, ₱8,000
        // instead of ₱1,750. A safe fallback needs the ceiling to be there.
        salary_cap: bracketsApply
          ? (bands.length > 0 ? bands[bands.length - 1].msc : null)
          : numOrNull(salaryCap),
        employee_amount_cap: bracketsApply ? null : numOrNull(maxAmount),
        employer_amount_cap: bracketsApply ? null : numOrNull(maxAmount),
        // When the table prints an EC per band, the flat rule must be cleared rather
        // than carried over. It would be inert for every band that has an amount, and then apply
        // silently to any band that does not — a surprise nobody could see coming.
        fixed_employer_add_on: ecFromTable ? 0 : Number(addOn) || 0,
        add_on_min_salary: ecFromTable ? null : numOrNull(addOnMin),
        note: note.trim() || null,
        basis: bracketsApply ? "bracket" : "percentage",
        salary_basis: salaryBasis,
        brackets: bracketsApply ? bands : undefined,
      });
      toast.success(`${label} saved, effective ${formatDate(effectiveFrom)}`);
      setRepricingWarning(null);
      onDone();
    } catch (err) {
      const errors = err instanceof AxiosError ? err.response?.data?.errors : null;

      // This one is a question, not a failure: show what it would reprice and let HR
      // decide. Any other error stays an error.
      const backdated = errors?.effective_from?.[0];
      if (backdated) {
        setRepricingWarning(String(backdated));
        return;
      }

      toast.error(errors ? String(Object.values(errors)[0]) : "Could not save.");
    }
  }

  return (
    <>
      <DialogHeader>
        <DialogTitle>{label}</DialogTitle>
        <DialogDescription>{guide.how}</DialogDescription>
      </DialogHeader>

      <div className="mt-5 space-y-5">
        <Field
          label="Takes effect from"
          hint="Payslips before this date keep the rates they were calculated with."
        >
          <Input
            type="date"
            className="max-w-xs"
            value={effectiveFrom}
            onChange={(e) => setEffectiveFrom(e.target.value)}
          />
        </Field>

        {/*
         * The salary the rate is applied to. Placed above the rate because it is the
         * larger of the two decisions: changing it moves what every payslip is measured against,
         * while a rate change only moves the multiplier.
         */}
        <Section title="The salary it is charged on">
          <div className="flex flex-wrap gap-2">
            {SALARY_BASES.map((option) => (
              <button
                key={option.value}
                type="button"
                onClick={() => setSalaryBasis(option.value)}
                className={cn(
                  "rounded-full border px-3 py-1.5 text-sm font-medium transition-colors",
                  salaryBasis === option.value
                    ? "border-brand bg-brand-subtle text-dark-teal"
                    : "border-input text-muted-foreground hover:bg-muted",
                )}
              >
                {option.label}
              </button>
            ))}
          </div>
          <p className="mt-2 text-caption text-muted-foreground">
            {salaryBasis === "earnings"
              ? "Charged on what the period actually paid: basic pay after absences and lates, plus overtime, night differential, holiday and rest-day premium, and the allowances marked as counting toward contributions."
              : "Charged on the employee's basic monthly salary from their profile, whatever they actually earned in the period."}
          </p>
          {/*
           * Not blocked — a company may have a reason — but PhilHealth's basis is set by
           * regulation rather than by preference, and saying so is cheaper than a correction
           * after a quarter of remittances.
           */}
          {type === "philhealth" && salaryBasis === "earnings" && (
            <p className="mt-2 text-caption text-warning">
              PhilHealth premiums are set on the fixed <strong>basic</strong> monthly salary
              (Circular 2019-0009), which expressly excludes allowances, overtime and COLA. Charging
              them on actual earnings will not match what PhilHealth expects to receive.
            </p>
          )}
        </Section>

        <Section title="The rate">
          <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
            <Field label="Employee pays (%)">
              <Input type="number" step="0.001" min={0} max={100} value={employeeRate} onChange={(e) => setEmployeeRate(e.target.value)} />
            </Field>
            <Field label="Employer pays (%)">
              <Input type="number" step="0.001" min={0} max={100} value={employerRate} onChange={(e) => setEmployerRate(e.target.value)} />
            </Field>
          </div>
        </Section>

        {/* SSS — the MSC table replaces floor/ceiling/maximum entirely. */}
        {bracketsApply && (
          <Section title="Salary brackets">
            <ScheduleUpload
              current={bands}
              imported={imported}
              onImported={applyImport}
            />
          </Section>
        )}

        {/* PhilHealth — a salary range. */}
        {!bracketsApply && guide.fields === "range" && (
          <Section title="Salary range the rate applies to">
            <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
              <Field label="Minimum salary" hint="Anyone earning less is charged as if they earned this">
                <Input type="number" step="0.01" min={0} placeholder="no minimum" value={salaryFloor} onChange={(e) => setSalaryFloor(e.target.value)} />
              </Field>
              <Field label="Maximum salary" hint="Anyone earning more is charged as if they earned this">
                <Input type="number" step="0.01" min={0} placeholder="no maximum" value={salaryCap} onChange={(e) => setSalaryCap(e.target.value)} />
              </Field>
            </div>
          </Section>
        )}

        {/*
         * Pag-IBIG — the cap can be expressed either way, and BOTH are shown because both can be
         * in force. The rule Pag-IBIG actually publishes is a cap on the salary the rate applies
         * to ("fund salary"), and that is what the seeded version uses. Showing only the amount
         * cap hid the ₱10,000 salary cap that was really in force — the form said "no maximum"
         * while payroll was capping every contribution at ₱200.
         */}
        {!bracketsApply && guide.fields === "maximum" && (
          <Section title="Maximum contribution">
            <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
              <Field
                label="Maximum salary the rate applies to"
                hint="Pag-IBIG's own rule: the “fund salary” ceiling. Anyone earning more is charged as if they earned this."
              >
                <Input type="number" step="0.01" min={0} placeholder="no maximum" value={salaryCap} onChange={(e) => setSalaryCap(e.target.value)} />
              </Field>
              <Field
                label="Or never deduct more than (₱/month)"
                hint="The same cap expressed as an amount. Only one of the two is needed."
              >
                <Input type="number" step="0.01" min={0} placeholder="no maximum" value={maxAmount} onChange={(e) => setMaxAmount(e.target.value)} />
              </Field>
            </div>
            <p className="mt-2 text-caption text-muted-foreground">
              These are two ways of saying the same thing at a flat rate — a {formatPeso(10000)}{" "}
              salary ceiling at 2% and a {formatPeso(200)} amount ceiling both come to{" "}
              {formatPeso(200)}. Prefer the salary ceiling: it stays correct if the rate ever
              changes.
            </p>
          </Section>
        )}

        {/* SSS EC — employer-only, so it lives on its own. */}
        {bracketsApply && ecFromTable && (
          <Section title="Employer-only add-on">
            <p className="text-caption text-muted-foreground">
              Taken from the uploaded schedule, which prints an amount for every bracket — so
              there is nothing to set here. Currently{" "}
              <strong>{ecRange}</strong> depending on the bracket, paid by the company on top and
              never deducted from the employee.
            </p>
          </Section>
        )}

        {bracketsApply && !ecFromTable && (
          <Section title="Employer-only add-on">
            <p className="text-caption text-muted-foreground">
              A flat amount the company pays on top, above a salary threshold. Never deducted from
              the employee. The published schedule normally prints this per bracket — if your
              uploaded file has that column, point at it above and these two fields disappear.
            </p>
            <div className="mt-2 grid grid-cols-1 gap-4 sm:grid-cols-2">
              <Field label="Amount (₱)">
                <Input type="number" step="0.01" min={0} value={addOn} onChange={(e) => setAddOn(e.target.value)} />
              </Field>
              <Field label="From salary of">
                <Input type="number" step="0.01" min={0} placeholder="none" value={addOnMin} onChange={(e) => setAddOnMin(e.target.value)} />
              </Field>
            </div>
          </Section>
        )}

        {uncapped && (
          <div className="rounded-card border border-warning bg-warning-subtle p-4">
            <p className="text-sm font-semibold text-warning">No maximum set</p>
            <p className="mt-1 text-sm text-foreground">
              Everyone pays the full rate on their whole salary — a {formatPeso(high)} earner would
              pay <strong>{formatPeso(high * toDecimal(employeeRate))}</strong> a month. If this
              contribution has a ceiling, set it above.
            </p>
          </div>
        )}

        {/* Check this against the published schedule before saving. */}
        <div className="rounded-card border border-info/40 bg-info-subtle p-4">
          <p className="text-sm font-semibold text-foreground">What this works out to</p>
          <div className="mt-2 space-y-1 text-sm text-foreground">
            {[sample, high].map((salary) => (
              <p key={salary}>
                On {formatPeso(salary)}: employee{" "}
                <strong>{formatPeso(employeePays(salary))}</strong>, employer{" "}
                <strong>{formatPeso(employerPays(salary))}</strong>
                {bracketsApply && bands.length > 0 && (
                  <span className="text-muted-foreground">
                    {" "}
                    (charged on {formatPeso(chargeableFor(salary))})
                  </span>
                )}
              </p>
            ))}
          </div>
        </div>

        <Field label="Note (optional)" hint="Where these figures came from">
          <Input value={note} onChange={(e) => setNote(e.target.value)} placeholder="Per SSS Circular 2025-006…" />
        </Field>

        {versions && versions.length > 0 && (
          <details className="rounded-card border border-border">
            <summary className="flex cursor-pointer list-none items-center justify-between gap-2 p-3">
              <span className="text-sm font-medium text-foreground">
                Previous rates ({versions.length})
              </span>
              <ChevronDown className="size-4 shrink-0 text-muted-foreground" />
            </summary>
            <div className="border-t border-border p-3">
              {versions.map((v) => (
                <div key={v.id} className="flex items-baseline justify-between gap-3 border-b border-border/60 py-1.5 text-caption last:border-0">
                  <span className="font-medium text-foreground">{formatDate(v.effective_from)}</span>
                  <span className="text-muted-foreground">
                    {Number((v.employee_rate * 100).toFixed(3))}% / {Number((v.employer_rate * 100).toFixed(3))}%
                    {v.basis === "bracket" && ` · ${v.brackets.length} brackets`}
                    {v.fixed_employer_add_on > 0 && ` · +${formatPeso(v.fixed_employer_add_on)}`}
                  </span>
                </div>
              ))}
            </div>
          </details>
        )}
      </div>

      <DialogFooter className="mt-6">
        <DialogClose asChild>
          <Button variant="outline">Cancel</Button>
        </DialogClose>
        <Button disabled={save.isPending || !bandsValid} onClick={() => submit()}>
          {save.isPending ? "Saving…" : "Save"}
        </Button>
      </DialogFooter>
      {/* Backdating is allowed, but never silently. */}
      <Dialog open={repricingWarning !== null} onOpenChange={(o) => !o && setRepricingWarning(null)}>
        <DialogContent className="max-w-lg">
          <DialogHeader>
            <DialogTitle>This date reaches into finished payroll</DialogTitle>
            <DialogDescription>{repricingWarning}</DialogDescription>
          </DialogHeader>
          <p className="rounded-md bg-muted px-3 py-2 text-caption text-muted-foreground">
            Those runs were priced at the old rates. Saving is fine — but if any of them is
            re-run later, its statutory deductions will change to match this new rate.
          </p>
          <DialogFooter>
            <Button variant="outline" onClick={() => setRepricingWarning(null)}>
              Pick another date
            </Button>
            <Button disabled={save.isPending} onClick={() => submit(true)}>
              {save.isPending ? "Saving…" : "Save anyway"}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </>
  );
}

function Section({ title, children }: { title: string; children: React.ReactNode }) {
  return (
    <div>
      <p className="mb-2 text-label font-bold uppercase tracking-wide text-muted-foreground">
        {title}
      </p>
      {children}
    </div>
  );
}

function Field({
  label,
  hint,
  children,
}: {
  label: string;
  hint?: string;
  children: React.ReactNode;
}) {
  return (
    <div className="space-y-1.5">
      <Label>{label}</Label>
      {children}
      {hint && <p className="text-caption text-muted-foreground">{hint}</p>}
    </div>
  );
}
