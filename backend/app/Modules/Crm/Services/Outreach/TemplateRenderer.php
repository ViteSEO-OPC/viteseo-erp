<?php

namespace App\Modules\Crm\Services\Outreach;

use App\Modules\Crm\Models\Lead;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * RC3 Phase 2 — deterministic template renderer. Substitutes `{{token}}` placeholders from a fixed
 * WHITELIST of lead + sender fields. Purely deterministic — NO AI, NO Blade, NO eval — so the same
 * template + lead always yields the same output. Unknown/mis-typed tokens render to an empty string
 * (never leaked raw to a recipient), which keeps output injection-safe (FROZEN #3 / #5).
 */
class TemplateRenderer
{
    /**
     * @return array{subject: string, body: string}
     */
    public function render(string $subject, string $body, Lead $lead, ?User $sender = null): array
    {
        $map = $this->tokens($lead, $sender);

        return [
            'subject' => $this->substitute($subject, $map),
            'body' => $this->substitute($body, $map),
        ];
    }

    /**
     * The token whitelist. Add a field here (and nowhere else) to expose a new placeholder.
     *
     * @return array<string, string>
     */
    private function tokens(Lead $lead, ?User $sender): array
    {
        $contact = (string) ($lead->contact_name ?? '');

        return [
            'first_name' => $contact !== '' ? (string) Str::of($contact)->trim()->explode(' ')->first() : '',
            'contact_name' => $contact,
            'business_name' => (string) ($lead->business_name ?? ''),
            'industry' => (string) ($lead->industry ?? ''),
            'city' => (string) ($lead->city ?? ''),
            'sender_name' => (string) ($sender->name ?? ''),
        ];
    }

    /**
     * @param  array<string, string>  $map
     */
    private function substitute(string $text, array $map): string
    {
        return (string) preg_replace_callback(
            '/\{\{\s*([a-z_]+)\s*\}\}/',
            static fn (array $m): string => $map[$m[1]] ?? '', // unknown token → blank (deterministic, safe)
            $text,
        );
    }
}
