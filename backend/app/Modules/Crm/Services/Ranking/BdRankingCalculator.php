<?php

namespace App\Modules\Crm\Services\Ranking;

/**
 * Pure, deterministic BD Ranking (Interim) calculator (BD-PERF-COMP-SPEC §5, B-01).
 *
 * Applies the approved **peer-relative min-max** normalization across active BDs, the weighted
 * composite (§5.3), the tie-breaks (§5.4), and the edge rules (§5.6). Being pure (raw components in,
 * ranking out) it is fully unit-testable — including against the §5.7 worked example. The weights,
 * index precision, and minimum-peer threshold come from {@see RankingConfig} (`config/ranking.php`).
 *
 * Edge rules:
 *   - A BD with no portfolio (0 assigned leads) is **unranked** — placed in a "No portfolio" bucket,
 *     never ranked last with 0 (§5.6). It is excluded from the peer min/max.
 *   - Fewer than the required number of ranked peers → the whole ranking is **N/A** (§5.6): relative
 *     ranking needs peers to normalize against.
 *   - When all peers are equal on a component (max == min) → that component's sub = 100 for all (§5.2).
 *
 * The composite is always computed from **exact** sub-scores; only the final index is rounded to the
 * configured precision, so rounding never compounds.
 */
class BdRankingCalculator
{
    public function __construct(private readonly RankingConfig $config) {}

    /**
     * @param  list<BdRankingInput>  $inputs
     */
    public function rank(array $inputs): BdRankingResult
    {
        $ranked = array_values(array_filter($inputs, fn (BdRankingInput $i): bool => $i->hasPortfolio));
        $unranked = array_values(array_filter($inputs, fn (BdRankingInput $i): bool => ! $i->hasPortfolio));

        // Relative ranking needs enough peers to normalize against (§5.6).
        if (count($ranked) < $this->config->minRankedPeers) {
            $rows = array_merge(
                array_map(fn (BdRankingInput $i): BdRankingRow => $this->bareRow($i, $i->hasPortfolio ? null : 'No portfolio'), $ranked),
                array_map(fn (BdRankingInput $i): BdRankingRow => $this->bareRow($i, 'No portfolio'), $unranked),
            );

            return new BdRankingResult(BdRankingResult::NA, true, $rows);
        }

        // Per-component peer min/max across the ranked set.
        $c1 = array_map(fn (BdRankingInput $i): float => $i->c1, $ranked);
        $c2 = array_map(fn (BdRankingInput $i): float => $i->c2, $ranked);
        $c3 = array_map(fn (BdRankingInput $i): float => $i->c3, $ranked);
        $c4 = array_map(fn (BdRankingInput $i): float => $i->c4, $ranked);

        $cfg = $this->config;

        /** @var list<array{input: BdRankingInput, subs: array{c1: float, c2: float, c3: float, c4: float}, exact: float, bdr: float}> $computed */
        $computed = [];
        foreach ($ranked as $i) {
            $subs = [
                'c1' => $this->sub($i->c1, min($c1), max($c1)),
                'c2' => $this->sub($i->c2, min($c2), max($c2)),
                'c3' => $this->sub($i->c3, min($c3), max($c3)),
                'c4' => $this->sub($i->c4, min($c4), max($c4)),
            ];
            // Full-precision composite — the internal value used for ranking. Only the surfaced
            // `bdr` is rounded for display (PO 2026-07-13: integer presentation).
            $exact = $cfg->c1Weight * $subs['c1'] + $cfg->c2Weight * $subs['c2']
                + $cfg->c3Weight * $subs['c3'] + $cfg->c4Weight * $subs['c4'];
            $computed[] = ['input' => $i, 'subs' => $subs, 'exact' => $exact, 'bdr' => round($exact, $cfg->bdrDecimals)];
        }

        // Rank = composite desc, ordered by the FULL-precision value so near-ties (that would round to
        // the same integer) still order correctly; ties then break by higher C1 raw, higher C2 raw,
        // then name ascending (§5.4).
        usort($computed, function (array $a, array $b): int {
            return $b['exact'] <=> $a['exact']
                ?: $b['input']->c1 <=> $a['input']->c1
                ?: $b['input']->c2 <=> $a['input']->c2
                ?: strcmp($a['input']->name, $b['input']->name);
        });

        $rankedRows = [];
        foreach ($computed as $index => $c) {
            $i = $c['input'];
            $rankedRows[] = new BdRankingRow(
                bdId: $i->bdId,
                name: $i->name,
                initials: $i->initials,
                ranked: true,
                raw: ['c1' => $i->c1, 'c2' => $i->c2, 'c3' => $i->c3, 'c4' => $i->c4],
                subs: $c['subs'],
                bdr: $c['bdr'],
                rank: $index + 1,
                reason: null,
            );
        }

        $unrankedRows = array_map(fn (BdRankingInput $i): BdRankingRow => $this->bareRow($i, 'No portfolio'), $unranked);

        return new BdRankingResult(BdRankingResult::RANKED, true, array_merge($rankedRows, $unrankedRows));
    }

    /** Peer-relative min-max sub-score (§5.2). */
    private function sub(float $raw, float $min, float $max): float
    {
        return $max > $min ? 100.0 * ($raw - $min) / ($max - $min) : 100.0;
    }

    private function bareRow(BdRankingInput $i, ?string $reason): BdRankingRow
    {
        return new BdRankingRow(
            bdId: $i->bdId,
            name: $i->name,
            initials: $i->initials,
            ranked: false,
            raw: ['c1' => $i->c1, 'c2' => $i->c2, 'c3' => $i->c3, 'c4' => $i->c4],
            subs: null,
            bdr: null,
            rank: null,
            reason: $reason,
        );
    }
}
