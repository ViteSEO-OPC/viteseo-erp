<?php

namespace App\Modules\Crm\Services\Copilot;

use App\Modules\Crm\Services\Copilot\Retrieval\FactScope;
use App\Modules\Crm\Services\Copilot\Retrieval\RetrievedFacts;

/**
 * Turns the retrieved facts into the model's input, and records what was supplied for grounding.
 *
 * DATA MINIMISATION happens before this point (retrievers return aggregates only); this class is the
 * last gate:
 *   - Every string is stripped of control characters and truncated (labels 120, articles 1,500).
 *   - The whole payload is capped at `copilot.ai.max_context_bytes`: curated article text is trimmed
 *     first, then dropped, and structured CRM figures are never cut.
 *   - Everything goes under one `data` key, which the instruction defines as data, never instructions.
 */
class CopilotContextBuilder
{
    private const MAX_LABEL = 120;

    private const MIN_ARTICLE = 200;

    /** @param  list<RetrievedFacts>  $retrieved */
    public function build(string $question, array $retrieved): CopilotContext
    {
        $sources = [];
        foreach ($retrieved as $r) {
            $sources[] = [
                'source' => $r->source,
                'scope' => $r->scope,
                'describes' => $r->describes,
                'facts' => array_map(fn (array $f): array => [
                    'name' => $this->clean($f['name'], self::MAX_LABEL),
                    'value' => $this->cleanValue($f['value'], $f['unit']),
                    'unit' => $f['unit'],
                ], $r->facts),
            ];
        }

        $sources = $this->fit($question, $sources);

        $numbers = $percents = $pesos = $figures = [];
        $text = '';
        $recordCounts = [];
        $viewerScope = null;
        $hasLead = false;
        $leadNames = [];
        foreach ($sources as $source) {
            $recordCounts[$source['source']] = 0;
            $text .= ' '.$source['describes'];
            if ($source['scope'] === FactScope::LEAD) {
                $hasLead = true;
                foreach ($source['facts'] as $fact) {
                    if ($fact['name'] === 'business_name' && is_string($fact['value']) && trim($fact['value']) !== '') {
                        $leadNames[] = mb_strtolower(trim($fact['value']));
                    }
                }
            }
            // A lead source describes one lead, not whose view the other figures are.
            if ($source['scope'] !== FactScope::REFERENCE && $source['scope'] !== FactScope::LEAD) {
                $viewerScope = $source['scope'];
            }
            foreach ($source['facts'] as $fact) {
                $recordCounts[$source['source']]++;
                $text .= ' '.$fact['name'];
                // A number in a CRM fact's LABEL ("Activity Volume (30d)") qualifies that fact and is
                // bound to it; a number in an article title is just text.
                $this->collectNumbers((string) $fact['name'], $numbers, $percents, $figures, $source['scope'] === FactScope::REFERENCE
                    ? null
                    : ['path' => (string) $fact['name'], 'scope' => $source['scope'], 'family' => null, 'negative' => null, 'kind' => 'label']);
                $origin = ['path' => (string) $fact['name'], 'scope' => $source['scope'], 'family' => null, 'negative' => null, 'kind' => 'value'];
                $this->collectValue($fact['value'], $fact['unit'], $numbers, $percents, $pesos, $text, $figures, $origin);
            }
        }
        foreach ($retrieved as $r) {
            if (isset($recordCounts[$r->source])) {
                $recordCounts[$r->source] = $r->recordCount;
            }
        }

        $payload = ['data' => ['question' => $this->clean($question, 2000), 'facts' => $sources]];

        return new CopilotContext(
            $payload,
            array_column($sources, 'source'),
            $recordCounts,
            $numbers,
            $percents,
            $pesos,
            $figures,
            $viewerScope,
            mb_strtolower($text),
            strlen((string) json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)),
            $hasLead,
            array_values(array_unique($leadNames)),
        );
    }

    /** Canonical form of a number for grounding: no separators, no trailing decimal zeros. */
    public static function canonical(string $number): string
    {
        $n = str_replace(',', '', $number);
        if (str_contains($n, '.')) {
            $n = rtrim(rtrim($n, '0'), '.');
        }
        $n = ltrim($n, '0');

        return $n === '' || str_starts_with($n, '.') ? '0'.$n : $n;
    }

    /**
     * Trim curated article text, then drop articles, until the payload fits the byte budget.
     * Structured figures are never cut.
     *
     * @param  list<array{source: string, describes: string, facts: list<array<string, mixed>>}>  $sources
     * @return list<array{source: string, describes: string, facts: list<array<string, mixed>>}>
     */
    private function fit(string $question, array $sources): array
    {
        $budget = max(2048, (int) config('copilot.ai.max_context_bytes', 8192));
        $size = fn (array $s): int => strlen((string) json_encode(['data' => ['question' => $question, 'facts' => $s]], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        while ($size($sources) > $budget) {
            // The longest remaining article.
            $target = null;
            foreach ($sources as $si => $source) {
                foreach ($source['facts'] as $fi => $fact) {
                    if ($fact['unit'] === RetrievedFacts::TEXT && ($target === null || mb_strlen($fact['value']) > mb_strlen($sources[$target[0]]['facts'][$target[1]]['value']))) {
                        $target = [$si, $fi];
                    }
                }
            }
            if ($target === null) {
                break; // Only structured figures remain; they are small and never cut.
            }
            [$si, $fi] = $target;
            $current = $sources[$si]['facts'][$fi]['value'];
            if (mb_strlen($current) > self::MIN_ARTICLE) {
                $sources[$si]['facts'][$fi]['value'] = mb_substr($current, 0, intdiv(mb_strlen($current), 2)).'…';
            } else {
                array_splice($sources[$si]['facts'], $fi, 1);
            }
        }

        return $sources;
    }

    private function cleanValue(mixed $value, string $unit): mixed
    {
        if (is_array($value)) {
            $out = [];
            foreach ($value as $k => $v) {
                $out[is_string($k) ? $this->clean($k, self::MAX_LABEL) : $k] = $this->cleanValue($v, $unit);
            }

            return $out;
        }
        if (is_string($value)) {
            return $this->clean($value, $unit === RetrievedFacts::TEXT ? 1500 : self::MAX_LABEL);
        }

        return is_int($value) || is_float($value) || is_bool($value) || $value === null ? $value : null;
    }

    private function clean(string $value, int $max): string
    {
        $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F\x{200B}-\x{200F}\x{202A}-\x{202E}\x{2066}-\x{2069}]/u', '', $value) ?? '';

        return mb_substr(trim($value), 0, $max);
    }

    /**
     * @param  array<string, true>  $numbers
     * @param  array<string, true>  $percents
     * @param  array<string, true>  $pesos
     * @param  array<string, list<array{path: string, scope: string, family: string|null, negative: bool|null, kind: string}>>  $figures
     * @param  array{path: string, scope: string, family: string|null, negative: bool|null, kind: string}  $origin
     */
    private function collectValue(mixed $value, string $unit, array &$numbers, array &$percents, array &$pesos, string &$text, array &$figures, array $origin): void
    {
        if (is_array($value)) {
            foreach ($value as $k => $v) {
                if (is_string($k)) {
                    $text .= ' '.$k;
                    $this->collectNumbers($k, $numbers, $percents, $figures, null);
                }
                // A breakdown bucket: its path names the bucket, and its siblings form the family a
                // ranking is checked against.
                $this->collectValue($v, $unit, $numbers, $percents, $pesos, $text, $figures, [
                    'path' => $origin['path'].'.'.$k,
                    'scope' => $origin['scope'],
                    'family' => $origin['path'],
                    'negative' => null,
                    'kind' => 'value',
                ]);
            }

            return;
        }
        if (is_int($value) || is_float($value)) {
            // Indexed by magnitude — an answer's figure is read without its sign — while the origin
            // remembers a penalty, so "+15" for a -15 signal can still be refused.
            $magnitude = abs($value);
            $n = self::canonical(is_float($magnitude) ? rtrim(rtrim(number_format($magnitude, 4, '.', ''), '0'), '.') : (string) $magnitude);
            $numbers[$n] = true;
            $figures[$n][] = [...$origin, 'negative' => $value < 0];
            if ($unit === RetrievedFacts::PERCENT) {
                $percents[$n] = true;
            }
            if ($unit === RetrievedFacts::PHP) {
                $pesos[$n] = true;
            }

            return;
        }
        // A date is a CRM value, not prose: its year, month and day are bound to the fact that
        // supplied it, so "September 23, 2026" is grounded and "September 24" is not.
        if ($unit === RetrievedFacts::DATE && is_string($value) && preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $date) === 1) {
            $text .= ' '.$value;
            foreach ([$date[1], $date[2], $date[3]] as $part) {
                $n = self::canonical($part);
                $numbers[$n] = true;
                $figures[$n][] = $origin;
            }

            return;
        }
        if (is_string($value)) {
            $text .= ' '.$value;
            $this->collectNumbers($value, $numbers, $percents, $figures, null);
        }
    }

    /**
     * Numbers inside supplied text. A number written with `%` in the text counts as a supplied
     * percentage; every number counts as a supplied figure. With no `$origin` the text describes no
     * one's data (an article, a title): a `text` reference figure that no metric can be bound to. With
     * one, it is the label of a CRM fact and is bound to that fact.
     *
     * @param  array<string, true>  $numbers
     * @param  array<string, true>  $percents
     * @param  array<string, list<array{path: string, scope: string, family: string|null, negative: bool|null, kind: string}>>  $figures
     * @param  array{path: string, scope: string, family: string|null, negative: bool|null, kind: string}|null  $origin
     */
    private function collectNumbers(string $text, array &$numbers, array &$percents, array &$figures, ?array $origin): void
    {
        preg_match_all('/(\d[\d,]*(?:\.\d+)?)\s*(%)?/u', $text, $m, PREG_SET_ORDER);
        foreach ($m as $match) {
            $n = self::canonical($match[1]);
            $numbers[$n] = true;
            $figures[$n][] = $origin ?? ['path' => '', 'scope' => FactScope::REFERENCE, 'family' => null, 'negative' => null, 'kind' => 'text'];
            if (($match[2] ?? '') === '%') {
                $percents[$n] = true;
            }
        }
    }
}
