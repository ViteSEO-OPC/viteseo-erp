<?php

declare(strict_types=1);

namespace App\Modules\Crm\Services\AiDraft;

/**
 * The outcome of one draft request: either a draft or a fixed failure code, never both.
 *
 * The failure vocabulary is CLOSED. A client branches on these strings; upstream error text is
 * never carried here, because a provider error message can quote the prompt — which is customer
 * text — straight back at us.
 */
final readonly class ReplyDraftResult
{
    /** `ai_drafts.enabled` is off. No HTTP was attempted. */
    public const DISABLED = 'disabled';

    /** The flag is on but no API key is configured. No HTTP was attempted. */
    public const NOT_CONFIGURED = 'not_configured';

    /** The thread has no text to draft from (e.g. attachments only). No HTTP was attempted. */
    public const EMPTY_CONVERSATION = 'empty_conversation';

    /** Connection failure or timeout. */
    public const TIMEOUT = 'timeout';

    /** Upstream answered 429. */
    public const RATE_LIMITED = 'rate_limited';

    /** Upstream answered 401 or 403 — our key, not the caller's fault. */
    public const AUTH_FAILED = 'auth_failed';

    /** Any other non-2xx, or an unexpected client exception. */
    public const UPSTREAM_ERROR = 'upstream_error';

    /** A 2xx we could not read, or an empty completion. */
    public const INVALID_RESPONSE = 'invalid_response';

    private function __construct(
        public ?string $draft,
        public ?string $failureCode,
        public string $model,
    ) {}

    public static function drafted(string $draft, string $model): self
    {
        return new self($draft, null, $model);
    }

    public static function failed(string $failureCode, string $model): self
    {
        return new self(null, $failureCode, $model);
    }

    public function succeeded(): bool
    {
        return $this->draft !== null;
    }
}
