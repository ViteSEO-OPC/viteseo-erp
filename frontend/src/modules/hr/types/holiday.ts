export const HOLIDAY_TYPES = [
  { value: "regular", label: "Regular" },
  { value: "special_non_working", label: "Special (Non-Working)" },
  { value: "special_working", label: "Special Working" },
] as const;

// A short country list for the picker (Nager.Date supports ~150 ISO-2 codes).
export const COUNTRIES = [
  { code: "PH", name: "Philippines" },
  { code: "CN", name: "China" },
  { code: "US", name: "United States" },
  { code: "SG", name: "Singapore" },
  { code: "JP", name: "Japan" },
  { code: "HK", name: "Hong Kong" },
  { code: "MY", name: "Malaysia" },
  { code: "AU", name: "Australia" },
  { code: "GB", name: "United Kingdom" },
  { code: "IN", name: "India" },
] as const;

export interface Holiday {
  id: number;
  name: string;
  date: string;
  type: string | null; // null = unclassified (HR must set it)
  source: string;
  needs_review: boolean;
  is_weekend: boolean;
}

export interface HolidaySet {
  id: number;
  country_code: string;
  year: number;
  source: string;
  refreshed_at: string | null;
  holidays_count: number;
  holidays?: Holiday[];
  created_at: string;
}

export interface HolidayInput {
  name: string;
  date: string;
  type: string;
}

export function holidayTypeLabel(t: string | null): string {
  if (!t) return "Unclassified";
  return HOLIDAY_TYPES.find((x) => x.value === t)?.label ?? t;
}

export function countryName(code: string): string {
  return COUNTRIES.find((c) => c.code === code)?.name ?? code;
}

/**
 * An HR-declared non-working day — a typhoon, a flood, a company shutdown.
 *
 * Deliberately NOT a Holiday. A holiday is a legal calendar date with a class that decides the
 * premium a worked day earns; this is the company cancelling one day, and the only thing it
 * decides is whether the day is still paid. `status` is what the roster and payroll read, and
 * it comes from the server so no screen re-derives it from the boolean.
 */
export interface WorkSuspension {
  id: number;
  date: string;
  name: string;
  is_paid: boolean;
  note: string | null;
  status: "suspended_paid" | "suspended_unpaid";
  declared_by: string | null;
  created_at: string;
  updated_at: string;
}

export interface WorkSuspensionInput {
  date: string;
  name: string;
  is_paid: boolean;
  note?: string | null;
}
