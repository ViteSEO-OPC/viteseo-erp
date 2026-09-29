<?php

namespace App\Modules\Crm\Services\Copilot;

use App\Models\User;
use App\Modules\Crm\Enums\NerbyProvider;
use App\Modules\Crm\Models\Lead;
use App\Modules\Crm\Services\Copilot\Prompts\CopilotInstruction;
use App\Modules\Crm\Services\Discovery\AiChatEndpoint;
use App\Modules\Crm\Services\Discovery\AiStrategyCapability;
use App\Modules\Crm\Services\Discovery\NerbyProviderSettingService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The Copilot's OPTIONAL AI fallback: answer a CRM question the rule-based Copilot could not, from
 * the caller's own aggregate lead figures, or decline.
 *
 * ADDITIVE ONLY. Every failure — switched off, no usable credential, provider error, timeout, an
 * out-of-scope or unanswerable question, a malformed or ungrounded answer — returns null, and the
 * Copilot shows the help reply it has always shown. Nothing here writes: not the CRM, and not the
 * NERBY provider row whose credential it borrows. In particular a 402 is NOT recorded as
 * insufficient credit (unlike the NERBY lanes), so a Copilot call can never change NERBY's state.
 *
 * AGGREGATES ONLY. The model sees counts by status, priority and website status over the leads the
 * caller may see (`Lead::visibleTo`, the CRM's one ownership rule) — never a name, contact, email,
 * phone or any other per-lead field. Context is built here, on the server, and never accepted from
 * the client.
 */
class CopilotAiService
{
    /** Hard ceiling on the provider body before anything decodes it. */
    private const MAX_RESPONSE_BYTES = 16384;

    /** Longest answer shown in the Copilot; anything longer is discarded, not truncated. */
    private const MAX_ANSWER_CHARS = 600;

    public function __construct(
        private readonly NerbyProviderSettingService $settings,
        private readonly AiStrategyCapability $capability,
    ) {}

    /** The grounded answer, or null when the Copilot should keep its own help reply. */
    public function answer(User $user, string $question): ?string
    {
        // THE SWITCH FIRST: free, and off means no credential read and no network.
        if (! config('copilot.ai.enabled')) {
            return null;
        }
        if ($this->capability->credential() !== AiStrategyCapability::AVAILABLE) {
            return null;
        }

        $context = $this->context($user);
        $content = $this->ask($question, $context);
        if ($content === null) {
            return null;
        }

        return $this->grounded($content, $context);
    }

    /**
     * The caller's aggregate lead figures. Same population the dashboard counts (`active()` +
     * `visibleTo()`); raw column groupings only, so no business definition is re-derived here.
     *
     * @return array{role: string, total_leads: int, by_status: array<string, int>, by_priority: array<string, int>, by_website_status: array<string, int>}
     */
    public function context(User $user): array
    {
        return [
            'role' => $user->hasAnyRole(['crm_admin', 'super_admin']) ? 'manager (whole CRM)' : 'business developer (own leads)',
            'total_leads' => Lead::query()->active()->visibleTo($user)->count(),
            'by_status' => $this->countBy($user, 'status'),
            'by_priority' => $this->countBy($user, 'priority'),
            'by_website_status' => $this->countBy($user, 'website_status'),
        ];
    }

    /** @return array<string, int> */
    private function countBy(User $user, string $column): array
    {
        return Lead::query()
            ->active()
            ->visibleTo($user)
            ->selectRaw("{$column} as bucket, COUNT(*) as aggregate")
            ->groupBy($column)
            ->pluck('aggregate', 'bucket')
            ->mapWithKeys(static fn ($count, $bucket): array => [
                ($bucket === null || $bucket === '') ? 'unset' : (string) $bucket => (int) $count,
            ])
            ->all();
    }

    /** @param  array<string, mixed>  $context */
    private function ask(string $question, array $context): ?string
    {
        $row = $this->settings->for(NerbyProvider::OPENAI);
        $model = (string) ($row->option('model') ?: config('openai.model', 'gpt-4o-mini'));
        $endpoint = AiChatEndpoint::for((string) $row->api_key, $model);

        $packet = json_encode([
            'untrusted_target_data' => [
                'question' => $question,
                'crm_context' => $context,
            ],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        try {
            $response = Http::withToken((string) $row->api_key)
                ->timeout((float) config('copilot.ai.timeout', 15))
                ->post($endpoint->chatCompletionsUrl(), [
                    'model' => $endpoint->model,
                    'temperature' => 0,
                    'max_tokens' => (int) config('copilot.ai.max_tokens', 300),
                    'response_format' => ['type' => 'json_object'],
                    'messages' => [
                        ['role' => 'system', 'content' => CopilotInstruction::TEXT],
                        ['role' => 'user', 'content' => (string) $packet],
                    ],
                ]);
        } catch (ConnectionException) {
            return null;
        } catch (Throwable $e) {
            // The class only: the request carried the credential, and a message could quote it.
            Log::warning('Copilot AI fallback failed', ['class' => $e::class]);

            return null;
        }

        // Any refusal — credit included — is simply "no answer". Deliberately NOT recorded on the
        // shared NERBY row: the Copilot must never change NERBY's provider state.
        if ($response->failed()) {
            return null;
        }

        $body = (string) $response->body();
        if (strlen($body) > self::MAX_RESPONSE_BYTES) {
            return null;
        }

        $content = $response->json('choices.0.message.content');

        return is_string($content) ? $content : null;
    }

    /**
     * The model's answer if it is well-formed, in scope and grounded; otherwise null.
     *
     * GROUNDED means every number in the answer is one of the figures it was given. The instruction
     * forbids computing new figures; this is what enforces it, so a made-up or derived count is
     * discarded rather than shown.
     *
     * @param  array<string, mixed>  $context
     */
    private function grounded(string $content, array $context): ?string
    {
        $data = json_decode($content, true);
        if (! is_array($data) || ($data['answerable'] ?? null) !== true) {
            return null;
        }

        $answer = $data['answer'] ?? null;
        if (! is_string($answer)) {
            return null;
        }
        $answer = trim($answer);
        if ($answer === '' || mb_strlen($answer) > self::MAX_ANSWER_CHARS) {
            return null;
        }

        $allowed = [];
        array_walk_recursive($context, static function ($value) use (&$allowed): void {
            if (is_int($value)) {
                $allowed[(string) $value] = true;
            }
        });

        preg_match_all('/\d[\d,]*(?:\.\d+)?/', $answer, $matches);
        foreach ($matches[0] as $number) {
            if (! isset($allowed[str_replace(',', '', $number)])) {
                return null;
            }
        }

        return $answer;
    }
}
