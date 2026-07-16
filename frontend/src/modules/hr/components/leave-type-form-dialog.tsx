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
  days_per_year: z.coerce.number().int("Whole days only").min(0, "Must be 0 or more").max(365),
  is_paid: z.boolean(),
  color: z.string().max(20).optional(),
  allow_backdated: z.boolean(),
  // Kept as the raw text-input value; "" = no limit. Parsed to number | null on submit.
  backdate_limit_days: z
    .union([
      z.coerce.number().int().min(1, "At least 1 day").max(365, "At most 365 days"),
      z.literal(""),
    ])
    .optional(),
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
    watch,
    setValue,
    formState: { errors, isSubmitting },
  } = useForm<FormValues>({
    resolver: zodResolver(schema),
    defaultValues: {
      name: "",
      code: "",
      days_per_year: 0,
      is_paid: true,
      color: "#4FDBD7",
      allow_backdated: false,
      backdate_limit_days: "",
    },
  });

  // Show the day-cap input only when backdated filing is allowed.
  const allowBackdated = watch("allow_backdated");

  // The swatch and the hex text box are two views of ONE field. Both read this
  // value and write it via setValue, so picking a color in the swatch updates
  // the hex box (and vice-versa) — and Save always submits what you see.
  const color = watch("color") ?? "";
  const swatchValue = /^#[0-9a-fA-F]{6}$/.test(color) ? color : "#000000";
  const setColor = (value: string) =>
    setValue("color", value, { shouldValidate: true, shouldDirty: true });

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
              allow_backdated: leaveType.allow_backdated,
              backdate_limit_days: leaveType.backdate_limit_days ?? "",
            }
          : {
              name: "",
              code: "",
              days_per_year: 0,
              is_paid: true,
              color: "#4FDBD7",
              allow_backdated: false,
              backdate_limit_days: "",
            },
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
      allow_backdated: values.allow_backdated,
      // No cap (or backdating off) → null.
      backdate_limit_days:
        values.allow_backdated && values.backdate_limit_days !== "" && values.backdate_limit_days != null
          ? Number(values.backdate_limit_days)
          : null,
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
              <Input id="lt-days" type="number" step="1" min="0" aria-invalid={!!errors.days_per_year} {...register("days_per_year")} />
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
              <input
                id="lt-color"
                type="color"
                className="h-9 w-12 rounded border border-input bg-transparent"
                value={swatchValue}
                onChange={(e) => setColor(e.target.value)}
              />
              <Input
                className="flex-1"
                placeholder="#4FDBD7"
                value={color}
                onChange={(e) => setColor(e.target.value)}
              />
            </div>
          </div>

          <div className="rounded-lg border border-border p-3">
            <label className="flex items-start gap-2 text-sm">
              <input
                type="checkbox"
                className="mt-0.5 size-4 rounded border-input accent-brand"
                {...register("allow_backdated")}
              />
              <span>
                Allow backdated filing
                <span className="block text-xs text-muted-foreground">
                  Let employees file this leave for dates that already passed (e.g. sick
                  leave). When off, only today or future dates are accepted.
                </span>
              </span>
            </label>

            {allowBackdated && (
              <div className="mt-3 space-y-1.5 pl-6">
                <Label htmlFor="lt-backdate">Max days back</Label>
                <Input
                  id="lt-backdate"
                  type="number"
                  step="1"
                  min="1"
                  max="365"
                  placeholder="Leave blank for no limit"
                  aria-invalid={!!errors.backdate_limit_days}
                  {...register("backdate_limit_days")}
                />
                {errors.backdate_limit_days ? (
                  <p className="text-xs text-destructive">{errors.backdate_limit_days.message}</p>
                ) : (
                  <p className="text-xs text-muted-foreground">
                    How far back a request may reach. Blank = no limit.
                  </p>
                )}
              </div>
            )}
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
