<?php

declare(strict_types=1);

namespace Tests\Fixtures\Outbound;

use App\Modules\Crm\Services\Outbound\Contracts\OutboundProviderAdapter;
use App\Modules\Crm\Services\Outbound\Credentials\ProviderCredential;
use App\Modules\Crm\Services\Outbound\OutboundMessageRequest;
use App\Modules\Crm\Services\Outbound\OutboundMessageResult;

/**
 * An adapter that exists only so tests can reach code the real ones refuse to reach.
 *
 * Lives under tests/ and is registered by setting `outbound.adapters` inside a test. It must never
 * appear in config/outbound.php, and a test asserts that it does not.
 */
final class FixtureOutboundAdapter implements OutboundProviderAdapter
{
    /** Set when send() is reached, so a test can prove it was or was not. */
    public static bool $sent = false;

    public static function reset(): void
    {
        self::$sent = false;
        self::$canTransmit = true;
    }

    public function name(): string
    {
        return 'fixture-outbound';
    }

    public function supports(string $provider, string $channel): bool
    {
        return $provider === 'fixture' && $channel === 'fixture_channel';
    }

    /** Configurable, so one fixture can play both "no transport" and "transport present". */
    public static bool $canTransmit = true;

    public function canTransmit(): bool
    {
        return self::$canTransmit;
    }

    public function resourceTypeFor(string $channel): ?string
    {
        return $channel === 'fixture_channel' ? 'fixture_resource' : null;
    }

    public function validate(OutboundMessageRequest $request): ?OutboundMessageResult
    {
        return null;
    }

    public function send(
        OutboundMessageRequest $request,
        string $resourceExternalId,
        ProviderCredential $credential,
    ): OutboundMessageResult {
        self::$sent = true;

        return OutboundMessageResult::accepted('fixture-provider-message-id');
    }
}
