"use client";

import { useEffect } from "react";
import { useForm, useWatch, Controller } from "react-hook-form";
import { zodResolver } from "@hookform/resolvers/zod";
import { z } from "zod";
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
import { cn } from "@/lib/utils";
import {
  useCreateWorkSchedule,
  useUpdateWorkSchedule,
} from "@/modules/hr/hooks/use-rules";
import { WEEKDAYS, type WorkSchedule } from "@/modules/hr/types/rules";
import { TimePicker } from "@/modules/hr/ui/time-picker";

const TIME_RE = /^([01]\d|2[0-3]):[0-5]\d$/;


function toMinutes(hm: string): number | null {
  const m = /^(\d{2}):(\d{2})$/.exec(hm);
  return m ? Number(m[1]) * 60 + Number(m[2]) : null;
}

function formatDuration(mins: number): string {
  return `${Math.floor(mins / 60)}h ${mins % 60}m`;
}

const schema = z.object({
  name: z.string().min(1, "Name is required").max(255),
  workdays: z.array(z.number()).min(1, "Pick at least one workday"),
  start_time: z.string().regex(TIME_RE, "Use HH:MM"),
  end_time: z.string().regex(TIME_RE, "Use HH:MM"),
  grace_minutes: z.coerce.number().min(0).max(240),
  unpaid_break_minutes: z.coerce.number().min(0).max(480),
  paid_break_minutes: z.coerce.number().min(0).max(480),
  // Required, not optional. A blank half-day window is the state in which an approved half day
  // cannot be judged at all — and the split cannot be computed from the shift, because the
  // schedule records how LONG the unpaid break is but never when it falls.
  am_start_time: z.string().regex(TIME_RE, "Use HH:MM"),
  am_end_time: z.string().regex(TIME_RE, "Use HH:MM"),
  pm_start_time: z.string().regex(TIME_RE, "Use HH:MM"),
  pm_end_time: z.string().regex(TIME_RE, "Use HH:MM"),
  is_default: z.boolean(),
});

type FormValues = z.input<typeof schema>;

const DEFAULTS: FormValues = {
  name: "",
  workdays: [1, 2, 3, 4, 5],
  start_time: "08:00",
  end_time: "18:00",
  grace_minutes: 15,
  unpaid_break_minutes: 60,
  paid_break_minutes: 20,
  am_start_time: "08:00",
  am_end_time: "12:00",
  pm_start_time: "13:00",
  pm_end_time: "18:00",
  is_default: false,
};

export function WorkScheduleFormDialog({
  open,
  onOpenChange,
  schedule,
}: {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  schedule?: WorkSchedule | null;
}) {
  const isEdit = !!schedule;
  const createMut = useCreateWorkSchedule();
  const updateMut = useUpdateWorkSchedule();

  const {
    register,
    handleSubmit,
    reset,
    control,
    formState: { errors, isSubmitting },
  } = useForm<FormValues>({ resolver: zodResolver(schema), defaultValues: DEFAULTS });

  // Live "paid day" = (end − start) − unpaid meal. Null when the window is incomplete.
  const [startTime, endTime, unpaidBreakValue] = useWatch({
    control,
    name: ["start_time", "end_time", "unpaid_break_minutes"],
  });
  const startMin = toMinutes(startTime ?? "");
  const endMin = toMinutes(endTime ?? "");
  const unpaidBreak = Number(unpaidBreakValue) || 0;
  const paidMinutes =
    startMin !== null && endMin !== null && endMin > startMin
      ? Math.max(0, endMin - startMin - unpaidBreak)
      : null;

  useEffect(() => {
    if (open) {
      reset(
        schedule
          ? {
              name: schedule.name,
              workdays: schedule.workdays,
              start_time: schedule.start_time,
              end_time: schedule.end_time,
              grace_minutes: schedule.grace_minutes,
              unpaid_break_minutes: schedule.unpaid_break_minutes,
              paid_break_minutes: schedule.paid_break_minutes,
              am_start_time: schedule.am_start_time ?? "",
              am_end_time: schedule.am_end_time ?? "",
              pm_start_time: schedule.pm_start_time ?? "",
              pm_end_time: schedule.pm_end_time ?? "",
              is_default: schedule.is_default,
            }
          : DEFAULTS,
      );
    }
  }, [open, schedule, reset]);

  async function onSubmit(values: FormValues) {
    const input = {
      name: values.name,
      workdays: values.workdays,
      start_time: values.start_time,
      end_time: values.end_time,
      grace_minutes: Number(values.grace_minutes),
      unpaid_break_minutes: Number(values.unpaid_break_minutes),
      paid_break_minutes: Number(values.paid_break_minutes),
      am_start_time: values.am_start_time || null,
      am_end_time: values.am_end_time || null,
      pm_start_time: values.pm_start_time || null,
      pm_end_time: values.pm_end_time || null,
      is_default: values.is_default,
    };
    try {
      if (schedule) {
        await updateMut.mutateAsync({ id: schedule.id, input });
        toast.success("Schedule updated");
      } else {
        await createMut.mutateAsync(input);
        toast.success("Schedule created");
      }
      onOpenChange(false);
    } catch {
      toast.error("Could not save the schedule.");
    }
  }

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="max-h-[90svh] max-w-[calc(100%-3rem)] overflow-y-auto sm:max-w-lg">
        <DialogHeader>
          <DialogTitle>{isEdit ? "Edit schedule" : "Add schedule"}</DialogTitle>
          <DialogDescription>
            Working days and hours. Lateness is measured against this (plus the
            grace period).
          </DialogDescription>
        </DialogHeader>

        <form onSubmit={handleSubmit(onSubmit)} className="space-y-4" noValidate>
          <div className="space-y-1.5">
            <Label htmlFor="ws-name">Name</Label>
            <Input id="ws-name" placeholder="Office" aria-invalid={!!errors.name} {...register("name")} />
            {errors.name && <p className="text-xs text-destructive">{errors.name.message}</p>}
          </div>

          <div className="space-y-1.5">
            <Label>Workdays</Label>
            <Controller
              control={control}
              name="workdays"
              render={({ field }) => (
                <div className="flex flex-wrap gap-1.5">
                  {WEEKDAYS.map((d) => {
                    const on = field.value.includes(d.value);
                    return (
                      <button
                        key={d.value}
                        type="button"
                        onClick={() =>
                          field.onChange(
                            on
                              ? field.value.filter((v) => v !== d.value)
                              : [...field.value, d.value],
                          )
                        }
                        className={cn(
                          "rounded-lg border px-2.5 py-1 text-sm font-medium transition-colors",
                          on
                            ? "border-brand bg-brand text-brand-foreground"
                            : "border-input text-muted-foreground hover:bg-muted",
                        )}
                      >
                        {d.label}
                      </button>
                    );
                  })}
                </div>
              )}
            />
            {errors.workdays && <p className="text-xs text-destructive">{errors.workdays.message}</p>}
          </div>

          <div className="grid grid-cols-1 sm:grid-cols-3 gap-3">
            <div className="space-y-1.5">
              <Label htmlFor="ws-start">Start</Label>
              <Controller
                control={control}
                name="start_time"
                render={({ field }) => (
                  <TimePicker
                    id="ws-start"
                    value={field.value}
                    onChange={field.onChange}
                    aria-invalid={!!errors.start_time}
                  />
                )}
              />
              {errors.start_time && <p className="text-xs text-destructive">{errors.start_time.message}</p>}
            </div>
            <div className="space-y-1.5">
              <Label htmlFor="ws-end">End</Label>
              <Controller
                control={control}
                name="end_time"
                render={({ field }) => (
                  <TimePicker
                    id="ws-end"
                    value={field.value}
                    onChange={field.onChange}
                    aria-invalid={!!errors.end_time}
                  />
                )}
              />
              {errors.end_time && <p className="text-xs text-destructive">{errors.end_time.message}</p>}
            </div>
            <div className="space-y-1.5">
              <Label htmlFor="ws-grace">Grace (min)</Label>
              <Input id="ws-grace" type="number" min="0" max="240" {...register("grace_minutes")} />
            </div>
          </div>

          {/* Breaks — the unpaid meal shortens the paid day; the paid break sits inside it. */}
          <div className="space-y-1.5">
            <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
              <div className="space-y-1.5">
                <Label htmlFor="ws-unpaid-break">Lunch / unpaid break (min)</Label>
                <Input id="ws-unpaid-break" type="number" min="0" max="480" {...register("unpaid_break_minutes")} />
              </div>
              <div className="space-y-1.5">
                <Label htmlFor="ws-paid-break">Paid break (min)</Label>
                <Input id="ws-paid-break" type="number" min="0" max="480" {...register("paid_break_minutes")} />
              </div>
            </div>
            <p className="text-xs text-muted-foreground">
              Lunch is unpaid and shortens the paid day. The paid break stays inside it (used only to
              flag over-long breaks, never to deduct pay).
              {paidMinutes !== null && (
                <>
                  {" "}
                  <span className="font-medium text-foreground">Paid day: {formatDuration(paidMinutes)}.</span>
                </>
              )}
            </p>
          </div>

          {/*
           * Half-day windows — what an approved half day's lateness and undertime are measured
           * against, so they have to be stated rather than guessed at.
           */}
          <div className="space-y-1.5">
            <Label>Half-day windows</Label>
            <div className="grid grid-cols-2 gap-3 sm:grid-cols-4">
              {(
                [
                  ["am_start_time", "AM start"],
                  ["am_end_time", "AM end"],
                  ["pm_start_time", "PM start"],
                  ["pm_end_time", "PM end"],
                ] as const
              ).map(([fieldName, label]) => (
                <div key={fieldName} className="space-y-1.5">
                  <Label htmlFor={`ws-${fieldName}`} className="text-xs text-muted-foreground">
                    {label}
                  </Label>
                  <Controller
                    control={control}
                    name={fieldName}
                    render={({ field }) => (
                      <TimePicker
                        id={`ws-${fieldName}`}
                        value={field.value ?? ""}
                        onChange={field.onChange}
                      />
                    )}
                  />
                </div>
              ))}
            </div>
            <p className="text-xs text-muted-foreground">
              The hours each half of a half-day covers — an approved half day is judged against
              these, so someone with the morning off is on time when they arrive for the PM start.
              Override them per person on their Job tab.
            </p>
          </div>

          <div className="flex items-center gap-2">
            <input id="ws-default" type="checkbox" className="size-4 rounded border-input accent-brand" {...register("is_default")} />
            <Label htmlFor="ws-default" className="font-normal">
              Default schedule (used by employees with no other assignment)
            </Label>
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
