"use client";

import { useEffect, useState } from "react";
import { useForm } from "react-hook-form";
import { zodResolver } from "@hookform/resolvers/zod";
import { z } from "zod";
import { AxiosError } from "axios";
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
import { cn } from "@/lib/utils";
import { useDepartments } from "@/modules/hr/hooks/use-departments";
import {
  useCreateEmployee,
  useUpdateEmployee,
  useEmployees,
} from "@/modules/hr/hooks/use-employees";
import {
  useEmployeeSchedule,
  useUpdateEmployeeSchedule,
  useWorkSchedules,
} from "@/modules/hr/hooks/use-rules";
import {
  WEEKDAYS,
  type EmployeeSchedule,
  type EmployeeScheduleInput,
  type WorkSchedule,
} from "@/modules/hr/types/rules";
import {
  EMPLOYMENT_STATUSES,
  GENDERS,
  CIVIL_STATUSES,
  JOB_TYPES,
  PAY_FREQUENCIES,
  BANK_ACCOUNT_TYPES,
  SALARY_CHANGE_TYPES,
  statusLabel,
  type Employee,
  type EmployeeInput,
} from "@/modules/hr/types/employee";

const ROLE_CHANGE_TYPES = [
  { value: "promotion", label: "Promotion" },
  { value: "transfer", label: "Transfer" },
  { value: "lateral", label: "Lateral move" },
  { value: "correction", label: "Correction (fix title, no new entry)" },
] as const;

export type EmployeeEditSection = "personal" | "job" | "compensation";

const SECTION_LABEL: Record<EmployeeEditSection, string> = {
  personal: "Personal",
  job: "Job",
  compensation: "Compensation",
};

const schema = z.object({
  first_name: z.string().min(1, "First name is required"),
  last_name: z.string().min(1, "Last name is required"),
  middle_name: z.string(),
  name_extension: z.string(),
  email: z.string().email("Enter a valid email"),
  phone: z.string(),
  gender: z.string(),
  civil_status: z.string(),
  nationality: z.string(),
  date_of_birth: z.string(),
  place_of_birth: z.string(),
  address: z.string(),
  current_address: z.string(),
  mothers_maiden_name: z.string(),
  fathers_name: z.string(),
  siblings_name: z.string(),
  high_school: z.string(),
  college_course: z.string(),
  university: z.string(),
  emergency_contact_name: z.string(),
  emergency_contact_relationship: z.string(),
  emergency_contact_phone: z.string(),
  department_id: z.string().min(1, "Select a department"),
  manager_id: z.string(),
  job_title: z.string().min(1, "Job title is required"),
  employment_status: z.string().min(1),
  employment_type: z.string(),
  work_location: z.string(),
  employee_role: z.string(),
  hire_date: z.string().min(1, "Hire date is required"),
  base_salary: z.string().min(1, "Salary is required"),
  is_management: z.boolean(),
  bank_name: z.string(),
  bank_account: z.string(),
  height: z.string(),
  weight: z.string(),
  health_condition: z.string(),
  pay_frequency: z.string(),
  currency: z.string(),
  bank_account_type: z.string(),
  bank_account_name: z.string(),
  sss_contribution: z.string(),
  pagibig_contribution: z.string(),
  philhealth_contribution: z.string(),
  food_allowance: z.string(),
  transportation_allowance: z.string(),
  load_allowance: z.string(),
  salary_change_reason: z.string(),
  salary_change_type: z.string(),
  role_change_type: z.string(),
  role_change_reason: z.string(),
});

type FormValues = z.infer<typeof schema>;

const EMPTY: FormValues = {
  first_name: "", last_name: "", middle_name: "", name_extension: "", email: "",
  phone: "", gender: "", civil_status: "", nationality: "", date_of_birth: "",
  place_of_birth: "", address: "", current_address: "", mothers_maiden_name: "",
  fathers_name: "", siblings_name: "", high_school: "", college_course: "",
  university: "", emergency_contact_name: "", emergency_contact_relationship: "",
  emergency_contact_phone: "", department_id: "", manager_id: "", job_title: "",
  employment_status: "regular", employment_type: "", work_location: "", employee_role: "",
  hire_date: "", base_salary: "", is_management: false, bank_name: "",
  bank_account: "", height: "", weight: "", health_condition: "",
  pay_frequency: "semi_monthly", currency: "PHP", bank_account_type: "",
  bank_account_name: "", sss_contribution: "", pagibig_contribution: "",
  philhealth_contribution: "", food_allowance: "", transportation_allowance: "",
  load_allowance: "",
  salary_change_reason: "", salary_change_type: "", role_change_type: "", role_change_reason: "",
};

const selectClass =
  "h-8 w-full rounded-lg border border-input bg-transparent px-2.5 text-sm outline-none focus-visible:border-ring focus-visible:ring-3 focus-visible:ring-ring/50 aria-invalid:border-destructive";

export function EmployeeFormDialog({
  open,
  onOpenChange,
  employee,
  section = "personal",
}: {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  employee?: Employee | null;
  /** Which tab's fields to edit. Ignored when creating (all sections show). */
  section?: EmployeeEditSection;
}) {
  const isEdit = !!employee;
  const { data: departments } = useDepartments();
  const { data: allEmployees } = useEmployees();
  const createMut = useCreateEmployee();
  const updateMut = useUpdateEmployee();

  // Work schedule is edited inside the Job section and saved via its own endpoint
  // on the same "Save changes" click (no query fires until we have an employee id).
  const { data: scheduleData } = useEmployeeSchedule(employee?.id ?? null);
  const scheduleMut = useUpdateEmployeeSchedule(employee?.id ?? 0);
  const { data: workSchedules } = useWorkSchedules();
  // The schedule sub-form bubbles its current values up here (null = untouched).
  const [schedulePayload, setSchedulePayload] = useState<EmployeeScheduleInput | null>(null);

  const {
    register,
    handleSubmit,
    reset,
    setError,
    formState: { errors, isSubmitting },
  } = useForm<FormValues>({ resolver: zodResolver(schema), defaultValues: EMPTY });

  useEffect(() => {
    if (!open) return;
    reset(
      employee
        ? {
            first_name: employee.first_name ?? "",
            last_name: employee.last_name ?? "",
            middle_name: employee.middle_name ?? "",
            name_extension: employee.name_extension ?? "",
            email: employee.email,
            phone: employee.phone ?? "",
            gender: employee.gender ?? "",
            civil_status: employee.civil_status ?? "",
            nationality: employee.nationality ?? "",
            date_of_birth: employee.date_of_birth ?? "",
            place_of_birth: employee.place_of_birth ?? "",
            address: employee.address ?? "",
            current_address: employee.current_address ?? "",
            mothers_maiden_name: employee.mothers_maiden_name ?? "",
            fathers_name: employee.fathers_name ?? "",
            siblings_name: employee.siblings_name ?? "",
            high_school: employee.high_school ?? "",
            college_course: employee.college_course ?? "",
            university: employee.university ?? "",
            emergency_contact_name: employee.emergency_contact_name ?? "",
            emergency_contact_relationship: employee.emergency_contact_relationship ?? "",
            emergency_contact_phone: employee.emergency_contact_phone ?? "",
            department_id: employee.department ? String(employee.department.id) : "",
            manager_id: employee.manager ? String(employee.manager.id) : "",
            job_title: employee.job_title,
            employment_status: employee.employment_status,
            employment_type: employee.employment_type ?? "",
            work_location: employee.work_location ?? "",
            employee_role: employee.employee_role ?? "",
            hire_date: employee.hire_date,
            base_salary: String(parseFloat(employee.base_salary)),
            is_management: employee.is_management ?? false,
            bank_name: employee.bank_name ?? "",
            bank_account: employee.bank_account ?? "",
            height: employee.height ?? "",
            weight: employee.weight ?? "",
            health_condition: employee.health_condition ?? "",
            pay_frequency: employee.pay_frequency ?? "semi_monthly",
            currency: employee.currency ?? "PHP",
            bank_account_type: employee.bank_account_type ?? "",
            bank_account_name: employee.bank_account_name ?? "",
            sss_contribution: employee.sss_contribution ?? "",
            pagibig_contribution: employee.pagibig_contribution ?? "",
            philhealth_contribution: employee.philhealth_contribution ?? "",
            food_allowance: employee.food_allowance ?? "",
            transportation_allowance: employee.transportation_allowance ?? "",
            load_allowance: employee.load_allowance ?? "",
            // Audit inputs are always blank per edit.
            salary_change_reason: "", salary_change_type: "", role_change_type: "", role_change_reason: "",
          }
        : EMPTY,
    );
  }, [open, employee, reset]);

  async function onSubmit(v: FormValues) {
    const s = (x: string) => x || undefined;
    const input: EmployeeInput = {
      first_name: v.first_name,
      last_name: v.last_name,
      middle_name: s(v.middle_name),
      name_extension: s(v.name_extension),
      email: v.email,
      department_id: Number(v.department_id),
      manager_id: v.manager_id ? Number(v.manager_id) : null,
      job_title: v.job_title,
      employment_status: v.employment_status,
      employment_type: s(v.employment_type),
      work_location: s(v.work_location),
      employee_role: s(v.employee_role),
      hire_date: v.hire_date,
      base_salary: Number(v.base_salary),
      is_management: v.is_management,
      phone: s(v.phone),
      address: s(v.address),
      current_address: s(v.current_address),
      gender: s(v.gender),
      civil_status: s(v.civil_status),
      nationality: s(v.nationality),
      date_of_birth: s(v.date_of_birth),
      place_of_birth: s(v.place_of_birth),
      mothers_maiden_name: s(v.mothers_maiden_name),
      fathers_name: s(v.fathers_name),
      siblings_name: s(v.siblings_name),
      high_school: s(v.high_school),
      college_course: s(v.college_course),
      university: s(v.university),
      emergency_contact_name: s(v.emergency_contact_name),
      emergency_contact_relationship: s(v.emergency_contact_relationship),
      emergency_contact_phone: s(v.emergency_contact_phone),
      bank_name: s(v.bank_name),
      bank_account: s(v.bank_account),
      height: s(v.height),
      weight: s(v.weight),
      health_condition: s(v.health_condition),
      pay_frequency: s(v.pay_frequency),
      currency: s(v.currency),
      bank_account_type: s(v.bank_account_type),
      bank_account_name: s(v.bank_account_name),
      sss_contribution: s(v.sss_contribution),
      pagibig_contribution: s(v.pagibig_contribution),
      philhealth_contribution: s(v.philhealth_contribution),
      food_allowance: s(v.food_allowance),
      transportation_allowance: s(v.transportation_allowance),
      load_allowance: s(v.load_allowance),
      salary_change_reason: s(v.salary_change_reason),
      salary_change_type: s(v.salary_change_type),
      role_change_type: s(v.role_change_type),
      role_change_reason: s(v.role_change_reason),
    };

    try {
      if (employee) {
        await updateMut.mutateAsync({ id: employee.id, input });
        // Schedule is only edited from the Job tab; save it on the same click.
        if (section === "job" && scheduleData) {
          await scheduleMut.mutateAsync(schedulePayload ?? scheduleToInput(scheduleData));
        }
        toast.success("Employee updated");
      } else {
        await createMut.mutateAsync(input);
        toast.success("Employee created — login provisioned");
      }
      onOpenChange(false);
    } catch (err) {
      if (err instanceof AxiosError && err.response?.status === 422) {
        const serverErrors = err.response.data?.errors ?? {};
        for (const field of Object.keys(serverErrors)) {
          setError(field as keyof FormValues, { message: serverErrors[field][0] });
        }
      } else {
        toast.error("Something went wrong. Please try again.");
      }
    }
  }

  // Managers = everyone except the person being edited (no self-management).
  const managerOptions = (allEmployees ?? []).filter((e) => e.id !== employee?.id);

  // When creating, show every section; when editing, only the chosen tab's fields.
  const show = (key: EmployeeEditSection) => !isEdit || section === key;
  const title = isEdit ? `Edit ${SECTION_LABEL[section]} Information` : "Add employee";

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="max-w-2xl">
        <DialogHeader>
          <DialogTitle>{title}</DialogTitle>
          <DialogDescription>
            {isEdit
              ? `Update this employee's ${SECTION_LABEL[section].toLowerCase()} details.`
              : "Creates the employee, their login (password: password), and leave balances."}
          </DialogDescription>
        </DialogHeader>

        <form onSubmit={handleSubmit(onSubmit)} noValidate>
          <div className="max-h-[60vh] space-y-6 overflow-y-auto px-0.5">
            {/* Personal */}
            <div className={cn("grid grid-cols-1 gap-4 sm:grid-cols-2", !show("personal") && "hidden")}>
              <Field label="First name" error={errors.first_name?.message}>
                <Input placeholder="Juan" {...register("first_name")} />
              </Field>
              <Field label="Last name" error={errors.last_name?.message}>
                <Input placeholder="Dela Cruz" {...register("last_name")} />
              </Field>
              <Field label="Middle name">
                <Input {...register("middle_name")} />
              </Field>
              <Field label="Name extension">
                <Input placeholder="Jr., III" {...register("name_extension")} />
              </Field>
              <Field label="Email address" error={errors.email?.message}>
                <Input type="email" placeholder="juan@viteseo.ph" {...register("email")} />
              </Field>
              <Field label="Contact number">
                <Input placeholder="+63 917 000 0000" {...register("phone")} />
              </Field>
              <Field label="Gender">
                <select className={selectClass} {...register("gender")}>
                  <option value="">Select…</option>
                  {GENDERS.map((g) => <option key={g} value={g}>{g}</option>)}
                </select>
              </Field>
              <Field label="Civil status">
                <select className={selectClass} {...register("civil_status")}>
                  <option value="">Select…</option>
                  {CIVIL_STATUSES.map((c) => <option key={c} value={c}>{c}</option>)}
                </select>
              </Field>
              <Field label="Nationality">
                <Input placeholder="Filipino" {...register("nationality")} />
              </Field>
              <Field label="Date of birth">
                <Input type="date" {...register("date_of_birth")} />
              </Field>
              <Field label="Place of birth">
                <Input {...register("place_of_birth")} />
              </Field>
              <Field label="Home address">
                <Input placeholder="City, Province" {...register("address")} />
              </Field>
              <Field label="Current address">
                <Input {...register("current_address")} />
              </Field>
              <Field label="Mother's maiden name">
                <Input {...register("mothers_maiden_name")} />
              </Field>
              <Field label="Father's name">
                <Input {...register("fathers_name")} />
              </Field>
              <Field label="Siblings name">
                <Input {...register("siblings_name")} />
              </Field>
              <Field label="High school">
                <Input {...register("high_school")} />
              </Field>
              <Field label="College course">
                <Input {...register("college_course")} />
              </Field>
              <Field label="University/College">
                <Input {...register("university")} />
              </Field>
              <Field label="Emergency contact name">
                <Input {...register("emergency_contact_name")} />
              </Field>
              <Field label="Emergency contact relationship">
                <Input placeholder="Father" {...register("emergency_contact_relationship")} />
              </Field>
              <Field label="Emergency contact phone">
                <Input {...register("emergency_contact_phone")} />
              </Field>
              <Field label="Height">
                <Input placeholder="162 cm" {...register("height")} />
              </Field>
              <Field label="Weight">
                <Input placeholder="55 kg" {...register("weight")} />
              </Field>
              <Field label="Health concern/condition">
                <Input placeholder="None" {...register("health_condition")} />
              </Field>
            </div>

            {/* Job */}
            <div className={cn("space-y-4", !show("job") && "hidden")}>
              <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <Field label="Job title" error={errors.job_title?.message}>
                  <Input placeholder="Software Engineer" {...register("job_title")} />
                </Field>
                <Field label="Department" error={errors.department_id?.message}>
                  <select className={selectClass} {...register("department_id")}>
                    <option value="">Select…</option>
                    {(departments ?? []).map((d) => (
                      <option key={d.id} value={d.id}>{d.name}</option>
                    ))}
                  </select>
                </Field>
                <Field label="Manager">
                  <select className={selectClass} {...register("manager_id")}>
                    <option value="">None</option>
                    {managerOptions.map((e) => (
                      <option key={e.id} value={e.id}>{e.name}</option>
                    ))}
                  </select>
                </Field>
                <Field label="Employment status">
                  <select className={selectClass} {...register("employment_status")}>
                    {EMPLOYMENT_STATUSES.map((st) => (
                      <option key={st} value={st}>{statusLabel(st)}</option>
                    ))}
                  </select>
                </Field>
                <Field label="Type">
                  <select className={selectClass} {...register("employment_type")}>
                    <option value="">Select…</option>
                    {JOB_TYPES.map((t) => <option key={t} value={t}>{t}</option>)}
                  </select>
                </Field>
                <Field label="Employee role">
                  <Input placeholder="Senior Product Manager" {...register("employee_role")} />
                </Field>
                <Field label="Location">
                  <Input placeholder="Manila HQ · Hybrid" {...register("work_location")} />
                </Field>
                <Field label="Hire date" error={errors.hire_date?.message}>
                  <Input type="date" {...register("hire_date")} />
                </Field>
              </div>

              {/* Role-change log — only when editing an existing employee. */}
              {isEdit && (
                <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                  <Field label="Change type">
                    <select className={selectClass} {...register("role_change_type")}>
                      <option value="">— (logs a generic role change)</option>
                      {ROLE_CHANGE_TYPES.map((t) => (
                        <option key={t.value} value={t.value}>{t.label}</option>
                      ))}
                    </select>
                  </Field>
                  <Field label="Reason for change">
                    <Input placeholder="e.g. Promoted after Q2 review" {...register("role_change_reason")} />
                  </Field>
                  <p className="text-xs text-muted-foreground sm:col-span-2">
                    Only applies if you change the job title — it&apos;s recorded in the employment history.
                  </p>
                </div>
              )}

              {/* Work schedule (was the separate Work Details tab). Re-mounts when
                  the fetched schedule arrives so it initialises from real values. */}
              {isEdit && scheduleData && (
                <ScheduleFields
                  key={employee!.id}
                  initial={scheduleData}
                  workSchedules={workSchedules ?? []}
                  selectClass={selectClass}
                  onChange={setSchedulePayload}
                />
              )}
            </div>

            {/* Compensation */}
            <div className={cn("grid grid-cols-1 gap-4 sm:grid-cols-2", !show("compensation") && "hidden")}>
              <Field label="Base salary (₱ / month)" error={errors.base_salary?.message}>
                <Input type="number" min="0" step="0.01" placeholder="45000" {...register("base_salary")} />
              </Field>
              <Field label="Pay frequency">
                <select className={selectClass} {...register("pay_frequency")}>
                  {PAY_FREQUENCIES.map((f) => (
                    <option key={f.value} value={f.value}>{f.label}</option>
                  ))}
                </select>
              </Field>
              <Field label="Currency">
                <Input placeholder="PHP" {...register("currency")} />
              </Field>
              <Field label="Bank name">
                <Input placeholder="BPI" {...register("bank_name")} />
              </Field>
              <Field label="Bank type">
                <select className={selectClass} {...register("bank_account_type")}>
                  <option value="">Select…</option>
                  {BANK_ACCOUNT_TYPES.map((t) => <option key={t} value={t}>{t}</option>)}
                </select>
              </Field>
              <Field label="Bank account number">
                <Input placeholder="1234-5678-9012-34" {...register("bank_account")} />
              </Field>
              <Field label="Bank account name">
                <Input placeholder="Juan Dela Cruz" {...register("bank_account_name")} />
              </Field>
              <Field label="SSS (₱ / mo)">
                <Input type="number" min="0" step="0.01" {...register("sss_contribution")} />
              </Field>
              <Field label="Pag-IBIG (₱ / mo)">
                <Input type="number" min="0" step="0.01" {...register("pagibig_contribution")} />
              </Field>
              <Field label="PhilHealth (₱ / mo)">
                <Input type="number" min="0" step="0.01" {...register("philhealth_contribution")} />
              </Field>
              <Field label="Food allowance (₱ / mo)">
                <Input type="number" min="0" step="0.01" {...register("food_allowance")} />
              </Field>
              <Field label="Transportation allowance (₱ / mo)">
                <Input type="number" min="0" step="0.01" {...register("transportation_allowance")} />
              </Field>
              <Field label="Load allowance (₱ / mo)">
                <Input type="number" min="0" step="0.01" {...register("load_allowance")} />
              </Field>
              <label className="flex items-start gap-2.5 rounded-lg border border-input p-3 sm:col-span-2">
                <input
                  type="checkbox"
                  className="mt-0.5 size-4 shrink-0 rounded border-input accent-brand"
                  {...register("is_management")}
                />
                <span className="text-sm">
                  <span className="font-medium text-foreground">Management (fixed salary · exempt from attendance)</span>
                  <span className="mt-0.5 block text-caption text-muted-foreground">
                    Pay is not affected by absences, lates, overtime, or holiday premium, and no clock-in is required.
                  </span>
                </span>
              </label>
              {isEdit && (
                <>
                  <Field label="Salary change type">
                    <select className={selectClass} {...register("salary_change_type")}>
                      <option value="">— (generic adjustment)</option>
                      {SALARY_CHANGE_TYPES.map((t) => (
                        <option key={t.value} value={t.value}>{t.label}</option>
                      ))}
                    </select>
                  </Field>
                  <Field label="Reason for salary change">
                    <Input placeholder="e.g. Annual increase" {...register("salary_change_reason")} />
                  </Field>
                  <p className="text-xs text-muted-foreground sm:col-span-2">
                    Type &amp; reason only apply if you change the base salary — recorded in the compensation history.
                  </p>
                </>
              )}
            </div>
          </div>

          <DialogFooter>
            <DialogClose asChild>
              <Button type="button" variant="outline">Cancel</Button>
            </DialogClose>
            <Button type="submit" disabled={isSubmitting}>
              {isSubmitting ? "Saving…" : isEdit ? "Save changes" : "Create employee"}
            </Button>
          </DialogFooter>
        </form>
      </DialogContent>
    </Dialog>
  );
}

function Field({
  label,
  error,
  children,
}: {
  label: string;
  error?: string;
  children: React.ReactNode;
}) {
  return (
    <div className={cn("space-y-1.5")}>
      <Label>{label}</Label>
      {children}
      {error && <p className="text-xs text-destructive">{error}</p>}
    </div>
  );
}

function scheduleToInput(s: EmployeeSchedule): EmployeeScheduleInput {
  return {
    work_schedule_id: s.work_schedule_id ?? null,
    custom_workdays: s.custom_workdays?.length ? s.custom_workdays : null,
    custom_start_time: s.custom_start_time || null,
    custom_end_time: s.custom_end_time || null,
    custom_grace_minutes: s.custom_grace_minutes ?? null,
  };
}

// Self-contained schedule editor: initialises from `initial` (via a remount key in
// the parent) and reports its current values up through `onChange` — no state-sync
// effect, so the parent stays simple.
function ScheduleFields({
  initial,
  workSchedules,
  selectClass,
  onChange,
}: {
  initial: EmployeeSchedule;
  workSchedules: WorkSchedule[];
  selectClass: string;
  onChange: (p: EmployeeScheduleInput) => void;
}) {
  const [schedId, setSchedId] = useState(
    initial.work_schedule_id != null ? String(initial.work_schedule_id) : "",
  );
  const [workdays, setWorkdays] = useState<number[]>(initial.custom_workdays ?? []);
  const [ovStart, setOvStart] = useState(initial.custom_start_time ?? "");
  const [ovEnd, setOvEnd] = useState(initial.custom_end_time ?? "");
  const [ovGrace, setOvGrace] = useState(
    initial.custom_grace_minutes != null ? String(initial.custom_grace_minutes) : "",
  );

  // Report current values up (onChange only mutates a ref — no re-render cascade).
  useEffect(() => {
    onChange({
      work_schedule_id: schedId ? Number(schedId) : null,
      custom_workdays: workdays.length ? workdays : null,
      custom_start_time: ovStart || null,
      custom_end_time: ovEnd || null,
      custom_grace_minutes: ovGrace !== "" ? Number(ovGrace) : null,
    });
  }, [schedId, workdays, ovStart, ovEnd, ovGrace, onChange]);

  function toggleDay(day: number) {
    setWorkdays((prev) => (prev.includes(day) ? prev.filter((d) => d !== day) : [...prev, day]));
  }

  return (
    <div className="space-y-3 rounded-lg border border-border p-3">
      <p className="text-label font-semibold uppercase tracking-wide text-muted-foreground">
        Work schedule
      </p>
      <div className="space-y-1.5">
        <Label>Assigned schedule</Label>
        <select className={selectClass} value={schedId} onChange={(e) => setSchedId(e.target.value)}>
          <option value="">Default schedule</option>
          {workSchedules.map((ws) => (
            <option key={ws.id} value={ws.id}>{ws.name}</option>
          ))}
        </select>
      </div>
      <div className="space-y-1.5">
        <Label>Override workdays (optional)</Label>
        <div className="flex flex-wrap gap-1.5">
          {WEEKDAYS.map((d) => {
            const on = workdays.includes(d.value);
            return (
              <button
                key={d.value}
                type="button"
                onClick={() => toggleDay(d.value)}
                className={cn(
                  "rounded-lg border px-2.5 py-1 text-sm font-medium transition-colors",
                  on
                    ? "border-brand bg-brand text-brand-foreground"
                    : "border-input text-muted-foreground hover:bg-muted",
                )}
              >
                {d.label}
              </button>
            );
          })}
        </div>
        <p className="text-xs text-muted-foreground">
          Leave all off to use the assigned schedule&apos;s days.
        </p>
      </div>
      <div className="grid grid-cols-3 gap-3">
        <div className="space-y-1.5">
          <Label>Override start</Label>
          <Input type="time" value={ovStart} onChange={(e) => setOvStart(e.target.value)} />
        </div>
        <div className="space-y-1.5">
          <Label>Override end</Label>
          <Input type="time" value={ovEnd} onChange={(e) => setOvEnd(e.target.value)} />
        </div>
        <div className="space-y-1.5">
          <Label>Override grace</Label>
          <Input type="number" min="0" max="240" value={ovGrace} onChange={(e) => setOvGrace(e.target.value)} />
        </div>
      </div>
    </div>
  );
}
