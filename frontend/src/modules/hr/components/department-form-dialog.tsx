"use client";

import { useEffect } from "react";
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
import {
  useCreateDepartment,
  useUpdateDepartment,
} from "@/modules/hr/hooks/use-departments";
import type { Department } from "@/modules/hr/types/department";

const schema = z.object({
  name: z.string().min(1, "Name is required").max(255),
  code: z.string().min(1, "Code is required").max(50),
  is_active: z.boolean(),
});

type FormValues = z.infer<typeof schema>;

export function DepartmentFormDialog({
  open,
  onOpenChange,
  department,
}: {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  /** Present = edit mode; absent = create mode. */
  department?: Department | null;
}) {
  const isEdit = !!department;
  const createMut = useCreateDepartment();
  const updateMut = useUpdateDepartment();

  const {
    register,
    handleSubmit,
    reset,
    setError,
    formState: { errors, isSubmitting },
  } = useForm<FormValues>({
    resolver: zodResolver(schema),
    defaultValues: { name: "", code: "", is_active: true },
  });

  // Refill the form whenever the dialog opens (with the row's values on edit,
  // or blank defaults on create).
  useEffect(() => {
    if (open) {
      reset(
        department
          ? {
              name: department.name,
              code: department.code,
              is_active: department.is_active,
            }
          : { name: "", code: "", is_active: true },
      );
    }
  }, [open, department, reset]);

  async function onSubmit(values: FormValues) {
    try {
      if (department) {
        await updateMut.mutateAsync({ id: department.id, input: values });
        toast.success("Department updated");
      } else {
        await createMut.mutateAsync(values);
        toast.success("Department created");
      }
      onOpenChange(false);
    } catch (err) {
      // Surface server-side validation (e.g. duplicate code) on the field.
      if (err instanceof AxiosError && err.response?.status === 422) {
        const serverErrors = err.response.data?.errors ?? {};
        for (const field of Object.keys(serverErrors)) {
          setError(field as keyof FormValues, {
            message: serverErrors[field][0],
          });
        }
      } else {
        toast.error("Something went wrong. Please try again.");
      }
    }
  }

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent>
        <DialogHeader>
          <DialogTitle>
            {isEdit ? "Edit department" : "Add department"}
          </DialogTitle>
          <DialogDescription>
            {isEdit
              ? "Update the department details."
              : "Create a new department for assigning employees."}
          </DialogDescription>
        </DialogHeader>

        <form onSubmit={handleSubmit(onSubmit)} className="space-y-4" noValidate>
          <div className="space-y-1.5">
            <Label htmlFor="name">Name</Label>
            <Input
              id="name"
              placeholder="Engineering"
              aria-invalid={!!errors.name}
              {...register("name")}
            />
            {errors.name && (
              <p className="text-xs text-destructive">{errors.name.message}</p>
            )}
          </div>

          <div className="space-y-1.5">
            <Label htmlFor="code">Code</Label>
            <Input
              id="code"
              placeholder="ENG"
              aria-invalid={!!errors.code}
              {...register("code")}
            />
            {errors.code && (
              <p className="text-xs text-destructive">{errors.code.message}</p>
            )}
          </div>

          <div className="flex items-center gap-2">
            <input
              id="is_active"
              type="checkbox"
              className="size-4 rounded border-input accent-brand"
              {...register("is_active")}
            />
            <Label htmlFor="is_active" className="font-normal">
              Active
            </Label>
          </div>

          <DialogFooter>
            <DialogClose asChild>
              <Button type="button" variant="outline">
                Cancel
              </Button>
            </DialogClose>
            <Button type="submit" disabled={isSubmitting}>
              {isSubmitting
                ? "Saving…"
                : isEdit
                  ? "Save changes"
                  : "Create"}
            </Button>
          </DialogFooter>
        </form>
      </DialogContent>
    </Dialog>
  );
}
