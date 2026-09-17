<?php

namespace App\Modules\Crm\Services\Discovery;

/**
 * Where an AI-assisted Nearby call goes, decided by the stored credential.
 *
 * The AI provider speaks the OpenAI chat-completions protocol. OpenRouter serves the same protocol at a
 * different base URL, with vendor-prefixed model ids (`openai/gpt-4o-mini`), and its keys are
 * recognisable by their `sk-or-` prefix. Routing on the key keeps the change invisible everywhere
 * else: an existing OpenAI key resolves to exactly the URL and model it always used, no new setting
 * or migration is needed, and the credential still lives only in the encrypted provider settings.
 *
 * Only the Nearby discovery services use this. `config/openai.php` (and the Website Intelligence
 * adjudicator that reads it) is untouched.
 */
final readonly class AiChatEndpoint
{
    public const OPENROUTER_BASE_URL = 'https://openrouter.ai/api/v1';

    public const OPENROUTER_KEY_PREFIX = 'sk-or-';

    private function __construct(
        public string $baseUrl,
        public string $model,
        public bool $isOpenRouter,
    ) {}

    public static function for(string $apiKey, string $model): self
    {
        if (str_starts_with($apiKey, self::OPENROUTER_KEY_PREFIX)) {
            // OpenRouter addresses models as `vendor/model`. A bare OpenAI model name — what the
            // settings page stores today — is given its vendor; an already-qualified id is kept.
            $qualified = $model === '' || str_contains($model, '/') ? $model : 'openai/'.$model;

            return new self(self::OPENROUTER_BASE_URL, $qualified, true);
        }

        return new self(rtrim((string) config('openai.base_url', 'https://api.openai.com/v1'), '/'), $model, false);
    }

    public function chatCompletionsUrl(): string
    {
        return $this->baseUrl.'/chat/completions';
    }

    /**
     * An authenticated, zero-cost request for the connection test. OpenAI's `/models` requires the key;
     * OpenRouter's does not (it is public), so a bad OpenRouter key would pass there — its `/key`
     * endpoint is the one that actually checks the credential.
     */
    public function probeUrl(): string
    {
        return $this->baseUrl.($this->isOpenRouter ? '/key' : '/models');
    }
}
