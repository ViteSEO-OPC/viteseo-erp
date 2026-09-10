"use client";

import { useEffect, useState } from "react";
import { useForm, Controller } from "react-hook-form";
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
  dialogScrollbar,
} from "@/components/ui/dialog";
import { cn } from "@/lib/utils";
import { CredentialsCard } from "@/core/team/team-accounts-manager";
import { useDepartments } from "@/modules/hr/hooks/use-departments";
import { GrantAccessSection } from "@/modules/hr/components/grant-access-section";
import { useCreateEmployee, useEmployees } from "@/modules/hr/hooks/use-employees";
import { SiblingsFields } from "@/modules/hr/components/siblings-fields";
import {
  emailField,
  numberOrNull,
  optionalChoice,
  optionalCount,
  optionalMoney,
  optionalPastDate,
  optionalPhone,
  optionalText,
  requiredChoice,
  requiredDate,
  requiredMoney,
  requiredText,
} from "@/modules/hr/lib/employee-validation";
import {
  EMPLOYMENT_STATUSES,
  GENDERS,
  CIVIL_STATUSES,
  PAY_FREQUENCIES,
  BANK_ACCOUNT_TYPES,
  statusLabel,
  type EmployeeInput,
} from "@/modules/hr/types/employee";
import { SimpleSelect } from "@/modules/hr/ui/select";
import { DatePicker } from "@/modules/hr/ui/date-picker";
import { manilaToday, manilaYear } from "@/modules/hr/lib/manila-date";

const PAY_FREQUENCY_VALUES = PAY_FREQUENCIES.map((f) => f.value);

/**
 * Creating an employee. Editing happens inline on the record itself
 * (see employee-record.tsx), so this dialog only ever adds someone new.
 */
const schema = z
  .object({
    first_name: requiredText("First name"),
    last_name: requiredText("Last name"),
    middle_name: optionalText(),
    name_extension: optionalText(50),
    email: emailField,
    phone: optionalPhone(),
    gender: optionalChoice(GENDERS, "Gender"),
    civil_status: optionalChoice(CIVIL_STATUSES, "Civil status"),
    nationality: optionalText(100),
    date_of_birth: optionalPastDate("Date of birth"),
    place_of_birth: optionalText(),
    address_house_no: optionalText(50),
    address_street: optionalText(150),
    address_barangay: optionalText(150),
    address_city: optionalText(150),
    address_province: optionalText(150),
    address_zip: optionalText(20),
    current_address_house_no: optionalText(50),
    current_address_street: optionalText(150),
    current_address_barangay: optionalText(150),
    current_address_city: optionalText(150),
    current_address_province: optionalText(150),
    current_address_zip: optionalText(20),
    mothers_maiden_name: optionalText(),
    fathers_name: optionalText(),
    siblings: z.array(z.object({ name: requiredText("Sibling's name"), gender: z.string() })),
    number_of_siblings: optionalCount("Number of siblings"),
    high_school: optionalText(),
    college_course: optionalText(),
    university: optionalText(),
    emergency_contact_name: optionalText(),
    emergency_contact_relationship: optionalText(100),
    emergency_contact_phone: optionalPhone("Emergency contact number"),
    height: optionalText(50),
    weight: optionalText(50),
    health_condition: optionalText(500),
    department_id: z.string().min(1, "Select a department"),
    manager_id: z.string(),
    job_title: requiredText("Job title"),
    employment_status: requiredChoice(EMPLOYMENT_STATUSES, "Employment status"),
    work_location: optionalText(),
    employee_role: optionalText(),
    hire_date: requiredDate("Hire date"),
    base_salary: requiredMoney("Base salary"),
    is_management: z.boolean(),
    // Access in the other modules, given as the person is hired. The backend
    // re-checks every value against GrantableRoles, so this stays a plain list.
    grant_roles: z.array(z.string()),
    bank_name: optionalText(),
    bank_account: optionalText(100),
    bank_account_name: optionalText(),
    bank_account_type: optionalChoice(BANK_ACCOUNT_TYPES, "Bank type"),
    pay_frequency: optionalChoice(PAY_FREQUENCY_VALUES, "Pay frequency"),
    currency: z
      .string()
      .trim()
      .refine((v) => v === "" || /^[A-Za-z]{3}$/.test(v), "Use a 3-letter code, e.g. PHP"),
    tin: optionalText(32),
    sss_number: optionalText(32),
    philhealth_number: optionalText(32),
    pagibig_number: optionalText(32),
  })
  // You needn't name every sibling, but the total can't be fewer than the names given.
  .superRefine((v, ctx) => {
    if (v.number_of_siblings === "") return;
    const named = v.siblings.filter((s) => s.name.trim() !== "").length;
    if (Number(v.number_of_siblings) < named) {
      ctx.addIssue({
        code: "custom",
        path: ["number_of_siblings"],
        message:
          named === 1
            ? "One sibling is listed, so this cannot be 0."
            : `${named} siblings are listed, so this cannot be lower than ${named}.`,
      });
    }
  });

type FormValues = z.infer<typeof schema>;

const EMPTY: FormValues = {
  first_name: "", last_name: "", middle_name: "", name_extension: "", email: "",
  phone: "", gender: "", civil_status: "", nationality: "", date_of_birth: "",
  place_of_birth: "", mothers_maiden_name: "",
  address_house_no: "", address_street: "", address_barangay: "", address_city: "", address_province: "", address_zip: "",
  current_address_house_no: "", current_address_street: "", current_address_barangay: "", current_address_city: "", current_address_province: "", current_address_zip: "",
  fathers_name: "", siblings: [], number_of_siblings: "", high_school: "",
  college_course: "", university: "", emergency_contact_name: "",
  emergency_contact_relationship: "", emergency_contact_phone: "",
  department_id: "", manager_id: "", job_title: "",
  employment_status: "regular", work_location: "", employee_role: "",
  hire_date: "", base_salary: "", is_management: false, grant_roles: [], bank_name: "",
  bank_account: "", height: "", weight: "", health_condition: "",
  pay_frequency: "semi_monthly", currency: "PHP", bank_account_type: "",
  bank_account_name: "",
  tin: "", sss_number: "", philhealth_number: "", pagibig_number: "",
};

export function EmployeeFormDialog({
  open,
  onOpenChange,
}: {
  open: boolean;
  onOpenChange: (open: boolean) => void;
}) {
  const { data: departments } = useDepartments();
  const { data: allEmployees } = useEmployees();
  const createMut = useCreateEmployee();

  // The hand-over, set only by a create that came back with a password. It lives
  // in component state and nowhere else: the server keeps only the hash, so once
  // this is dropped the password is gone for good.
  const [handover, setHandover] = useState<{
    account: { name: string; email: string };
    password: string;
  } | null>(null);

  const {
    register,
    control,
    handleSubmit,
    reset,
    setError,
    setValue,
    watch,
    formState: { errors, isSubmitting },
  } = useForm<FormValues>({ resolver: zodResolver(schema), defaultValues: EMPTY });

  useEffect(() => {
    if (open) {
      reset(EMPTY);
      // Cleared on the way IN rather than on the way out, so the card stays put
      // while the dialog animates closed.
      setHandover(null);
    }
  }, [open, reset]);

  async function onSubmit(v: FormValues) {
    // Blank optional fields are omitted; the columns default to empty.
    const s = (x: string) => x.trim() || undefined;
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
      work_location: s(v.work_location),
      employee_role: s(v.employee_role),
      hire_date: v.hire_date,
      base_salary: Number(v.base_salary),
      is_management: v.is_management,
      grant_roles: v.grant_roles.length ? v.grant_roles : undefined,
      phone: s(v.phone),
      address_house_no: s(v.address_house_no),
      address_street: s(v.address_street),
      address_barangay: s(v.address_barangay),
      address_city: s(v.address_city),
      address_province: s(v.address_province),
      address_zip: s(v.address_zip),
      current_address_house_no: s(v.current_address_house_no),
      current_address_street: s(v.current_address_street),
      current_address_barangay: s(v.current_address_barangay),
      current_address_city: s(v.current_address_city),
      current_address_province: s(v.current_address_province),
      current_address_zip: s(v.current_address_zip),
      gender: s(v.gender),
      civil_status: s(v.civil_status),
      nationality: s(v.nationality),
      date_of_birth: s(v.date_of_birth),
      place_of_birth: s(v.place_of_birth),
      mothers_maiden_name: s(v.mothers_maiden_name),
      fathers_name: s(v.fathers_name),
      siblings: v.siblings
        .filter((sibling) => sibling.name.trim() !== "")
        .map((sibling) => ({ name: sibling.name.trim(), gender: sibling.gender || null })),
      number_of_siblings: numberOrNull(v.number_of_siblings),
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
      currency: s(v.currency)?.toUpperCase(),
      bank_account_type: s(v.bank_account_type),
      bank_account_name: s(v.bank_account_name),
      tin: s(v.tin),
      sss_number: s(v.sss_number),
      philhealth_number: s(v.philhealth_number),
      pagibig_number: s(v.pagibig_number),
    };

    try {
      const { employee, temporary_password } = await createMut.mutateAsync(input);
      toast.success("Employee created — login provisioned");
      // No password on the response — an older backend, or a proxy that dropped
      // the key — means there is nothing to hand over, so close as before rather
      // than show a card with a blank where the password should be. The account
      // still exists; a password reset is the way in.
      if (temporary_password) setHandover({ account: employee, password: temporary_password });
      else onOpenChange(false);
    } catch (err) {
      if (err instanceof AxiosError && err.response?.status === 422) {
        const serverErrors = err.response.data?.errors ?? {};
        for (const field of Object.keys(serverErrors)) {
          setError(field as keyof FormValues, { message: serverErrors[field][0] });
        }
        toast.error("Please fix the highlighted fields.");
      } else {
        toast.error("Something went wrong. Please try again.");
      }
    }
  }

  // Employee role options come from the selected department's positions catalog.
  // Falls back to a free-text input when that department has no positions yet.
  const selectedDeptId = watch("department_id");
  const positionOptions =
    (departments ?? []).find((d) => String(d.id) === selectedDeptId)?.positions ?? [];

  // The hand-over takes the dialog over: the form has done its job, and the one
  // thing left is the password nobody can read back. Returned as its own tree
  // rather than wrapped around the form below — same <Dialog> in the same place,
  // so React swaps the body without the dialog closing and reopening.
  if (handover) {
    return (
      <Dialog open={open} onOpenChange={onOpenChange}>
        <DialogContent className="max-w-[calc(100%-3rem)] sm:max-w-lg">
          <DialogHeader>
            <DialogTitle>Employee created</DialogTitle>
            <DialogDescription>
              {handover.account.name} can sign in with these details.
            </DialogDescription>
          </DialogHeader>
          <CredentialsCard
            account={handover.account}
            password={handover.password}
            onDone={() => onOpenChange(false)}
          />
        </DialogContent>
      </Dialog>
    );
  }

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      {/*
       * One scroller, not two. The dialog used to scroll itself (`max-h-[90svh]
       * overflow-y-auto`) around a body that also scrolled at `max-h-[60vh]`, so
       * a tall form showed a scrollbar inside a scrollbar.
       */}
      <DialogContent className="flex max-h-[90svh] max-w-[calc(100%-3rem)] flex-col sm:max-w-lg">
        <DialogHeader className="shrink-0">
          <DialogTitle>Add employee</DialogTitle>
          <DialogDescription>
            Creates the employee, their login and leave balances. The temporary
            password is shown once, right after this, for you to hand over.
          </DialogDescription>
        </DialogHeader>

        <form onSubmit={handleSubmit(onSubmit)} noValidate className="flex min-h-0 flex-1 flex-col">
          <div className={cn("min-h-0 flex-1 space-y-6 overflow-y-auto overflow-x-hidden px-0.5", dialogScrollbar)}>
            {/* Personal */}
            <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
              <Field label="First name" error={errors.first_name?.message}>
                <Input placeholder="Juan" {...register("first_name")} />
              </Field>
              <Field label="Last name" error={errors.last_name?.message}>
                <Input placeholder="Dela Cruz" {...register("last_name")} />
              </Field>
              <Field label="Middle name" error={errors.middle_name?.message}>
                <Input {...register("middle_name")} />
              </Field>
              <Field label="Name extension" error={errors.name_extension?.message}>
                <Input placeholder="Jr., III" {...register("name_extension")} />
              </Field>
              <Field label="Email address" error={errors.email?.message}>
                <Input type="email" placeholder="juan@viteseo.ph" {...register("email")} />
              </Field>
              <Field label="Contact number" error={errors.phone?.message}>
                <Input placeholder="+63 917 000 0000" {...register("phone")} />
              </Field>
              <Field label="Gender" error={errors.gender?.message}>
                <Controller
                  control={control}
                  name="gender"
                  render={({ field }) => (
                    <SimpleSelect
                      value={field.value}
                      onValueChange={field.onChange}
                      placeholder="Select…"
                      options={GENDERS.map((g) => ({ value: g, label: g }))}
                    />
                  )}
                />
              </Field>
              <Field label="Civil status" error={errors.civil_status?.message}>
                <Controller
                  control={control}
                  name="civil_status"
                  render={({ field }) => (
                    <SimpleSelect
                      value={field.value}
                      onValueChange={field.onChange}
                      placeholder="Select…"
                      options={CIVIL_STATUSES.map((c) => ({ value: c, label: c }))}
                    />
                  )}
                />
              </Field>
              <Field label="Nationality" error={errors.nationality?.message}>
                <Input placeholder="Filipino" {...register("nationality")} />
              </Field>
              <Field label="Date of birth" error={errors.date_of_birth?.message}>
                <Controller
                  control={control}
                  name="date_of_birth"
                  render={({ field }) => (
                    <DatePicker
                      value={field.value}
                      onChange={field.onChange}
                      max={manilaToday()}
                      fromYear={manilaYear() - 80}
                      aria-invalid={!!errors.date_of_birth}
                    />
                  )}
                />
              </Field>
              <Field label="Place of birth" error={errors.place_of_birth?.message}>
                <Input {...register("place_of_birth")} />
              </Field>
              <Legend>Home address</Legend>
              <Field label="House / unit no." error={errors.address_house_no?.message}>
                <Input placeholder="42" {...register("address_house_no")} />
              </Field>
              <Field label="Street" error={errors.address_street?.message}>
                <Input placeholder="Jasmine St" {...register("address_street")} />
              </Field>
              <Field label="Barangay" error={errors.address_barangay?.message}>
                <Input placeholder="Brgy. Holy Spirit" {...register("address_barangay")} />
              </Field>
              <Field label="City / municipality" error={errors.address_city?.message}>
                <Input placeholder="Quezon City" {...register("address_city")} />
              </Field>
              <Field label="Province" error={errors.address_province?.message}>
                <Input placeholder="Metro Manila" {...register("address_province")} />
              </Field>
              <Field label="ZIP code" error={errors.address_zip?.message}>
                <Input placeholder="1127" {...register("address_zip")} />
              </Field>
              <Legend>Current address</Legend>
              <Field label="House / unit no." error={errors.current_address_house_no?.message}>
                <Input placeholder="42" {...register("current_address_house_no")} />
              </Field>
              <Field label="Street" error={errors.current_address_street?.message}>
                <Input placeholder="Jasmine St" {...register("current_address_street")} />
              </Field>
              <Field label="Barangay" error={errors.current_address_barangay?.message}>
                <Input placeholder="Brgy. Holy Spirit" {...register("current_address_barangay")} />
              </Field>
              <Field label="City / municipality" error={errors.current_address_city?.message}>
                <Input placeholder="Quezon City" {...register("current_address_city")} />
              </Field>
              <Field label="Province" error={errors.current_address_province?.message}>
                <Input placeholder="Metro Manila" {...register("current_address_province")} />
              </Field>
              <Field label="ZIP code" error={errors.current_address_zip?.message}>
                <Input placeholder="1127" {...register("current_address_zip")} />
              </Field>
              <Field label="Mother's maiden name" error={errors.mothers_maiden_name?.message}>
                <Input {...register("mothers_maiden_name")} />
              </Field>
              <Field label="Father's name" error={errors.fathers_name?.message}>
                <Input {...register("fathers_name")} />
              </Field>

              <div className="border-t border-border pt-4 sm:col-span-2">
                <p className="mb-4 text-label font-semibold uppercase tracking-wide text-brand">
                  Siblings
                </p>
                <SiblingsFields
                  siblings={watch("siblings")}
                  onSiblingsChange={(next) => setValue("siblings", next, { shouldDirty: true })}
                  count={watch("number_of_siblings")}
                  onCountChange={(next) =>
                    setValue("number_of_siblings", next, {
                      shouldDirty: true,
                      shouldValidate: true,
                    })
                  }
                  countError={errors.number_of_siblings?.message}
                  nameErrors={watch("siblings").map((_, i) => errors.siblings?.[i]?.name?.message)}
                />
              </div>

              <Field label="High school" error={errors.high_school?.message}>
                <Input {...register("high_school")} />
              </Field>
              <Field label="College course" error={errors.college_course?.message}>
                <Input {...register("college_course")} />
              </Field>
              <Field label="University/College" error={errors.university?.message}>
                <Input {...register("university")} />
              </Field>
              <Field label="Emergency contact name" error={errors.emergency_contact_name?.message}>
                <Input {...register("emergency_contact_name")} />
              </Field>
              <Field
                label="Emergency contact relationship"
                error={errors.emergency_contact_relationship?.message}
              >
                <Input placeholder="Father" {...register("emergency_contact_relationship")} />
              </Field>
              <Field
                label="Emergency contact phone"
                error={errors.emergency_contact_phone?.message}
              >
                <Input {...register("emergency_contact_phone")} />
              </Field>
              <Field label="Height" error={errors.height?.message}>
                <Input placeholder="162 cm" {...register("height")} />
              </Field>
              <Field label="Weight" error={errors.weight?.message}>
                <Input placeholder="55 kg" {...register("weight")} />
              </Field>
              <Field label="Health concern/condition" error={errors.health_condition?.message}>
                <Input placeholder="None" {...register("health_condition")} />
              </Field>
            </div>

            {/* Job */}
            <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
              <Field label="Job title" error={errors.job_title?.message}>
                <Input placeholder="Software Engineer" {...register("job_title")} />
              </Field>
              <Field label="Department" error={errors.department_id?.message}>
                <Controller
                  control={control}
                  name="department_id"
                  render={({ field }) => (
                    <SimpleSelect
                      value={field.value}
                      onValueChange={field.onChange}
                      placeholder="Select…"
                      aria-invalid={!!errors.department_id}
                      options={(departments ?? []).map((d) => ({
                        value: String(d.id),
                        label: d.name,
                      }))}
                    />
                  )}
                />
              </Field>
              <Field label="Manager">
                <Controller
                  control={control}
                  name="manager_id"
                  render={({ field }) => (
                    <SimpleSelect
                      value={field.value || "none"}
                      onValueChange={(v) => field.onChange(v === "none" ? "" : v)}
                      options={[
                        { value: "none", label: "None" },
                        ...(allEmployees ?? []).map((e) => ({
                          value: String(e.id),
                          label: e.name,
                        })),
                      ]}
                    />
                  )}
                />
              </Field>
              <Field label="Employment status" error={errors.employment_status?.message}>
                <Controller
                  control={control}
                  name="employment_status"
                  render={({ field }) => (
                    <SimpleSelect
                      value={field.value}
                      onValueChange={field.onChange}
                      options={EMPLOYMENT_STATUSES.map((st) => ({
                        value: st,
                        label: statusLabel(st),
                      }))}
                    />
                  )}
                />
              </Field>
              <Field label="Employee role" error={errors.employee_role?.message}>
                {positionOptions.length > 0 ? (
                  <Controller
                    control={control}
                    name="employee_role"
                    render={({ field }) => (
                      <SimpleSelect
                        value={field.value || "none"}
                        onValueChange={(v) => field.onChange(v === "none" ? "" : v)}
                        placeholder="Select…"
                        options={[
                          { value: "none", label: "None" },
                          ...positionOptions.map((p) => ({ value: p.name, label: p.name })),
                        ]}
                      />
                    )}
                  />
                ) : (
                  <Input placeholder="Senior Product Manager" {...register("employee_role")} />
                )}
              </Field>
              <Field label="Location" error={errors.work_location?.message}>
                <Input placeholder="Manila HQ · Hybrid" {...register("work_location")} />
              </Field>
              <Field label="Hire date" error={errors.hire_date?.message}>
                <Controller
                  control={control}
                  name="hire_date"
                  render={({ field }) => (
                    <DatePicker
                      value={field.value}
                      onChange={field.onChange}
                      aria-invalid={!!errors.hire_date}
                    />
                  )}
                />
              </Field>
            </div>

            <Controller
              control={control}
              name="grant_roles"
              render={({ field }) => (
                <GrantAccessSection
                  value={field.value}
                  onChange={field.onChange}
                  employmentStatus={watch("employment_status")}
                />
              )}
            />
            
            {/* Compensation */}
            <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
              <Field label="Base salary (₱ / month)" error={errors.base_salary?.message}>
                <Input type="number" min="0" step="0.01" placeholder="45000" {...register("base_salary")} />
              </Field>
              <Field label="Pay frequency" error={errors.pay_frequency?.message}>
                <Controller
                  control={control}
                  name="pay_frequency"
                  render={({ field }) => (
                    <SimpleSelect
                      value={field.value}
                      onValueChange={field.onChange}
                      options={PAY_FREQUENCIES.map((f) => ({ value: f.value, label: f.label }))}
                    />
                  )}
                />
              </Field>
              <Field label="Currency" error={errors.currency?.message}>
                <Input placeholder="PHP" {...register("currency")} />
              </Field>
              <Field label="Bank name" error={errors.bank_name?.message}>
                <Input placeholder="BPI" {...register("bank_name")} />
              </Field>
              <Field label="Bank type" error={errors.bank_account_type?.message}>
                <Controller
                  control={control}
                  name="bank_account_type"
                  render={({ field }) => (
                    <SimpleSelect
                      value={field.value}
                      onValueChange={field.onChange}
                      placeholder="Select…"
                      options={BANK_ACCOUNT_TYPES.map((t) => ({ value: t, label: t }))}
                    />
                  )}
                />
              </Field>
              <Field label="Bank account number" error={errors.bank_account?.message}>
                <Input placeholder="1234-5678-9012-34" {...register("bank_account")} />
              </Field>
              <Field label="Bank account name" error={errors.bank_account_name?.message}>
                <Input placeholder="Juan Dela Cruz" {...register("bank_account_name")} />
              </Field>
              {/*
               * No per-employee SSS / Pag-IBIG / PhilHealth amounts. They replaced the
               * EMPLOYEE side of the deduction while the employer side kept following the
               * brackets, so one payslip could carry a pair on no government table. Everyone
               * is charged the company rates; un-enrolling is how someone stops paying one.
               */}
              {/*
               * No allowance boxes here. Allowances are programmes in
               * Company → Benefits: the catalogue grows and shrinks, so three fixed fields
               * could never describe it, and payroll stopped reading these columns in B1.
               */}

              {/*
               * Membership numbers, not amounts — these print on the payslip header.
               * Kept apart from the ₱ overrides above so "SSS" can't mean two things.
               */}
              <div className="border-t border-border pt-4 sm:col-span-2">
                <p className="text-label font-semibold uppercase tracking-wide text-brand">
                  Government IDs
                </p>
                <p className="mt-1 text-caption text-muted-foreground">
                  Printed on the payslip. Blank fields show as “—”.
                </p>
              </div>
              <Field label="TIN" error={errors.tin?.message}>
                <Input placeholder="123-456-789-000" {...register("tin")} />
              </Field>
              <Field label="SSS number" error={errors.sss_number?.message}>
                <Input placeholder="34-5678901-2" {...register("sss_number")} />
              </Field>
              <Field label="PhilHealth number" error={errors.philhealth_number?.message}>
                <Input placeholder="12-009876543-1" {...register("philhealth_number")} />
              </Field>
              <Field label="Pag-IBIG number" error={errors.pagibig_number?.message}>
                <Input placeholder="1211-0098-7654" {...register("pagibig_number")} />
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
            </div>
          </div>

          <DialogFooter className="shrink-0">
            <DialogClose asChild>
              <Button type="button" variant="outline">Cancel</Button>
            </DialogClose>
            <Button type="submit" disabled={isSubmitting}>
              {isSubmitting ? "Saving…" : "Create employee"}
            </Button>
          </DialogFooter>
        </form>
      </DialogContent>
    </Dialog>
  );
}

function Legend({ children }: { children: React.ReactNode }) {
  return (
    <p className="pt-2 text-label font-semibold uppercase tracking-wide text-brand sm:col-span-2">
      {children}
    </p>
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
    <div className="space-y-1.5">
      <Label>{label}</Label>
      {children}
      {error && <p className="text-xs text-destructive">{error}</p>}
    </div>
  );
}
