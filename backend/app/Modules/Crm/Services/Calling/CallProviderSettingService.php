<?php

declare(strict_types=1);

namespace App\Modules\Crm\Services\Calling;

use App\Models\User;
use App\Modules\Crm\Models\CallProviderSetting;
use App\Support\CurrentOrg;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The single READ and WRITE boundary for an organization's paid-calling configuration.
 *
 * Controllers never touch {@see CallProviderSetting} directly, mirroring {@see NerbyProviderSettingService}
 * and {@see \App\Modules\Crm\Services\BusinessRules\BusinessRuleService}. One boundary means one place
 * that knows the encrypted columns must not be queried, one place that decides what an empty table
 * means, and one place that can verify a credential without ever returning it.
 *
 * AN EMPTY TABLE IS THE DEFAULT, NOT AN ERROR. No row means the org runs the free manual transport,
 * which is exactly today's behaviour — so a CRM that has never opened this settings page needs nothing
 * seeded to work.
 *
 * THE SECRETS NEVER LEAVE. Nothing here returns an auth token or an API secret. Configuration reads
 * answer with identifiers and presence flags; the connection test answers with a status CODE. The
 * plaintext secrets exist at runtime in exactly two places — the outbound probes in {@see test()}, and
 * the token/signature paths in {@see TwilioCallProvider} and the webhook middleware.
 */
class CallProviderSettingService
{
    /** Connection-test outcomes. Deliberately reason CODES, never Twilio's error text. */
    public const TEST_OK = 'ok';
    public const TEST_AUTH_FAILED = 'auth_failed';
    public const TEST_API_KEY_FAILED = 'api_key_failed';
    public const TEST_TWIML_APP_MISSING = 'twiml_app_missing';
    public const TEST_RATE_LIMITED = 'rate_limited';
    public const TEST_UNREACHABLE = 'unreachable';
    public const TEST_NOT_CONFIGURED = 'not_configured';

    /**
     * The org's row for a provider, or an unsaved instance carrying the defaults. Never writes.
     *
     * Scoped normally (no `withoutGlobalScope`): this is the authenticated settings path, where the
     * tenant scope is exactly the protection wanted — an admin can only ever read their own org's row.
     */
    public function for(string $provider = TwilioCallProvider::NAME): CallProviderSetting
    {
        return CallProviderSetting::query()->where('provider', $provider)->first()
            ?? new CallProviderSetting(['provider' => $provider]);
    }

    /** What is in force for the current org — including the deployment fallback when no row exists. */
    public function effectiveConfig(): TwilioConfig
    {
        return TwilioConfig::forOrg(CurrentOrg::id());
    }

    /**
     * Configure the org's Twilio account: enablement, identifiers, secrets.
     *
     * THE SECRETS ARE WRITE-ONLY. An absent `auth_token` / `api_secret` leaves the stored one
     * untouched, so flipping the switch or correcting a caller ID never sends a live secret through a
     * browser again. Clearing them is the separate {@see clearCredentials()}, because a blank field is
     * far more often "I did not retype it" than "delete it".
     *
     * ENABLING AN INCOMPLETE CONFIGURATION IS VALID, deliberately. An admin waiting on a colleague for
     * the API key needs somewhere to record the decision; the capability resolver then reports browser
     * calling as unavailable and names the missing fields, rather than the CRM refusing to remember.
     *
     * @param  array<string, mixed>  $changes  already validated by the form request
     */
    public function configure(array $changes, User $actor, string $provider = TwilioCallProvider::NAME): CallProviderSetting
    {
        $row = $this->for($provider);
        $row->provider = $provider;

        // The switch is NOT a credential. Toggling it deliberately does not invalidate the stored
        // verdict below: nothing about the account changed, and making an admin re-run the connection
        // test every time they flip a switch would be busywork that teaches them to ignore it.
        if (array_key_exists('enabled', $changes)) {
            $row->enabled = (bool) $changes['enabled'];
        }

        // What actually changed. Tracked by VALUE rather than by which keys the request happened to
        // carry, because the settings form submits every identifier on every save — treating that as
        // a change would invalidate the verdict on any edit at all, including one that changed nothing.
        $credentialsChanged = [];

        // Identifiers: plain, and an explicitly empty string means "clear this field". They are not
        // secrets, so unlike the two below there is no ambiguity about a blank submission.
        foreach (['account_sid', 'api_key', 'twiml_app_sid', 'caller_id', 'webhook_base_url'] as $field) {
            if (! array_key_exists($field, $changes)) {
                continue;
            }

            $value = is_string($changes[$field]) ? trim($changes[$field]) : $changes[$field];
            $value = blank($value) ? null : $value;

            if ($value !== $row->{$field}) {
                $row->{$field} = $value;
                $credentialsChanged[] = $field;
            }
        }

        $secretsChanged = [];

        foreach (['auth_token', 'api_secret'] as $field) {
            if (! filled($changes[$field] ?? null)) {
                continue;
            }

            $secret = trim((string) $changes[$field]);

            // Compared against the DECRYPTED value: re-pasting the same secret is not a rotation, and
            // the ciphertext would differ anyway (a fresh IV per write), so comparing stored bytes
            // would report a change every single time.
            if ($secret === $row->{$field}) {
                continue;
            }

            $row->{$field} = $secret;
            $row->{$field.'_last4'} = mb_substr($secret, -4);
            $credentialsChanged[] = $field;
            $secretsChanged[] = $field;
        }

        // A CREDENTIAL change invalidates the previous verdict, and with it browser calling: a stale
        // "ok" against a replaced key would be a claim nothing has verified, and `TwilioConfig` reads
        // this verdict as the gate on offering browser calling at all. Re-testing is what turns it
        // back on.
        if ($credentialsChanged !== []) {
            $row->last_tested_at = null;
            $row->last_test_status = null;
        }

        $row->updated_by = $actor->id;
        $row->save();

        // WHAT changed, never the values. The secrets are named as a list of FIELD NAMES for exactly
        // that reason: the trail must be able to say a credential was rotated without recording any
        // part of it.
        Log::info('Calling provider configuration updated', [
            'actor_id' => $actor->id,
            'org_id' => $row->org_id,
            'provider' => $provider,
            'enabled' => $row->enabled,
            'fields_changed' => $credentialsChanged,
            'secrets_changed' => $secretsChanged,
            'verification_invalidated' => $credentialsChanged !== [],
        ]);

        return $row->refresh();
    }

    /**
     * Remove the org's stored secrets. Idempotent; identifiers and the enable flag are left alone so
     * an admin can paste a rotated key pair back without re-entering everything.
     */
    public function clearCredentials(User $actor, string $provider = TwilioCallProvider::NAME): CallProviderSetting
    {
        $row = $this->for($provider);

        if (! $row->exists) {
            return $row;
        }

        $row->auth_token = null;
        $row->api_secret = null;
        $row->auth_token_last4 = null;
        $row->api_secret_last4 = null;
        $row->last_tested_at = null;
        $row->last_test_status = null;
        $row->updated_by = $actor->id;
        $row->save();

        Log::info('Calling provider credentials cleared', [
            'actor_id' => $actor->id,
            'org_id' => $row->org_id,
            'provider' => $provider,
        ]);

        return $row->refresh();
    }

    /**
     * Verify the stored configuration against Twilio, and record the outcome.
     *
     * NO CALL IS PLACED. Verifying credentials must never cost the client a connected minute or ring a
     * real phone, so the probes are read-only REST lookups on the account itself.
     *
     * Answers with a reason CODE, never Twilio's error body — an upstream message can echo request
     * detail, and this response is going to a browser.
     */
    public function test(User $actor, string $provider = TwilioCallProvider::NAME): CallProviderSetting
    {
        $row = $this->for($provider);
        $config = $row->exists ? TwilioConfig::fromSettings($row) : TwilioConfig::forOrg(CurrentOrg::id());

        // A test that cannot run says so, without a request. Reporting a fabricated success is the one
        // thing this must never do.
        if (! $config->isComplete()) {
            return $this->recordTest($row, self::TEST_NOT_CONFIGURED, $actor, persist: $row->exists);
        }

        return $this->recordTest($row, $this->probe($config), $actor, persist: $row->exists);
    }

    /**
     * Three read-only round trips, each proving one thing the first real call would otherwise discover
     * the hard way:
     *
     *   1. The API key pair can read the account  → `api_key` + `api_secret` + `account_sid` are valid,
     *      which is exactly what signing a browser Access Token requires.
     *   2. The auth token can read the account    → the secret Twilio signs WEBHOOKS with is correct.
     *      Wrong here and every callback would be rejected by our own signature check, leaving calls
     *      that connect but never complete.
     *   3. The TwiML Application exists           → the browser's `Device.connect()` has somewhere to
     *      land. A wrong app SID is a silent failure at dial time.
     *
     * The CALLER ID is deliberately not probed. Twilio accepts outbound caller IDs both from numbers
     * the account owns and from separately verified caller IDs, and checking only the owned-numbers
     * list would report a false failure for a perfectly valid verified number.
     */
    private function probe(TwilioConfig $config): string
    {
        $account = rawurlencode((string) $config->accountSid);
        $accountUrl = $this->baseUrl()."/2010-04-01/Accounts/{$account}.json";

        $withKey = $this->get($accountUrl, (string) $config->apiKey, (string) $config->apiSecret);
        if ($withKey !== self::TEST_OK) {
            return $withKey === self::TEST_AUTH_FAILED ? self::TEST_API_KEY_FAILED : $withKey;
        }

        $withToken = $this->get($accountUrl, (string) $config->accountSid, (string) $config->authToken);
        if ($withToken !== self::TEST_OK) {
            return $withToken;
        }

        $app = rawurlencode((string) $config->twimlAppSid);
        $appUrl = $this->baseUrl()."/2010-04-01/Accounts/{$account}/Applications/{$app}.json";

        $withApp = $this->get($appUrl, (string) $config->apiKey, (string) $config->apiSecret);

        return $withApp === self::TEST_AUTH_FAILED ? self::TEST_TWIML_APP_MISSING : $withApp;
    }

    /** One authenticated round trip mapped to a code. Nothing propagates, and nothing is logged raw. */
    private function get(string $url, string $username, string $password): string
    {
        try {
            $response = Http::withBasicAuth($username, $password)
                ->timeout((float) config('twilio.test_timeout', 8))
                ->acceptJson()
                ->get($url);
        } catch (ConnectionException) {
            return self::TEST_UNREACHABLE;
        } catch (Throwable $e) {
            // Deliberately not logging the message: the request carried a credential, and an
            // exception message can quote the request.
            Log::warning('Calling provider connection test failed', ['class' => $e::class]);

            return self::TEST_UNREACHABLE;
        }

        return $this->classify($response);
    }

    private function classify(Response $response): string
    {
        if ($response->successful()) {
            return self::TEST_OK;
        }

        return match (true) {
            // 404 on the account or application URL means the SID does not exist under these
            // credentials, which for our purposes is the same actionable answer as a rejection.
            in_array($response->status(), [401, 403, 404], true) => self::TEST_AUTH_FAILED,
            $response->status() === 429 => self::TEST_RATE_LIMITED,
            default => self::TEST_UNREACHABLE,
        };
    }

    private function baseUrl(): string
    {
        return rtrim((string) config('twilio.api_base_url', 'https://api.twilio.com'), '/');
    }

    private function recordTest(
        CallProviderSetting $row,
        string $status,
        User $actor,
        bool $persist,
    ): CallProviderSetting {
        $row->last_test_status = $status;
        $row->last_tested_at = now();

        if ($persist) {
            $row->updated_by = $actor->id;
            $row->save();
            $row->refresh();
        }

        Log::info('Calling provider connection test', [
            'actor_id' => $actor->id,
            'org_id' => $row->org_id,
            'provider' => $row->provider,
            'status' => $status,
        ]);

        return $row;
    }
}
