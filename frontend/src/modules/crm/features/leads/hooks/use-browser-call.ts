"use client";

import { useCallback, useEffect, useRef, useState } from "react";
import { useQueryClient } from "@tanstack/react-query";
import type { Call as TwilioCall, Device } from "@twilio/voice-sdk";
import { notify as toast } from "@/core/lib/notify";
import { apiClient } from "@/modules/crm/lib/axios";
import { getApiErrorMessage } from "@/modules/crm/lib/api-error";
import { queryKeys } from "@/modules/crm/lib/query-keys";
import type { Call } from "@/modules/crm/features/leads/types/call-types";
import {
  browserFailureHint,
  describeTwilioError,
  isStillOpen,
} from "@/modules/crm/features/leads/lib/browser-call";

/**
 * Places a real outbound call from the browser over Twilio Voice, for one lead.
 *
 * ── Nothing here loads unless a call is actually placed ──
 * The SDK is pulled in with a DYNAMIC import inside {@link start}, not at module scope. A CRM running
 * the free manual transport — which is every deployment that has not bought Twilio — never downloads
 * a byte of telephony code, and the Lead Details page is not made heavier by a capability it does not
 * have. The static `import type` above is erased at compile time and costs nothing.
 *
 * ── The order of operations, and why ──
 *   1. POST /leads/{id}/calls {browser:true}  the CRM record first, so an attempt that fails halfway
 *                                             still exists in the history rather than vanishing. The
 *                                             SERVER decides the transport here; asking for `browser`
 *                                             in a manual workspace simply returns a manual call.
 *   2. POST /calls/{id}/token                 a short-lived Access Token plus the opaque correlation
 *                                             token. The browser never learns the call id, the
 *                                             account SID, or any secret.
 *   3. Device.connect({ correlation })        Twilio calls our TwiML webhook with that correlation,
 *                                             which is how the server knows which lead to dial. The
 *                                             number is never sent from here.
 *
 * If any step after (1) fails, the CRM call is DISCARDED rather than left open — an abandoned
 * `in_progress` row would trip the single-active-call guard and block the BD's next attempt.
 *
 * ── State comes from the provider, not from a timer ──
 * `connecting` / `ringing` / `connected` are the SDK's own events. The talk-time clock starts on
 * `accept` — the moment Twilio says the leg is up — not when the CRM record was created, which is what
 * a manual call has to fall back on. The authoritative outcome and duration still arrive server-side
 * through the completion webhook; this hook drives the live UI, never the record.
 */

export type BrowserCallState =
  | "idle"
  | "requesting"
  | "connecting"
  | "ringing"
  | "connected"
  /** The network dropped mid-call and the SDK is restoring the media path. No audio right now. */
  | "reconnecting"
  | "ending"
  /** The leg has ended; waiting (bounded) for Twilio's completion callback to conclude the record. */
  | "finalizing";

type BrowserToken = {
  token: string;
  identity: string;
  /** Null on a refresh for a call already bridged — a refreshed credential can never dial. */
  correlation: string | null;
  expiresIn: number;
};

/** How often, and for how long at most, a finished call waits for Twilio's completion callback. */
const FINALIZE_POLL_MS = 1500;
const FINALIZE_MAX_MS = 12_000;

export function useBrowserCall(leadId: string) {
  const queryClient = useQueryClient();

  const [state, setState] = useState<BrowserCallState>("idle");
  const [isMuted, setIsMuted] = useState(false);
  /** When the carrier says the call was answered. Null until then — never a guess. */
  const [connectedAt, setConnectedAt] = useState<number | null>(null);

  const deviceRef = useRef<Device | null>(null);
  const callRef = useRef<TwilioCall | null>(null);
  const crmCallIdRef = useRef<string | null>(null);
  // Survives unmount: cleanup must not resurrect state on a component that is gone.
  const mountedRef = useRef(true);
  // Whether Twilio said this attempt was ANSWERED. A connected call is never discarded on an error.
  const acceptedRef = useRef(false);
  // One toast per attempt: a single failure can surface as a device error, a call error AND a
  // rejected connect(), and three stacked toasts for one problem read as three problems.
  const reportedRef = useRef(false);
  const finalizeTimerRef = useRef<ReturnType<typeof setTimeout> | null>(null);

  const invalidate = useCallback(() => {
    void queryClient.invalidateQueries({ queryKey: queryKeys.leads.calls(leadId) });
    void queryClient.invalidateQueries({ queryKey: queryKeys.leads.activities(leadId) });
    void queryClient.invalidateQueries({ queryKey: queryKeys.planner.list() });
  }, [queryClient, leadId]);

  /** Tear down the SDK. Safe to call twice; never throws into the caller. */
  const teardown = useCallback(() => {
    if (finalizeTimerRef.current) {
      clearTimeout(finalizeTimerRef.current);
      finalizeTimerRef.current = null;
    }
    try {
      callRef.current?.removeAllListeners();
      deviceRef.current?.removeAllListeners();
      deviceRef.current?.destroy();
    } catch {
      // A device already destroyed by a disconnect is the normal case, not an error.
    }
    callRef.current = null;
    deviceRef.current = null;
  }, []);

  const reset = useCallback(() => {
    teardown();
    crmCallIdRef.current = null;
    if (!mountedRef.current) return;
    setState("idle");
    setIsMuted(false);
    setConnectedAt(null);
  }, [teardown]);

  useEffect(() => {
    mountedRef.current = true;
    return () => {
      mountedRef.current = false;
      // Leaving the page ends the WebRTC leg regardless; destroying the device makes that explicit
      // and releases the microphone rather than waiting for garbage collection.
      teardown();
    };
  }, [teardown]);

  /**
   * A browser call that never got off the ground. The CRM row is discarded so it does not sit
   * `in_progress` forever and block the next attempt — the same endpoint the Discard button uses, so
   * the attempt is still recorded as cancelled rather than deleted.
   */
  const abandon = useCallback(async () => {
    const callId = crmCallIdRef.current;
    crmCallIdRef.current = null;

    if (!callId) return;

    try {
      await apiClient.patch(`/calls/${callId}/discard`);
    } catch {
      // Best effort. The BD can still discard it by hand from the panel, which is why this failure
      // is not surfaced on top of the one that already was.
    }
    invalidate();
  }, [invalidate]);

  /** Report a failure once per attempt — see `reportedRef`. */
  const fail = useCallback((title: string, description: string) => {
    if (reportedRef.current) return;
    reportedRef.current = true;
    toast.error(title, description);
  }, []);

  /**
   * A call Twilio actually carried has ended. The completion webhook writes its outcome and the
   * carrier's duration server-side, usually within a second or two — but until it lands the record
   * still reads `in_progress`, and showing the manual Complete / Discard controls in that gap invites a
   * BD to discard a call that is about to be concluded properly. So the panel stays on "Finishing…"
   * and re-reads the record until it is concluded, then goes idle. If the webhook never arrives (a
   * tunnel down, an outage) the wait is bounded, and the manual controls are the fallback as before.
   */
  const finalize = useCallback(
    (callId: string | null) => {
      teardown();
      crmCallIdRef.current = null;
      if (!mountedRef.current) return;

      setState("finalizing");
      setIsMuted(false);
      const deadline = Date.now() + FINALIZE_MAX_MS;

      const check = async () => {
        if (!mountedRef.current) return;
        await queryClient
          .refetchQueries({ queryKey: queryKeys.leads.calls(leadId) })
          .catch(() => undefined);
        const calls = queryClient.getQueryData<Call[]>(queryKeys.leads.calls(leadId));

        if (isStillOpen(calls, callId) && Date.now() < deadline) {
          finalizeTimerRef.current = setTimeout(() => void check(), FINALIZE_POLL_MS);
          return;
        }

        invalidate();
        reset();
      };

      void check();
    },
    [invalidate, leadId, queryClient, reset, teardown],
  );

  /** Attach the live-call listeners. Everything the UI shows during a call comes from here. */
  const attach = useCallback(
    (call: TwilioCall) => {
      callRef.current = call;

      call.on("ringing", () => {
        if (mountedRef.current) setState("ringing");
      });

      call.on("accept", () => {
        acceptedRef.current = true;
        if (!mountedRef.current) return;
        setState("connected");
        // The clock starts when the carrier says someone answered — the one moment that is real
        // talk time rather than an approximation of it.
        setConnectedAt(Date.now());
      });

      call.on("mute", (muted: boolean) => {
        if (mountedRef.current) setIsMuted(muted);
      });

      // Network loss mid-call: the SDK tries to restore the media path by itself. The panel must not
      // keep claiming "connected" while there is no audio — it says so, and returns to connected only
      // when the SDK confirms the leg is back.
      call.on("reconnecting", () => {
        if (!mountedRef.current) return;
        setState("reconnecting");
        toast.warning(
          "Connection lost — reconnecting…",
          "Stay on the line. The call resumes by itself if your network comes back.",
        );
      });

      call.on("reconnected", () => {
        if (mountedRef.current) setState("connected");
      });

      call.on("disconnect", () => {
        // The completion webhook writes the outcome and the carrier's duration server-side; wait for
        // it rather than showing a record that is about to change. See finalize().
        finalize(crmCallIdRef.current);
      });

      call.on("cancel", () => {
        crmCallIdRef.current = null;
        reset();
        invalidate();
      });

      call.on("error", (error: { code?: number; message?: string }) => {
        fail("Call failed", describeTwilioError(error));
        if (acceptedRef.current) {
          // The call was genuinely CONNECTED, so it is never discarded: Twilio carried it, and its
          // completion callback records the real outcome and talk time.
          finalize(crmCallIdRef.current);
          return;
        }
        void abandon();
        reset();
      });
    },
    [abandon, fail, finalize, invalidate, reset],
  );

  const start = useCallback(async () => {
    if (state !== "idle") return;

    acceptedRef.current = false;
    reportedRef.current = false;
    setState("requesting");

    let crmCall: Call;

    try {
      // The CRM record first — and the server, not this request, decides the transport.
      crmCall = await apiClient
        .post(`/leads/${leadId}/calls`, { browser: true })
        .then((r) => r.data.data as Call);
    } catch (err) {
      // Nothing was created (an undialable number is refused before any record exists), so there is
      // nothing to discard.
      toast.error(getApiErrorMessage(err, "Couldn't start the call"));
      reset();
      return;
    }

    crmCallIdRef.current = crmCall.id;
    invalidate();

    try {
      const credentials = await apiClient
        .post(`/calls/${crmCall.id}/token`)
        .then((r) => r.data.data as BrowserToken);

      if (!credentials.correlation) {
        // Only a refresh for an already-bridged call comes back without one; a fresh call always has it.
        throw new Error("Browser calling could not be started.");
      }
      const correlation = credentials.correlation;

      // The SDK arrives here and nowhere else — see the note at the top of this file.
      const { Device, Call: TwilioCallClass } = await import("@twilio/voice-sdk");

      const device = new Device(credentials.token, {
        // Opus first for quality, PCMU as the fallback every carrier accepts. Taken from the SDK's
        // own enum rather than written as strings, so a rename cannot silently become an unknown
        // codec the browser ignores.
        codecPreferences: [TwilioCallClass.Codec.Opus, TwilioCallClass.Codec.PCMU],
        // The SDK is chatty at its default level and this runs in a customer's console.
        logLevel: "error",
      });

      deviceRef.current = device;

      device.on("error", (error: { code?: number; message?: string }) => {
        fail("Calling error", describeTwilioError(error));
        if (acceptedRef.current) {
          finalize(crmCallIdRef.current);
          return;
        }
        void abandon();
        reset();
      });

      // The Access Token is short-lived, a long conversation outlives it, and the SDK needs a valid
      // one to restore signalling after a network blip. Refreshed in place from the same endpoint —
      // once the call is bridged the server answers with a credential that carries NO correlation, so
      // a refresh can keep this leg alive but can never place another call.
      device.on("tokenWillExpire", () => {
        void apiClient
          .post(`/calls/${crmCall.id}/token`)
          .then((r) => device.updateToken((r.data.data as BrowserToken).token))
          .catch(() => {
            // The live leg continues on the current token. If a later reconnect needs a fresh one and
            // cannot get it, that surfaces as an error on the call, reported then.
          });
      });

      if (!mountedRef.current) {
        // The BD navigated away while the token was in flight.
        await abandon();
        teardown();
        return;
      }

      setState("connecting");

      // The ONLY thing sent to Twilio is the opaque correlation token. The number to dial is read
      // server-side from the call record this token names.
      const call = await device.connect({
        params: { correlation },
      });

      attach(call);
    } catch (err) {
      fail("Couldn't place the call", browserFailureHint(err));
      await abandon();
      reset();
    }
  }, [abandon, attach, fail, finalize, invalidate, leadId, reset, state, teardown]);

  /** Hang up. The completion webhook still writes the outcome; this only ends the audio. */
  const hangUp = useCallback(() => {
    if (!callRef.current) return;
    setState("ending");
    callRef.current.disconnect();
  }, []);

  const toggleMute = useCallback(() => {
    const call = callRef.current;
    if (!call) return;
    const next = !call.isMuted();
    call.mute(next);
    // The `mute` event confirms it, but setting here keeps the button responsive if it does not fire.
    setIsMuted(next);
  }, []);

  return {
    state,
    /** True from the moment the CRM record is created until the audio leg is gone. */
    isActive: state !== "idle",
    isConnected: state === "connected",
    isMuted,
    connectedAt,
    start,
    hangUp,
    toggleMute,
  };
}

// The error-message helpers live in `lib/browser-call.ts`, where `scripts/calling-gates` pins them.
