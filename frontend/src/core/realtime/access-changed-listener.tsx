"use client";

import { useAuth } from "@/core/auth-context";
import { useRealtimeEvent } from "@/core/realtime/use-realtime";

/** What arrives on `user.{id}` when what this person may use has changed. */
interface AccessChangedPayload {
  signed_out: boolean;
  reason: string;
}

const ACCESS_CHANGED = ".AccessChanged";

/**
 * Re-reads who this browser is the moment the server says their access changed: a
 * role taken or given, a CRM suspension, the account closed, this very session
 * signed out from another device's list, the password changed elsewhere.
 *
 * AuthProvider.refresh() does the rest. No session left, and <RequireAuth> sends
 * them to sign in; fewer systems, and <RequireModule> walks them out of the one they
 * lost; more, and the launcher shows it without signing out and back in.
 *
 * Mounted inside a <RealtimeProvider> section (CRM, Projects) beside the other
 * `user.{id}` listeners. HRIS and Accounting open no socket by design; the same
 * re-read reaches them when a tab comes back to the front.
 */
export function AccessChangedListener() {
  const { user, refresh } = useAuth();

  useRealtimeEvent<AccessChangedPayload>(
    user?.id == null ? null : `user.${user.id}`,
    ACCESS_CHANGED,
    () => void refresh(),
  );

  return null;
}
