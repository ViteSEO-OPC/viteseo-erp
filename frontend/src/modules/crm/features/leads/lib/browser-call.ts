import { getApiErrorMessage } from "@/modules/crm/lib/api-error";
import type { Call } from "@/modules/crm/features/leads/types/call-types";

/**
 * The pure parts of browser calling, kept out of `use-browser-call` so they can be pinned by
 * `scripts/calling-gates` without a React test runner.
 *
 * Every message here is written for a BD, not a developer: it says what went wrong in terms they can
 * act on (allow the microphone, plug one in, check the network, try again). The SDK's own `message`
 * is never shown — it reads like "JWT token expired" or "AccessTokenInvalid".
 */

const MICROPHONE_PERMISSION =
  "Zerem needs microphone access to place the call. Allow it in your browser and try again.";
const NO_MICROPHONE =
  "Zerem couldn't use a microphone — none was found, or another app is using it. Connect or free one and try again.";
const NETWORK = "The connection to Twilio dropped. Check your network and try again.";
const SESSION = "The calling session expired. Try the call again.";
const FIREWALL =
  "Could not reach Twilio's media servers — a firewall may be blocking the connection.";

/** The answer for any code not listed below: a plain sentence, never an error code or SDK text. */
export const GENERIC_CALL_FAILURE = "The call could not be completed.";

/**
 * Twilio Voice SDK error codes, in words a BD can act on. Codes are taken from the SDK's own error
 * classes (2.x `errors/generated`), not from memory.
 */
export function describeTwilioError(error: { code?: number; message?: string }): string {
  switch (error.code) {
    case 31401: // PermissionDeniedError — the browser or the user refused the microphone.
    case 31208: // An older SDK's media-permission code; kept so a downgrade cannot regress the wording.
      return MICROPHONE_PERMISSION;
    case 31402: // AcquisitionFailedError — no microphone, or one another app holds exclusively.
      return NO_MICROPHONE;
    case 31005: // ConnectionError (signalling)
    case 31009: // TransportError
    case 53405: // ConnectionError (media)
      return NETWORK;
    case 20101: // AccessTokenInvalid
    case 20104: // AccessTokenExpired
    case 31204: // InvalidJWTTokenError
    case 31205: // JWTTokenExpiredError
      return SESSION;
    case 31003: // ConnectionTimeoutError
      return FIREWALL;
    default:
      return GENERIC_CALL_FAILURE;
  }
}

/**
 * A failure before the call leg exists: the token request, loading the SDK, or the microphone. The
 * browser reports microphone problems as `DOMException`s, the SDK as coded errors, the API as an HTTP
 * error — each gets the same specific message it would get mid-call.
 */
export function browserFailureHint(err: unknown): string {
  if (err instanceof DOMException) {
    if (err.name === "NotAllowedError" || err.name === "SecurityError") return MICROPHONE_PERMISSION;
    if (
      err.name === "NotFoundError" ||
      err.name === "NotReadableError" ||
      err.name === "OverconstrainedError"
    ) {
      return NO_MICROPHONE;
    }
  }

  const code = (err as { code?: unknown } | null)?.code;
  if (typeof code === "number") return describeTwilioError({ code });

  return getApiErrorMessage(err, "Browser calling could not be started.");
}

/**
 * Whether a call that has just ended is STILL `in_progress` on the server — i.e. Twilio's completion
 * callback has not landed yet, so the panel should keep showing "Finishing…" rather than offering the
 * manual Complete / Discard controls for a record that is about to be concluded.
 */
export function isStillOpen(
  calls: readonly Pick<Call, "id" | "status">[] | undefined,
  callId: string | null,
): boolean {
  if (!callId || !calls) return false;

  return calls.some((call) => call.id === callId && call.status === "in_progress");
}
