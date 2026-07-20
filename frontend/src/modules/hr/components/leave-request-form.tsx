"use client";

import { useForm } from "react-hook-form";
import { zodResolver } from "@hookform/resolvers/zod";
import { z } from "zod";
import { AxiosError } from "axios";
import { notify as toast } from "@/core/lib/notify";
import { Info, AlertTriangle } from "lucide-react";

import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { cn } from "@/lib/utils";
import { useCreateLeaveRequest } from "@/modules/hr/hooks/use-leave";
import { computeLeaveDays } from "@/modules/hr/lib/leave-days";
import type { LeaveBalance, LeaveType } from "@/modules/hr/types/leave";

const schema = z.object({
  leave_type_id: z.string().min(1, "Select a leave type"),
  start_date: z.string().min(1, "Start date is required"),
  end_date: z.string().min(1, "End date is required"),
  reason: z.string().min(1, "Reason is required"),
});

type FormValues = z.infer<typeof schema>;

const selectClass =
  "h-8 w-full rounded-lg border border-input bg-transparent px-2.5 text-sm outline-none focus-visible:border-ring focus-visible:ring-3 focus-visible:ring-ring/50";

// Local (browser-tz) YYYY-MM-DD. en-CA formats as ISO. The backend re-checks in
// Asia/Manila — this is just the picker's guardrail.
const todayISO = () => new Date().toLocaleDateString("en-CA");
const isoDaysAgo = (n: number) => {
  const d = new Date();
  d.setDate(d.getDate() - n);
  return d.toLocaleDateString("en-CA");
};

/** Earliest date this leave type may start, mirroring the backend backdating gate. */
function earliestStart(type: LeaveType | undefined): string | undefined {
  if (!type) return undefined;
  if (!type.allow_backdated) return todayISO(); // no past dates
  if (type.backdate_limit_days != null) return isoDaysAgo(type.backdate_limit_days);
  return undefined; // backdating allowed with no limit
}

export function LeaveRequestForm({
  types,
  balances,
}: {
  types: LeaveType[];
  balances: LeaveBalance[];
}) {
  const createMut = useCreateLeaveRequest();

  const {
    register,
    handleSubmit,
    watch,
    setError,
    reset,
    formState: { errors, isSubmitting },
  } = useForm<FormValues>({
    resolver: zodResolver(schema),
    defaultValues: {
      leave_type_id: "",
      start_date: "",
      end_date: "",
      reason: "",
    },
  });

  const [typeId, start, end] = watch(["leave_type_id", "start_date", "end_date"]);

  // Live balance preview (mirrors the server; the 422 is still the real gate).
  const requested = computeLeaveDays(start, end, false);
  const selectedType = types.find((t) => t.id === Number(typeId));
  // Unpaid leave (LWOP) has no credit pool — it's deducted from pay, not a balance.
  const isPaidType = selectedType?.is_paid ?? true;
  const balance = balances.find((b) => b.leave_type.id === Number(typeId));
  const remaining = balance?.remaining_days ?? 0;
  const willRemain = remaining - requested;
  const overBalance = isPaidType && !!balance && requested > remaining;
  const minDate = earliestStart(selectedType);
  const canSubmit = !!typeId && requested > 0 && !overBalance;

  async function onSubmit(v: FormValues) {
    try {
      await createMut.mutateAsync({
        leave_type_id: Number(v.leave_type_id),
        start_date: v.start_date,
        end_date: v.end_date,
        reason: v.reason,
      });
      toast.success("Request submitted");
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
      <h2 className="text-h3 font-bold text-foreground">Request Leave</h2>

      <form onSubmit={handleSubmit(onSubmit)} className="mt-4 space-y-4" noValidate>
        <div className="space-y-1.5">
          <Label>Leave Type</Label>
          <select className={selectClass} {...register("leave_type_id")}>
            <option value="">Select…</option>
            {types.map((t) => (
              <option key={t.id} value={t.id}>{t.name}</option>
            ))}
          </select>
          {errors.leave_type_id && (
            <p className="text-xs text-destructive">{errors.leave_type_id.message}</p>
          )}
        </div>

        <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
          <div className="space-y-1.5">
            <Label>Start Date</Label>
            <Input type="date" min={minDate} {...register("start_date")} />
            {errors.start_date && (
              <p className="text-xs text-destructive">{errors.start_date.message}</p>
            )}
          </div>
          <div className="space-y-1.5">
            <Label>End Date</Label>
            <Input type="date" min={minDate} {...register("end_date")} />
            {errors.end_date && (
              <p className="text-xs text-destructive">{errors.end_date.message}</p>
            )}
          </div>
        </div>

        {/* Backdating rule for the selected type. */}
        {selectedType && (
          <p className="text-caption text-muted-foreground">
            {!selectedType.allow_backdated
              ? "This leave must be filed for today or a future date."
              : selectedType.backdate_limit_days != null
                ? `Backdated filing allowed up to ${selectedType.backdate_limit_days} day(s) back (on or after ${minDate}).`
                : "Backdated filing allowed for any past date."}
          </p>
        )}

        <p className="text-caption text-muted-foreground">
          Leave is booked in full days. Need only part of a day? Use{" "}
          <span className="font-medium text-foreground">Request Half-Day</span> below
          (unpaid).
        </p>

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

        {/* Live balance banner */}
        {typeId && requested > 0 && (
          <div
            className={cn(
              "flex items-center gap-2 rounded-lg px-3 py-2 text-sm",
              overBalance
                ? "bg-danger-subtle text-danger"
                : !isPaidType
                  ? "bg-warning-subtle text-warning"
                  : "bg-brand-subtle text-brand-foreground",
            )}
          >
            {!isPaidType ? (
              <>
                <Info className="size-4 shrink-0" />
                Unpaid leave · {requested} day(s) will be deducted from your pay
              </>
            ) : overBalance ? (
              <>
                <AlertTriangle className="size-4 shrink-0" />
                {requested} day(s) requested · exceeds your {remaining} remaining
              </>
            ) : (
              <>
                <Info className="size-4 shrink-0" />
                {requested} day(s) requested · {willRemain} day(s) will remain
              </>
            )}
          </div>
        )}

        <Button type="submit" className="h-10 w-full" disabled={!canSubmit || isSubmitting}>
          {isSubmitting ? "Submitting…" : "Submit Request"}
        </Button>
        <p className="text-center text-caption text-muted-foreground">
          Your manager will be notified of this request immediately.
        </p>
      </form>
    </div>
  );
}
