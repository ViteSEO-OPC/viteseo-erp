"use client";

import * as React from "react";
import { Calendar as CalendarIcon, ChevronLeft, ChevronRight } from "lucide-react";
import {
  addMonths,
  eachDayOfInterval,
  endOfMonth,
  endOfWeek,
  format,
  isAfter,
  isBefore,
  isSameDay,
  isSameMonth,
  parse,
  startOfMonth,
  startOfWeek,
  startOfToday,
} from "date-fns";

import { cn } from "@/lib/utils";
import { Popover, PopoverTrigger, PopoverContent } from "@/modules/hr/ui/popover";

const FMT = "yyyy-MM-dd"; // the value contract — matches <input type="date">
const WEEKDAYS = ["Su", "Mo", "Tu", "We", "Th", "Fr", "Sa"];
const MONTHS = Array.from({ length: 12 }, (_, i) => format(new Date(2000, i, 1), "MMMM"));

/**
 * How far the year dropdown reaches when the caller gives no `min`/`max`. Wide
 * enough backwards for a date of birth, and a little forward for future-dated
 * things like a hire date.
 */
const YEARS_BACK = 100;
const YEARS_FORWARD = 10;

const CAPTION_SELECT =
  "h-7 rounded-md border border-input bg-card px-1.5 text-sm font-semibold text-foreground outline-none focus-visible:border-ring";

/** Parse a "yyyy-MM-dd" string to a LOCAL Date (no timezone shift). */
function parseValue(value: string | null | undefined): Date | null {
  if (!value) return null;
  const d = parse(value, FMT, new Date());
  return Number.isNaN(d.getTime()) ? null : d;
}

/**
 * Date picker that renders a styled calendar in a popover instead of the native
 * browser date control. Its value contract is identical to `<input type="date">`
 * — a plain "yyyy-MM-dd" string (timezone-agnostic) — so it's a drop-in swap.
 * `onChange` receives the string directly (not an event).
 */
export function DatePicker({
  value,
  onChange,
  placeholder = "Pick a date",
  disabled,
  min,
  max,
  fromYear,
  toYear,
  id,
  className,
  "aria-label": ariaLabel,
  "aria-invalid": ariaInvalid,
}: {
  value: string | null | undefined;
  onChange: (value: string) => void;
  placeholder?: string;
  disabled?: boolean;
  /** Earliest selectable date, "yyyy-MM-dd". */
  min?: string;
  /** Latest selectable date, "yyyy-MM-dd". */
  max?: string;
  /** First year in the year dropdown. Defaults to `min`'s year, else 100 years back. */
  fromYear?: number;
  /** Last year in the year dropdown. Defaults to `max`'s year, else 10 years ahead. */
  toYear?: number;
  id?: string;
  className?: string;
  "aria-label"?: string;
  "aria-invalid"?: boolean;
}) {
  const selected = parseValue(value);
  const minDate = parseValue(min);
  const maxDate = parseValue(max);

  const [open, setOpen] = React.useState(false);
  const [viewMonth, setViewMonth] = React.useState<Date>(selected ?? startOfToday());

  // Re-sync the shown month to the value whenever the popover opens.
  function handleOpenChange(next: boolean) {
    if (next) setViewMonth(selected ?? startOfToday());
    setOpen(next);
  }

  const days = eachDayOfInterval({
    start: startOfWeek(startOfMonth(viewMonth)),
    end: endOfWeek(endOfMonth(viewMonth)),
  });

  function isDisabled(day: Date): boolean {
    if (minDate && isBefore(day, minDate)) return true;
    if (maxDate && isAfter(day, maxDate)) return true;
    return false;
  }

  function pick(day: Date) {
    if (isDisabled(day)) return;
    onChange(format(day, FMT));
    setOpen(false);
  }

  const today = startOfToday();

  // Year dropdown range, so picking a birthday isn't 26 chevron clicks.
  const viewStart = startOfMonth(viewMonth);
  const viewYear = viewMonth.getFullYear();
  // The shown year always has an option, even if an existing value sits outside
  // the range — otherwise the dropdown would render blank.
  const firstYear = Math.min(
    fromYear ?? minDate?.getFullYear() ?? today.getFullYear() - YEARS_BACK,
    viewYear,
  );
  const lastYear = Math.max(
    toYear ?? maxDate?.getFullYear() ?? today.getFullYear() + YEARS_FORWARD,
    viewYear,
  );
  // Newest first — most picks are near the present, in both directions.
  const years = Array.from({ length: lastYear - firstYear + 1 }, (_, i) => lastYear - i);

  const atMinMonth = !!minDate && !isAfter(viewStart, startOfMonth(minDate));
  const atMaxMonth = !!maxDate && !isBefore(viewStart, startOfMonth(maxDate));

  function goToMonth(month: number, year: number) {
    setViewMonth(startOfMonth(new Date(year, month, 1)));
  }

  return (
    <Popover open={open} onOpenChange={handleOpenChange}>
      <PopoverTrigger
        id={id}
        type="button"
        disabled={disabled}
        aria-label={ariaLabel}
        aria-invalid={ariaInvalid}
        className={cn(
          "flex h-8 w-full min-w-0 items-center justify-between gap-2 rounded-lg border border-input bg-transparent px-2.5 py-1 text-sm transition-colors outline-none focus-visible:border-ring focus-visible:ring-3 focus-visible:ring-ring/50 disabled:cursor-not-allowed disabled:opacity-50 aria-invalid:border-destructive aria-invalid:ring-3 aria-invalid:ring-destructive/20",
          className,
        )}
      >
        <span className={cn("truncate", !selected && "text-muted-foreground")}>
          {selected ? format(selected, "MMM d, yyyy") : placeholder}
        </span>
        <CalendarIcon className="size-4 shrink-0 opacity-50" />
      </PopoverTrigger>
      <PopoverContent className="p-3">
        <div className="mb-2 flex items-center justify-between gap-1">
          <button
            type="button"
            disabled={atMinMonth}
            onClick={() => setViewMonth((m) => addMonths(m, -1))}
            className="inline-flex size-7 shrink-0 items-center justify-center rounded-md text-foreground transition-colors hover:bg-muted disabled:cursor-not-allowed disabled:opacity-40"
            aria-label="Previous month"
          >
            <ChevronLeft className="size-4" />
          </button>

          <div className="flex items-center gap-1">
            <select
              value={viewMonth.getMonth()}
              onChange={(e) => goToMonth(Number(e.target.value), viewMonth.getFullYear())}
              className={CAPTION_SELECT}
              aria-label="Month"
            >
              {MONTHS.map((label, i) => (
                <option key={label} value={i}>{label}</option>
              ))}
            </select>
            <select
              value={viewMonth.getFullYear()}
              onChange={(e) => goToMonth(viewMonth.getMonth(), Number(e.target.value))}
              className={CAPTION_SELECT}
              aria-label="Year"
            >
              {years.map((y) => (
                <option key={y} value={y}>{y}</option>
              ))}
            </select>
          </div>

          <button
            type="button"
            disabled={atMaxMonth}
            onClick={() => setViewMonth((m) => addMonths(m, 1))}
            className="inline-flex size-7 shrink-0 items-center justify-center rounded-md text-foreground transition-colors hover:bg-muted disabled:cursor-not-allowed disabled:opacity-40"
            aria-label="Next month"
          >
            <ChevronRight className="size-4" />
          </button>
        </div>

        <div className="grid grid-cols-7 gap-0.5">
          {WEEKDAYS.map((w) => (
            <div
              key={w}
              className="flex h-7 items-center justify-center text-caption font-medium text-muted-foreground"
            >
              {w}
            </div>
          ))}
          {days.map((day) => {
            const outside = !isSameMonth(day, viewMonth);
            const isSelected = selected && isSameDay(day, selected);
            const isToday = isSameDay(day, today);
            const off = isDisabled(day);
            return (
              <button
                key={day.toISOString()}
                type="button"
                disabled={off}
                onClick={() => pick(day)}
                className={cn(
                  "flex size-8 items-center justify-center rounded-md text-sm transition-colors",
                  isSelected
                    ? "bg-brand font-semibold text-brand-foreground"
                    : "hover:bg-muted",
                  !isSelected && outside && "text-muted-foreground/50",
                  !isSelected && !outside && "text-foreground",
                  !isSelected && isToday && "ring-1 ring-brand ring-inset",
                  off && "pointer-events-none opacity-40",
                )}
              >
                {format(day, "d")}
              </button>
            );
          })}
        </div>
      </PopoverContent>
    </Popover>
  );
}
