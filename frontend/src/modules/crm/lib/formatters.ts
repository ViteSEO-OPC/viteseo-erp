export function formatActivityTimestamp(date: Date | string | null | undefined): string {
  if (!date) return "—";
  const d = typeof date === "string" ? new Date(date) : date;
  if (Number.isNaN(d.getTime())) return "—";
  const now = new Date();
  const isToday =
    d.getDate() === now.getDate() &&
    d.getMonth() === now.getMonth() &&
    d.getFullYear() === now.getFullYear();

  if (isToday) {
    return d.toLocaleTimeString("en-US", {
      hour: "numeric",
      minute: "2-digit",
      hour12: true,
    });
  }

  const month = d.toLocaleString("en-US", { month: "short" });
  const day = d.getDate();
  return `${month} ${day}`;
}

/**
 * Absolute date with the year — "Jun 27, 2026". Used by the Leads table, where rows span years and
 * the relative/short forms above become ambiguous. Additive: existing formatters are unchanged.
 */
export function formatDateLong(date: Date | string | null | undefined): string {
  if (!date) return "—";
  const d = typeof date === "string" ? new Date(date) : date;
  if (Number.isNaN(d.getTime())) return "—";
  return d.toLocaleDateString("en-US", { month: "short", day: "numeric", year: "numeric" });
}

/**
 * Absolute date AND time with the year — "Jun 27, 2026, 9:59 AM". For moments where both halves
 * matter and the value can be arbitrarily old: the Lead Details "Converted" line reads a Deal's
 * `converted_at`, which is frequently from a previous month or year, so the year-less forms above
 * would render it ambiguously. Same en-US style as {@link formatDateLong}.
 */
export function formatDateTimeLong(date: Date | string | null | undefined): string {
  if (!date) return "—";
  const d = typeof date === "string" ? new Date(date) : date;
  if (Number.isNaN(d.getTime())) return "—";
  return d.toLocaleString("en-US", {
    month: "short",
    day: "numeric",
    year: "numeric",
    hour: "numeric",
    minute: "2-digit",
    hour12: true,
  });
}

export function formatTimestamp(date: Date | string): string {
  const d = typeof date === "string" ? new Date(date) : date;
  const now = new Date();
  const diffMs = now.getTime() - d.getTime();
  const diffMins = Math.floor(diffMs / 60000);
  const diffHours = Math.floor(diffMins / 60);

  if (diffMins < 1) return "Just now";
  if (diffMins < 60) return `${diffMins}m ago`;
  if (diffHours < 24) return `${diffHours}h ago`;

  const month = d.toLocaleString("en-US", { month: "short" });
  const day = d.getDate();
  return `${month} ${day}`;
}

export function formatNumber(n: number): string {
  return n.toLocaleString("en-US");
}

