<?php

namespace App\Modules\Crm\Services\Outreach;

use App\Modules\Crm\Models\EmailTemplate;
use App\Modules\Crm\Support\EmailTemplateCatalog;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Settings → Email Templates writes, and the rules that keep them from breaking the two things that
 * read templates: the Outreach composer and the n8n automation endpoint.
 *
 * ── Automation protection ──
 * AutomationEmailController resolves a template with `where('purpose', $key)` over the active rows and
 * answers 422 when nothing matches. The guards below ask the database that SAME question instead of
 * comparing strings in PHP, so they agree with the resolver on every database — including MySQL, whose
 * case-insensitive collation lets the composer's `Introduction` rows answer for the automation key
 * `introduction`. That collision is known and deliberately not fixed here; the guard simply respects it.
 *
 * Nothing in this class renders or sends. TemplateRenderer, the composer endpoint and the automation
 * resolver are untouched: they read whatever active rows this service leaves behind.
 */
class EmailTemplateManagementService
{
    /** Every template in the current org, active or not. Soft-deleted rows stay out. */
    public function all(): Collection
    {
        return EmailTemplate::query()->orderBy('purpose')->orderBy('tone')->get();
    }

    /**
     * Templates the automation resolver's purpose lookup can reach, by the database's own comparison.
     *
     * @return list<string>
     */
    public function automationTemplateIds(): array
    {
        return EmailTemplate::query()
            ->whereIn('purpose', EmailTemplateCatalog::automationPurposes())
            ->pluck('id')
            ->map(fn (int|string $id): string => (string) $id)
            ->values()
            ->all();
    }

    /** @param array{purpose: string, tone: string, subject: string, body: string, is_active?: bool} $data */
    public function create(array $data, ?int $actorId): EmailTemplate
    {
        $this->assertPairIsFree($data['purpose'], $data['tone']);

        return EmailTemplate::create([
            'purpose' => $data['purpose'],
            'tone' => $data['tone'],
            'subject' => $data['subject'],
            'body' => $data['body'],
            'is_active' => $data['is_active'] ?? true,
            'created_by' => $actorId,
        ]);
    }

    /** @param array{purpose?: string, tone?: string, subject?: string, body?: string, is_active?: bool} $data */
    public function update(EmailTemplate $template, array $data): EmailTemplate
    {
        $purpose = $data['purpose'] ?? $template->purpose;
        $tone = $data['tone'] ?? $template->tone;

        if ($purpose !== $template->purpose) {
            $this->assertPurposeMayChange($template, $purpose);
        }

        if ($purpose !== $template->purpose || $tone !== $template->tone) {
            $this->assertPairIsFree($purpose, $tone, $template->id);
        }

        if (array_key_exists('is_active', $data) && ! $data['is_active']) {
            $this->assertAutomationKeepsATemplate($template, 'is_active');
        }

        $template->update(array_intersect_key($data, array_flip(['purpose', 'tone', 'subject', 'body', 'is_active'])));

        return $template->fresh();
    }

    /** Soft delete, like every other CRM record: the row is kept, it simply stops being offered. */
    public function delete(EmailTemplate $template): void
    {
        $this->assertAutomationKeepsATemplate($template, 'template');

        $template->delete();
    }

    private function assertPurposeMayChange(EmailTemplate $template, string $purpose): void
    {
        if (EmailTemplateCatalog::kindOf($purpose) !== EmailTemplateCatalog::kindOf($template->purpose)) {
            throw ValidationException::withMessages([
                'purpose' => 'A composer template cannot become an automation template, or the reverse. Create a new template instead.',
            ]);
        }

        if ($this->servedAutomationPurposes($template) !== []) {
            throw ValidationException::withMessages([
                'purpose' => 'This template is used by the automation endpoint, so its purpose cannot be changed. Create a new template instead.',
            ]);
        }
    }

    /** One template per purpose and tone — the key the seeder, the composer and the resolver all assume. */
    private function assertPairIsFree(string $purpose, string $tone, ?int $ignoreId = null): void
    {
        $taken = EmailTemplate::query()
            ->where('purpose', $purpose)
            ->where('tone', $tone)
            ->when($ignoreId !== null, fn ($query) => $query->whereKeyNot($ignoreId))
            ->exists();

        if ($taken) {
            throw ValidationException::withMessages([
                'tone' => "A {$purpose} template with the {$tone} tone already exists. Edit that one instead.",
            ]);
        }
    }

    private function assertAutomationKeepsATemplate(EmailTemplate $template, string $field): void
    {
        // An inactive template serves nothing today, so switching it off or removing it cannot break a send.
        if (! $template->is_active) {
            return;
        }

        foreach ($this->servedAutomationPurposes($template) as $purpose) {
            $another = EmailTemplate::query()
                ->active()
                ->where('purpose', $purpose)
                ->whereKeyNot($template->id)
                ->exists();

            if (! $another) {
                throw ValidationException::withMessages([
                    $field => "This is the only active template for the automation purpose '{$purpose}', so automated sends would fail without it. Activate or create another '{$purpose}' template first.",
                ]);
            }
        }
    }

    /**
     * The automation purposes whose resolver query matches this template.
     *
     * @return list<string>
     */
    private function servedAutomationPurposes(EmailTemplate $template): array
    {
        return array_values(array_filter(
            EmailTemplateCatalog::automationPurposes(),
            fn (string $purpose): bool => EmailTemplate::query()
                ->whereKey($template->id)
                ->where('purpose', $purpose)
                ->exists(),
        ));
    }
}
