"use client";

import * as React from "react";
import { Clock } from "lucide-react";

import { cn } from "@/lib/utils";
import { Popover, PopoverTrigger, PopoverContent } from "@/modules/hr/ui/popover";

const pad = (n: number) => String(n).padStart(2, "0");

interface TimeParts {
  h24: number;
  m: number;
}

/** Parse "HH:MM" (24h) → parts, or null. */
function parseTime(value: string | null | undefined): TimeParts | null {
  if (!value) return null;
  const match = /^(\d{1,2}):(\d{2})$/.exec(value.trim());
  if (!match) return null;
  const h24 = Number(match[1]);
  const m = Number(match[2]);
  if (h24 < 0 || h24 > 23 || m < 0 || m > 59) return null;
  return { h24, m };
}

const to12 = (h24: number) => ((h24 + 11) % 12) + 1;
const to24 = (h12: number, period: "AM" | "PM") =>
  period === "AM" ? h12 % 12 : (h12 % 12) + 12;

function label12(parts: TimeParts): string {
  const period = parts.h24 >= 12 ? "PM" : "AM";
  return `${to12(parts.h24)}:${pad(parts.m)} ${period}`;
}

const HOURS = Array.from({ length: 12 }, (_, i) => i + 1); // 1..12

/**
 * Time picker that replaces the native `<input type="time">`. Value contract is
 * identical — "HH:MM" in 24h — so it's a drop-in; `onChange` returns the string.
 * The trigger shows a friendly 12h label; the popover has hour / minute / AM-PM
 * columns for full-minute precision (custom buttons, so no nested-portal issues).
 */
export function TimePicker({
  value,
  onChange,
  placeholder = "Select time",
  disabled,
  minuteStep = 1,
  id,
  className,
  "aria-label": ariaLabel,
  "aria-invalid": ariaInvalid,
}: {
  value: string | null | undefined;
  onChange: (value: string) => void;
  placeholder?: string;
  disabled?: boolean;
  /** Minute increments listed in the picker (value precision is unaffected). */
  minuteStep?: number;
  id?: string;
  className?: string;
  "aria-label"?: string;
  "aria-invalid"?: boolean;
}) {
  const [open, setOpen] = React.useState(false);
  const parsed = parseTime(value);

  const h12 = parsed ? to12(parsed.h24) : 12;
  const minute = parsed ? parsed.m : 0;
  const period: "AM" | "PM" = parsed && parsed.h24 >= 12 ? "PM" : "AM";

  const minutes = React.useMemo(() => {
    const step = Math.max(1, Math.min(60, minuteStep));
    const list = Array.from({ length: Math.ceil(60 / step) }, (_, i) => i * step);
    // Ensure the current minute is always selectable even if off-step.
    if (parsed && !list.includes(parsed.m)) list.push(parsed.m);
    return list.sort((a, b) => a - b);
  }, [minuteStep, parsed]);

  function emit(next: { h12?: number; minute?: number; period?: "AM" | "PM" }) {
    const nh = next.h12 ?? h12;
    const nm = next.minute ?? minute;
    const np = next.period ?? period;
    onChange(`${pad(to24(nh, np))}:${pad(nm)}`);
  }

  const colClass =
    "flex max-h-[210px] flex-col gap-0.5 overflow-y-auto px-1 first:pl-0 last:pr-0";
  const cellClass = (active: boolean) =>
    cn(
      "rounded-md px-2.5 py-1 text-sm text-center transition-colors",
      active ? "bg-brand font-semibold text-brand-foreground" : "hover:bg-muted text-foreground",
    );

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
        <span className={cn("truncate", !parsed && "text-muted-foreground")}>
          {parsed ? label12(parsed) : placeholder}
        </span>
        <Clock className="size-4 shrink-0 opacity-50" />
      </PopoverTrigger>
      <PopoverContent className="p-2">
        <div className="flex divide-x divide-border">
          <div className={colClass} role="listbox" aria-label="Hour">
            {HOURS.map((h) => (
              <button
                key={h}
                type="button"
                onClick={() => emit({ h12: h })}
                className={cellClass(!!parsed && h === h12)}
              >
                {h}
              </button>
            ))}
          </div>
          <div className={colClass} role="listbox" aria-label="Minute">
            {minutes.map((m) => (
              <button
                key={m}
                type="button"
                onClick={() => emit({ minute: m })}
                className={cellClass(!!parsed && m === minute)}
              >
                {pad(m)}
              </button>
            ))}
          </div>
          <div className={colClass} role="listbox" aria-label="AM or PM">
            {(["AM", "PM"] as const).map((p) => (
              <button
                key={p}
                type="button"
                onClick={() => emit({ period: p })}
                className={cellClass(!!parsed && p === period)}
              >
                {p}
              </button>
            ))}
          </div>
        </div>
      </PopoverContent>
    </Popover>
  );
}
