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
     * The TwiML Application exists, but its Voice Request URL is not this deployment's — it is blank,
     * or it points somewhere else.
     *
     * This is the one failure the rest of the check could not see. Every other probe asks whether a
     * CREDENTIAL is right; this asks whether the account is pointed at us. An onboarding can get all
     * six values correct, pass every authentication probe, and still produce calls that die the instant
     * a BD presses Call, because the app Twilio dials into fetches its instructions from somewhere that
     * is not this deployment. It was also invisible from our side: Twilio does not call us to tell us
     * it went elsewhere.
     */
    public const TEST_TWIML_APP_VOICE_URL_MISMATCH = 'twiml_app_voice_url_mismatch';

    /**
     * The Voice Request URL is right, but Twilio would fetch it with the wrong HTTP method.
     *
     * A separate verdict from the one above because it is a separate mistake with a separate fix — the
     * URL field is correct and the dropdown beside it is not — and "check your Voice URL" sends an
     * admin to re-read a value that is already right.
     */
    public const TEST_TWIML_APP_VOICE_METHOD_INVALID = 'twiml_app_voice_method_invalid';

    /**
     * The path Twilio's Voice request must reach on this deployment — the route the browser Device's
     * `Device.connect()` ultimately lands on (`Routes/api.php`, the `v1/twilio` group). Declared here
     * because the connection test is the only thing that can tell an admin their TwiML App points at
     * it, and the frontend's `VOICE_WEBHOOK_PATH` states the same path for display.
     */
    public const VOICE_WEBHOOK_PATH = '/api/v1/twilio/voice';

    /**
     * The method that path must be fetched with. POST, because the correlation token and Twilio's own
     * call parameters travel in the form body — with GET, `ValidateTwilioSignature` is handed a body
     * Twilio never signed and the webhook is refused before it can dial anything.
     */
    public const VOICE_WEBHOOK_METHOD = 'POST';

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

    /**
     * What is in force for the current org, exactly as every calling path resolves it: the centralized
     * account only where that account applies, and nothing at all for a client with no row of its own.
     */
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
        //
        // The callback origin is deliberately NOT among them: it is the deployment's, never a tenant's
        // (see TwilioConfig::fromSettings()), so nothing an organization submits can move it.
        foreach (['account_sid', 'api_key', 'twiml_app_sid', 'caller_id'] as $field) {
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
            // would report a change every single time. A stored secret that cannot be decrypted reads
            // as absent, so pasting it again is exactly how an admin repairs it.
            if ($secret === $row->secret($field)) {
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
     *
     * ONLY THE ORGANIZATION'S OWN STORED CREDENTIALS ARE EVER TESTED. An organization with no row has
     * nothing to test and is told so without a request — it is never answered by probing some other
     * account on its behalf, the centralized ViteSEO account least of all. (The centrally managed
     * organization never reaches this: its settings are read-only, and the centralized account is
     * verified by being installed — see TwilioConfig::isVerified().)
     */
    public function test(User $actor, string $provider = TwilioCallProvider::NAME): CallProviderSetting
    {
        $row = $this->for($provider);

        if (! $row->exists) {
            return $this->recordTest($row, self::TEST_NOT_CONFIGURED, $actor, persist: false);
        }

        $config = TwilioConfig::fromSettings($row);

        // A test that cannot run says so, without a request. Reporting a fabricated success is the one
        // thing this must never do.
        if (! $config->isComplete()) {
            return $this->recordTest($row, self::TEST_NOT_CONFIGURED, $actor, persist: true);
        }

        return $this->recordTest($row, $this->probe($config), $actor, persist: true);
    }

    /**
     * Three read-only round trips, each proving one thing the first real call would otherwise discover
     * the hard way — and one check, on the body of the third, of whether the account is pointed at us
     * at all:
     *
     *   1. The API key pair can read the account  → `api_key` + `api_secret` + `account_sid` are valid,
     *      which is exactly what signing a browser Access Token requires.
     *   2. The auth token can read the account    → the secret Twilio signs WEBHOOKS with is correct.
     *      Wrong here and every callback would be rejected by our own signature check, leaving calls
     *      that connect but never complete.
     *   3. The TwiML Application exists           → the browser's `Device.connect()` has somewhere to
     *      land. A wrong app SID is a silent failure at dial time.
     *   4. That application POINTS AT US          → its Voice Request URL is this deployment's
     *      `/api/v1/twilio/voice`, fetched with POST. Costs no extra round trip — Twilio already
     *      described the application in step 3. Steps 1-3 only prove the credentials are REAL; this
     *      proves the account is wired to this CRM. Without it an onboarding can hold six correct
     *      values, pass every authentication probe, and still fail the instant a BD presses Call:
     *      Twilio dials into the app, the app fetches its instructions from somewhere else, and
     *      nothing ever reaches us to say so.
     *
     * The CALLER ID is deliberately not probed. Twilio accepts outbound caller IDs both from numbers
     * the account owns and from separately verified caller IDs, and checking only the owned-numbers
     * list would report a false failure for a perfectly valid verified number. That is a real gap and
     * it is stated on the settings card in as many words: a passing test does not mean the caller ID
     * is eligible to place calls.
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

        [$withApp, $application] = $this->fetch($appUrl, (string) $config->apiKey, (string) $config->apiSecret);

        if ($withApp !== self::TEST_OK) {
            return $withApp === self::TEST_AUTH_FAILED ? self::TEST_TWIML_APP_MISSING : $withApp;
        }

        return $this->classifyVoiceWiring($config, $application);
    }

    /**
     * Whether the TwiML Application Twilio just described is pointed at THIS deployment's voice webhook.
     *
     * The expected URL is built from the same configuration the webhook signature is validated against
     * ({@see TwilioConfig::callbackUrl()}), so this can never pass for a URL that would then fail
     * signature validation, and can never demand one the deployment is not actually reachable at.
     *
     * The comparison is EXACT after trimming. Not lenient on a trailing slash, and not
     * case-insensitive: a trailing slash is a different Laravel route and does not work for a POST, so
     * accepting it here would hand back a green tick for a configuration that still cannot place a
     * call. The exact string is displayed on the settings card for copy-paste, which is what makes
     * strictness fair rather than pedantic.
     *
     * An application whose voice URL Twilio reports as absent reads as a mismatch, which it is: the app
     * is not pointed at us.
     *
     * @param  array<string, mixed>  $application  Twilio's JSON for the application
     */
    private function classifyVoiceWiring(TwilioConfig $config, array $application): string
    {
        $expected = $config->callbackUrl(self::VOICE_WEBHOOK_PATH);
        $actual = trim((string) ($application['voice_url'] ?? ''));

        if ($expected !== $actual) {
            return self::TEST_TWIML_APP_VOICE_URL_MISMATCH;
        }

        // Twilio stores and returns the method uppercased, but an upper-casing comparison costs
        // nothing and cannot accept a method that would not work.
        $method = strtoupper(trim((string) ($application['voice_method'] ?? '')));

        return $method === self::VOICE_WEBHOOK_METHOD
            ? self::TEST_OK
            : self::TEST_TWIML_APP_VOICE_METHOD_INVALID;
    }

    /** One authenticated round trip mapped to a code. Nothing propagates, and nothing is logged raw. */
    private function get(string $url, string $username, string $password): string
    {
        return $this->fetch($url, $username, $password)[0];
    }

    /**
     * One authenticated round trip mapped to a code, WITH the decoded body for the caller that needs to
     * read it. The body is only ever non-empty on a success, so no failure path can be made to depend
     * on what an error response happened to contain.
     *
     * @return array{0: string, 1: array<string, mixed>}
     */
    private function fetch(string $url, string $username, string $password): array
    {
        try {
            $response = Http::withBasicAuth($username, $password)
                ->timeout((float) config('twilio.test_timeout', 8))
                ->acceptJson()
                ->get($url);
        } catch (ConnectionException) {
            return [self::TEST_UNREACHABLE, []];
        } catch (Throwable $e) {
            // Deliberately not logging the message: the request carried a credential, and an
            // exception message can quote the request.
            Log::warning('Calling provider connection test failed', ['class' => $e::class]);

            return [self::TEST_UNREACHABLE, []];
        }

        $status = $this->classify($response);

        return [$status, $status === self::TEST_OK ? (array) $response->json() : []];
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
