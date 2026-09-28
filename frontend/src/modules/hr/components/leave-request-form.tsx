"use client";

import { useForm, useWatch, Controller } from "react-hook-form";
import { zodResolver } from "@hookform/resolvers/zod";
import { z } from "zod";
import { AxiosError } from "axios";
import { notify as toast } from "@/core/lib/notify";
import { Info, AlertTriangle } from "lucide-react";

import { Button } from "@/components/ui/button";
import { Label } from "@/components/ui/label";
import { cn } from "@/lib/utils";
import { SimpleSelect } from "@/modules/hr/ui/select";
import { DatePicker } from "@/modules/hr/ui/date-picker";
import { useCreateLeaveRequest } from "@/modules/hr/hooks/use-leave";
import { computeLeaveDays } from "@/modules/hr/lib/leave-days";
import type {
  AllotmentMode,
  LeaveBalance,
  LeavePool,
  LeaveType,
} from "@/modules/hr/types/leave";

const schema = z.object({
  leave_type_id: z.string().min(1, "Select a leave type"),
  start_date: z.string().min(1, "Start date is required"),
  // Not unconditionally required: a half day is a single day, so the form hides End Date and the
  // refine below only demands it for a range. Without that, toggling half-day left the schema
  // blocking submit on a field nobody could see.
  end_date: z.string(),
  reason: z.string().min(1, "Reason is required"),
  // Half a day OF THIS LEAVE TYPE: 0.5 off the balance, paid when the type is paid.
  // Distinct from the separate Half-Day Request, which is always unpaid and touches no balance.
  is_half_day: z.boolean(),
  // UPPERCASE to match the backend — DeductionService compares 'AM'/'PM' with ===.
  half_day_period: z.enum(["AM", "PM"]),
}).superRefine((v, ctx) => {
  if (!v.is_half_day && !v.end_date) {
    ctx.addIssue({ code: "custom", path: ["end_date"], message: "End date is required" });
  }
});

type FormValues = z.infer<typeof schema>;

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
  mode = "not_shared",
  pool = null,
}: {
  types: LeaveType[];
  balances: LeaveBalance[];
  mode?: AllotmentMode;
  pool?: LeavePool | null;
}) {
  const createMut = useCreateLeaveRequest();

  const {
    register,
    control,
    handleSubmit,
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
      is_half_day: false,
      half_day_period: "AM",
    },
  });

  const [typeId, start, end, isHalfDay] = useWatch({
    control,
    name: ["leave_type_id", "start_date", "end_date", "is_half_day"],
  });

  // Live balance preview (mirrors the server; the 422 is still the real gate).
  const requested = computeLeaveDays(start, isHalfDay ? start : end, isHalfDay);
  const selectedType = types.find((t) => t.id === Number(typeId));
  // Unpaid leave (LWOP) has no credit pool — it's deducted from pay, not a balance.
  const isPaidType = selectedType?.is_paid ?? true;
  const isShared = mode === "shared";
  // Shared mode: every paid type draws from ONE pool; not-shared: the per-type row.
  const balance = balances.find((b) => b.leave_type.id === Number(typeId));
  const hasBalanceRecord = isShared ? !!pool : !!balance;
  const remaining = isShared ? (pool?.remaining_days ?? 0) : (balance?.remaining_days ?? 0);
  const willRemain = remaining - requested;
  const overBalance = isPaidType && hasBalanceRecord && requested > remaining;
  const minDate = earliestStart(selectedType);
  const canSubmit = !!typeId && requested > 0 && !overBalance;

  async function onSubmit(v: FormValues) {
    try {
      await createMut.mutateAsync({
        leave_type_id: Number(v.leave_type_id),
        start_date: v.start_date,
        // A half day is one day by definition, so the end date follows the start rather than
        // relying on the user to match them — the server rejects a spanning half-day (422).
        end_date: v.is_half_day ? v.start_date : v.end_date,
        reason: v.reason,
        is_half_day: v.is_half_day,
        ...(v.is_half_day ? { half_day_period: v.half_day_period } : {}),
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
    <form onSubmit={handleSubmit(onSubmit)} className="space-y-4" noValidate>
        <div className="space-y-1.5">
          <Label>Leave Type</Label>
          <Controller
            control={control}
            name="leave_type_id"
            render={({ field }) => (
              <SimpleSelect
                value={field.value}
                onValueChange={field.onChange}
                placeholder="Select…"
                aria-invalid={!!errors.leave_type_id}
                options={types.map((t) => ({ value: String(t.id), label: t.name }))}
              />
            )}
          />
          {errors.leave_type_id && (
            <p className="text-xs text-destructive">{errors.leave_type_id.message}</p>
          )}
        </div>

        <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
          <div className="space-y-1.5">
            <Label>Start Date</Label>
            <Controller
              control={control}
              name="start_date"
              render={({ field }) => (
                <DatePicker
                  value={field.value}
                  onChange={field.onChange}
                  min={minDate}
                  aria-invalid={!!errors.start_date}
                />
              )}
            />
            {errors.start_date && (
              <p className="text-xs text-destructive">{errors.start_date.message}</p>
            )}
          </div>
          {/*
           * A half day is a single day, so End Date is replaced by "which half" rather
           * than left on screen for the user to keep in sync with the start.
           */}
          {isHalfDay ? (
            <div className="space-y-1.5">
              <Label>Which half are you taking off</Label>
              <Controller
                control={control}
                name="half_day_period"
                render={({ field }) => (
                  <SimpleSelect
                    value={field.value}
                    onValueChange={field.onChange}
                    options={[
                      // The half taken OFF, not the half worked — the backend reads this as
                      // `half_day_off` and judges lateness against the OTHER half. "Morning (AM)"
                      // alone read either way, and picking it backwards spends the credit on the
                      // wrong half of the day.
                      { value: "AM", label: "Morning (AM) off — I work the afternoon" },
                      { value: "PM", label: "Afternoon (PM) off — I work the morning" },
                    ]}
                  />
                )}
              />
            </div>
          ) : (
            <div className="space-y-1.5">
              <Label>End Date</Label>
              <Controller
                control={control}
                name="end_date"
                render={({ field }) => (
                  <DatePicker
                    value={field.value}
                    onChange={field.onChange}
                    min={start || minDate}
                    aria-invalid={!!errors.end_date}
                  />
                )}
              />
              {errors.end_date && (
                <p className="text-xs text-destructive">{errors.end_date.message}</p>
              )}
            </div>
          )}
        </div>

        {/*
         * The toggle the spec always asked for (POD 1 Employee View, "Half-day option"). It sits
         * in the leave form because it is a property of the leave, not a separate kind of request:
         * it spends 0.5 of THIS type's balance and is paid whenever the type is paid.
         */}
        <label className="flex cursor-pointer items-start gap-2.5 rounded-lg border border-border px-3 py-2.5">
          <input
            type="checkbox"
            className="mt-0.5 size-4 shrink-0 accent-brand"
            {...register("is_half_day")}
          />
          <span className="text-sm text-foreground">
            Take only half a day
            <span className="block text-caption text-muted-foreground">
              Uses 0.5 day of this leave type instead of a full day. You&apos;re expected to work the
              other half.
            </span>
          </span>
        </label>

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
  );
}
