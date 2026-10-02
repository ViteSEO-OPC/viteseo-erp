"use client";

import { Label } from "@/components/ui/label";
import { cn } from "@/lib/utils";
import { MultiCombobox } from "@/modules/hr/ui/multi-combobox";
import { EMPLOYMENT_STATUSES, statusLabel, type EmployeeOption } from "@/modules/hr/types/employee";
import { ELIGIBILITY_MODES, type EligibilityMode } from "@/modules/hr/types/benefits";

/** Who an allowance is for — the same control on the add and the edit dialog (B8 follow-up). */
export function EligibilityPicker({
  mode,
  statuses,
  employeeIds,
  employees,
  modes = ELIGIBILITY_MODES,
  onModeChange,
  onStatusesChange,
  onEmployeeIdsChange,
}: {
  mode: EligibilityMode;
  statuses: string[];
  /** Defaults to every mode. */
  modes?: readonly { value: EligibilityMode; label: string }[];
  employeeIds: number[];
  employees: EmployeeOption[];
  onModeChange: (mode: EligibilityMode) => void;
  onStatusesChange: (statuses: string[]) => void;
  onEmployeeIdsChange: (ids: number[]) => void;
}) {
  // What the rule covers right now, so the choice is made against a number rather than a guess.
  const covered =
    mode === "all"
      ? employees.length
      : mode === "status"
        ? employees.filter((e) => statuses.includes(e.employment_status)).length
        : mode === "management"
          ? employees.filter((e) => e.is_management).length
          : employeeIds.length;

  return (
    <div className="space-y-3">
      <div className="space-y-1.5">
        <Label>Who gets this allowance</Label>
        <div className="flex flex-wrap gap-2">
          {modes.map((o) => (
            <button
              key={o.value}
              type="button"
              onClick={() => onModeChange(o.value)}
              className={cn(
                "rounded-full border px-3 py-1.5 text-sm font-medium transition-colors",
                mode === o.value
                  ? "border-brand bg-brand-subtle text-dark-teal"
                  : "border-input text-muted-foreground hover:bg-muted",
              )}
            >
              {o.label}
            </button>
          ))}
        </div>
      </div>

      {mode === "status" && (
        <div className="space-y-1.5">
          <Label htmlFor="elig-statuses">Employment types</Label>
          <MultiCombobox
            id="elig-statuses"
            options={EMPLOYMENT_STATUSES.map((s) => ({ value: s, label: statusLabel(s) }))}
            selected={statuses}
            onChange={onStatusesChange}
            placeholder="Choose one or more…"
            searchPlaceholder="Search statuses…"
          />
          <p className="text-caption text-muted-foreground">
            Anyone on one of these statuses gets it, including people hired later. Someone whose
            status changes starts or stops getting it on their next payslip.
          </p>
        </div>
      )}

      {mode === "management" && (
        <p className="text-caption text-muted-foreground">
          Everyone marked as management staff on their record, now and in future.
        </p>
      )}

      {mode === "custom" && (
        <div className="space-y-1.5">
          <Label htmlFor="elig-employees">Employees</Label>
          <MultiCombobox
            id="elig-employees"
            options={employees.map((e) => ({ value: String(e.id), label: e.name }))}
            selected={employeeIds.map(String)}
            onChange={(v) => onEmployeeIdsChange(v.map(Number))}
            placeholder="Choose employees…"
            searchPlaceholder="Search employees…"
          />
          <p className="text-caption text-muted-foreground">
            Only these people. Unlike the options above, this list does not update itself — a new
            hire has to be added here by hand.
          </p>
        </div>
      )}

      <p
        className={cn(
          "text-caption",
          covered === 0 ? "font-medium text-warning" : "text-muted-foreground",
        )}
      >
        {covered === 0
          ? "This covers nobody right now — nobody would be paid it."
          : `Covers ${covered} employee${covered === 1 ? "" : "s"} right now.`}
      </p>
    </div>
  );
}
