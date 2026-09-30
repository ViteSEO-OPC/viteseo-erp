<?php

declare(strict_types=1);

namespace App\Modules\Crm\Services\Inbound;

use App\Modules\Crm\Models\Conversation;
use App\Modules\Crm\Models\Lead;

/**
 * Decides which CRM lead — if any — an inbound conversation belongs to.
 *
 * CONSERVATIVE BY DESIGN. A wrong association puts one prospect's private conversation on another
 * company's record, so every strategy here is an EXACT, deterministic identity check. There is no
 * fuzzy business-name matching, no domain-guessing, no "closest match". When the evidence is
 * ambiguous the answer is "no lead", which is a legitimate resting state: a conversation lives
 * perfectly well unmatched until a human decides.
 *
 * Priority, first hit wins:
 *
 *   1. sticky           — the conversation already names a lead. Never re-opened by a later turn.
 *   2. contact_identity — this provider+channel contact id is already linked to exactly one lead.
 *   3. email            — exact, case-insensitive.
 *   4. phone            — exact after PH canonicalization.
 *   5. none             — no signal, or more than one lead matched (recorded as `ambiguous`).
 *
 * EVERY query is scoped by org_id explicitly, on top of the BelongsToOrg global scope. The global
 * scope is the safety net; the explicit clause is the statement of intent, and it means a future
 * refactor that runs this outside a request cannot silently match across tenants.
 *
 * This class NEVER writes: no lead creation, no ownership assignment, no leads.status change.
 */
final class ConversationLeadMatcher
{
    public const STRATEGY_STICKY = 'sticky';

    public const STRATEGY_CONTACT_IDENTITY = 'contact_identity';

    public const STRATEGY_EMAIL = 'email';

    public const STRATEGY_PHONE = 'phone';

    public const STRATEGY_NONE = 'none';

    public const STRATEGY_AMBIGUOUS = 'ambiguous';

    /**
     * @return array{lead_id: int|null, strategy: string}
     */
    public function match(int $orgId, InboundConversationMessage $message, ?Conversation $conversation): array
    {
        if ($conversation?->lead_id !== null) {
            return ['lead_id' => (int) $conversation->lead_id, 'strategy' => self::STRATEGY_STICKY];
        }

        $byContact = $this->byContactIdentity($orgId, $message);
        if ($byContact !== null) {
            return $byContact;
        }

        $byEmail = $this->byEmail($orgId, $message->senderEmail);
        if ($byEmail !== null) {
            return $byEmail;
        }

        $byPhone = $this->byPhone($orgId, $message->senderPhone);
        if ($byPhone !== null) {
            return $byPhone;
        }

        return ['lead_id' => null, 'strategy' => self::STRATEGY_NONE];
    }

    /**
     * The same person, on the same provider channel, already resolved to a lead once.
     *
     * Scoped by provider AND channel: a contact id is issued inside one namespace (a Messenger PSID
     * is page-scoped; an Instagram IGSID is a different system entirely), so the same string from
     * two channels is not evidence of the same person.
     *
     * @return array{lead_id: int|null, strategy: string}|null
     */
    private function byContactIdentity(int $orgId, InboundConversationMessage $message): ?array
    {
        $contactId = $this->trimmedOrNull($message->senderExternalId);
        if ($contactId === null) {
            return null;
        }

        $leadIds = Conversation::query()
            ->where('org_id', $orgId)
            ->where('provider', $message->provider)
            ->where('channel', $message->channel)
            ->where('external_contact_id', $contactId)
            ->whereNotNull('lead_id')
            ->distinct()
            ->pluck('lead_id')
            ->all();

        return $this->decide($leadIds, self::STRATEGY_CONTACT_IDENTITY);
    }

    /** @return array{lead_id: int|null, strategy: string}|null */
    private function byEmail(int $orgId, ?string $email): ?array
    {
        $needle = $this->trimmedOrNull($email);
        if ($needle === null) {
            return null;
        }

        $leadIds = Lead::query()
            ->where('org_id', $orgId)
            ->whereRaw('LOWER(email) = ?', [mb_strtolower($needle)])
            ->pluck('id')
            ->all();

        return $this->decide($leadIds, self::STRATEGY_EMAIL);
    }

    /**
     * Exact match after canonicalization. The coarse SQL filter strips the separators people
     * actually type so a candidate is not missed by formatting; the decision itself is made in PHP
     * on fully canonical values, so "+63 917 123 4567" and "0917-123-4567" are one number and
     * nothing looser than equality ever matches.
     *
     * @return array{lead_id: int|null, strategy: string}|null
     */
    private function byPhone(int $orgId, ?string $phone): ?array
    {
        $canonical = $this->canonicalPhone($this->trimmedOrNull($phone) ?? '');
        if ($canonical === '' || mb_strlen($canonical) < 7) {
            return null;
        }

        $suffix = mb_substr($canonical, -7);

        $candidates = Lead::query()
            ->where('org_id', $orgId)
            ->whereNotNull('phone')
            ->whereRaw(
                "REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(phone, ' ', ''), '-', ''), '(', ''), ')', ''), '.', '') LIKE ?",
                ['%'.$suffix],
            )
            ->get(['id', 'phone']);

        $leadIds = $candidates
            ->filter(fn (Lead $lead): bool => $this->canonicalPhone((string) $lead->phone) === $canonical)
            ->pluck('id')
            ->all();

        return $this->decide($leadIds, self::STRATEGY_PHONE);
    }

    /**
     * Exactly one lead is a match; several is an ambiguity we refuse to guess at; none means this
     * strategy simply had nothing to say and the next one should try.
     *
     * @param  list<int|string>  $leadIds
     * @return array{lead_id: int|null, strategy: string}|null
     */
    private function decide(array $leadIds, string $strategy): ?array
    {
        $unique = array_values(array_unique(array_map('intval', $leadIds)));

        if (count($unique) === 1) {
            return ['lead_id' => $unique[0], 'strategy' => $strategy];
        }

        if (count($unique) > 1) {
            return ['lead_id' => null, 'strategy' => self::STRATEGY_AMBIGUOUS];
        }

        return null;
    }

    /**
     * PH canonicalization, mirroring LeadImportService::normPhone (which is left untouched):
     * digits only, and a +63XXXXXXXXXX form collapses to the local 0XXXXXXXXXX. A bare 10-digit
     * mobile (9XXXXXXXXX), which chat providers and web forms both emit, gains its leading zero so
     * it lands on the same canonical form.
     */
    private function canonicalPhone(string $value): string
    {
        $digits = (string) preg_replace('/\D+/', '', $value);

        if (strlen($digits) === 12 && str_starts_with($digits, '63')) {
            return '0'.substr($digits, 2);
        }

        if (strlen($digits) === 10 && str_starts_with($digits, '9')) {
            return '0'.$digits;
        }

        return $digits;
    }

    private function trimmedOrNull(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }
}
