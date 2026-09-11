<?php

namespace App\Modules\Crm\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Crm\Enums\CallOutcome;
use App\Modules\Crm\Enums\CallStatus;
use App\Modules\Crm\Models\Call;
use App\Modules\Crm\Models\Lead;
use App\Modules\Crm\Models\PlannerEvent;
use App\Modules\Crm\Requests\CompleteCallRequest;
use App\Modules\Crm\Resources\CallResource;
use App\Modules\Crm\Services\Calling\BrowserCallingUnavailable;
use App\Modules\Crm\Services\Calling\CallProviderRegistry;
use App\Modules\Crm\Services\Calling\CallSessionService;
use App\Modules\Crm\Services\Calling\ManualCallProvider;
use App\Modules\Crm\Support\DialablePhone;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * The Calling Workspace HTTP surface. Thin by design: EVERY state change delegates to
 * {@see CallSessionService} (the sole orchestration layer) — the controller only resolves the HTTP
 * intent and shapes the response, and NEVER persists a Call itself. Reading/starting authorize through
 * the parent lead or planner event (ownership boundary); completing/discarding authorize through
 * CallPolicy. A blocked domain rule (single-active-call, already-concluded) surfaces from the service as
 * a 422 via ValidationException — the standard `{message, errors}` envelope.
 */
class CallController extends Controller
{
    public function __construct(
        private readonly CallSessionService $calls,
        private readonly CallProviderRegistry $providers,
    ) {}

    /**
     * What calling this deployment can actually do, for THIS lead. The Call panel renders from this
     * and nothing else, which is what keeps the UI honest: a button only appears when the capability
     * behind it exists.
     *
     * Always answers 200. "No provider configured" and "this lead's number is unusable" are ordinary
     * states of a working CRM, not errors — the free manual workflow is available in both.
     */
    public function capability(Lead $lead): JsonResponse
    {
        $phone = DialablePhone::parse($lead->phone);

        return response()->json(['data' => [
            // Browser calling is an OPTIONAL paid capability. False is the default and the CRM is
            // fully usable there — the BD dials on their own phone and logs the call.
            'browserCalling' => $this->providers->supportsBrowserCalling(),
            'provider' => $this->providers->active()->name(),
            'phone' => [
                // The lead's number exactly as stored. Shown to the BD verbatim, because that is what
                // they may have to read aloud or type into a handset.
                'raw' => $lead->phone,
                // The normalized form, for `tel:` and for a provider. Null when it cannot be derived
                // safely — the CRM refuses to guess a country code.
                'e164' => $phone->e164,
                'dialable' => $phone->isDialable(),
                // Written for the BD, not for a log: it says what to fix on the lead.
                'reason' => $phone->reason,
            ],
        ]]);
    }

    /**
     * A short-lived provider credential for placing THIS call from the browser, plus the correlation
     * token that ties the resulting provider call back to this CRM record.
     *
     * Only reachable when a browser-capable provider is configured; the manual provider has no token
     * to give and says so with a 422 rather than a 500. Authorized by CallPolicy@update (the call
     * permission plus lead access) and rate-limited per user on the route.
     */
    public function token(Request $request, Call $call): JsonResponse
    {
        $provider = $this->providers->active();

        if (! $provider->supportsBrowserCalling()) {
            return $this->browserCallingUnavailable();
        }

        // A browser credential is only ever issued for an OPEN call on this transport: a manual call or
        // a concluded one has nothing left for a browser to carry.
        if ($call->provider !== $provider->name() || $call->status !== CallStatus::IN_PROGRESS) {
            return response()->json([
                'message' => 'This call cannot be placed from the browser.',
                'errors' => ['call' => ['Only an open browser call can use browser calling.']],
            ], 422);
        }

        try {
            $credential = $provider->issueBrowserToken($request->user(), $call);
        } catch (BrowserCallingUnavailable) {
            // Capability said yes, but the configuration changed before the credential was signed.
            return $this->browserCallingUnavailable();
        }

        // Once the voice webhook has bridged the call, this is a REFRESH of the browser's signalling
        // credential for the conversation in progress — the Access Token is short-lived and a long
        // call outlives it. It carries NO correlation: it keeps the live leg's signalling alive but
        // cannot make the voice webhook dial anything (a request without a correlation dials nothing),
        // so a refresh can never re-dial the lead. Before the call connects the correlation is
        // included, which is what a retried `connect()` needs.
        if ($call->external_call_id !== null) {
            $credential['correlation'] = null;
        }

        return response()->json(['data' => $credential]);
    }

    /** The one answer for "no browser calling here": a 422 in the standard envelope, never a 500. */
    private function browserCallingUnavailable(): JsonResponse
    {
        return response()->json([
            'message' => 'Browser calling is not configured for this workspace.',
            'errors' => ['provider' => ['Browser calling is not available.']],
        ], 422);
    }

    /** Call History for a lead — every attempt across the lead's planner events, newest first. */
    public function index(Lead $lead): AnonymousResourceCollection
    {
        return CallResource::collection(
            $lead->calls()->with('agent')->get(),
        );
    }

    /**
     * Ad-hoc "Start Call" from the lead (the Calling Workspace widget). Planner-first: the service
     * creates the owning `call` PlannerEvent, then opens the Call against it.
     */
    public function storeForLead(Request $request, Lead $lead): JsonResponse
    {
        // Which transport this attempt uses is decided HERE, once, from the deployment's capability —
        // never from anything the client sends. A browser that asks for a Twilio call in a manual
        // deployment simply gets a manual call.
        $provider = $this->providers->active();
        $useBrowser = $provider->supportsBrowserCalling() && $request->boolean('browser');

        if (! $useBrowser) {
            // The free path: the CRM opens the record, the BD dials on their own phone. `to_number`
            // stays null because the CRM does not place this call and will not imply that it did.
            return $this->created($this->calls->startAdHoc($lead, $request->user(), ManualCallProvider::NAME));
        }

        // A provider call needs a number a carrier will accept. Refusing here — before any Twilio
        // resource is created — is what stops garbage reaching the carrier and keeps the lead's
        // free-text phone from ever being dialed as-is.
        $phone = DialablePhone::parse($lead->phone);

        if (! $phone->isDialable()) {
            return response()->json([
                'message' => (string) $phone->reason,
                'errors' => ['phone' => [(string) $phone->reason]],
            ], 422);
        }

        return $this->created(
            $this->calls->startAdHoc($lead, $request->user(), $provider->name(), $phone->e164)
        );
    }

    /** Start a call against an EXISTING planned call event (the Planner-driven flow). */
    public function storeForEvent(Request $request, PlannerEvent $plannerEvent): JsonResponse
    {
        // ALWAYS MANUAL, deliberately. Starting a call from the Planner board opens the CRM record so
        // the BD can dial and disposition it; the board has no browser-call control and this endpoint
        // places no call, so claiming a carrier transport here would attach `provider = twilio` — and
        // with it the promise of carrier-reported duration — to a call Zerem never carried. Browser
        // calling is offered from the lead's Call panel, which goes through storeForLead above.
        return $this->created($this->calls->startForEvent($plannerEvent, $request->user()));
    }

    /** Conclude a call with a disposition (connected completes the planner event; others keep it open). */
    public function complete(CompleteCallRequest $request, Call $call): JsonResponse
    {
        $updated = $this->calls->complete(
            $call,
            CallOutcome::from((string) $request->validated('outcome')),
            $request->validated('notes'),
            $request->user(),
        );

        return (new CallResource($updated->load('agent')))->response();
    }

    /** Discard an in-progress call (tracked as `cancelled`; the planner event stays open for a retry). */
    public function discard(Request $request, Call $call): JsonResponse
    {
        $updated = $this->calls->discard($call, $request->user());

        return (new CallResource($updated->load('agent')))->response();
    }

    /** 201 with the freshly opened call. */
    private function created(Call $call): JsonResponse
    {
        return (new CallResource($call->load('agent')))->response()->setStatusCode(201);
    }
}
