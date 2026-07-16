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
  useCreateLeaveType,
  useUpdateLeaveType,
} from "@/modules/hr/hooks/use-leave-config";
import type { LeaveType } from "@/modules/hr/types/leave";

const schema = z.object({
  name: z.string().min(1, "Name is required").max(255),
  code: z.string().min(1, "Code is required").max(50),
  days_per_year: z.coerce.number().min(0, "Must be 0 or more").max(365),
  is_paid: z.boolean(),
  color: z.string().max(20).optional(),
});

type FormValues = z.input<typeof schema>;

export function LeaveTypeFormDialog({
  open,
  onOpenChange,
  leaveType,
}: {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  /** Present = edit mode; absent = create mode. */
  leaveType?: LeaveType | null;
}) {
  const isEdit = !!leaveType;
  const createMut = useCreateLeaveType();
  const updateMut = useUpdateLeaveType();

  const {
    register,
    handleSubmit,
    reset,
    setError,
    formState: { errors, isSubmitting },
  } = useForm<FormValues>({
    resolver: zodResolver(schema),
    defaultValues: { name: "", code: "", days_per_year: 0, is_paid: true, color: "#4FDBD7" },
  });

  useEffect(() => {
    if (open) {
      reset(
        leaveType
          ? {
              name: leaveType.name,
              code: leaveType.code,
              days_per_year: Number(leaveType.days_per_year),
              is_paid: leaveType.is_paid,
              color: leaveType.color ?? "#4FDBD7",
            }
          : { name: "", code: "", days_per_year: 0, is_paid: true, color: "#4FDBD7" },
      );
    }
  }, [open, leaveType, reset]);

  async function onSubmit(values: FormValues) {
    const input = {
      name: values.name,
      code: values.code,
      days_per_year: Number(values.days_per_year),
      is_paid: values.is_paid,
      color: values.color || null,
    };
    try {
      if (leaveType) {
        await updateMut.mutateAsync({ id: leaveType.id, input });
        toast.success("Leave type updated");
      } else {
        await createMut.mutateAsync(input);
        toast.success("Leave type created");
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

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent>
        <DialogHeader>
          <DialogTitle>{isEdit ? "Edit leave type" : "Add leave type"}</DialogTitle>
          <DialogDescription>
            {isEdit
              ? "Update this leave type. The base days are the default before per-status allotments."
              : "Create a leave type. Its base days are the default before per-status allotments."}
          </DialogDescription>
        </DialogHeader>

        <form onSubmit={handleSubmit(onSubmit)} className="space-y-4" noValidate>
          <div className="space-y-1.5">
            <Label htmlFor="lt-name">Name</Label>
            <Input id="lt-name" placeholder="Annual Leave" aria-invalid={!!errors.name} {...register("name")} />
            {errors.name && <p className="text-xs text-destructive">{errors.name.message}</p>}
          </div>

          <div className="grid grid-cols-2 gap-3">
            <div className="space-y-1.5">
              <Label htmlFor="lt-code">Code</Label>
              <Input id="lt-code" placeholder="ANL" aria-invalid={!!errors.code} {...register("code")} />
              {errors.code && <p className="text-xs text-destructive">{errors.code.message}</p>}
            </div>
            <div className="space-y-1.5">
              <Label htmlFor="lt-days">Base days / year</Label>
              <Input id="lt-days" type="number" step="0.5" min="0" aria-invalid={!!errors.days_per_year} {...register("days_per_year")} />
              {errors.days_per_year && <p className="text-xs text-destructive">{errors.days_per_year.message}</p>}
            </div>
          </div>

          <label className="flex items-start gap-2 text-sm">
            <input
              type="checkbox"
              className="mt-0.5 size-4 rounded border-input accent-brand"
              {...register("is_paid")}
            />
            <span>
              Paid leave
              <span className="block text-xs text-muted-foreground">
                Uncheck for unpaid leave (LWOP) — payroll deducts these days at the daily rate.
              </span>
            </span>
          </label>

          <div className="space-y-1.5">
            <Label htmlFor="lt-color">Color</Label>
            <div className="flex items-center gap-2">
              <input id="lt-color" type="color" className="h-9 w-12 rounded border border-input bg-transparent" {...register("color")} />
              <Input className="flex-1" placeholder="#4FDBD7" {...register("color")} />
            </div>
          </div>

          <DialogFooter>
            <DialogClose asChild>
              <Button type="button" variant="outline">Cancel</Button>
            </DialogClose>
            <Button type="submit" disabled={isSubmitting}>
              {isSubmitting ? "Saving…" : isEdit ? "Save changes" : "Create"}
            </Button>
          </DialogFooter>
        </form>
      </DialogContent>
    </Dialog>
  );
}
