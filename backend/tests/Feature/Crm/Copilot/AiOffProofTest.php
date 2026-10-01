<?php

namespace Tests\Feature\Crm\Copilot;

use App\Modules\Crm\Models\CopilotAiLog;
use App\Modules\Crm\Models\CopilotSetting;
use App\Modules\Crm\Models\NerbyProviderSetting;
use App\Modules\Crm\Services\Copilot\CopilotRetrievalPlanner;
use App\Modules\Crm\Services\Copilot\Retrieval\CapabilityRegistry;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * With AI off — by the ops switch, the toggle or the credential — the Copilot AI does NOTHING: no
 * planning, no retrieval, no provider request, no audit row, and `/copilot/config` says false, so the
 * browser never asks. The deterministic Copilot is all that runs.
 */
class AiOffProofTest extends TestCase
{
    use RefreshDatabase;
    use UsesCopilotAi;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->aiOn();
    }

    /** @return array<string, array{0: string}> */
    public static function offStates(): array
    {
        return ['ops switch off' => ['ops'], 'toggle off' => ['toggle'], 'credential unusable' => ['credential']];
    }

    #[DataProvider('offStates')]
    public function test_off_means_nothing_runs(string $off): void
    {
        match ($off) {
            'ops' => config(['copilot.ai.enabled' => false]),
            'toggle' => CopilotSetting::query()->update(['ai_enabled' => false]),
            'credential' => NerbyProviderSetting::query()->update(['last_test_status' => 'insufficient_credit']),
        };

        $this->mock(CapabilityRegistry::class)->shouldNotReceive('get');
        $this->mock(CopilotRetrievalPlanner::class)->shouldNotReceive('plan');
        Http::fake();
        Sanctum::actingAs($this->crmUser('crm_bd'));

        $this->getJson('/api/v1/crm/copilot/config')->assertOk()->assertExactJson(['data' => ['aiAvailable' => false]]);
        $this->postJson(self::ASK, ['question' => 'Can you analyze my lead pipeline?'])
            ->assertOk()
            ->assertExactJson(['data' => ['answered' => false, 'text' => null]]);

        Http::assertNothingSent();
        $this->assertSame(0, CopilotAiLog::query()->count());
    }
}
