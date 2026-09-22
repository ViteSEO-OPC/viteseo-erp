/**
 * The one definition of "no Business Development Manager" across every lead-ownership control.
 *
 * A lead may legitimately have no owner — created before anyone was chosen to work it, or handed
 * back while that is decided. Several surfaces express that (the Create Lead wizard, the bulk
 * Assign/Reassign modal), and they were each one string literal away from disagreeing about how to
 * say it. The sentinel, the label and the option-list shape live here so they cannot drift.
 *
 * WHY A SENTINEL RATHER THAN THE EMPTY STRING. The empty string is already the "nothing picked yet"
 * placeholder in these selects. Both end up sending no owner, but only one of them is a decision,
 * and a control that offers Unassigned as something you choose on purpose must be able to tell the
 * two apart — otherwise the confirm button cannot know whether the user has answered the question.
 */
export const UNASSIGNED_BD = "unassigned";

/** How the unassigned state is written wherever a user reads it. */
export const UNASSIGNED_BD_LABEL = "Unassigned";

/** True when this selector value means "no owner" — either explicitly chosen, or nothing chosen. */
export function isUnassignedBd(value: string): boolean {
  return value === "" || value === UNASSIGNED_BD;
}

/**
 * What the API should receive for `assigned_to`.
 *
 * `null` CLEARS the assignment, which the backend accepts as an explicit value (AssignLeadRequest is
 * `present` + `nullable`, so the key must be sent). Returning null rather than omitting the field is
 * therefore the point: the request says "no owner" out loud instead of by absence.
 */
export function assignedToPayload(value: string): number | null {
  return isUnassignedBd(value) ? null : Number(value);
}

type BdUser = { id: string; name: string };
type SelectOption = { value: string; label: string };

/**
 * BD options for an ownership selector, with Unassigned offered FIRST.
 *
 * First because it is the shortest path to the thing a manager reaches this control to do in a hurry
 * — take a lead off someone — and because it must not be buried under a long roster of BDs. The
 * existing BD options follow, in their existing order, unchanged.
 */
export function bdOptionsWithUnassigned(bdUsers: readonly BdUser[]): SelectOption[] {
  return [
    { value: UNASSIGNED_BD, label: UNASSIGNED_BD_LABEL },
    ...bdUsers.map((user) => ({ value: user.id, label: user.name })),
  ];
}
