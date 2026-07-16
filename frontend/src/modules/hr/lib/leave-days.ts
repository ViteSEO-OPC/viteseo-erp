// Client-side preview of leave days — MUST mirror the server (LeaveService):
// weekdays (Mon–Fri) inclusive; half-day = 0.5 (single day). The server
// recomputes and enforces this; the 422 is the real gate (plan §5.5c).
export function computeLeaveDays(
  start: string,
  end: string,
  isHalfDay: boolean,
): number {
  if (!start || !end) return 0;
  const startDate = new Date(start + "T00:00:00");
  const endDate = new Date(end + "T00:00:00");
  if (endDate < startDate) return 0;

  if (isHalfDay) {
    // Only valid when it's a single day; the form enforces end = start.
    return start === end ? 0.5 : 0;
  }

  let days = 0;
  for (
    let d = new Date(startDate);
    d <= endDate;
    d.setDate(d.getDate() + 1)
  ) {
    const dow = d.getDay(); // 0 = Sun, 6 = Sat
    if (dow !== 0 && dow !== 6) days++;
  }
  return days;
}
