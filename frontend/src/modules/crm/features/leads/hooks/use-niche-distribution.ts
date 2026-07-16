import { useMemo } from "react";
import { colorForNiche } from "@/modules/crm/features/leads/config/niche-colors";

export type NicheDistributionItem = {
  /** The industry value — doubles as the niche id and filter value (Industry = Niche). */
  id: string;
  label: string;
  color: string;
  count: number;
};

export type NicheDistribution = {
  items: NicheDistributionItem[];
  /** Current filtered result count (for "Showing X of Y"). */
  shown: number;
  /** Grand total across all industries. */
  total: number;
};

/**
 * Niche distribution built from REAL per-industry totals supplied by the API (`industry_counts`,
 * a SQL GROUP BY over the whole dataset — industry IS the niche). Counts are accurate and
 * page-independent, and every industry stays present regardless of the current selection. Chips
 * are sorted alphabetically for stable positions. Colors are assigned deterministically per
 * industry name (presentation only — not a taxonomy/mapping layer).
 *
 * @param industryCounts real DB totals keyed by industry value
 * @param filteredTotal  current filtered result count (meta.total) → the "X" in "Showing X of Y"
 */
export function useNicheDistribution(
  industryCounts: Record<string, number>,
  filteredTotal: number,
): NicheDistribution {
  return useMemo(() => {
    const items = Object.entries(industryCounts)
      .map(([label, count]) => ({ id: label, label, color: colorForNiche(label), count }))
      .sort((a, b) => a.label.localeCompare(b.label));

    const total = items.reduce((sum, item) => sum + item.count, 0);

    return { items, shown: filteredTotal, total };
  }, [industryCounts, filteredTotal]);
}
