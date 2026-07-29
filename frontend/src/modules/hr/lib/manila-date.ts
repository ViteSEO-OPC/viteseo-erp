// Calendar-day helpers in Asia/Manila. Datetimes are stored UTC, but day
// boundaries are Manila's (build plan §5.5b) — so "today" for a date input has to
// be resolved in Manila, not in the browser's zone.

/** Today in Manila as "yyyy-MM-dd" — the DatePicker value contract. */
export function manilaToday(): string {
  return new Date().toLocaleDateString("en-CA", { timeZone: "Asia/Manila" });
}

/** The current Manila calendar year. */
export function manilaYear(): number {
  return Number(manilaToday().slice(0, 4));
}

/**
 * A moment as the Manila wall-clock "HH:MM", so it can be compared against a schedule's
 * own times with a plain string compare. Takes the instant explicitly, which lets a
 * ticking component pass its own clock and re-evaluate as it advances.
 */
export function manilaHm(at: number = Date.now()): string {
  return new Date(at).toLocaleTimeString("en-GB", {
    timeZone: "Asia/Manila",
    hour: "2-digit",
    minute: "2-digit",
    hour12: false,
  });
}

/**
 * A schedule time ("09:00", already Manila wall-clock) as "9:00 AM". Not a datetime —
 * use formatTimeManila for those, which converts from UTC first.
 */
export function format12Hour(hhmm: string | null | undefined): string {
  if (!hhmm) return "—";
  const [h, m] = hhmm.split(":").map(Number);
  const period = h >= 12 ? "PM" : "AM";
  return `${((h + 11) % 12) + 1}:${String(m).padStart(2, "0")} ${period}`;
}
