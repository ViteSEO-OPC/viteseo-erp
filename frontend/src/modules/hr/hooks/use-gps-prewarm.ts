"use client";

import { useCallback, useEffect, useRef, useState } from "react";

import type { LocationFix } from "@/modules/hr/types/attendance";

/**
 * Ask the browser where it is BEFORE the employee needs it.
 *
 * A cold geolocation fix takes 2–15 seconds. Requesting it when someone taps "I'm at the
 * office" would make them stand there watching a spinner, and `time_in` is stamped when the
 * request reaches the server — so the delay would read as lateness. Instead the request
 * starts the moment the dialog OPENS, and by the time anyone taps, the answer is already
 * sitting in a ref.
 *
 * The options matter as much as the timing:
 *   • `enableHighAccuracy: false` — high accuracy is what makes GPS slow, and it buys
 *     nothing against a fence measured in hundreds of metres.
 *   • `maximumAge: 60_000` — a fix from the last minute is reused instantly. Reopening the
 *     dialog is then free.
 *   • `timeout: 5_000` — past five seconds we stop waiting. No fix is a legitimate answer:
 *     the office network alone can verify the clock-in.
 */
const FAST_FIX: PositionOptions = {
  enableHighAccuracy: false,
  maximumAge: 60_000,
  timeout: 5_000,
};

/** Why we have no position — only ever used to explain, never to block. */
export type GpsState = "locating" | "ready" | "denied" | "unavailable";

/**
 * Resolves rather than rejects, always. A denial or a timeout is not an error condition
 * here — it is the ordinary desktop path, and the caller's next step is identical either
 * way: send the clock-in, with coordinates if we have them.
 */
function requestFix(): Promise<{ fix: LocationFix | null; state: GpsState }> {
  if (typeof navigator === "undefined" || !navigator.geolocation) {
    return Promise.resolve({ fix: null, state: "unavailable" });
  }

  return new Promise((resolve) => {
    navigator.geolocation.getCurrentPosition(
      (pos) =>
        resolve({
          fix: {
            lat: pos.coords.latitude,
            lng: pos.coords.longitude,
            accuracy: pos.coords.accuracy,
          },
          state: "ready",
        }),
      (err) =>
        resolve({
          fix: null,
          // PERMISSION_DENIED is worth naming: it's the one case the employee can fix
          // themselves, so the dialog can say so. Position-unavailable and timeout are
          // indistinguishable to them and both just mean "we'll use the network instead".
          state: err.code === err.PERMISSION_DENIED ? "denied" : "unavailable",
        }),
      FAST_FIX,
    );
  });
}

/**
 * Starts a position fix when the component using it MOUNTS. Call it from inside the
 * dialog's content, which Radix mounts on open and unmounts on close — so "the dialog
 * opened" and "this hook mounted" are the same event, and reopening starts a fresh request
 * that the browser answers from its 60-second cache.
 *
 * @returns `state` for display, and `getFix()` which resolves to the pre-warmed position
 *          (immediately, in the normal case) or null when there isn't one.
 */
export function useGpsPrewarm() {
  const pending = useRef<Promise<{ fix: LocationFix | null; state: GpsState }> | null>(null);
  // "locating" from the very first render: the request goes out in the effect below, and
  // there is no frame in which we are mounted and not yet trying.
  const [state, setState] = useState<GpsState>("locating");

  useEffect(() => {
    const promise = requestFix();
    pending.current = promise;

    let live = true;
    void promise.then((r) => {
      if (live) setState(r.state);
    });

    return () => {
      // Unmounted before the fix landed. The promise is left to settle — a geolocation
      // request can't be cancelled, and its result is harmless once nobody is listening.
      live = false;
    };
  }, []);

  /**
   * The pre-warmed fix. Awaits the in-flight request if it hasn't landed yet (so an
   * impatient tap still works), and starts one from scratch only if there is none.
   */
  const getFix = useCallback(async (): Promise<LocationFix | null> => {
    const result = await (pending.current ??= requestFix());
    return result.fix;
  }, []);

  return { state, getFix };
}
