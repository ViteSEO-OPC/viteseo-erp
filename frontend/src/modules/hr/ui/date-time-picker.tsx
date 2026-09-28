"use client";

import * as React from "react";

import { cn } from "@/lib/utils";
import { DatePicker } from "@/modules/hr/ui/date-picker";
import { TimePicker } from "@/modules/hr/ui/time-picker";

/** Split "YYYY-MM-DDTHH:MM" into its date and time parts. */
function split(value: string | null | undefined): { date: string; time: string } {
  if (!value) return { date: "", time: "" };
  const [date = "", time = ""] = value.split("T");
  // Trim seconds if present ("HH:MM:SS" → "HH:MM").
  return { date, time: time.slice(0, 5) };
}

/**
 * Replaces the native `<input type="datetime-local">` with a styled date picker
 * plus time picker side by side. Value contract matches the native control —
 * "YYYY-MM-DDTHH:MM" — and `onChange` returns that string, but only once BOTH a
 * date and a time are chosen (partial picks are held locally so the UI keeps them
 * without emitting an invalid value).
 */
export function DateTimePicker({
  value,
  onChange,
  disabled,
  min,
  max,
  datePlaceholder = "Pick a date",
  timePlaceholder = "Select time",
  className,
  "aria-invalid": ariaInvalid,
}: {
  value: string | null | undefined;
  onChange: (value: string) => void;
  disabled?: boolean;
  /** Earliest selectable date, "yyyy-MM-dd". */
  min?: string;
  /** Latest selectable date, "yyyy-MM-dd". */
  max?: string;
  datePlaceholder?: string;
  timePlaceholder?: string;
  className?: string;
  "aria-invalid"?: boolean;
}) {
  const [parts, setParts] = React.useState(() => split(value));

  // Keep local parts in sync when the external value changes — during render rather than
  // in an effect.
  const [syncedValue, setSyncedValue] = React.useState(value);
  if (value !== syncedValue) {
    setSyncedValue(value);
    setParts(split(value));
  }

  function update(next: { date?: string; time?: string }) {
    const merged = { date: next.date ?? parts.date, time: next.time ?? parts.time };
    setParts(merged);
    onChange(merged.date && merged.time ? `${merged.date}T${merged.time}` : "");
  }

  return (
    <div className={cn("flex flex-col gap-2 sm:flex-row", className)}>
      <DatePicker
        value={parts.date}
        onChange={(date) => update({ date })}
        disabled={disabled}
        min={min}
        max={max}
        placeholder={datePlaceholder}
        aria-invalid={ariaInvalid}
        className="sm:flex-1"
      />
      <TimePicker
        value={parts.time}
        onChange={(time) => update({ time })}
        disabled={disabled}
        placeholder={timePlaceholder}
        aria-invalid={ariaInvalid}
        className="sm:w-40"
      />
    </div>
  );
}
