// Categorical palette for the niche (industry) chips + distribution bar. Colors are assigned
// deterministically from the industry name, so a given industry keeps the same color across pages
// and filter states (independent of ordering or which industries are currently present). This is
// pure presentation styling — it is NOT a mapping/taxonomy layer and stays even if a canonical
// niche taxonomy is introduced later.

export const NICHE_PALETTE: readonly string[] = [
  "#F6A935", // amber
  "#5487F4", // blue
  "#14B8B2", // teal
  "#9454F2", // purple
  "#EA8642", // orange
  "#786CEE", // violet
  "#26A9E8", // azure
  "#00B4D2", // cyan
  "#14CA6F", // green
  "#F14998", // pink
  "#7B9EF9", // periwinkle
  "#96999E", // slate
];

const FALLBACK_COLOR = "#96999E";

/** Deterministic, stable color for an industry/niche key. */
export function colorForNiche(key: string): string {
  let hash = 0;
  for (let i = 0; i < key.length; i += 1) {
    hash = (hash * 31 + key.charCodeAt(i)) | 0;
  }

  return NICHE_PALETTE[Math.abs(hash) % NICHE_PALETTE.length] ?? FALLBACK_COLOR;
}
