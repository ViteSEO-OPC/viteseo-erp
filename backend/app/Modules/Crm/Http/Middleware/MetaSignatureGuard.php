<?php

declare(strict_types=1);

namespace App\Modules\Crm\Http\Middleware;

use App\Modules\Crm\Services\Inbound\Meta\MetaWebhookSignatureVerifier;
use App\Modules\Crm\Services\Inbound\SafeIdentifier;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * The gate in front of the public Meta webhook: size first, then signature.
 *
 * ORDER MATTERS AND IS DELIBERATE. The byte ceiling is checked BEFORE the HMAC, because hashing is
 * work an unauthenticated stranger would otherwise be able to command in unbounded quantity. The
 * length is measured on the actual body rather than Content-Length, which the sender writes and is
 * free to lie about.
 *
 * DISABLED MEANS REFUSE. With `meta.enabled` false — the default — every POST is rejected. It does
 * NOT mean "accept without verifying", and it does not mean "pass through": a feature flag that
 * turns authentication off rather than the feature off is a backdoor with a friendly name.
 *
 * WHAT GOES IN THE RESPONSE: nothing. Meta gets a bare status. Telling a caller which check failed
 * turns the endpoint into an oracle for probing the secret's presence, the size limit and the
 * header grammar, one request at a time.
 *
 * WHAT GOES IN THE LOG: a code, the byte length, and nothing else — never the signature header,
 * never the body, never the secret.
 */
class MetaSignatureGuard
{
    private const SIGNATURE_HEADER = 'X-Hub-Signature-256';

    public function __construct(private readonly MetaWebhookSignatureVerifier $verifier) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! (bool) config('meta.enabled', false)) {
            $this->reject('integration_disabled');

            return response()->noContent(403);
        }

        $body = $request->getContent();
        $bytes = strlen($body);
        $ceiling = (int) config('meta.limits.raw_body_bytes', 1048576);

        if ($bytes > $ceiling) {
            // 413 rather than 403: this is a permanent property of the delivery, and it is the one
            // rejection whose reason is safe to name because the sender already knows the size.
            $this->reject('payload_too_large', ['bytes' => $bytes, 'ceiling' => $ceiling]);

            return response()->noContent(413);
        }

        if (! $this->verifier->verify($body, $request->header(self::SIGNATURE_HEADER))) {
            $this->reject('signature_invalid', ['bytes' => $bytes]);

            return response()->noContent(403);
        }

        return $next($request);
    }

    /** @param  array<string, scalar|null>  $context */
    private function reject(string $code, array $context = []): void
    {
        Log::warning('crm.inbound.meta.rejected', [
            'provider' => 'meta',
            'code' => SafeIdentifier::for($code, SafeIdentifier::CODE),
            ...$context,
        ]);
    }
}
