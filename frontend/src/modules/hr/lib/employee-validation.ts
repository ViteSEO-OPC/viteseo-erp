import { z } from "zod";

import { manilaToday } from "@/modules/hr/lib/manila-date";

/**
 * Field-level validators shared by the employee record's inline section forms and the
 * "Add employee" dialog, so both surfaces reject the same input. These mirror the
 * server rules in EmployeeProfileRules — keep the two in step.
 */

/** Digits plus the separators people actually type: +63 917 555 0142, (02) 8123-4567. */
const PHONE_RE = /^[0-9+()\-.\s]{7,}$/;

export const requiredText = (label: string, max = 255) =>
  z.string().trim().min(1, `${label} is required`).max(max, `${label} must be ${max} characters or fewer`);

export const optionalText = (max = 255) =>
  z.string().trim().max(max, `Must be ${max} characters or fewer`);

export const emailField = z
  .string()
  .trim()
  .min(1, "Email address is required")
  .max(255, "Email address must be 255 characters or fewer")
  .pipe(z.email("Enter a valid email address, e.g. juan@viteseo.ph"));

export const optionalPhone = (label = "Contact number") =>
  z
    .string()
    .trim()
    .max(50)
    .refine((v) => v === "" || PHONE_RE.test(v), `${label} may only contain digits, spaces, + ( ) - .`);

/** A date that cannot be in the future, e.g. a birthday. */
export const optionalPastDate = (label: string) =>
  z
    .string()
    .trim()
    .refine((v) => v === "" || v <= manilaToday(), `${label} cannot be in the future`);

export const requiredDate = (label: string) =>
  z.string().trim().min(1, `${label} is required`);

const isMoney = (v: string) => {
  const n = Number(v);
  return v.trim() !== "" && Number.isFinite(n) && n >= 0;
};

export const optionalMoney = (label: string) =>
  z.string().trim().refine((v) => v === "" || isMoney(v), `${label} must be an amount of 0 or more`);

export const requiredMoney = (label: string) =>
  z
    .string()
    .trim()
    .min(1, `${label} is required`)
    .refine(isMoney, `${label} must be an amount of 0 or more`);

/** A picklist value, or "" when nothing is chosen. */
export const optionalChoice = (values: readonly string[], label: string) =>
  z.string().refine((v) => v === "" || values.includes(v), `Choose a valid ${label.toLowerCase()}`);

export const requiredChoice = (values: readonly string[], label: string) =>
  z.string().refine((v) => values.includes(v), `${label} is required`);

/** A whole count like "number of siblings"; blank means "not stated". */
export const optionalCount = (label: string, max = 50) =>
  z
    .string()
    .trim()
    .refine(
      (v) => v === "" || (/^\d+$/.test(v) && Number(v) <= max),
      `${label} must be a whole number between 0 and ${max}`,
    );

/**
 * Turn "" into null. A section save posts every field it owns, so a cleared box has to
 * send an explicit null to blank the column — omitting the key would leave it unchanged.
 */
export function orNull(v: string): string | null {
  return v.trim() === "" ? null : v.trim();
}

/** Same, for the numeric columns. */
export function numberOrNull(v: string): number | null {
  return v.trim() === "" ? null : Number(v);
}
