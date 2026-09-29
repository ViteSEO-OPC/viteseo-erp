<?php

namespace App\Modules\Crm\Services\Copilot;

use App\Modules\Crm\Services\Copilot\Knowledge\FactVocabulary;
use App\Modules\Crm\Services\Copilot\Knowledge\LeadVocabulary;
use App\Modules\Crm\Services\Copilot\Prompts\CopilotInstruction;
use App\Modules\Crm\Services\Copilot\Retrieval\FactScope;

/**
 * THE SAFETY BOUNDARY on the model's output. It never writes, rewrites or completes an answer — the
 * model's own text is accepted as-is or rejected, and a rejection means the Copilot shows its
 * existing deterministic reply.
 *
 * Conservative by design. It rejects:
 *   - malformed output, or `sources` that name anything not actually supplied;
 *   - URLs, email addresses, phone numbers, key-like strings, instruction text or role labels;
 *   - any claim to have acted on the CRM;
 *   - any figure not supplied (a `%` figure only if supplied as a percentage, a peso figure only if
 *     supplied as an amount — no figure the model computed itself), or a supplied figure tied to a
 *     metric that did not supply it; a number from help-article prose only in a sentence carrying
 *     no CRM figure and no ownership wording;
 *   - a business claim no supplied data supports — inactivity (except when explaining the No
 *     Activity scoring rule), probability, expected value — except in a sentence saying the data
 *     cannot tell; "opportunity" except as the scoring article defines it (tied to the lead score,
 *     naming no lead group) or in everyday phrasing ("opportunities to improve");
 *   - a figure attached to the wrong subject ("your" for the whole CRM's figure, another role), a
 *     ranking out of order or mixing breakdowns, a scoring figure with the wrong sign;
 *   - an answer that ignores a premise the data cannot support ("leads that are not moving").
 *
 * TWO PHASES. Every security, safety and grounding check runs first; only an answer that passes all
 * of them can fail on a FIXABLE reason — a wording fault the model can correct from the same facts.
 * So a FIXABLE reason is never masking a harder failure, and the one retry
 * ({@see CopilotAiService}) is only ever spent on wording.
 */
class CopilotAnswerValidator
{
    public const OK = 'ok';

    /** Wording faults: the only reasons for which the Copilot asks the model once more. */
    public const FIXABLE = [
        'misattributed_scope',
        'misattributed_subject',
        'misordered_ranking',
        'mixed_ranking',
        'wrong_sign',
        'unsupported_opportunity',
        'unsupported_expected_value',
        'premise_unaddressed',
    ];

    /** Words stating the data cannot answer — the only sentences where an unsupported concept may appear. */
    private const LIMITATION = '/\b(can(?:no|\'|’)?t|cannot|unable|not able|no way to|don(?:\'|’)t (?:have|know|track|see)|do not (?:have|know|track|see)|does(?:n(?:\'|’)t| not) (?:include|show|tell|track|measure|contain|say)|is(?:n(?:\'|’)t| not) (?:available|included|provided|tracked|measured|shown)|not (?:available|included|provided|tracked|measured|in the data)|no data|without data|hindi ko|wala akong|walang datos|walang data|hindi kasama|hindi available|hindi masasabi|hindi matukoy|hindi ko alam|hindi makikita)\b/iu';

    /**
     * Concepts that need supporting data. `null` = no Phase 1 capability supplies it, so it may only
     * appear in a limitation sentence (or under its own exception). Otherwise: a pattern the supplied
     * context must contain.
     */
    private const CONCEPTS = [
        'inactivity' => ['/\b(inactive|stale|stalled|stagnant|dormant|idle|neglected|untouched|abandoned|not moving|no (?:recent )?activity|hindi gumagalaw|walang galaw|nakatengga|natengga|stuck)\b/iu', null],
        'probability' => ['/\b(probab\w*|likel(?:y|ihood)|chances?|odds|posibleng|malamang|forecast\w*|predict\w*)\b/iu', null],
        'opportunity' => ['/\b(opportunit(?:y|ies)|pagkakataon|potential value|most valuable|pinakamahalaga)\b/iu', null],
        'expected_value' => ['/\b(?:expected|projected|potential|possible|future|forecast\w*|inaasahang?)\s+(?:\w+\s+)?(?:revenue|value|income|sales|earnings|deal size|kita|halaga)\b|\b(?:will|would|could|can|might|may)\s+(?:likely\s+)?(?:bring|generate|earn|produce|yield|be worth|add|close for)\s+(?:in\s+)?(?:the\s+)?(?:most\s+|more\s+|additional\s+|extra\s+|significant\s+|some\s+)?(?:₱|php\b|\d|revenue|value|income|sales|money|kita)|\brevenue potential\b|\bmagdadala ng (?:kita|pera)\b/iu', null],
        'revenue' => ['/\b(revenue|income|sales value|kita)\b|₱|\bphp\s?\d/iu', '/revenue/'],
        'pipeline_value' => ['/\b(pipeline value|deal value|worth|halaga)\b/iu', '/pipeline_value/'],
        'conversion' => ['/\b(conversion|converts?|converted|converting)\b/iu', '/conver/'],
        'score' => ['/\b(scores?|scoring|points?|puntos)\b/iu', '/score|point/'],
    ];

    /** Checked in the grounding phase — never retried. */
    private const GROUNDING_CONCEPTS = ['inactivity', 'probability', 'revenue', 'pipeline_value', 'conversion', 'score'];

    /** Checked in the wording phase — a retry may fix them. */
    private const WORDING_CONCEPTS = ['opportunity', 'expected_value'];

    /** Concepts a QUESTION can assume that no data can show; the answer must say so. */
    private const PREMISES = ['inactivity', 'probability', 'expected_value'];

    /** Where "opportunity" is defined — a curated article saying what a lead's score measures. */
    private const OPPORTUNITY_SUPPLIED = '/opportunit|pagkakataon/u';

    /** "Opportunity" tied to the lead score — the only basis any supplied fact gives it. */
    private const SCORE_TIE = '/\b(?:lead\s+)?scor(?:e|es|ed|ing)\b|\bpoints?\b|\bpuntos\b|\biskor\b/iu';

    /** Everyday "a chance to …" phrasing, which says nothing about the value of leads. */
    private const EVERYDAY_OPPORTUNITY = '/\bopportunit(?:y|ies)\s+(?:to|for)\s+(?:improv|increas|grow|boost|rais|review|learn|follow|reach|engag)[\w-]*(?:\s+up)?|\bpagkakataon\s+(?:na|para)\s+(?:mapabuti|mapataas|mapalago|mapaganda|matuto|masuri|makipag-?ugnayan|ma-?follow)\w*/iu';

    /** Words of a scoring rule, for the No Activity explanation exception. */
    private const SCORING_WORD = '/\b(?:scor(?:e|es|ed|ing)|points?|penalt(?:y|ies)|signals?|puntos|bawas)\b/iu';

    /** A sentence claiming an order. Descending unless it says otherwise. */
    private const RANK_ORDER = '/\b(followed by|sinundan ng|kasunod|then|next|second|third|ranked|ranking|in (?:descending |ascending )?order|order of)\b/iu';

    /**
     * A sentence ranking COUNTS, where every ranked figure must come from one breakdown. "then/next"
     * alone ("start with the overdue follow-ups, then the Interested leads") is a to-do order, not a
     * count ranking, so it counts only beside a count word.
     */
    private const RANK_COUNTS = '/\b(followed by|sinundan ng|kasunod|second|third|ranked|ranking)\b/iu';

    private const RANK_THEN = '/\b(then|next)\b/iu';

    private const COUNT_WORD = '/\b(largest|biggest|most|highest|count|counts|pinakamarami|pinakamalaki|bilang)\b/iu';

    /**
     * An ordinal right before a superlative cue ("second-largest", "next largest", "ikalawang
     * pinakamarami"): the named figure must hold THAT rank, not the extreme. `next` only says "not
     * the extreme", which is all it can be checked for.
     */
    private const ORDINAL_BEFORE_CUE = '/\b(second|2nd|third|3rd|next|ikalawang?|pangalawang?|ikatlong?|pangatlong?)[\s-]+(?:sa\s+)?$/iu';

    private const RANK_ASCENDING = '/\b(smallest|fewest|lowest|least|pinakakaunti|pinakamaliit|pinakamababa|ascending)\b/iu';

    /**
     * "X is the largest/most …" — the figure it names must be its breakdown's maximum. A COUNT claim
     * only: "highest priority" or "pinakamataas na antas" (highest level of need) is not one.
     */
    private const RANK_MAX = '/\b(largest|biggest|highest\s+(?:count|number|total|share)|the top(?!\s*-?\s*priorit)|pinakamarami|pinakamalaki|pinakamataas(?:\s+na)?\s+bilang|most\s+(?:leads|common)|has the most|most of (?:your|the|our) leads)\b/iu';

    /** Nouns a figure can belong to. Ownership words count only when attached to one of these. */
    private const CRM_NOUN = '(?:leads?|pipeline|follow-?ups?|performance|conversions?|conversion rate|revenue|kita|status(?:es)?|portfolio|numbers|figures|total|target|deals?|outreach|emails?|engagement|coverage|rate|accounts?|metrics|data|kpis?|dashboard|results)';

    /**
     * Words that make a figure the caller's OWN — attached to a figure or a CRM noun. Bare pronouns
     * are not ownership: "maaari mong unahin" (you can prioritise), "iyong mga gawain" (your tasks),
     * "you could focus on".
     */
    private const OWN = '/\b(?:you have|you(?:\'|’)ve got|you own|in your case|i have|(?:are|is) (?:all )?yours)\b'
        .'|\b(?:your|my)\s+(?:own\s+)?(?:[\p{L}\p{N},.-]+\s+){0,2}'.self::CRM_NOUN.'\b'
        .'|\b(?:mayroon|meron)\s+kang\b|\bmay\s+(?:[\p{L}\p{N},.-]+\s+){0,4}(?:kang|ka)\b'
        .'|\b'.self::CRM_NOUN.'\s+(?:mo|ko|ninyo|niyo|nyo)\b'
        .'|\b(?:iyong|inyong|aking|yong)\s+(?:mga\s+)?(?:[\p{L}\p{N}-]+\s+)?'.self::CRM_NOUN.'\b/iu';

    private const OWN_EXEMPT = '/\byour\s+(?:crm|organi[sz]ation|company|team|workspace)\b/iu';

    /** Words that make a figure the whole CRM's. */
    private const ORGANIZATION = '/\b(the (?:whole |entire )?crm(?:\'s| has| as a whole)|across the (?:whole |entire )?crm|(?:whole|entire) (?:crm|team|company|organi[sz]ation)|all (?:bds|users|leads in the crm)|every bd|company-wide|org-wide|organi[sz]ation-wide|everyone(?:\'s)?|our|we|we\'ve|natin|tayo|tayong|namin|buong|lahat ng bd)\b/iu';

    private const ROLE_BD = '/\b(bds?|bd\'s|business development(?: managers?)?)\b/iu';

    private const ROLE_ADMIN = '/\b(admins?|administrators?|super ?admins?)\b/iu';

    /** Other people, whoever the viewer is: the Copilot is never given anyone else's figures. */
    private const ROLE_OTHERS = '/\b(?:other|each|every|per|another|ibang|bawat)\s+(?:bds?|users?|business development managers?|admins?|team ?mates?|reps?|members?)\b/iu';

    private const ACTION = '/\b(i|we)(?:\s+(?:have|\'ve|’ve|just|already))?\s+(?:assigned|re-?assigned|updated|changed|deleted|removed|created|added|sent|emailed|called|scheduled|moved|marked|set|archived|restored|imported|exported)\b|\b(?:has|have)\s+been\s+(?:assigned|updated|changed|deleted|sent|emailed|scheduled|moved|marked)\s+(?:for|to|by)\s+(?:you|me)\b|\b(?:na-?assign|na-?update|na-?delete|in-?assign|inassign|pinalitan|binago|ipinadala|tinawagan|binura|inilipat|ni-?schedule)\s+ko\b/iu';

    private const LEAK = [
        'email' => '/[a-z0-9._%+-]+@[a-z0-9.-]+\.[a-z]{2,}/iu',
        'url' => '/https?:\/\/|www\.|\b[a-z0-9-]+\.(?:com|net|org|ph|io|ai|dev|app|co|xyz|info|biz)\b/iu',
        'phone' => '/(?:\+?63|\b0)9\d{2}[\s-]?\d{3}[\s-]?\d{4}\b|\+\d[\d\s-]{8,}\d/u',
        'key' => '/\bsk-[a-z0-9-]{6,}|\bapi[\s_-]?key\s*[:=]|\bbearer\s+[a-z0-9._-]{10,}|\b(?:app_key|openrouter_api_key|openai_api_key|db_password|\.env)\b|\b[a-z0-9_-]{40,}\b/iu',
        'role_label' => '/(?:^|\n)\s*(?:system|developer|assistant|user)\s*:/iu',
    ];

    private const SENTENCES = '/(?<=[.!?;])\s+|\n+/u';

    private const CLAUSES = '/,\s+|;\s*|:\s+|\s+[—–-]\s+|\s+(?:and|but|pero|while|habang|whereas|followed by|sinundan ng|kasunod ang)\s+/iu';

    private const NUMBER = '/(?<![\d.])\d[\d,]*(?:\.\d+)?/u';

    /** @return array{0: string, 1: string|null} [OK|reason, accepted answer or null] */
    public function validate(string $content, CopilotContext $context, string $question): array
    {
        $data = json_decode($content, true);
        if (! is_array($data)) {
            return ['invalid_json', null];
        }
        if (($data['answerable'] ?? null) !== true) {
            return ['declined', null];
        }

        $answer = $data['answer'] ?? null;
        if (! is_string($answer) || ($answer = trim($answer)) === '') {
            return ['empty_answer', null];
        }
        if (mb_strlen($answer) > (int) config('copilot.ai.max_answer_chars', 1200)) {
            return ['answer_too_long', null];
        }

        $sources = $data['sources'] ?? null;
        if (! is_array($sources) || $sources === [] || array_diff(array_map('strval', $sources), $context->sources) !== []) {
            return ['bad_sources', null];
        }

        // ── Phase 1: security, safety, grounding. Never retried. ──────────────────────────────────
        foreach (self::LEAK as $reason => $pattern) {
            if (preg_match($pattern, $answer) === 1) {
                return ['leak_'.$reason, null];
            }
        }
        foreach (CopilotInstruction::LEAK_MARKERS as $marker) {
            if (mb_stripos($answer, $marker) !== false) {
                return ['leak_instructions', null];
            }
        }
        if (preg_match(self::ACTION, $answer) === 1) {
            return ['action_claim', null];
        }

        $numbers = $this->ungroundedNumber($answer, $context, $question);
        if ($numbers !== null) {
            return [$numbers, null];
        }
        $concept = $this->unsupportedConcept($answer, $context, self::GROUNDING_CONCEPTS);
        if ($concept !== null) {
            return ['unsupported_'.$concept, null];
        }
        if ($this->unknownQuotedTerm($answer, $context, $question)) {
            return ['unknown_term', null];
        }
        [$grounding, $wording] = $this->checkFigures($answer, $context, $question);
        if ($grounding !== null) {
            return [$grounding, null];
        }

        // ── Phase 2: wording. A retry may fix these. ──────────────────────────────────────────────
        if ($wording !== null) {
            return [$wording, null];
        }
        $concept = $this->unsupportedConcept($answer, $context, self::WORDING_CONCEPTS);
        if ($concept !== null) {
            return ['unsupported_'.$concept, null];
        }
        if ($this->wrongSign($answer, $context)) {
            return ['wrong_sign', null];
        }
        if ($this->premiseUnaddressed($answer, $question)) {
            return ['premise_unaddressed', null];
        }

        return [self::OK, $answer];
    }

    private function ungroundedNumber(string $answer, CopilotContext $context, string $question): ?string
    {
        $asked = [];
        preg_match_all('/\d[\d,]*(?:\.\d+)?/u', $question, $q);
        foreach ($q[0] as $n) {
            $asked[CopilotContextBuilder::canonical($n)] = true;
        }

        preg_match_all('/(₱|\bphp\s?|\bp(?=\d))?\s?(\d[\d,]*(?:\.\d+)?)(?:\s?(%|percent\b|porsyento\b|pct\b))?/iu', $this->withoutListMarkers($answer), $m, PREG_SET_ORDER);
        foreach ($m as $match) {
            $n = CopilotContextBuilder::canonical($match[2]);
            $isPercent = ($match[3] ?? '') !== '';
            $isPeso = ($match[1] ?? '') !== '';

            if ($isPercent) {
                if (! isset($context->percents[$n])) {
                    return 'ungrounded_percent';
                }

                continue;
            }
            if ($isPeso) {
                if (! isset($context->pesos[$n])) {
                    return 'ungrounded_amount';
                }

                continue;
            }
            if (! isset($context->numbers[$n]) && ! isset($asked[$n])) {
                return 'ungrounded_number';
            }
        }

        return null;
    }

    /** List markers ("1.", "2)") at the start of a line are numbering, not figures. */
    private function withoutListMarkers(string $answer): string
    {
        return preg_replace('/^(\s*)\d{1,2}[.)](?=\s)/mu', '$1', $answer) ?? $answer;
    }

    /** @param  list<string>  $concepts */
    private function unsupportedConcept(string $answer, CopilotContext $context, array $concepts): ?string
    {
        $sentences = preg_split('/(?<=[.!?])\s+|\n+/u', $answer) ?: [$answer];

        foreach ($concepts as $concept) {
            [$claim, $support] = self::CONCEPTS[$concept];
            if ($support !== null && preg_match($support, $context->text) === 1) {
                continue; // The supplied data covers this concept.
            }
            foreach ($sentences as $sentence) {
                if (preg_match($claim, $sentence) !== 1 || preg_match(self::LIMITATION, $sentence) === 1) {
                    continue;
                }
                $allowed = match ($concept) {
                    'inactivity' => $this->explainsNoActivityRule($sentence, $context),
                    'opportunity' => $this->describesSuppliedOpportunity($sentence, $context),
                    'expected_value' => $this->citesSuppliedPipelineValue($sentence, $context),
                    default => false,
                };
                if (! $allowed) {
                    return $concept;
                }
            }
        }

        return null;
    }

    /**
     * "A lead with no activity for over 30 days loses 10 points" explains a SCORING RULE; "399 of
     * your leads have no activity" claims inactivity no capability measures. Allowed only when the
     * rule was supplied, the sentence speaks of scoring, and it carries no CRM figure, no ownership
     * or whole-CRM wording and no lead group.
     */
    private function explainsNoActivityRule(string $sentence, CopilotContext $context): bool
    {
        return (str_contains($context->text, 'no_activity') || str_contains($context->text, 'no activity'))
            && preg_match(self::SCORING_WORD, $sentence) === 1
            && ! $this->hasCrmFigure($sentence, $context)
            && ! $this->hasOwnership($sentence)
            && preg_match(self::ORGANIZATION, $sentence) !== 1
            && ! $this->namesGroup($sentence);
    }

    /**
     * "Opportunity" is allowed in two forms only:
     *   - everyday phrasing ("opportunities to improve the conversion rate") naming no lead group;
     *   - the scoring article's own concept — a supplied article defines it, the sentence ties it to
     *     the lead score, and it names no lead group ("sort by score to see the strongest
     *     opportunities"). Any other use — "New is a significant/good/better opportunity" — is a
     *     judgment no supplied figure makes.
     */
    private function describesSuppliedOpportunity(string $sentence, CopilotContext $context): bool
    {
        if ($this->namesGroup($sentence)) {
            return false;
        }
        $remaining = preg_replace(self::EVERYDAY_OPPORTUNITY, '', $sentence) ?? $sentence;
        if (preg_match(self::CONCEPTS['opportunity'][0], $remaining) !== 1) {
            return true;
        }

        return preg_match(self::OPPORTUNITY_SUPPLIED, $context->text) === 1
            && preg_match(self::SCORE_TIE, $sentence) === 1;
    }

    /** The estimated pipeline value IS an expected value — quotable when it was supplied. */
    private function citesSuppliedPipelineValue(string $sentence, CopilotContext $context): bool
    {
        return str_contains($context->text, 'estimated_pipeline_value')
            && preg_match('/\bpipeline\b/iu', $sentence) === 1
            && preg_match('/\b(?:will|would|could|might|may)\b/iu', $sentence) !== 1;
    }

    /** A question assuming what no data can show ("hindi gumagalaw") needs an answer that says so. */
    private function premiseUnaddressed(string $answer, string $question): bool
    {
        foreach (self::PREMISES as $concept) {
            if (preg_match(self::CONCEPTS[$concept][0], $question) === 1) {
                foreach (preg_split(self::SENTENCES, $answer) ?: [$answer] as $sentence) {
                    if (preg_match(self::LIMITATION, $sentence) === 1) {
                        return false;
                    }
                }

                return true;
            }
        }

        return false;
    }

    /**
     * Every number is held to its MEANING. Returns [grounding failure, wording failure]; the first
     * means no retry, the second is only reported if the first is null.
     *
     *   1. METRIC (grounding) — a number from a fact value or a CRM fact's label is bound to a fact
     *      that supplied it: a metric named nearest it in its clause, else one named in its
     *      sentence, else a generic figure (the lead total). Scoring rule figures included.
     *   2. PROSE NUMBERS (grounding) — a number only a help article states may not sit in a sentence
     *      that carries a CRM figure or ownership / whole-CRM wording.
     *   3. SUBJECT (wording) — "your 20 follow-ups / mayroon kang" needs the caller's own figure,
     *      "the whole CRM / natin" the organisation's; a sentence about another role or other users
     *      may carry no CRM figure.
     *   4. ORDER (wording) — a ranking lists one breakdown's figures largest first (or smallest first
     *      when it says so), "largest/most" names the breakdown's maximum, and a count ranking never
     *      mixes breakdowns.
     *
     * The user's own numbers are exempt.
     *
     * @return array{0: string|null, 1: string|null}
     */
    private function checkFigures(string $answer, CopilotContext $context, string $question): array
    {
        preg_match_all('/\d[\d,]*(?:\.\d+)?/u', $question, $q);
        $asked = array_flip(array_map(CopilotContextBuilder::canonical(...), $q[0]));

        // Every bindable fact's naming pattern, which of them are CRM data, and each breakdown's values.
        $patterns = $families = $crmPaths = [];
        foreach ($context->figures as $n => $origins) {
            foreach ($origins as $o) {
                if ($o['kind'] === 'text') {
                    continue;
                }
                $patterns[$o['path']] ??= FactVocabulary::pattern($o['path']);
                if ($o['kind'] === 'value' && $o['scope'] !== FactScope::REFERENCE) {
                    $crmPaths[$o['path']] = true;
                }
                if ($o['family'] !== null && $o['kind'] === 'value' && $o['scope'] !== FactScope::REFERENCE) {
                    $families[$o['family']][] = (float) $n;
                }
            }
        }
        $named = static function (string $text) use ($patterns): array {
            $hits = [];
            foreach ($patterns as $path => $pattern) {
                if ($pattern !== null && preg_match_all('/'.$pattern.'/iu', $text, $hm, PREG_OFFSET_CAPTURE) > 0) {
                    foreach ($hm[0] as [$word, $at]) {
                        $hits[] = [$path, $at, $at + strlen($word)];
                    }
                }
            }

            return $hits;
        };

        $wording = null;
        $body = $this->withoutListMarkers($answer);

        foreach (preg_split(self::SENTENCES, $body) ?: [$body] as $sentence) {
            $sentenceHits = null;
            $hasCrmFigure = false;
            $proseOnly = false;
            $ranked = [];      // [family, value, byte offset in the sentence], in text order
            $rankedKeys = [];  // breakdown (or single metric) of every ranked CRM figure
            $cursor = 0;

            foreach (preg_split(self::CLAUSES, $sentence) ?: [$sentence] as $clause) {
                $clauseAt = strpos($sentence, $clause, $cursor);
                $clauseAt = $clauseAt === false ? $cursor : $clauseAt;
                $cursor = $clauseAt + strlen($clause);
                preg_match_all(self::NUMBER, $clause, $m, PREG_OFFSET_CAPTURE);
                $claimed = $this->claimNames($named($clause), $m[0], $context);

                foreach ($m[0] as $i => [$raw, $offset]) {
                    $n = CopilotContextBuilder::canonical($raw);
                    $origins = $context->figures[$n] ?? [];
                    if (isset($asked[$n]) || $origins === []) {
                        continue;
                    }
                    $bindable = array_values(array_filter($origins, static fn (array $o): bool => $o['kind'] !== 'text'));
                    $isProse = count($bindable) < count($origins);

                    // 1. METRIC
                    $bound = [];
                    if (($claimed[$i] ?? []) !== []) {
                        $bound = array_values(array_filter($bindable, static fn (array $o): bool => isset($claimed[$i][$o['path']])));
                        // A CRM metric named beside it must have supplied it, even if some article
                        // happens to contain the same number ("12 overdue" when 12 is Interested).
                        if ($bound === [] && (! $isProse || array_intersect_key($claimed[$i], $crmPaths) !== [])) {
                            return ['misbound_number', null];
                        }
                    } elseif ($bindable !== []) {
                        $sentenceHits ??= array_fill_keys(array_column($named($sentence), 0), true);
                        $bound = array_values(array_filter($bindable, static fn (array $o): bool => isset($sentenceHits[$o['path']])));
                        if ($bound === []) {
                            $bound = array_values(array_filter($bindable, static fn (array $o): bool => $patterns[$o['path']] === null));
                        }
                        if ($bound === [] && ! $isProse) {
                            return ['unbound_number', null];
                        }
                    }
                    if ($bound === []) {
                        $proseOnly = true; // 2. checked once the whole sentence is read

                        continue;
                    }

                    $crm = array_values(array_filter($bound, static fn (array $o): bool => $o['kind'] === 'value' && $o['scope'] !== FactScope::REFERENCE));
                    if ($crm === []) {
                        continue; // a scoring rule figure or a label qualifier: its metric is all it has
                    }
                    $hasCrmFigure = true;

                    // 3. SUBJECT
                    $wording ??= $this->wrongSubject($sentence, $crm[0]['scope'], $context->viewerScope);

                    $family = null;
                    foreach ($crm as $o) {
                        if ($o['family'] !== null) {
                            $family = $o['family'];
                            break;
                        }
                    }
                    if ($family !== null) {
                        $ranked[] = [$family, (float) $n, $clauseAt + $offset];
                    }
                    if ($family !== null || $patterns[$crm[0]['path']] !== null) {
                        $rankedKeys[$family ?? 'metric:'.$crm[0]['path']] = true;
                    }
                }
            }

            // 2. PROSE NUMBERS
            if ($proseOnly && ($hasCrmFigure || $this->hasOwnership($sentence) || preg_match(self::ORGANIZATION, $sentence) === 1)) {
                return ['misplaced_reference_number', null];
            }

            // 4. ORDER
            if (count($rankedKeys) > 1 && $this->ranksCounts($sentence)) {
                $wording ??= 'mixed_ranking';
            }
            if ($this->misordered($sentence, $ranked, $families)) {
                $wording ??= 'misordered_ranking';
            }
        }

        return [null, $wording];
    }

    private function ranksCounts(string $sentence): bool
    {
        return preg_match(self::RANK_COUNTS, $sentence) === 1
            || (preg_match(self::RANK_THEN, $sentence) === 1 && preg_match(self::COUNT_WORD, $sentence) === 1);
    }

    /** A number in the sentence that only CRM data supplied (no rule or article states it). */
    private function hasCrmFigure(string $sentence, CopilotContext $context): bool
    {
        preg_match_all(self::NUMBER, $sentence, $m);
        foreach ($m[0] as $raw) {
            $origins = $context->figures[CopilotContextBuilder::canonical($raw)] ?? [];
            $crm = array_filter($origins, static fn (array $o): bool => $o['kind'] === 'value' && $o['scope'] !== FactScope::REFERENCE);
            if ($crm !== [] && count($crm) === count($origins)) {
                return true;
            }
        }

        return false;
    }

    private function hasOwnership(string $sentence): bool
    {
        return preg_match(self::OWN, preg_replace(self::OWN_EXEMPT, '', $sentence) ?? $sentence) === 1;
    }

    private function namesGroup(string $sentence): bool
    {
        return preg_match('/'.FactVocabulary::groupPattern().'/iu', $sentence) === 1;
    }

    /**
     * A figure written WITH a sign must carry the sign it was supplied with, judged against the signal
     * its clause names ("Has Website +15" is refused: Has Website is a -15 penalty). With no signal
     * named, it is refused only if every signed origin of that figure has the opposite sign. Figures
     * read out of text carry no known sign and never constrain.
     */
    private function wrongSign(string $answer, CopilotContext $context): bool
    {
        $patterns = [];
        foreach ($context->figures as $origins) {
            foreach ($origins as $o) {
                if ($o['negative'] !== null && $o['path'] !== '') {
                    $patterns[$o['path']] ??= FactVocabulary::pattern($o['path']);
                }
            }
        }
        if ($patterns === []) {
            return false;
        }

        foreach (preg_split(self::SENTENCES, $answer) ?: [$answer] as $sentence) {
            foreach (preg_split('/,\s+|;\s*|:\s+|\s+[—–]\s+|\s+(?:and|but|pero|while|habang|whereas|followed by|sinundan ng|kasunod ang)\s+/iu', $sentence) ?: [$sentence] as $clause) {
                preg_match_all(self::NUMBER, $clause, $m, PREG_OFFSET_CAPTURE);
                $hits = [];
                foreach ($patterns as $path => $pattern) {
                    if ($pattern !== null && preg_match_all('/'.$pattern.'/iu', $clause, $hm, PREG_OFFSET_CAPTURE) > 0) {
                        foreach ($hm[0] as [$word, $at]) {
                            $hits[] = [$path, $at, $at + strlen($word)];
                        }
                    }
                }
                $claimed = $this->claimNames($hits, $m[0], $context);

                foreach ($m[0] as $i => [$raw, $at]) {
                    $before = rtrim(substr($clause, 0, $at));
                    $sign = match (true) {
                        str_ends_with($before, '+') => false,
                        str_ends_with($before, '-'), str_ends_with($before, '−'), str_ends_with($before, '–') => true,
                        default => null,
                    };
                    // Unsigned, or a hyphen that is a range ("0-100") or a compound ("follow-up 3").
                    if ($sign === null || preg_match('/[\p{L}\p{N}][+\-−–]$/u', $before) === 1) {
                        continue;
                    }
                    $signed = array_values(array_filter(
                        $context->figures[CopilotContextBuilder::canonical($raw)] ?? [],
                        static fn (array $o): bool => $o['negative'] !== null && $o['path'] !== '',
                    ));
                    $named = array_values(array_filter($signed, static fn (array $o): bool => isset($claimed[$i][$o['path']])));
                    $judged = $named !== [] ? $named : $signed;
                    if ($judged !== [] && ! in_array($sign, array_column($judged, 'negative'), true)) {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    /**
     * Give each metric name in a clause to ONE number: the nearest number that metric supplied ("399
     * of your 436 leads are 'new'" — "new" speaks for 399), or, when the clause holds none of its
     * figures, simply the nearest number ("'new' has 436 leads" — which then fails, as it should).
     * On a tie the following number takes it ("'new' with 399").
     *
     * @param  list<array{0: string, 1: int, 2: int}>  $hits  [path, start, end]
     * @param  list<array{0: string, 1: int}>  $numbers  [raw, offset]
     * @return array<int, array<string, true>> number index => paths named for it
     */
    private function claimNames(array $hits, array $numbers, CopilotContext $context): array
    {
        $claimed = [];
        foreach ($hits as [$path, $start, $end]) {
            $supplied = array_filter($numbers, static fn (array $n): bool => in_array(
                $path,
                array_column($context->figures[CopilotContextBuilder::canonical($n[0])] ?? [], 'path'),
                true,
            ));
            $best = null;
            $bestDistance = PHP_INT_MAX;
            foreach ($supplied === [] ? $numbers : $supplied as $i => [$raw, $at]) {
                $distance = $at >= $end ? $at - $end : max(0, $start - ($at + strlen($raw)));
                if ($distance < $bestDistance || ($distance === $bestDistance && $at >= $end)) {
                    [$best, $bestDistance] = [$i, $distance];
                }
            }
            if ($best !== null) {
                $claimed[$best][$path] = true;
            }
        }

        return $claimed;
    }

    private function wrongSubject(string $sentence, string $scope, ?string $viewerScope): ?string
    {
        if ($scope === FactScope::ORGANIZATION && $this->hasOwnership($sentence)) {
            return 'misattributed_scope';
        }
        if ($scope === FactScope::OWN && preg_match(self::ORGANIZATION, $sentence) === 1) {
            return 'misattributed_scope';
        }

        $otherRole = match ($viewerScope) {
            FactScope::ORGANIZATION => self::ROLE_BD,
            FactScope::OWN => self::ROLE_ADMIN,
            default => null,
        };
        if (preg_match(self::ROLE_OTHERS, $sentence) === 1 || ($otherRole !== null && preg_match($otherRole, $sentence) === 1)) {
            return 'misattributed_subject';
        }

        return null;
    }

    /**
     * @param  list<array{0: string, 1: float, 2: int}>  $ranked  [family, value, offset] in text order
     * @param  array<string, list<float>>  $families
     */
    private function misordered(string $sentence, array $ranked, array $families): bool
    {
        if ($ranked === []) {
            return false;
        }
        $ascending = preg_match(self::RANK_ASCENDING, $sentence) === 1;

        // A sequence claim: each family's figures in the stated direction.
        if (preg_match(self::RANK_ORDER, $sentence) === 1 || preg_match(self::RANK_MAX, $sentence) === 1 || $ascending) {
            $last = [];
            foreach ($ranked as [$family, $value]) {
                if (isset($last[$family]) && ($ascending ? $value < $last[$family] : $value > $last[$family])) {
                    return true;
                }
                $last[$family] = $value;
            }
        }

        // A superlative claim: the figure each cue names — the first one after it, or else the last
        // one before it ("new, with 399, is the largest") — holds the rank the cue states: the extreme,
        // or the ordinal's place ("Interested, with 12, is the second-largest").
        foreach ([[self::RANK_MAX, true], [self::RANK_ASCENDING, false]] as [$pattern, $descending]) {
            preg_match_all($pattern, $sentence, $cues, PREG_OFFSET_CAPTURE);
            foreach ($cues[0] as [, $cueAt]) {
                $after = array_values(array_filter($ranked, static fn (array $r): bool => $r[2] > $cueAt));
                $before = array_values(array_filter($ranked, static fn (array $r): bool => $r[2] < $cueAt));
                $named = $after[0] ?? ($before === [] ? null : $before[count($before) - 1]);
                if ($named !== null && ! $this->holdsRank($named[1], $families[$named[0]], $descending, $this->ordinalBefore($sentence, $cueAt))) {
                    return true;
                }
            }
        }

        return false;
    }

    /** 1 for a plain superlative, 2/3 for second/third, 0 for "next" (not the extreme). */
    private function ordinalBefore(string $sentence, int $cueAt): int
    {
        if (preg_match(self::ORDINAL_BEFORE_CUE, substr($sentence, 0, $cueAt), $m) !== 1) {
            return 1;
        }

        return match (true) {
            (bool) preg_match('/^(second|2nd|ikalawa|pangalawa)/iu', $m[1]) => 2,
            (bool) preg_match('/^(third|3rd|ikatlo|pangatlo)/iu', $m[1]) => 3,
            default => 0,
        };
    }

    /**
     * Rank among the breakdown's DISTINCT values (ties share a place: 399, 12, 11, 7, 7 → 7 is
     * fourth). `$place` 0 = "next": anything but the extreme.
     *
     * @param  list<float>  $values
     */
    private function holdsRank(float $value, array $values, bool $descending, int $place): bool
    {
        $distinct = array_values(array_unique($values, SORT_REGULAR));
        $descending ? rsort($distinct) : sort($distinct);

        return $place === 0
            ? count($distinct) > 1 && $value !== $distinct[0] && in_array($value, $distinct, true)
            : ($distinct[$place - 1] ?? null) === $value;
    }

    /** A quoted short term must be a known category, a supplied name, or the user's own words. */
    private function unknownQuotedTerm(string $answer, CopilotContext $context, string $question): bool
    {
        // Checked outside limitation sentences only: "I can't tell which leads are "not moving""
        // quotes the user's idea in order to decline it. Single quotes count only when they open
        // and close a word, so "can't … don't" is not a quotation.
        $terms = [];
        foreach (preg_split('/(?<=[.!?])\s+|\n+/u', $answer) ?: [$answer] as $sentence) {
            if (preg_match(self::LIMITATION, $sentence) === 1) {
                continue;
            }
            preg_match_all('/(?<![\p{L}\p{N}])[\'‘]([^\'’\n]{1,40})[\'’](?![\p{L}\p{N}])|["“]([^"”\n]{1,40})["”]/u', $sentence, $m);
            array_push($terms, ...array_filter([...$m[1], ...$m[2]], static fn (string $t): bool => $t !== ''));
        }
        $known = array_map('mb_strtolower', [
            ...array_keys(LeadVocabulary::STATUSES),
            ...array_values(LeadVocabulary::STATUSES),
            ...LeadVocabulary::PRIORITIES,
            ...LeadVocabulary::WEBSITE_STATUSES,
            LeadVocabulary::OTHER,
        ]);

        foreach ($terms as $term) {
            $t = mb_strtolower(trim($term));
            if ($t === '' || in_array($t, $known, true)) {
                continue;
            }
            if (str_contains($context->text, $t) || str_contains(mb_strtolower($question), $t)) {
                continue;
            }

            return true;
        }

        return false;
    }
}
