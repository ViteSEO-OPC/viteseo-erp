<?php

namespace App\Modules\Crm\Services\Mail\Google;

use App\Modules\Crm\Services\Mail\Contracts\GoogleCredentialSource;
use App\Modules\Crm\Services\Mail\MailboxOauthTokenStore;

/**
 * Builds the configured GoogleCredentialSource from the `outreach.gmail` config block.
 *
 * One place that reads auth config, so adding a credential shape means a new implementation plus a
 * branch here — and nothing in the sync provider, the job, or the business layer. Per-tenant OAuth
 * was the worked example in this docblock before it existed; it is now the `oauth` branch below,
 * and it cost exactly what was predicted.
 *
 * Unknown values fall back to keyless rather than throwing, matching the pre-extraction behaviour:
 * only the literal string `key` selected the legacy path, anything else meant keyless. `oauth` joins
 * `key` as an exact-match opt-in, so no existing deployment can drift onto it by typo — a misspelled
 * `oauht` still lands on keyless, exactly as it does today.
 *
 * Nothing here contacts Google or reads the database. Every branch is a constructor call, so a
 * deployment with none of the Google settings configured still boots; a missing client secret is
 * discovered when a refresh is attempted, not at container-resolution time.
 */
class GoogleCredentialSourceFactory
{
    /**
     * @param  array<string, mixed>  $config  keys: auth, service_account, credentials, client_id,
     *                                        client_secret, scopes
     * @param  MailboxOauthTokenStore|null  $tokens  the grant store OAuth credentials read and write
     *                                               through. Injected so the caller's configured
     *                                               instance wins (CrmServiceProvider resolves it
     *                                               from the container); defaulted so this stays a
     *                                               pure config→object function for tests and for
     *                                               the two branches that have no use for it.
     */
    public static function fromConfig(array $config, ?MailboxOauthTokenStore $tokens = null): GoogleCredentialSource
    {
        /** @var list<string> $scopes */
        $scopes = array_values((array) ($config['scopes'] ?? []));
        $auth = $config['auth'] ?? 'keyless';

        if ($auth === 'key') {
            return new ServiceAccountKeyCredentials((string) ($config['credentials'] ?? ''), $scopes);
        }

        if ($auth === 'oauth') {
            return new OAuthUserCredentials(
                $tokens ?? new MailboxOauthTokenStore,
                (string) ($config['client_id'] ?? ''),
                (string) ($config['client_secret'] ?? ''),
                $scopes,
            );
        }

        return new KeylessDelegatedCredentials((string) ($config['service_account'] ?? ''), $scopes);
    }
}
