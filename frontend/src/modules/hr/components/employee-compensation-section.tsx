"use client";

import { zodResolver } from "@hookform/resolvers/zod";
import { z } from "zod";

import { formatPeso } from "@/core/lib/format";
import {
  FieldGrid,
  RecordSectionShell,
  type FieldSpec,
} from "@/modules/hr/components/record-fields";
import {
  optionalChoice,
  optionalMoney,
  optionalText,
  orNull,
  requiredMoney,
} from "@/modules/hr/lib/employee-validation";
import { useRecordSection } from "@/modules/hr/lib/use-record-section";
import {
  BANK_ACCOUNT_TYPES,
  PAY_FREQUENCIES,
  SALARY_CHANGE_TYPES,
  payFrequencyLabel,
  type Employee,
  type EmployeeUpdateInput,
} from "@/modules/hr/types/employee";

const PAY_FREQUENCY_VALUES = PAY_FREQUENCIES.map((f) => f.value);

const schema = z.object({
  base_salary: requiredMoney("Base salary"),
  pay_frequency: optionalChoice(PAY_FREQUENCY_VALUES, "Pay frequency"),
  currency: z
    .string()
    .trim()
    .refine((v) => v === "" || /^[A-Za-z]{3}$/.test(v), "Use a 3-letter code, e.g. PHP"),
  bank_name: optionalText(),
  bank_account_type: optionalChoice(BANK_ACCOUNT_TYPES, "Bank type"),
  bank_account: optionalText(100),
  bank_account_name: optionalText(),
  sss_contribution: optionalMoney("SSS"),
  pagibig_contribution: optionalMoney("Pag-IBIG"),
  philhealth_contribution: optionalMoney("PhilHealth"),
  food_allowance: optionalMoney("Food allowance"),
  transportation_allowance: optionalMoney("Transportation allowance"),
  load_allowance: optionalMoney("Load allowance"),
  tin: optionalText(32),
  sss_number: optionalText(32),
  philhealth_number: optionalText(32),
  pagibig_number: optionalText(32),
  is_management: z.boolean(),
  // Only consulted when the base salary actually changes.
  salary_change_type: z.string(),
  salary_change_reason: optionalText(),
});

type Values = z.infer<typeof schema>;

function toValues(employee: Employee): Values {
  return {
    base_salary: String(parseFloat(employee.base_salary)),
    pay_frequency: employee.pay_frequency ?? "",
    currency: employee.currency ?? "",
    bank_name: employee.bank_name ?? "",
    bank_account_type: employee.bank_account_type ?? "",
    bank_account: employee.bank_account ?? "",
    bank_account_name: employee.bank_account_name ?? "",
    sss_contribution: employee.sss_contribution ?? "",
    pagibig_contribution: employee.pagibig_contribution ?? "",
    philhealth_contribution: employee.philhealth_contribution ?? "",
    food_allowance: employee.food_allowance ?? "",
    transportation_allowance: employee.transportation_allowance ?? "",
    load_allowance: employee.load_allowance ?? "",
    tin: employee.tin ?? "",
    sss_number: employee.sss_number ?? "",
    philhealth_number: employee.philhealth_number ?? "",
    pagibig_number: employee.pagibig_number ?? "",
    is_management: employee.is_management ?? false,
    salary_change_type: "",
    salary_change_reason: "",
  };
}

function toPayload(v: Values): EmployeeUpdateInput {
  return {
    base_salary: Number(v.base_salary),
    pay_frequency: orNull(v.pay_frequency),
    currency: orNull(v.currency)?.toUpperCase() ?? null,
    bank_name: orNull(v.bank_name),
    bank_account_type: orNull(v.bank_account_type),
    bank_account: orNull(v.bank_account),
    bank_account_name: orNull(v.bank_account_name),
    // Sent as strings; the API validates them as numeric and the columns are decimal.
    sss_contribution: orNull(v.sss_contribution),
    pagibig_contribution: orNull(v.pagibig_contribution),
    philhealth_contribution: orNull(v.philhealth_contribution),
    food_allowance: orNull(v.food_allowance),
    transportation_allowance: orNull(v.transportation_allowance),
    load_allowance: orNull(v.load_allowance),
    tin: orNull(v.tin),
    sss_number: orNull(v.sss_number),
    philhealth_number: orNull(v.philhealth_number),
    pagibig_number: orNull(v.pagibig_number),
    is_management: v.is_management,
    salary_change_type: orNull(v.salary_change_type),
    salary_change_reason: orNull(v.salary_change_reason),
  };
}

export function EmployeeCompensationSection({
  employee,
  canEdit,
  children,
}: {
  employee: Employee;
  canEdit: boolean;
  /** Leave credits, history and loans — their own editors, below the fields. */
  children?: React.ReactNode;
}) {
  const { form, editing, saving, startEdit, cancel, submit } = useRecordSection<Values>({
    employee,
    resolver: zodResolver(schema),
    toValues,
    toPayload,
  });

  const salaryChanged =
    editing && Number(form.watch("base_salary")) !== parseFloat(employee.base_salary);

  const pay: FieldSpec<Values>[] = [
    {
      name: "base_salary",
      label: "Base salary",
      kind: "number",
      placeholder: "45000",
      display: `${formatPeso(employee.base_salary)} / mo`,
    },
    {
      name: "pay_frequency",
      label: "Pay frequency",
      kind: "select",
      options: PAY_FREQUENCIES.map((f) => ({ value: f.value, label: f.label })),
      display: payFrequencyLabel(employee.pay_frequency),
    },
    { name: "currency", label: "Currency", placeholder: "PHP" },
    { name: "bank_name", label: "Bank name", placeholder: "BPI" },
    {
      name: "bank_account_type",
      label: "Bank type",
      kind: "select",
      options: BANK_ACCOUNT_TYPES.map((t) => ({ value: t, label: t })),
      clearable: true,
    },
    { name: "bank_account", label: "Bank account number", placeholder: "1234-5678-9012-34" },
    { name: "bank_account_name", label: "Bank account name" },
    {
      name: "sss_contribution",
      label: "SSS",
      kind: "number",
      display: money(employee.sss_contribution),
    },
    {
      name: "pagibig_contribution",
      label: "Pag-IBIG",
      kind: "number",
      display: money(employee.pagibig_contribution),
    },
    {
      name: "philhealth_contribution",
      label: "PhilHealth",
      kind: "number",
      display: money(employee.philhealth_contribution),
    },
    {
      name: "food_allowance",
      label: "Food allowance",
      kind: "number",
      display: money(employee.food_allowance),
    },
    {
      name: "transportation_allowance",
      label: "Transportation allowance",
      kind: "number",
      display: money(employee.transportation_allowance),
    },
    {
      name: "load_allowance",
      label: "Load allowance",
      kind: "number",
      display: money(employee.load_allowance),
    },
  ];

  return (
    <RecordSectionShell
      title="Compensation Information"
      editable={canEdit}
      editing={editing}
      saving={saving}
      onEdit={startEdit}
      onCancel={cancel}
      onSubmit={submit}
    >
      <div className="space-y-8">
        <FieldGrid specs={pay} editing={editing} form={form} />

        <div className="border-t border-border pt-6">
          <p className="mb-1 text-label font-semibold uppercase tracking-wide text-brand">
            Government IDs
          </p>
          <p className="mb-4 text-caption text-muted-foreground">
            Membership numbers printed on the payslip — not the peso amounts above.
          </p>
          <FieldGrid
            specs={[
              { name: "tin", label: "TIN", placeholder: "123-456-789-000" },
              { name: "sss_number", label: "SSS number", placeholder: "34-5678901-2" },
              { name: "philhealth_number", label: "PhilHealth number", placeholder: "12-009876543-1" },
              { name: "pagibig_number", label: "Pag-IBIG number", placeholder: "1211-0098-7654" },
            ]}
            editing={editing}
            form={form}
            columns={4}
          />
        </div>

        {editing ? (
          <label className="flex items-start gap-2.5 rounded-lg border border-input p-3">
            <input
              type="checkbox"
              className="mt-0.5 size-4 shrink-0 rounded border-input accent-brand"
              {...form.register("is_management")}
            />
            <span className="text-sm">
              <span className="font-medium text-foreground">
                Management (fixed salary · exempt from attendance)
              </span>
              <span className="mt-0.5 block text-caption text-muted-foreground">
                Pay is not affected by absences, lates, overtime, or holiday premium, and no
                clock-in is required.
              </span>
            </span>
          </label>
        ) : (
          employee.is_management && (
            <p className="rounded-lg border border-border bg-muted/40 p-3 text-caption text-muted-foreground">
              <span className="font-medium text-foreground">Management</span> — fixed salary,
              exempt from attendance.
            </p>
          )
        )}

        {/* Why the pay moved — recorded on the compensation-history timeline. */}
        {salaryChanged && (
          <div className="rounded-card border border-border bg-muted/40 p-4">
            <p className="mb-3 text-sm font-semibold text-foreground">
              You changed the base salary — log the reason
            </p>
            <FieldGrid
              specs={[
                {
                  name: "salary_change_type",
                  label: "Salary change type",
                  kind: "select",
                  clearable: true,
                  options: SALARY_CHANGE_TYPES.map((t) => ({ value: t.value, label: t.label })),
                  hint: "Leave as None to log a generic adjustment.",
                },
                {
                  name: "salary_change_reason",
                  label: "Reason for change",
                  placeholder: "e.g. Annual increase",
                },
              ]}
              editing={editing}
              form={form}
            />
          </div>
        )}

        {children}
      </div>
    </RecordSectionShell>
  );
}

// Format a raw decimal string as peso, or a dash when empty.
function money(v: string | null): string | null {
  return v ? formatPeso(v) : null;
}
