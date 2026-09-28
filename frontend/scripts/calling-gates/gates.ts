/**
 * Browser-calling gates.
 *
 * Run with:  npm run calling:gates        (from frontend/)
 *
 * Same recipe as scripts/attachment-preview-gates: `frontend/` has no test runner, Node 24 strips the
 * TypeScript natively, and scripts/copilot-gates/loader.mjs bridges the "@/" alias — so these gates
 * drive the REAL module `use-browser-call` imports, never a copy that could drift from it.
 *
 * Exits non-zero if any gate fails, so it can be wired into CI unchanged.
 *
 * ── WHAT EACH GATE VERIFIES ──────────────────────────────────────────────────────────────────
 *
 * A  TWILIO ERROR CODES — every microphone, device, network and session code the SDK raises maps to
 *    the specific sentence a BD can act on, and an unknown code never leaks the SDK's developer text.
 * B  PRE-CALL FAILURES — the browser's DOMExceptions and the SDK's coded errors get the same specific
 *    messages; anything else falls through to the API's own message.
 * C  FINISHING A CALL — after hang-up the panel waits ("Finishing…") only while THIS call's record is
 *    still in progress on the server, never because some other call is.
 *
 * Settings → Integrations → Calling (lib/calling-settings — the rules the settings card renders from):
 *
 * D  CLIENT FORM — a client organization gets exactly the credential fields the API accepts.
 * E  CENTRALLY MANAGED — ViteSEO's organization is read-only: no editable field, whatever its state.
 * F  NO CALLBACK INPUT — the callback address is never a field and never sent; it is shown read-only
 *    as the TwiML App's Voice URL, and the card's source has no input for it. The path and method the
 *    card displays are also pinned against the BACKEND's constants, so the URL a client is told to use
 *    can never drift from the one the connection test accepts.
 * G  SECRETS NEVER RENDERED — the form's secret inputs start empty whatever the settings carry.
 * H  SAVE PAYLOAD — a blank secret is omitted (the API's "keep the stored value"), filled ones are sent
 *    trimmed, and nothing outside the allow-list can ride along.
 * I  CONNECTION TEST — "Connected" only on Twilio's `ok`; a filled-in form is never "connected"; and
 *    every verdict sends the admin to the place that can actually fix it — the TwiML App verdicts to
 *    the Twilio console, the credential verdicts to the form.
 * J  REFUSALS — a centrally-managed 403 is told apart from a role 403; a 422 lands on its fields.
 * K  ORGANIZATION SCOPE — one fixed endpoint, and no organization identifier in any payload.
 * L  STATE MATRIX — the card's existing states, and who must fix a missing field.
 */
import { readFileSync } from "node:fs";
import { AxiosError } from "axios";
import { getApiErrorMessage } from "@/modules/crm/lib/api-error";
import {
  browserFailureHint,
  describeTwilioError,
  GENERIC_CALL_FAILURE,
  isStillOpen,
} from "@/modules/crm/features/leads/lib/browser-call";
import {
  buildConfigurePayload,
  CALLING_SETTINGS_ENDPOINT,
  callingCardMode,
  callingState,
  CLIENT_CREDENTIAL_FIELDS,
  credentialFormDefaults,
  credentialSchema,
  editableFields,
  formFieldErrors,
  isCentrallyManagedRefusal,
  missingByOwner,
  testFailureRemedy,
  testOutcome,
  testStatusLabel,
  VOICE_WEBHOOK_PATH,
  voiceWebhookUrl,
  type CredentialFormValues,
} from "@/modules/crm/features/settings/lib/calling-settings";
import type {
  CallProviderSettings,
  CallingIntegration,
  CallingTestStatus,
} from "@/modules/crm/features/settings/hooks/use-calling-integration";

let failed = 0;
function report(gate: string, ok: number, total: number, detail: string[] = []) {
  const pass = ok === total;
  if (!pass) failed++;
  console.log(`${pass ? "PASS" : "FAIL"}  ${gate.padEnd(46)} ${ok}/${total}`);
  for (const d of detail.slice(0, 10)) console.log(`         ${d}`);
  if (detail.length > 10) console.log(`         … and ${detail.length - 10} more`);
}

const MICROPHONE = /microphone access/;
const NO_MICROPHONE = /couldn't use a microphone/;
const NETWORK = /network/;
const SESSION = /session expired/;
const FIREWALL = /firewall/;

// ── A  Twilio error codes ───────────────────────────────────────────────────────────────────
{
  const cases: [number, RegExp][] = [
    [31401, MICROPHONE], // PermissionDeniedError
    [31208, MICROPHONE], // legacy media-permission code
    [31402, NO_MICROPHONE], // AcquisitionFailedError
    [31005, NETWORK], // ConnectionError
    [31009, NETWORK], // TransportError
    [53405, NETWORK], // media ConnectionError
    [20101, SESSION], // AccessTokenInvalid
    [20104, SESSION], // AccessTokenExpired
    [31204, SESSION], // InvalidJWTTokenError
    [31205, SESSION], // JWTTokenExpiredError
    [31003, FIREWALL], // ConnectionTimeoutError
  ];

  const bad = cases
    .filter(([code, expected]) => !expected.test(describeTwilioError({ code })))
    .map(([code, expected]) => `${code} → "${describeTwilioError({ code })}", want ${expected}`);

  // The SDK's own text reads like "AccessTokenInvalid"; it must never reach a BD.
  const unknown = describeTwilioError({ code: 99999, message: "AccessTokenInvalid: JWT token expired" });
  if (unknown !== GENERIC_CALL_FAILURE) bad.push(`unknown code → "${unknown}", want the plain sentence`);
  const uncoded = describeTwilioError({ message: "raw developer text" });
  if (uncoded !== GENERIC_CALL_FAILURE) bad.push(`no code → "${uncoded}", want the plain sentence`);

  const total = cases.length + 2;
  report("A  Twilio error codes → actionable messages", total - bad.length, total, bad);
}

// ── B  pre-call failures ────────────────────────────────────────────────────────────────────
{
  const coded = (code: number) => Object.assign(new Error("TwilioError"), { code });
  const cases: [string, unknown, RegExp][] = [
    ["DOMException NotAllowedError", new DOMException("denied", "NotAllowedError"), MICROPHONE],
    ["DOMException SecurityError", new DOMException("insecure origin", "SecurityError"), MICROPHONE],
    ["DOMException NotFoundError", new DOMException("no device", "NotFoundError"), NO_MICROPHONE],
    ["DOMException NotReadableError", new DOMException("device busy", "NotReadableError"), NO_MICROPHONE],
    ["SDK error 31402", coded(31402), NO_MICROPHONE],
    ["SDK error 31005", coded(31005), NETWORK],
    ["SDK error 20104", coded(20104), SESSION],
  ];

  const bad = cases
    .filter(([, err, expected]) => !expected.test(browserFailureHint(err)))
    .map(([label, err, expected]) => `${label} → "${browserFailureHint(err)}", want ${expected}`);

  // Anything else is the API's own answer (e.g. 422 "not configured"), exactly as before.
  const fallback = "Browser calling could not be started.";
  const apiError = new AxiosError("Request failed with status code 422", "ERR_BAD_REQUEST", undefined, undefined, {
    status: 422,
    statusText: "Unprocessable Content",
    headers: {},
    data: { message: "Browser calling is not configured for this workspace." },
  } as never);
  for (const [label, err] of [
    ["API 422", apiError],
    ["plain Error", new Error("something else")],
  ] as const) {
    const got = browserFailureHint(err);
    const want = getApiErrorMessage(err, fallback);
    if (got !== want) bad.push(`${label} → "${got}", want the API message "${want}"`);
  }

  const total = cases.length + 2;
  report("B  pre-call failures → actionable messages", total - bad.length, total, bad);
}

// ── C  finishing a call waits only for its own record ───────────────────────────────────────
{
  const calls = [
    { id: "7", status: "in_progress" },
    { id: "8", status: "completed" },
  ] as const;

  const cases: [string, Parameters<typeof isStillOpen>[0], string | null, boolean][] = [
    ["own record still in progress", calls, "7", true],
    ["own record concluded", calls, "8", false],
    ["own record absent (another call open)", calls, "9", false],
    ["nothing cached yet", undefined, "7", false],
    ["no call id", calls, null, false],
  ];

  const bad = cases
    .filter(([, list, id, expected]) => isStillOpen(list, id) !== expected)
    .map(([label, list, id, expected]) => `${label} → ${isStillOpen(list, id)}, want ${expected}`);

  report("C  finishing waits only for its own record", cases.length - bad.length, cases.length, bad);
}

// ── Settings → Integrations → Calling ──────────────────────────────────────────────────────

/** An overview exactly as the settings API returns it, with overrides. */
function overview(
  over: Partial<CallingIntegration> = {},
  settings: Partial<CallProviderSettings> = {},
): CallingIntegration {
  return {
    browserCalling: false,
    provider: "manual",
    source: "none",
    centrallyManaged: false,
    enabled: false,
    complete: false,
    verified: false,
    missing: [],
    requiredFields: [],
    ...over,
    settings: {
      provider: "twilio",
      enabled: false,
      accountSid: null,
      apiKey: null,
      twimlAppSid: null,
      callerId: null,
      webhookBaseUrl: "https://zerem.example",
      hasAuthToken: false,
      authTokenLast4: null,
      hasApiSecret: false,
      apiSecretLast4: null,
      lastTestStatus: null,
      lastTestedAt: null,
      configured: false,
      updatedAt: null,
      ...settings,
    },
  };
}

const same = (a: readonly string[], b: readonly string[]) =>
  a.length === b.length && [...a].sort().join("|") === [...b].sort().join("|");

const API_FIELDS = ["account_sid", "auth_token", "api_key", "api_secret", "twiml_app_sid", "caller_id"];
const schemaKeys = Object.keys(credentialSchema.shape);

const CARD_SOURCE = readFileSync(
  new URL("../../src/modules/crm/features/settings/components/twilio-calling-card.tsx", import.meta.url),
  "utf8",
);

/**
 * The BACKEND's copy of the voice webhook path and method.
 *
 * Read as source text because the two live in different languages and must not drift: the card tells a
 * client admin which URL to put in their TwiML App, and the connection test refuses any app that does
 * not point at exactly that. If these two constants ever disagree, the screen would hand out one URL
 * and the server would reject it — the most confusing possible onboarding failure, and one no
 * single-language test could catch.
 */
const SETTING_SERVICE_SOURCE = readFileSync(
  new URL(
    "../../../backend/app/Modules/Crm/Services/Calling/CallProviderSettingService.php",
    import.meta.url,
  ),
  "utf8",
);

const filledValues: CredentialFormValues = {
  account_sid: "  AC" + "a".repeat(32) + " ",
  api_key: "SK" + "a".repeat(32),
  twiml_app_sid: "AP" + "a".repeat(32),
  caller_id: "+639170000002",
  auth_token: "",
  api_secret: "",
};

// ── D  client form ──────────────────────────────────────────────────────────────────────────
{
  const client = overview();
  const checks: [string, boolean][] = [
    ["a client is in the editable mode", callingCardMode(client) === "client"],
    ["a client edits exactly the API's credential fields", same(editableFields(client), API_FIELDS)],
    ["the form's schema is exactly those fields", same(schemaKeys, API_FIELDS)],
    ["the allow-list is the schema", same(CLIENT_CREDENTIAL_FIELDS, schemaKeys)],
    ["a configured client is still editable", editableFields(overview({ source: "organization" }, { configured: true })).length === 6],
  ];
  const bad = checks.filter(([, ok]) => !ok).map(([label]) => label);
  report("D  client sees the editable credential form", checks.length - bad.length, checks.length, bad);
}

// ── E  centrally managed ────────────────────────────────────────────────────────────────────
{
  const states = [
    overview({ centrallyManaged: true, source: "deployment", browserCalling: true, enabled: true, complete: true, verified: true }),
    overview({ centrallyManaged: true, source: "none" }),
    // A stored row the server still reports never makes the organization editable.
    overview({ centrallyManaged: true, source: "deployment" }, { configured: true, accountSid: "AC" + "a".repeat(32) }),
  ];
  const bad: string[] = [];
  states.forEach((state, i) => {
    if (callingCardMode(state) !== "central") bad.push(`case ${i}: mode ${callingCardMode(state)}`);
    if (editableFields(state).length !== 0) bad.push(`case ${i}: ${editableFields(state).length} editable fields`);
    if (callingState(state) !== "centrally-managed") bad.push(`case ${i}: state ${callingState(state)}`);
  });
  report("E  centrally managed is read-only", states.length * 3 - bad.length, states.length * 3, bad);
}

// ── F  no callback input ────────────────────────────────────────────────────────────────────
{
  const smuggled = { ...filledValues, webhook_base_url: "https://attacker.example" } as CredentialFormValues;
  const checks: [string, boolean][] = [
    ["the schema has no callback field", !schemaKeys.includes("webhook_base_url")],
    ["the editable fields have no callback field", !editableFields(overview()).includes("webhook_base_url" as never)],
    ["a save never sends a callback URL", !("webhook_base_url" in buildConfigurePayload(smuggled))],
    ["the card registers no callback input", !CARD_SOURCE.includes('register("webhook_base_url")')],
    ["the card renders no callback field", !CARD_SOURCE.includes("twilio-webhook-url")],
    ["the Voice URL is the deployment origin + the voice path",
      voiceWebhookUrl({ webhookBaseUrl: "https://zerem.example" }) === "https://zerem.example/api/v1/twilio/voice"],
    ["a trailing slash on the origin is normalized",
      voiceWebhookUrl({ webhookBaseUrl: "https://zerem.example/ " }) === "https://zerem.example/api/v1/twilio/voice"],
    ["no origin configured → no Voice URL", voiceWebhookUrl({ webhookBaseUrl: null }) === null],
    ["a blank origin → no Voice URL", voiceWebhookUrl({ webhookBaseUrl: "  " }) === null],
    // The URL the card shows and the URL the server verifies the TwiML App against are one value.
    ["the backend verifies the same voice path the card displays",
      SETTING_SERVICE_SOURCE.includes(`VOICE_WEBHOOK_PATH = '${VOICE_WEBHOOK_PATH}'`)],
    ["the backend requires the POST the card tells the admin to use",
      SETTING_SERVICE_SOURCE.includes("VOICE_WEBHOOK_METHOD = 'POST'") && CARD_SOURCE.includes("HTTP POST")],
  ];
  const bad = checks.filter(([, ok]) => !ok).map(([label]) => label);
  report("F  callback URL is never an editable field", checks.length - bad.length, checks.length, bad);
}

// ── G  secrets never rendered ───────────────────────────────────────────────────────────────
{
  // Even a settings object that somehow carried the secrets must not put them in the form.
  const hostile = {
    ...overview({}, { hasAuthToken: true, authTokenLast4: "0000", hasApiSecret: true, apiSecretLast4: "1111" }).settings,
    authToken: "a-real-auth-token-value",
    auth_token: "a-real-auth-token-value",
    apiSecret: "a-real-api-secret-value",
    api_secret: "a-real-api-secret-value",
  } as CallProviderSettings;
  const defaults = credentialFormDefaults(hostile);
  const checks: [string, boolean][] = [
    ["the Auth Token input starts empty", defaults.auth_token === ""],
    ["the API Key Secret input starts empty", defaults.api_secret === ""],
    ["no secret value appears anywhere in the defaults", !JSON.stringify(defaults).includes("a-real-")],
    ["identifiers are pre-filled", credentialFormDefaults({ ...hostile, accountSid: "ACx" }).account_sid === "ACx"],
    ["the card never reads a secret from the settings", !/settings\.(authToken|apiSecret|auth_token|api_secret)\b/.test(CARD_SOURCE)],
  ];
  const bad = checks.filter(([, ok]) => !ok).map(([label]) => label);
  report("G  secrets are never rendered from the API", checks.length - bad.length, checks.length, bad);
}

// ── H  save payload ─────────────────────────────────────────────────────────────────────────
{
  const keepSecrets = buildConfigurePayload(filledValues);
  const rotate = buildConfigurePayload({ ...filledValues, auth_token: "  new-auth-token-value-000000 ", api_secret: "new-api-secret-value-0000000" });
  const checks: [string, boolean][] = [
    ["blank secrets are omitted, not sent empty", !("auth_token" in keepSecrets) && !("api_secret" in keepSecrets)],
    ["identifiers are sent trimmed", keepSecrets.account_sid === "AC" + "a".repeat(32)],
    ["all four identifiers are sent", same(Object.keys(keepSecrets), ["account_sid", "api_key", "twiml_app_sid", "caller_id"])],
    ["a filled secret is sent, trimmed", rotate.auth_token === "new-auth-token-value-000000"],
    ["both secrets can be rotated together", rotate.api_secret === "new-api-secret-value-0000000"],
    ["only API fields are ever sent", Object.keys(rotate).every((k) => API_FIELDS.includes(k))],
  ];
  const bad = checks.filter(([, ok]) => !ok).map(([label]) => label);
  report("H  save follows the API contract", checks.length - bad.length, checks.length, bad);
}

// ── I  connection test ──────────────────────────────────────────────────────────────────────
{
  const complete = { source: "organization" as const, enabled: true, complete: true };
  const checks: [string, boolean][] = [
    ["ok is a success labelled Connected", testOutcome("ok").kind === "success" && testOutcome("ok").label === "Connected"],
    ["a rejected auth token is a failure", testOutcome("auth_failed").kind === "failure"],
    ["a rejected API key is a failure", testOutcome("api_key_failed").kind === "failure"],
    ["a missing TwiML App is a failure", testOutcome("twiml_app_missing").kind === "failure"],
    // The two verdicts that say the CREDENTIALS are right and the account is pointed elsewhere.
    ["a TwiML App pointed elsewhere is a failure", testOutcome("twiml_app_voice_url_mismatch").kind === "failure"],
    ["the wrong Voice method is a failure", testOutcome("twiml_app_voice_method_invalid").kind === "failure"],
    ["neither voice verdict is labelled as a code",
      !testStatusLabel("twiml_app_voice_url_mismatch").includes("_")
      && !testStatusLabel("twiml_app_voice_method_invalid").includes("_")],
    ["the two voice verdicts read differently",
      testStatusLabel("twiml_app_voice_url_mismatch") !== testStatusLabel("twiml_app_voice_method_invalid")],
    // A verdict about the TwiML App must send the admin to Twilio, not back to a correct form.
    ["a TwiML App verdict is fixed in Twilio",
      ["twiml_app_missing", "twiml_app_voice_url_mismatch", "twiml_app_voice_method_invalid"]
        .every((s) => testFailureRemedy(s as CallingTestStatus).includes("TwiML App"))],
    ["a credential verdict is fixed in the form",
      testFailureRemedy("auth_failed").includes("above") && testFailureRemedy("api_key_failed").includes("above")],
    ["an unanswered check blames nothing",
      testFailureRemedy("unreachable") === testFailureRemedy("rate_limited")
      && !testFailureRemedy("unreachable").includes("above")],
    ["unreachable is a failure", testOutcome("unreachable").kind === "failure"],
    ["never tested is not a success", testOutcome(null).kind === "untested"],
    ["not_configured is not a failure of the account", testOutcome("not_configured").kind === "untested"],
    ["filled-in and untested is NOT connected",
      callingState(overview(complete, { configured: true })) === "not-verified"],
    ["a failed test reads as test-failed",
      callingState(overview(complete, { configured: true, lastTestStatus: "auth_failed" })) === "test-failed"],
    ["a TwiML App mismatch also reads as test-failed",
      callingState(overview(complete, { configured: true, lastTestStatus: "twiml_app_voice_url_mismatch" })) === "test-failed"],
    ["only the server's browserCalling makes it connected",
      callingState(overview({ ...complete, verified: true, browserCalling: true }, { configured: true, lastTestStatus: "ok" })) === "connected"],
  ];
  const bad = checks.filter(([, ok]) => !ok).map(([label]) => label);
  report("I  connection test success and failure", checks.length - bad.length, checks.length, bad);
}

// ── J  refusals ─────────────────────────────────────────────────────────────────────────────
{
  const httpError = (status: number, data: unknown) =>
    new AxiosError("Request failed", "ERR_BAD_REQUEST", undefined, undefined, {
      status,
      statusText: "",
      headers: {},
      data,
    } as never);

  const central = httpError(403, {
    message: "Calling for this organization is managed centrally by ViteSEO and cannot be changed here.",
    errors: { provider: ["Calling is centrally managed for this organization."] },
  });
  const role = httpError(403, { message: "User does not have the right roles." });
  const invalid = httpError(422, {
    message: "The given data was invalid.",
    errors: {
      account_sid: ["A Twilio Account SID starts with AC and is 34 characters long."],
      auth_token: ["That does not look like an Auth Token. Paste the full value."],
      webhook_base_url: ["ignored"],
      org_id: ["ignored"],
    },
  });
  const mapped = formFieldErrors(invalid);

  const checks: [string, boolean][] = [
    ["a centrally-managed 403 is recognized", isCentrallyManagedRefusal(central)],
    ["a role 403 is not mistaken for it", !isCentrallyManagedRefusal(role)],
    ["a 422 is not mistaken for it", !isCentrallyManagedRefusal(invalid)],
    ["a plain Error is not mistaken for it", !isCentrallyManagedRefusal(new Error("x"))],
    ["a 422 maps onto the form's fields", mapped.account_sid?.startsWith("A Twilio Account SID") === true && mapped.auth_token !== undefined],
    ["only the form's own fields are mapped", same(Object.keys(mapped), ["account_sid", "auth_token"])],
    ["a non-422 maps nothing", Object.keys(formFieldErrors(central)).length === 0],
  ];
  const bad = checks.filter(([, ok]) => !ok).map(([label]) => label);
  report("J  403 centrally managed and 422 handling", checks.length - bad.length, checks.length, bad);
}

// ── K  organization scope ───────────────────────────────────────────────────────────────────
{
  const smuggled = { ...filledValues, org_id: 7, organization_id: 7, orgId: 7 } as unknown as CredentialFormValues;
  const payload = buildConfigurePayload(smuggled);
  const checks: [string, boolean][] = [
    ["one fixed endpoint", CALLING_SETTINGS_ENDPOINT === "/settings/integrations/calling"],
    ["the endpoint names no organization", !/org|\{|:\w/i.test(CALLING_SETTINGS_ENDPOINT)],
    ["no organization identifier reaches a payload", !Object.keys(payload).some((k) => /org/i.test(k))],
  ];
  const bad = checks.filter(([, ok]) => !ok).map(([label]) => label);
  report("K  settings stay organization-scoped", checks.length - bad.length, checks.length, bad);
}

// ── L  state matrix ─────────────────────────────────────────────────────────────────────────
{
  const cases: [string, CallingIntegration, string][] = [
    ["nothing configured", overview(), "not-configured"],
    ["saved, fields missing", overview({ source: "organization", missing: ["api_key"] }, { configured: true }), "incomplete"],
    ["complete, switched off", overview({ source: "organization", complete: true }, { configured: true }), "switched-off"],
    ["complete, on, untested", overview({ source: "organization", complete: true, enabled: true }, { configured: true }), "not-verified"],
    ["verified and running", overview({ source: "organization", complete: true, enabled: true, verified: true, browserCalling: true }, { configured: true, lastTestStatus: "ok" }), "connected"],
  ];
  const bad = cases
    .filter(([, state, want]) => callingState(state) !== want)
    .map(([label, state, want]) => `${label} → ${callingState(state)}, want ${want}`);

  const split = missingByOwner(["api_key", "webhook_base_url", "caller_id"]);
  if (!same(split.client, ["api_key", "caller_id"])) bad.push(`client-fixable → ${split.client.join(",")}`);
  if (!same(split.server, ["webhook_base_url"])) bad.push(`ViteSEO-fixable → ${split.server.join(",")}`);

  report("L  existing calling states are intact", cases.length + 2 - bad.length, cases.length + 2, bad);
}

console.log(failed === 0 ? "\nAll gates passed." : `\n${failed} gate(s) FAILED.`);
process.exit(failed === 0 ? 0 : 1);
