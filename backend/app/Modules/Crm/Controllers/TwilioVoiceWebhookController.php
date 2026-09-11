<?php

declare(strict_types=1);

namespace App\Modules\Crm\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Crm\Enums\CallStatus;
use App\Modules\Crm\Models\Call;
use App\Modules\Crm\Services\Calling\CallCorrelation;
use App\Modules\Crm\Services\Calling\CallSessionService;
use App\Modules\Crm\Services\Calling\TwilioCallProvider;
use App\Modules\Crm\Services\Calling\TwilioCallStateMapper;
use App\Modules\Crm\Services\Calling\TwilioConfig;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Twilio\TwiML\VoiceResponse;

/**
 * The two endpoints Twilio calls back on during an outbound browser call.
 *
 * Both are UNAUTHENTICATED by necessity and both are protected the same way:
 *   1. `ValidateTwilioSignature` proves the request is Twilio's.
 *   2. `CallCorrelation` proves WHICH CRM call it may touch.
 *
 * Neither check substitutes for the other. The signature says the message is genuine but says nothing
 * about which row it refers to; the correlation token — minted earlier by an authenticated,
 * policy-checked request — names the row and cannot be edited by the browser that carried it. A
 * request passing only one of the two is refused.
 *
 * ── Tenancy ──
 * These routes deliberately sit outside `set-current-org`, because a webhook has no user to derive a
 * tenant from. They never need one: the correlation token resolves exactly one `crm_calls` row, and
 * that row already carries its `org_id`, its lead and its agent from when an authenticated BD created
 * it. Ownership is READ from the record rather than asserted by the caller, so no tenant resolution
 * happens here and the multi-tenancy implementation is untouched.
 */
class TwilioVoiceWebhookController extends Controller
{
    public function __construct(private readonly CallSessionService $calls) {}

    /**
     * The TwiML App's Voice URL. Twilio hits this the moment the browser's `Device.connect()` reaches
     * it, and the XML returned here is the instruction for what to do with that leg — in our case,
     * bridge it to the lead's phone.
     */
    public function voice(Request $request): Response
    {
        $call = $this->resolveCall($request, CallCorrelation::PURPOSE_DIAL);

        if ($call === null) {
            // A signed Twilio request that we cannot tie to a live CRM call. Say so to the caller and
            // hang up rather than dialing anything — there is no safe number to dial.
            return $this->spoken('This call could not be matched to a CRM record.');
        }

        if ($call->to_number === null || $call->to_number === '') {
            return $this->spoken('No dialable number is on file for this lead.');
        }

        // The CALL'S OWN organization's Twilio account — the one that placed this call and whose Auth
        // Token the middleware just validated the signature against. Read from the correlated record,
        // never from the request, so a webhook can never borrow another tenant's caller ID.
        $config = TwilioConfig::forOrg((int) $call->org_id);

        // COMPLETE, not usable. The stricter `isUsable()` also demands the switch be on and the
        // credentials re-verified, which are questions about whether to OFFER browser calling — and
        // this call has already been placed. An admin who opens the settings screen mid-call, edits a
        // caller ID, or toggles the feature off must not cause Zerem to hang up on the person on the
        // line. What matters here is only whether we still hold what it takes to dial.
        if (! $config->isComplete()) {
            // Credentials were removed between placing the call and this callback.
            return $this->spoken('Calling is no longer configured for this workspace.');
        }

        // Twilio's id for the BROWSER leg. Stored write-once so the CRM record can be found again
        // from a Twilio console, and so a retry of this same webhook changes nothing.
        $this->calls->attachProviderCall($call, (string) $request->input('CallSid', ''));

        $response = new VoiceResponse;

        // `action` fires when the DIALED leg finishes and carries DialCallStatus/DialCallDuration —
        // the carrier's own verdict and talk time, which is what completes the CRM call. `answerOnBridge`
        // means the BD hears real ringing rather than silence, and Twilio does not bill or mark the
        // call answered until the callee actually picks up.
        //
        // The action URL carries its OWN correlation (see completionUrl): Twilio calls it back
        // verbatim, but does not re-send what the browser passed to `connect()`. `timeLimit` is
        // Twilio's default made explicit, because the completion token is sized to it — a call can
        // never outlive the token that concludes it.
        $dial = $response->dial('', [
            'callerId' => (string) $config->callerId,
            'answerOnBridge' => true,
            'timeout' => $config->dialTimeout,
            'timeLimit' => CallCorrelation::MAX_CALL_SECONDS,
            'action' => $this->completionUrl($config, $call),
            'method' => 'POST',
        ]);

        // The number comes from OUR record, never from the request body. A signed webhook still must
        // not be able to choose what we dial.
        $dial->number($call->to_number);

        return $this->twiml($response);
    }

    /**
     * The `<Dial>` action callback: the dialed leg has ended. This is where a Twilio call is concluded
     * with the carrier's status and duration.
     *
     * Always answers 200 with empty TwiML, including for a request that resolves to nothing. Twilio
     * retries any non-2xx, and retrying will not make an unresolvable correlation resolvable — it
     * would only produce a retry storm.
     */
    public function completed(Request $request): Response
    {
        // The correlation arrives in the QUERY STRING of the action URL the voice webhook wrote, and
        // only a COMPLETE token is accepted here — a dial token cannot conclude a call.
        $call = $this->resolveCall($request, CallCorrelation::PURPOSE_COMPLETE, activeOnly: false);

        if ($call === null) {
            Log::warning('Twilio call-completed webhook could not be correlated to a CRM call.', [
                'call_sid' => $request->input('CallSid'),
            ]);

            return $this->twiml(new VoiceResponse);
        }

        // Bind the token to the Twilio call it was written for. The voice webhook stored that
        // call's CallSid (write-once) before dialing, and the action callback reports the same
        // parent CallSid. A valid token arriving with any other CallSid is not this call's
        // conclusion, so it changes nothing.
        if ($call->external_call_id !== null
            && ! hash_equals((string) $call->external_call_id, (string) $request->input('CallSid', ''))) {
            Log::warning('Twilio call-completed webhook named a different Twilio call than the CRM call placed.', [
                'call_id' => $call->id,
            ]);

            return $this->twiml(new VoiceResponse);
        }

        $duration = TwilioCallStateMapper::durationFrom($request->input('DialCallDuration'));
        $outcome = TwilioCallStateMapper::toOutcome(
            (string) $request->input('DialCallStatus', ''),
            $duration,
        );

        // Idempotent by contract: a duplicate delivery for an already-concluded call is a no-op, so a
        // Twilio retry can never double-complete a call or write a second Activity.
        $this->calls->completeFromProvider($call, $outcome, $duration);

        return $this->twiml(new VoiceResponse);
    }

    /**
     * Resolve the CRM call this webhook is allowed to act on — the security core of both endpoints.
     *
     * The ONLY accepted identifier is the correlation token, which is HMAC-signed over the call id —
     * by an authenticated request that already passed CallPolicy (dial), or by the voice webhook for
     * a call that dial token already resolved (complete). A raw id in the body is never read, so a
     * signed request cannot name another BD's — or another organization's — call.
     *
     * `$activeOnly` guards the dialing path: TwiML must only ever be produced for a call still
     * in progress, so a replayed token cannot re-dial a lead from a finished record. The completion
     * path deliberately relaxes it so a late duplicate still lands on its (already-concluded) row and
     * is recognised as the no-op it is.
     */
    private function resolveCall(Request $request, string $purpose, bool $activeOnly = true): ?Call
    {
        $callId = CallCorrelation::resolveCallId($request->input(CallCorrelation::PARAM), $purpose);

        if ($callId === null) {
            return null;
        }

        // The tenant scope is deliberately lifted, and this is the ONE place in the CRM that does it
        // on the call domain. It is not a tenancy change and not a bypass:
        //
        //   * There is no authenticated user here, so `CurrentOrg::id()` would fall back to the FIRST
        //     org. Left in place, that scope does not protect anything — it silently pins every
        //     webhook to one tenant and makes a second org's calls simply unfindable.
        //   * The correlation token has already named exactly one row, and it was minted by an
        //     authenticated request that passed CallPolicy. Identity is settled before this query.
        //   * `org_id` is then READ from the row that comes back, together with its lead and agent.
        //     Ownership is inherited from what an authenticated BD created, never asserted by the
        //     caller — which is precisely what the tenant scope exists to guarantee.
        //
        // `withoutGlobalScope('org')` is the escape hatch BelongsToOrg documents for exactly this.
        return Call::query()
            ->withoutGlobalScope('org')
            ->whereKey($callId)
            // A call this webhook may touch is by definition one a Twilio browser session opened.
            ->where('provider', TwilioCallProvider::NAME)
            ->when($activeOnly, fn ($query) => $query->where('status', CallStatus::IN_PROGRESS->value))
            ->first();
    }

    /**
     * The `<Dial action>` URL: the completion endpoint on the call's own public origin, carrying a
     * COMPLETE correlation this server mints here. Twilio signs the full URL, query included, so the
     * token is covered by the webhook signature as well as by its own HMAC.
     */
    private function completionUrl(TwilioConfig $config, Call $call): string
    {
        $token = CallCorrelation::issue(
            $call,
            CallCorrelation::PURPOSE_COMPLETE,
            CallCorrelation::completionTtl($config->dialTimeout),
        );

        return $config->callbackUrl('/api/v1/twilio/voice/completed')
            .'?'.http_build_query([CallCorrelation::PARAM => $token]);
    }

    /**
     * A spoken dead-end: the BD hears why nothing is being dialed instead of silence.
     *
     * Built in two statements on purpose — the TwiML builder's `say()` returns the CHILD node, not
     * the response, so `(new VoiceResponse)->say(...)` yields a `Say` and never the document.
     */
    private function spoken(string $message): Response
    {
        $response = new VoiceResponse;
        $response->say($message);

        return $this->twiml($response);
    }

    /** TwiML is XML; Twilio ignores a response that is not served as such. */
    private function twiml(VoiceResponse $response): Response
    {
        return new Response((string) $response, 200, ['Content-Type' => 'text/xml']);
    }
}
