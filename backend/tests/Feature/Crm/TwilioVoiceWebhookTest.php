<?php

declare(strict_types=1);

namespace Tests\Feature\Crm;

use App\Models\User;
use App\Modules\Crm\Enums\CallOutcome;
use App\Modules\Crm\Enums\CallStatus;
use App\Modules\Crm\Models\Activity;
use App\Modules\Crm\Models\Call;
use App\Modules\Crm\Models\Lead;
use App\Modules\Crm\Services\Calling\CallCorrelation;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Crm\Concerns\ConfiguresCentralTwilio;
use Tests\TestCase;
use Twilio\Security\RequestValidator;

/**
 * The CRM's only unauthenticated surface, and the two independent checks that make it safe.
 *
 * A Twilio webhook has no user, no token and no tenant, so it passes none of the five gates every
 * other CRM route sits behind. In their place:
 *
 *   1. the official SDK's signature validation proves the request is Twilio's;
 *   2. an HMAC correlation token — minted earlier by an authenticated, policy-checked request —
 *      names the ONE crm_calls row the request may touch.
 *
 * Neither is sufficient alone, and these tests pin that: a perfectly-signed request bearing a forged
 * or foreign correlation must change nothing.
 *
 * COMPLETION IS TESTED IN THE SHAPE TWILIO ACTUALLY SENDS. The `<Dial action>` callback is POSTed to
 * the action URL the voice webhook returned, carrying only Twilio's standard parameters — Twilio does
 * not re-send what the browser passed to `Device.connect()`. So every completion below is driven
 * through {@see dial()} + {@see completeAsTwilio()}, never by posting a correlation into the body.
 */
class TwilioVoiceWebhookTest extends TestCase
{
    use ConfiguresCentralTwilio;
    use RefreshDatabase;

    private const AUTH_TOKEN = 'test-auth-token';

    private const BASE_URL = 'https://example.test';

    private const ACCOUNT_SID = 'ACtest00000000000000000000000000';

    private const COMPLETED_PATH = '/api/v1/twilio/voice/completed';

    private User $bd;

    private Lead $lead;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);

        // Zerem's ONE centralized ViteSEO Twilio account, exactly as the server's TWILIO_* provides it.
        $this->configureCentralTwilio([
            'account_sid' => self::ACCOUNT_SID,
            'auth_token' => self::AUTH_TOKEN,
            'webhook_base_url' => self::BASE_URL,
        ]);

        $this->bd = User::factory()->create(['status' => 'active']);
        $this->bd->assignRole('crm_bd');
        $this->lead = Lead::factory()->create([
            'assigned_to' => $this->bd->id,
            'phone' => '0917 847 5233',
        ]);
    }

    /** Open a real Twilio-mode call the way the authenticated UI does. */
    private function openBrowserCall(): Call
    {
        Sanctum::actingAs($this->bd);
        $this->postJson("/api/v1/crm/leads/{$this->lead->id}/calls", ['browser' => true])->assertCreated();
        app('auth')->forgetGuards();

        return Call::query()->withoutGlobalScope('org')->latest('id')->firstOrFail();
    }

    /**
     * POST a webhook signed exactly as Twilio signs one — same canonical string, same HMAC, via the
     * SDK's own validator's counterpart. Nothing about the signing is reimplemented here. `$path` may
     * carry a query string; Twilio signs the full URL it called, query included.
     *
     * @param  array<string, mixed>  $params
     */
    private function postSigned(string $path, array $params, ?string $signature = null, string $authToken = self::AUTH_TOKEN)
    {
        $url = self::BASE_URL.$path;
        $signature ??= (new RequestValidator($authToken))->computeSignature($url, $params);

        return $this->post($path, $params, ['X-Twilio-Signature' => $signature]);
    }

    /**
     * Run the voice webhook exactly as Twilio does after `Device.connect()`, and return the
     * `<Dial action>` it answered with — as a path plus query, ready to be called back.
     */
    private function dial(Call $call, string $callSid): string
    {
        $response = $this->postSigned('/api/v1/twilio/voice', [
            CallCorrelation::PARAM => CallCorrelation::issue($call),
            'CallSid' => $callSid,
        ])->assertOk();

        $action = (string) simplexml_load_string((string) $response->getContent())->Dial['action'];
        $this->assertStringStartsWith(self::BASE_URL.self::COMPLETED_PATH.'?', $action);

        return substr($action, strlen(self::BASE_URL));
    }

    /**
     * The `<Dial action>` callback exactly as Twilio sends it: POSTed to the action URL the voice
     * webhook returned, with Twilio's standard parameters for the parent (browser) call plus the
     * dialed leg's verdict — and NOTHING the browser passed to `connect()`, so no correlation in the
     * body.
     *
     * @param  array<string, string>  $dial  DialCallStatus / DialCallDuration
     */
    private function completeAsTwilio(string $actionPath, string $callSid, array $dial, string $authToken = self::AUTH_TOKEN)
    {
        return $this->postSigned($actionPath, array_merge([
            'AccountSid' => self::ACCOUNT_SID,
            'ApiVersion' => '2010-04-01',
            'CallSid' => $callSid,
            'CallStatus' => 'in-progress',
            'Direction' => 'inbound',
            'From' => 'client:crm-user',
            'DialCallSid' => 'CAffffffffffffffffffffffffffffffff',
        ], $dial), authToken: $authToken);
    }

    // ── Signature ──────────────────────────────────────────────────────────────────────────────

    public function test_a_webhook_without_a_signature_is_refused(): void
    {
        $call = $this->openBrowserCall();

        $this->post('/api/v1/twilio/voice', [
            CallCorrelation::PARAM => CallCorrelation::issue($call),
            'CallSid' => 'CA00000000000000000000000000000001',
        ])->assertForbidden();
    }

    public function test_a_webhook_with_an_invalid_signature_is_refused(): void
    {
        $call = $this->openBrowserCall();

        $this->postSigned('/api/v1/twilio/voice', [
            CallCorrelation::PARAM => CallCorrelation::issue($call),
            'CallSid' => 'CA00000000000000000000000000000001',
        ], signature: 'obviously-not-a-valid-signature')->assertForbidden();
    }

    public function test_a_tampered_body_invalidates_the_signature(): void
    {
        $call = $this->openBrowserCall();
        $params = [
            CallCorrelation::PARAM => CallCorrelation::issue($call),
            'CallSid' => 'CA00000000000000000000000000000001',
        ];
        $signature = (new RequestValidator(self::AUTH_TOKEN))
            ->computeSignature(self::BASE_URL.'/api/v1/twilio/voice', $params);

        // Same signature, different body — the canonical string no longer matches.
        $params['CallSid'] = 'CA00000000000000000000000000000009';

        $this->post('/api/v1/twilio/voice', $params, ['X-Twilio-Signature' => $signature])
            ->assertForbidden();
    }

    public function test_a_tampered_action_url_invalidates_the_signature(): void
    {
        // The completion correlation travels in the QUERY STRING, which Twilio signs as part of the
        // URL. Swapping it for another value after signing must fail the signature, not just the HMAC.
        $call = $this->openBrowserCall();
        $action = $this->dial($call, 'CA00000000000000000000000000000001');
        $params = ['CallSid' => 'CA00000000000000000000000000000001', 'DialCallStatus' => 'completed'];
        $signature = (new RequestValidator(self::AUTH_TOKEN))->computeSignature(self::BASE_URL.$action, $params);

        $this->post(self::COMPLETED_PATH.'?'.CallCorrelation::PARAM.'=something-else', $params, [
            'X-Twilio-Signature' => $signature,
        ])->assertForbidden();

        $this->assertSame(CallStatus::IN_PROGRESS, $call->fresh()->status);
    }

    /**
     * POST a genuinely form-encoded body, the way Twilio does — parsed input AND raw content, as a real
     * PHP request has both — signed over `$signedFor` (default: exactly what is sent).
     *
     * @param  array<string, string>  $params
     * @param  array<string, string>|null  $signedFor
     */
    private function postRawSigned(string $path, array $params, ?array $signedFor = null)
    {
        $signature = (new RequestValidator(self::AUTH_TOKEN))
            ->computeSignature(self::BASE_URL.$path, $signedFor ?? $params);

        return $this->call('POST', $path, $params, [], [], [
            'CONTENT_TYPE' => 'application/x-www-form-urlencoded',
            'HTTP_X_TWILIO_SIGNATURE' => $signature,
        ], http_build_query($params));
    }

    public function test_the_signature_is_checked_against_the_body_twilio_actually_sent(): void
    {
        // D6. Laravel's global TrimStrings / ConvertEmptyStringsToNull rewrite these before any route
        // middleware runs; checked against the rewritten input, this GENUINE request would be refused.
        $call = $this->openBrowserCall();

        $response = $this->postRawSigned('/api/v1/twilio/voice', [
            CallCorrelation::PARAM => CallCorrelation::issue($call),
            'CallSid' => 'CA00000000000000000000000000000033',
            'CallerName' => '  Juan Dela Cruz ',
            'ForwardedFrom' => '',
        ])->assertOk();

        $this->assertStringContainsString('<Dial', (string) $response->getContent());
        $this->assertSame('CA00000000000000000000000000000033', $call->fresh()->external_call_id);
    }

    public function test_a_body_that_differs_from_what_was_signed_is_refused_even_by_whitespace(): void
    {
        // Signed over "Juan", sent as " Juan ". Trimming would have made them look equal; the raw body
        // Twilio signed is what counts, so this is not the request that was signed.
        $call = $this->openBrowserCall();
        $sent = [
            CallCorrelation::PARAM => CallCorrelation::issue($call),
            'CallSid' => 'CA00000000000000000000000000000034',
            'CallerName' => ' Juan ',
        ];

        $this->postRawSigned('/api/v1/twilio/voice', $sent, signedFor: array_merge($sent, ['CallerName' => 'Juan']))
            ->assertForbidden();

        $this->assertNull($call->fresh()->external_call_id);
    }

    public function test_webhooks_are_unavailable_when_twilio_is_not_configured(): void
    {
        // Every free deployment. The endpoint exists but is inert — it cannot verify anything, so it
        // accepts nothing.
        Config::set('twilio.auth_token', null);

        $this->post('/api/v1/twilio/voice', [])->assertStatus(503);
    }

    public function test_stored_organization_credentials_cannot_sign_a_webhook(): void
    {
        // Zerem verifies every webhook against its ONE centralized ViteSEO account. A per-organization
        // row — complete, enabled, "verified" — must not become an alternative signer: while
        // per-organization accounts are off it is ignored entirely.
        $this->storeOrganizationTwilioSettings(['auth_token' => 'org-row-auth-token']);
        $call = $this->openBrowserCall();
        $params = [
            CallCorrelation::PARAM => CallCorrelation::issue($call),
            'CallSid' => 'CA00000000000000000000000000000032',
        ];

        $this->postSigned('/api/v1/twilio/voice', $params, authToken: 'org-row-auth-token')->assertForbidden();
        $this->assertNull($call->fresh()->external_call_id);

        // The centralized account's own signature is the one that is accepted.
        $this->postSigned('/api/v1/twilio/voice', $params)->assertOk();
        $this->assertSame('CA00000000000000000000000000000032', $call->fresh()->external_call_id);
    }

    // ── Correlation ────────────────────────────────────────────────────────────────────────────

    public function test_a_signed_webhook_dials_the_number_on_the_correlated_call(): void
    {
        $call = $this->openBrowserCall();

        $response = $this->postSigned('/api/v1/twilio/voice', [
            CallCorrelation::PARAM => CallCorrelation::issue($call),
            'CallSid' => 'CA00000000000000000000000000000001',
        ])->assertOk();

        $xml = $response->getContent();
        $this->assertStringContainsString('<Dial', (string) $xml);
        // The number dialed comes from OUR record, not from the request.
        $this->assertStringContainsString('+639178475233', (string) $xml);
        $this->assertStringContainsString('+639170000000', (string) $xml);

        // The provider's id for the browser leg is recorded.
        $this->assertSame(
            'CA00000000000000000000000000000001',
            $call->fresh()->external_call_id,
        );
    }

    public function test_the_dial_action_carries_a_completion_correlation_for_this_call_only(): void
    {
        $call = $this->openBrowserCall();
        $action = $this->dial($call, 'CA00000000000000000000000000000001');

        parse_str((string) parse_url($action, PHP_URL_QUERY), $query);
        $token = (string) ($query[CallCorrelation::PARAM] ?? '');

        // Resolves to THIS call, and only as a completion token: the long-lived token Twilio holds
        // for the length of the conversation cannot be replayed at the voice webhook to dial again.
        $this->assertSame($call->id, CallCorrelation::resolveCallId($token, CallCorrelation::PURPOSE_COMPLETE));
        $this->assertNull(CallCorrelation::resolveCallId($token, CallCorrelation::PURPOSE_DIAL));
    }

    public function test_the_dial_time_limit_never_exceeds_the_completion_window(): void
    {
        $call = $this->openBrowserCall();

        $response = $this->postSigned('/api/v1/twilio/voice', [
            CallCorrelation::PARAM => CallCorrelation::issue($call),
            'CallSid' => 'CA00000000000000000000000000000001',
        ])->assertOk();

        $timeLimit = (int) simplexml_load_string((string) $response->getContent())->Dial['timeLimit'];

        $this->assertSame(CallCorrelation::MAX_CALL_SECONDS, $timeLimit);
        $this->assertGreaterThan($timeLimit, CallCorrelation::completionTtl(30));
    }

    public function test_a_forged_correlation_dials_nothing(): void
    {
        $call = $this->openBrowserCall();

        // A correctly-signed Twilio request whose correlation is a bare call id — exactly what an
        // attacker would try if the token were merely an identifier.
        $response = $this->postSigned('/api/v1/twilio/voice', [
            CallCorrelation::PARAM => (string) $call->id,
            'CallSid' => 'CA00000000000000000000000000000002',
        ])->assertOk();

        $this->assertStringNotContainsString('<Dial', (string) $response->getContent());
        $this->assertNull($call->fresh()->external_call_id);
    }

    public function test_a_completion_token_cannot_dial(): void
    {
        $call = $this->openBrowserCall();
        $completion = CallCorrelation::issue($call, CallCorrelation::PURPOSE_COMPLETE, CallCorrelation::completionTtl(30));

        $response = $this->postSigned('/api/v1/twilio/voice', [
            CallCorrelation::PARAM => $completion,
            'CallSid' => 'CA00000000000000000000000000000002',
        ])->assertOk();

        $this->assertStringNotContainsString('<Dial', (string) $response->getContent());
        $this->assertNull($call->fresh()->external_call_id);
    }

    public function test_a_correlation_signed_for_another_call_cannot_be_retargeted(): void
    {
        $mine = $this->openBrowserCall();

        // Take a valid token for MY call and swap the id inside it. The HMAC covers the id, so the
        // edit invalidates it — this is the property that keeps one tenant out of another's calls.
        $token = CallCorrelation::issue($mine);
        $decoded = base64_decode(strtr($token, '-_', '+/'), true);
        $parts = explode('.', (string) $decoded);
        $parts[0] = (string) ($mine->id + 1);
        $forged = rtrim(strtr(base64_encode(implode('.', $parts)), '+/', '-_'), '=');

        $response = $this->postSigned('/api/v1/twilio/voice', [
            CallCorrelation::PARAM => $forged,
            'CallSid' => 'CA00000000000000000000000000000003',
        ])->assertOk();

        $this->assertStringNotContainsString('<Dial', (string) $response->getContent());
    }

    public function test_an_expired_correlation_is_refused(): void
    {
        $call = $this->openBrowserCall();
        $token = CallCorrelation::issue($call);

        $this->travel(20)->minutes();

        $this->assertNull(CallCorrelation::resolveCallId($token));
    }

    public function test_a_manual_call_cannot_be_driven_by_a_webhook(): void
    {
        // Provider webhooks may only touch provider calls. A manual record is the BD's to conclude.
        Sanctum::actingAs($this->bd);
        $this->postJson("/api/v1/crm/leads/{$this->lead->id}/calls")->assertCreated();
        app('auth')->forgetGuards();
        $manual = Call::query()->withoutGlobalScope('org')->latest('id')->firstOrFail();

        $response = $this->postSigned('/api/v1/twilio/voice', [
            CallCorrelation::PARAM => CallCorrelation::issue($manual),
            'CallSid' => 'CA00000000000000000000000000000004',
        ])->assertOk();

        $this->assertStringNotContainsString('<Dial', (string) $response->getContent());
        $this->assertSame(CallStatus::IN_PROGRESS, $manual->fresh()->status);
    }

    // ── Completion, in Twilio's real callback shape (D1) ───────────────────────────────────────

    public function test_a_completion_sent_exactly_as_twilio_sends_it_concludes_the_call(): void
    {
        // THE REGRESSION. Twilio calls the action URL back with its standard parameters only; the
        // body carries no correlation. The call must still conclude, with the carrier's verdict.
        $call = $this->openBrowserCall();
        $action = $this->dial($call, 'CA00000000000000000000000000000005');

        $this->completeAsTwilio($action, 'CA00000000000000000000000000000005', [
            'DialCallStatus' => 'completed',
            'DialCallDuration' => '137',
        ])->assertOk();

        $fresh = $call->fresh();
        $this->assertSame(CallStatus::COMPLETED, $fresh->status);
        $this->assertSame(CallOutcome::CONNECTED, $fresh->outcome);
        // The carrier's talk time, NOT the wall-clock between two API calls.
        $this->assertSame(137, $fresh->duration_seconds);
        $this->assertNotNull($fresh->ended_at);
    }

    public function test_the_bare_completion_url_without_a_correlation_changes_nothing(): void
    {
        // What the broken implementation told Twilio to call: no correlation anywhere. It must be a
        // harmless 200 no-op, never a guess at which call it was.
        $call = $this->openBrowserCall();
        $this->dial($call, 'CA00000000000000000000000000000005');

        $this->completeAsTwilio(self::COMPLETED_PATH, 'CA00000000000000000000000000000005', [
            'DialCallStatus' => 'completed',
            'DialCallDuration' => '137',
        ])->assertOk();

        $this->assertSame(CallStatus::IN_PROGRESS, $call->fresh()->status);
    }

    public function test_a_dial_token_cannot_complete_a_call(): void
    {
        $call = $this->openBrowserCall();
        $this->dial($call, 'CA00000000000000000000000000000006');

        $path = self::COMPLETED_PATH.'?'.http_build_query([CallCorrelation::PARAM => CallCorrelation::issue($call)]);

        $this->completeAsTwilio($path, 'CA00000000000000000000000000000006', [
            'DialCallStatus' => 'completed',
            'DialCallDuration' => '60',
        ])->assertOk();

        $this->assertSame(CallStatus::IN_PROGRESS, $call->fresh()->status);
    }

    public function test_a_completion_naming_a_different_twilio_call_changes_nothing(): void
    {
        // Two live calls. Call A's action URL arrives reporting call B's CallSid: the token names A,
        // the CallSid does not match what A dialed, so NEITHER call may be concluded by it.
        $a = $this->openBrowserCall();
        $actionA = $this->dial($a, 'CA0000000000000000000000000000000a');
        $b = $this->openBrowserCall();
        $this->dial($b, 'CA0000000000000000000000000000000b');

        $this->completeAsTwilio($actionA, 'CA0000000000000000000000000000000b', [
            'DialCallStatus' => 'completed',
            'DialCallDuration' => '60',
        ])->assertOk();

        $this->assertSame(CallStatus::IN_PROGRESS, $a->fresh()->status);
        $this->assertSame(CallStatus::IN_PROGRESS, $b->fresh()->status);
    }

    public function test_an_unanswered_call_is_not_recorded_as_connected(): void
    {
        foreach ([
            ['no-answer', null, CallOutcome::NO_ANSWER],
            ['busy', null, CallOutcome::BUSY],
            ['failed', null, CallOutcome::FAILED],
            ['canceled', null, CallOutcome::CANCELLED],
            // The case that matters most: Twilio says completed, but nobody spoke.
            ['completed', '0', CallOutcome::NO_ANSWER],
        ] as $i => [$status, $duration, $expected]) {
            $call = $this->openBrowserCall();
            $sid = 'CA'.str_pad((string) ($i + 100), 32, '0', STR_PAD_LEFT);
            $action = $this->dial($call, $sid);

            $this->completeAsTwilio($action, $sid, array_filter([
                'DialCallStatus' => $status,
                'DialCallDuration' => $duration,
            ], fn ($v) => $v !== null))->assertOk();

            $this->assertSame(
                $expected,
                $call->fresh()->outcome,
                "DialCallStatus={$status} must not map to connected",
            );
        }
    }

    public function test_a_duplicate_callback_is_idempotent(): void
    {
        $call = $this->openBrowserCall();
        $sid = 'CA00000000000000000000000000000007';
        $action = $this->dial($call, $sid);

        $this->completeAsTwilio($action, $sid, ['DialCallStatus' => 'completed', 'DialCallDuration' => '42'])->assertOk();
        $afterFirst = $call->fresh();

        // Twilio retries any non-2xx and can deliver twice regardless. The second must change nothing.
        $this->completeAsTwilio($action, $sid, ['DialCallStatus' => 'completed', 'DialCallDuration' => '42'])->assertOk();
        // Even a CONTRADICTING retry must not re-open or re-decide a concluded call.
        $this->completeAsTwilio($action, $sid, ['DialCallStatus' => 'busy'])->assertOk();

        $afterRetries = $call->fresh();
        $this->assertSame(CallOutcome::CONNECTED, $afterRetries->outcome);
        $this->assertSame(42, $afterRetries->duration_seconds);
        $this->assertEquals($afterFirst->ended_at, $afterRetries->ended_at);

        // Exactly ONE completion Activity, never one per delivery.
        $this->assertSame(1, Activity::query()
            ->withoutGlobalScope('org')
            ->where('event', 'call.completed')
            ->whereJsonContains('properties->call_id', $call->id)
            ->count());
    }

    public function test_only_a_connected_call_completes_the_planner_event(): void
    {
        // The frozen disposition policy is shared with the manual path, not re-decided for providers.
        $connected = $this->openBrowserCall();
        $action = $this->dial($connected, 'CA00000000000000000000000000000008');
        $this->completeAsTwilio($action, 'CA00000000000000000000000000000008', [
            'DialCallStatus' => 'completed',
            'DialCallDuration' => '30',
        ])->assertOk();
        $this->assertSame('completed', $connected->fresh()->plannerEvent()->first()->status);

        $busy = $this->openBrowserCall();
        $action = $this->dial($busy, 'CA00000000000000000000000000000009');
        $this->completeAsTwilio($action, 'CA00000000000000000000000000000009', [
            'DialCallStatus' => 'busy',
        ])->assertOk();
        $this->assertNotSame('completed', $busy->fresh()->plannerEvent()->first()->status);
    }

    public function test_an_uncorrelatable_completion_returns_200_and_changes_nothing(): void
    {
        // Genuinely Twilio's (signed by the centralized account), but naming no call. Answering non-2xx
        // would make Twilio retry something that can never succeed.
        $this->completeAsTwilio(self::COMPLETED_PATH.'?'.CallCorrelation::PARAM.'=garbage', 'CA00000000000000000000000000000010', [
            'DialCallStatus' => 'completed',
        ])->assertOk();

        $this->assertSame(0, Call::query()->withoutGlobalScope('org')->count());
    }

    // ── Long calls and expiry (D2) ─────────────────────────────────────────────────────────────

    public function test_a_call_longer_than_fifteen_minutes_still_completes(): void
    {
        // The old single 900-second token expired mid-conversation, so any call past ~15 minutes
        // could never be concluded by the carrier.
        $call = $this->openBrowserCall();
        $sid = 'CA00000000000000000000000000000011';
        $action = $this->dial($call, $sid);

        $this->travel(3)->hours();

        $this->completeAsTwilio($action, $sid, [
            'DialCallStatus' => 'completed',
            'DialCallDuration' => '10800',
        ])->assertOk();

        $fresh = $call->fresh();
        $this->assertSame(CallStatus::COMPLETED, $fresh->status);
        $this->assertSame(10800, $fresh->duration_seconds);
    }

    public function test_an_expired_completion_correlation_cannot_complete(): void
    {
        // Longer than any call Twilio will bridge (timeLimit) plus the grace window: the token is
        // spent, and a callback bearing it concludes nothing — still a 200, so Twilio does not retry.
        $call = $this->openBrowserCall();
        $sid = 'CA00000000000000000000000000000012';
        $action = $this->dial($call, $sid);

        $this->travel(CallCorrelation::completionTtl(30) + 3600)->seconds();

        $this->completeAsTwilio($action, $sid, [
            'DialCallStatus' => 'completed',
            'DialCallDuration' => '60',
        ])->assertOk();

        $this->assertSame(CallStatus::IN_PROGRESS, $call->fresh()->status);
    }
}
