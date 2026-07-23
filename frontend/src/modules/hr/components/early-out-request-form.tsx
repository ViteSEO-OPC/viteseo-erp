"use client";

import { useForm, Controller } from "react-hook-form";
import { zodResolver } from "@hookform/resolvers/zod";
import { z } from "zod";
import { AxiosError } from "axios";
import { notify as toast } from "@/core/lib/notify";
import { Info } from "lucide-react";

import { Button } from "@/components/ui/button";
import { Label } from "@/components/ui/label";
import { DatePicker } from "@/modules/hr/ui/date-picker";
import { TimePicker } from "@/modules/hr/ui/time-picker";
import { useCreateEarlyOutRequest } from "@/modules/hr/hooks/use-early-out";

const schema = z.object({
  work_date: z.string().min(1, "Date is required"),
  early_out_time: z.string().regex(/^\d{2}:\d{2}$/, "Time is required"),
  reason: z.string().min(1, "Reason is required"),
});

type FormValues = z.infer<typeof schema>;

const todayISO = () => new Date().toLocaleDateString("en-CA");

/**
 * Early-out (undertime) request: leave before the scheduled end on a workday.
 * Always unpaid — payroll deducts the proportional undertime once HR approves.
 * Renders bare (no card) so the Time Off tabbed request panel can host it.
 */
export function EarlyOutRequestForm() {
  const createMut = useCreateEarlyOutRequest();

  const {
    register,
    control,
    handleSubmit,
    reset,
    setError,
    formState: { errors, isSubmitting },
  } = useForm<FormValues>({
    resolver: zodResolver(schema),
    defaultValues: { work_date: "", early_out_time: "", reason: "" },
  });

  async function onSubmit(v: FormValues) {
    try {
      await createMut.mutateAsync(v);
      toast.success("Early-out request submitted", "HR will review it.");
      reset();
    } catch (err) {
      if (err instanceof AxiosError && err.response?.status === 422) {
        const serverErrors = err.response.data?.errors ?? {};
        for (const field of Object.keys(serverErrors)) {
          setError(field as keyof FormValues, { message: serverErrors[field][0] });
        }
        toast.error(err.response.data?.message ?? "Please fix the form.");
      } else {
        toast.error("Something went wrong. Please try again.");
      }
    }
  }

  return (
    <form onSubmit={handleSubmit(onSubmit)} className="space-y-4" noValidate>
      <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
        <div className="space-y-1.5">
          <Label>Date</Label>
          <Controller
            control={control}
            name="work_date"
            render={({ field }) => (
              <DatePicker
                value={field.value}
                onChange={field.onChange}
                min={todayISO()}
                aria-invalid={!!errors.work_date}
              />
            )}
          />
          {errors.work_date && (
            <p className="text-xs text-destructive">{errors.work_date.message}</p>
          )}
        </div>
        <div className="space-y-1.5">
          <Label>Time</Label>
          <Controller
            control={control}
            name="early_out_time"
            render={({ field }) => (
              <TimePicker
                value={field.value}
                onChange={field.onChange}
                minuteStep={15}
                aria-invalid={!!errors.early_out_time}
              />
            )}
          />
          {errors.early_out_time && (
            <p className="text-xs text-destructive">{errors.early_out_time.message}</p>
          )}
        </div>
      </div>

      <div className="space-y-1.5">
        <Label>Reason</Label>
        <textarea
          rows={2}
          className="w-full rounded-lg border border-input bg-transparent px-2.5 py-1.5 text-sm outline-none focus-visible:border-ring focus-visible:ring-3 focus-visible:ring-ring/50"
          placeholder="Briefly describe your reason"
          {...register("reason")}
        />
        {errors.reason && (
          <p className="text-xs text-destructive">{errors.reason.message}</p>
        )}
      </div>

      <div className="flex items-center gap-2 rounded-lg bg-brand-subtle px-3 py-2 text-sm text-brand-foreground">
        <Info className="size-4 shrink-0" />
        Early out will be deducted from your pay once approved.
      </div>

      <Button type="submit" className="h-10 w-full" disabled={isSubmitting}>
        {isSubmitting ? "Submitting…" : "Submit Request"}
      </Button>
      <p className="text-center text-caption text-muted-foreground">
        Your manager will be notified of this request immediately.
      </p>
    </form>
  );
}
