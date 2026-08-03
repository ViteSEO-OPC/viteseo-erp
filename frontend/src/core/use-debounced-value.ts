"use client";

import { useEffect, useState } from "react";

/**
 * Returns `value` delayed by `delay` ms, resetting the timer on every change —
 * so a fast typist produces one settled value instead of one per keystroke.
 *
 * Use it to keep an input responsive (bind the raw value) while the expensive
 * work downstream (a query, a filter pass) only sees the debounced one.
 *
 * ```ts
 * const [q, setQ] = useState('');
 * const debouncedQ = useDebouncedValue(q, 250);
 * const { data } = useQuery({ queryKey: ['search', debouncedQ], ... });
 * ```
 */
export function useDebouncedValue<T>(value: T, delay = 250): T {
  const [debounced, setDebounced] = useState(value);

  useEffect(() => {
    const timer = setTimeout(() => setDebounced(value), delay);
    return () => clearTimeout(timer);
  }, [value, delay]);

  return debounced;
}
