<?php

namespace App\Modules\Crm\Services\Copilot;

use App\Modules\Crm\Services\Copilot\Retrieval\FactScope;

/**
 * Exactly what the model was given, and the grounding sets derived from it.
 *
 * `numbers` holds every figure supplied anywhere; `percents` only those supplied AS percentages;
 * `pesos` only those supplied as peso amounts. `figures` says where each number came from — the fact
 * path, whose data it is ({@see FactScope}), for a breakdown bucket its family, and whether it was
 * supplied negative (a scoring penalty; null for a number read out of text), and its kind: `value` (a
 * fact's value), `label` (a number in a CRM fact's name) or `text` (a number in reference prose) — so an answer
 * can be held to the metric and subject a figure belongs to. `viewerScope` is the scope of the data
 * figures (null when only reference text was supplied). `text` is the lower-cased concatenation of every
 * supplied name, description and article, used to decide whether a concept was supplied at all.
 * `hasLead` says one authorised lead was supplied, and `leadNames` holds its (lower-cased, scrubbed)
 * business name when it survived scrubbing, so a sentence naming the lead can be held to its figures.
 */
final readonly class CopilotContext
{
    /**
     * @param  array<string, mixed>  $payload  the JSON sent as the user message
     * @param  list<string>  $sources  capability keys actually supplied
     * @param  array<string, int>  $recordCounts  capability key => records supplied
     * @param  array<string, true>  $numbers
     * @param  array<string, true>  $percents
     * @param  array<string, true>  $pesos
     * @param  array<string, list<array{path: string, scope: string, family: string|null, negative: bool|null, kind: string}>>  $figures
     * @param  list<string>  $leadNames
     */
    public function __construct(
        public array $payload,
        public array $sources,
        public array $recordCounts,
        public array $numbers,
        public array $percents,
        public array $pesos,
        public array $figures,
        public ?string $viewerScope,
        public string $text,
        public int $bytes,
        public bool $hasLead = false,
        public array $leadNames = [],
    ) {}
}
