<?php

namespace App\Modules\Crm\Support;

use App\Modules\Crm\Controllers\EmailTemplateController;
use App\Modules\Crm\Requests\AutomationSendEmailRequest;
use App\Modules\Crm\Services\Outreach\TemplateRenderer;

/**
 * The controlled vocabulary behind Settings → Email Templates: the purposes and tones a template may
 * carry, the `{{tokens}}` it may use, and the sample values the preview renders them with.
 *
 * ── Two populations, one table ──
 * `crm_email_templates` holds the Outreach composer's templates (Title Case purposes) and the n8n
 * automation endpoint's templates (snake_case keys) with no column separating them. The KIND is derived
 * here from the purpose, case-sensitively, the same way {@see EmailTemplateController} filters them.
 *
 * ── Why two of these lists are copies ──
 * The composer purposes are a private constant on the frozen RC3 {@see EmailTemplateController}, and the
 * token whitelist is private to {@see TemplateRenderer}. Neither is edited for this surface: instead
 * EmailTemplateManagementTest reads both by reflection and fails the moment either drifts from this
 * class. The automation purposes are public, so they are referenced rather than copied.
 */
final class EmailTemplateCatalog
{
    public const KIND_COMPOSER = 'composer';

    public const KIND_AUTOMATION = 'automation';

    /** The purposes the Outreach composer offers a BD. */
    public const COMPOSER_PURPOSES = ['Proposal Check-in', 'Follow-up', 'Introduction'];

    /** The seeded tone set. The automation endpoint defaults to the first. */
    public const TONES = ['Warm & professional', 'Formal', 'Casual'];

    /** `crm_email_templates.body` is TEXT (65,535 bytes); 10,000 characters fits at four bytes each. */
    public const BODY_MAX = 10000;

    /**
     * Placeholder → what it means and the CRM sample value the preview substitutes. The keys are exactly
     * TemplateRenderer's whitelist; nothing else is ever substituted into a sent email.
     */
    public const TOKENS = [
        'first_name' => ['label' => 'Contact first name', 'sample' => 'Maria'],
        'contact_name' => ['label' => 'Contact full name', 'sample' => 'Maria Santos'],
        'business_name' => ['label' => 'Business name', 'sample' => 'Santos Bakery'],
        'industry' => ['label' => 'Industry', 'sample' => 'Bakery'],
        'city' => ['label' => 'City', 'sample' => 'Quezon City'],
        'sender_name' => ['label' => 'Sender name', 'sample' => 'Alex Reyes'],
    ];

    /** @return list<string> */
    public static function automationPurposes(): array
    {
        return AutomationSendEmailRequest::PURPOSES;
    }

    /** @return list<string> */
    public static function purposes(): array
    {
        return [...self::COMPOSER_PURPOSES, ...self::automationPurposes()];
    }

    public static function kindOf(string $purpose): string
    {
        return in_array($purpose, self::automationPurposes(), true) ? self::KIND_AUTOMATION : self::KIND_COMPOSER;
    }

    /**
     * Every `{{…}}` in $text that TemplateRenderer would not substitute. The renderer blanks an unknown
     * lowercase token but leaves anything else — `{{FirstName}}`, `{{ first name }}` — in the sent email
     * verbatim, so both kinds are refused before they can be saved.
     *
     * @return list<string>
     */
    public static function unknownTokens(string $text): array
    {
        preg_match_all('/\{\{(.*?)\}\}/s', $text, $matches);

        $unknown = [];
        foreach ($matches[1] as $i => $inner) {
            $key = trim($inner);
            if (preg_match('/^[a-z_]+$/', $key) !== 1 || ! array_key_exists($key, self::TOKENS)) {
                $unknown[] = $matches[0][$i];
            }
        }

        return array_values(array_unique($unknown));
    }

    /** A lone `{{` or `}}` left over once every complete placeholder is removed — a half-typed token. */
    public static function hasStrayBraces(string $text): bool
    {
        $rest = (string) preg_replace('/\{\{.*?\}\}/s', '', $text);

        return str_contains($rest, '{{') || str_contains($rest, '}}');
    }

    /**
     * The vocabulary as the Settings page consumes it.
     *
     * @return array{purposes: list<array{value: string, kind: string}>, tones: list<string>, tokens: list<array{key: string, label: string, sample: string}>}
     */
    public static function options(): array
    {
        return [
            'purposes' => array_map(
                fn (string $purpose): array => ['value' => $purpose, 'kind' => self::kindOf($purpose)],
                self::purposes(),
            ),
            'tones' => self::TONES,
            'tokens' => array_map(
                fn (string $key, array $token): array => ['key' => $key, ...$token],
                array_keys(self::TOKENS),
                self::TOKENS,
            ),
        ];
    }
}
