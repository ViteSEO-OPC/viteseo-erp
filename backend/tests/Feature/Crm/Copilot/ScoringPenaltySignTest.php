<?php

namespace Tests\Feature\Crm\Copilot;

use App\Modules\Crm\Services\BusinessRules\ScoringConfigProvider;
use App\Modules\Crm\Services\Copilot\Retrieval\ScoringRulesRetriever;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The rule table stores Has Website as the magnitude 15 and carries the sign in its category; the
 * calculator subtracts it. Supplied raw, the model read it as a +15 bonus. It must receive -15, be
 * able to say so, and be refused if it calls the penalty a bonus.
 */
class ScoringPenaltySignTest extends TestCase
{
    use RefreshDatabase;
    use UsesCopilotAi;

    private const QUESTION = 'Why do leads with a website lose score points?';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_penalties_are_supplied_negative_and_bonuses_positive(): void
    {
        $points = app(ScoringConfigProvider::class)->resolve()->points;
        $facts = array_column(app(ScoringRulesRetriever::class)->retrieve($this->crmUser('super_admin'), 'score')->facts, 'value', 'name');

        $this->assertArrayNotHasKey('signal_points', $facts);
        $this->assertSame(-abs($points['has_website']), $facts['negative_signal_points']['has_website']);
        $this->assertSame(-15, $facts['negative_signal_points']['has_website']);
        $this->assertSame(-10, $facts['negative_signal_points']['no_activity']);
        $this->assertSame(25, $facts['fit_signal_points']['no_website']);
        $this->assertSame(15, $facts['engagement_signal_points']['call_completed']);
        foreach ($facts['negative_signal_points'] as $key => $value) {
            $this->assertLessThanOrEqual(0, $value, $key);
        }
        foreach ([...$facts['fit_signal_points'], ...$facts['engagement_signal_points']] as $key => $value) {
            $this->assertGreaterThanOrEqual(0, $value, $key);
        }
    }

    public function test_the_model_receives_has_website_as_minus_15_and_stating_it_is_accepted(): void
    {
        $this->aiOn();
        $answer = 'Leads with no website gain +25 points, while leads that have a website get a -15 point penalty, so a lead without a website scores higher.';
        $this->modelReplies(['answerable' => true, 'answer' => $answer, 'sources' => ['scoring_rules']]);
        Sanctum::actingAs($this->crmUser('super_admin'));

        $this->postJson(self::ASK, ['question' => self::QUESTION])
            ->assertOk()
            ->assertJsonPath('data.answered', true)
            ->assertJsonPath('data.text', $answer);

        $packet = json_decode(json_decode($this->sentToProvider()['body'], true)['messages'][1]['content'], true);
        $scoring = array_column(array_column($packet['data']['facts'], null, 'source')['scoring_rules']['facts'], 'value', 'name');
        $this->assertSame(-15, $scoring['negative_signal_points']['has_website']);
        $this->assertArrayNotHasKey('has_website', $scoring['fit_signal_points']);
        $this->assertArrayNotHasKey('has_website', $scoring['engagement_signal_points']);
    }

    public function test_a_reply_that_calls_the_penalty_a_bonus_falls_back(): void
    {
        $this->aiOn();
        $this->modelReplies(['answerable' => true, 'answer' => 'Has Website adds +15 points to a lead.', 'sources' => ['scoring_rules']]);
        Sanctum::actingAs($this->crmUser('super_admin'));

        $this->postJson(self::ASK, ['question' => self::QUESTION])->assertOk()->assertJsonPath('data.answered', false);
    }
}
