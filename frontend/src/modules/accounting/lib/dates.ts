/**
 * Business dates for Accounting are Asia/Manila. `new Date().toISOString()` is
 * UTC — for a Manila user between midnight and 8 AM it yields YESTERDAY's date,
 * so every default period/date initializer must go through here instead.
 */
export function manilaToday(): string {
  // en-CA formats as YYYY-MM-DD.
  return new Intl.DateTimeFormat("en-CA", { timeZone: "Asia/Manila" }).format(new Date());
}

/** The current Manila month, YYYY-MM (default reporting period). */
export function manilaMonth(): string {
  return manilaToday().slice(0, 7);
}

/**
 * `date` (YYYY-MM-DD) plus a whole number of days, as YYYY-MM-DD.
 *
 * Built on UTC on purpose. The input is already a Manila calendar date, not an
 * instant, so this is calendar arithmetic — going through local time would let a
 * DST-less-but-offset browser shift the result by a day.
 */
export function addDays(date: string, days: number): string {
  const [y, m, d] = date.split("-").map(Number);
  if (!y || !m || !d) return date;

  const shifted = new Date(Date.UTC(y, m - 1, d));
  shifted.setUTCDate(shifted.getUTCDate() + days);

  return shifted.toISOString().slice(0, 10);
}
