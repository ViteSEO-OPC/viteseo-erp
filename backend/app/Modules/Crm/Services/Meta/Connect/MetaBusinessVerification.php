<?php

declare(strict_types=1);

namespace App\Modules\Crm\Services\Meta\Connect;

/**
 * Whether a supplied credential genuinely speaks for a named Business Portfolio.
 *
 * WHY THIS EXISTS AT ALL. The client OAuth path never has to ask: Meta names the authorizing
 * business itself, through `GET /me?fields=client_business_id` on the token it just issued. The
 * platform bootstrap path has no such luxury — an operator types a Business Portfolio ID into a
 * form and pastes a token beside it, and nothing about those two values proves they belong
 * together. So the server proves it: it reads the business back USING the token, and accepts the
 * id only if the read succeeds and returns that same id.
 *
 * A TYPED ANSWER, NOT A BOOLEAN. "Could not verify" has several causes an operator must tell
 * apart — a token that has expired, a token missing `business_management`, a portfolio id that
 * simply does not match — and each sends them somewhere different.
 */
final class MetaBusinessVerification
{
    private function __construct(
        public readonly bool $verified,
        /** Echoed back from Meta, not from the form. */
        public readonly ?string $businessId = null,
        /** Display name, for the confirmation an operator sees. Never used to resolve anything. */
        public readonly ?string $businessName = null,
        public readonly ?MetaConnectFailureCode $failure = null,
    ) {}

    public static function verified(string $businessId, ?string $businessName = null): self
    {
        return new self(true, businessId: $businessId, businessName: $businessName);
    }

    public static function failed(MetaConnectFailureCode $code): self
    {
        return new self(false, failure: $code);
    }
}
