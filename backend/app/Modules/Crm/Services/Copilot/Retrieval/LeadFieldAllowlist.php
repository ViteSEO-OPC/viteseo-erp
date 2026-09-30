<?php

namespace App\Modules\Crm\Services\Copilot\Retrieval;

/**
 * THE CODE-OWNED DECISION of which `crm_leads` columns may reach the Copilot's model.
 *
 * Every column is in exactly one class, and LeadScopedDataTest fails when the table gains a column
 * this file has not classified — so a new column can never reach the model by default.
 *
 *   SENDABLE           — may be read and supplied to the model.
 *   AUTHORIZATION_ONLY — read to run LeadPolicy::view; never supplied.
 *   NEVER_SENDABLE     — personal data, location, links, owner and tenancy. Locked by the PO for all
 *                        of Phase 2; moving one out is a policy change, not a code change.
 *   NOT_YET_REVIEWED   — not sendable until a later slice moves it to SENDABLE with its own tests.
 */
final class LeadFieldAllowlist
{
    public const SENDABLE = ['business_name'];

    public const AUTHORIZATION_ONLY = ['id', 'assigned_to'];

    public const NEVER_SENDABLE = [
        'contact_name', 'email', 'phone',
        'address', 'latitude', 'longitude', 'place_id',
        'website', 'facebook', 'instagram', 'tiktok', 'linkedin', 'shopee', 'lazada', 'tiktok_shop', 'other_marketplace',
        'created_by', 'qualified_by',
        'org_id', 'deleted_at',
    ];

    public const NOT_YET_REVIEWED = [
        'industry', 'website_status', 'priority', 'email_outreach', 'cold_calling', 'status',
        'last_activity', 'score', 'amount',
        'region', 'province', 'city',
        'source', 'discovery_ai_assisted', 'acquisition_channel', 'qualified_at',
        'created_at', 'updated_at',
    ];

    /** Contact-shaped text inside a free-text field: emails, links and bare domains, phone numbers. */
    private const CONTACT_SHAPED = [
        '/[a-z0-9._%+-]+@[a-z0-9.-]+\.[a-z]{2,}/iu',
        '/\b(?:https?:\/\/|www\.)\S+/iu',
        '/\b[a-z0-9-]+(?:\.[a-z0-9-]+)*\.(?:com|net|org|ph|io|ai|dev|app|co|xyz|info|biz|example)\b\S*/iu',
        '/\+?\d(?:[\s().-]*\d){7,}/u',
    ];

    /**
     * A sendable free-text value with anything contact-shaped removed. A business name is typed by
     * people and filled by imports; the model must not receive a phone number or address hidden in
     * one. Ordinary names ("7-Eleven", "Hardware 101") are left as they are.
     */
    public static function scrub(string $value): string
    {
        $value = preg_replace(self::CONTACT_SHAPED, '', $value) ?? '';

        return trim(preg_replace('/\s{2,}/u', ' ', $value) ?? '', " \t\n\r\0\x0B,;:");
    }
}
