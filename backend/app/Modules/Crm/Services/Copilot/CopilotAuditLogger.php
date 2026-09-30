<?php

namespace App\Modules\Crm\Services\Copilot;

use App\Models\User;
use App\Modules\Crm\Models\CopilotAiLog;
use App\Modules\Crm\Services\Copilot\Prompts\CopilotInstruction;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Writes one metadata row per Copilot AI request. The question is stored ONLY as its SHA-256 and
 * length; the context, the answer and any credential are never stored. Logging never affects the
 * reply: a failure to write is swallowed (class name only to the log).
 */
class CopilotAuditLogger
{
    /**
     * @param  array<string, int>|null  $capabilities  capability key => records supplied
     * @param  array{prompt_tokens?: int|null, completion_tokens?: int|null}  $usage
     */
    public function record(
        User $user,
        string $question,
        string $outcome,
        ?string $reason,
        ?array $capabilities,
        ?string $model,
        int $durationMs,
        array $usage = [],
    ): void {
        try {
            CopilotAiLog::query()->create([
                'user_id' => $user->id,
                'question_hash' => hash('sha256', $question),
                'question_length' => min(mb_strlen($question), 65535),
                'capabilities' => $capabilities,
                'model' => $model,
                'instruction_version' => CopilotInstruction::VERSION,
                'duration_ms' => max(0, $durationMs),
                'prompt_tokens' => $usage['prompt_tokens'] ?? null,
                'completion_tokens' => $usage['completion_tokens'] ?? null,
                'outcome' => $outcome,
                'reason' => $reason,
            ]);

            // Retention without depending on the scheduler: the first write of each day prunes.
            if (Cache::add('copilot:ai:log-prune:'.now()->toDateString(), true, now()->addDay())) {
                $this->prune();
            }
        } catch (Throwable $e) {
            Log::warning('Copilot AI audit write failed', ['class' => $e::class]);
        }
    }

    /** Delete rows older than the configured retention. Returns the number removed. */
    public function prune(): int
    {
        $days = max(1, (int) config('copilot.ai.log_retention_days', 90));

        return CopilotAiLog::query()->where('created_at', '<', now()->subDays($days))->delete();
    }
}
