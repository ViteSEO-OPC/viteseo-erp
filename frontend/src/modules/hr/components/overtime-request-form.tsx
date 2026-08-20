"use client";

import { useForm, Controller, useWatch } from "react-hook-form";
import { zodResolver } from "@hookform/resolvers/zod";
import { z } from "zod";
import { AxiosError } from "axios";
import { notify as toast } from "@/core/lib/notify";
import { Info } from "lucide-react";

import { Button } from "@/components/ui/button";
import { Label } from "@/components/ui/label";
import { DatePicker } from "@/modules/hr/ui/date-picker";
import { TimePicker } from "@/modules/hr/ui/time-picker";
import {
  useCreateOvertimeRequest,
  useOvertimeClassification,
  useOvertimeSettings,
} from "@/modules/hr/hooks/use-overtime";

const HHMM = /^\d{2}:\d{2}$/;

const schema = z.object({
  work_date: z.string().min(1, "Date is required"),
  time_from: z.string().regex(HHMM, "Start time is required"),
  time_to: z.string().regex(HHMM, "End time is required"),
  reason: z.string().min(1, "Reason is required"),
});

type FormValues = z.infer<typeof schema>;

/**
 * Hours in a from→to window. An end at/before the start crosses MIDNIGHT (22:00→02:00 = 4h),
 * mirroring OvertimeRequestService::hoursBetween so the preview matches what the server stores.
 */
/** Today in the browser's local zone as YYYY-MM-DD — the floor for the date picker. */
const todayISO = () => new Date().toLocaleDateString("en-CA");

function toMinutes(hm: string): number {
  const [h, m] = hm.split(":").map(Number);
  return h * 60 + m;
}

/**
 * Is `timeFrom` before the shift ends, once both are placed on a real day? (Item 11.)
 *
 * MIRRORS `OvertimeRequestService::assertAfterShift` DELIBERATELY, including the tiebreak. A bare
 * HH:MM has no day attached, so it has to be placed on one, and the alignment chosen is the one
 * nearest the shift end — the same choice the server makes and the same one payroll's window match
 * makes later. Comparing the raw numbers instead (as this did) got a 22:00–06:00 shift backwards:
 * it waved through 23:00, an hour INTO the shift, and refused 01:00 after a day shift, which is
 * ordinary overtime past midnight.
 *
 * Returns false when the schedule is unknown or the day has no shift — a rest day has nothing to
 * be "beyond", so nothing to guard.
 */
function startsBeforeShiftEnd(
  timeFrom: string,
  shiftStart: string | null,
  shiftEnd: string | null,
): boolean {
  if (!shiftStart || !shiftEnd || !HHMM.test(timeFrom)) return false;

  const startMin = toMinutes(shiftStart);
  let endMin = toMinutes(shiftEnd);
  if (endMin <= startMin) endMin += 24 * 60; // the shift ends the following day

  const raw = toMinutes(timeFrom);
  let nearest = raw;
  for (const offset of [-24 * 60, 0, 24 * 60]) {
    if (Math.abs(raw + offset - endMin) < Math.abs(nearest - endMin)) nearest = raw + offset;
  }

  return nearest < endMin;
}

function hoursBetween(from: string, to: string): number | null {
  if (!HHMM.test(from) || !HHMM.test(to) || from === to) return null;
  const start = toMinutes(from);
  const end = toMinutes(to) <= start ? toMinutes(to) + 24 * 60 : toMinutes(to);
  return Math.round(((end - start) / 60) * 100) / 100;
}

/** A computed, non-editable field — same shape as an Input so the row lines up. */
function DerivedField({ label, children }: { label: string; children: React.ReactNode }) {
  return (
    <div className="space-y-1.5">
      <Label>{label}</Label>
      <div className="flex h-9 w-full items-center rounded-lg border border-input bg-muted/50 px-2.5 text-sm">
        {children}
      </div>
    </div>
  );
}

/**
 * Overtime request: hours worked BEYOND the scheduled day, filed as a time window. Total hours and
 * the pay classification are DERIVED (never typed) — hours from the window, classification from the
 * date via the server (regular day / rest day / special day / regular holiday) with its OT rate.
 * Renders bare so the Work Schedule tab panel can host it.
 */
export function OvertimeRequestForm() {
  const createMut = useCreateOvertimeRequest();
  const { data: settings, isLoading: settingsLoading } = useOvertimeSettings();

  const {
    register,
    control,
    handleSubmit,
    reset,
    setError,
    formState: { errors, isSubmitting },
  } = useForm<FormValues>({
    resolver: zodResolver(schema),
    defaultValues: { work_date: "", time_from: "", time_to: "", reason: "" },
  });

  const [workDate, timeFrom, timeTo] = useWatch({
    control,
    name: ["work_date", "time_from", "time_to"],
  });

  const hours = hoursBetween(timeFrom ?? "", timeTo ?? "");
  const { data: classification } = useOvertimeClassification(workDate ?? "");

  // Overtime is time BEYOND the shift, so on a workday it can't start before the scheduled end.
  // (A rest day has no shift end — the whole day is overtime, so there's nothing to guard.)
  const shiftStart = classification?.schedule_start ?? null;
  const shiftEnd = classification?.schedule_end ?? null;
  const startsTooEarly = startsBeforeShiftEnd(timeFrom ?? "", shiftStart, shiftEnd);
  // An overnight shift needs its whole window shown: "your shift ends at 06:00" alone reads as
  // this morning, when it means tomorrow morning.
  const isOvernight =
    !!shiftStart && !!shiftEnd && toMinutes(shiftEnd) <= toMinutes(shiftStart);

  async function onSubmit(v: FormValues) {
    if (startsBeforeShiftEnd(v.time_from, shiftStart, shiftEnd)) {
      setError("time_from", { message: `Overtime starts after your shift ends at ${shiftEnd}.` });
      return;
    }
    try {
      await createMut.mutateAsync(v);
      toast.success("Overtime request submitted", "HR will review it.");
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

  // Requesting only makes sense when the company pays overtime on approval. With overtime off, or
  // recorded automatically from the clock, a form here would just be rejected — explain instead.
  if (settingsLoading) {
    return <p className="text-sm text-muted-foreground">Loading…</p>;
  }
  if (settings && !settings.requests_enabled) {
    return (
      <div className="space-y-3">
        <div className="flex items-start gap-2 rounded-lg border border-border bg-muted/40 px-3 py-3 text-sm text-foreground">
          <Info className="mt-0.5 size-4 shrink-0 text-muted-foreground" />
          <span>{settings.note}</span>
        </div>
        <p className="text-caption text-muted-foreground">
          {settings.mode === "none"
            ? "Overtime requests are unavailable while overtime is switched off."
            : "Any overtime you work is picked up from your attendance automatically."}
        </p>
      </div>
    );
  }

  return (
    <form onSubmit={handleSubmit(onSubmit)} className="space-y-4" noValidate>
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

      <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
        <div className="space-y-1.5">
          <Label>Time from</Label>
          <Controller
            control={control}
            name="time_from"
            render={({ field }) => (
              <TimePicker
                value={field.value}
                onChange={field.onChange}
                minuteStep={15}
                aria-invalid={!!errors.time_from || startsTooEarly}
              />
            )}
          />
          {errors.time_from ? (
            <p className="text-xs text-destructive">{errors.time_from.message}</p>
          ) : startsTooEarly ? (
            <p className="text-xs text-destructive">
              Overtime starts after your shift ends at {shiftEnd}.
            </p>
          ) : isOvernight ? (
            // Naming both ends is the whole point on a graveyard shift: "ends at 06:00" on its own
            // reads as this morning, when the shift it belongs to has not started yet.
            <p className="text-xs text-muted-foreground">
              Your shift runs {shiftStart} to {shiftEnd} the next morning, so overtime starts from{" "}
              {shiftEnd} onwards.
            </p>
          ) : shiftEnd ? (
            <p className="text-xs text-muted-foreground">Your shift ends at {shiftEnd}.</p>
          ) : null}
        </div>
        <div className="space-y-1.5">
          <Label>Time to</Label>
          <Controller
            control={control}
            name="time_to"
            render={({ field }) => (
              <TimePicker
                value={field.value}
                onChange={field.onChange}
                minuteStep={15}
                aria-invalid={!!errors.time_to}
              />
            )}
          />
          {errors.time_to && (
            <p className="text-xs text-destructive">{errors.time_to.message}</p>
          )}
        </div>
      </div>

      {/* Both derived — hours from the window, classification from the date. */}
      <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
        <DerivedField label="Total hours">
          {hours !== null ? (
            <span className="font-semibold text-foreground">{hours.toFixed(1)} hrs</span>
          ) : (
            <span className="text-muted-foreground">—</span>
          )}
        </DerivedField>
        <DerivedField label="Classification">
          {classification ? (
            <span className="truncate">
              <span className="font-semibold text-foreground">{classification.label}</span>{" "}
              <span className="text-muted-foreground">{classification.rate_pct}%</span>
            </span>
          ) : (
            <span className="text-muted-foreground">Pick a date</span>
          )}
        </DerivedField>
      </div>

      <div className="space-y-1.5">
        <Label>Reason</Label>
        <textarea
          rows={2}
          className="w-full rounded-lg border border-input bg-transparent px-2.5 py-1.5 text-sm outline-none focus-visible:border-ring focus-visible:ring-3 focus-visible:ring-ring/50"
          placeholder="Describe the work to be done…"
          {...register("reason")}
        />
        {errors.reason && <p className="text-xs text-destructive">{errors.reason.message}</p>}
      </div>

      <div className="flex items-center gap-2 rounded-lg bg-brand-subtle px-3 py-2 text-sm text-brand-foreground">
        <Info className="size-4 shrink-0" />
        {hours !== null && classification ? (
          <span>
            {hours.toFixed(1)} hrs requested — {classification.label.toLowerCase()} OT at{" "}
            {classification.rate_pct}%
          </span>
        ) : (
          <span>Overtime is paid at your company&apos;s OT rate once approved.</span>
        )}
      </div>

      <Button type="submit" className="h-10 w-full" disabled={isSubmitting}>
        {isSubmitting ? "Submitting…" : "Submit request"}
      </Button>
      <p className="text-center text-caption text-muted-foreground">
        Your manager will be notified of this request immediately.
      </p>
    </form>
  );
}
