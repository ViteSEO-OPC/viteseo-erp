/**
 * Custom email-template purposes and tones: the rules the add dialog checks before sending, mirroring
 * EmailTemplateOptionService (PHP). The server re-checks everything — including collisions with automation keys the
 * browser does not know — and its message is the one shown. Free of React and the DOM so
 * scripts/email-template-gates can drive it; the gates read the PHP constants and fail on drift.
 */
import type { TemplateOptionType } from "@/modules/crm/features/email-templates/types/email-template-types";

/** EmailTemplateOptionService::MIN_LENGTH / MAX_LENGTH, in characters. */
export const OPTION_MIN_LENGTH = 2;

export const OPTION_MAX_LENGTH = 60;

/** The Select value of "+ Add custom …". Never a real purpose or tone: it contains characters a value may not. */
export const ADD_CUSTOM_OPTION = "{add-custom-option}";

/** EmailTemplateOptionService::EMPTY, LENGTH and INVALID, word for word. */
export const OPTION_EMPTY = "Enter a name.";

export const OPTION_LENGTH = "Use between 2 and 60 characters.";

export const OPTION_INVALID =
  "Use letters, numbers, spaces and simple punctuation, with no braces, angle brackets, backslashes, backticks or control characters.";

/** Trimmed, every run of whitespace one space: the value as it is stored and shown. */
export function cleanOptionValue(value: string): string {
  return value.replace(/[\p{Z}\s]+/gu, " ").trim();
}

/** What duplicates are compared by: lower case, with spaces, underscores and hyphens alike. */
export function normalizeOptionValue(value: string): string {
  return value.replace(/[\p{Z}\s_-]+/gu, " ").trim().toLowerCase();
}

/**
 * Why a value cannot be added — or null. `existing` is every value already in that list (built-in and custom); a
 * value equal to one of them however spelled is refused here, and the server additionally refuses automation keys.
 */
export function optionValueProblem(raw: string, type: TemplateOptionType, existing: readonly string[]): string | null {
  if (raw.trim() === "") return OPTION_EMPTY;
  if (/\p{C}/u.test(raw) || /[{}<>\\`]/.test(raw) || !/[\p{L}\p{N}]/u.test(raw)) return OPTION_INVALID;

  const value = cleanOptionValue(raw);
  const length = [...value].length;
  if (length < OPTION_MIN_LENGTH || length > OPTION_MAX_LENGTH) return OPTION_LENGTH;

  const normalized = normalizeOptionValue(value);
  const match = existing.find((item) => normalizeOptionValue(item) === normalized);
  return match === undefined ? null : `“${match}” is already in the ${type} list.`;
}
