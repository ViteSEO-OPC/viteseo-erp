<?php

namespace App\Modules\Crm\Services\Mail\Google;

use App\Modules\Crm\Services\Mail\Contracts\GoogleCredentialSource;

/**
 * Builds the configured GoogleCredentialSource from the `outreach.gmail` config block.
 *
 * One place that reads auth config, so adding a credential shape (per-tenant OAuth, for instance)
 * means a new implementation plus a branch here — and nothing in the sync provider, the job, or
 * the business layer.
 *
 * Unknown values fall back to keyless rather than throwing, matching the pre-extraction behaviour:
 * only the literal string `key` selected the legacy path, anything else meant keyless.
 */
class GoogleCredentialSourceFactory
{
    /**
     * @param  array<string, mixed>  $config  keys: auth, service_account, credentials, scopes
     */
    public static function fromConfig(array $config): GoogleCredentialSource
    {
        /** @var list<string> $scopes */
        $scopes = array_values((array) ($config['scopes'] ?? []));

        if (($config['auth'] ?? 'keyless') === 'key') {
            return new ServiceAccountKeyCredentials((string) ($config['credentials'] ?? ''), $scopes);
        }

        return new KeylessDelegatedCredentials((string) ($config['service_account'] ?? ''), $scopes);
    }
}
