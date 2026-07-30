"use client";

import { useCallback, useEffect, useState } from "react";

/**
 * State backed by localStorage, safe for SSR/hydration.
 *
 * The initial render always uses `defaultValue` (deterministic on the server),
 * then the stored value is read on the client after mount. Pass a `key` of
 * `null` to disable persistence (e.g. before the current user has resolved);
 * the hook then behaves as plain in-memory state and re-reads once a real key
 * arrives — this is how preferences are scoped per user.
 */
export function usePersistentState<T>(
  key: string | null,
  defaultValue: T,
): [T, (value: T | ((prev: T) => T)) => void] {
  const [value, setValue] = useState<T>(defaultValue);

  // Read (or re-read) the persisted value whenever the key changes. The key
  // going from null → "…:<userId>" is what loads a specific user's preference.
  useEffect(() => {
    if (!key) return;
    try {
      const raw = window.localStorage.getItem(key);
      if (raw !== null) {
        // eslint-disable-next-line react-hooks/set-state-in-effect
        setValue(JSON.parse(raw) as T);
      }
    } catch {
      /* localStorage unavailable or corrupt entry — keep the in-memory value */
    }
  }, [key]);

  const set = useCallback(
    (next: T | ((prev: T) => T)) => {
      setValue((prev) => {
        const resolved =
          typeof next === "function"
            ? (next as (p: T) => T)(prev)
            : next;
        if (key) {
          try {
            window.localStorage.setItem(key, JSON.stringify(resolved));
          } catch {
            /* ignore persistence failures (private mode, quota, etc.) */
          }
        }
        return resolved;
      });
    },
    [key],
  );

  return [value, set];
}
