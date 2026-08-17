"use client";

import { useEffect, useState } from "react";
import {
  useForm,
  type DefaultValues,
  type FieldValues,
  type Path,
  type Resolver,
  type UseFormReturn,
} from "react-hook-form";
import { AxiosError } from "axios";

import { notify as toast } from "@/core/lib/notify";
import { useUpdateEmployee } from "@/modules/hr/hooks/use-employees";
import type { Employee, EmployeeUpdateInput } from "@/modules/hr/types/employee";

/**
 * One editable section of the employee record: read-only until HR clicks Edit, then the
 * same fields become inputs with Save / Cancel. Saving posts ONLY this section's fields
 * (the API applies just the keys it is sent), so two sections can't overwrite each other.
 */
export function useRecordSection<TValues extends FieldValues>({
  employee,
  resolver,
  toValues,
  toPayload,
  interceptError,
}: {
  employee: Employee;
  resolver: Resolver<TValues>;
  toValues: (employee: Employee) => TValues;
  toPayload: (values: TValues) => EmployeeUpdateInput;
  /**
   * Take over a 422 before it is painted onto the fields. Return true when handled.
   *
   * `retry` re-sends the same values with extra keys merged in — the acknowledgement pattern the
   * backend uses for backdated salary changes (FIN-007).
   */
  interceptError?: (
    errors: Record<string, string[]>,
    retry: (extra: Record<string, unknown>) => Promise<void>,
  ) => boolean;
}): {
  form: UseFormReturn<TValues>;
  editing: boolean;
  saving: boolean;
  startEdit: () => void;
  cancel: () => void;
  submit: (event?: React.BaseSyntheticEvent) => Promise<void>;
} {
  const [editing, setEditing] = useState(false);
  const update = useUpdateEmployee();

  const form = useForm<TValues>({
    resolver,
    defaultValues: toValues(employee) as DefaultValues<TValues>,
  });

  // Pick up a fresh record (our own save's response, or another section's) — but never
  // while editing, which would throw away what HR is typing.
  useEffect(() => {
    if (!editing) form.reset(toValues(employee));
    // eslint-disable-next-line react-hooks/exhaustive-deps -- toValues/form identities are stable
  }, [employee, editing]);

  /** One save attempt. `extra` carries anything the payload itself does not, e.g. an acknowledgement. */
  const save = async (values: TValues, extra: Record<string, unknown> = {}) => {
    await update.mutateAsync({
      id: employee.id,
      input: { ...toPayload(values), ...extra } as ReturnType<typeof toPayload>,
    });
    toast.success("Changes saved");
    setEditing(false);
  };

  const submit = form.handleSubmit(async (values) => {
    // A section is read-only until Edit is pressed, so a submit arriving now did not come from
    // the Save button — it is a stray `type="submit"` default from some control nested inside
    // this section's <form> (lifecycle, schedule, loans…). Saving there would PATCH the record
    // and toast "Changes saved" for a click that meant something else entirely.
    if (!editing) return;

    try {
      await save(values);
    } catch (err) {
      if (err instanceof AxiosError && err.response?.status === 422) {
        const errors: Record<string, string[]> = err.response.data?.errors ?? {};

        // FIN-007 — some 422s are QUESTIONS, not failures. Backdating a salary over a completed
        // payroll run is refused until HR confirms, and the message names the affected runs. A
        // section can intercept that, show a confirmation, and retry with the acknowledgement.
        // Without this the refusal is a dead end and the salary simply cannot be saved.
        if (interceptError?.(errors, (extra) => save(values, extra))) {
          return;
        }

        for (const [field, messages] of Object.entries(errors)) {
          form.setError(field as Path<TValues>, { message: messages[0] });
        }
        toast.error("Please fix the highlighted fields.");
      } else {
        toast.error("Could not save the changes. Please try again.");
      }
    }
  });

  return {
    form,
    editing,
    saving: update.isPending || form.formState.isSubmitting,
    startEdit: () => setEditing(true),
    cancel: () => {
      form.reset(toValues(employee));
      setEditing(false);
    },
    submit,
  };
}
