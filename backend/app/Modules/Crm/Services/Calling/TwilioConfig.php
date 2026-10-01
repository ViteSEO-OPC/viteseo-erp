<?php

declare(strict_types=1);

namespace App\Modules\Crm\Services\Calling;

use App\Models\Org;
use App\Modules\Crm\Models\CallProviderSetting;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;

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
 * ── Per-organization accounts: off by default, fail-closed when on ──
 * `crm_call_provider_settings` (Settings → Integrations → Calling) holds a client organization's OWN
 * Twilio account. Stored rows are consulted ONLY when a deployment explicitly sets
 * `calling.organization_credentials` (CALLING_ORGANIZATION_CREDENTIALS=true). With it off — the default,
 * and Zerem's configuration — {@see forOrg()} always answers with the centralized account, so nobody
 * can redirect Zerem's calls to another account from a settings screen, and every webhook is verified
 * against the one centralized Auth Token.
 *
 * With it on, every organization is resolved on its own, and the resolution FAILS CLOSED:
 *   - the ONE organization named by `calling.central_org_id` (CALLING_CENTRAL_ORG_ID — ViteSEO's own)
 *     is carried on the centralized account, and any row it holds is ignored;
 *   - every other organization is carried ONLY on its own row — authoritative even when it says "off"
 *     or is incomplete — and an organization with no row has no credentials at all (manual calling).
 *     A client is never carried on ViteSEO's account, or on anyone else's, by default;
 *   - with no central organization named, no organization uses the centralized account.
 *
 * The central organization is named in configuration because nothing in the schema designates it, and
 * "whichever organization happens to have the lowest id" is not a rule a credential decision may rest on.
 *
 * That last bullet is fail-closed but SILENT — ViteSEO's own organization would simply stop being
 * recognised as central and lose browser calling — so {@see misconfiguration()} detects the condition
 * and {@see reportMisconfiguration()} logs it as critical on the resolution path. Neither corrects it:
 * there is no safe guess at which organization is ViteSEO's. `php artisan calling:doctor` reports the
 * same verdict on demand.
 *
 * ── ORGANIZATION STATE IS NOT A CREDENTIAL QUESTION, AND IS DELIBERATELY NOT ASKED HERE ──
 * Nothing below consults `orgs.is_active`, a subscription, or any other notion of whether an
 * organization is in good standing. That is a product decision, not an omission.
 *
 * Suspension is already enforced, once, where every other capability is: `EnsureSubscriptionActive`
 * answers 402 before a request reaches any CRM route, so a lapsed organization cannot open its Calling
 * settings, cannot ask for a browser token, and cannot start a call. Re-deciding it here would be a
 * second, differently-shaped copy of an access rule — the kind that drifts from the first and then
 * disagrees with it.
 *
 * The Twilio WEBHOOKS are outside those gates by necessity (Twilio has no user, no token and no
 * tenant), and they stay outside deliberately. A call that was authorized when it was placed must be
 * allowed to finish: dropping the `<Dial>` action callback because a subscription lapsed mid-call would
 * hang up on the person on the line and lose the CRM's record of a call that really happened. The
 * organization is billed by Twilio for that minute either way.
 *
 * `orgs.is_active` is written at onboarding and read nowhere on this path — see the client setup guide.
 * Enforcing it would be a platform-wide change, and calling is not the place to introduce one.
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
     * Per-organization mode is on, but `CALLING_CENTRAL_ORG_ID` is not set at all. Nothing designates
     * ViteSEO's organization, so NO organization uses the centralized account.
     */
    public const MISCONFIG_CENTRAL_ORG_UNSET = 'central_org_unset';

    /** It is set to something that is not a positive integer — a typo, a name, a zero. Names nobody. */
    public const MISCONFIG_CENTRAL_ORG_INVALID = 'central_org_invalid';

    /** It is a well-formed id, but no organization has it. Names nobody. */
    public const MISCONFIG_CENTRAL_ORG_MISSING = 'central_org_missing';

    /**
     * The one sentence each misconfiguration is reported with. Kept beside the codes so the log line
     * and `calling:doctor` can never describe the same condition two different ways.
     *
     * @var array<string, string>
     */
    public const MISCONFIG_MESSAGES = [
        self::MISCONFIG_CENTRAL_ORG_UNSET => 'Per-organization Twilio accounts are switched on, but no central organization is named: CALLING_CENTRAL_ORG_ID is not set.',
        self::MISCONFIG_CENTRAL_ORG_INVALID => 'Per-organization Twilio accounts are switched on, but CALLING_CENTRAL_ORG_ID is not a positive integer and names no organization.',
        self::MISCONFIG_CENTRAL_ORG_MISSING => 'Per-organization Twilio accounts are switched on, but CALLING_CENTRAL_ORG_ID names an organization that does not exist.',
    ];

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

    /**
     * Misconfigurations already reported in this process, keyed by reason + the offending value.
     *
     * @var array<string, true>
     */
    private static array $reportedMisconfigurations = [];

    /**
     * The verdict for a given configuration, remembered for this process.
     *
     * {@see misconfiguration()} has to ask the database whether the named organization exists, and
     * {@see forOrg()} is the hot path — every capability check, every browser token, every webhook. One
     * `exists()` per credential resolution would be a query added to the busiest path in calling, in
     * exactly the mode this support exists for. It is remembered per configuration VALUE, so correcting
     * the setting is re-evaluated rather than masked.
     *
     * Only a diagnostic is cached. No resolution in {@see forOrg()} consults this, so a stale entry can
     * change what is REPORTED and never which account a call is carried on.
     *
     * @var array<string, string|null>
     */
    private static array $misconfigurationCache = [];

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
     * The configuration in force for an organization's calls — the ONE credential decision in Zerem.
     * Tokens, dialing, caller ID and webhook signatures all ask this, and nothing else.
     *
     * With per-organization accounts off (the default — see the class docblock) this is ALWAYS the
     * centralized ViteSEO account, whatever org is asked about and whatever rows exist. The org
     * argument is kept so every call site asks the same question in either mode.
     *
     * With them on, it fails closed, in this order:
     *   1. no organization          → nothing: there is no tenant whose account could be meant;
     *   2. the central organization → the centralized account; its own rows are ignored;
     *   3. any other organization   → its own row, even when switched off or incomplete, and with no row,
     *                                  nothing. Never the centralized account.
     *
     * The org row is looked up by an EXPLICIT `org_id` with the tenant scope lifted, rather than by
     * relying on the ambient scope. That is not a bypass — it is stricter. This method is called from an
     * authenticated request (the caller passes `CurrentOrg::id()`) and from the Twilio webhook (no user;
     * the org comes from the correlated call row), and reading the org from the argument means the
     * answer can never depend on which of those the caller is.
     */
    public static function forOrg(?int $orgId): self
    {
        if (! self::organizationCredentialsEnabled()) {
            return self::fromDeployment();
        }

        // Per-organization mode is on. Say so loudly if the central organization it depends on is not
        // actually named — the resolution below stays fail-closed either way, but silently is exactly
        // how ViteSEO's own calling would disappear. See reportMisconfiguration().
        self::reportMisconfiguration();

        return self::decide($orgId, fn (): ?CallProviderSetting => CallProviderSetting::query()
            ->withoutGlobalScope('org')
            ->where('org_id', $orgId)
            ->where('provider', TwilioCallProvider::NAME)
            ->first());
    }

    /**
     * The configuration in force for an organization whose stored row the CALLER already holds.
     *
     * Identical to {@see forOrg()} in every respect except where the row comes from, because it IS
     * forOrg() — both hand the same decision procedure a way to obtain the row, and neither has a
     * policy of its own. That matters more than the query it saves: a second copy of "central gets the
     * deployment account, everyone else gets their own row or nothing" is a second place for the rule
     * to drift, and this is the rule that keeps one tenant off another tenant's account.
     *
     * For a caller that has already loaded every organization's row in one query — the super-admin
     * overview — where asking per organization is one query per organization. Pass NULL for an
     * organization that genuinely has no row; that is the fail-closed answer, not a missing lookup.
     */
    public static function forOrgWithRow(?int $orgId, ?CallProviderSetting $row): self
    {
        if (! self::organizationCredentialsEnabled()) {
            return self::fromDeployment();
        }

        self::reportMisconfiguration();

        return self::decide($orgId, static fn (): ?CallProviderSetting => $row);
    }

    /**
     * The one resolution procedure, shared by {@see forOrg()} and {@see forOrgWithRow()}.
     *
     * Called only after per-organization mode is known to be on. The row is taken lazily so the
     * central organization — whose own rows are ignored — never causes a lookup at all.
     *
     * @param  callable(): ?CallProviderSetting  $row
     */
    private static function decide(?int $orgId, callable $row): self
    {
        if ($orgId === null) {
            return self::none();
        }

        if (self::isCentralOrg($orgId)) {
            return self::fromDeployment();
        }

        $stored = $row();

        return $stored === null ? self::none() : self::fromSettings($stored);
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
     * The organization carried on the centralized ViteSEO account when per-organization accounts are on,
     * or null when none is named — in which case NO organization uses that account.
     *
     * Named in configuration (CALLING_CENTRAL_ORG_ID), next to the centralized credentials it selects,
     * because nothing in the schema designates ViteSEO's organization. Anything that is not a positive
     * integer names nobody.
     */
    public static function centralOrgId(): ?int
    {
        $id = filter_var(Config::get('calling.central_org_id'), FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1],
        ]);

        return $id === false ? null : $id;
    }

    /** Whether this is the organization named as the centralized account's owner. */
    public static function isCentralOrg(?int $orgId): bool
    {
        $central = self::centralOrgId();

        return $orgId !== null && $central !== null && $orgId === $central;
    }

    /**
     * ── The misconfiguration guard for per-organization mode ──
     *
     * Per-organization mode rests entirely on `CALLING_CENTRAL_ORG_ID` naming a real organization. The
     * resolution in {@see forOrg()} is fail-closed whatever that value is, so a wrong one can never
     * lend one tenant another's account — but it is fail-closed in a way nobody notices: ViteSEO's own
     * organization stops being recognised as central, is resolved as if it were a client, and loses
     * browser calling. Nothing errors, nothing 500s, and the settings card even offers ViteSEO an
     * editable credential form for an account it does not own.
     *
     * So the condition is DETECTED and reported rather than corrected. Correcting it is not on the
     * table: there is no safe guess at which organization is ViteSEO's — "the lowest id" is exactly the
     * rule the product decision forbade — and falling back to one would be the silent cross-tenant
     * credential leak this whole design exists to prevent.
     *
     * MODE OFF IS ALWAYS SOUND. With `calling.organization_credentials` false, every organization uses
     * the centralized account and `central_org_id` selects nothing, so an unset or nonsense value there
     * is not a misconfiguration — it is the default state of every deployment, this one included.
     *
     * @return string|null one of the MISCONFIG_* codes, or null when the configuration is sound
     */
    public static function misconfiguration(): ?string
    {
        if (! self::organizationCredentialsEnabled()) {
            return null;
        }

        $raw = Config::get('calling.central_org_id');
        $key = json_encode($raw);

        if (array_key_exists($key, self::$misconfigurationCache)) {
            return self::$misconfigurationCache[$key];
        }

        return self::$misconfigurationCache[$key] = self::evaluate($raw);
    }

    /** The uncached verdict — the only place that asks the database whether the organization exists. */
    private static function evaluate(mixed $raw): ?string
    {
        if (blank($raw)) {
            return self::MISCONFIG_CENTRAL_ORG_UNSET;
        }

        $central = self::centralOrgId();

        if ($central === null) {
            return self::MISCONFIG_CENTRAL_ORG_INVALID;
        }

        return Org::query()->whereKey($central)->exists()
            ? null
            : self::MISCONFIG_CENTRAL_ORG_MISSING;
    }

    /**
     * Log a per-organization-mode misconfiguration, at most ONCE per process.
     *
     * CRITICAL, because the consequence is that ViteSEO's own organization quietly loses browser
     * calling — and DELIBERATELY NOT FATAL. Throwing here would take down every CRM request, the Twilio
     * webhooks included, over a credential-routing setting: a deployment that mis-set one environment
     * variable would lose the whole CRM rather than one optional capability, and calls already in
     * flight would be dropped. Refusing to offer browser calling is the proportionate failure, and that
     * is what {@see forOrg()} already does.
     *
     * Once per process because {@see forOrg()} is the hot path — every capability check, every token,
     * every webhook — and a line per resolution would bury the diagnostic in its own repetition. The
     * key includes the offending value, so correcting it and getting it wrong a second way still
     * reports. Long-running workers and tests can reset it with {@see flushMisconfigurationReports()}.
     */
    public static function reportMisconfiguration(): void
    {
        $reason = self::misconfiguration();

        if ($reason === null) {
            return;
        }

        $key = $reason.'|'.json_encode(Config::get('calling.central_org_id'));

        if (isset(self::$reportedMisconfigurations[$key])) {
            return;
        }

        self::$reportedMisconfigurations[$key] = true;

        Log::critical(self::MISCONFIG_MESSAGES[$reason], [
            'reason' => $reason,
            // The configured value, so the diagnostic names what is actually wrong. It is an
            // organization id or a typo — never a credential.
            'configured_central_org_id' => Config::get('calling.central_org_id'),
            'setting' => 'CALLING_CENTRAL_ORG_ID',
            'mode' => 'organization_credentials',
            'consequence' => 'No organization is carried on the centralized ViteSEO Twilio account; '
                .'every organization is resolved against its own stored credentials only.',
            'remedy' => 'Set CALLING_CENTRAL_ORG_ID to the id of ViteSEO\'s own organization, or set '
                .'CALLING_ORGANIZATION_CREDENTIALS=false to return to the centralized default. '
                .'Run `php artisan calling:doctor` to check.',
        ]);
    }

    /**
     * Forget both the remembered verdicts and which of them have already been reported.
     *
     * For a long-running worker whose configuration or organizations changed under it, and for tests,
     * which change `calling.central_org_id` between cases inside one process and must each see their
     * own report. `calling:doctor` calls it too, because an operator running it is explicitly asking
     * for a fresh answer.
     */
    public static function flushMisconfigurationReports(): void
    {
        self::$reportedMisconfigurations = [];
        self::$misconfigurationCache = [];
    }

    /**
     * Whether this organization's calling is decided on the server rather than on its settings screen:
     * every organization in centralized mode, and the central organization when per-organization
     * accounts are on. The settings endpoints are read-only for such an organization.
     */
    public static function isCentrallyManaged(?int $orgId): bool
    {
        return ! self::organizationCredentialsEnabled() || self::isCentralOrg($orgId);
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
     * test reads — and the ONLY thing it reads — for an org that has saved a row.
     *
     * Every credential comes from the one row, so the Account SID a token is issued for is always the
     * account the API key, the Auth Token and the TwiML App were entered for — never a mix of two sources.
     */
    public static function fromSettings(CallProviderSetting $row): self
    {
        return new self(
            source: self::SOURCE_ORGANIZATION,
            enabled: (bool) $row->enabled,
            accountSid: self::clean($row->account_sid),
            // Decrypted here, server-side, and a secret that cannot be decrypted reads as ABSENT (see
            // CallProviderSetting::secret()): the configuration is then incomplete — unusable, never a
            // 500, and never a reason to reach for another account.
            authToken: self::clean($row->secret('auth_token')),
            apiKey: self::clean($row->api_key),
            apiSecret: self::clean($row->secret('api_secret')),
            twimlAppSid: self::clean($row->twiml_app_sid),
            callerId: self::clean($row->caller_id),
            // ALWAYS the deployment's public origin — never a value from the row. Where Twilio calls back
            // is a property of where Zerem is hosted, not of who is using it, and a tenant-editable value
            // here would let an organization move its call callbacks, and the URL their signatures are
            // checked against, to a host of its choosing. A value stored before this rule is not read.
            webhookBaseUrl: self::clean(Config::get('twilio.webhook_base_url')),
            tokenTtl: self::tokenTtl(),
            dialTimeout: self::dialTimeout(),
            lastTestStatus: $row->last_test_status,
        );
    }

    /**
     * No credentials at all: the fail-closed answer for an organization with no account of its own.
     * Deliberately built from nothing — not from the deployment's values — so no field of the
     * centralized account can ever reach an organization that is not entitled to it.
     */
    private static function none(): self
    {
        return new self(
            source: self::SOURCE_NONE,
            enabled: false,
            accountSid: null,
            authToken: null,
            apiKey: null,
            apiSecret: null,
            twimlAppSid: null,
            callerId: null,
            webhookBaseUrl: null,
            tokenTtl: self::tokenTtl(),
            dialTimeout: self::dialTimeout(),
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
