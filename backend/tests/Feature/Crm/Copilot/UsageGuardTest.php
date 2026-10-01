<?php

namespace Tests\Feature\Crm\Copilot;

use App\Modules\Crm\Models\CopilotAiLog;
use App\Modules\Crm\Models\NerbyProviderSetting;
use App\Modules\Crm\Models\ProviderSpendMeter;
use App\Modules\Crm\Services\Copilot\CopilotUsageGuard;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** The daily cap and the circuit breaker — both fail closed, neither touches NERBY. */
class UsageGuardTest extends TestCase
{
    use RefreshDatabase;
    use UsesCopilotAi;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->aiOn();
    }

    private function answered(): bool
    {
        return (bool) $this->postJson(self::ASK, ['question' => 'overview'])->assertOk()->json('data.answered');
    }

    public function test_the_daily_cap_stops_ai_calls_for_that_user_only(): void
    {
        config(['copilot.ai.daily_limit' => 3]);
        $this->modelReplies(['answerable' => true, 'answer' => 'Most of your leads are new.', 'sources' => ['lead_portfolio']]);
        $bd = $this->crmUser('crm_bd');
        Sanctum::actingAs($bd);

        $this->assertTrue($this->answered());
        $this->assertTrue($this->answered());
        $this->assertTrue($this->answered());
        $this->assertFalse($this->answered());
        Http::assertSentCount(3);
        $this->assertSame('daily_limit', CopilotAiLog::query()->latest('id')->value('outcome'));

        // Another user has their own allowance.
        Sanctum::actingAs($this->crmUser('crm_bd'));
        $this->assertTrue($this->answered());
    }

    public function test_a_missing_or_non_positive_limit_fails_closed(): void
    {
        $this->modelReplies(['answerable' => true, 'answer' => 'Most of your leads are new.', 'sources' => ['lead_portfolio']]);
        Sanctum::actingAs($this->crmUser('crm_bd'));

        foreach ([0, -1, null, 'lots'] as $limit) {
            config(['copilot.ai.daily_limit' => $limit]);
            $this->assertFalse($this->answered());
        }
        Http::assertNothingSent();
    }

    public function test_an_unreadable_cache_fails_closed(): void
    {
        $user = $this->crmUser('crm_bd');
        $guard = app(CopilotUsageGuard::class);
        Cache::shouldReceive('has')->andThrow(new \RuntimeException('cache down'));
        Cache::shouldReceive('add')->andThrow(new \RuntimeException('cache down'));

        // Unsure = no AI: the breaker reads as open and the daily allowance as spent.
        $this->assertTrue($guard->breakerOpen());
        $this->assertFalse($guard->consumeDaily($user));
    }

    public function test_five_provider_failures_open_the_breaker_for_five_minutes_then_it_recovers(): void
    {
        // ONE fake with a switch: Laravel serves the first matching stub, so re-faking later would
        // never take effect.
        $down = true;
        Http::fake([self::PROVIDER => function () use (&$down) {
            if ($down) {
                throw new ConnectionException('down');
            }

            return Http::response(['choices' => [['message' => ['content' => json_encode(['answerable' => true, 'answer' => 'Most of your leads are new.', 'sources' => ['lead_portfolio']])]]]]);
        }]);
        Sanctum::actingAs($this->crmUser('crm_bd'));

        for ($i = 0; $i < 5; $i++) {
            $this->assertFalse($this->answered());
        }
        // Connection failures never produce a recorded response, so count them in the audit log.
        $this->assertSame(5, CopilotAiLog::query()->where('outcome', 'timeout')->count());

        // Open: no provider call at all.
        $this->assertFalse($this->answered());
        $this->assertSame(5, CopilotAiLog::query()->where('outcome', 'timeout')->count());
        $this->assertSame('circuit_open', CopilotAiLog::query()->latest('id')->value('outcome'));

        // After five minutes it closes again.
        $this->travel(6)->minutes();
        $down = false;
        $this->assertTrue($this->answered());
    }

    public function test_rejected_answers_do_not_trip_the_breaker(): void
    {
        $this->modelReplies(['answerable' => true, 'answer' => 'You have 999 leads.', 'sources' => ['lead_portfolio']]);
        Sanctum::actingAs($this->crmUser('crm_bd'));

        for ($i = 0; $i < 7; $i++) {
            $this->assertFalse($this->answered());
        }
        Http::assertSentCount(7);
    }

    public function test_limits_never_write_nerby_or_spend_state(): void
    {
        $before = NerbyProviderSetting::query()->first()->getAttributes();
        Http::fake([self::PROVIDER => Http::response([], 402)]);
        Sanctum::actingAs($this->crmUser('crm_bd'));

        for ($i = 0; $i < 6; $i++) {
            $this->answered();
        }

        $this->assertSame($before, NerbyProviderSetting::query()->first()->getAttributes());
        $this->assertSame(0, ProviderSpendMeter::query()->count());
    }
}
