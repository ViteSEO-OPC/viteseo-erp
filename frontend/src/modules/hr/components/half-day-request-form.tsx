"use client";

import { useForm } from "react-hook-form";
import { zodResolver } from "@hookform/resolvers/zod";
import { z } from "zod";
import { AxiosError } from "axios";
import { notify as toast } from "@/core/lib/notify";
import { Info } from "lucide-react";

import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { useCreateHalfDayRequest } from "@/modules/hr/hooks/use-half-day";

const schema = z.object({
  work_date: z.string().min(1, "Date is required"),
  period: z.enum(["AM", "PM"]),
  reason: z.string().min(1, "Reason is required"),
});

type FormValues = z.infer<typeof schema>;

const selectClass =
  "h-8 w-full rounded-lg border border-input bg-transparent px-2.5 text-sm outline-none focus-visible:border-ring focus-visible:ring-3 focus-visible:ring-ring/50";

export function HalfDayRequestForm() {
  const createMut = useCreateHalfDayRequest();

  const {
    register,
    handleSubmit,
    reset,
    setError,
    formState: { errors, isSubmitting },
  } = useForm<FormValues>({
    resolver: zodResolver(schema),
    defaultValues: { work_date: "", period: "AM", reason: "" },
  });

  async function onSubmit(v: FormValues) {
    try {
      await createMut.mutateAsync(v);
      toast.success("Half-day request submitted", "HR will review it.");
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
    <div className="rounded-card border border-border bg-card p-6">
      <h2 className="text-h3 font-bold text-foreground">Request Half-Day</h2>
      <p className="mt-1 text-caption text-muted-foreground">
        A half-day off for part of a day. Half-days are unpaid — the half is deducted
        from your pay once HR approves.
      </p>

      <form onSubmit={handleSubmit(onSubmit)} className="mt-4 space-y-4" noValidate>
        <div className="grid grid-cols-2 gap-3">
          <div className="space-y-1.5">
            <Label>Date</Label>
            <Input type="date" {...register("work_date")} />
            {errors.work_date && (
              <p className="text-xs text-destructive">{errors.work_date.message}</p>
            )}
          </div>
          <div className="space-y-1.5">
            <Label>Which half</Label>
            <select className={selectClass} {...register("period")}>
              <option value="AM">Morning (AM)</option>
              <option value="PM">Afternoon (PM)</option>
            </select>
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

        <div className="flex items-center gap-2 rounded-lg bg-warning-subtle px-3 py-2 text-sm text-warning">
          <Info className="size-4 shrink-0" />
          Half a day (0.5) will be deducted from your pay once approved.
        </div>

        <Button type="submit" className="h-10 w-full" disabled={isSubmitting}>
          {isSubmitting ? "Submitting…" : "Submit Half-Day Request"}
        </Button>
      </form>
    </div>
  );
}
