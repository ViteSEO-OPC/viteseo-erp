"use client";

import type { FieldValues, Path, UseFormReturn } from "react-hook-form";

import { Input } from "@/components/ui/input";
import { Button } from "@/components/ui/button";
import { formatPeso } from "@/core/lib/format";
import { useStatutoryPreview } from "@/modules/hr/hooks/use-employees";
import { CONTRIBUTION_LABELS } from "@/modules/hr/types/benefits";
import type { StatutoryPreviewRow } from "@/modules/hr/types/employee";

/** The three form fields this block owns, in display order. */
const FIELDS = [
  { type: "sss", name: "sss_contribution" },
  { type: "philhealth", name: "philhealth_contribution" },
  { type: "pagibig", name: "pagibig_contribution" },
] as const;

/**
 * Per-employee statutory overrides — SSS / PhilHealth / Pag-IBIG (Slice B7).
 *
 * WHY THIS ISN'T THREE ORDINARY FIELDS ANY MORE. A peso amount in one of these boxes switches off
 * the government rates for that employee — and only on the EMPLOYEE side. The employer half is
 * still calculated, so the payslip ends up carrying a pair that exists on no SSS table (₱1,350
 * employee against ₱3,530 employer) and the remittance built from it cannot be filed. They sat in
 * the same flat grid as the bank details with no label saying any of that, so the next person to
 * type in one would have silently switched off the rates for that employee.
 *
 * Every field therefore shows what the rates WOULD charge, and can be cleared in one click.
 */
export function StatutoryOverridesBlock<T extends FieldValues>({
  employeeId,
  employee,
  editing,
  form,
}: {
  employeeId: number;
  /** The saved values — what to show when not editing. */
  employee: Record<string, string | null | undefined>;
  editing: boolean;
  form: UseFormReturn<T>;
}) {
  const { data: preview } = useStatutoryPreview(employeeId);
  const byType = new Map((preview ?? []).map((r) => [r.type, r]));

  // In edit mode the warning has to track what is typed, not what is saved — clearing a box
  // should make the banner go away before saving, or it reads as though the clear did nothing.
  const active = FIELDS.filter(({ name }) => {
    const typed = editing ? form.watch(name as Path<T>) : employee[name];
    return typed !== null && typed !== undefined && String(typed).trim() !== "";
  }).map(({ type }) => type);

  return (
    <div className="border-t border-border pt-6">
      <p className="mb-1 text-label font-semibold uppercase tracking-wide text-brand">
        Statutory contribution overrides
      </p>
      <p className="mb-4 text-caption text-muted-foreground">
        Leave these blank so the employee is charged the government rates set in{" "}
        <strong>Company &rarr; Benefits</strong>. A peso amount here replaces the rate for this
        person — and only their half of it. The employer share is still calculated, so an override
        makes the two halves of their payslip disagree.
      </p>

      {active.length > 0 && (
        <div className="mb-4 rounded-lg border border-warning/40 bg-warning-subtle px-3 py-2.5 text-xs text-foreground">
          <strong>
            {active.map((t) => CONTRIBUTION_LABELS[t] ?? t).join(", ")}{" "}
            {active.length === 1 ? "is" : "are"} overridden for this employee.
          </strong>{" "}
          The government rates do not apply to {active.length === 1 ? "it" : "them"}, and the
          employer counterpart still does — the pair will not match any single salary bracket on
          the remittance.
        </div>
      )}

      <div className="grid gap-4 sm:grid-cols-3">
        {FIELDS.map(({ type, name }) => (
          <OverrideField
            key={type}
            label={CONTRIBUTION_LABELS[type] ?? type}
            name={name as Path<T>}
            saved={employee[name]}
            row={byType.get(type)}
            editing={editing}
            form={form}
          />
        ))}
      </div>
    </div>
  );
}

function OverrideField<T extends FieldValues>({
  label,
  name,
  saved,
  row,
  editing,
  form,
}: {
  label: string;
  name: Path<T>;
  saved: string | null | undefined;
  row: StatutoryPreviewRow | undefined;
  editing: boolean;
  form: UseFormReturn<T>;
}) {
  const typed = editing ? (form.watch(name) as unknown) : saved;
  const has = typed !== null && typed !== undefined && String(typed).trim() !== "";

  return (
    <div className="space-y-1.5">
      <label className="block text-label font-bold uppercase tracking-wide text-muted-foreground">
        {label}
      </label>

      {editing ? (
        <div className="flex items-center gap-2">
          <Input
            type="number"
            min="0"
            step="0.01"
            placeholder="Use the rates"
            {...form.register(name)}
          />
          {has && (
            // Clearing is the intended action for the ten profiles carrying leftover seed values,
            // so it is one click rather than "select the text and delete it".
            <Button
              type="button"
              variant="outline"
              size="sm"
              onClick={() =>
                form.setValue(name, "" as never, { shouldDirty: true, shouldValidate: true })
              }
            >
              Clear
            </Button>
          )}
        </div>
      ) : (
        <p className="text-sm text-foreground">
          {has ? (
            <span className="font-semibold text-warning">{formatPeso(String(typed))}</span>
          ) : (
            <span className="text-muted-foreground">Government rate</span>
          )}
        </p>
      )}

      <Calculated row={row} overriding={has} />
    </div>
  );
}

/**
 * What payroll would charge without the override. Both halves, because the employer one is what
 * the override does NOT change — seeing ₱1,350 against an employer ₱3,530 is the fastest way to
 * understand why the remittance is wrong.
 */
function Calculated({ row, overriding }: { row: StatutoryPreviewRow | undefined; overriding: boolean }) {
  if (!row) return null;

  if (!row.enrolled) {
    return (
      <p className="text-caption text-muted-foreground">
        Not enrolled — nothing is deducted, so an amount here does nothing.
      </p>
    );
  }

  return (
    <p className="text-caption text-muted-foreground">
      {overriding ? "Rates would charge" : "Charged"}{" "}
      <strong>{formatPeso(row.calculated_employee)}</strong> · employer{" "}
      {formatPeso(row.calculated_employer)}
      {row.basis === "bracket" && " · bracket"}
    </p>
  );
}
