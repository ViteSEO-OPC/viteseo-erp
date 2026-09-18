<?php

namespace App\Modules\Crm\Services\Discovery;

use App\Modules\Crm\Enums\NerbyProvider;

/**
 * Can a discovery run ask AI to plan its searches right now — and if not, why not?
 *
 * ONE ANSWER, READ BY TWO CALLERS. `GET /discovery/config` reports it so the UI offers the AI action
 * only when it can work, and `POST /discovery/runs` refuses an explicit AI request against it before
 * anything is spent. Both read the same method, so the button and the gate cannot disagree.
 *
 * BUILT ENTIRELY FROM STATE THAT ALREADY EXISTS: the `openai` row in `crm_nerby_provider_settings`
 * (presence of a key, `enabled`, and the recorded `last_test_status`, which is where the OpenRouter
 * credit check already lands) and the effective provider. No new credential store, no network call,
 * and nothing it returns is a credential — only one of the status words below.
 *
 * CREDIT IS AS FRESH AS THE LAST OBSERVATION. It is recorded by the Settings connection test and,
 * see {@see recordInsufficientCredit()}, by any real strategy call the provider refuses with 402. A
 * top-up is picked up by re-running the connection test in Settings.
 */
class AiStrategyCapability
{
    public const AVAILABLE = 'available';

    /** No AI credential has been stored. */
    public const NOT_CONFIGURED = 'not_configured';

    /** A credential exists but an admin switched the AI provider off. */
    public const DISABLED = 'disabled';

    /** The credential is valid and the account behind it cannot pay for a call. */
    public const INSUFFICIENT_CREDIT = 'insufficient_credit';

    /**
     * AI planning is offered only on the Google Places Stage 2 flow. On the OpenStreetMap baseline
     * it would not be a Google search at all, and the default Nearby behaviour must stay untouched.
     */
    public const UNSUPPORTED_PROVIDER = 'unsupported_provider';

    public function __construct(private readonly NerbyProviderSettingService $settings) {}

    public function status(): string
    {
        $ai = $this->settings->for(NerbyProvider::OPENAI);

        if (! $ai->exists || ! $ai->hasApiKey()) {
            return self::NOT_CONFIGURED;
        }

        if (! $ai->enabled) {
            return self::DISABLED;
        }

        if ($ai->last_test_status === NerbyProviderSettingService::TEST_INSUFFICIENT_CREDIT) {
            return self::INSUFFICIENT_CREDIT;
        }

        if ($this->settings->resolveQuietly()->provider !== NerbyProvider::GOOGLE_PLACES) {
            return self::UNSUPPORTED_PROVIDER;
        }

        return self::AVAILABLE;
    }

    public function available(): bool
    {
        return $this->status() === self::AVAILABLE;
    }

    /**
     * Record that a REAL call was refused for lack of credit.
     *
     * The same word the Settings connection test writes, into the same column, so the settings card
     * shows "No credit" and `/discovery/config` stops offering AI without anyone re-testing. Only
     * ever writes the shortfall — a success elsewhere never clears it, because clearing is the
     * connection test's job and it asks the balance endpoint rather than inferring from one call.
     */
    public function recordInsufficientCredit(): void
    {
        $ai = $this->settings->for(NerbyProvider::OPENAI);

        if (! $ai->exists) {
            return;
        }

        $ai->forceFill([
            'last_test_status' => NerbyProviderSettingService::TEST_INSUFFICIENT_CREDIT,
            'last_tested_at' => now(),
        ])->save();
    }
}
