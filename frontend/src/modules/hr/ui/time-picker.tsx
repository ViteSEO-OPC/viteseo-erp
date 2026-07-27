"use client";

import * as React from "react";
import { ChevronDown, ChevronUp, Clock } from "lucide-react";

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

type Segment = "hour" | "minute";

/**
 * Time picker that replaces the native `<input type="time">`. Value contract is
 * identical — "HH:MM" in 24h — so it's a drop-in; `onChange` returns the string.
 * The trigger shows a friendly 12h label; the popover is a digital clock you edit
 * directly: type the digits, or step each field with the chevrons / arrow keys.
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
  /** Minute increment used when stepping (typed values keep full precision). */
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

  const hourRef = React.useRef<HTMLInputElement>(null);
  const minuteRef = React.useRef<HTMLInputElement>(null);
  const periodRef = React.useRef<HTMLButtonElement>(null);

  /** Half-typed digits, held only while a field has focus ("1" on the way to "12"). */
  const [draft, setDraft] = React.useState<{ seg: Segment; text: string } | null>(null);

  const step = Math.max(1, Math.min(60, minuteStep));

  function emit(next: { h12?: number; minute?: number; period?: "AM" | "PM" }) {
    const nh = next.h12 ?? h12;
    const nm = next.minute ?? minute;
    const np = next.period ?? period;
    onChange(`${pad(to24(nh, np))}:${pad(nm)}`);
  }

  /** First interaction on an empty field commits the 12:00 AM default, nothing more. */
  function ensureValue(): boolean {
    if (parsed) return true;
    emit({});
    return false;
  }

  function stepHour(delta: number) {
    setDraft(null);
    if (!ensureValue()) return;
    emit({ h12: ((h12 - 1 + delta + 12) % 12) + 1 });
  }

  function stepMinute(delta: number) {
    setDraft(null);
    if (!ensureValue()) return;
    // Snap to the step grid, so an off-step minute lands on a round value first.
    const raw =
      delta > 0 ? (Math.floor(minute / step) + 1) * step : Math.ceil(minute / step) * step - step;
    emit({ minute: ((raw % 60) + 60) % 60 });
  }

  function togglePeriod(next?: "AM" | "PM") {
    setDraft(null);
    if (!ensureValue()) return;
    emit({ period: next ?? (period === "AM" ? "PM" : "AM") });
  }

  /**
   * Digit entry for one field. Commits as soon as the typed number is valid and
   * moves on once the field can't take another digit — like a digital clock.
   */
  function typeDigits(seg: Segment, raw: string) {
    const digits = raw.replace(/\D/g, "");
    if (!digits) {
      setDraft({ seg, text: "" });
      return;
    }

    const max = seg === "hour" ? 12 : 59;
    let text = digits.length > 2 ? digits.slice(-1) : digits;
    let n = Number(text);
    // A two-digit overrun restarts from the newest digit ("13" in the hour → "3").
    if (text.length === 2 && (n > max || (seg === "hour" && n < 1))) {
      text = text.slice(-1);
      n = Number(text);
    }

    if (seg === "hour" ? n >= 1 : true) emit(seg === "hour" ? { h12: n } : { minute: n });

    // Done when a second digit can't change the value any more.
    const complete = text.length === 2 || n > (seg === "hour" ? 1 : 5);
    if (complete) {
      setDraft(null);
      if (seg === "hour") {
        minuteRef.current?.focus();
        minuteRef.current?.select();
      } else {
        periodRef.current?.focus();
      }
      return;
    }
    setDraft({ seg, text });
  }

  function onFieldKeyDown(seg: Segment, event: React.KeyboardEvent<HTMLInputElement>) {
    switch (event.key) {
      case "ArrowUp":
        event.preventDefault();
        if (seg === "hour") stepHour(1);
        else stepMinute(1);
        return;
      case "ArrowDown":
        event.preventDefault();
        if (seg === "hour") stepHour(-1);
        else stepMinute(-1);
        return;
      case "ArrowRight":
        event.preventDefault();
        setDraft(null);
        if (seg === "hour") {
          minuteRef.current?.focus();
          minuteRef.current?.select();
        } else {
          periodRef.current?.focus();
        }
        return;
      case "ArrowLeft":
        event.preventDefault();
        setDraft(null);
        if (seg === "minute") {
          hourRef.current?.focus();
          hourRef.current?.select();
        }
        return;
      case "a":
      case "A":
        event.preventDefault();
        togglePeriod("AM");
        return;
      case "p":
      case "P":
        event.preventDefault();
        togglePeriod("PM");
        return;
      case "Enter":
        event.preventDefault();
        setOpen(false);
        return;
      default:
    }
  }

  const hourText = draft?.seg === "hour" ? draft.text : parsed ? pad(h12) : "--";
  const minuteText = draft?.seg === "minute" ? draft.text : parsed ? pad(minute) : "--";

  const stepperClass =
    "inline-flex size-6 items-center justify-center rounded-md text-muted-foreground transition-colors hover:bg-muted hover:text-foreground";
  const fieldClass = cn(
    "h-12 w-14 rounded-lg border border-input bg-muted/40 text-center text-2xl font-semibold tabular-nums text-foreground outline-none transition-colors",
    "focus:border-ring focus:ring-3 focus:ring-ring/50",
    !parsed && "text-muted-foreground",
  );

  return (
    <Popover
      open={open}
      onOpenChange={(next) => {
        if (!next) setDraft(null); // drop any half-typed digits when it closes
        setOpen(next);
      }}
    >
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
      <PopoverContent
        className="p-3"
        onOpenAutoFocus={(event) => {
          event.preventDefault();
          hourRef.current?.focus();
          hourRef.current?.select();
        }}
      >
        <div className="flex items-start justify-center gap-1.5">
          {/* Hour */}
          <div className="flex flex-col items-center gap-1">
            <button
              type="button"
              className={stepperClass}
              onClick={() => stepHour(1)}
              aria-label="Increase hour"
              tabIndex={-1}
            >
              <ChevronUp className="size-4" />
            </button>
            <input
              ref={hourRef}
              type="text"
              inputMode="numeric"
              autoComplete="off"
              value={hourText}
              aria-label="Hour"
              className={fieldClass}
              onFocus={(event) => {
                setDraft(null);
                event.currentTarget.select();
              }}
              onBlur={() => setDraft(null)}
              onChange={(event) => typeDigits("hour", event.target.value)}
              onKeyDown={(event) => onFieldKeyDown("hour", event)}
            />
            <button
              type="button"
              className={stepperClass}
              onClick={() => stepHour(-1)}
              aria-label="Decrease hour"
              tabIndex={-1}
            >
              <ChevronDown className="size-4" />
            </button>
          </div>

          <span className="pt-7 text-2xl font-semibold text-muted-foreground select-none">:</span>

          {/* Minute */}
          <div className="flex flex-col items-center gap-1">
            <button
              type="button"
              className={stepperClass}
              onClick={() => stepMinute(1)}
              aria-label="Increase minute"
              tabIndex={-1}
            >
              <ChevronUp className="size-4" />
            </button>
            <input
              ref={minuteRef}
              type="text"
              inputMode="numeric"
              autoComplete="off"
              value={minuteText}
              aria-label="Minute"
              className={fieldClass}
              onFocus={(event) => {
                setDraft(null);
                event.currentTarget.select();
              }}
              onBlur={() => setDraft(null)}
              onChange={(event) => typeDigits("minute", event.target.value)}
              onKeyDown={(event) => onFieldKeyDown("minute", event)}
            />
            <button
              type="button"
              className={stepperClass}
              onClick={() => stepMinute(-1)}
              aria-label="Decrease minute"
              tabIndex={-1}
            >
              <ChevronDown className="size-4" />
            </button>
          </div>

          {/* AM / PM */}
          <div className="ml-1 flex flex-col items-center gap-1">
            <span className="size-6" aria-hidden />
            <button
              ref={periodRef}
              type="button"
              aria-label="AM or PM"
              className={cn(
                "h-12 w-12 rounded-lg border border-input text-sm font-semibold outline-none transition-colors focus-visible:border-ring focus-visible:ring-3 focus-visible:ring-ring/50",
                parsed
                  ? "bg-brand text-brand-foreground"
                  : "bg-muted/40 text-muted-foreground hover:bg-muted",
              )}
              onClick={() => togglePeriod()}
              onKeyDown={(event) => {
                if (event.key === "ArrowUp" || event.key === "ArrowDown") {
                  event.preventDefault();
                  togglePeriod();
                } else if (event.key === "a" || event.key === "A") {
                  event.preventDefault();
                  togglePeriod("AM");
                } else if (event.key === "p" || event.key === "P") {
                  event.preventDefault();
                  togglePeriod("PM");
                } else if (event.key === "ArrowLeft") {
                  event.preventDefault();
                  minuteRef.current?.focus();
                  minuteRef.current?.select();
                } else if (event.key === "Enter") {
                  event.preventDefault();
                  setOpen(false);
                }
              }}
            >
              {parsed ? period : "--"}
            </button>
            <span className="size-6" aria-hidden />
          </div>
        </div>

        <div className="mt-3 flex items-center justify-between gap-2 border-t border-border pt-2.5">
          <button
            type="button"
            className="rounded-md px-2 py-1 text-xs font-medium text-muted-foreground transition-colors hover:bg-muted hover:text-foreground"
            onClick={() => {
              const now = new Date();
              setDraft(null);
              onChange(`${pad(now.getHours())}:${pad(now.getMinutes())}`);
            }}
          >
            Now
          </button>
          <button
            type="button"
            className="rounded-md bg-brand px-3 py-1 text-xs font-semibold text-brand-foreground transition-opacity hover:opacity-90"
            onClick={() => setOpen(false)}
          >
            Done
          </button>
        </div>
      </PopoverContent>
    </Popover>
  );
}
