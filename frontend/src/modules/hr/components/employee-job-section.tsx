"use client";

import { zodResolver } from "@hookform/resolvers/zod";
import { z } from "zod";

import { formatDate } from "@/core/lib/format";
import { useDepartments } from "@/modules/hr/hooks/use-departments";
import { useEmployees } from "@/modules/hr/hooks/use-employees";
import {
  Detail,
  FieldGrid,
  RecordSectionShell,
  type FieldSpec,
} from "@/modules/hr/components/record-fields";
import {
  optionalText,
  orNull,
  requiredChoice,
  requiredDate,
  requiredText,
} from "@/modules/hr/lib/employee-validation";
import { useRecordSection } from "@/modules/hr/lib/use-record-section";
import {
  EMPLOYMENT_STATUSES,
  statusLabel,
  type Employee,
  type EmployeeUpdateInput,
} from "@/modules/hr/types/employee";

const ROLE_CHANGE_TYPES = [
  { value: "promotion", label: "Promotion" },
  { value: "transfer", label: "Transfer" },
  { value: "lateral", label: "Lateral move" },
  { value: "correction", label: "Correction (fix title, no new entry)" },
] as const;

const schema = z.object({
  job_title: requiredText("Job title"),
  department_id: z.string().min(1, "Department is required"),
  manager_id: z.string(),
  employment_status: requiredChoice(EMPLOYMENT_STATUSES, "Employment status"),
  work_location: optionalText(),
  employee_role: optionalText(),
  hire_date: requiredDate("Start date"),
  // Only consulted when the job title actually changes.
  role_change_type: z.string(),
  role_change_reason: optionalText(),
});

type Values = z.infer<typeof schema>;

function toValues(employee: Employee): Values {
  return {
    job_title: employee.job_title,
    department_id: employee.department ? String(employee.department.id) : "",
    manager_id: employee.manager ? String(employee.manager.id) : "",
    employment_status: employee.employment_status,
    work_location: employee.work_location ?? "",
    employee_role: employee.employee_role ?? "",
    hire_date: employee.hire_date,
    // Audit inputs start blank on every edit.
    role_change_type: "",
    role_change_reason: "",
  };
}

function toPayload(v: Values): EmployeeUpdateInput {
  return {
    job_title: v.job_title,
    department_id: Number(v.department_id),
    manager_id: v.manager_id ? Number(v.manager_id) : null,
    employment_status: v.employment_status,
    work_location: orNull(v.work_location),
    employee_role: orNull(v.employee_role),
    hire_date: v.hire_date,
    role_change_type: orNull(v.role_change_type),
    role_change_reason: orNull(v.role_change_reason),
  };
}

export function EmployeeJobSection({
  employee,
  canEdit,
  children,
}: {
  employee: Employee;
  canEdit: boolean;
  /** Blocks below the field grid (schedule, history, access) — always read/write in place. */
  children?: React.ReactNode;
}) {
  const { form, editing, saving, startEdit, cancel, submit } = useRecordSection<Values>({
    employee,
    resolver: zodResolver(schema),
    toValues,
    toPayload,
  });

  const { data: departments } = useDepartments();
  const { data: allEmployees } = useEmployees();

  // Employee role options come from the selected department's positions catalog; a
  // department with no positions yet falls back to free text.
  const selectedDept = form.watch("department_id");
  const positions =
    (departments ?? []).find((d) => String(d.id) === selectedDept)?.positions ?? [];

  const titleChanged = editing && form.watch("job_title") !== employee.job_title;

  const specs: FieldSpec<Values>[] = [
    { name: "job_title", label: "Title", placeholder: "Software Engineer" },
    {
      name: "department_id",
      label: "Department",
      kind: "select",
      options: (departments ?? []).map((d) => ({ value: String(d.id), label: d.name })),
      display: employee.department?.name ?? null,
    },
    {
      name: "manager_id",
      label: "Manager",
      kind: "select",
      clearable: true,
      // Nobody can manage themselves.
      options: (allEmployees ?? [])
        .filter((e) => e.id !== employee.id)
        .map((e) => ({ value: String(e.id), label: e.name })),
      display: employee.manager?.name ?? null,
    },
    {
      name: "employment_status",
      label: "Employment status",
      kind: "select",
      options: EMPLOYMENT_STATUSES.map((s) => ({ value: s, label: statusLabel(s) })),
    },
    { name: "work_location", label: "Location", placeholder: "Manila HQ · Hybrid" },
    {
      name: "hire_date",
      label: "Start date",
      kind: "date",
      display: formatDate(employee.hire_date),
    },
    ...(positions.length > 0
      ? ([
          {
            name: "employee_role",
            label: "Employee role",
            kind: "select",
            clearable: true,
            options: positions.map((p) => ({ value: p.name, label: p.name })),
          },
        ] as FieldSpec<Values>[])
      : ([
          {
            name: "employee_role",
            label: "Employee role",
            placeholder: "Senior Product Manager",
          },
        ] as FieldSpec<Values>[])),
  ];

  return (
    <RecordSectionShell
      title="Job Information"
      editable={canEdit}
      editing={editing}
      saving={saving}
      onEdit={startEdit}
      onCancel={cancel}
      onSubmit={submit}
    >
      <div className="space-y-8">
        <FieldGrid specs={specs} editing={editing} form={form} />

        {/* The employee ID is assigned by the system and never edited. */}
        {!editing && (
          <div className="grid grid-cols-1 gap-x-8 gap-y-5 sm:grid-cols-2 lg:grid-cols-3">
            {/*
             * Was a hand-rolled copy of `Detail`; using the real one keeps it on
             * the shared label/value styling and its overflow guards.
             */}
            <Detail label="Employee ID number" value={employee.employee_no} />
          </div>
        )}

        {/* Why the title moved — recorded on the employment-history timeline. */}
        {titleChanged && (
          <div className="rounded-card border border-border bg-muted/40 p-4">
            <p className="mb-3 text-sm font-semibold text-foreground">
              You changed the job title — log the reason
            </p>
            <FieldGrid
              specs={[
                {
                  name: "role_change_type",
                  label: "Change type",
                  kind: "select",
                  clearable: true,
                  options: ROLE_CHANGE_TYPES.map((t) => ({ value: t.value, label: t.label })),
                  hint: "Leave as None to log a generic role change.",
                },
                {
                  name: "role_change_reason",
                  label: "Reason for change",
                  placeholder: "e.g. Promoted after Q2 review",
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
