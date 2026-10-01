<?php

namespace Tests\Unit\Copilot;

use App\Modules\Crm\Services\Copilot\CopilotContext;
use App\Modules\Crm\Services\Copilot\CopilotContextBuilder;
use App\Modules\Crm\Services\Copilot\Retrieval\FactScope;
use App\Modules\Crm\Services\Copilot\Retrieval\RetrievedFacts;
use Tests\TestCase;

class ContextBuilderTest extends TestCase
{
    private function build(array $retrieved, string $question = 'q'): CopilotContext
    {
        return (new CopilotContextBuilder)->build($question, $retrieved);
    }

    public function test_everything_goes_under_data_and_the_grounding_sets_follow_the_units(): void
    {
        $context = $this->build([
            new RetrievedFacts('performance', 'figures', [
                RetrievedFacts::fact('conversion_rate', 2.5, RetrievedFacts::PERCENT),
                RetrievedFacts::fact('overall_revenue', 1426000.0, RetrievedFacts::PHP),
                RetrievedFacts::fact('assigned_leads', 436, RetrievedFacts::COUNT),
                RetrievedFacts::fact('by_status', ['new' => 399, 'interested' => 12], RetrievedFacts::COUNT),
            ], 4, FactScope::OWN),
            new RetrievedFacts('knowledge', 'articles', [RetrievedFacts::fact('Article', 'Engagement Coverage is 91.7% here; windows are 30 days.', RetrievedFacts::TEXT)], 1, FactScope::REFERENCE),
        ], 'why 40 leads?');

        $this->assertSame(['data'], array_keys($context->payload));
        $this->assertSame('why 40 leads?', $context->payload['data']['question']);
        $this->assertSame(['performance', 'knowledge'], $context->sources);
        $this->assertSame(['performance' => 4, 'knowledge' => 1], $context->recordCounts);

        foreach (['2.5', '1426000', '436', '399', '12', '91.7', '30'] as $n) {
            $this->assertArrayHasKey($n, $context->numbers, $n);
        }
        $this->assertSame(['2.5' => true, '91.7' => true], $context->percents);
        $this->assertSame(['1426000' => true], $context->pesos);
    }

    public function test_control_and_bidi_characters_are_stripped_and_labels_truncated(): void
    {
        $context = $this->build([new RetrievedFacts('x', 'd', [
            RetrievedFacts::fact("bad\u{202E}name\x07", str_repeat('y', 500), RetrievedFacts::LABEL),
        ], 1, FactScope::REFERENCE)]);

        $fact = $context->payload['data']['facts'][0]['facts'][0];
        $this->assertSame('badname', $fact['name']);
        $this->assertSame(120, mb_strlen($fact['value']));
    }

    public function test_the_byte_budget_trims_articles_first_and_never_cuts_figures(): void
    {
        config(['copilot.ai.max_context_bytes' => 3000]);
        $context = $this->build([
            new RetrievedFacts('lead_portfolio', 'counts', [RetrievedFacts::fact('total_leads', 436, RetrievedFacts::COUNT)], 1, FactScope::ORGANIZATION),
            new RetrievedFacts('knowledge', 'articles', [
                RetrievedFacts::fact('A', str_repeat('alpha ', 250), RetrievedFacts::TEXT),
                RetrievedFacts::fact('B', str_repeat('bravo ', 250), RetrievedFacts::TEXT),
                RetrievedFacts::fact('C', str_repeat('charlie ', 180), RetrievedFacts::TEXT),
            ], 3, FactScope::REFERENCE),
        ]);

        $this->assertLessThanOrEqual(3000, $context->bytes);
        $this->assertSame(436, $context->payload['data']['facts'][0]['facts'][0]['value']);
    }

    public function test_each_figure_remembers_its_metric_scope_and_breakdown(): void
    {
        $context = $this->build([
            new RetrievedFacts('lead_portfolio', 'counts', [
                RetrievedFacts::fact('total_leads', 436, RetrievedFacts::COUNT),
                RetrievedFacts::fact('leads_by_status', ['new' => 399, 'interested' => 12], RetrievedFacts::COUNT),
            ], 436, FactScope::ORGANIZATION),
            new RetrievedFacts('knowledge', 'articles', [RetrievedFacts::fact('Score', 'From 0 to 100; 12 signals.', RetrievedFacts::TEXT)], 1, FactScope::REFERENCE),
        ]);

        $this->assertSame('organization', $context->payload['data']['facts'][0]['scope']);
        $this->assertSame('reference', $context->payload['data']['facts'][1]['scope']);
        $this->assertSame(FactScope::ORGANIZATION, $context->viewerScope);
        $this->assertSame([['path' => 'total_leads', 'scope' => 'organization', 'family' => null, 'negative' => false, 'kind' => 'value']], $context->figures['436']);
        $this->assertSame([['path' => 'leads_by_status.new', 'scope' => 'organization', 'family' => 'leads_by_status', 'negative' => false, 'kind' => 'value']], $context->figures['399']);
        // A figure supplied twice keeps both origins: the count, and the article's text.
        $this->assertSame(['organization', 'reference'], array_column($context->figures['12'], 'scope'));
        $this->assertSame([['path' => '', 'scope' => 'reference', 'family' => null, 'negative' => null, 'kind' => 'text']], $context->figures['100']);
    }

    public function test_a_penalty_reaches_the_model_negative_and_is_grounded_by_its_magnitude(): void
    {
        $context = $this->build([new RetrievedFacts('scoring_rules', 'rules', [
            RetrievedFacts::fact('negative_signal_points', ['has_website' => -15], RetrievedFacts::POINTS),
            RetrievedFacts::fact('engagement_signal_points', ['call_completed' => 15], RetrievedFacts::POINTS),
        ], 2, FactScope::REFERENCE)]);

        // The model sees the sign…
        $this->assertSame(['has_website' => -15], $context->payload['data']['facts'][0]['facts'][0]['value']);
        // …the answer's "15" is grounded, and each origin remembers its sign.
        $this->assertArrayHasKey('15', $context->numbers);
        $this->assertSame(
            ['negative_signal_points.has_website' => true, 'engagement_signal_points.call_completed' => false],
            array_column($context->figures['15'], 'negative', 'path'),
        );
    }

    public function test_a_number_in_a_crm_label_belongs_to_that_fact_and_one_in_a_title_is_text(): void
    {
        $context = $this->build([
            new RetrievedFacts('performance', 'figures', [RetrievedFacts::fact('Activity Volume (30d)', 709, RetrievedFacts::COUNT)], 1, FactScope::ORGANIZATION),
            new RetrievedFacts('knowledge', 'articles', [RetrievedFacts::fact('How Lead Status Mix (M-02) is computed', 'text', RetrievedFacts::TEXT)], 1, FactScope::REFERENCE),
        ]);

        $this->assertSame([['path' => 'Activity Volume (30d)', 'scope' => 'organization', 'family' => null, 'negative' => null, 'kind' => 'label']], $context->figures['30']);
        $this->assertSame('text', $context->figures['2'][0]['kind']);
    }

    public function test_numbers_are_canonicalised(): void
    {
        $this->assertSame('1426000', CopilotContextBuilder::canonical('1,426,000'));
        $this->assertSame('2.5', CopilotContextBuilder::canonical('2.50'));
        $this->assertSame('0', CopilotContextBuilder::canonical('0'));
        $this->assertSame('0.5', CopilotContextBuilder::canonical('0.5'));
    }
}
