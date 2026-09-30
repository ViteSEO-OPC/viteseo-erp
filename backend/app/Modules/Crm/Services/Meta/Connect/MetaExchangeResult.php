<?php

declare(strict_types=1);

namespace App\Modules\Crm\Services\Meta\Connect;

use App\Modules\Crm\Services\Outbound\Credentials\ProviderCredential;

/**
 * What came back from trading an authorization code: a credential, or a reason there is none.
 *
 * A failure is a value rather than an exception because "this app is not configured to talk to
 * Meta yet" is the ordinary state of this deployment, not an exceptional one.
 */
final class MetaExchangeResult
{
    private function __construct(
        public readonly bool $successful,
        public readonly ?ProviderCredential $credential = null,
        public readonly ?MetaConnectFailureCode $failure = null,
    ) {}

    public static function success(ProviderCredential $credential): self
    {
        return new self(true, credential: $credential);
    }

    public static function failed(MetaConnectFailureCode $code): self
    {
        return new self(false, failure: $code);
    }
}
