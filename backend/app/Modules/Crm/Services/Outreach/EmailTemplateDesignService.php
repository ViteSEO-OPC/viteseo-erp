<?php

namespace App\Modules\Crm\Services\Outreach;

use App\Models\User;
use App\Modules\Crm\Models\Lead;
use App\Modules\Crm\Support\EmailDesign\EmailDesignContext;
use App\Modules\Crm\Support\EmailDesign\EmailDesignRenderer;
use App\Modules\Crm\Support\EmailDesign\EmailDesignSchema;
use App\Modules\Crm\Support\EmailDesign\EmailDesignValidator;
use App\Modules\Crm\Support\EmailTemplateCatalog;

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

    /** The subject the design preview shows, since a design has no subject of its own. */
    public const PREVIEW_SUBJECT = 'A sample email';

    /** Anything a reader would see: not whitespace, a non-breaking space or a zero-width character. */
    private const VISIBLE_CHARACTER = '/[^\s\x{00A0}\x{200B}-\x{200D}\x{2060}\x{FEFF}]/u';

    /** A full-length public asset URL, for measuring only — it is never rendered to anyone. */
    private const STAND_IN_ASSET_URL = 'https://assets.example.invalid/api/v1/crm/email-assets/%s.png';

    private const STAND_IN_LOGO_ID = '00000000-0000-4000-8000-000000000000';

    /** A realistic organization website, so a logo's default link is measured too. */
    private const STAND_IN_WEBSITE = 'https://www.organization-website.example/';

    public function __construct(
        private readonly EmailDesignValidator $validator,
        private readonly EmailDesignRenderer $renderer,
        private readonly TemplateRenderer $templates,
        private readonly EmailBrandingService $branding,
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
        );
    }

    /**
     * What a design is previewed with: a short message standing in for a template's content.
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
                ['type' => 'button', 'id' => 'sample-button', 'label' => 'Book a call', 'url' => 'https://example.com/book', 'align' => 'center', 'radius' => 6],
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
            $readable = match ($block['type']) {
                'heading' => $this->isVisible($block['text']),
                'button' => $this->isVisible($block['label']),
                'text' => $this->documentHasVisibleText($block['content']),
                // divider · spacer · image
                default => false,
            };

            if ($readable) {
                return true;
            }
        }

        return false;
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
