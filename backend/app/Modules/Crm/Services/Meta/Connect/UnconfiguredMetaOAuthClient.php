<?php

declare(strict_types=1);

namespace App\Modules\Crm\Services\Meta\Connect;

use App\Modules\Crm\Services\Meta\Connect\Contracts\MetaOAuthClient;
use App\Modules\Crm\Services\Outbound\Credentials\ProviderCredential;

/**
 * The client Phase 3F.3A ships: it cannot talk to Meta, and says so.
 *
 * DELIBERATELY NOT A FLAG. A boolean that switched this on would switch on code that does not
 * exist — there is no Graph call anywhere in this tree. Replacing this binding is Phase 3F.3B and
 * is reviewed on its own, which is the only way the first real token exchange gets read by someone
 * before it runs.
 *
 * IT MAKES NO HTTP REQUEST, and a test asserts that by scanning this directory for a Graph host, a
 * token endpoint, curl and Guzzle rather than by trusting the reading above.
 */
final class UnconfiguredMetaOAuthClient implements MetaOAuthClient
{
    public function exchangeCode(string $code, string $redirectUri): MetaExchangeResult
    {
        return MetaExchangeResult::failed(MetaConnectFailureCode::NotConfigured);
    }

    public function discoverAssets(ProviderCredential $userCredential): MetaAssetDiscovery
    {
        return MetaAssetDiscovery::failed(MetaConnectFailureCode::NotConfigured);
    }
}
