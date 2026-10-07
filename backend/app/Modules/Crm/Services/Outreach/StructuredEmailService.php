<?php

namespace App\Modules\Crm\Services\Outreach;

use App\Models\User;
use App\Modules\Crm\Models\Email;
use App\Modules\Crm\Models\EmailDesign;
use App\Modules\Crm\Models\EmailTemplate;
use App\Modules\Crm\Models\Lead;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * Per-lead structured emails: a designed Email Template's content copied onto one lead's draft, edited for that
 * lead only, previewed, and sent or scheduled through the existing pipeline.
 *
 * ── The template is only ever read ──
 * Starting an email copies the template's content document, its design and its subject (filled in for this lead)
 * onto a NEW draft row. Every later edit changes that row's `content`; the template — which every other lead is
 * offered — is never written. `email_template_id` records where the email started, nothing more.
 *
 * ── One rendering ──
 * Preview, save, schedule and send all render through EmailTemplateDesignService::structuredHtml() — the same
 * validate → render → Gmail guard → readable-content sequence, in the same send context — so what the BD previews
 * is what the lead receives and what body_html stores. `body` is always the renderer's text form of the current
 * content, so the plain text CRM lists show can never disagree with the structured content.
 *
 * ── Fail closed ──
 * Content that does not validate, a design that is gone, or an email too large for Gmail is refused with a 422 the
 * BD can act on. A structured email is never sent as plain text instead (see OutreachEmailService::sendExisting).
 */
final class StructuredEmailService
{
    public function __construct(
        private readonly EmailTemplateDesignService $designs,
        private readonly TemplateRenderer $templates,
        private readonly ScheduledEmailService $scheduled,
    ) {}

    /** A new draft for `$lead`, holding a copy of the template's content, design and subject. */
    public function createFromTemplate(Lead $lead, User $sender, EmailTemplate $template): Email
    {
        if (! $template->isDesigned()) {
            throw ValidationException::withMessages(['email_template_id' => 'Choose a template made with the visual designer.']);
        }
        if (! $template->is_active) {
            throw ValidationException::withMessages(['email_template_id' => 'This template is inactive. Choose an active template.']);
        }
        $design = $template->design;
        if ($design === null) {
            throw ValidationException::withMessages(['email_template_id' => 'This template\'s email design is no longer available.']);
        }

        // A copy: an array taken by value. Nothing written below can reach the template's own row.
        $content = (array) $template->content;
        $rendered = $this->renderedOrRefuse($design, $content, $lead, $sender, 'email_template_id');

        return Email::create([
            'lead_id' => $lead->id,
            'user_id' => $sender->id,
            'direction' => 'outbound',
            'to_email' => (string) $lead->email,
            'to_name' => $lead->contact_name,
            'subject' => $this->templates->render((string) $template->subject, '', $lead, $sender)['subject'],
            'body' => $rendered['text'],
            'content' => $content,
            'email_template_id' => $template->id,
            'email_design_id' => $design->id,
            'status' => 'draft',
        ]);
    }

    /**
     * Save a structured draft's subject, recipient, design and content.
     *
     * @param  array{subject: string, to?: string|null, email_design_id: int|string, content: string}  $data
     */
    public function save(Email $email, User $sender, array $data): Email
    {
        $this->assertStructured($email);
        $lead = $this->leadOf($email);
        $design = EmailDesign::query()->findOrFail((int) $data['email_design_id']);
        $content = $this->validContent($data['content'], $design);
        $rendered = $this->renderedOrRefuse($design, $content, $lead, $sender, 'content');

        $email->update([
            'subject' => $data['subject'],
            'to_email' => ($data['to'] ?? null) !== null && $data['to'] !== '' ? $data['to'] : $email->to_email,
            'email_design_id' => $design->id,
            'content' => $content,
            'body' => $rendered['text'],
        ]);

        return $email;
    }

    /**
     * The preview of unsaved content in a design for this lead and sender — the sent HTML, byte for byte.
     *
     * @return array{subject: string, html: string, text: string, htmlBytes: int, maxHtmlBytes: int}
     */
    public function preview(Lead $lead, User $sender, EmailDesign $design, string $json, string $subject): array
    {
        $content = $this->validContent($json, $design);
        $rendered = $this->renderedOrRefuse($design, $content, $lead, $sender, 'content');

        return [
            'subject' => $this->templates->render($subject, '', $lead, $sender)['subject'],
            'html' => (string) $rendered['html'],
            'text' => (string) $rendered['text'],
            'htmlBytes' => (int) $rendered['bytes'],
            'maxHtmlBytes' => EmailTemplateDesignService::MAX_HTML_BYTES,
        ];
    }

    /** Schedule a saved structured draft; refused now, not at delivery, if it could not be rendered. */
    public function schedule(Email $email, User $sender, Carbon $scheduledAt): Email
    {
        $this->assertStructured($email);
        $design = $email->emailDesign;
        if ($design === null || $design->trashed()) {
            throw ValidationException::withMessages(['email_design_id' => 'This email\'s design is no longer available. Choose another design.']);
        }
        $this->renderedOrRefuse($design, (array) $email->content, $this->leadOf($email), $sender, 'content');

        return $this->scheduled->scheduleDraft($email, $scheduledAt);
    }

    /**
     * Both parts of a structured email as the send renders them, or a 422 under `$field`.
     *
     * @param  array<string, mixed>  $content
     * @return array{html: string, text: string, reason: null, bytes: int}
     */
    private function renderedOrRefuse(EmailDesign $design, array $content, Lead $lead, User $sender, string $field): array
    {
        $rendered = $this->designs->structuredHtml($design, $content, $lead, $sender);
        if ($rendered['html'] === null || $rendered['text'] === null) {
            throw ValidationException::withMessages([$field => EmailTemplateDesignService::structuredRefusalMessage((string) $rendered['reason'])]);
        }

        /** @var array{html: string, text: string, reason: null, bytes: int} $rendered */
        return $rendered;
    }

    /**
     * A content document held to every rule a template's content is held to in Settings — structure, size,
     * Gmail guard, readable content — with problems keyed like `content.blocks.3.items.1.title`.
     *
     * @return array<string, mixed>
     */
    private function validContent(string $json, EmailDesign $design): array
    {
        $problem = $this->designs->contentProblem($json, $design->layout);
        if ($problem !== null) {
            throw ValidationException::withMessages([$problem['key'] => $problem['message']]);
        }

        return $this->designs->decode($json);
    }

    private function assertStructured(Email $email): void
    {
        if ($email->content === null) {
            throw ValidationException::withMessages(['email' => 'This email was not started from a designed template.']);
        }
    }

    private function leadOf(Email $email): Lead
    {
        $lead = $email->lead;
        if ($lead === null) {
            throw ValidationException::withMessages(['lead' => 'This lead no longer exists.']);
        }

        return $lead;
    }
}
