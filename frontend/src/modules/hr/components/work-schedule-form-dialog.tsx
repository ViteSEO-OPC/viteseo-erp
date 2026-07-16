"use client";

import { useEffect } from "react";
import { useForm, Controller } from "react-hook-form";
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

const TIME_RE = /^([01]\d|2[0-3]):[0-5]\d$/;

const schema = z.object({
  name: z.string().min(1, "Name is required").max(255),
  workdays: z.array(z.number()).min(1, "Pick at least one workday"),
  start_time: z.string().regex(TIME_RE, "Use HH:MM"),
  end_time: z.string().regex(TIME_RE, "Use HH:MM"),
  grace_minutes: z.coerce.number().min(0).max(240),
  is_default: z.boolean(),
});

type FormValues = z.input<typeof schema>;

const DEFAULTS: FormValues = {
  name: "",
  workdays: [1, 2, 3, 4, 5],
  start_time: "09:00",
  end_time: "18:00",
  grace_minutes: 15,
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
      <DialogContent>
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

          <div className="grid grid-cols-3 gap-3">
            <div className="space-y-1.5">
              <Label htmlFor="ws-start">Start</Label>
              <Input id="ws-start" type="time" aria-invalid={!!errors.start_time} {...register("start_time")} />
              {errors.start_time && <p className="text-xs text-destructive">{errors.start_time.message}</p>}
            </div>
            <div className="space-y-1.5">
              <Label htmlFor="ws-end">End</Label>
              <Input id="ws-end" type="time" aria-invalid={!!errors.end_time} {...register("end_time")} />
              {errors.end_time && <p className="text-xs text-destructive">{errors.end_time.message}</p>}
            </div>
            <div className="space-y-1.5">
              <Label htmlFor="ws-grace">Grace (min)</Label>
              <Input id="ws-grace" type="number" min="0" max="240" {...register("grace_minutes")} />
            </div>
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
