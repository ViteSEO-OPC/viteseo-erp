<?php

declare(strict_types=1);

namespace App\Modules\Crm\Services\Calling;

use App\Modules\Crm\Models\CallProviderSetting;
use Illuminate\Support\Facades\Config;

/**
 * The Twilio credentials in force for Zerem, and the reasons they may not be usable.
 *
 * ── ONE centralized ViteSEO Twilio account — this is intentional ──
 * Zerem is operated by ViteSEO for its own employees and places browser calls through ONE centralized
 * Twilio account: the server-side TWILIO_* configuration in `config/twilio.php`. Every authorized CRM
 * user — the existing `crm.calls.log` permission plus access to the lead — calls through that same
 * account and caller ID. That is the business model, NOT an accidental fallback: do not "fix" it into
 * per-employee or per-organization accounts without a product decision.
 *
 * Those credentials never leave the server. The browser receives only a short-lived Access Token signed
 * with the API key pair; the Auth Token and API secret are never serialized, logged or returned, and
 * no request can supply credentials of its own.
 *
 * ── Per-organization accounts: retained, but OFF ──
 * `crm_call_provider_settings` (Settings → Integrations → Calling) can hold an organization's own Twilio
 * account. That support is kept, but it is dormant: stored rows are consulted ONLY when a deployment
 * explicitly sets `calling.organization_credentials` (CALLING_ORGANIZATION_CREDENTIALS=true). With it
 * off — the default, and Zerem's configuration — {@see forOrg()} always answers with the centralized
 * account, so nobody can redirect Zerem's calls to another account from a settings screen, and every
 * webhook is verified against the one centralized Auth Token. With it on, an org's own row is
 * authoritative for that org (including when it says "off"), and an org with no row uses the
 * centralized account.
 *
 * ── Three conditions, all required, before browser calling is offered ──
 * A configuration must be ENABLED, COMPLETE, and VERIFIED. For the centralized account, enabled and
 * verified follow from it being installed at all — see {@see isVerified()}. For a per-organization row
 * (only when that support is on), verified means Twilio itself accepted the credentials on an explicit
 * connection test: seven well-formed strings prove only that seven fields were filled in.
 *
 * ── This object never decides policy, only reports facts ──
 * {@see isUsable()} is the single question the rest of the system asks. Everything else here exists so
 * the settings screen can explain a "no": {@see missing()} names the empty fields, {@see $enabled}
 * separates "not configured" from "switched off", {@see isVerified()} separates "never checked" from
 * "checked and rejected", and {@see $source} says where this answer came from.
 *
 * ── isUsable() is not the same question as isComplete() ──
 * {@see isUsable()} asks "should this workspace OFFER browser calling?" and is deliberately strict.
 * {@see isComplete()} asks "do we hold the credentials needed to carry a call that is already in
 * flight?" and is deliberately not. The Twilio webhook path reads the second: a credential edited or
 * re-tested while a call is ringing must not silently hang up on the person on the line.
 *
 * ── The two secrets ──
 * `authToken` and `apiSecret` are the only values here that can place a call or forge a webhook
 * signature. They exist in memory for exactly two purposes — signing a browser Access Token, and
 * validating an inbound Twilio signature — and they are never serialized, never logged, and never
 * returned by any endpoint. Everything else on this object is an identifier Twilio itself displays.
 */
final class TwilioConfig
{
    /** This configuration came from an organization's own settings row (per-org support only). */
    public const SOURCE_ORGANIZATION = 'organization';

    /** Zerem's centralized ViteSEO Twilio account: the server-side TWILIO_* environment values. */
    public const SOURCE_DEPLOYMENT = 'deployment';

    /** Nothing is configured anywhere. The ordinary state of a manual-only CRM. */
    public const SOURCE_NONE = 'none';

    /**
     * The one stored verdict that counts as verification: Twilio accepted the credentials on an
     * explicit connection test. Mirrors {@see CallProviderSettingService::TEST_OK} rather than
     * importing it, so the gate cannot be widened by editing a status code somewhere else.
     */
    public const VERIFIED_STATUS = 'ok';

    /**
     * Every credential a browser call needs. All of them are required: a partial configuration fails
     * at the carrier minutes into a BD's workflow, which is far worse than an honest "not configured"
     * before the button is ever shown.
     *
     * @var list<string>
     */
    public const REQUIRED_FIELDS = [
        'account_sid',
        'auth_token',
        'api_key',
        'api_secret',
        'twiml_app_sid',
        'caller_id',
        'webhook_base_url',
    ];

    private function __construct(
        public readonly string $source,
        public readonly bool $enabled,
        public readonly ?string $accountSid,
        public readonly ?string $authToken,
        public readonly ?string $apiKey,
        public readonly ?string $apiSecret,
        public readonly ?string $twimlAppSid,
        public readonly ?string $callerId,
        public readonly ?string $webhookBaseUrl,
        public readonly int $tokenTtl,
        public readonly int $dialTimeout,
        /** The outcome of the last explicit connection test; null when never run. */
        public readonly ?string $lastTestStatus = null,
    ) {}

    /**
     * The configuration in force for an organization's calls.
     *
     * With per-organization accounts off (the default — see the class docblock) this is ALWAYS the
     * centralized ViteSEO account, whatever org is asked about and whatever rows exist. The org
     * argument is kept so every call site asks the same question in either mode.
     *
     * When per-org support is on, the org row is looked up by an EXPLICIT `org_id` with the tenant
     * scope lifted, rather than by relying on the ambient scope. That is not a bypass — it is stricter.
     * This method is called from an authenticated request (the caller passes `CurrentOrg::id()`) and
     * from the Twilio webhook (no user; the org comes from the correlated call row), and reading the
     * org from the argument means the answer can never depend on which of those the caller is.
     */
    public static function forOrg(?int $orgId): self
    {
        if ($orgId === null || ! self::organizationCredentialsEnabled()) {
            return self::fromDeployment();
        }

        $row = CallProviderSetting::query()
            ->withoutGlobalScope('org')
            ->where('org_id', $orgId)
            ->where('provider', TwilioCallProvider::NAME)
            ->first();

        return $row === null ? self::fromDeployment() : self::fromSettings($row);
    }

    /**
     * Whether this deployment has explicitly switched on per-organization Twilio accounts. Off by
     * default: Zerem runs one centralized ViteSEO account.
     */
    public static function organizationCredentialsEnabled(): bool
    {
        return (bool) Config::get('calling.organization_credentials', false);
    }

    /**
     * Zerem's centralized ViteSEO Twilio account — the server-side TWILIO_* values. Presence is the
     * switch: there is no separate env flag for the credentials themselves (CALL_PROVIDER chooses the
     * transport; see CallProviderRegistry).
     */
    public static function fromDeployment(): self
    {
        $config = new self(
            source: self::SOURCE_DEPLOYMENT,
            enabled: true,
            accountSid: self::clean(Config::get('twilio.account_sid')),
            authToken: self::clean(Config::get('twilio.auth_token')),
            apiKey: self::clean(Config::get('twilio.api_key')),
            apiSecret: self::clean(Config::get('twilio.api_secret')),
            twimlAppSid: self::clean(Config::get('twilio.twiml_app_sid')),
            callerId: self::clean(Config::get('twilio.caller_id')),
            webhookBaseUrl: self::clean(Config::get('twilio.webhook_base_url')),
            tokenTtl: self::tokenTtl(),
            dialTimeout: self::dialTimeout(),
        );

        // Nothing in the environment either. Reported as its own source so the settings screen can
        // say "not configured anywhere" rather than "the centralized account is incomplete" — and with
        // `enabled` FALSE, because nothing exists to have been switched on. A fresh install that
        // reported `enabled: true` made the settings card render an amber "Not running" warning where
        // the honest answer is the neutral "Manual calling".
        return $config->isComplete() ? $config : $config->withSource(self::SOURCE_NONE, enabled: false);
    }

    /**
     * An organization's stored configuration (per-org support). Authoritative for that org, when that
     * support is on, even when incomplete or switched off. Also what the settings screen's connection
     * test reads for an org that has saved a row.
     */
    public static function fromSettings(CallProviderSetting $row): self
    {
        return new self(
            source: self::SOURCE_ORGANIZATION,
            enabled: (bool) $row->enabled,
            accountSid: self::clean($row->account_sid),
            authToken: self::clean($row->auth_token),
            apiKey: self::clean($row->api_key),
            apiSecret: self::clean($row->api_secret),
            twimlAppSid: self::clean($row->twiml_app_sid),
            callerId: self::clean($row->caller_id),
            // An org that has not been given its own public callback origin inherits the deployment's,
            // which is the normal single-deployment case: the tunnel or domain Twilio can reach is a
            // property of where the CRM is hosted, not of who is using it.
            webhookBaseUrl: self::clean($row->webhook_base_url) ?? self::clean(Config::get('twilio.webhook_base_url')),
            tokenTtl: self::tokenTtl(),
            dialTimeout: self::dialTimeout(),
            lastTestStatus: $row->last_test_status,
        );
    }

    /** Every required credential is present. Says nothing about whether the org switched it on. */
    public function isComplete(): bool
    {
        return $this->missing() === [];
    }

    /**
     * Whether these credentials count as accepted by Twilio.
     *
     * THE CENTRALIZED ACCOUNT is verified by being installed. Its values are put on the server by
     * whoever operates it — they are not editable from any screen and there is no row to record a
     * verdict on — so requiring a test would make Zerem's own account permanently unusable. Presence
     * is the switch, exactly as it always was.
     *
     * A PER-ORGANIZATION row (only when that support is on) counts as verified only on the stored
     * verdict of an explicit connection test. Seven well-formed strings are not evidence that an
     * account exists: the fake-credential verification run filled every field with syntactically
     * perfect values that Twilio rejected on sight.
     *
     * This is NOT a claim that the credentials still work. Twilio keys can be revoked a second after a
     * passing test, which is why every runtime path retains its own graceful failure handling: the
     * capability gate decides what to OFFER, never what to assume.
     */
    public function isVerified(): bool
    {
        return $this->source === self::SOURCE_ORGANIZATION
            ? $this->lastTestStatus === self::VERIFIED_STATUS
            : $this->source === self::SOURCE_DEPLOYMENT;
    }

    /**
     * The one question the CRM asks before OFFERING browser calling: switched on, complete, AND
     * verified. Anything else means the free manual transport, with no error and no broken panel.
     *
     * Deliberately stricter than {@see isComplete()}. See the class docblock for why the webhook path
     * reads that one instead — a call already in flight must not be dropped because someone opened the
     * settings screen.
     */
    public function isUsable(): bool
    {
        return $this->enabled && $this->isComplete() && $this->isVerified();
    }

    /**
     * Which required fields are still empty, for the settings screen to name. Field KEYS only — never
     * values, and never a hint about the values that are present.
     *
     * @return list<string>
     */
    public function missing(): array
    {
        $present = [
            'account_sid' => $this->accountSid,
            'auth_token' => $this->authToken,
            'api_key' => $this->apiKey,
            'api_secret' => $this->apiSecret,
            'twiml_app_sid' => $this->twimlAppSid,
            'caller_id' => $this->callerId,
            'webhook_base_url' => $this->webhookBaseUrl,
        ];

        return array_values(array_filter(
            self::REQUIRED_FIELDS,
            static fn (string $field): bool => blank($present[$field]),
        ));
    }

    /** The `<Dial action=…>` / TwiML App callback URL for a path on this deployment. */
    public function callbackUrl(string $path): string
    {
        return rtrim((string) $this->webhookBaseUrl, '/').'/'.ltrim($path, '/');
    }

    private function withSource(string $source, ?bool $enabled = null): self
    {
        return new self(
            source: $source,
            enabled: $enabled ?? $this->enabled,
            accountSid: $this->accountSid,
            authToken: $this->authToken,
            apiKey: $this->apiKey,
            apiSecret: $this->apiSecret,
            twimlAppSid: $this->twimlAppSid,
            callerId: $this->callerId,
            webhookBaseUrl: $this->webhookBaseUrl,
            tokenTtl: $this->tokenTtl,
            dialTimeout: $this->dialTimeout,
            lastTestStatus: $this->lastTestStatus,
        );
    }

    /** Blank-to-null, so `''` from an environment file and a NULL column mean the same thing. */
    private static function clean(mixed $value): ?string
    {
        $value = is_string($value) ? trim($value) : $value;

        return blank($value) ? null : (string) $value;
    }

    /**
     * Token lifetime and ring timeout stay deployment-level rather than per-org: they are operational
     * tuning, not credentials, and no client admin has a reason to change them. Floors match the
     * documented intent in config/twilio.php.
     */
    private static function tokenTtl(): int
    {
        return max(60, (int) Config::get('twilio.token_ttl', 600));
    }

    private static function dialTimeout(): int
    {
        return max(5, (int) Config::get('twilio.dial_timeout', 30));
    }
}
