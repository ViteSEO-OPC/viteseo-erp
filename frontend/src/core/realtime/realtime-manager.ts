"use client";

import type Echo from "laravel-echo";
import { getEcho, teardownEcho } from "./echo";

/**
 * THE single owner of the realtime layer: the Echo singleton, the connection, every
 * channel subscription, and every cleanup.
 *
 * ── Why this exists ──────────────────────────────────────────────────────────
 *
 * `user.{id}` is a SHARED channel. The CRM listens on it for notifications and for
 * lead-assignment toasts; Projects listens on it for its own notifications. Before
 * this module, each of those called `getEcho()` and drove the channel itself, and
 * `RealtimeProvider` additionally called `echo.leave('user.{id}')` in its cleanup
 * and `teardownEcho()` on any not-yet-authenticated render.
 *
 * That is a lifecycle race, not a style problem. `echo.leave(name)` unsubscribes the
 * CHANNEL — it removes every handler bound to it, including handlers belonging to a
 * module that is still mounted and still expects them. React runs child effects
 * before parent effects, and the CRM listener and the provider read DIFFERENT auth
 * contexts, so the interleaving that decides whether the CRM keeps its handler was
 * decided by render timing. It happened to hold; nothing made it hold.
 *
 * ── How this makes it impossible ─────────────────────────────────────────────
 *
 * Two rules, both structural:
 *
 *  1. LISTENERS ARE DECLARED INTENT, NOT SOCKET CALLS. `subscribe()` records a
 *     (channel, event, listener) triple in `entries` whether or not a connection
 *     exists, and the manager reconciles that registry against the socket whenever
 *     the socket appears or disappears. A feature can therefore subscribe before,
 *     during or after connect and end up in the same state — effect ordering stops
 *     being a variable. This is what actually kills the race; refcounting alone
 *     would not.
 *
 *  2. ONLY THIS MODULE TOUCHES THE SOCKET. `echo.private()`, `echo.leave()` and
 *     `teardownEcho()` appear nowhere else in the app — enforced by the
 *     `no-restricted-imports` rule in eslint.config.mjs, so a future feature that
 *     reaches for them fails lint rather than shipping a new race. A channel is left
 *     exactly when its LAST listener unsubscribes, so one module unmounting can no
 *     longer strand another.
 *
 * The connection itself is refcounted across {@link acquireRealtime} /
 * {@link releaseRealtime} because RealtimeProvider is mounted per section (CRM and
 * Projects both mount one) and a route change can overlap the two. Refcounting means
 * the socket survives the handover instead of being closed by the outgoing layout
 * after the incoming one already opened it.
 */

/** A channel handler. Payload shape is the caller's business; see `useRealtimeEvent`. */
type Listener = (payload: unknown) => void;

/** What `echo.private()` returns — narrowed to the two methods this module uses. */
type EchoChannel = {
  listen: (event: string, cb: Listener) => unknown;
  stopListening: (event: string, cb: Listener) => unknown;
};

type ChannelEntry = {
  /** Desired listeners, by event. Survives disconnects — this is the intent. */
  listeners: Map<string, Set<Listener>>;
  /** The live channel while connected; null whenever there is no socket. */
  channel: EchoChannel | null;
};

/** Every channel any feature currently wants, connected or not. */
const entries = new Map<string, ChannelEntry>();

/**
 * Reconnect subscribers. `sawConnected` is per-subscriber, not global: a feature that
 * mounts while the socket is ALREADY up must treat the next `connected` as a genuine
 * reconnect and backfill, whereas one that mounts before the first connect must skip
 * that first event (its initial REST fetches already cover current state).
 */
type ReconnectSub = { fn: () => void; sawConnected: boolean };
const reconnectSubs = new Set<ReconnectSub>();

/** How many RealtimeProvider instances currently want a connection. */
let owners = 0;
/** Whether the manager has an open socket it is managing. */
let live = false;

type PusherConnection = {
  state?: string;
  bind?: (event: string, cb: () => void) => void;
  unbind?: (event: string, cb: () => void) => void;
};

/**
 * Reach the underlying pusher-js connection. laravel-echo does not surface it, and
 * the reconnect signal only exists there. Isolated to this one function so the cast
 * is written once rather than in every feature that wants a backfill.
 */
function pusherConnection(echo: Echo<"reverb">): PusherConnection | undefined {
  return (echo.connector as unknown as { pusher?: { connection?: PusherConnection } })
    .pusher?.connection;
}

function connectionState(): string | undefined {
  const echo = live ? getEcho() : null;
  return echo ? pusherConnection(echo)?.state : undefined;
}

function handleConnected(): void {
  for (const sub of reconnectSubs) {
    if (sub.sawConnected) sub.fn();
    else sub.sawConnected = true;
  }
}

/** Attach a channel to the socket and bind every listener already recorded for it. */
function bindEntry(echo: Echo<"reverb">, name: string, entry: ChannelEntry): void {
  if (entry.channel) return;
  const channel = echo.private(name) as unknown as EchoChannel;
  entry.channel = channel;
  for (const [event, listeners] of entry.listeners) {
    for (const listener of listeners) channel.listen(event, listener);
  }
}

function openConnection(): void {
  if (live) return;
  const echo = getEcho();
  // Realtime is optional: with no NEXT_PUBLIC_REVERB_APP_KEY, getEcho() returns null
  // and everything below stays inert. Subscriptions are still recorded, so enabling
  // the key later needs no change at any call site.
  if (!echo) return;

  live = true;
  pusherConnection(echo)?.bind?.("connected", handleConnected);
  for (const [name, entry] of entries) bindEntry(echo, name, entry);
}

function closeConnection(): void {
  if (!live) return;
  const echo = getEcho();
  if (echo) pusherConnection(echo)?.unbind?.("connected", handleConnected);

  // Drop the channel handles but KEEP the listener registry: the socket is going
  // away wholesale, so per-channel leave() would be redundant, and the intent must
  // survive so a later acquire re-binds everything exactly as it was.
  for (const entry of entries.values()) entry.channel = null;
  // A subscriber that outlives the socket must not skip the backfill on the next
  // connect — that connect IS the reconnect it exists for.
  for (const sub of reconnectSubs) sub.sawConnected = true;

  live = false;
  teardownEcho();
}

/**
 * Declare that a RealtimeProvider wants the connection open. Owner-only — features
 * never call this.
 */
export function acquireRealtime(): void {
  owners += 1;
  if (owners === 1) openConnection();
}

/** Release one provider's claim; the socket closes when the last one lets go. */
export function releaseRealtime(): void {
  owners = Math.max(0, owners - 1);
  if (owners === 0) closeConnection();
}

/**
 * Register a listener for one event on one channel. Returns its unsubscribe.
 *
 * Safe to call at any time, connected or not. The channel is joined on the first
 * listener and left on the last — which is the whole point: a module unsubscribing
 * can never remove another module's handlers, because it only ever removes its own.
 */
export function subscribe(name: string, event: string, listener: Listener): () => void {
  let entry = entries.get(name);
  if (!entry) {
    entry = { listeners: new Map(), channel: null };
    entries.set(name, entry);
  }

  let listeners = entry.listeners.get(event);
  if (!listeners) {
    listeners = new Set();
    entry.listeners.set(event, listeners);
  }
  listeners.add(listener);

  const echo = live ? getEcho() : null;
  if (echo) {
    // Either the channel is new — bindEntry binds everything including this
    // listener — or it already exists and only the new listener needs binding.
    // Never both, so a listener is bound exactly once.
    if (!entry.channel) bindEntry(echo, name, entry);
    else entry.channel.listen(event, listener);
  }

  return () => {
    const current = entries.get(name);
    if (!current) return;

    const set = current.listeners.get(event);
    // Guard against a double-invoked cleanup removing someone else's bookkeeping.
    if (!set?.delete(listener)) return;

    current.channel?.stopListening(event, listener);
    if (set.size === 0) current.listeners.delete(event);

    if (current.listeners.size === 0) {
      // Last listener on this channel — and only now — actually leave it.
      if (current.channel) getEcho()?.leave(name);
      entries.delete(name);
    }
  };
}

/**
 * Run `fn` after the socket RE-establishes, so a feature can refetch whatever it
 * missed while offline. Never fires for the initial connection.
 */
export function onReconnect(fn: () => void): () => void {
  const sub: ReconnectSub = { fn, sawConnected: connectionState() === "connected" };
  reconnectSubs.add(sub);
  return () => {
    reconnectSubs.delete(sub);
  };
}

/**
 * Live view of the manager's own bookkeeping — the thing to read when asking whether
 * a subscription leaked, duplicated, or was orphaned by a navigation.
 *
 * Because every subscription now flows through one registry, those questions have a
 * direct answer instead of an argument: a channel with no listeners cannot exist (it
 * is deleted on the last unsubscribe), and a listener count that grows as you
 * navigate is a leak you can see. Exposed on `window.__realtime` outside production
 * (see below) so it is reachable from the console and from automation.
 */
export function realtimeDebugState(): {
  owners: number;
  live: boolean;
  connectionState: string | undefined;
  reconnectSubs: number;
  channels: Array<{ name: string; bound: boolean; events: Record<string, number> }>;
} {
  return {
    owners,
    live,
    connectionState: connectionState(),
    reconnectSubs: reconnectSubs.size,
    channels: [...entries].map(([name, entry]) => ({
      name,
      bound: entry.channel !== null,
      events: Object.fromEntries([...entry.listeners].map(([e, s]) => [e, s.size])),
    })),
  };
}

// Dev-only diagnostic handle. Stripped from production builds by the constant-folded
// NODE_ENV check, so this ships nothing — it exists so realtime lifecycle questions
// ("did that navigation leave a listener behind?") can be answered by reading state
// rather than by inference.
if (process.env.NODE_ENV !== "production" && typeof window !== "undefined") {
  (window as unknown as { __realtime?: typeof realtimeDebugState }).__realtime =
    realtimeDebugState;
}
