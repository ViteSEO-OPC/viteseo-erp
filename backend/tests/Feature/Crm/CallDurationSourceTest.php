<?php

declare(strict_types=1);

namespace Tests\Feature\Crm;

use App\Models\User;
use App\Modules\Crm\Enums\CallDurationSource;
use App\Modules\Crm\Enums\CallOutcome;
use App\Modules\Crm\Models\Call;
use App\Modules\Crm\Models\Lead;
use App\Modules\Crm\Services\Calling\CallCorrelation;
use App\Modules\Crm\Services\Calling\TwilioCallProvider;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Crm\Concerns\ConfiguresCentralTwilio;
use Tests\TestCase;
use Twilio\Security\RequestValidator;

/**
 * A duration must never claim to be something it is not.
 *
 * The CRM stores two quantities under one column name. One is how long a call RECORD was open, which
 * is all a CRM can know about a call placed on someone's handset. The other is a carrier's measurement
 * of a dialed leg — real talk time. Presenting the first as the second would overstate every manual
 * call in the history, and `crm_calls.provider` cannot tell them apart: a Twilio call whose completion
 * webhook never arrived (a tunnel down, an outage, a BD who concluded the record by hand) is carried
 * by Twilio and yet timed by the CRM.
 *
 * `duration_source` is that distinction, and these tests pin it from the direction that would actually
 * go wrong — the FALLBACK paths, where a provider call ends up with a CRM measurement.
 *
 * NOTHING HERE SCORES ANYTHING. The scoring engine reads the lead's `cold_calling` status, not
 * seconds; this column records a fact for a rule that may be configured later, never a rule of its own.
 */
class CallDurationSourceTest extends TestCase
{
    use ConfiguresCentralTwilio;
    use RefreshDatabase;

    private const AUTH_TOKEN = 'test-auth-token';

    private const BASE_URL = 'https://example.test';

    private User $bd;

    private Lead $lead;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->bd = User::factory()->create(['status' => 'active']);
        $this->bd->assignRole('crm_bd');
        $this->lead = Lead::factory()->create([
            'assigned_to' => $this->bd->id,
            'phone' => '0917 847 5233',
        ]);
    }

    /** Zerem's centralized ViteSEO Twilio account, so a browser call can be opened at all. */
    private function configureTwilio(): void
    {
        $this->configureCentralTwilio([
            'auth_token' => self::AUTH_TOKEN,
            'webhook_base_url' => self::BASE_URL,
        ]);
    }

    private function openCall(bool $browser = false): Call
    {
        Sanctum::actingAs($this->bd);
        $this->postJson("/api/v1/crm/leads/{$this->lead->id}/calls", $browser ? ['browser' => true] : [])
            ->assertCreated();

        return Call::query()->withoutGlobalScope('org')->latest('id')->firstOrFail();
    }

    /** @param array<string, mixed> $params */
    private function postSigned(string $path, array $params)
    {
        $signature = (new RequestValidator(self::AUTH_TOKEN))->computeSignature(self::BASE_URL.$path, $params);

        return $this->post($path, $params, ['X-Twilio-Signature' => $signature]);
    }

    /**
     * The voice webhook, as Twilio runs it after `Device.connect()`. Returns the `<Dial action>` path
     * (with its query) that Twilio calls back when the dialed leg ends — the only way a completion
     * really arrives, since Twilio does not re-send the browser's `connect()` parameters.
     */
    private function dial(Call $call, string $callSid): string
    {
        $xml = $this->postSigned('/api/v1/twilio/voice', [
            CallCorrelation::PARAM => CallCorrelation::issue($call),
            'CallSid' => $callSid,
        ])->assertOk()->getContent();

        return substr((string) simplexml_load_string((string) $xml)->Dial['action'], strlen(self::BASE_URL));
    }

    // ── The manual transport ───────────────────────────────────────────────────────────────────

    public function test_a_manual_call_is_crm_timed_from_the_moment_it_opens(): void
    {
        $call = $this->openCall();

        $this->assertSame(CallDurationSource::MANUAL, $call->duration_source);
    }

    public function test_a_manual_call_concluded_by_the_bd_stays_crm_timed(): void
    {
        $call = $this->openCall();

        Sanctum::actingAs($this->bd);
        $this->patchJson("/api/v1/crm/calls/{$call->id}/complete", [
            'outcome' => CallOutcome::CONNECTED->value,
            'notes' => null,
        ])->assertOk();

        $this->assertSame(CallDurationSource::MANUAL, $call->refresh()->duration_source);
    }

    public function test_the_api_reports_the_duration_source_to_the_client(): void
    {
        $call = $this->openCall();

        Sanctum::actingAs($this->bd);
        $history = $this->getJson("/api/v1/crm/leads/{$this->lead->id}/calls")->assertOk()->json('data');

        $this->assertSame('manual', $history[0]['durationSource']);
        $this->assertSame((string) $call->id, (string) $history[0]['id']);
    }

    // ── The provider transport ─────────────────────────────────────────────────────────────────

    public function test_a_browser_call_is_crm_timed_until_the_carrier_reports(): void
    {
        // Opening a Twilio call proves nothing about talk time yet. Until the completion webhook
        // lands there is only the CRM's own clock, and the row must say so.
        $this->configureTwilio();

        $this->assertSame(CallDurationSource::MANUAL, $this->openCall(browser: true)->duration_source);
    }

    public function test_a_carrier_reported_duration_is_marked_provider_measured(): void
    {
        $this->configureTwilio();
        $call = $this->openCall(browser: true);
        app('auth')->forgetGuards();

        $action = $this->dial($call, 'CA00000000000000000000000000000001');
        $this->postSigned($action, [
            'CallSid' => 'CA00000000000000000000000000000001',
            'DialCallStatus' => 'completed',
            'DialCallDuration' => '95',
        ])->assertOk();

        $call->refresh();

        $this->assertSame(95, $call->duration_seconds);
        $this->assertSame(CallDurationSource::PROVIDER, $call->duration_source);
        $this->assertTrue($call->duration_source->isVerified());
    }

    public function test_a_completion_without_a_carrier_duration_falls_back_to_the_crm_clock(): void
    {
        // THE CASE THAT MATTERS. Twilio carried the call, so `provider` says `twilio` — but no
        // duration was reported, so the stored seconds are the CRM's own. Reading `provider` alone
        // would present this as verified talk time.
        $this->configureTwilio();
        $call = $this->openCall(browser: true);
        app('auth')->forgetGuards();

        $action = $this->dial($call, 'CA00000000000000000000000000000002');
        $this->postSigned($action, [
            'CallSid' => 'CA00000000000000000000000000000002',
            'DialCallStatus' => 'no-answer',
        ])->assertOk();

        $call->refresh();

        $this->assertSame(TwilioCallProvider::NAME, $call->provider);
        $this->assertSame(CallDurationSource::MANUAL, $call->duration_source);
        $this->assertFalse($call->duration_source->isVerified());
    }

    public function test_a_browser_call_concluded_by_the_bd_before_the_webhook_stays_crm_timed(): void
    {
        // The other fallback: the BD pressed Complete while the callback was still in flight.
        $this->configureTwilio();
        $call = $this->openCall(browser: true);

        Sanctum::actingAs($this->bd);
        $this->patchJson("/api/v1/crm/calls/{$call->id}/complete", [
            'outcome' => CallOutcome::CONNECTED->value,
            'notes' => null,
        ])->assertOk();

        $call->refresh();

        $this->assertSame(TwilioCallProvider::NAME, $call->provider);
        $this->assertSame(CallDurationSource::MANUAL, $call->duration_source);
    }
}
