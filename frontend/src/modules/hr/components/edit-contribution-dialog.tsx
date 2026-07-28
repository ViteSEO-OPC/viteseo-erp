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
import type { ContributionVersion } from "@/modules/hr/api/benefits";
import { CONTRIBUTION_LABELS } from "@/modules/hr/types/benefits";
import { SimpleSelect } from "@/modules/hr/ui/select";

/**
 * Record a dated change to a government contribution's rates (Slices B3a / B3b).
 *
 * The form is shaped per contribution rather than showing every knob to everyone. The three work
 * differently and an earlier version of this dialog exposed all of it at once — a salary floor, a
 * salary ceiling, two amount caps and a bracket toggle — which read as six interchangeable boxes.
 * It also let a version save with no ceiling at all, which charged a ₱95,000 earner 5% of their
 * whole salary.
 *
 *   SSS         salary brackets (MSC) · employer EC add-on
 *   PhilHealth  percentage between a floor and a ceiling
 *   Pag-IBIG    percentage with a maximum deduction
 *
 * Rates are entered as PERCENTAGES (how the agencies publish them); the API stores decimals.
 */
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
    how: "SSS puts each salary into a bracket and charges the rate on that bracket's Monthly Salary Credit — not on the actual salary. The employer also pays a flat EC amount above a salary threshold.",
    fields: "brackets",
  },
  philhealth: {
    how: "PhilHealth charges a percentage of the monthly salary, with a minimum and a maximum salary it applies to. The premium is split evenly between employee and employer.",
    fields: "range",
  },
  pagibig: {
    how: "Pag-IBIG charges a percentage, but never more than a fixed peso amount per month. Both sides contribute the same.",
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
  const [csv, setCsv] = useState(
    (latest?.brackets ?? []).map((b) => `${b.min_salary},${b.msc}`).join("\n"),
  );

  // Brackets are SSS's model. The option only appears for a contribution that already uses it or
  // is the one that does — showing it on PhilHealth and Pag-IBIG invited the question "do these
  // have brackets too?", and the answer is no.
  const bracketsApply = guide.fields === "brackets" || latest?.basis === "bracket";

  // The pasted table, as rows of numbers. HR then points at the two columns that matter — the
  // published SSS table has five or six, and which one is the MSC differs between circulars.
  const [minCol, setMinCol] = useState(0);
  const [mscCol, setMscCol] = useState(1);
  const allRows = parseRows(csv);
  const width = dominantWidth(allRows);
  const rows = allRows.filter((r) => r.length === width);
  const skipped = allRows.length - rows.length;
  const bands = bandsFrom(rows, minCol, mscCol);
  const bandsValid = !bracketsApply || bands.length > 0;

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
    return capped + (salary >= (Number(addOnMin) || 0) ? Number(addOn) || 0 : 0);
  };

  // The mistake this exists to catch: no ceiling and no maximum means a high earner pays the full
  // rate on their whole salary. Warn rather than block — a contribution can legitimately be
  // uncapped, and blocking would be wrong for it.
  const uncapped =
    !bracketsApply && salaryCap.trim() === "" && maxAmount.trim() === "" && toDecimal(employeeRate) > 0;

  async function submit() {
    try {
      await save.mutateAsync({
        type,
        effective_from: effectiveFrom,
        employee_rate: toDecimal(employeeRate),
        employer_rate: toDecimal(employerRate),
        // Only send what this contribution actually uses, so a stale value from another shape
        // can't linger on the version.
        salary_floor: bracketsApply ? null : numOrNull(salaryFloor),
        salary_cap: bracketsApply ? null : numOrNull(salaryCap),
        employee_amount_cap: bracketsApply ? null : numOrNull(maxAmount),
        employer_amount_cap: bracketsApply ? null : numOrNull(maxAmount),
        fixed_employer_add_on: Number(addOn) || 0,
        add_on_min_salary: numOrNull(addOnMin),
        note: note.trim() || null,
        basis: bracketsApply ? "bracket" : "percentage",
        brackets: bracketsApply ? bands : undefined,
      });
      toast.success(`${label} saved, effective ${formatDate(effectiveFrom)}`);
      onDone();
    } catch (err) {
      const errors = err instanceof AxiosError ? err.response?.data?.errors : null;
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
            <p className="text-caption text-muted-foreground">
              Paste the table straight from the SSS circular — select the rows in the PDF, copy,
              and paste here. Columns, ₱ signs and thousands separators are all fine; you pick
              which two columns to use below.
            </p>
            <textarea
              rows={7}
              value={csv}
              onChange={(e) => setCsv(e.target.value)}
              placeholder={"Below 5,250          5,000    250.00\n5,250 - 5,749.99     5,500    275.00\n19,750 - 20,249.99  20,000  1,000.00"}
              className="mt-2 w-full rounded-lg border border-input bg-transparent px-3 py-2 font-mono text-xs outline-none focus-visible:border-ring focus-visible:ring-3 focus-visible:ring-ring/50"
            />

            {rows.length === 0 ? (
              <p className="mt-1.5 text-caption text-muted-foreground">
                Nothing pasted yet.
              </p>
            ) : (
              <div className="mt-3 space-y-3">
                <div className="grid grid-cols-2 gap-4">
                  <Field label="Salary column" hint="Where each bracket starts">
                    <SimpleSelect
                      value={String(minCol)}
                      onValueChange={(v) => setMinCol(Number(v))}
                      options={Array.from({ length: width }, (_, i) => ({
                        value: String(i),
                        label: `Column ${i + 1} — e.g. ${formatPeso(rows[0][i])}`,
                      }))}
                    />
                  </Field>
                  <Field label="MSC column" hint="What the rate is charged on">
                    <SimpleSelect
                      value={String(mscCol)}
                      onValueChange={(v) => setMscCol(Number(v))}
                      options={Array.from({ length: width }, (_, i) => ({
                        value: String(i),
                        label: `Column ${i + 1} — e.g. ${formatPeso(rows[0][i])}`,
                      }))}
                    />
                  </Field>
                </div>

                {/* Check a few rows read correctly before trusting the other forty. */}
                <div className="overflow-x-auto rounded-lg border border-border">
                  <table className="w-full text-caption">
                    <thead className="bg-muted/60 text-muted-foreground">
                      <tr>
                        <th className="px-3 py-1.5 text-left">Salary from</th>
                        <th className="px-3 py-1.5 text-left">Charged on (MSC)</th>
                      </tr>
                    </thead>
                    <tbody>
                      {bands.slice(0, 4).map((b, i) => (
                        <tr key={i} className="border-t border-border">
                          <td className="px-3 py-1.5">{formatPeso(b.min_salary)}</td>
                          <td className="px-3 py-1.5">{formatPeso(b.msc)}</td>
                        </tr>
                      ))}
                      {bands.length > 4 && (
                        <tr className="border-t border-border text-muted-foreground">
                          <td className="px-3 py-1.5" colSpan={2}>
                            …and {bands.length - 4} more, up to {formatPeso(bands[bands.length - 1].msc)}
                          </td>
                        </tr>
                      )}
                    </tbody>
                  </table>
                </div>

                <p className="text-caption text-muted-foreground">
                  {bands.length} bracket{bands.length === 1 ? "" : "s"} · the lowest is set to start
                  at ₱0 automatically, because the published table prints it as
                  &ldquo;Below&rdquo; with no starting figure.
                  {skipped > 0 && (
                    <>
                      {" "}
                      <span className="font-medium text-warning">
                        {skipped} line{skipped === 1 ? "" : "s"} skipped
                      </span>{" "}
                      — headings or notes with a different number of columns. Check the top and
                      bottom of your paste.
                    </>
                  )}
                </p>
              </div>
            )}
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

        {/* Pag-IBIG — a cap on the deduction itself. */}
        {!bracketsApply && guide.fields === "maximum" && (
          <Section title="Maximum contribution">
            <Field
              label="Never deduct more than (₱ per month)"
              hint="Applies to both sides. Leave blank for no maximum."
            >
              <Input type="number" step="0.01" min={0} className="max-w-xs" placeholder="no maximum" value={maxAmount} onChange={(e) => setMaxAmount(e.target.value)} />
            </Field>
          </Section>
        )}

        {/* SSS EC — employer-only, so it lives on its own. */}
        {bracketsApply && (
          <Section title="Employer-only add-on">
            <p className="text-caption text-muted-foreground">
              A flat amount the company pays on top, above a salary threshold. Never deducted from
              the employee.
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
        <Button disabled={save.isPending || !bandsValid} onClick={submit}>
          {save.isPending ? "Saving…" : "Save"}
        </Button>
      </DialogFooter>
    </>
  );
}

/**
 * Turn text pasted from an SSS circular (usually a PDF or a spreadsheet) into rows of numbers.
 *
 * The published table is multi-column and the numbers carry thousands separators, so the naive
 * "split on commas" approach shredded "5,250" into two values. This pulls every NUMBER out of a
 * line instead, which survives tabs, multiple spaces, ₱ signs, en-dashes and stray footnotes:
 *
 *   "Below 5,250        5,000   250.00"  →  [5250, 5000, 250]
 *   "5,250 - 5,749.99   5,500   275.00"  →  [5250, 5749.99, 5500, 275]
 *
 * Rows come out with different lengths (a "Below X" row has no range start), so the caller keeps
 * only the most common shape and lets HR point at the two columns that matter.
 */
/**
 * A comma means "thousands" only when exactly three digits follow it — `5,250` is one number but
 * `0,5000` (how an existing table is prefilled below) is two. Without the lookahead the greedy
 * form reads `0,5000` as `05000` and the round-trip of a saved table silently corrupts it.
 */
const NUMBER = /\d{1,3}(?:,\d{3})+(?![\d,])(?:\.\d+)?|\d+(?:\.\d+)?/g;

export function parseRows(text: string): number[][] {
  const raw = text
    .split(/\r?\n/)
    .map((line) => ({
      line,
      nums: (line.match(NUMBER) ?? []).map((n) => Number(n.replace(/,/g, ""))),
    }))
    .filter((r) => r.nums.length >= 2 && r.nums.every(Number.isFinite));

  const width = dominantWidth(raw.map((r) => r.nums));

  // The FIRST and LAST rows of the published table are shaped differently: "Below ₱5,250" has no
  // range start, and "₱34,750 and above" has no range end. Both come out one number short, so a
  // plain width filter drops exactly the two brackets that matter most — the bottom one, and the
  // TOP one that every high earner is charged on. Pad them back into alignment.
  return raw.map(({ line, nums }) => {
    if (nums.length !== width - 1) {
      return nums;
    }
    if (/below|less than|under/i.test(line)) {
      return [0, ...nums]; // the missing range start is 0
    }
    if (/above|over|and up|onwards/i.test(line)) {
      return [nums[0], nums[0], ...nums.slice(1)]; // no range end; only the start is ever read
    }
    return nums;
  });
}

/** The row shape that appears most often — the table body, with headers and notes left out. */
export function dominantWidth(rows: number[][]): number {
  const counts = new Map<number, number>();
  rows.forEach((r) => counts.set(r.length, (counts.get(r.length) ?? 0) + 1));
  let best = 0;
  let bestCount = 0;
  counts.forEach((count, width) => {
    if (count > bestCount || (count === bestCount && width > best)) {
      best = width;
      bestCount = count;
    }
  });
  return best;
}

/**
 * Build the bands from two chosen columns.
 *
 * The lowest band is forced to start at 0: the published table's first row reads "Below ₱5,250",
 * so its range START is not printed anywhere. Without this every salary under the first printed
 * figure would fall into no band and contribute nothing.
 */
export function bandsFrom(
  rows: number[][],
  minCol: number,
  mscCol: number,
): { min_salary: number; msc: number }[] {
  const bands = rows
    .filter((r) => r[minCol] !== undefined && r[mscCol] !== undefined)
    .map((r) => ({ min_salary: r[minCol], msc: r[mscCol] }))
    .sort((a, b) => a.min_salary - b.min_salary);

  if (bands.length > 0) {
    bands[0] = { ...bands[0], min_salary: 0 };
  }

  return bands;
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
