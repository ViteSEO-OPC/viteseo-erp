"use client";

import { useEffect } from "react";
import { useRouter } from "next/navigation";
import { isAxiosError } from "axios";

/** Where a forbidden access attempt lands (the module's access-denied screen). */
export const ACCESS_DENIED_PATH = "/sprintly/403";

/** True when a query error is an API "forbidden" (403) response. */
export function isForbiddenError(error: unknown): boolean {
  return isAxiosError(error) && error.response?.status === 403;
}

/**
 * Redirects to the access-denied screen when a query fails with 403 — i.e. the
 * user requested a project/workspace they aren't a member of. 401 (expired
 * session) is handled globally by the api-client, so it's intentionally NOT
 * caught here. Returns whether the error was a 403 so callers can render a quiet
 * "Redirecting…" state instead of a generic error card while the nav happens.
 */
export function useForbiddenRedirect(error: unknown): boolean {
  const router = useRouter();
  const forbidden = isForbiddenError(error);

  useEffect(() => {
    if (forbidden) router.replace(ACCESS_DENIED_PATH);
  }, [forbidden, router]);

  return forbidden;
}
