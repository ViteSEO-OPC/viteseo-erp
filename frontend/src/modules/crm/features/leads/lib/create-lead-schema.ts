import { z } from "zod";

import { requiredTrimmedString } from "@/core/forms/validation";

/**
 * Create Lead Wizard validation — the single source of truth for what each step requires.
 *
 * Mirrors `StoreLeadRequest` deliberately, field for field, because the wizard used to disagree with
 * it in both directions: `contact_name` is `required` server-side but carried no client marker or
 * gate (submitting produced a 422 on step 3 for a step 2 field), while Business Niche wore a red
 * asterisk the server never enforced (`industry` is nullable in every request class). The schema is
 * now the only place that answers "is this required?", so the asterisk, the Next gate and the
 * payload can no longer drift apart.
 *
 * Every value is a string ("" = not set); conversion to numbers happens when the payload is built,
 * matching the platform's existing form convention.
 */

/** Optional free text — allowed to be empty, bounded like its column. */
const optionalText = (max = 255) =>
  z.string().trim().max(max, `Must be ${max} characters or fewer`);

/** Optional email: empty is fine, but anything typed must be a real address. */
const optionalEmail = z
  .string()
  .trim()
  .max(255, "Email address must be 255 characters or fewer")
  .refine(
    (v) => v === "" || z.email().safeParse(v).success,
    "Enter a valid email address, e.g. alex@globaltech.com",
  );

/** Estimated deal value. Empty is fine; a value must be a non-negative number (server: numeric|min:0). */
const optionalAmount = z
  .string()
  .trim()
  .refine(
    (v) => v === "" || (Number.isFinite(Number(v)) && Number(v) >= 0),
    "Enter a valid amount of 0 or more",
  );

/**
 * `assignedTo` is the one conditionally-required field: Admin/Super Admin must name the owning BD,
 * while a BD creator is auto-assigned server-side and never sees the selector. Same condition as
 * `StoreLeadRequest`'s `Rule::requiredIf(... ! hasRole('crm_bd'))`, so the two cannot disagree.
 */
/**
 * `assignedTo` is the only field that ever varied by role, and it no longer does — so this takes no
 * arguments. It kept a manager-only `min(1)` until unassigned leads were allowed; leaving the
 * parameter behind would imply a role-dependence the schema no longer has.
 */
export function createLeadSchema() {
  return z.object({
    // Step 1 — Business Info
    businessName: requiredTrimmedString("Business name is required"),
    // Optional: `industry` is nullable in StoreLeadRequest, UpdateLeadRequest and the ingest request,
    // and no business rule requires it. It is NOT marked required in the UI for that reason.
    niche: optionalText(),
    website: optionalText(),
    // Bound to `address`, which has always had a column and a Fillable entry — only the create rule
    // was missing, so anything typed here used to be discarded.
    address: optionalText(),

    // Step 2 — Contact Details
    ownerName: requiredTrimmedString("Owner full name is required"),
    email: optionalEmail,
    phone: optionalText(50),

    // Step 3 — Lead Source
    acquisitionChannel: optionalText(100),
    priority: z.enum(["high", "medium", "low"]),
    amount: optionalAmount,
    // Optional for EVERY creator, manager included. A lead may legitimately exist before anyone
    // has been chosen to work it, and the old `min(1)` for managers made an Admin or Super Admin
    // unable to create one at all until they picked an owner. The backend rule was relaxed in step
    // with this (StoreLeadRequest), so the two agree; `UNASSIGNED` below is the explicit way to
    // say "no owner" rather than leaving the placeholder selected by accident.
    assignedTo: z.string(),
    notes: optionalText(5000),
  });
}

// The wizard and the bulk Assign/Reassign modal must mean the same thing by "Unassigned", so the
// sentinel is defined once in modules/crm/lib/bd-assignment and re-exported here for the wizard's
// existing importers rather than declared a second time.
export { UNASSIGNED_BD, isUnassignedBd } from "@/modules/crm/lib/bd-assignment";

export type CreateLeadValues = z.infer<ReturnType<typeof createLeadSchema>>;

/** Field names, per step. Next validates exactly the current step's fields — never the whole form. */
export const STEP_FIELDS = [
  ["businessName", "niche", "website", "address"],
  ["ownerName", "email", "phone"],
  ["acquisitionChannel", "priority", "amount", "assignedTo", "notes"],
] as const satisfies readonly (readonly (keyof CreateLeadValues)[])[];

export const STEP_COUNT = STEP_FIELDS.length;

/**
 * Server field → form field. A 422 names a snake_case column; the wizard needs the camelCase field
 * so it can attach the message to the input AND jump to the step that owns it. Without this a
 * server-only rule surfaces as a sentence on whichever step happened to be open.
 */
export const SERVER_FIELD_TO_FORM: Record<string, keyof CreateLeadValues> = {
  business_name: "businessName",
  industry: "niche",
  website: "website",
  address: "address",
  contact_name: "ownerName",
  email: "email",
  phone: "phone",
  acquisition_channel: "acquisitionChannel",
  priority: "priority",
  amount: "amount",
  assigned_to: "assignedTo",
  note: "notes",
};

/** 1-based step that owns a field, for navigating to a server error. */
export function stepForField(field: keyof CreateLeadValues): number {
  const index = STEP_FIELDS.findIndex((fields) =>
    (fields as readonly string[]).includes(field),
  );
  return index === -1 ? 1 : index + 1;
}
