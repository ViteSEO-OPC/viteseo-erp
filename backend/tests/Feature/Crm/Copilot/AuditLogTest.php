<?php

namespace Tests\Feature\Crm\Copilot;

use App\Modules\Crm\Models\CopilotAiLog;
use App\Modules\Crm\Models\Lead;
use App\Modules\Crm\Services\Copilot\CopilotAuditLogger;
use App\Modules\Crm\Services\Copilot\Prompts\CopilotInstruction;
use App\Support\CurrentOrg;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** One metadata row per AI request — and nothing that was said. */
class AuditLogTest extends TestCase
{
    use RefreshDatabase;
    use UsesCopilotAi;

    private const QUESTION = 'Parang ang dami kong leads na hindi gumagalaw. Ano tingin mo ang dapat kong unahin?';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->aiOn();
    }

    public function test_an_answered_request_logs_metadata_only(): void
    {
        $bd = $this->crmUser('crm_bd');
        Lead::factory()->count(3)->create(['assigned_to' => $bd->id, 'business_name' => 'Confidential Clinic', 'email' => 'owner@clinic.example']);
        $answer = "I can't tell which leads are not moving from this data. You have 3 leads in total.";
        $this->modelReplies(['answerable' => true, 'answer' => $answer, 'sources' => ['lead_portfolio']]);
        Sanctum::actingAs($bd);

        $this->postJson(self::ASK, ['question' => self::QUESTION])->assertOk()->assertJsonPath('data.answered', true);

        $log = CopilotAiLog::query()->sole();
        $this->assertSame('answered', $log->outcome);
        $this->assertSame($bd->id, $log->user_id);
        $this->assertSame(CurrentOrg::id(), $log->org_id);
        $this->assertSame(hash('sha256', self::QUESTION), $log->question_hash);
        $this->assertSame(mb_strlen(self::QUESTION), $log->question_length);
        $this->assertSame(CopilotInstruction::VERSION, $log->instruction_version);
        $this->assertSame('openai/gpt-4o-mini', $log->model);
        $this->assertSame(1200, $log->prompt_tokens);
        $this->assertSame(80, $log->completion_tokens);
        $this->assertSame(3, $log->capabilities['lead_portfolio']);
        $this->assertArrayHasKey('performance', $log->capabilities);

        // Nothing that was said, nothing from the CRM, nothing secret — in any column.
        $row = json_encode(DB::table('crm_copilot_ai_logs')->first());
        foreach (['gumagalaw', 'unahin', "can't tell", 'Confidential Clinic', 'owner@clinic.example', self::API_KEY, 'sk-or'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $row);
        }
    }

    public function test_a_rejected_answer_logs_the_reason_code(): void
    {
        $this->modelReplies(['answerable' => true, 'answer' => 'I have assigned your leads.', 'sources' => ['lead_portfolio']]);
        Sanctum::actingAs($this->crmUser('crm_bd'));

        $this->postJson(self::ASK, ['question' => 'assign my leads'])->assertOk();

        $log = CopilotAiLog::query()->sole();
        $this->assertSame('unsafe', $log->outcome);
        $this->assertSame('action_claim', $log->reason);
    }

    public function test_rows_are_org_scoped(): void
    {
        $this->modelReplies(['answerable' => false, 'answer' => '', 'sources' => []]);
        Sanctum::actingAs($this->crmUser('crm_bd'));
        $this->postJson(self::ASK, ['question' => 'overview'])->assertOk();

        $other = (int) DB::table('orgs')->insertGetId(['name' => 'Other', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
        $this->assertSame(0, CurrentOrg::runAs($other, fn (): int => CopilotAiLog::query()->count()));
        $this->assertSame(1, CopilotAiLog::query()->count());
    }

    public function test_old_rows_are_pruned_after_the_retention_period(): void
    {
        config(['copilot.ai.log_retention_days' => 90]);
        $user = $this->crmUser('crm_bd');
        $logger = app(CopilotAuditLogger::class);

        $this->travelTo(now()->subDays(91));
        $logger->record($user, 'old', 'answered', null, null, null, 5);
        $this->travelBack();
        $logger->record($user, 'new', 'answered', null, null, null, 5);

        $this->assertSame(1, CopilotAiLog::query()->count());
        $this->assertSame(hash('sha256', 'new'), CopilotAiLog::query()->value('question_hash'));
    }
}
