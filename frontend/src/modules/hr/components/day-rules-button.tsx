"use client";

import { useState } from "react";
import { SlidersHorizontal } from "lucide-react";

import { Button } from "@/components/ui/button";
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from "@/components/ui/dialog";
import { Input } from "@/components/ui/input";
import { useDailyOverride, useUpsertDailyOverride } from "@/modules/hr/hooks/use-attendance";
import { manilaToday } from "@/modules/hr/lib/manila-date";

/**
 * The two rules HR can bend for ONE date, in one dialog because they are one decision:
 *
 *   • Grace period    — how late an arrival still counts as on time.
 *   • Full day at     — how many hours WORKED make the day whole, however early they leave.
 *
 * The second exists for the days a DOLE advisory (or the company) shortens the compensable
 * day: "six hours is a full day today". Somebody on 8:00–18:00 who clocks out at 15:00 has
 * worked six hours once the unpaid meal is out, so the day is whole — no undertime.
 *
 * IT CAN ONLY EVER FORGIVE. Falling short of the bar costs nothing that it did not already
 * cost, so a part-timer or someone with an approved half-day is priced exactly as before.
 * Lateness is deliberately not forgiven by it either — that is what the grace field is for.
 */

/** 360 → "6", 390 → "6.5". The rule is announced in hours; it is stored in minutes. */
function minutesToHoursInput(minutes: number | null | undefined): string {
  if (minutes == null) return "";
  const hours = minutes / 60;
  return Number.isInteger(hours) ? String(hours) : String(Number(hours.toFixed(2)));
}

/** "6" → 360, "6.5" → 390. Returns null for empty, NaN for anything that isn't a number. */
function hoursInputToMinutes(raw: string): number | null | typeof NaN {
  const trimmed = raw.trim();
  if (trimmed === "") return null;
  const hours = Number(trimmed);
  return Number.isFinite(hours) ? Math.round(hours * 60) : NaN;
}

export function DayRulesButton() {
  const [open, setOpen] = useState(false);
  const [minutes, setMinutes] = useState("");
  const [fullDayHours, setFullDayHours] = useState("");
  const [error, setError] = useState<string | null>(null);

  const { data: override } = useDailyOverride();
  const upsert = useUpsertDailyOverride();

  const handleOpen = () => {
    setMinutes(override?.grace_minutes != null ? String(override.grace_minutes) : "");
    setFullDayHours(minutesToHoursInput(override?.min_full_day_minutes));
    setError(null);
    setOpen(true);
  };

  const save = (graceMinutes: number | null, minFullDayMinutes: number | null) => {
    // Manila, NOT `toISOString()`. The browser's UTC date is still YESTERDAY until 8am here,
    // which is exactly the window HR sets a typhoon morning's rules in — the write would have
    // landed on the wrong day and today would have got nothing.
    upsert.mutate(
      { date: manilaToday(), graceMinutes, minFullDayMinutes },
      { onSuccess: () => setOpen(false) },
    );
  };

  const handleSave = () => {
    const grace = minutes.trim() === "" ? null : Number(minutes);
    if (grace !== null && (!Number.isFinite(grace) || grace < 0 || grace > 240)) {
      setError("Grace period must be between 0 and 240 minutes.");
      return;
    }

    const fullDay = hoursInputToMinutes(fullDayHours);
    if (Number.isNaN(fullDay)) {
      setError("Full day hours must be a number.");
      return;
    }
    if (fullDay !== null && (fullDay < 1 || fullDay > 1440)) {
      setError("Full day hours must be between 0 and 24.");
      return;
    }

    setError(null);
    save(grace, fullDay as number | null);
  };

  const hasGrace = override?.grace_minutes != null;
  const hasFullDay = override?.min_full_day_minutes != null;
  const hasAny = hasGrace || hasFullDay;

  const summary = [
    hasGrace ? `${override!.grace_minutes}m grace` : null,
    hasFullDay ? `${minutesToHoursInput(override!.min_full_day_minutes)}h day` : null,
  ]
    .filter(Boolean)
    .join(" · ");

  return (
    <>
      <Button variant="outline" size="sm" className="gap-2" onClick={handleOpen}>
        <SlidersHorizontal className="size-4" />
        {summary || "Today's rules"}
      </Button>

      <Dialog open={open} onOpenChange={setOpen}>
        <DialogContent className="max-h-[90svh] max-w-[calc(100%-3rem)] overflow-y-auto sm:max-w-sm">
          <DialogHeader>
            <DialogTitle>Today&apos;s Rules</DialogTitle>
            <DialogDescription>
              Bend the attendance rules for{" "}
              {new Date().toLocaleDateString("en-PH", {
                timeZone: "Asia/Manila",
                weekday: "short",
                month: "short",
                day: "numeric",
              })}
              . Leave a field empty to use the schedule default.
            </DialogDescription>
          </DialogHeader>

          <div className="space-y-5">
            <div className="space-y-2">
              <label className="text-sm font-medium text-foreground" htmlFor="day-rules-grace">
                Grace minutes
              </label>
              <Input
                id="day-rules-grace"
                type="number"
                min={0}
                max={240}
                placeholder="e.g. 30"
                value={minutes}
                onChange={(e) => setMinutes(e.target.value)}
              />
              <p className="text-xs text-muted-foreground">
                Employees arriving within this window after their schedule start time are
                marked &quot;On time&quot; today.
              </p>
            </div>

            <div className="space-y-2">
              <label className="text-sm font-medium text-foreground" htmlFor="day-rules-full-day">
                Full day at (hours)
              </label>
              <Input
                id="day-rules-full-day"
                type="number"
                min={0}
                max={24}
                step={0.5}
                placeholder="e.g. 6"
                value={fullDayHours}
                onChange={(e) => setFullDayHours(e.target.value)}
              />
              <p className="text-xs text-muted-foreground">
                Work this many hours today and the day counts in full, with no undertime,
                however early the employee clocks out. Lunch doesn&apos;t count toward it.
                Anyone below it is paid for the time they worked, exactly as they are now —
                this never costs anybody more.
              </p>
            </div>

            {error && <p className="text-xs font-medium text-destructive">{error}</p>}
          </div>

          <DialogFooter className="gap-2 sm:gap-0">
            {hasAny && (
              <Button
                variant="outline"
                size="sm"
                onClick={() => save(null, null)}
                disabled={upsert.isPending}
              >
                Clear all
              </Button>
            )}
            <Button size="sm" onClick={handleSave} disabled={upsert.isPending}>
              {upsert.isPending ? "Saving…" : "Save"}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </>
  );
}
