// API functions for auth. These are the ONLY place that knows the auth URLs
// and unwraps the `{ data: ... }` envelope. Components/hooks call these.

import { apiClient } from "@/core/api-client";
import type { LoginResponse, MeResponse, SignedInDevice } from "@/core/types/auth";

/**
 * Have Laravel set the XSRF-TOKEN cookie before a session's first write.
 *
 * Under /api, not Sanctum's default /sanctum, because nginx sends only /api/* to
 * Laravel; anything else lands on Next.js and 404s.
 */
async function ensureCsrfCookie(): Promise<void> {
  const base = new URL(apiClient.defaults.baseURL ?? "/", window.location.origin);
  await apiClient.get(new URL("/api/sanctum/csrf-cookie", base).toString());
}

export async function loginRequest(
  email: string,
  password: string,
): Promise<LoginResponse> {
  // Signing in is itself a cookie-authenticated write, so it needs the token first.
  await ensureCsrfCookie();
  const res = await apiClient.post("/auth/login", { email, password });
  return res.data.data;
}

/** `quiet`: a 401 comes back to the caller instead of bouncing to /login. */
export async function fetchMe({ quiet = false }: { quiet?: boolean } = {}): Promise<MeResponse> {
  const res = await apiClient.get("/auth/me", { skipAuthRedirect: quiet });
  return res.data.data;
}

export async function logoutRequest(): Promise<void> {
  await apiClient.post("/auth/logout");
}

/** Every browser the user is signed in on. */
export async function fetchSessions(): Promise<SignedInDevice[]> {
  const res = await apiClient.get("/auth/sessions");
  return res.data.data;
}

/** Sign one of them out -- by its key, never its session id. */
export async function endSession(key: string): Promise<void> {
  await apiClient.delete(`/auth/sessions/${key}`);
}

/** Sign out of every browser except this one. */
export async function endOtherSessions(): Promise<void> {
  await apiClient.delete("/auth/sessions");
}
