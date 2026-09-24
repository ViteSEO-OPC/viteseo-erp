<?php

declare(strict_types=1);

namespace App\Modules\Crm\Services\Outbound\Meta;

use App\Modules\Crm\Services\Outbound\Credentials\ProviderCredential;
use App\Modules\Crm\Services\Outbound\OutboundFailureCode;
use App\Modules\Crm\Services\Outbound\OutboundMessageResult;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * The only place in the outbound tree that may speak to Meta.
 *
 * ONE COLLABORATOR, NOT A METHOD ON EACH ADAPTER. The two Meta adapters differ only in the URL
 * segment and one payload field; everything that is actually dangerous — the token, the
 * appsecret_proof, the timeouts, the retry rule — is identical, and duplicating it across two
 * classes is how one of the copies eventually grows a retry the other does not have. A guard test
 * asserts that no adapter constructs an HTTP client of its own and that no Graph host string
 * appears outside this file.
 *
 * THE TOKEN GOES IN THE AUTHORIZATION HEADER, NEVER THE QUERY STRING. Meta accepts
 * `?access_token=`, and it is the single most common way a Page token ends up in an access log, a
 * proxy log, a Sentry breadcrumb or a browser referer. `appsecret_proof` is a query parameter
 * because it must be — it is an HMAC of the token rather than the token, so logging it discloses
 * nothing usable.
 *
 * ================== THE RETRY RULE, WHICH IS THE POINT OF THIS CLASS ==================
 *
 * A POST that times out is NOT retried. Not once, not with backoff, not at a higher layer.
 *
 * The connect client next door retries twice, and is right to: it retries GETs, which are
 * idempotent, so a duplicate costs a wasted round trip. A Send API POST is the opposite. Meta gives
 * the caller no idempotency token and no way to ask "did the message you may have received from me
 * thirty seconds ago actually land?", so a timed-out POST has exactly two possible histories —
 * Meta never saw it, or Meta delivered it and we lost the answer — and nothing in the protocol
 * distinguishes them. Retrying picks the first history by assumption; when the assumption is wrong
 * a real customer receives the same message twice, from a business, with no way to tell it was a
 * machine error.
 *
 * So an ambiguous outcome returns {@see OutboundFailureCode::DeliveryUnknown} and the row stays
 * `pending`. A human resolves it by looking at the thread. That is slower and it is correct.
 *
 * NOTHING HERE LOGS A CREDENTIAL, A PAYLOAD OR A PROVIDER MESSAGE. Diagnostics carry the resource
 * id, our own failure code and Meta's numeric error code.
 */
final class GraphMetaSender
{
    public function __construct(private readonly MetaSendFailure $failures) {}

    /**
     * Whether this sender is configured well enough to be worth calling.
     *
     * Half of `canTransmit()`. The other half is the operator's flag — and the two are separate so
     * that setting `META_OUTBOUND_ENABLED=true` against a blank Graph host produces a refusal with
     * a name rather than a request to `https:///v26.0/...`.
     */
    public function isAvailable(): bool
    {
        return $this->host() !== '' && $this->version() !== '';
    }

    /**
     * POST one message to one resource.
     *
     * @param  string  $resourceExternalId  the Page or IG business account id, from
     *                                      `crm_provider_accounts` — never from a request
     * @param  array<string, mixed>  $payload  already rendered by the adapter that owns the channel
     */
    public function send(
        string $resourceExternalId,
        array $payload,
        ProviderCredential $credential,
    ): OutboundMessageResult {
        $url = $this->graph($resourceExternalId.'/messages');

        try {
            $response = $this->http()
                ->withToken($credential->reveal())
                ->post($this->withProof($url, $credential), $payload);
        } catch (ConnectionException $exception) {
            /*
             * Deliberately NOT chained and deliberately not re-thrown: a client exception's message
             * carries the full request URL, and the exception would travel up through a queue
             * worker's failure handler which serialises what it is given.
             */
            unset($exception);

            $this->diagnose('connection', $resourceExternalId, OutboundFailureCode::DeliveryUnknown, null);

            return OutboundMessageResult::refused(
                OutboundFailureCode::DeliveryUnknown,
                'No response was received from the provider; whether the message was delivered is unknown. It will not be sent again automatically.',
            );
        }

        if ($response->successful()) {
            $messageId = $response->json('message_id');

            if (is_string($messageId) && $messageId !== '') {
                return OutboundMessageResult::accepted($messageId);
            }

            /*
             * Accepted, but with nothing to record. Marking this `failed` would deny a delivery
             * that almost certainly happened, and marking it `sent` would invent an identifier. It
             * is the same epistemic position as a timeout, so it gets the same answer.
             */
            $this->diagnose('response', $resourceExternalId, OutboundFailureCode::DeliveryUnknown, null);

            return OutboundMessageResult::refused(
                OutboundFailureCode::DeliveryUnknown,
                'The provider accepted the message without returning an identifier; delivery cannot be confirmed.',
            );
        }

        $code = $this->failures->classify($response);

        $this->diagnose('rejected', $resourceExternalId, $code, $this->metaErrorCode($response));

        // The reason is OUR sentence, built from OUR code. Meta's prose never reaches it.
        return OutboundMessageResult::refused(
            $code,
            sprintf('The provider refused the message (%s).', $code->value),
        );
    }

    /**
     * Append `appsecret_proof` when an app secret is configured.
     *
     * Same rule as the connect client: Meta accepts it always and REQUIRES it once "Require App
     * Secret" is enabled, so sending it unconditionally means turning that setting on later does
     * not break a working integration.
     */
    private function withProof(string $url, ProviderCredential $credential): string
    {
        $appSecret = (string) config('meta.app_secret', '');

        if ($appSecret === '') {
            return $url;
        }

        return $url.'?'.http_build_query([
            'appsecret_proof' => hash_hmac('sha256', $credential->reveal(), $appSecret),
        ]);
    }

    private function http(): PendingRequest
    {
        return Http::connectTimeout((int) config('meta.outbound.http.connect_timeout', 5))
            ->timeout((int) config('meta.outbound.http.timeout', 10))
            ->acceptJson()
            // A 4xx is data to classify, not an exception to catch; and a redirect on a send is
            // something to refuse rather than follow with the credential attached.
            ->withoutRedirecting();
    }

    private function metaErrorCode(Response $response): ?int
    {
        $value = $response->json('error.code');

        return is_int($value) ? $value : null;
    }

    /** Our code, Meta's numeric code and the resource. Never a token, a payload or a body. */
    private function diagnose(string $stage, string $resourceExternalId, OutboundFailureCode $code, ?int $metaErrorCode): void
    {
        Log::warning('crm.outbound.meta.failed', [
            'provider' => 'meta',
            'stage' => $stage,
            'code' => $code->value,
            'meta_error_code' => $metaErrorCode,
            'resource_external_id' => $resourceExternalId,
        ]);
    }

    private function graph(string $path): string
    {
        return $this->host().'/'.$this->version().'/'.ltrim($path, '/');
    }

    private function host(): string
    {
        return rtrim((string) config('meta.oauth.graph_host', ''), '/');
    }

    private function version(): string
    {
        return trim((string) config('meta.oauth.graph_version', ''), '/');
    }
}
