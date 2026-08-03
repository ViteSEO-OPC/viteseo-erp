<?php

namespace App\Modules\Crm\Services\Mail\Google;

use App\Modules\Crm\Services\Mail\Contracts\GoogleCredentialSource;
use Google\Auth\ApplicationDefaultCredentials;
use Google\Client as GoogleClient;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Domain-wide delegation WITHOUT a downloadable service-account key — the default and preferred
 * credential for ViteSEO Enterprise (org policy iam.disableServiceAccountKeyCreation).
 *
 * The delegation assertion is signed AS the service account via the IAM Credentials signJwt API,
 * using the runtime identity from Application Default Credentials (Workload Identity Federation
 * off-GCP, or an attached service account on-GCP), which must hold
 * roles/iam.serviceAccountTokenCreator on the sync service account. No private key ever exists
 * locally.
 *
 * Moved here verbatim from GmailMailboxSync when credentials became their own abstraction; the
 * token exchange, the cache key and the failure messages are unchanged, so a deployment that
 * worked before this extraction authenticates identically after it.
 */
class KeylessDelegatedCredentials implements GoogleCredentialSource
{
    /**
     * @param  string  $serviceAccount  the SA impersonated for delegation, e.g. crm-inbound-sync@…
     * @param  list<string>  $scopes
     */
    public function __construct(
        private readonly string $serviceAccount,
        private readonly array $scopes,
    ) {}

    public function authorize(GoogleClient $client, string $mailbox): void
    {
        $client->setScopes($this->scopes);
        $client->setAccessToken($this->delegatedToken($mailbox));
    }

    /**
     * Mint a domain-wide-delegated access token for $mailbox. Cached (~50 min) to avoid re-minting
     * on every sync; tokens live ~1h, so this refreshes a little early.
     *
     * @return array{access_token: string, expires_in: int}
     */
    private function delegatedToken(string $mailbox): array
    {
        if ($this->serviceAccount === '') {
            throw new RuntimeException(
                'Keyless Gmail auth requires outreach.gmail.service_account (env GMAIL_SYNC_SERVICE_ACCOUNT).'
            );
        }

        $sa = $this->serviceAccount;
        $scopes = implode(' ', $this->scopes);

        /** @var array{access_token: string, expires_in: int} $token */
        $token = Cache::remember(
            "outreach:gmail:dwd-token:{$sa}:{$mailbox}",
            3000,
            function () use ($sa, $mailbox, $scopes): array {
                $now = time();
                $assertion = $this->signDelegatedJwt($sa, [
                    'iss' => $sa,
                    'sub' => $mailbox, // the impersonated mailbox — domain-wide delegation
                    'scope' => $scopes,
                    'aud' => 'https://oauth2.googleapis.com/token',
                    'iat' => $now,
                    'exp' => $now + 3600,
                ]);

                $response = Http::asForm()->post('https://oauth2.googleapis.com/token', [
                    'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                    'assertion' => $assertion,
                ]);

                if ($response->failed()) {
                    throw new RuntimeException('Gmail keyless token exchange failed: '.$response->body());
                }

                return [
                    'access_token' => (string) $response->json('access_token'),
                    'expires_in' => (int) $response->json('expires_in', 3600),
                ];
            },
        );

        return $token;
    }

    /**
     * Sign a JWT as the service account via IAM signJwt — the single keyed→keyless substitution.
     *
     * @param  array<string, mixed>  $claims
     */
    private function signDelegatedJwt(string $sa, array $claims): string
    {
        $source = ApplicationDefaultCredentials::getCredentials('https://www.googleapis.com/auth/cloud-platform');
        $bearer = (string) ($source->fetchAuthToken()['access_token'] ?? '');
        if ($bearer === '') {
            throw new RuntimeException(
                'No ADC source token for IAM signJwt — check Workload Identity Federation / GOOGLE_APPLICATION_CREDENTIALS.'
            );
        }

        $response = Http::withToken($bearer)->post(
            "https://iamcredentials.googleapis.com/v1/projects/-/serviceAccounts/{$sa}:signJwt",
            ['payload' => (string) json_encode($claims)],
        );

        if ($response->failed()) {
            throw new RuntimeException("IAM signJwt failed for {$sa}: ".$response->body());
        }

        return (string) $response->json('signedJwt');
    }
}
