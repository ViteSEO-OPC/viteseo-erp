/**
 * WHICH DISCOVERY CONTROLS MAY BE SHOWN, decided from the provider that is actually running.
 *
 * ON-DEMAND DISCOVERY IS AN ADDITIVE CAPABILITY, NOT A NEW BASELINE. The Nearby board's original
 * experience is the curated OpenStreetMap/NERBY pool: it fills overnight, the board reads it, and
 * nothing on the page starts a search. Stage 2 added surfaces that do start one — and those belong
 * to the capabilities that came with them. Offering "Advanced Lead Discovery" on a deployment whose
 * effective provider is the OpenStreetMap baseline advertises a paid Google search that would
 * quietly run as a free Overpass sweep instead: the button works, the results are not what its name
 * promises, and the difference is invisible until someone compares them.
 *
 * SO THE RULE IS: no new capability configured → the board is exactly what it was before Stage 2.
 *
 * READ FROM THE ONE EXISTING SOURCE. `GET /discovery/config` reports the EFFECTIVE provider — the
 * same answer the scraper acts on, already resolved from `crm_nerby_provider_settings` with its
 * fallbacks applied. EFFECTIVE, not configured, is the load-bearing word: a provider an admin
 * selected but never credentialed arrives here already degraded to `openstreetmap`, so a key that
 * does not work cannot light up a control. No second credential store, and no credential in the
 * payload — only provider identifiers and a reason code.
 *
 * FAIL CLOSED WHILE UNKNOWN. Until the lookup answers, the capability is absent: showing a control
 * and withdrawing it a moment later is worse than showing it once the answer is in, and a failed
 * lookup must not be read as permission.
 */

/** The provider ids the backend reports. Anything else is treated as unknown, never assumed. */
export const PROVIDER_GOOGLE_PLACES = "google_places";
export const PROVIDER_OPENAI = "openai";
export const PROVIDER_OPENSTREETMAP = "openstreetmap";

/**
 * May any on-demand discovery UI be shown at all?
 *
 * True only when a Stage 2 capability is genuinely live. On the OpenStreetMap baseline — the default
 * — this is false and the board keeps its pre-Stage-2 shape.
 */
export function canRunDiscovery(effectiveProvider: string | null | undefined): boolean {
  return effectiveProvider === PROVIDER_GOOGLE_PLACES || effectiveProvider === PROVIDER_OPENAI;
}

/**
 * The nationwide block `GET /discovery/config` reports. Absent on a backend older than it.
 */
export type NationwideCapability = { available: boolean; maxTasks: number; reason: string };

/**
 * May the Nationwide Philippines mode be offered?
 *
 * ASK THE SERVER; DO NOT RE-DERIVE IT. This used to read `effectiveProvider === "google_places"`,
 * mirroring an orchestrator that refused every other provider. When the server replaced that refusal
 * with a budget fence, this copy kept refusing — a finished, working mode stayed invisible because
 * the rule lived in two languages and only one of them was updated. So the rule now has one home
 * (`NationwideCapability` on the backend) and this reads its answer.
 *
 * FAIL CLOSED WHILE UNKNOWN, exactly as before: no block — because the lookup has not answered, or
 * because the backend predates the field — is not permission.
 */
export function canRunNationwide(nationwide: NationwideCapability | null | undefined): boolean {
  return nationwide?.available === true && (nationwide?.maxTasks ?? 0) > 0;
}

/**
 * Why on-demand discovery is unavailable, for an operator reading the page — or null when it is
 * available. Never names a credential; it reports which provider is running, which is already public
 * to any BD through the same endpoint.
 */
export function discoveryUnavailableReason(effectiveProvider: string | null | undefined): string | null {
  if (canRunDiscovery(effectiveProvider)) return null;
  if (effectiveProvider === PROVIDER_OPENSTREETMAP) {
    return "On-demand discovery needs Google Places. This workspace is running the OpenStreetMap baseline, which fills the pool on a schedule instead.";
  }
  return "On-demand discovery is not available in this workspace yet.";
}
