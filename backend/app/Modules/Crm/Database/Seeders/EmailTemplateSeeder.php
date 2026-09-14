<?php

namespace App\Modules\Crm\Database\Seeders;

use App\Modules\Crm\Models\EmailTemplate;
use Illuminate\Database\Seeder;

/**
 * RC3 Phase 2 — default deterministic email templates, keyed by Purpose + Tone, covering the Outreach
 * composer's dropdown combinations so every selection resolves to a real template. These are editable
 * content defaults (configuration), not fabricated lead data. Placeholders are deterministic {{tokens}}
 * rendered by TemplateRenderer (whitelist: first_name, contact_name, business_name, industry, city,
 * sender_name).
 *
 * Installs MISSING templates only, keyed on (purpose, tone). Templates are edited in Settings → Email
 * Templates, so a reseed or a re-provisioned org must never overwrite an admin's subject, body or
 * active state — and `withTrashed()` means a template an admin deleted is not brought back either.
 *
 * On MySQL the case-insensitive collation still folds the automation `introduction` row into the
 * composer's `Introduction` · Warm & professional row. It used to overwrite that row's content with the
 * automation copy; it now leaves whichever arrived first. The collision itself is a known, unfixed issue.
 */
class EmailTemplateSeeder extends Seeder
{
    public function run(): void
    {
        foreach ($this->templates() as $t) {
            EmailTemplate::withTrashed()->firstOrCreate(
                ['purpose' => $t['purpose'], 'tone' => $t['tone']],
                ['subject' => $t['subject'], 'body' => $t['body'], 'is_active' => true],
            );
        }
    }

    /**
     * @return list<array{purpose: string, tone: string, subject: string, body: string}>
     */
    private function templates(): array
    {
        // Signature by tone, reused across purposes for consistency.
        $sign = [
            'Warm & professional' => "Best regards,\n{{sender_name}}",
            'Formal' => "Sincerely,\n{{sender_name}}",
            'Casual' => "Cheers,\n{{sender_name}}",
        ];
        $greet = [
            'Warm & professional' => 'Hi {{first_name}},',
            'Formal' => 'Dear {{contact_name}},',
            'Casual' => 'Hey {{first_name}},',
        ];

        $bodies = [
            'Proposal Check-in' => "just checking in on the proposal we shared for {{business_name}}. I'd be happy to walk you through it and answer any questions.\n\nWould a quick call this week work for you?",
            'Follow-up' => "following up on our recent conversation about {{business_name}}. I'd love to hear your thoughts and figure out the best next step.\n\nIs there a good time to connect?",
            'Introduction' => "I came across {{business_name}} and thought there may be a strong fit with what we do for {{industry}} businesses. I'd welcome the chance to introduce ourselves.\n\nWould you be open to a short intro call?",
        ];
        $subjects = [
            'Proposal Check-in' => 'Quick check-in on your proposal, {{first_name}}',
            'Follow-up' => 'Following up, {{first_name}}',
            'Introduction' => 'A quick hello from our team to {{business_name}}',
        ];

        $rows = [];
        foreach ($subjects as $purpose => $subject) {
            foreach ($sign as $tone => $signature) {
                $rows[] = [
                    'purpose' => $purpose,
                    'tone' => $tone,
                    'subject' => $subject,
                    'body' => $greet[$tone]."\n\n".$bodies[$purpose]."\n\n".$signature,
                ];
            }
        }

        return [...$rows, ...$this->automationTemplates()];
    }

    /**
     * RC3 Outreach Automation Pack — machine-keyed templates the automation send endpoint selects by
     * `purpose` (the values in AutomationSendEmailRequest::PURPOSES). Tone 'Warm & professional' matches
     * the automation default. These are content defaults (configuration), deterministic {{tokens}} only.
     *
     * @return list<array{purpose: string, tone: string, subject: string, body: string}>
     */
    private function automationTemplates(): array
    {
        $tone = 'Warm & professional';
        $greeting = 'Hi {{first_name}},';
        $signature = "Best regards,\n{{sender_name}}";

        $content = [
            'proposal' => [
                'subject' => 'Your proposal from {{sender_name}}',
                'body' => "I've put together a proposal for {{business_name}} and would love to walk you through it. Please take a look and let me know your thoughts.",
            ],
            'proposal_follow_up' => [
                'subject' => 'Following up on your proposal, {{first_name}}',
                'body' => "just following up on the proposal we shared for {{business_name}}. I'd be happy to answer any questions.\n\nIs there a good time this week to connect?",
            ],
            'introduction' => [
                'subject' => 'A quick hello from {{sender_name}}',
                'body' => "I came across {{business_name}} and thought there may be a strong fit with what we do for {{industry}} businesses.\n\nWould you be open to a short intro call?",
            ],
            'meeting_reminder' => [
                'subject' => 'Reminder: our upcoming meeting, {{first_name}}',
                'body' => 'a quick reminder about our upcoming meeting regarding {{business_name}}. Looking forward to speaking with you.',
            ],
            'thank_you' => [
                'subject' => 'Thank you, {{first_name}}!',
                'body' => "thank you for choosing to work with us — we're thrilled to partner with {{business_name}}. We'll be in touch shortly with the next steps.",
            ],
        ];

        $rows = [];
        foreach ($content as $purpose => $c) {
            $rows[] = [
                'purpose' => $purpose,
                'tone' => $tone,
                'subject' => $c['subject'],
                'body' => $greeting."\n\n".$c['body']."\n\n".$signature,
            ];
        }

        return $rows;
    }
}
