"use client";

import { type UseFormReturn } from "react-hook-form";
import { zodResolver } from "@hookform/resolvers/zod";
import { z } from "zod";

import { formatDate } from "@/core/lib/format";
import {
  Detail,
  FieldGrid,
  RecordSectionShell,
  Section,
  type FieldSpec,
} from "@/modules/hr/components/record-fields";
import { MAX_SIBLINGS, SiblingsFields } from "@/modules/hr/components/siblings-fields";
import {
  emailField,
  numberOrNull,
  optionalChoice,
  optionalCount,
  optionalPastDate,
  optionalPhone,
  optionalText,
  orNull,
  requiredText,
} from "@/modules/hr/lib/employee-validation";
import { useRecordSection } from "@/modules/hr/lib/use-record-section";
import { manilaToday, manilaYear } from "@/modules/hr/lib/manila-date";
import {
  CIVIL_STATUSES,
  GENDERS,
  type Employee,
  type EmployeeUpdateInput,
} from "@/modules/hr/types/employee";

const schema = z
  .object({
    last_name: requiredText("Last name"),
    first_name: requiredText("First name"),
    middle_name: optionalText(),
    name_extension: optionalText(50),
    phone: optionalPhone(),
    email: emailField,
    gender: optionalChoice(GENDERS, "Gender"),
    civil_status: optionalChoice(CIVIL_STATUSES, "Civil status"),
    nationality: optionalText(100),
    date_of_birth: optionalPastDate("Date of birth"),
    place_of_birth: optionalText(),
    address: optionalText(500),
    current_address: optionalText(500),
    mothers_maiden_name: optionalText(),
    fathers_name: optionalText(),
    siblings: z
      .array(z.object({ name: requiredText("Sibling's name"), gender: optionalChoice(GENDERS, "Gender") }))
      .max(MAX_SIBLINGS, `You can name at most ${MAX_SIBLINGS} siblings`),
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

type Values = z.infer<typeof schema>;

function toValues(employee: Employee): Values {
  return {
    last_name: employee.last_name ?? "",
    first_name: employee.first_name ?? "",
    middle_name: employee.middle_name ?? "",
    name_extension: employee.name_extension ?? "",
    phone: employee.phone ?? "",
    email: employee.email,
    gender: employee.gender ?? "",
    civil_status: employee.civil_status ?? "",
    nationality: employee.nationality ?? "",
    date_of_birth: employee.date_of_birth ?? "",
    place_of_birth: employee.place_of_birth ?? "",
    address: employee.address ?? "",
    current_address: employee.current_address ?? "",
    mothers_maiden_name: employee.mothers_maiden_name ?? "",
    fathers_name: employee.fathers_name ?? "",
    siblings: (employee.siblings ?? []).map((s) => ({ name: s.name, gender: s.gender ?? "" })),
    number_of_siblings:
      employee.number_of_siblings == null ? "" : String(employee.number_of_siblings),
    high_school: employee.high_school ?? "",
    college_course: employee.college_course ?? "",
    university: employee.university ?? "",
    emergency_contact_name: employee.emergency_contact_name ?? "",
    emergency_contact_relationship: employee.emergency_contact_relationship ?? "",
    emergency_contact_phone: employee.emergency_contact_phone ?? "",
    height: employee.height ?? "",
    weight: employee.weight ?? "",
    health_condition: employee.health_condition ?? "",
  };
}

function toPayload(v: Values): EmployeeUpdateInput {
  return {
    first_name: v.first_name,
    last_name: v.last_name,
    middle_name: orNull(v.middle_name),
    name_extension: orNull(v.name_extension),
    email: v.email,
    phone: orNull(v.phone),
    gender: orNull(v.gender),
    civil_status: orNull(v.civil_status),
    nationality: orNull(v.nationality),
    date_of_birth: orNull(v.date_of_birth),
    place_of_birth: orNull(v.place_of_birth),
    address: orNull(v.address),
    current_address: orNull(v.current_address),
    mothers_maiden_name: orNull(v.mothers_maiden_name),
    fathers_name: orNull(v.fathers_name),
    siblings: v.siblings
      .filter((s) => s.name.trim() !== "")
      .map((s) => ({ name: s.name.trim(), gender: s.gender || null })),
    number_of_siblings: numberOrNull(v.number_of_siblings),
    high_school: orNull(v.high_school),
    college_course: orNull(v.college_course),
    university: orNull(v.university),
    emergency_contact_name: orNull(v.emergency_contact_name),
    emergency_contact_relationship: orNull(v.emergency_contact_relationship),
    emergency_contact_phone: orNull(v.emergency_contact_phone),
    height: orNull(v.height),
    weight: orNull(v.weight),
    health_condition: orNull(v.health_condition),
  };
}

export function EmployeePersonalSection({
  employee,
  canEdit,
  headerExtra,
}: {
  employee: Employee;
  canEdit: boolean;
  headerExtra?: React.ReactNode;
}) {
  const { form, editing, saving, startEdit, cancel, submit } = useRecordSection<Values>({
    employee,
    resolver: zodResolver(schema),
    toValues,
    toPayload,
  });

  const identity: FieldSpec<Values>[] = [
    { name: "last_name", label: "Last name", placeholder: "Dela Cruz" },
    { name: "first_name", label: "First name", placeholder: "Juan" },
    { name: "middle_name", label: "Middle name" },
    { name: "name_extension", label: "Name extension", placeholder: "Jr., III" },
    { name: "phone", label: "Contact number", placeholder: "+63 917 000 0000" },
    { name: "email", label: "Email address", kind: "email", placeholder: "juan@viteseo.ph" },
    { name: "gender", label: "Gender", kind: "select", options: choices(GENDERS), clearable: true },
    {
      name: "civil_status",
      label: "Civil status",
      kind: "select",
      options: choices(CIVIL_STATUSES),
      clearable: true,
    },
    { name: "nationality", label: "Nationality", placeholder: "Filipino" },
    {
      name: "date_of_birth",
      label: "Date of birth",
      kind: "date",
      maxDate: manilaToday(),
      // Birthdays reach back decades — open the year dropdown wide enough.
      fromYear: manilaYear() - 80,
      display: employee.date_of_birth ? formatDate(employee.date_of_birth) : null,
    },
    { name: "place_of_birth", label: "Place of birth" },
    { name: "address", label: "Home address", placeholder: "City, Province" },
    { name: "current_address", label: "Current address" },
    { name: "mothers_maiden_name", label: "Mother's maiden name" },
    { name: "fathers_name", label: "Father's name" },
  ];

  return (
    <RecordSectionShell
      title="Personal Information"
      editable={canEdit}
      editing={editing}
      saving={saving}
      onEdit={startEdit}
      onCancel={cancel}
      onSubmit={submit}
      headerExtra={headerExtra}
    >
      <div className="space-y-8">
        <FieldGrid specs={identity} editing={editing} form={form} />

        <SiblingsBlock employee={employee} editing={editing} form={form} />

        <div className="border-t border-border pt-6">
          <p className="mb-4 text-label font-semibold uppercase tracking-wide text-brand">
            Educational Background
          </p>
          <FieldGrid
            specs={[
              { name: "high_school", label: "High school" },
              { name: "college_course", label: "College course" },
              { name: "university", label: "University/College" },
            ]}
            editing={editing}
            form={form}
          />
        </div>

        <div className="border-t border-border pt-6">
          <p className="mb-4 text-label font-semibold uppercase tracking-wide text-brand">
            Emergency Contact
          </p>
          <FieldGrid
            specs={[
              { name: "emergency_contact_name", label: "Full name" },
              {
                name: "emergency_contact_relationship",
                label: "Relationship",
                placeholder: "Father",
              },
              { name: "emergency_contact_phone", label: "Contact number" },
            ]}
            editing={editing}
            form={form}
          />
        </div>

        <div className="border-t border-border pt-6">
          <p className="mb-4 text-label font-semibold uppercase tracking-wide text-brand">Health</p>
          <FieldGrid
            specs={[
              { name: "height", label: "Height", placeholder: "162 cm" },
              { name: "weight", label: "Weight", placeholder: "55 kg" },
              {
                name: "health_condition",
                label: "Health concern/condition",
                placeholder: "None",
              },
            ]}
            editing={editing}
            form={form}
          />
        </div>
      </div>
    </RecordSectionShell>
  );
}

/**
 * Siblings are a list: any number of named entries plus a stated total, since someone
 * may have four siblings but only care to name two.
 */
function SiblingsBlock({
  employee,
  editing,
  form,
}: {
  employee: Employee;
  editing: boolean;
  form: UseFormReturn<Values>;
}) {
  if (!editing) {
    return (
      <Section title="Siblings" divider>
        <Detail
          label="Number of siblings"
          value={employee.number_of_siblings == null ? null : String(employee.number_of_siblings)}
        />
        {employee.siblings.length === 0 ? (
          <Detail label="Names" value={null} />
        ) : (
          employee.siblings.map((s, i) => (
            <Detail
              key={`${s.name}-${i}`}
              label={`Sibling ${i + 1}`}
              value={s.gender ? `${s.name} · ${s.gender}` : s.name}
            />
          ))
        )}
      </Section>
    );
  }

  return (
    <div className="border-t border-border pt-6">
      <p className="mb-4 text-label font-semibold uppercase tracking-wide text-brand">Siblings</p>
      <SiblingsFields
        siblings={form.watch("siblings")}
        onSiblingsChange={(next) => form.setValue("siblings", next, { shouldDirty: true })}
        count={form.watch("number_of_siblings")}
        onCountChange={(next) =>
          form.setValue("number_of_siblings", next, { shouldDirty: true, shouldValidate: true })
        }
        countError={form.formState.errors.number_of_siblings?.message}
        nameErrors={form.watch("siblings").map((_, i) => form.formState.errors.siblings?.[i]?.name?.message)}
      />
    </div>
  );
}

function choices(values: readonly string[]) {
  return values.map((v) => ({ value: v, label: v }));
}
