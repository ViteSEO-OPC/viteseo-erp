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
 */
import { AxiosError } from "axios";
import { getApiErrorMessage } from "@/modules/crm/lib/api-error";
import {
  browserFailureHint,
  describeTwilioError,
  GENERIC_CALL_FAILURE,
  isStillOpen,
} from "@/modules/crm/features/leads/lib/browser-call";

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

console.log(failed === 0 ? "\nAll gates passed." : `\n${failed} gate(s) FAILED.`);
process.exit(failed === 0 ? 0 : 1);
