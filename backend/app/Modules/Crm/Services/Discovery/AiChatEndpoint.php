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

    /*
     * FAILURE VOCABULARY, shared by every caller of this endpoint.
     *
     * These values are deliberately IDENTICAL to the `STATUS_*` constants on the discovery contracts
     * and the `TEST_*` constants on {@see NerbyProviderSettingService}, so a caller can hand a
     * classified code straight to its own vocabulary without translating it. `AiChatEndpointTest`
     * pins that equality, so the sets cannot drift apart silently.
     */
    public const FAILURE_AUTH = 'auth_failed';

    public const FAILURE_RATE_LIMITED = 'rate_limited';

    /** A genuine, well-formed key with no money behind it. See {@see classify()}. */
    public const FAILURE_INSUFFICIENT_CREDIT = 'insufficient_credit';

    public const FAILURE_UNREACHABLE = 'unreachable';

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

    /**
     * One HTTP failure, reduced to the reason code every caller reports.
     *
     * THE MAPPING LIVES HERE AND NOWHERE ELSE. Three discovery services and the connection test each
     * used to carry their own copy of this `match`, which is how 402 came to be missing from all
     * four at once: a status nobody had thought about fell through every `default` independently.
     * One classifier means a newly understood status is understood everywhere in a single edit.
     */
    public static function classify(int $httpStatus): string
    {
        return match (true) {
            in_array($httpStatus, [401, 403], true) => self::FAILURE_AUTH,
            // 402 PAYMENT REQUIRED is how OpenRouter reports a key that is genuine and correctly
            // formed but has no credit left. Reporting it as `unreachable` — which is what every
            // caller did before this method existed — sends an operator to look at DNS, firewalls
            // and timeouts for a problem that is solved by topping up an account. It is also the
            // one failure that is guaranteed to persist until a human acts, so it must be the one
            // failure that names itself.
            $httpStatus === 402 => self::FAILURE_INSUFFICIENT_CREDIT,
            $httpStatus === 429 => self::FAILURE_RATE_LIMITED,
            default => self::FAILURE_UNREACHABLE,
        };
    }

    /**
     * The account-balance endpoint, or null for a provider that has none.
     *
     * WHY A SECOND REQUEST IS UNAVOIDABLE. `/key` describes the KEY — the spending limit configured
     * on that credential and how much of it has been used. It says nothing about whether the account
     * behind it holds any credit, and the two are genuinely independent: a key showing "20 of 20
     * remaining" on an account with a zero balance answers 200 here and 402 on every real call. That
     * exact combination was observed on this deployment's live credential, which is why the balance
     * is asked for separately rather than inferred.
     *
     * Both requests are free GETs and the connection test is a deliberate, rate-limited admin action,
     * so the second round trip costs nothing worth saving.
     */
    public function creditsUrl(): ?string
    {
        return $this->isOpenRouter ? $this->baseUrl.'/credits' : null;
    }

    /**
     * Whether the responses prove this credential cannot actually pay for a call.
     *
     * WHY A 200 IS NOT ENOUGH. OpenRouter's `/key` answers 200 for any well-formed key, including
     * one that cannot spend anything — it reports the credential, not the wallet. So the connection
     * test used to paint a green "Verified" tick beside a key that fails 402 on every real request,
     * which is the most misleading state the settings page can be in: it tells an operator the
     * integration is fine while nothing works.
     *
     * TWO INDEPENDENT WAYS TO BE UNABLE TO PAY, and either one is disqualifying:
     *   - the ACCOUNT holds no credit (`/credits`: `total_credits - total_usage <= 0`);
     *   - the KEY has spent its own configured limit (`/key`: `limit_remaining`, or `usage >= limit`).
     *
     * DELIBERATELY CONSERVATIVE. Only a body that positively STATES the shortfall counts. A missing,
     * null or unlimited `limit` is not exhaustion, an unparseable or failed response is not
     * exhaustion, and OpenAI — whose `/models` probe carries no budget at all — can never reach any
     * of this. A false "out of credit" on a working key would be worse than the bug being fixed, so
     * anything ambiguous stays OK and lets the real call be the judge.
     *
     * @param  array<string, mixed>|null  $keyBody  the decoded `/key` response
     * @param  array<string, mixed>|null  $creditsBody  the decoded `/credits` response, when fetched
     */
    public function creditExhausted(?array $keyBody, ?array $creditsBody = null): bool
    {
        if (! $this->isOpenRouter) {
            return false;
        }

        return $this->accountIsEmpty($creditsBody) || $this->keyLimitIsSpent($keyBody);
    }

    /**
     * The authoritative signal: what the ACCOUNT can still spend.
     *
     * @param  array<string, mixed>|null  $creditsBody
     */
    private function accountIsEmpty(?array $creditsBody): bool
    {
        $data = is_array($creditsBody) ? ($creditsBody['data'] ?? null) : null;
        if (! is_array($data)) {
            return false;
        }

        $granted = $data['total_credits'] ?? null;
        $spent = $data['total_usage'] ?? null;

        // Both numbers are required. A body carrying only one of them has not stated a balance, and
        // guessing the other would be inventing the fact this method exists to read.
        return is_numeric($granted) && is_numeric($spent) && (float) $granted - (float) $spent <= 0.0;
    }

    /**
     * The secondary signal: a key that has spent the ceiling set on it, even on a funded account.
     *
     * @param  array<string, mixed>|null  $keyBody
     */
    private function keyLimitIsSpent(?array $keyBody): bool
    {
        $data = is_array($keyBody) ? ($keyBody['data'] ?? null) : null;
        if (! is_array($data)) {
            return false;
        }

        // The direct answer when OpenRouter supplies it: spend left on this key.
        $remaining = $data['limit_remaining'] ?? null;
        if (is_numeric($remaining)) {
            return (float) $remaining <= 0.0;
        }

        // Otherwise derive it, but only from a REAL ceiling. A null `limit` means unlimited, which
        // is the common shape and must never read as spent.
        $limit = $data['limit'] ?? null;
        $usage = $data['usage'] ?? null;

        return is_numeric($limit) && is_numeric($usage) && (float) $usage >= (float) $limit;
    }
}
