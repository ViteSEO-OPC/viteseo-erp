<?php

namespace App\Modules\Crm\Services\Outreach;

use App\Models\Org;
use App\Models\User;
use App\Modules\Crm\Models\EmailDesign;
use App\Modules\Crm\Models\EmailTemplate;
use App\Modules\Crm\Models\Lead;
use App\Modules\Crm\Support\EmailDesign\EmailDesignContext;
use App\Modules\Crm\Support\EmailDesign\EmailDesignRenderer;
use App\Modules\Crm\Support\EmailDesign\EmailDesignSchema;
use App\Modules\Crm\Support\EmailDesign\EmailDesignValidator;
use App\Modules\Crm\Support\EmailTemplateCatalog;
use App\Support\CurrentOrg;
use App\Support\Mail\Module;
use App\Support\Mail\ModuleSenderResolver;

/**
 * Designed email templates: whether a design layout or a template's content may be saved, the plain-text
 * body stored beside the content, and the Settings previews.
 *
 * ── One renderer ──
 * The previews, the size guard and the stored plain-text body all come from EmailDesignRenderer, composing
 * a design's layout with a template's content — the same class the send path will call — and the preview
 * subject from TemplateRenderer, which sends subjects today. Nothing here writes HTML itself, so what an
 * admin previews is what a recipient will be sent.
 *
 * ── What "content may be saved" means, in order ──
 * 1. The content validates (EmailDesignValidator: bytes → depth → structure).
 * 2. Shown in its design, its HTML stays under the Gmail guard.
 * 3. Shown in its design, its plain-text form fits the `body` column.
 * 4. It has readable content of its own: a heading, text or a button label with at least one visible
 *    character. Dividers, spacers and images are layout, and a sign-off override is not a message — the
 *    design's header, signature and footer never make an empty template readable.
 *
 * ── The Gmail guard ──
 * Gmail shows "[Message clipped]" and hides the rest of any email whose HTML is over about 102 KB. The guard
 * renders the email with the catalog's sample values, a full-length stand-in URL for every image and the
 * logo, and a stand-in organization website, and refuses anything over MAX_HTML_BYTES. The margin below
 * 102 KB is headroom for real lead values, which are often longer than the samples.
 */
class EmailTemplateDesignService
{
    public const MAX_HTML_BYTES = 90_000;

    public const HTML_TOO_LARGE = 'This email is too large for Gmail, which cuts off emails over about 102 KB. Remove some content.';

    public const TEXT_TOO_LONG = 'The plain-text version of this email is longer than 10,000 characters. Remove some text.';

    public const NO_READABLE_CONTENT = 'Add readable content — a heading, text or a button. Dividers, spacers and images alone are not an email.';

    public const REPLY_TOO_LARGE = 'This reply is too large to send in the selected design. Shorten it or choose No design.';

    public const REPLY_DESIGN_UNUSABLE = 'The selected design cannot be used right now. Choose another design or No design.';

    /** A designed reply's text size: the design text block's usual size, as in the Settings sample. */
    private const REPLY_FONT_SIZE = 14;

    /** The subject the design preview shows, since a design has no subject of its own. */
    public const PREVIEW_SUBJECT = 'A sample email';

    /** Anything a reader would see: not whitespace, a non-breaking space or a zero-width character. */
    private const VISIBLE_CHARACTER = '/[^\s\x{00A0}\x{200B}-\x{200D}\x{2060}\x{FEFF}]/u';

    /** A full-length public asset URL, for measuring only — it is never rendered to anyone. */
    private const STAND_IN_ASSET_URL = 'https://assets.example.invalid/api/v1/crm/email-assets/%s.png';

    private const STAND_IN_LOGO_ID = '00000000-0000-4000-8000-000000000000';

    /** A realistic organization website, so a logo's default link is measured too. */
    private const STAND_IN_WEBSITE = 'https://www.organization-website.example/';

    /** The sample sender's phone in Settings previews and the Gmail guard's measurement. */
    private const SAMPLE_SENDER_PHONE = '+63 917 123 4567';

    public function __construct(
        private readonly EmailDesignValidator $validator,
        private readonly EmailDesignRenderer $renderer,
        private readonly TemplateRenderer $templates,
        private readonly EmailBrandingService $branding,
        private readonly ModuleSenderResolver $senders,
    ) {}

    /**
     * Null when a design layout may be saved; otherwise the validation error key — `layout`, or `layout.` and
     * the path inside it, e.g. `layout.header.0.link_url` — and a message safe to show.
     *
     * @return array{key: string, message: string}|null
     */
    public function layoutProblem(string $json): ?array
    {
        $problem = $this->validator->layoutProblemInJson($json);

        return $problem === null ? null : $this->keyed('layout', $problem);
    }

    /**
     * Null when a template's content may be saved in the design with `$layout`; otherwise the validation
     * error key — `content`, or `content.` and the path inside it — and a message safe to show.
     *
     * @param  array<string, mixed>  $layout  a stored, valid design layout
     * @return array{key: string, message: string}|null
     */
    public function contentProblem(string $json, array $layout): ?array
    {
        $problem = $this->validator->contentProblemInJson($json);
        if ($problem !== null) {
            return $this->keyed('content', $problem);
        }

        $content = $this->decode($json);

        $message = $this->composedProblem($layout, $content);
        if ($message !== null) {
            return ['key' => 'content', 'message' => $message];
        }

        return $this->hasReadableContent($content) ? null : ['key' => 'content', 'message' => self::NO_READABLE_CONTENT];
    }

    /**
     * Null when the email a valid layout and content make fits Gmail and the `body` column; otherwise why not.
     * Used for new content, and for existing content whenever its design changes.
     *
     * @param  array<string, mixed>  $layout
     * @param  array<string, mixed>  $content
     */
    public function composedProblem(array $layout, array $content): ?string
    {
        if ($this->htmlBytes($layout, $content) > self::MAX_HTML_BYTES) {
            return self::HTML_TOO_LARGE;
        }

        return mb_strlen($this->plainTextBody($layout, $content)) > EmailTemplateCatalog::BODY_MAX ? self::TEXT_TOO_LONG : null;
    }

    /**
     * Only for JSON that has already been accepted.
     *
     * @return array<string, mixed>
     */
    public function decode(string $json): array
    {
        return json_decode($json, true, EmailDesignSchema::MAX_DEPTH + 1, JSON_THROW_ON_ERROR);
    }

    /**
     * The `body` stored beside a template's content: the plain-text form of the whole email — design and
     * content together — with every placeholder left in place, so TemplateRenderer fills it in for each lead
     * exactly as it does a hand-written body.
     *
     * It is a snapshot, for the readers of `body` that do not understand designs. It is never the source of
     * truth: it is regenerated whenever the content or the design changes, and sending a designed email will
     * generate its text part from the documents themselves.
     *
     * @param  array<string, mixed>  $layout
     * @param  array<string, mixed>  $content
     */
    public function plainTextBody(array $layout, array $content): string
    {
        return $this->renderer->toText($layout, $content, EmailDesignContext::preservingPlaceholders(EmailDesignSchema::tokenKeys()));
    }

    /**
     * The HTML size the Gmail guard measures: sample values, a stand-in URL for every image and the logo, and
     * a stand-in organization website for the logo's default link.
     *
     * @param  array<string, mixed>  $layout
     * @param  array<string, mixed>  $content
     */
    public function htmlBytes(array $layout, array $content): int
    {
        $assetUrls = [];
        foreach (EmailDesignSchema::referencedAssetIds($layout, $content) as $id) {
            $assetUrls[$id] = sprintf(self::STAND_IN_ASSET_URL, $id);
        }

        return strlen($this->renderer->toHtml($layout, $content, new EmailDesignContext(
            tokens: $this->sampleTokens(),
            assetUrls: $assetUrls,
            logoUrl: sprintf(self::STAND_IN_ASSET_URL, self::STAND_IN_LOGO_ID),
            logoAlt: 'Company logo',
            brandWebsite: self::STAND_IN_WEBSITE,
            organizationName: $this->organizationName(),
            organizationEmail: $this->organizationEmail(),
            senderPhone: self::SAMPLE_SENDER_PHONE,
        )));
    }

    /**
     * An email rendered for Settings with the catalog's sample values.
     *
     * @param  array<string, mixed>  $layout
     * @param  array<string, mixed>  $content
     * @return array{subject: string, html: string, text: string, htmlBytes: int, maxHtmlBytes: int}
     */
    public function preview(array $layout, array $content, string $subject): array
    {
        $context = $this->sampleContext();

        return [
            'subject' => $this->templates->render($subject, '', $this->sampleLead(), $this->sampleSender())['subject'],
            'html' => $this->renderer->toHtml($layout, $content, $context),
            'text' => $this->renderer->toText($layout, $content, $context),
            'htmlBytes' => $this->htmlBytes($layout, $content),
            'maxHtmlBytes' => self::MAX_HTML_BYTES,
        ];
    }

    /**
     * The preview's context: the catalog's sample values and the organization's CURRENT logo, resolved through
     * its branding — so every design and template previews whatever logo the branding points at now. The logo
     * is given by its public URL, with its PNG bytes for the preview to show inline (the preview frame loads
     * no remote images). Image blocks have no uploads yet, so the preview omits them.
     */
    public function sampleContext(): EmailDesignContext
    {
        $logo = $this->branding->logoForRender();

        return new EmailDesignContext(
            tokens: $this->sampleTokens(),
            logoUrl: $logo['url'] ?? null,
            logoAlt: $logo['alt'] ?? '',
            previewImages: $logo === null ? [] : [$logo['url'] => $logo['png']],
            organizationName: $this->organizationName(),
            organizationEmail: $this->organizationEmail(),
            senderPhone: self::SAMPLE_SENDER_PHONE,
        );
    }

    /**
     * The context a REAL send renders with — the preview's context with real values in place of samples.
     *
     * THE ONLY DIFFERENCES FROM {@see sampleContext()} ARE THE TOKENS AND THE SENDER'S PHONE (the sample
     * sender's stands in for a preview), and that is the whole point: if the
     * two contexts diverged anywhere else, the preview would stop being a promise about the email. Logo
     * resolution is the same call on the same branding, `assetUrls` is empty in both until image uploads
     * exist, and `brandStyles`/`brandWebsite` are empty in both because `crm_email_brandings` stores only
     * a logo today.
     *
     * `previewImages` IS DELIBERATELY EMPTY AND MUST STAY SO. It exists for the Settings preview frame,
     * which loads no remote images and so needs the logo's bytes inline. A real email client gets the
     * public URL; Gmail blocks inline data, so sending those bytes would hide the logo it is meant to show.
     */
    public function sendContext(Lead $lead, ?User $sender): EmailDesignContext
    {
        $logo = $this->branding->logoForRender();

        return new EmailDesignContext(
            tokens: $this->templates->tokens($lead, $sender),
            logoUrl: $logo['url'] ?? null,
            logoAlt: $logo['alt'] ?? '',
            previewImages: [],
            organizationName: $this->organizationName(),
            organizationEmail: $this->organizationEmail(),
            senderPhone: $sender?->phone,
        );
    }

    /**
     * The HTML part a designed template sends to this lead from this sender — or null, and why not.
     *
     * THE ONE RENDERING SEQUENCE for a real recipient, shared by the send path
     * (OutreachEmailService::designedHtmlFor) and the composer's per-lead preview, so the preview is the
     * sent HTML, not a second rendering that resembles it: the same documents, re-validated rather than
     * trusted, rendered by the same renderer in the same {@see sendContext()}, and held to the same Gmail
     * guard re-run with this lead's real values.
     *
     * Deliberately NOT here: the edit check. Whether a message's text is still the template's own is a
     * question about one email row, so the send path keeps deciding it before it calls this. A preview is
     * only offered while the composer's text is unedited, which is the same condition.
     *
     * Reasons, as the send path logs them: `template_not_designed`, `design_missing`, `document_invalid`,
     * `html_too_large` (which also reports the size it measured).
     *
     * @return array{html: string|null, reason: string|null, bytes: int|null}
     */
    public function designedHtml(EmailTemplate $template, Lead $lead, ?User $sender): array
    {
        $refused = static fn (string $reason, ?int $bytes = null): array => ['html' => null, 'reason' => $reason, 'bytes' => $bytes];

        if (! $template->isDesigned()) {
            return $refused('template_not_designed');
        }

        $design = $template->design;
        if ($design === null) {
            return $refused('design_missing');
        }

        return $this->renderChecked($design->layout, $template->content, $this->sendContext($lead, $sender));
    }

    /**
     * A Lead Details reply's free-form document as design content: one text block, nothing else. The design
     * supplies everything around it — header, logo, styles, signature, footer — exactly as it is saved.
     *
     * The reply's document is a strict subset of what a text block accepts (EmailDocument::NODES/MARKS ⊂
     * EmailDesignSchema::TEXT_CHILDREN/MARK_TAGS), so a valid reply needs no conversion to fit.
     *
     * @param  array<string, mixed>  $doc  a reply document that passed EmailDocument::problem()
     * @return array<string, mixed>
     */
    public function replyContent(array $doc): array
    {
        return [
            'version' => EmailDesignSchema::VERSION,
            'blocks' => [
                ['type' => 'text', 'id' => 'reply', 'content' => $doc, 'align' => 'left', 'font_size' => self::REPLY_FONT_SIZE],
            ],
        ];
    }

    /**
     * Both parts of a Lead Details reply sent in an existing Email Design — or nulls, and why not.
     *
     * THE ONE SEQUENCE FOR A DESIGNED REPLY, shared by the reply's preview and its send, so the preview IS
     * the sent HTML: the same design layout and reply content, re-validated, rendered by the same renderer in
     * the same {@see sendContext()}, and held to the same Gmail guard as a designed template
     * ({@see designedHtml()}). The text/plain part is the renderer's text form of the same email, so the
     * design's signature reaches a plain-text reader too.
     *
     * Reasons: `document_invalid`, `html_too_large` (with the size measured). Callers refuse the send on
     * either — a BD who chose a design is never silently sent plain text.
     *
     * @param  array<string, mixed>  $doc  a reply document that passed EmailDocument::problem()
     * @return array{html: string|null, text: string|null, reason: string|null, bytes: int|null}
     */
    public function designedReplyHtml(EmailDesign $design, array $doc, Lead $lead, ?User $sender): array
    {
        $content = $this->replyContent($doc);
        $context = $this->sendContext($lead, $sender);
        $rendered = $this->renderChecked($design->layout, $content, $context);

        return [
            ...$rendered,
            'text' => $rendered['html'] === null ? null : $this->renderer->toText($design->layout, $content, $context),
        ];
    }

    /** What a BD is told when the design they chose cannot carry this reply, by refusal reason. */
    public static function replyRefusalMessage(string $reason): string
    {
        return $reason === 'html_too_large' ? self::REPLY_TOO_LARGE : self::REPLY_DESIGN_UNUSABLE;
    }

    /**
     * Validate → render → Gmail guard, for a design layout and content in a real send context.
     *
     * @return array{html: string|null, reason: string|null, bytes: int|null}
     */
    private function renderChecked(mixed $layout, mixed $content, EmailDesignContext $context): array
    {
        // The documents have been at rest in the database since they were accepted, so they are
        // re-validated rather than trusted.
        if ($this->validator->layoutProblem($layout) !== null || $this->validator->contentProblem($content) !== null) {
            return ['html' => null, 'reason' => 'document_invalid', 'bytes' => null];
        }

        $html = $this->renderer->toHtml($layout, $content, $context);

        // THE GMAIL GUARD, RE-RUN WITH THIS LEAD'S REAL VALUES. Settings measures the same email with
        // sample values, and a real business name is often longer. Gmail clips over ~102 KB and hides
        // the rest behind "[Message clipped]", so an email that would be truncated is sent as plain
        // text instead: a complete message a person can read beats a designed one they cannot.
        if (strlen($html) > self::MAX_HTML_BYTES) {
            return ['html' => null, 'reason' => 'html_too_large', 'bytes' => strlen($html)];
        }

        return ['html' => $html, 'reason' => null, 'bytes' => strlen($html)];
    }

    /**
     * What a design is previewed with: a short message standing in for a template's content.
     *
     * DELIBERATELY NO BUTTON, and that is the point of this docblock.
     *
     * The sample used to include a "Book a call" button pointing at example.com. It read as a saved
     * part of the design: a designer looking at the preview could not tell the stand-in message from
     * their own blocks, and reasonably concluded their design already carried a call to action with a
     * working link. It did not — the link went nowhere, and nothing in their design contained it.
     *
     * A heading and a paragraph are enough to show how a design frames a message. A BUTTON IS NOT,
     * because a button is the one sample block that is also something a designer can genuinely add to
     * a design (header or footer), so the fake one and the real one are indistinguishable on screen.
     * The stand-in must never be mistakable for the thing it stands in for.
     *
     * Real design-level buttons are unaffected: a button saved into a design's header or footer is
     * part of the layout, renders here like any other block, and reaches the recipient.
     *
     * @return array<string, mixed>
     */
    public function sampleContent(): array
    {
        return [
            'version' => EmailDesignSchema::VERSION,
            'blocks' => [
                ['type' => 'heading', 'id' => 'sample-heading', 'level' => 2, 'text' => 'Hi {{first_name}},', 'align' => 'left'],
                [
                    'type' => 'text',
                    'id' => 'sample-text',
                    'content' => ['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [
                        ['type' => 'text', 'text' => 'Each template shows its own message here. We would love to help {{business_name}} grow online.'],
                    ]]]],
                    'align' => 'left',
                    'font_size' => 14,
                ],
            ],
        ];
    }

    /**
     * Whether the content's own blocks carry words a reader sees. Decided per block, not from the plain-text
     * form: a divider writes a rule of dashes there, and that is layout, not content.
     *
     * @param  array<string, mixed>  $content
     */
    private function hasReadableContent(array $content): bool
    {
        foreach ($content['blocks'] as $block) {
            if ($this->isReadable($block)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether a validated block carries visible text. A card counts through its eyebrow, title or any of its
     * blocks — one level, since a card never holds a card.
     *
     * @param  array<string, mixed>  $block
     */
    private function isReadable(array $block): bool
    {
        return match ($block['type']) {
            'heading' => $this->isVisible($block['text']),
            'button' => $this->isVisible($block['label']),
            'text' => $this->documentHasVisibleText($block['content']),
            'rows' => array_any($block['items'], fn (array $item): bool => $this->isVisible($item['title']) || $this->isVisible((string) ($item['description'] ?? ''))),
            'card' => $this->isVisible((string) ($block['eyebrow'] ?? '')) || $this->isVisible((string) ($block['title'] ?? ''))
                || array_any($block['blocks'], fn (array $inner): bool => $this->isReadable($inner)),
            // divider · spacer · image
            default => false,
        };
    }

    /** @param  array<string, mixed>  $node  a validated rich-text node, so its depth is already bounded */
    private function documentHasVisibleText(array $node): bool
    {
        if ($node['type'] === 'text') {
            return $this->isVisible($node['text']);
        }

        foreach ($node['content'] ?? [] as $child) {
            if ($this->documentHasVisibleText($child)) {
                return true;
            }
        }

        return false;
    }

    private function isVisible(string $text): bool
    {
        return preg_match(self::VISIBLE_CHARACTER, $text) === 1;
    }

    /**
     * @param  array{path: string, message: string}  $problem
     * @return array{key: string, message: string}
     */
    private function keyed(string $field, array $problem): array
    {
        return ['key' => $problem['path'] === '' ? $field : $field.'.'.$problem['path'], 'message' => $problem['message']];
    }

    /** The current organization's name, for a signature that shows the company. */
    private function organizationName(): string
    {
        return trim((string) Org::query()->whereKey(CurrentOrg::id())->value('name'));
    }

    /**
     * The organization's CRM mailbox: the From address every CRM email is sent from, which is also where replies
     * land (no Reply-To is applied to CRM mail; see ModuleSender).
     */
    private function organizationEmail(): string
    {
        return $this->senders->for(Module::Crm)->address;
    }

    /** @return array<string, string> */
    private function sampleTokens(): array
    {
        return array_map(fn (array $token): string => $token['sample'], EmailTemplateCatalog::TOKENS);
    }

    /** An unsaved lead carrying the samples, so TemplateRenderer derives them exactly as it would for a real one. */
    private function sampleLead(): Lead
    {
        $samples = $this->sampleTokens();

        return (new Lead)->forceFill([
            'contact_name' => $samples['contact_name'],
            'business_name' => $samples['business_name'],
            'industry' => $samples['industry'],
            'city' => $samples['city'],
        ]);
    }

    private function sampleSender(): User
    {
        return (new User)->forceFill(['name' => $this->sampleTokens()['sender_name']]);
    }
}
