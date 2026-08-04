import { formatInTimeZone, fromZonedTime } from "date-fns-tz";

/**
 * The single business timezone for the CRM. The backend stores UTC; the Planner displays and
 * accepts input in this zone. Centralized here so moving to per-user/multi-timezone later is a
 * one-constant change (swap the literal for a resolved value). Currently Asia/Manila.
 */
export const BUSINESS_TIMEZONE = "Asia/Manila";

/** Format a UTC ISO instant (or Date) for display in the business timezone. */
export function formatBusiness(iso: string | Date, fmt: string): string {
  return formatInTimeZone(iso, BUSINESS_TIMEZONE, fmt);
}

/** `yyyy-MM-dd` day key for an instant, bucketed in the business timezone. */
export function businessDateKey(iso: string | Date): string {
  return formatInTimeZone(iso, BUSINESS_TIMEZONE, "yyyy-MM-dd");
}

/**
 * Convert a business-timezone wall-clock value (from an `<input type="datetime-local">`, e.g.
 * "2026-07-16T14:30") into a UTC ISO string for the API — the user typed a Manila time; this is
 * the instant it refers to.
 */
export function businessLocalToUtcIso(local: string): string {
  return fromZonedTime(local, BUSINESS_TIMEZONE).toISOString();
}

/** Convert a UTC ISO instant into a value for an `<input type="datetime-local">` shown in the
 *  business timezone (so the user edits the Manila wall-clock time). */
export function utcIsoToBusinessLocalInput(iso: string): string {
  return formatInTimeZone(iso, BUSINESS_TIMEZONE, "yyyy-MM-dd'T'HH:mm");
}

/** Start of "today" in the business timezone, as a UTC Date — for range comparisons. */
export function startOfBusinessTodayUtc(): Date {
  const todayKey = formatInTimeZone(new Date(), BUSINESS_TIMEZONE, "yyyy-MM-dd");
  return fromZonedTime(`${todayKey}T00:00:00`, BUSINESS_TIMEZONE);
}

/** Now, as a `datetime-local` value in the business timezone — the `min` for a scheduling input. */
export function businessNowLocalInput(): string {
  return formatInTimeZone(new Date(), BUSINESS_TIMEZONE, "yyyy-MM-dd'T'HH:mm");
}

/**
 * A `datetime-local` value N days from today at a given business-timezone wall-clock time.
 *
 * Day arithmetic is done on the business-timezone DATE KEY rather than on a UTC instant, so
 * "tomorrow 09:00" means 09:00 Manila regardless of the viewer's device timezone or a DST-style
 * shift in between — the same rule the Planner uses for its day boundaries.
 */
export function businessDaysAheadLocalInput(days: number, hour: number, minute = 0): string {
  const todayKey = formatInTimeZone(new Date(), BUSINESS_TIMEZONE, "yyyy-MM-dd");
  const [y, m, d] = todayKey.split("-").map(Number);
  // UTC arithmetic on a bare date is calendar-safe: no zone is involved until we format back.
  const target = new Date(Date.UTC(y, m - 1, d));
  target.setUTCDate(target.getUTCDate() + days);

  const key = target.toISOString().slice(0, 10);
  const hh = String(hour).padStart(2, "0");
  const mm = String(minute).padStart(2, "0");

  return `${key}T${hh}:${mm}`;
}

/** Days until the next Monday in the business timezone (always ≥ 1, so it is never "today"). */
export function businessDaysUntilNextMonday(): number {
  // 1 = Monday … 7 = Sunday, in the business timezone.
  const isoDow = Number(formatInTimeZone(new Date(), BUSINESS_TIMEZONE, "i"));

  return ((8 - isoDow) % 7) || 7;
}
