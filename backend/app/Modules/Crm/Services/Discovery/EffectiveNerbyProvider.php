<?php

namespace App\Modules\Crm\Services\Discovery;

use App\Modules\Crm\Enums\NerbyProvider;

/**
 * WHICH PROVIDER WILL ACTUALLY RUN, AND WHY — never just which one was configured.
 *
 * The whole reason this class exists rather than a bare enum return: choosing OpenAI does not
 * guarantee OpenAI runs. Without a stored key, or with the integration switched off, discovery falls
 * back to OpenStreetMap. That fallback is correct (OSM is the safe baseline) but it must never be
 * INVISIBLE: a run labelled "openai" that actually queried Overpass would make the provider
 * comparison meaningless and would have an operator debugging the wrong system.
 *
 * So every resolution carries three things: what runs, what was asked for, and the reason they
 * differ. {@see isFallback()} is the diagnostic signal — when it is true the service logs a warning
 * and the API surfaces the reason, so "OpenAI is selected but OSM is running" is a visible state
 * rather than something you discover from a Nominatim bill that never arrived.
 *
 * Immutable.
 */
final readonly class EffectiveNerbyProvider
{
    /** No provider is selected at all — the out-of-the-box state. */
    public const REASON_DEFAULT = 'default_no_configuration';

    /** The configured provider is the one running. Nothing to explain. */
    public const REASON_CONFIGURED = 'configured';

    /** The selected provider is switched off, so the baseline runs. */
    public const REASON_DISABLED = 'provider_disabled';

    /**
     * The selected provider is enabled but has no credential — nothing to authenticate with.
     *
     * Provider-neutral on purpose: a structured Places provider added later inherits this reason
     * without a new constant, because "selected but unconfigured" is the same fact whichever engine
     * it is about.
     */
    public const REASON_NOT_CONFIGURED = 'provider_not_configured';

    /** A stored provider value this build does not recognise (older release, hand-edited row). */
    public const REASON_UNKNOWN_PROVIDER = 'unknown_provider';

    public function __construct(
        /** The provider that will actually perform discovery. */
        public NerbyProvider $provider,
        /** What the configuration asked for — equal to `provider` unless a fallback occurred. */
        public NerbyProvider $configured,
        /** One of the REASON_* constants. */
        public string $reason,
        /**
         * `live` when the effective provider can genuinely run, `preview` when a provider is
         * configured but not yet operable (selected, no credentials). Preview is how the UI can show
         * planned capabilities honestly without implying they are active.
         */
        public string $mode,
    ) {}

    /** True when the provider running is NOT the one configured — the state worth logging. */
    public function isFallback(): bool
    {
        return $this->provider !== $this->configured;
    }

    /**
     * Diagnostic payload. Safe by construction: it carries provider identifiers and a reason code,
     * and there is no code path by which a credential could enter it.
     *
     * @return array<string, mixed>
     */
    public function toDiagnostics(): array
    {
        return [
            'effective_provider' => $this->provider->value,
            'configured_provider' => $this->configured->value,
            'reason' => $this->reason,
            'mode' => $this->mode,
            'is_fallback' => $this->isFallback(),
        ];
    }
}
