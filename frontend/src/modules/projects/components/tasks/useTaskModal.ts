"use client";

import { usePathname, useRouter } from "next/navigation";
import { useCallback } from "react";

/** URL query param that drives the global task-view modal. */
export const TASK_PARAM = "task";

/**
 * Returns `openTask(taskId)` which opens the global task-view modal by writing
 * `?task={uuid}` onto the current URL. Reads the live query string from
 * `window.location` at call time (inside an event handler) instead of
 * `useSearchParams`, so callers don't need their own Suspense boundary.
 */
export function useOpenTask(): (taskId: string) => void {
  const router = useRouter();
  const pathname = usePathname();

  return useCallback(
    (taskId: string) => {
      const params = new URLSearchParams(
        typeof window !== "undefined" ? window.location.search : "",
      );
      params.set(TASK_PARAM, taskId);
      router.push(`${pathname}?${params.toString()}`, { scroll: false });
    },
    [router, pathname],
  );
}
