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
}: {
  employee: Employee;
  resolver: Resolver<TValues>;
  toValues: (employee: Employee) => TValues;
  toPayload: (values: TValues) => EmployeeUpdateInput;
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

  const submit = form.handleSubmit(async (values) => {
    try {
      await update.mutateAsync({ id: employee.id, input: toPayload(values) });
      toast.success("Changes saved");
      setEditing(false);
    } catch (err) {
      if (err instanceof AxiosError && err.response?.status === 422) {
        const errors: Record<string, string[]> = err.response.data?.errors ?? {};
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
