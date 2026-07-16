<?php

namespace App\Modules\Crm\Controllers;

use App\Http\Controllers\Controller;

use App\Modules\Crm\Jobs\ProcessInboundNotificationJob;
use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Public Pub/Sub push endpoint for Gmail change notifications. Verifies the Google-signed OIDC
 * token, dedupes on the Pub/Sub message id, enqueues ProcessInboundNotificationJob, and returns
 * fast (2xx) so Pub/Sub does not redeliver. It performs NO business logic itself.
 */
class GmailWebhookController extends Controller
{
    public function __invoke(Request $request): Response
    {
        if (config('outreach.webhook.verify') && ! $this->verified($request)) {
            return response('', 403);
        }

        /** @var array<string, mixed> $payload */
        $payload = $request->all();

        // Idempotency: drop duplicate Pub/Sub deliveries before enqueuing.
        $messageId = data_get($payload, 'message.messageId');
        if (is_string($messageId)
            && Cache::add('gmail-pubsub:'.$messageId, true, now()->addMinutes(10)) === false) {
            return response('', 204);
        }

        ProcessInboundNotificationJob::dispatch($payload);

        return response('', 204);
    }

    private function verified(Request $request): bool
    {
        $token = $request->bearerToken();
        if ($token === null) {
            return false;
        }

        try {
            $claims = (array) JWT::decode($token, $this->googleKeys());

            $audience = config('outreach.webhook.audience');
            if (is_string($audience) && ($claims['aud'] ?? null) !== $audience) {
                return false;
            }

            $serviceAccount = config('outreach.webhook.service_account');
            if (is_string($serviceAccount) && ($claims['email'] ?? null) !== $serviceAccount) {
                return false;
            }

            return ($claims['email_verified'] ?? false) === true;
        } catch (\Throwable $e) {
            Log::warning('outreach.inbound.webhook_verify_failed', ['error' => $e->getMessage()]);

            return false;
        }
    }

    /**
     * @return array<string, Key>
     */
    private function googleKeys(): array
    {
        /** @var array<string, mixed> $jwks */
        $jwks = Cache::remember(
            'google-oauth-certs',
            now()->addHour(),
            fn (): array => (array) Http::get('https://www.googleapis.com/oauth2/v3/certs')->json(),
        );

        return JWK::parseKeySet($jwks);
    }
}
