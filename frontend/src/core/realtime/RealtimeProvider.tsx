"use client";

import { useEffect, type ReactNode } from "react";
import { useAuth } from "@/core/auth-context";
import { acquireRealtime, releaseRealtime } from "./realtime-manager";

/**
 * Declares that this section of the app wants a realtime connection, for as long as
 * the user is authenticated. That is its ENTIRE job.
 *
 * It used to do considerably more — create the Echo singleton, subscribe
 * `user.{id}`, carry the Projects notification handler, `echo.leave()` that channel
 * on cleanup and `teardownEcho()` on any unauthenticated render. Those last two are
 * why the Notification Center could silently stop receiving events: `leave()` drops
 * EVERY handler on a shared channel, including the CRM's, and whether that happened
 * before or after the CRM attached its own was decided by React effect ordering
 * across two different auth contexts. Owning both the transport and one module's
 * business logic is what made that possible, so the two are now separated:
 *
 *   - the transport belongs to `realtime-manager`, which nothing else touches;
 *   - Projects' notification handler belongs to Projects
 *     (`modules/projects/use-project-notifications`), mounted as a listener
 *     alongside the CRM's, with neither able to disturb the other.
 *
 * Mounted per section (CRM and Projects each mount one) rather than at the root, so
 * HRIS and the rest of the platform still open no socket. The claim is REFCOUNTED in
 * the manager: navigating CRM → Projects overlaps the two providers, and refcounting
 * keeps the socket up across the handover instead of letting the outgoing layout
 * close a connection the incoming one just opened.
 *
 * Reads the platform auth context, not any module's shim — this is platform
 * infrastructure and must not depend on a module.
 */
export function RealtimeProvider({ children }: { children: ReactNode }) {
  const { user, loading } = useAuth();
  const userId = user?.id;

  useEffect(() => {
    // Nothing to own until there is an authenticated user. Note what is NOT here any
    // more: an `else` branch tearing the socket down. Releasing the claim on cleanup
    // covers logout, and the old eager teardown was exactly the call that could kill
    // a channel a child had already subscribed to.
    if (loading || userId == null) return;

    acquireRealtime();
    return () => releaseRealtime();
  }, [loading, userId]);

  return <>{children}</>;
}
