<?php

declare(strict_types=1);

namespace App\Modules\Crm\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Crm\Services\Inbound\Meta\MetaDeliveryTooLargeException;
use App\Modules\Crm\Services\Inbound\Meta\MetaWebhookDispatcher;
use App\Modules\Crm\Services\Inbound\SafeIdentifier;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use JsonException;

/**
 * The two public Meta endpoints. Neither uses Sanctum; each carries its own Meta-specific proof.
 *
 *   GET  — the subscription handshake. No signature exists to check (Meta sends none), so the proof
 *          is the verify token, compared in constant time.
 *   POST — the delivery. The proof is the HMAC, already enforced by MetaSignatureGuard before this
 *          controller is reached. The guard is on the route rather than in here so an unsigned body
 *          cannot reach any code that decodes it.
 *
 * THE BODY IS DECODED FROM THE VERIFIED BYTES. `$request->json()` / `$request->all()` are
 * deliberately never used: the framework's input pipeline can trim and null-coerce values, so the
 * decoded array would not be the document that was signed — and the difference would be invisible
 * while quietly changing both what is persisted and the derived idempotency key. Decoding the same
 * string the HMAC covered is what makes "verified" mean anything.
 *
 * RESPONSES CARRY NO DIAGNOSTICS. Meta gets a bare status code. Naming the failing check would turn
 * the endpoint into an oracle for probing the secret's presence, the size ceiling and the header
 * grammar one request at a time; the operator learns the reason from the log instead.
 *
 * THE ONE RETRYABLE ANSWER is 500, and only for a recoverable infrastructure failure. Meta retries
 * 5xx and eventually DISABLES a subscription that keeps failing — and the subscription is per-app,
 * so answering 5xx for a permanently malformed event or an unmapped Page would take every tenant's
 * webhook down over one tenant's misconfiguration.
 */
class MetaWebhookController extends Controller
{
    public function __construct(private readonly MetaWebhookDispatcher $dispatcher) {}

    /**
     * Meta's subscription handshake: echo `hub.challenge`, but only to a caller that knows the
     * verify token.
     *
     * Every input is validated as a bounded scalar BEFORE comparison, because Laravel's query
     * parsing happily produces arrays (`?hub.verify_token[]=x`) and handing one to a string
     * comparison is how these checks are bypassed. The configured token must be non-empty: an
     * empty one would otherwise match an omitted parameter.
     */
    public function verify(Request $request): Response
    {
        // PHP rewrites dots in query keys to underscores when it parses the query string, so
        // Meta's `hub.mode` arrives as `hub_mode`. Reading the dotted names would silently find
        // nothing and fail closed forever.
        $configured = (string) config('meta.verify_token', '');
        $mode = $this->boundedScalar($request->query('hub_mode'));
        $token = $this->boundedScalar($request->query('hub_verify_token'));
        $challenge = $this->boundedScalar($request->query('hub_challenge'));

        $ok = (bool) config('meta.enabled', false)
            && $configured !== ''
            && $mode === 'subscribe'
            && $token !== null
            && $challenge !== null
            && hash_equals($configured, $token);

        if (! $ok) {
            Log::warning('crm.inbound.meta.rejected', [
                'provider' => 'meta',
                'code' => 'handshake_failed',
            ]);

            return response('', 403);
        }

        Log::info('crm.inbound.meta.verified', ['provider' => 'meta']);

        // Echoed verbatim and only on success. `text/plain` is what Meta expects to read back.
        return response($challenge, 200)->header('Content-Type', 'text/plain');
    }

    /** The signed delivery. MetaSignatureGuard has already proven it came from our app. */
    public function handle(Request $request): Response
    {
        $depth = max(2, (int) config('meta.limits.json_depth', 16));

        try {
            /** @var mixed $decoded */
            $decoded = json_decode($request->getContent(), true, $depth, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            // Covers malformed JSON AND a document deeper than the ceiling — json_decode's depth is
            // a hard limit, so "too deep" is an error rather than a silent truncation. Both are
            // permanent for this delivery, so neither is worth a retry.
            Log::warning('crm.inbound.meta.rejected', [
                'provider' => 'meta',
                'code' => 'malformed_json',
                'exception' => $exception::class,
            ]);

            return response('', 400);
        }

        if (! is_array($decoded)) {
            Log::warning('crm.inbound.meta.rejected', ['provider' => 'meta', 'code' => 'malformed_json']);

            return response('', 400);
        }

        try {
            $result = $this->dispatcher->dispatch($decoded);
        } catch (MetaDeliveryTooLargeException) {
            // Refused whole, before any group ran — see the dispatcher.
            return response('', 413);
        }

        return response('', $result->shouldAskForRedelivery() ? 500 : 200);
    }

    /**
     * A query parameter as a bounded string, or null for anything else.
     *
     * Bounded before use because the challenge is echoed back and the token is compared: an
     * unbounded value would let a stranger choose the size of our response body.
     */
    private function boundedScalar(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        return SafeIdentifier::for($value, SafeIdentifier::EXTERNAL_ID);
    }
}
