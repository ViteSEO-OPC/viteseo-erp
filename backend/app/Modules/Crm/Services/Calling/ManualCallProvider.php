<?php

declare(strict_types=1);

namespace App\Modules\Crm\Services\Calling;

use App\Models\User;
use App\Modules\Crm\Models\Call;
use App\Modules\Crm\Services\Calling\Contracts\CallProvider;
use RuntimeException;

/**
 * The free, always-available call transport: the BD's own phone.
 *
 * This is the CRM's DEFAULT and it is not a stub or a fallback-of-last-resort — it is the workflow
 * Zerem has always shipped, now named. It needs no account, no credentials, no webhook and no
 * subscription, and it must keep working forever regardless of what paid providers exist.
 *
 * What it provides is the CRM half of a call: the Planner event, the `crm_calls` row, the Activity
 * timeline entries, the single-active-call guard, the disposition workflow and the call history. What
 * it does not provide is knowledge of the PSTN — so `provider` stays `manual`, `external_call_id`
 * stays null, and the duration remains the CRM's own start→end measurement rather than a carrier's.
 * The UI must not present manual duration as though a carrier reported it.
 *
 * `manual` is also the exact value every pre-existing row already carries, so historical calls and
 * new manual calls are the same kind of record. Nothing is migrated or reinterpreted.
 */
class ManualCallProvider implements CallProvider
{
    public const NAME = 'manual';

    public function name(): string
    {
        return self::NAME;
    }

    /** Always. Requiring configuration for the free path is the failure mode this class prevents. */
    public function isConfigured(): bool
    {
        return true;
    }

    /**
     * Never. Manual is not a transport an organization opts into — it is what runs when no paid one
     * can, which is the property that keeps the CRM working with no telephony account at all.
     */
    public function isSelectedByOrganization(): bool
    {
        return false;
    }

    public function supportsBrowserCalling(): bool
    {
        return false;
    }

    /**
     * Never reachable through the HTTP layer: the token endpoint checks {@see supportsBrowserCalling}
     * and refuses first. Throwing here rather than returning an empty token means a future caller that
     * forgets the check fails loudly in development instead of handing the browser a useless string.
     */
    public function issueBrowserToken(User $user, Call $call): array
    {
        throw new RuntimeException('The manual call provider cannot place browser calls.');
    }
}
