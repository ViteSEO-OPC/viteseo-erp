"use client";

import { useState } from "react";
import { zodResolver } from "@hookform/resolvers/zod";
import { z } from "zod";

import { Button } from "@/components/ui/button";
import {
  Dialog,
  DialogContent,
  DialogHeader,
  DialogTitle,
  DialogDescription,
  DialogFooter,
} from "@/components/ui/dialog";
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
import { StatutoryOverridesBlock } from "@/modules/hr/components/statutory-overrides-block";
import { ContributionEnrolmentBlock } from "@/modules/hr/components/contribution-enrolment-block";
import { EmployeeAllowancesBlock } from "@/modules/hr/components/employee-allowances-block";
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
  tin: optionalText(32),
  sss_number: optionalText(32),
  philhealth_number: optionalText(32),
  pagibig_number: optionalText(32),
  is_management: z.boolean(),
  // Only consulted when the base salary actually changes.
  salary_change_type: z.string(),
  salary_change_reason: optionalText(),
  // FIN-007 — the date the new salary actually takes effect. Blank means today, which is what
  // every salary change used to be stamped with whether or not that was true.
  salary_effective_date: z.string(),
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
    tin: employee.tin ?? "",
    sss_number: employee.sss_number ?? "",
    philhealth_number: employee.philhealth_number ?? "",
    pagibig_number: employee.pagibig_number ?? "",
    is_management: employee.is_management ?? false,
    salary_change_type: "",
    salary_change_reason: "",
    salary_effective_date: "",
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
    tin: orNull(v.tin),
    sss_number: orNull(v.sss_number),
    philhealth_number: orNull(v.philhealth_number),
    pagibig_number: orNull(v.pagibig_number),
    is_management: v.is_management,
    salary_change_type: orNull(v.salary_change_type),
    salary_change_reason: orNull(v.salary_change_reason),
    salary_effective_date: orNull(v.salary_effective_date),
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
  /**
   * FIN-007 — backdating a salary over a completed payroll run is refused until HR confirms, and
   * the message names the affected periods. That refusal is a QUESTION, so it becomes a dialog
   * rather than a red field the user cannot get past.
   */
  const [backdateWarning, setBackdateWarning] = useState<{
    message: string;
    retry: (extra: Record<string, unknown>) => Promise<void>;
  } | null>(null);

  const { form, editing, saving, startEdit, cancel, submit } = useRecordSection<Values>({
    employee,
    resolver: zodResolver(schema),
    toValues,
    toPayload,
    interceptError: (errors, retry) => {
      const backdated = errors.salary_effective_date?.[0];
      if (!backdated) return false;

      setBackdateWarning({ message: backdated, retry });
      return true;
    },
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
    // Slice B7 — SSS / PhilHealth / Pag-IBIG moved OUT of this grid into their own block below.
    // Sitting here they read as three ordinary peso boxes beside the bank details, with nothing
    // to say that filling one switches off the government rates for that employee.
    //
    // Slice B9 — the food / transportation / load boxes that used to close this list are gone.
    // Payroll stopped reading them in B1, so raising someone's food allowance here changed
    // nothing on their payslip. Allowances are programmes in Company → Benefits, and the
    // catalogue is not three of anything — it grows and shrinks as HR adds and deletes them.
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

        {/* FIN-030 — the derived daily rate against the regional floor.
            Advisory, never blocking: the floor varies by region, industry and establishment size,
            and this system does not know which apply. It is shown at all because the daily rate is
            DERIVED (salary ÷ the policy's basis), so nobody typing a monthly figure can see what it
            implies per day — which is the actual gap, not that HR would knowingly underpay. */}
        {employee.minimum_wage_check?.below && (
          <div className="rounded-card border border-warning/40 bg-warning/10 p-3">
            <p className="text-sm font-medium text-foreground">
              This salary works out to {formatPeso(employee.minimum_wage_check.daily_rate)} a day
            </p>
            <p className="mt-0.5 text-caption text-muted-foreground">
              Below the {formatPeso(employee.minimum_wage_check.minimum)} regional minimum daily wage
              configured in Payroll settings. Saving is allowed — part-time and exempt arrangements
              legitimately fall below it — but check the applicable wage order.
            </p>
          </div>
        )}

        {/* Slice B14 — what replaces the three fixed allowance boxes B9 removed. Rendered from
            the catalogue, so it follows however many allowances the company has. */}
        <EmployeeAllowancesBlock employeeId={employee.id} />

        <StatutoryOverridesBlock
          employeeId={employee.id}
          employee={{
            sss_contribution: employee.sss_contribution,
            philhealth_contribution: employee.philhealth_contribution,
            pagibig_contribution: employee.pagibig_contribution,
          }}
          editing={editing}
          form={form}
        />

        {/* Slice B9 item 3 — who pays into what. Sits under the overrides on purpose: this is
            the honest way to express "this person is exempt", and the block above is the one
            that cannot express it without breaking the remittance. */}
        <ContributionEnrolmentBlock employeeId={employee.id} />

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
                {
                  // FIN-007 — a raise agreed for 1 August but entered on 5 September used to
                  // price August at the OLD salary, permanently. The date is now recorded as it
                  // really is; leaving it blank keeps the previous behaviour (effective today).
                  name: "salary_effective_date",
                  label: "Effective from",
                  kind: "date",
                  hint: "Leave blank for today. A past date may affect payroll already run.",
                },
              ]}
              editing={editing}
              form={form}
            />
          </div>
        )}

        {children}
      </div>

      {/* FIN-007 — backdating is allowed, but never silently. */}
      <Dialog open={backdateWarning !== null} onOpenChange={(o) => !o && setBackdateWarning(null)}>
        <DialogContent className="max-w-lg">
          <DialogHeader>
            <DialogTitle>This date falls inside finished payroll</DialogTitle>
            <DialogDescription>{backdateWarning?.message}</DialogDescription>
          </DialogHeader>
          <p className="rounded-md bg-muted px-3 py-2 text-caption text-muted-foreground">
            Those periods were paid at the old salary. Recording the raise is fine — but the
            difference is not paid automatically. Settle it with an adjustment run in Payroll.
          </p>
          <DialogFooter>
            <Button variant="outline" onClick={() => setBackdateWarning(null)}>
              Pick another date
            </Button>
            <Button
              disabled={saving}
              onClick={async () => {
                const pending = backdateWarning;
                setBackdateWarning(null);
                await pending?.retry({ acknowledge_repricing: true });
              }}
            >
              Record it anyway
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </RecordSectionShell>
  );
}

// Format a raw decimal string as peso, or a dash when empty.
function money(v: string | null): string | null {
  return v ? formatPeso(v) : null;
}
