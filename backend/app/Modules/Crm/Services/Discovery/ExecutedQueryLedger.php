<?php

namespace App\Modules\Crm\Services\Discovery;

use App\Support\CurrentOrg;
use Illuminate\Support\Facades\Cache;

/**
 * WHICH SEARCH PHRASES HAVE ALREADY BEEN RUN for a niche in a place — so the next plan can add to
 * them instead of repeating them.
 *
 * THE PROBLEM THIS EXISTS FOR. A strategy is cached permanently per (instruction, dialects, niche,
 * place), which is what keeps its cost at roughly one model call per target forever. The same
 * property makes the SECOND run of a target search exactly the phrases the first one did: it is a
 * cache hit, so the model is never asked again, and the run re-finds the businesses it already has.
 * Anti-repetition therefore needs a record of what was actually searched, and that record has to
 * reach the cache key — otherwise the old plan answers for the new question forever.
 *
 * IT STORES PHRASES, NEVER RESULTS. The only thing written here is text the CRM itself dispatched to
 * the provider as a SEARCH TERM. No business name, provider id, place id, coordinate, phone number,
 * website or any other factual field is recorded, or could be: the source is the outgoing request,
 * not the response. That is the property that lets this be fed back into a prompt at all.
 *
 * WHY THE CACHE STORE AND NOT A TABLE. This is advisory context whose loss costs one repeated
 * search, not data anything depends on — a cleared cache degrades to today's behaviour exactly. It
 * needs no migration, no schema and no backfill, and a record that may be discarded is the honest
 * shape for a hint.
 *
 * ORG-SCOPED, WHICH IS NOT OPTIONAL. Coverage is a fact about one organization's searching. A key
 * without the org would let one tenant's history suppress another's phrasing, which is both a
 * correctness bug and a quiet cross-tenant leak of what someone else has been looking for.
 */
class ExecutedQueryLedger
{
    /**
     * How many phrases are remembered per target, most recent last.
     *
     * Bounded because this is fed into a prompt: an unbounded history would grow the request every
     * run until it dominated the instruction. It deliberately matches the strategist's own ceiling
     * on covered values, so nothing is remembered that could not also be sent.
     */
    public const MAX_REMEMBERED = OpenAiDiscoveryStrategist::MAX_KNOWN_SELECTORS;

    /** Longest phrase kept, mirroring the value length the strategist will accept back. */
    public const MAX_PHRASE_LENGTH = OpenAiDiscoveryStrategist::MAX_VALUE_LENGTH;

    private const PREFIX = 'crm:discovery:queries-covered:';

    /**
     * Remember the phrases a search actually ran.
     *
     * `$executed` is how many of `$planned` the provider reached before it had what it was asked
     * for. NORMALISING BEFORE SLICING is what makes that count mean the right thing: the provider
     * de-duplicates its own phrase list and then counts against the de-duplicated one, so slicing
     * the raw list by that count would attribute the wrong phrases whenever two planned phrases
     * collapsed into one.
     *
     * A phrase that was planned but never reached is deliberately NOT recorded. It has not been
     * searched, so treating it as covered would suppress it from a future plan for no reason.
     *
     * @param  list<string>  $planned  the phrases the caller intended to run, in order
     * @param  int  $executed  how many of them the provider actually ran
     */
    public function record(string $category, string $area, array $planned, int $executed): void
    {
        $ran = $this->executed($planned, $executed);
        if ($ran === []) {
            return;
        }

        $key = $this->key($category, $area);

        // Existing first, so the most recently run phrases survive the cap when it bites.
        $merged = $this->normalise([...$this->read($key), ...$ran]);

        Cache::forever($key, array_slice($merged, -self::MAX_REMEMBERED));
    }

    /**
     * WHICH of the planned phrases actually ran — the one definition, shared by every writer.
     *
     * Extracted so the observability log and this ledger cannot disagree about what "executed"
     * means. Two writers deriving the same fact separately is how a measurement quietly stops
     * matching the thing it is supposed to measure.
     *
     * @param  list<string>  $planned  the phrases the caller intended to run, in order
     * @param  int  $executed  how many of them the provider actually ran
     * @return list<string>
     */
    public function executed(array $planned, int $executed): array
    {
        if ($executed < 1) {
            return [];
        }

        return array_slice($this->normalise($planned), 0, $executed);
    }

    /**
     * The phrases already searched for this target, oldest first. Empty when nothing is known,
     * which is the honest answer for a target that has never run.
     *
     * @return list<string>
     */
    public function covered(string $category, string $area): array
    {
        return $this->read($this->key($category, $area));
    }

    /** Drop a target's history so it is searched as though for the first time. */
    public function forget(string $category, string $area): void
    {
        Cache::forget($this->key($category, $area));
    }

    /**
     * A stable, bounded identity for a covered set, for use in another cache key.
     *
     * The set itself can be 32 phrases of 120 characters, which is far too long to put in a key.
     * It is order-insensitive on purpose: the same phrases searched in a different order are the
     * same coverage, and should not buy a second model call.
     *
     * @param  list<string>  $covered
     */
    public function fingerprint(array $covered): string
    {
        if ($covered === []) {
            // Named rather than hashed, so an untouched target is obvious in a key.
            return 'none';
        }

        $sorted = $covered;
        sort($sorted);

        return substr(sha1(implode("\n", $sorted)), 0, 12);
    }

    /** @return list<string> */
    private function read(string $key): array
    {
        $stored = Cache::get($key);

        return is_array($stored) ? $this->normalise($stored) : [];
    }

    /**
     * Trimmed, bounded, case-insensitively de-duplicated, order preserved.
     *
     * The de-duplication rule matches the provider's own: two phrases differing only by case or
     * whitespace are one search, so remembering both would claim coverage twice.
     *
     * @param  array<int, mixed>  $values
     * @return list<string>
     */
    private function normalise(array $values): array
    {
        $out = [];
        $seen = [];

        foreach ($values as $value) {
            if (! is_string($value)) {
                continue;
            }

            $clean = trim(preg_replace('/\s+/u', ' ', $value) ?? $value);
            if ($clean === '' || mb_strlen($clean) > self::MAX_PHRASE_LENGTH) {
                continue;
            }

            $key = mb_strtolower($clean);
            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $out[] = $clean;
        }

        return $out;
    }

    private function key(string $category, string $area): string
    {
        return self::PREFIX
            .(CurrentOrg::id() ?? 0).'|'
            .mb_strtolower(trim($category)).'|'
            .mb_strtolower(trim($area));
    }
}
