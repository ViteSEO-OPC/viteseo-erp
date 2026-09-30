<?php

namespace Tests\Feature\Crm\Copilot;

use App\Modules\Crm\Models\CopilotAiLog;
use App\Modules\Crm\Models\Lead;
use App\Modules\Crm\Services\Copilot\CopilotAnswerValidator;
use App\Modules\Crm\Services\Copilot\CopilotUsageGuard;
use App\Modules\Crm\Services\Copilot\Prompts\CopilotInstruction;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The ONE retry: only for a wording fault the validator marks FIXABLE, with the same facts, the
 * model's own reply and a fixed correction note; the retry is validated in full, and it is a model
 * call like any other — breaker, daily cap, audit row.
 */
class WordingRetryTest extends TestCase
{
    use RefreshDatabase;
    use UsesCopilotAi;

    private const QUESTION = 'How many leads are there in total?';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->aiOn();
        // Three leads, all visible to an Admin: a whole-CRM ("organization") figure.
        Lead::factory()->count(3)->create(['assigned_to' => $this->crmUser('crm_bd')->id]);
        Sanctum::actingAs($this->crmUser('crm_admin'));
    }

    /** The model replies with these JSON objects, in order. */
    private function modelRepliesInTurn(array ...$replies): void
    {
        $sequence = Http::sequence();
        foreach ($replies as $reply) {
            $sequence->push([
                'choices' => [['message' => ['content' => json_encode($reply)]]],
                'usage' => ['prompt_tokens' => 1200, 'completion_tokens' => 80],
            ]);
        }
        Http::fake([self::PROVIDER => $sequence]);
    }

    private function says(string $answer): array
    {
        return ['answerable' => true, 'answer' => $answer, 'sources' => ['lead_portfolio']];
    }

    public function test_a_wording_fault_is_retried_once_and_the_corrected_answer_is_shown(): void
    {
        $this->modelRepliesInTurn($this->says('You have 3 leads.'), $this->says('The CRM has 3 leads.'));

        $this->postJson(self::ASK, ['question' => self::QUESTION])
            ->assertOk()
            ->assertJsonPath('data.answered', true)
            ->assertJsonPath('data.text', 'The CRM has 3 leads.');

        Http::assertSentCount(2);
        [$first, $retry] = array_map(fn (array $r): array => json_decode($r[0]->body(), true)['messages'], Http::recorded()->all());
        // Same facts, no new retrieval: the retry repeats the first two messages exactly…
        $this->assertSame(array_slice($first, 0, 2), array_slice($retry, 0, 2));
        // …then the rejected reply as the model's own turn, then the FIXED correction note only.
        $this->assertSame(['role' => 'assistant', 'content' => json_encode($this->says('You have 3 leads.'))], $retry[2]);
        $this->assertSame(
            CopilotInstruction::CORRECTION_PREFIX.CopilotInstruction::CORRECTIONS['misattributed_scope'].CopilotInstruction::CORRECTION_SUFFIX,
            $retry[3]['content'],
        );
        $this->assertCount(4, $retry);

        $this->assertSame(
            [['ungrounded', 'misattributed_scope'], ['retry_answered', null]],
            CopilotAiLog::query()->orderBy('id')->get()->map(fn (CopilotAiLog $l): array => [$l->outcome, $l->reason])->all(),
        );
    }

    public function test_a_retry_that_still_fails_falls_back_and_there_is_no_third_call(): void
    {
        $this->modelRepliesInTurn($this->says('You have 3 leads.'), $this->says('Your 3 leads are all here.'), $this->says('The CRM has 3 leads.'));

        $this->postJson(self::ASK, ['question' => self::QUESTION])->assertOk()->assertJsonPath('data.answered', false);

        Http::assertSentCount(2);
        $this->assertSame(['ungrounded', 'retry_ungrounded'], CopilotAiLog::query()->orderBy('id')->pluck('outcome')->all());
    }

    /** @return array<string, array{0: array<string, mixed>}> */
    public static function neverRetried(): array
    {
        return [
            'invented figure' => [['answerable' => true, 'answer' => 'You have 7 leads.', 'sources' => ['lead_portfolio']]],
            'leak' => [['answerable' => true, 'answer' => 'See https://evil.example for your 3 leads.', 'sources' => ['lead_portfolio']]],
            'action claim' => [['answerable' => true, 'answer' => 'I have assigned your 3 leads to you.', 'sources' => ['lead_portfolio']]],
            'probability' => [['answerable' => true, 'answer' => 'The CRM has 3 leads, all likely to close.', 'sources' => ['lead_portfolio']]],
            'declined' => [['answerable' => false, 'answer' => '', 'sources' => []]],
            'bad sources' => [['answerable' => true, 'answer' => 'The CRM has 3 leads.', 'sources' => ['lead_notes']]],
        ];
    }

    /** @param  array<string, mixed>  $reply */
    #[DataProvider('neverRetried')]
    public function test_security_safety_and_grounding_failures_are_never_retried(array $reply): void
    {
        $this->modelRepliesInTurn($reply, $this->says('The CRM has 3 leads.'));

        $this->postJson(self::ASK, ['question' => self::QUESTION])->assertOk()->assertJsonPath('data.answered', false);

        Http::assertSentCount(1);
        $this->assertSame(1, CopilotAiLog::query()->count());
    }

    public function test_a_wording_fault_hiding_behind_a_grounding_failure_is_not_retried(): void
    {
        // "You have" (wording) AND an invented 7 (grounding): grounding wins, so no retry.
        $this->modelRepliesInTurn($this->says('You have 3 leads, and 7 of them are new.'), $this->says('The CRM has 3 leads.'));

        $this->postJson(self::ASK, ['question' => self::QUESTION])->assertOk()->assertJsonPath('data.answered', false);

        Http::assertSentCount(1);
        $this->assertSame('ungrounded_number', CopilotAiLog::query()->sole()->reason);
    }

    public function test_the_retry_counts_against_the_daily_cap(): void
    {
        config(['copilot.ai.daily_limit' => 1]);
        $this->modelRepliesInTurn($this->says('You have 3 leads.'), $this->says('The CRM has 3 leads.'));

        $this->postJson(self::ASK, ['question' => self::QUESTION])->assertOk()->assertJsonPath('data.answered', false);

        Http::assertSentCount(1); // the cap of 1 was spent on the first call
    }

    public function test_the_retry_is_refused_while_the_breaker_is_open(): void
    {
        // Closed for the first call, open by the time the retry is considered.
        $this->partialMock(CopilotUsageGuard::class, fn ($mock) => $mock->shouldReceive('breakerOpen')->andReturn(false, true));
        $this->modelRepliesInTurn($this->says('You have 3 leads.'), $this->says('The CRM has 3 leads.'));

        $this->postJson(self::ASK, ['question' => self::QUESTION])->assertOk()->assertJsonPath('data.answered', false);

        Http::assertSentCount(1);
    }

    public function test_a_failed_retry_counts_toward_the_breaker(): void
    {
        // A retry whose provider call fails is a provider failure like any other.
        Http::fake([self::PROVIDER => Http::sequence()
            ->push(['choices' => [['message' => ['content' => json_encode($this->says('You have 3 leads.'))]]]])
            ->push('down', 500)]);

        $this->postJson(self::ASK, ['question' => self::QUESTION])->assertOk()->assertJsonPath('data.answered', false);

        Http::assertSentCount(2);
        $this->assertSame('retry_provider_error', CopilotAiLog::query()->latest('id')->value('outcome'));
        $this->assertSame(1, Cache::get('copilot:ai:breaker:failures'));
    }

    public function test_every_fixable_reason_has_a_static_correction_note(): void
    {
        foreach (CopilotAnswerValidator::FIXABLE as $reason) {
            $this->assertArrayHasKey($reason, CopilotInstruction::CORRECTIONS, $reason);
            foreach (CopilotInstruction::LEAK_MARKERS as $marker) {
                $this->assertStringNotContainsString($marker, CopilotInstruction::CORRECTIONS[$reason]);
            }
        }
        $this->assertSame([], array_diff(array_keys(CopilotInstruction::CORRECTIONS), CopilotAnswerValidator::FIXABLE));
    }
}
