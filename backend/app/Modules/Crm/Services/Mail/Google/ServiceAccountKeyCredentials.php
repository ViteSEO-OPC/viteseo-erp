<?php

namespace App\Modules\Crm\Services\Mail\Google;

use App\Modules\Crm\Services\Mail\Contracts\GoogleCredentialSource;
use Google\Client as GoogleClient;

/**
 * Domain-wide delegation signed locally by a downloadable service-account JSON key.
 *
 * LEGACY FALLBACK, selected only by `outreach.gmail.auth=key`, for environments where key creation
 * is permitted and Workload Identity Federation is not available. KeylessDelegatedCredentials is
 * the default; prefer it wherever the org policy allows.
 *
 * The Google client handles the assertion itself once given the key and the subject, which is why
 * this implementation is three lines and the keyless one is not.
 */
class ServiceAccountKeyCredentials implements GoogleCredentialSource
{
    /**
     * @param  string  $keyPath  path to the service-account JSON key
     * @param  list<string>  $scopes
     */
    public function __construct(
        private readonly string $keyPath,
        private readonly array $scopes,
    ) {}

    public function authorize(GoogleClient $client, string $mailbox): void
    {
        $client->setScopes($this->scopes);
        $client->setAuthConfig($this->keyPath);
        $client->setSubject($mailbox); // domain-wide delegation: impersonate the mailbox
    }
}
