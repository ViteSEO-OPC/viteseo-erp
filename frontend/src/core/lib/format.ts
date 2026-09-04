// Shared formatters. Money is stored raw (decimal) on the API; format for
// display only (standards: Intl.NumberFormat en-PH).

export function formatPeso(amount: number | string): string {
  const n = typeof amount === "string" ? parseFloat(amount) : amount;
  if (Number.isNaN(n)) return "—";
  return new Intl.NumberFormat("en-PH", {
    style: "currency",
    currency: "PHP",
  }).format(n);
}

/**
 * Money in the accounting convention: negatives are shown in parentheses —
 * e.g. -1234 → "(₱1,234.00)" — instead of a minus sign. Use for signed figures
 * (variance, net income, net cash), keeping colour as a secondary cue only.
 */
export function formatPesoAccounting(amount: number | string): string {
  const n = typeof amount === "string" ? parseFloat(amount) : amount;
  if (Number.isNaN(n)) return "—";
  const formatted = formatPeso(Math.abs(n));
  return n < 0 ? `(${formatted})` : formatted;
}

export function formatDate(iso: string | null | undefined): string {
  if (!iso) return "—";
  return new Date(iso).toLocaleDateString("en-PH", {
    year: "numeric",
    month: "short",
    day: "numeric",
  });
}

// UTC ISO → "Mon DD, YYYY, HH:MM" in Manila (date + time together).
export function formatDateTime(iso: string | null | undefined): string {
  if (!iso) return "—";
  return new Date(iso).toLocaleString("en-PH", {
    timeZone: "Asia/Manila",
    year: "numeric",
    month: "short",
    day: "numeric",
    hour: "2-digit",
    minute: "2-digit",
  });
}

// Datetimes come from the API as UTC; always display in Manila (§5.5b).
export function formatTimeManila(iso: string | null | undefined): string {
  if (!iso) return "—";
  return new Date(iso).toLocaleTimeString("en-PH", {
    timeZone: "Asia/Manila",
    hour: "2-digit",
    minute: "2-digit",
  });
}

// UTC ISO → "HH:MM" (24h, Manila) for a <input type="time">.
export function toManilaTimeInput(iso: string | null | undefined): string {
  if (!iso) return "";
  return new Date(iso).toLocaleTimeString("en-GB", {
    timeZone: "Asia/Manila",
    hour: "2-digit",
    minute: "2-digit",
    hour12: false,
  });
}

/** Minutes → "H:MM" (e.g. 465 → "7:45"). */
export function formatHm(totalMinutes: number): string {
  const m = Math.max(0, Math.round(totalMinutes));
  return `${Math.floor(m / 60)}:${String(m % 60).padStart(2, "0")}`;
}

/**
 * A late duration in hours + minutes: 75 → "1h 15m", 45 → "45m", 120 → "2h".
 * 0 or negative → "" (caller decides whether to show anything).
 */
export function formatLateDuration(totalMinutes: number): string {
  const m = Math.max(0, Math.round(totalMinutes));
  if (m === 0) return "";
  const h = Math.floor(m / 60);
  const mins = m % 60;
  if (h === 0) return `${mins}m`;
  if (mins === 0) return `${h}h`;
  return `${h}h ${mins}m`;
}

/** Compact age since an ISO timestamp — "6h", "3d", "2w" — for "Pending N" labels. */
export function formatShortAge(iso: string | null | undefined): string {
  if (!iso) return "";
  const diffMs = Date.now() - new Date(iso).getTime();
  const min = Math.round(diffMs / 60000);
  if (min < 60) return `${Math.max(1, min)}m`;
  const hr = Math.round(min / 60);
  if (hr < 24) return `${hr}h`;
  const days = Math.round(hr / 24);
  if (days < 7) return `${days}d`;
  return `${Math.round(days / 7)}w`;
}

/** Rough "time ago" for activity feeds. */
export function formatRelative(iso: string | null | undefined): string {
  if (!iso) return "";
  const diffMs = Date.now() - new Date(iso).getTime();
  const min = Math.round(diffMs / 60000);
  if (min < 1) return "just now";
  if (min < 60) return `${min} min ago`;
  const hr = Math.round(min / 60);
  if (hr < 24) return `${hr} hr ago`;
  const days = Math.round(hr / 24);
  if (days === 1) return "yesterday";
  if (days < 7) return `${days} days ago`;
  return formatDate(iso);
}
