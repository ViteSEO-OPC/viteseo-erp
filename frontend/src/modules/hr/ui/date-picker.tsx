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
  React.useEffect(() => {
    if (open) setViewMonth(selected ?? startOfToday());
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [open]);

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

  return (
    <Popover open={open} onOpenChange={setOpen}>
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
        <div className="mb-2 flex items-center justify-between">
          <button
            type="button"
            onClick={() => setViewMonth((m) => addMonths(m, -1))}
            className="inline-flex size-7 items-center justify-center rounded-md text-foreground transition-colors hover:bg-muted"
            aria-label="Previous month"
          >
            <ChevronLeft className="size-4" />
          </button>
          <span className="text-sm font-semibold text-foreground">
            {format(viewMonth, "MMMM yyyy")}
          </span>
          <button
            type="button"
            onClick={() => setViewMonth((m) => addMonths(m, 1))}
            className="inline-flex size-7 items-center justify-center rounded-md text-foreground transition-colors hover:bg-muted"
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
