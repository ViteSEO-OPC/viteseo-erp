import type { MyAttendanceDay, MyDayStatus } from "@/modules/hr/types/my-attendance";

/**
 * How every day of the employee's own month reads — one label and one colour per status, shared
 * by the calendar, the day table and the legend so the three cannot describe the same day
 * differently.
 *
 * The two suspension flavours share a chip on purpose: to the eye they are the same fact (HR
 * cancelled work), and which of them costs a day's pay is said in words on the row rather than
 * left to a colour nobody can decode.
 */
export const MY_DAY_STATUS: Record<MyDayStatus, { label: string; chip: string; dot: string }> = {
  present: { label: "Present", chip: "bg-success-subtle text-success", dot: "bg-success" },
  late: { label: "Late", chip: "bg-warning-subtle text-warning", dot: "bg-warning" },
  absent: { label: "Absent", chip: "bg-danger-subtle text-danger", dot: "bg-danger" },
  on_leave: { label: "On leave", chip: "bg-info-subtle text-info", dot: "bg-info" },
  half_day: {
    label: "Half day",
    chip: "bg-severity-orange-subtle text-severity-orange",
    dot: "bg-severity-orange",
  },
  holiday: { label: "Holiday", chip: "bg-violet-subtle text-violet", dot: "bg-violet" },
  suspended_paid: { label: "Work suspended", chip: "bg-brand-subtle text-brand", dot: "bg-brand" },
  suspended_unpaid: {
    label: "Work suspended",
    chip: "bg-brand-subtle text-brand",
    dot: "bg-brand",
  },
  rest_day: {
    label: "Rest day",
    chip: "bg-muted text-muted-foreground",
    dot: "bg-muted-foreground",
  },
  not_employed: {
    label: "Not employed",
    chip: "bg-muted text-muted-foreground/60",
    dot: "bg-muted-foreground",
  },
  upcoming: {
    label: "Upcoming",
    chip: "bg-muted text-muted-foreground/60",
    dot: "bg-muted-foreground",
  },
};

/** The statuses worth a legend — the rest are days nobody was expected in. */
export const MY_DAY_LEGEND: MyDayStatus[] = [
  "present",
  "late",
  "absent",
  "on_leave",
  "half_day",
  "holiday",
  "suspended_paid",
];

/** Minutes as "45m" / "1h 30m" — never a bare decimal of an hour, which nobody reads as time. */
export function formatMinutes(minutes: number): string {
  if (minutes <= 0) return "—";
  const hours = Math.floor(minutes / 60);
  const rest = minutes % 60;
  if (hours === 0) return `${rest}m`;
  return rest === 0 ? `${hours}h` : `${hours}h ${rest}m`;
}

/** Hours without a pointless ".00": 8, 7.5. */
export function formatHours(hours: number): string {
  if (!hours) return "—";
  return Number.isInteger(hours) ? `${hours}h` : `${hours.toFixed(2).replace(/0$/, "")}h`;
}

/** One thing that happened on a day, tagged by what kind of fact it is. */
export interface DayHighlight {
  kind:
    | "leave"
    | "holiday"
    | "suspension"
    | "half_day"
    | "late"
    | "undertime"
    | "early_out"
    | "overtime"
    | "night"
    | "rest_day"
    | "note";
  text: string;
}

/**
 * Everything that happened on a day, in reading order — the calendar's tooltip and the table's
 * detail column both come from here, so a day never says two different things on one screen.
 *
 * Each entry is TAGGED rather than just worded, because the table drops the three facts its own
 * numeric columns already carry. Matching on the wording instead would have quietly eaten an HR
 * note ending in "late" — the one sentence on the row a human actually wrote.
 */
export function dayHighlights(day: MyAttendanceDay): DayHighlight[] {
  const parts: DayHighlight[] = [];

  if (day.leave_type) {
    parts.push({
      kind: "leave",
      text: day.leave_type + (day.leave_is_paid === false ? " (unpaid)" : ""),
    });
  }
  if (day.holiday) parts.push({ kind: "holiday", text: day.holiday.name });
  if (day.suspension) {
    parts.push({
      kind: "suspension",
      text: `${day.suspension.name} — ${day.suspension.is_paid ? "still paid" : "unpaid"}`,
    });
  }
  if (day.half_day_off) {
    parts.push({
      kind: "half_day",
      text:
        day.half_day_off === "BOTH"
          ? "Whole day approved off"
          : `${day.half_day_off === "AM" ? "Morning" : "Afternoon"} approved off`,
    });
  }
  if (day.late_minutes > 0) {
    parts.push({ kind: "late", text: `${formatMinutes(day.late_minutes)} late` });
  }
  if (day.undertime_minutes > 0) {
    parts.push({ kind: "undertime", text: `${formatMinutes(day.undertime_minutes)} undertime` });
  }
  if (day.early_out_time) {
    parts.push({ kind: "early_out", text: `Approved early-out at ${day.early_out_time}` });
  }
  if (day.overtime_minutes > 0) {
    parts.push({ kind: "overtime", text: `${formatMinutes(day.overtime_minutes)} overtime` });
  }
  if (day.night_minutes > 0) {
    parts.push({ kind: "night", text: `${formatMinutes(day.night_minutes)} night differential` });
  }
  if (day.rest_day_minutes > 0) {
    parts.push({ kind: "rest_day", text: `${formatMinutes(day.rest_day_minutes)} rest-day work` });
  }
  if (day.note) parts.push({ kind: "note", text: day.note });

  return parts;
}

/** The whole day in one line — every fact, for a tooltip. */
export function dayHighlightLine(day: MyAttendanceDay): string {
  return dayHighlights(day)
    .map((h) => h.text)
    .join(" · ");
}
