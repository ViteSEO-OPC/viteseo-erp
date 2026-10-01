<?php

namespace Tests\Feature\Crm\Copilot;

use App\Models\User;
use App\Modules\Crm\Models\CopilotSetting;
use App\Modules\Crm\Models\NerbyProviderSetting;
use App\Modules\Crm\Services\Discovery\AiChatEndpoint;
use App\Modules\Crm\Services\Discovery\NerbyProviderSettingService;
use Illuminate\Support\Facades\Http;

/** Shared fixtures for Copilot AI feature tests. No test here ever reaches a real provider. */
trait UsesCopilotAi
{
    protected const ASK = '/api/v1/crm/copilot/ask';

    protected const PROVIDER = AiChatEndpoint::OPENROUTER_BASE_URL.'/chat/completions';

    protected const API_KEY = 'sk-or-v1-redteamsecretkey000000000000';

    /** All three gates open: ops switch, Super Admin toggle, usable NERBY credential. */
    protected function aiOn(): void
    {
        config(['copilot.ai.enabled' => true]);
        CopilotSetting::query()->create(['ai_enabled' => true]);
        NerbyProviderSetting::query()->updateOrCreate(['provider' => 'openai'], [
            'enabled' => true,
            'api_key' => self::API_KEY,
            'key_last4' => '0000',
            'options' => ['model' => 'gpt-4o-mini'],
            'last_test_status' => NerbyProviderSettingService::TEST_OK,
            'last_tested_at' => now(),
        ]);
    }

    protected function crmUser(string $role): User
    {
        $user = User::factory()->create(['status' => 'active']);
        $user->assignRole($role);

        return $user;
    }

    /** The model replies with this JSON object (as its message content). */
    protected function modelReplies(array $reply): void
    {
        Http::fake([self::PROVIDER => Http::response([
            'choices' => [['message' => ['content' => json_encode($reply)]]],
            'usage' => ['prompt_tokens' => 1200, 'completion_tokens' => 80],
        ])]);
    }

    /** Everything sent to the provider in the one request, as raw strings. */
    protected function sentToProvider(): array
    {
        $request = Http::recorded()->first()[0];

        return ['body' => $request->body(), 'authorization' => $request->header('Authorization')[0] ?? ''];
    }
}
