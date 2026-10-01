"use client";

import { useState } from "react";
import { SlidersHorizontal } from "lucide-react";
import { AxiosError } from "axios";

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
import { DatePicker } from "@/modules/hr/ui/date-picker";
import { RepricingConfirmDialog } from "@/modules/hr/components/repricing-confirm-dialog";
import { useDailyOverride, useUpsertDailyOverride } from "@/modules/hr/hooks/use-attendance";
import { manilaToday } from "@/modules/hr/lib/manila-date";

/** The two rules HR can bend for ONE date, in one dialog because they are one decision: */

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

/** Pull the server's message out of a 422, and say WHICH field it landed on. */
function fieldError(err: unknown): { field: string | null; message: string } {
  if (err instanceof AxiosError && err.response?.status === 422) {
    const errors: Record<string, string[]> = err.response.data?.errors ?? {};
    const [field, messages] = Object.entries(errors)[0] ?? [];
    if (field && Array.isArray(messages) && typeof messages[0] === "string") {
      return { field, message: messages[0] };
    }
    return { field: null, message: err.response.data?.message ?? "Could not save the day's rules." };
  }
  return { field: null, message: "Could not save the day's rules. Please try again." };
}

const LONG_DATE = (iso: string) =>
  new Date(`${iso}T00:00:00`).toLocaleDateString("en-PH", {
    weekday: "short",
    month: "short",
    day: "numeric",
    year: "numeric",
  });

/**
 * `forDate` is the day the surrounding screen is showing — the attendance page's own date
 * picker. The button then reports and opens on THAT day rather than today, because sitting
 * beside a date control while silently meaning a different date is the one thing it must not
 * do. Omitted (a screen with no date of its own), it means today.
 */
export function DayRulesButton({ forDate }: { forDate?: string } = {}) {
  const anchor = forDate ?? manilaToday();

  const [open, setOpen] = useState(false);
  /** The date being edited. Opens on the screen's date; the picker inside may move it. */
  const [date, setDate] = useState(anchor);
  /**
   * What HR has TYPED, tagged with the date they typed it for. The fields themselves are
   * derived below: whatever is in here for the current date wins, otherwise the day's saved
   * rules show through. Switching dates therefore drops the edits by construction, with no
   * effect syncing server state into local state behind it.
   */
  const [edits, setEdits] = useState<{
    date: string;
    minutes: string;
    fullDayHours: string;
  } | null>(null);
  const [error, setError] = useState<string | null>(null);
  /**
   * Set when the server asked the repricing question. Carries the values that were REFUSED,
   * not just the message: "Clear all" sends nulls, and re-reading the inputs on confirm would
   * have quietly saved them instead of clearing the day.
   */
  const [confirmRepricing, setConfirmRepricing] = useState<
    { message: string; grace: number | null; fullDay: number | null } | null
  >(null);
  // The trigger reports the SCREEN's day — it is a status badge on the page header, not a
  // cursor into whatever date the dialog has since been moved to.
  const { data: anchorOverride } = useDailyOverride(anchor);
  const { data: override } = useDailyOverride(date);
  const upsert = useUpsertDailyOverride();

  // The fields: typed values for THIS date if there are any, else what the day already says.
  const typed = edits?.date === date ? edits : null;
  const minutes = typed?.minutes ?? (override?.grace_minutes != null ? String(override.grace_minutes) : "");
  const fullDayHours = typed?.fullDayHours ?? minutesToHoursInput(override?.min_full_day_minutes);

  const setMinutes = (value: string) => setEdits({ date, minutes: value, fullDayHours });
  const setFullDayHours = (value: string) => setEdits({ date, minutes, fullDayHours: value });

  const pickDate = (value: string) => {
    setDate(value);
    setError(null); // it belonged to the day being left
  };

  const handleOpen = () => {
    setDate(anchor);
    setEdits(null);
    setError(null);
    setOpen(true);
  };

  const save = async (
    graceMinutes: number | null,
    minFullDayMinutes: number | null,
    acknowledgeRepricing = false,
  ) => {
    try {
      await upsert.mutateAsync({ date, graceMinutes, minFullDayMinutes, acknowledgeRepricing });
      setConfirmRepricing(null);
      setOpen(false);
    } catch (err) {
      const { field, message } = fieldError(err);
      // A 422 on the date is a QUESTION — this day sits inside a completed run — not a
      // failure. Same shape as a back-dated salary or contribution rate.
      if (field === "date" && !acknowledgeRepricing) {
        setConfirmRepricing({ message, grace: graceMinutes, fullDay: minFullDayMinutes });
        return;
      }
      setError(message);
    }
  };

  /** The validated field values, or null when something is out of range (message set). */
  const readFields = (): { grace: number | null; fullDay: number | null } | null => {
    const grace = minutes.trim() === "" ? null : Number(minutes);
    if (grace !== null && (!Number.isFinite(grace) || grace < 0 || grace > 240)) {
      setError("Grace period must be between 0 and 240 minutes.");
      return null;
    }

    const fullDay = hoursInputToMinutes(fullDayHours);
    if (Number.isNaN(fullDay)) {
      setError("Full day hours must be a number.");
      return null;
    }
    if (fullDay !== null && (fullDay < 1 || fullDay > 1440)) {
      setError("Full day hours must be between 0 and 24.");
      return null;
    }

    return { grace, fullDay: fullDay as number | null };
  };

  const handleSave = () => {
    const values = readFields();
    if (!values) return;
    setError(null);
    void save(values.grace, values.fullDay);
  };

  const hasGrace = anchorOverride?.grace_minutes != null;
  const hasFullDay = anchorOverride?.min_full_day_minutes != null;

  const summary = [
    hasGrace ? `${anchorOverride!.grace_minutes}m grace` : null,
    hasFullDay ? `${minutesToHoursInput(anchorOverride!.min_full_day_minutes)}h day` : null,
  ]
    .filter(Boolean)
    .join(" · ");

  const today = manilaToday();
  const isToday = date === today;
  const isPast = date < today;
  const dayHasAny = override?.grace_minutes != null || override?.min_full_day_minutes != null;

  return (
    <>
      <Button variant="outline" size="sm" className="gap-2" onClick={handleOpen}>
        <SlidersHorizontal className="size-4" />
        {summary || (anchor === today ? "Today's rules" : "Day rules")}
      </Button>

      <Dialog open={open} onOpenChange={setOpen}>
        <DialogContent className="max-h-[90svh] max-w-[calc(100%-3rem)] overflow-y-auto sm:max-w-sm">
          <DialogHeader>
            <DialogTitle>Day Rules</DialogTitle>
            <DialogDescription>
              Bend the attendance rules for one date. Leave a field empty to use the schedule
              default.
            </DialogDescription>
          </DialogHeader>

          <div className="space-y-5">
            <div className="space-y-2">
              <label className="text-sm font-medium text-foreground" htmlFor="day-rules-date">
                Date
              </label>
              <DatePicker
                id="day-rules-date"
                value={date}
                onChange={pickDate}
                aria-label="The date these rules apply to"
              />
              <p className="text-xs text-muted-foreground">
                {isToday
                  ? "Today. Rules set now apply to everyone who has already clocked in."
                  : isPast
                    ? `${LONG_DATE(date)} — already happened. Saving re-judges that day from the punches: a late inside the new grace stops being one, and hours past the new full-day bar stop counting as undertime.`
                    : `${LONG_DATE(date)} — ahead of today. Set it now and it applies when the day arrives.`}
              </p>
            </div>

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
                marked &quot;On time&quot;.
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
                Work this many hours and the day counts in full, with no undertime, however
                early the employee clocks out. Lunch doesn&apos;t count toward it. Anyone below
                it is paid for the time they worked, exactly as they are now — this never costs
                anybody more.
              </p>
            </div>

            {isPast && (
              <p className="text-xs text-muted-foreground">
                Pay for a day already paid out does not move on its own. Re-run that period in
                Payroll to pick this up — already-disbursed payslips are never recomputed.
              </p>
            )}

            {error && <p className="text-xs font-medium text-destructive">{error}</p>}
          </div>

          <DialogFooter className="gap-2 sm:gap-0">
            {dayHasAny && (
              <Button
                variant="outline"
                size="sm"
                onClick={() => void save(null, null)}
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

      <RepricingConfirmDialog
        message={confirmRepricing?.message ?? null}
        pending={upsert.isPending}
        title="This day was already paid"
        note="The day is re-judged either way, but payslips only move when you re-run that period in Payroll — and already-disbursed ones never will."
        confirmLabel="Set it anyway"
        onCancel={() => setConfirmRepricing(null)}
        onConfirm={() => {
          if (!confirmRepricing) return;
          void save(confirmRepricing.grace, confirmRepricing.fullDay, true);
        }}
      />
    </>
  );
}
