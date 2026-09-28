<?php

namespace App\Modules\Crm\Services\Discovery\Contracts;

/**
 * Produces CANDIDATE BUSINESS NAMES for an (area, category) pair — nothing more.
 *
 * The narrowness is the security boundary. A generator returns names and token usage; it never
 * returns coordinates, identifiers, phone numbers, websites or anything else that could be mistaken
 * for authoritative business data. Whatever it proposes is a hypothesis that the scraper's
 * deterministic OSM resolver must confirm before a single record can become a CRM prospect.
 *
 * It exists as an interface for one reason: the credential that powers it lives server-side and must
 * stay there. The scraper asks the CRM for names; the CRM holds the key and makes the call. A second
 * implementation (a different model vendor, or a fake for tests) plugs in here without the scraper
 * learning anything new — and without a credential ever crossing the process boundary.
 */
interface ProposalGenerator
{
    /** The call succeeded — `proposals` is the model's real answer, even if it is empty. */
    public const STATUS_OK = 'ok';

    /** The credential was rejected. NOT the same as "no businesses found". */
    public const STATUS_AUTH_FAILED = 'auth_failed';

    public const STATUS_RATE_LIMITED = 'rate_limited';

    /**
     * The credential is valid but the account behind it is out of credit.
     *
     * Kept separate from {@see STATUS_UNREACHABLE} because the two demand opposite responses: an
     * unreachable provider is usually transient and worth retrying later, while this one will fail
     * identically on every future call until a human tops up the account. Collapsing them sends an
     * operator hunting for a network fault that does not exist.
     */
    public const STATUS_INSUFFICIENT_CREDIT = 'insufficient_credit';

    /** Timeout, DNS, socket, or any non-2xx that is not auth/rate-limit. */
    public const STATUS_UNREACHABLE = 'unreachable';

    /** A 2xx whose body could not be understood — a real failure, not an empty result. */
    public const STATUS_INVALID_RESPONSE = 'invalid_response';

    /** No credential stored, so no call was attempted. */
    public const STATUS_NOT_CONFIGURED = 'not_configured';

    /**
     * @return array{status: string, proposals: list<string>, usage: array<string, mixed>, error: string|null}
     *
     * `status` IS THE POINT OF THIS SIGNATURE. An empty `proposals` list is ambiguous on its own —
     * it could mean the model genuinely found nothing in a thin rural category, or it could mean the
     * API key was rejected. Those must never be reported as the same outcome: the first is an
     * ordinary discovery result, the second is a broken integration that an operator has to fix, and
     * collapsing them would let a misconfigured provider look like an honest zero forever.
     *
     * So a failure returns a non-OK `status` with an empty list, and only `STATUS_OK` means the list
     * is the model's actual answer. `error` carries a short, non-sensitive code for the log; it never
     * relays an upstream body, which can quote the request that carried the credential.
     */
    public function propose(string $area, string $category, int $count): array;
}
