"use client";

import { useCallback, useEffect, useMemo, useState, type ReactNode } from "react";

import {
  TABLE_DENSITY_STORAGE_KEY,
  TableDensityContext,
  type TableDensity,
} from "@/modules/crm/components/ui/table-density";

/**
 * Zerem-only Compact Tables preference.
 *
 * Frontend-only and per-browser by design: the value lives in `localStorage` under a
 * Zerem-namespaced key, exactly as the theme does. Nothing is stored server-side, so there is no
 * migration and no `users.preferences` write — a later move to server persistence would change
 * only this file.
 *
 * The restore/persist split mirrors {@link ../providers/theme-provider.tsx} and is load-bearing for
 * the same two reasons:
 *
 *  - Restoring in an EFFECT (not the useState initializer) keeps the server-rendered markup and the
 *    first client render in agreement. The visible cost is that a compact user sees one normal-
 *    density paint before it flips — the same trade the theme already makes.
 *  - Persisting ONLY on an explicit change, never in a mount effect, so the stored value cannot be
 *    clobbered by the `normal` default before the restore runs (or twice, under StrictMode).
 */
export function TableDensityProvider({ children }: { children: ReactNode }) {
  const [density, setDensityState] = useState<TableDensity>("normal");

  useEffect(() => {
    try {
      const stored = localStorage.getItem(TABLE_DENSITY_STORAGE_KEY);
      if (stored === "normal" || stored === "compact") {
        // Restoring a persisted choice on mount — the intended reset-to-baseline, not the
        // cascading-render pattern this rule targets.
        // eslint-disable-next-line react-hooks/set-state-in-effect
        setDensityState(stored);
      }
    } catch {
      // Private browsing / blocked site data: the preference simply stays at its default.
    }
  }, []);

  const setDensity = useCallback((next: TableDensity) => {
    setDensityState(next);
    try {
      localStorage.setItem(TABLE_DENSITY_STORAGE_KEY, next);
    } catch {
      // Unwritable storage must not break the toggle — it just will not survive a reload.
    }
  }, []);

  const value = useMemo(() => ({ density, setDensity }), [density, setDensity]);

  return <TableDensityContext.Provider value={value}>{children}</TableDensityContext.Provider>;
}
