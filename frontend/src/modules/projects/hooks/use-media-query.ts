"use client";

import { useCallback, useSyncExternalStore } from "react";

/**
 * Match a CSS media query from React.
 *
 * The notes surfaces don't just restyle across breakpoints — they change shape
 * (a pushed panel becomes an overlay drawer becomes a bottom sheet), so the
 * decision has to reach the component tree, not just the class list.
 *
 * `useSyncExternalStore` is the right shape for this: matchMedia is an external
 * store, and the server snapshot is false so SSR and the first client render
 * agree before the real value is read.
 */
export function useMediaQuery(query: string): boolean {
  const subscribe = useCallback(
    (onChange: () => void) => {
      const list = window.matchMedia(query);
      list.addEventListener("change", onChange);
      return () => list.removeEventListener("change", onChange);
    },
    [query],
  );

  return useSyncExternalStore(
    subscribe,
    () => window.matchMedia(query).matches,
    () => false,
  );
}

/** Tailwind's `md` — at/above this, notes can live inline instead of in a sheet. */
export const MD_UP = "(min-width: 768px)";
/** Tailwind's `xl` — at/above this there's room to push content aside. */
export const XL_UP = "(min-width: 1280px)";
