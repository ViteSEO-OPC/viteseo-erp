/**
 * Settings → Integrations → Calling: every decision the Calling card makes, as pure functions.
 *
 * Kept out of the component so `npm run calling:gates` drives the REAL rules — which fields a client
 * may edit, what a save sends, what state the card claims, how a refusal is read — rather than a copy
 * that could drift from what the screen does. The component renders; this decides.
 *
 * ── Two kinds of organization ──
 * CENTRALLY MANAGED (`centrallyManaged`): the organization's calling runs on ViteSEO's centralized
 * Twilio account, configured on the server. There is nothing to edit — no field, no switch, no test —
 * and the API refuses every write with a 403. The card is a read-only status.
 *
 * CLIENT: the organization brings its OWN Twilio account. Its admin enters the credentials here; the
 * secrets are write-only and never come back. Without its own account it has manual calling — never
 * ViteSEO's account, which the server refuses to lend it.
 *
 * ── No organization is ever named from here ──
 * Every request goes to one fixed endpoint, and the server resolves the organization from the signed-in
 * user. Nothing in this module — or any payload it builds — carries an organization identifier.
 */
import { z } from "zod";
import type {
  CallProviderSettings,
  CallingConfigInput,
  CallingField,
  CallingIntegration,
  CallingTestStatus,
} from "@/modules/crm/features/settings/hooks/use-calling-integration";

/** The one settings endpoint. The organization is the signed-in user's — never a parameter. */
export const CALLING_SETTINGS_ENDPOINT = "/settings/integrations/calling";

/** Where Twilio's Voice webhook lives on this deployment, relative to its public origin. */
export const VOICE_WEBHOOK_PATH = "/api/v1/twilio/voice";

/**
 * The credential fields a client organization enters — exactly the credential fields the settings API
 * accepts. The callback origin is NOT among them: it belongs to the deployment and the server ignores
 * any value for it.
 */
export const CLIENT_CREDENTIAL_FIELDS = [
  "account_sid",
  "auth_token",
  "api_key",
  "api_secret",
  "twiml_app_sid",
  "caller_id",
] as const;

export type CredentialFormField = (typeof CLIENT_CREDENTIAL_FIELDS)[number];

/** The two write-only fields. Never pre-filled, never read back; blank means "keep what is stored". */
export const SECRET_FIELDS: readonly CredentialFormField[] = ["auth_token", "api_secret"];

/**
 * Required fields only the deployment can supply. When the server reports one missing, no admin on
 * this screen can fix it — ViteSEO has to.
 */
const SERVER_FIELDS: readonly CallingField[] = ["webhook_base_url"];

/**
 * The form's shape, validated exactly as the server validates it, so a paste error is caught before a
 * round trip rather than after one.
 */
export const credentialSchema = z.object({
  account_sid: z
    .string()
    .trim()
    .regex(/^AC[0-9a-fA-F]{32}$/, "An Account SID starts with AC and is 34 characters long."),
  api_key: z
    .string()
    .trim()
    .regex(/^SK[0-9a-fA-F]{32}$/, "An API Key SID starts with SK and is 34 characters long."),
  twiml_app_sid: z
    .string()
    .trim()
    .regex(/^AP[0-9a-fA-F]{32}$/, "A TwiML App SID starts with AP and is 34 characters long."),
  caller_id: z
    .string()
    .trim()
    .regex(/^\+[1-9]\d{7,14}$/, "Use the full international form, e.g. +639171234567."),
  auth_token: z
    .string()
    .trim()
    .regex(/^\S*$/, "An Auth Token cannot contain spaces.")
    .refine((v) => v === "" || v.length >= 20, "Paste the full Auth Token."),
  api_secret: z
    .string()
    .trim()
    .regex(/^\S*$/, "An API Key Secret cannot contain spaces.")
    .refine((v) => v === "" || v.length >= 20, "Paste the full API Key Secret."),
});

export type CredentialFormValues = z.infer<typeof credentialSchema>;

/** Read-only (centrally managed) or editable (the organization's own account). */
export type CallingCardMode = "central" | "client";

export function callingCardMode(integration: Pick<CallingIntegration, "centrallyManaged">): CallingCardMode {
  return integration.centrallyManaged ? "central" : "client";
}

/** The fields this organization may edit here: every credential for a client, none when central. */
export function editableFields(
  integration: Pick<CallingIntegration, "centrallyManaged">,
): readonly CredentialFormField[] {
  return callingCardMode(integration) === "central" ? [] : CLIENT_CREDENTIAL_FIELDS;
}

/**
 * The form's starting values. Identifiers ARE pre-filled — they are not secrets, and an admin
 * correcting one should not have to retype the others. The secrets NEVER are, whatever the settings
 * object happens to carry: the inputs start empty and a masked placeholder is the only hint.
 */
export function credentialFormDefaults(
  settings: Pick<CallProviderSettings, "accountSid" | "apiKey" | "twimlAppSid" | "callerId">,
): CredentialFormValues {
  return {
    account_sid: settings.accountSid ?? "",
    api_key: settings.apiKey ?? "",
    twiml_app_sid: settings.twimlAppSid ?? "",
    caller_id: settings.callerId ?? "",
    auth_token: "",
    api_secret: "",
  };
}

/**
 * What a save sends. Built field by field from the allow-list, so nothing else — no callback URL, no
 * organization, no stray key — can ride along. A blank secret is OMITTED, not sent empty: omitting is
 * what the API reads as "keep the stored value". Clearing the secrets is the separate Remove action.
 */
export function buildConfigurePayload(values: CredentialFormValues): CallingConfigInput {
  const authToken = values.auth_token.trim();
  const apiSecret = values.api_secret.trim();

  return {
    account_sid: values.account_sid.trim(),
    api_key: values.api_key.trim(),
    twiml_app_sid: values.twiml_app_sid.trim(),
    caller_id: values.caller_id.trim(),
    ...(authToken ? { auth_token: authToken } : {}),
    ...(apiSecret ? { api_secret: apiSecret } : {}),
  };
}

/**
 * The one state the card leads with, computed from what the SERVER says is in force — never from the
 * form's contents, so a filled-in but untested configuration can never read as connected.
 */
export type CallingState =
  | "centrally-managed"
  | "connected"
  | "not-configured"
  | "incomplete"
  | "switched-off"
  | "test-failed"
  | "not-verified";

export function callingState(integration: CallingIntegration): CallingState {
  if (integration.centrallyManaged) return "centrally-managed";
  if (integration.browserCalling) return "connected";
  if (!integration.settings.configured && integration.source === "none") return "not-configured";
  // In the order an admin has to resolve them. "Not verified" is last because it is the only one that
  // needs Twilio to answer, and it is the state a fresh save always lands in.
  if (!integration.complete) return "incomplete";
  if (!integration.enabled) return "switched-off";
  if (testOutcome(integration.settings.lastTestStatus).kind === "failure") return "test-failed";
  return "not-verified";
}

/** The server's missing-field list, split by who can fix each one. */
export function missingByOwner(missing: readonly CallingField[]): {
  client: CallingField[];
  server: CallingField[];
} {
  return {
    client: missing.filter((field) => !SERVER_FIELDS.includes(field)),
    server: missing.filter((field) => SERVER_FIELDS.includes(field)),
  };
}

/**
 * The URL a client's TwiML App must use as its Voice Request URL, built from the deployment's public
 * origin the API reports. Informational only: it is fixed by the deployment and cannot be changed here.
 */
export function voiceWebhookUrl(settings: Pick<CallProviderSettings, "webhookBaseUrl">): string | null {
  const base = settings.webhookBaseUrl?.trim();

  return base ? `${base.replace(/\/+$/, "")}${VOICE_WEBHOOK_PATH}` : null;
}

/** A connection-test verdict, read for display. Filled-in fields are never a success. */
export type TestOutcome = { kind: "success" | "failure" | "untested"; label: string };

export function testOutcome(status: CallingTestStatus | null): TestOutcome {
  if (status === "ok") return { kind: "success", label: testStatusLabel(status) };
  // `not_configured` is not a failure of the account: the server made no request at all.
  if (status && status !== "not_configured") return { kind: "failure", label: testStatusLabel(status) };
  return { kind: "untested", label: testStatusLabel(status) };
}

type ApiErrorShape = {
  response?: {
    status?: number;
    data?: { message?: unknown; errors?: Record<string, unknown> };
  };
} | null;

/**
 * True when the server refused a write because this organization's calling is managed centrally — a
 * 403 carrying the `provider` error. Told apart from the role refusal (a plain 403 with no field
 * errors), which means the signed-in user is not an Admin at all.
 */
export function isCentrallyManagedRefusal(err: unknown): boolean {
  const response = (err as ApiErrorShape)?.response;

  return response?.status === 403 && Array.isArray(response.data?.errors?.provider);
}

/**
 * A 422's field errors, mapped onto the form's own fields — the first message per field, and only for
 * fields the form has. Anything else is left to the summary message.
 */
export function formFieldErrors(err: unknown): Partial<Record<CredentialFormField, string>> {
  const response = (err as ApiErrorShape)?.response;
  if (response?.status !== 422) return {};

  const errors = response.data?.errors ?? {};
  const out: Partial<Record<CredentialFormField, string>> = {};

  for (const field of CLIENT_CREDENTIAL_FIELDS) {
    const messages = errors[field];
    if (Array.isArray(messages) && typeof messages[0] === "string") {
      out[field] = messages[0];
    }
  }

  return out;
}

/**
 * Plain-language reading of a test verdict. A new server status cannot be rendered as a raw code — the
 * fallthrough says "not tested" rather than leaking an identifier.
 */
export function testStatusLabel(status: CallingTestStatus | null): string {
  switch (status) {
    case "ok":
      return "Connected";
    case "auth_failed":
      return "Twilio rejected the Account SID / Auth Token";
    case "api_key_failed":
      return "Twilio rejected the API Key SID / Secret";
    case "twiml_app_missing":
      return "No TwiML App with that SID exists on this account";
    case "rate_limited":
      return "Rate limited by Twilio — try again shortly";
    case "unreachable":
      return "Could not reach Twilio";
    case "not_configured":
      return "Fill in every field before testing";
    default:
      return "Not tested";
  }
}

/** The field names as the form labels them, so a "still needed" list reads like the form. */
export function fieldLabel(field: CallingField): string {
  switch (field) {
    case "account_sid":
      return "Account SID";
    case "auth_token":
      return "Auth Token";
    case "api_key":
      return "API Key SID";
    case "api_secret":
      return "API Key Secret";
    case "twiml_app_sid":
      return "TwiML App SID";
    case "caller_id":
      return "Caller ID";
    case "webhook_base_url":
      return "the server's public callback address";
    default:
      return field;
  }
}
