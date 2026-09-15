<?php

declare(strict_types=1);

namespace App\Modules\Crm\Support\EmailDesign;

/**
 * Everything a render needs that is not in the design itself: the placeholder values for one recipient,
 * the public URLs the server resolved for the design's images, and the organization's email branding —
 * its logo, website and styles.
 *
 * The design never carries a URL for an image or the logo — only an asset id, or a logo placement — so
 * these come from the caller, which owns the asset store and the branding. The renderer still refuses any
 * URL here that is not a plain absolute address of the right kind, ignores any brand style that is not a
 * known font key or six-digit hex color, and escapes every value, so a bad context can omit an image, a
 * link or a style but never inject markup.
 */
final readonly class EmailDesignContext
{
    /**
     * @param  array<string, string>  $tokens  placeholder key → value, e.g. `first_name` → `Maria`
     * @param  array<string, string>  $assetUrls  asset id → its public URL
     * @param  string|null  $logoUrl  the organization logo's public URL, if it has one
     * @param  string  $logoAlt  alt text for the logo, usually the organization name
     * @param  array<string, string>  $brandStyles  the organization's email styles, keyed like
     *                                              EmailDesignSchema::SYSTEM_DEFAULTS; the step between a
     *                                              template's own settings and the system defaults
     * @param  string|null  $brandWebsite  the organization website a logo links to by default (https only)
     * @param  array<string, string>  $previewImages  SETTINGS PREVIEWS ONLY: public image URL → the PNG bytes the
     *                                                server read from its own asset store for it. The preview
     *                                                frame loads no remote image, so a preview shows these
     *                                                inline instead. A send context never sets this: email
     *                                                clients get the public URL, and Gmail blocks inline data.
     */
    public function __construct(
        public array $tokens = [],
        public array $assetUrls = [],
        public ?string $logoUrl = null,
        public string $logoAlt = '',
        public array $brandStyles = [],
        public ?string $brandWebsite = null,
        public array $previewImages = [],
    ) {}

    /**
     * A context in which every placeholder renders as itself — for the plain-text `body` stored beside a
     * design, which TemplateRenderer later fills in for each lead exactly as it does a hand-written body.
     * Substitution is a single pass, so `{{first_name}}` becomes `{{first_name}}` and nothing more.
     *
     * A signature line whose key is not in $keys is simply left out, as it is for any token with no value.
     *
     * @param  list<string>  $keys
     */
    public static function preservingPlaceholders(array $keys): self
    {
        return new self(tokens: array_combine($keys, array_map(fn (string $key): string => '{{'.$key.'}}', $keys)));
    }
}
