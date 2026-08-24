<?php

namespace App\Modules\Crm\Services\Discovery;

use App\Models\User;
use App\Modules\Crm\Enums\NerbyProvider;
use App\Modules\Crm\Models\NerbyProviderSetting;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The single READ and WRITE boundary for Nearby's discovery-provider configuration.
 *
 * Controllers never touch {@see NerbyProviderSetting} directly, mirroring how
 * {@see \App\Modules\Crm\Services\BusinessRules\BusinessRuleService} is the sole author of Business
 * Rules state. One boundary means one place that knows the encrypted column must not be queried, one
 * place that decides what an empty table means, one place that resolves a fallback, and one place
 * that keeps the single-active-provider invariant.
 *
 * PROVIDER-NEUTRAL BY CONSTRUCTION. Nothing below names OpenAI. Credential and enablement rules are
 * driven by {@see NerbyProvider::requiresCredentials()}, so a structured Places provider added later
 * inherits "selected but unconfigured ⇒ visibly falls back to OpenStreetMap" without a line changing
 * here. That is what makes the architecture extensible rather than merely two-valued.
 *
 * AN EMPTY TABLE IS THE DEFAULT, NOT AN ERROR. No rows means OpenStreetMap, which is exactly today's
 * behaviour — so a CRM that has never opened the settings page needs nothing seeded to work.
 *
 * THE CREDENTIAL NEVER LEAVES. Nothing here returns an API key. Resolution answers with provider
 * identifiers and a reason code; the connection test answers with a status. The plaintext key exists
 * at runtime in exactly one place — inside {@see test()}, on the outbound request that needs it.
 */
class NerbyProviderSettingService
{
    /** Connection-test outcomes. Deliberately reason CODES, never provider error text. */
    public const TEST_OK = 'ok';
    public const TEST_AUTH_FAILED = 'auth_failed';
    public const TEST_RATE_LIMITED = 'rate_limited';
    public const TEST_UNREACHABLE = 'unreachable';
    public const TEST_NOT_CONFIGURED = 'not_configured';
    public const TEST_NOT_APPLICABLE = 'not_applicable';

    /**
     * Every provider's configuration, keyed by provider value — including providers with no row yet,
     * represented by an unsaved model.
     *
     * The settings UI needs a complete picture (OpenStreetMap and OpenAI today, a Places provider
     * tomorrow) whether or not anyone has configured each one, and callers should not have to
     * distinguish "no row" from "default row".
     *
     * @return array<string, NerbyProviderSetting>
     */
    public function all(): array
    {
        /** @var Collection<int, NerbyProviderSetting> $stored */
        $stored = NerbyProviderSetting::query()->get()->keyBy('provider');

        $out = [];
        foreach (NerbyProvider::cases() as $provider) {
            $out[$provider->value] = $stored->get($provider->value)
                ?? new NerbyProviderSetting(['provider' => $provider->value]);
        }

        return $out;
    }

    /** One provider's configuration, or an unsaved instance carrying the defaults. Never writes. */
    public function for(NerbyProvider $provider): NerbyProviderSetting
    {
        return NerbyProviderSetting::query()->where('provider', $provider->value)->first()
            ?? new NerbyProviderSetting(['provider' => $provider->value]);
    }

    /** The selected row, or null when nothing has been selected. */
    public function activeRow(): ?NerbyProviderSetting
    {
        return NerbyProviderSetting::query()->where('is_active', true)->first();
    }

    /**
     * WHICH PROVIDER WILL RUN, and why. The observable-fallback contract.
     *
     * Logs a warning whenever the effective provider differs from the selected one. That log is the
     * point: an operator who chose a provider and is quietly getting OpenStreetMap needs to learn it
     * from a diagnostic, not from wondering why their token usage is zero.
     */
    public function resolve(): EffectiveNerbyProvider
    {
        $resolved = $this->resolveQuietly();

        if ($resolved->isFallback()) {
            Log::warning('Nearby discovery provider fell back', $resolved->toDiagnostics());
        }

        return $resolved;
    }

    /**
     * The resolution rules, without the logging side effect.
     *
     * Separated so callers that resolve repeatedly — a settings page render, a diagnostics endpoint —
     * do not spam the log with a condition the operator has already been told about.
     */
    public function resolveQuietly(): EffectiveNerbyProvider
    {
        $active = $this->activeRow();

        // Nothing selected: the out-of-the-box state. Reported distinctly from a deliberately-chosen
        // OpenStreetMap, because "nobody has configured this" and "someone picked the baseline" are
        // different facts an operator may want to tell apart.
        if ($active === null) {
            return new EffectiveNerbyProvider(
                provider: NerbyProvider::default(),
                configured: NerbyProvider::default(),
                reason: EffectiveNerbyProvider::REASON_DEFAULT,
                mode: 'live',
            );
        }

        $raw = (string) $active->provider;
        $configured = NerbyProvider::fromStringOrDefault($raw);

        // A stored value this build does not know — a row from a newer release, or hand-edited.
        // Degrade and say so: a settings page that 500s is worse than one that reports a problem.
        if (NerbyProvider::tryFrom($raw) === null) {
            return new EffectiveNerbyProvider(
                provider: NerbyProvider::default(),
                configured: $configured,
                reason: EffectiveNerbyProvider::REASON_UNKNOWN_PROVIDER,
                mode: 'live',
            );
        }

        // The baseline needs no credential and is always runnable.
        if (! $configured->requiresCredentials()) {
            return new EffectiveNerbyProvider(
                provider: $configured,
                configured: $configured,
                reason: EffectiveNerbyProvider::REASON_CONFIGURED,
                mode: 'live',
            );
        }

        // Selected but switched off: the configuration (credential, options) is kept so it can be
        // turned back on without re-entering anything, and the baseline runs meanwhile.
        if (! $active->enabled) {
            return $this->fallback($configured, EffectiveNerbyProvider::REASON_DISABLED);
        }

        // Selected and enabled, but nothing to authenticate with. THE case this class exists for:
        // it must never look as though the selected provider is running.
        if (! $active->hasApiKey()) {
            return $this->fallback($configured, EffectiveNerbyProvider::REASON_NOT_CONFIGURED);
        }

        return new EffectiveNerbyProvider(
            provider: $configured,
            configured: $configured,
            reason: EffectiveNerbyProvider::REASON_CONFIGURED,
            mode: 'live',
        );
    }

    private function fallback(NerbyProvider $configured, string $reason): EffectiveNerbyProvider
    {
        return new EffectiveNerbyProvider(
            provider: NerbyProvider::default(),
            configured: $configured,
            reason: $reason,
            mode: 'preview',
        );
    }

    /**
     * Select the provider Nearby uses, keeping the single-active invariant.
     *
     * One transaction: deactivate everything, then activate the chosen row (creating it if this is
     * the first time that provider has been selected). Done in two statements rather than one
     * because "at most one active" is the invariant, and a partial write that left two rows active
     * would make {@see activeRow()} nondeterministic.
     *
     * Selecting a provider deliberately does NOT enable it and does NOT touch any credential.
     * Choosing an engine and authorising it to spend money are different decisions, and conflating
     * them would mean a mis-click could start billing.
     */
    public function selectProvider(NerbyProvider $provider, User $actor): void
    {
        DB::transaction(function () use ($provider, $actor): void {
            NerbyProviderSetting::query()->where('is_active', true)->update(['is_active' => false]);

            $row = $this->for($provider);
            $row->provider = $provider->value;
            $row->is_active = true;
            $row->updated_by = $actor->id;
            $row->save();
        });

        Log::info('Nearby discovery provider selected', [
            'actor_id' => $actor->id,
            'provider' => $provider->value,
        ]);
    }

    /**
     * Configure one provider: enablement, credential, options.
     *
     * The credential is WRITE-ONLY. An absent key leaves the stored one untouched, so changing a
     * model or flipping the switch never sends it through a browser again. Clearing is the separate
     * {@see clearCredential()}, because a blank field is far more often "I did not retype my key"
     * than "delete my key".
     *
     * @param  array<string, mixed>  $changes  already validated by the form request
     */
    public function configure(NerbyProvider $provider, array $changes, User $actor): NerbyProviderSetting
    {
        $row = $this->for($provider);
        $row->provider = $provider->value;

        if (array_key_exists('enabled', $changes)) {
            $row->enabled = (bool) $changes['enabled'];
        }

        if (array_key_exists('options', $changes)) {
            // Only keys this provider declares. An unknown option is dropped rather than stored, so
            // the bag cannot silently accumulate settings nothing reads.
            $allowed = $provider->optionKeys();
            $incoming = is_array($changes['options']) ? $changes['options'] : [];
            $row->options = array_intersect_key($incoming, array_flip($allowed)) ?: null;
        }

        $keySubmitted = filled($changes['api_key'] ?? null);
        if ($keySubmitted) {
            $key = trim((string) $changes['api_key']);
            $row->api_key = $key;
            $row->key_last4 = mb_substr($key, -4);
            // A new credential invalidates the previous verdict — a stale "ok" against a replaced key
            // would be a claim nothing has verified.
            $row->last_tested_at = null;
            $row->last_test_status = null;
        }

        $row->updated_by = $actor->id;
        $row->save();

        // WHAT changed, never the values. `key_changed` is a boolean for exactly that reason: the
        // trail must be able to say a credential was rotated without recording any part of it.
        Log::info('Nearby provider configuration updated', [
            'actor_id' => $actor->id,
            'provider' => $provider->value,
            'enabled' => $row->enabled,
            'options' => $row->options,
            'key_changed' => $keySubmitted,
        ]);

        return $row->refresh();
    }

    /** Remove a provider's credential. Idempotent; leaves selection and enablement alone. */
    public function clearCredential(NerbyProvider $provider, User $actor): NerbyProviderSetting
    {
        $row = $this->for($provider);
        if (! $row->exists) {
            return $row;
        }

        $row->api_key = null;
        $row->key_last4 = null;
        $row->last_tested_at = null;
        $row->last_test_status = null;
        $row->updated_by = $actor->id;
        $row->save();

        Log::info('Nearby provider credential cleared', [
            'actor_id' => $actor->id,
            'provider' => $provider->value,
        ]);

        return $row->refresh();
    }

    /**
     * Verify a provider's stored credential, and record the outcome.
     *
     * Answers with a reason CODE, never the provider's error body — an upstream message can echo
     * request detail, and this response is going to a browser.
     *
     * Two non-calls are deliberate. A provider that needs no credential reports `not_applicable`
     * without a request (there is nothing to authenticate). A provider with no key stored reports
     * `not_configured` without a request — a test that cannot run must say so, because reporting a
     * fabricated success is the one thing this must never do.
     */
    public function test(NerbyProvider $provider, User $actor): NerbyProviderSetting
    {
        $row = $this->for($provider);

        if (! $provider->requiresCredentials()) {
            return $this->recordTest($row, self::TEST_NOT_APPLICABLE, $actor, persist: false);
        }

        if (! $row->exists || ! $row->hasApiKey()) {
            return $this->recordTest($row, self::TEST_NOT_CONFIGURED, $actor, persist: $row->exists);
        }

        return $this->recordTest($row, $this->probe($provider, (string) $row->api_key), $actor, persist: true);
    }

    /**
     * One authenticated round trip. Every failure maps to a code; nothing propagates.
     *
     * The endpoint is chosen per provider so a future engine plugs in here rather than in the caller.
     * For OpenAI a models-list request proves authentication without spending tokens.
     */
    private function probe(NerbyProvider $provider, string $apiKey): string
    {
        $url = match ($provider) {
            NerbyProvider::OPENAI => rtrim((string) config('openai.base_url', 'https://api.openai.com/v1'), '/').'/models',
            // Unreachable today: the baseline never requires credentials and is filtered out above.
            NerbyProvider::OPENSTREETMAP => null,
        };

        if ($url === null) {
            return self::TEST_NOT_APPLICABLE;
        }

        try {
            $response = Http::withToken($apiKey)->timeout((float) config('openai.timeout', 8))->get($url);
        } catch (ConnectionException) {
            return self::TEST_UNREACHABLE;
        } catch (Throwable $e) {
            // Deliberately not logging the message: the request carried the credential, and an
            // exception message can quote the request.
            Log::warning('Nearby provider connection test failed', [
                'provider' => $provider->value,
                'class' => $e::class,
            ]);

            return self::TEST_UNREACHABLE;
        }

        if ($response->successful()) {
            return self::TEST_OK;
        }

        return match (true) {
            in_array($response->status(), [401, 403], true) => self::TEST_AUTH_FAILED,
            $response->status() === 429 => self::TEST_RATE_LIMITED,
            default => self::TEST_UNREACHABLE,
        };
    }

    private function recordTest(
        NerbyProviderSetting $row,
        string $status,
        User $actor,
        bool $persist,
    ): NerbyProviderSetting {
        $row->last_test_status = $status;
        $row->last_tested_at = now();

        if ($persist) {
            $row->updated_by = $actor->id;
            $row->save();
            $row->refresh();
        }

        Log::info('Nearby provider connection test', [
            'actor_id' => $actor->id,
            'provider' => $row->provider,
            'status' => $status,
        ]);

        return $row;
    }
}
