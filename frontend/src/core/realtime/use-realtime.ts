"use client";

import { useEffect, useRef } from "react";
import { onReconnect, subscribe } from "./realtime-manager";

/**
 * The ONLY realtime API feature modules use: register a callback, get it called.
 *
 * Everything about the socket — creating it, joining the channel, leaving it,
 * tearing it down — belongs to {@link realtime-manager} and is deliberately not
 * reachable from here. A feature declares what it wants to hear about; it never
 * owns the transport that delivers it.
 */

/**
 * Listen to one event on one channel for as long as this component is mounted.
 *
 * Pass `null` as the channel to subscribe nothing — the normal way to express "not
 * ready yet" (no user id, no lead id) or "stop listening now", without a conditional
 * hook call.
 *
 * The handler may be an inline closure: it is held in a ref and read at call time, so
 * a new function identity on every render does NOT resubscribe. Only the channel or
 * event changing does that.
 */
export function useRealtimeEvent<TPayload>(
  channel: string | null | undefined,
  event: string,
  handler: (payload: TPayload) => void,
): void {
  const handlerRef = useRef(handler);
  useEffect(() => {
    handlerRef.current = handler;
  });

  useEffect(() => {
    if (!channel) return;
    return subscribe(channel, event, (payload) => handlerRef.current(payload as TPayload));
  }, [channel, event]);
}

/**
 * Run `handler` whenever the socket re-establishes after a drop — the hook for
 * backfilling whatever was missed while offline.
 *
 * Never fires for the FIRST connection: if this mounts before the socket is up, that
 * connect is not a reconnect and the component's own initial fetches already cover
 * it; if it mounts while the socket is already up, the next connect genuinely is one.
 * That distinction is the manager's, so every feature gets it right by default.
 */
export function useRealtimeReconnect(handler: () => void): void {
  const handlerRef = useRef(handler);
  useEffect(() => {
    handlerRef.current = handler;
  });

  useEffect(() => onReconnect(() => handlerRef.current()), []);
}
