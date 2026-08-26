"use client";

/**
 * The CRM's table VERTICAL density, in one place.
 *
 * Every data table used to carry its own row height as a literal — `py-3.5` here, `py-3` there,
 * `h-10` in one header and `h-9` in the next — spread across five files and repeated inline on
 * individual cells. Because `cn()` is tailwind-merge, a consumer's literal always beat the
 * primitive's default, so there was no single place a density change could be made at all.
 *
 * ── Scope, deliberately narrow ───────────────────────────────────────────────────────────────
 * VERTICAL ONLY. Horizontal padding (`px-2` / `px-3` / `px-4`), font size, and every chip, badge
 * and avatar stay exactly where they are, owned by each table. They differ per table for layout
 * reasons that have nothing to do with density, and centralising them would force a visual change
 * on tables that never asked for one.
 *
 * ── Why two tiers rather than one ────────────────────────────────────────────────────────────
 * These are the values the CRM renders TODAY, not an ideal. `standard` and `condensed` exist
 * because the tables genuinely differ, and this module's first job was to change nothing:
 *
 *   standard   h-10 / py-3.5   Leads, Team Accounts, Dashboard Recent Leads (header)
 *   condensed  h-9  / py-3     Import Mapping, BD Performance (header), Recent Leads (rows)
 *
 * A table may legitimately mix the two — BD Performance pairs a condensed header with standard
 * rows, and Recent Leads does the reverse. That mismatch is pre-existing drift, preserved on
 * purpose: normalising it would be a visual change wearing a refactor's clothes.
 *
 * ── Why a hook, when it returns a constant ───────────────────────────────────────────────────
 * So the call sites never have to change again. A later Compact Tables preference only has to
 * make THIS function read a context and return tighter values; the five tables that consume it
 * are already wired. Nothing here reads storage or context yet.
 */

/** Vertical density tokens. Frozen and module-level so the hook returns a stable reference. */
const DENSITY = Object.freeze({
  /** `<TableHead>` heights. */
  head: Object.freeze({
    standard: "h-10",
    condensed: "h-9",
  }),
  /** `<TableCell>` vertical padding. */
  row: Object.freeze({
    standard: "py-3.5",
    condensed: "py-3",
  }),
});

export type TableDensity = typeof DENSITY;

/**
 * The vertical density classes for a data table.
 *
 * Compose with the table's own horizontal padding and text classes:
 *
 *   const density = useTableDensity();
 *   const th = cn(density.head.standard, "px-3 text-xs font-semibold uppercase");
 *   const td = cn(density.row.standard, "px-3 text-sm");
 */
export function useTableDensity(): TableDensity {
  return DENSITY;
}
