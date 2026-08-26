"use client";

import { createContext, useContext } from "react";

/**
 * The CRM's table VERTICAL density, in one place — and the Compact Tables preference that drives it.
 *
 * Every data table used to carry its own row height as a literal — `py-3.5` here, `py-3` there,
 * `h-10` in one header and `h-9` in the next — spread across five files and repeated inline on
 * individual cells. Because `cn()` is tailwind-merge, a consumer's literal always beat the
 * primitive's default, so there was no single place a density change could be made at all.
 *
 * ── Scope, deliberately narrow ───────────────────────────────────────────────────────────────
 * VERTICAL ONLY. Horizontal padding (`px-2` / `px-3` / `px-4`), font size, column widths,
 * alignment, and every chip, badge and avatar are untouched in both modes. They differ per table
 * for layout reasons that have nothing to do with density.
 *
 * ── Normal is not a scale of Compact ─────────────────────────────────────────────────────────
 * `normal` holds the values the CRM renders TODAY, not an ideal. Three tiers exist because the
 * tables genuinely differ, and a table may legitimately mix them — BD Performance pairs a
 * condensed header with standard rows, Recent Leads does the reverse, and Team Accounts has one
 * tighter cell among standard ones. That drift is PRESERVED EXACTLY in normal mode.
 *
 * In `compact` the two explicit row tiers deliberately converge on `py-2`: compact is a designed
 * state, not a proportional shrink, and replicating a 2px accident there would serve nobody. The
 * `primitive` tier stays the tightest — as it already is in normal — because those five tables
 * render at `py-2` TODAY. A flat compact of `py-2` would leave half the CRM's tables unchanged and
 * make the toggle look broken on Archive, Planner History, Rules, Version and Audit History.
 *
 * ── What compact cannot fix ──────────────────────────────────────────────────────────────────
 * Team Accounts and BD Performance carry a fixed 32px avatar. Once padding drops to 8px the avatar
 * IS the row height, so those tables compact by ~14% while Leads compacts by ~25%. That asymmetry
 * is intentional: shrinking avatars to even the numbers out would change content, which this
 * feature does not do.
 */

export type TableDensity = "normal" | "compact";

/**
 * Vertical tokens per mode.
 *
 * `head`/`row` are consumed by the five tables that set their own density; `primitive` is consumed
 * by {@link ../ui/table.tsx} for the five tables that never override anything, which is why those
 * five need no changes to follow the preference.
 */
export type TableDensityTokens = {
  head: { standard: string; condensed: string };
  row: { standard: string; condensed: string };
  primitive: { head: string; cell: string };
};

const TOKENS: Readonly<Record<TableDensity, TableDensityTokens>> = Object.freeze({
  normal: Object.freeze({
    head: Object.freeze({ standard: "h-10", condensed: "h-9" }),
    row: Object.freeze({ standard: "py-3.5", condensed: "py-3" }),
    primitive: Object.freeze({ head: "h-10", cell: "py-2" }),
  }),
  compact: Object.freeze({
    head: Object.freeze({ standard: "h-8", condensed: "h-8" }),
    row: Object.freeze({ standard: "py-2", condensed: "py-2" }),
    primitive: Object.freeze({ head: "h-8", cell: "py-1.5" }),
  }),
});

export type TableDensityContextValue = {
  density: TableDensity;
  setDensity: (density: TableDensity) => void;
};

/**
 * Defaults to `normal` with an inert setter rather than throwing when no provider is mounted.
 *
 * `Table` is a generic primitive: a missing provider must never crash a page that merely renders a
 * table. The preference simply reads as off, which is also the correct fallback. (`useTheme` throws
 * instead, and that is right for a whole-app theme — it is not right for one UI primitive.)
 */
export const TableDensityContext = createContext<TableDensityContextValue>({
  density: "normal",
  setDensity: () => {},
});

/** The Compact Tables preference itself — for the Settings toggle. */
export function useTableDensityPreference(): TableDensityContextValue {
  return useContext(TableDensityContext);
}

/**
 * The vertical density classes for the current mode.
 *
 * Compose with the table's own horizontal padding and text classes:
 *
 *   const density = useTableDensity();
 *   const th = cn(density.head.standard, "px-3 text-xs font-semibold uppercase");
 *   const td = cn(density.row.standard, "px-3 text-sm");
 */
export function useTableDensity(): TableDensityTokens {
  return TOKENS[useContext(TableDensityContext).density];
}

/** The token table itself, for tests and for the provider. Not for component use. */
export const TABLE_DENSITY_TOKENS = TOKENS;

/** Zerem-namespaced, matching the theme key's convention so no other app can collide with it. */
export const TABLE_DENSITY_STORAGE_KEY = "zerem-table-density";
