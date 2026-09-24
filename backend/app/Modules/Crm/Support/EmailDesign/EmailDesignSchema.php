<?php

declare(strict_types=1);

namespace App\Modules\Crm\Support\EmailDesign;

use App\Modules\Crm\Services\Outreach\TemplateRenderer;
use App\Modules\Crm\Support\EmailTemplateCatalog;

/**
 * Version 1 of the designed-email format: the closed vocabulary designs and templates may use, and the
 * fixed values the renderer turns them into.
 *
 * ── Two documents, one email ──
 * A reusable email DESIGN holds a LAYOUT — the look many templates share:
 *
 *   { version, styles, header: [blocks], signature: {enabled, sign_off, align}, footer: [blocks] }
 *
 * A TEMPLATE holds CONTENT — what one email says — and names the design it is shown in:
 *
 *   { version, blocks: [blocks], preheader?, sign_off?, style_overrides? }
 *
 * The renderer composes them in this order: header · content · signature area · footer. Neither document
 * holds HTML, CSS, script or a URL for an image or logo. REGION_BLOCKS decides which blocks each part may
 * hold: the logo and footer text belong to a design, a template's message to its content, and a button
 * to either — the design's is the reusable call to action every template drawn in it carries.
 *
 * ── Styles are inherited, not copied ──
 * Every style, and every block color, is OPTIONAL. An absent (or null) value is inherited:
 *
 *   block color → template style override → design style → organization email branding → SYSTEM_DEFAULTS
 *
 * BLOCK_COLOR_FALLBACKS names the style each block color falls back to. A design stores only the styles it
 * sets, a template only the ones it deliberately overrides, so a change to the design or the organization's
 * branding reaches every template that did not override it. EmailDesignRenderer::effectiveStyles() is the
 * one implementation of the chain.
 *
 * ── The signature area belongs to the design ──
 * The design decides whether a signature is shown, its sign-off and its alignment; the sender's name, title
 * and phone are filled in at render time. A template may override only the sign-off text: an absent
 * `sign_off` uses the design's, an empty one shows no sign-off line.
 *
 * ── The logo is a branding reference ──
 * A logo block records only placement — width, alignment and where it links. Its image and alt text are the
 * organization's logo, supplied at render time. `link_target`: `website` (the default: the organization
 * website, when branding has one), `custom` (`link_url`, a validated https / mailto / tel address) or `none`.
 * A logo with a `link_url` and no `link_target` is `custom`.
 *
 * ── Images are assets ──
 * An image block holds an `asset_id` — the UUID of a stored email asset — never a URL. The caller resolves
 * ids to the asset store's permanent public URLs when it renders; an unresolved id renders no image.
 *
 * ── Rich text inside a block ──
 * `text` and `footer` blocks hold a small Tiptap document. Footer text is paragraphs only; text blocks may
 * also hold lists. Marks are bold · italic · underline · strike · link, and a link obeys the https / mailto /
 * tel rule.
 *
 * ── Placeholders ──
 * `{{token}}` substitution uses {@see TemplateRenderer}'s exact pattern and is allowed in TEXT only —
 * headings, rich text, button labels, sign-offs and the preheader. Never in a URL, an asset id or alt text.
 *
 * ── Versions ──
 * Additive changes stay inside a version: a new optional field, a new block type, a new placeholder. Every
 * document valid before such a change stays valid and renders the same. A change that would make a stored
 * document invalid, or render differently, bumps VERSION, and stored documents are upgraded to the current
 * version when they are read — validation and rendering only ever see the current version.
 */
final class EmailDesignSchema
{
    public const VERSION = 1;

    /** Ceiling on either encoded document, checked before anything is decoded or walked. */
    public const MAX_BYTES = 100_000;

    /** Deepest container nesting a document may reach; the document itself is level 1. */
    public const MAX_DEPTH = 32;

    /** Blocks a template's content may hold. */
    public const MAX_CONTENT_BLOCKS = 50;

    /** Blocks a design's header, and separately its footer, may hold. */
    public const MAX_REGION_BLOCKS = 10;

    public const MAX_DESIGN_NAME = 100;

    /** The content column's width. Below it the layout is fluid. */
    public const CONTENT_WIDTH = 600;

    /** Horizontal padding inside the content column. */
    public const CONTENT_PADDING = 24;

    /** The widest an image may be: the column less its padding on both sides. */
    public const INNER_WIDTH = self::CONTENT_WIDTH - 2 * self::CONTENT_PADDING;

    /** Font key → the email-safe stack written into the HTML. Unquoted names are valid CSS. */
    public const FONTS = [
        'arial' => 'Arial, Helvetica, sans-serif',
        'helvetica' => 'Helvetica Neue, Helvetica, Arial, sans-serif',
        'verdana' => 'Verdana, Geneva, sans-serif',
        'tahoma' => 'Tahoma, Geneva, sans-serif',
        'trebuchet' => 'Trebuchet MS, Helvetica, sans-serif',
        'georgia' => 'Georgia, Times New Roman, Times, serif',
        'times' => 'Times New Roman, Times, serif',
    ];

    /** @var list<string> */
    public const ALIGNMENTS = ['left', 'center', 'right'];

    // ── Documents ────────────────────────────────────────────────────────────────────────────

    /** A design layout's fields, all required. */
    public const LAYOUT_FIELDS = ['version', 'styles', 'header', 'signature', 'footer'];

    /** The signature area's fields, all required. */
    public const SIGNATURE_FIELDS = ['enabled', 'sign_off', 'align'];

    /** A template's content fields. */
    public const CONTENT_FIELDS = ['required' => ['version', 'blocks'], 'optional' => ['preheader', 'sign_off', 'style_overrides']];

    public const HEADER = 'header';

    public const FOOTER = 'footer';

    /** The key of a template's content blocks. */
    public const CONTENT = 'blocks';

    /**
     * Each part of an email → the blocks it may hold.
     *
     * A BUTTON IS ALLOWED IN ALL THREE. In the content it is the message's own call to action, written
     * per template; in the header or footer it is the REUSABLE one — "Book a call", "View our work" —
     * that belongs to the design and appears on every template drawn in it. Both are the same block
     * type, validated by the same rules and drawn by the same renderer; only who owns it differs.
     *
     * Widening an allow-list is backward-compatible by construction: a design saved before this
     * accepted exactly the blocks it still accepts, so nothing stored can become invalid.
     *
     * @var array<string, list<string>>
     */
    public const REGION_BLOCKS = [
        'header' => ['logo', 'heading', 'text', 'button', 'image', 'divider', 'spacer'],
        'footer' => ['footer', 'text', 'logo', 'button', 'image', 'divider', 'spacer'],
        'blocks' => ['heading', 'text', 'button', 'image', 'divider', 'spacer'],
    ];

    // ── Styles ───────────────────────────────────────────────────────────────────────────────

    /**
     * The end of the inheritance chain: every style's value when neither the template, its design nor the
     * organization's branding sets it. Its keys are the inheritable styles, in order.
     *
     *   background_color          behind the email
     *   content_background_color  the email itself
     *   text_color                headings, text, signature
     *   muted_text_color          footers
     *   link_color                links in text and footers
     *   accent_color              buttons
     *   accent_text_color         button labels
     *   divider_color             dividers
     */
    public const SYSTEM_DEFAULTS = [
        'font' => 'arial',
        'background_color' => '#f4f4f5',
        'content_background_color' => '#ffffff',
        'text_color' => '#18181b',
        'muted_text_color' => '#71717a',
        'link_color' => '#0d9488',
        'accent_color' => '#0d9488',
        'accent_text_color' => '#ffffff',
        'divider_color' => '#e4e4e7',
    ];

    /**
     * Block type → color field → the style it inherits when the block does not set it.
     *
     * @var array<string, array<string, string>>
     */
    public const BLOCK_COLOR_FALLBACKS = [
        'heading' => ['color' => 'text_color'],
        'text' => ['color' => 'text_color'],
        'footer' => ['color' => 'muted_text_color'],
        'button' => ['background_color' => 'accent_color', 'text_color' => 'accent_text_color'],
        'divider' => ['color' => 'divider_color'],
    ];

    /** @var list<string> */
    public const LOGO_LINK_TARGETS = ['website', 'custom', 'none'];

    // ── Blocks ───────────────────────────────────────────────────────────────────────────────

    /**
     * Block type → its fields. Every required field must be present; nothing outside the two lists may be.
     *
     * @var array<string, array{required: list<string>, optional: list<string>}>
     */
    public const BLOCK_FIELDS = [
        'logo' => ['required' => ['type', 'id', 'width', 'align'], 'optional' => ['link_target', 'link_url']],
        'heading' => ['required' => ['type', 'id', 'level', 'text', 'align'], 'optional' => ['color']],
        'text' => ['required' => ['type', 'id', 'content', 'align', 'font_size'], 'optional' => ['color']],
        'button' => ['required' => ['type', 'id', 'label', 'url', 'align', 'radius'], 'optional' => ['background_color', 'text_color']],
        'image' => ['required' => ['type', 'id', 'asset_id', 'alt', 'width', 'align'], 'optional' => ['link_url']],
        'divider' => ['required' => ['type', 'id', 'thickness'], 'optional' => ['color']],
        'spacer' => ['required' => ['type', 'id', 'height'], 'optional' => []],
        'footer' => ['required' => ['type', 'id', 'content', 'align', 'font_size'], 'optional' => ['color']],
    ];

    // ── Ranges: inclusive [min, max], whole numbers only ─────────────────────────────────────

    public const HEADING_LEVELS = [1, 3];

    public const TEXT_FONT_SIZE = [12, 22];

    public const FOOTER_FONT_SIZE = [10, 14];

    public const IMAGE_WIDTH = [16, self::INNER_WIDTH];

    public const LOGO_WIDTH = [40, 300];

    public const SPACER_HEIGHT = [4, 96];

    public const DIVIDER_THICKNESS = [1, 4];

    public const BUTTON_RADIUS = [0, 24];

    public const LIST_START = [1, 100000];

    /** Heading level → font size in px. */
    public const HEADING_SIZES = [1 => 28, 2 => 22, 3 => 18];

    // ── Text limits, in characters ───────────────────────────────────────────────────────────

    public const MAX_HEADING_TEXT = 200;

    public const MAX_BUTTON_LABEL = 60;

    public const MAX_ALT_TEXT = 150;

    public const MAX_SIGN_OFF = 100;

    /** The inbox preview line; clients show roughly the first 90–140 characters. */
    public const MAX_PREHEADER = 150;

    /** All text in one text or footer block, hard breaks counting as one character each. */
    public const MAX_BLOCK_TEXT = 5000;

    public const MAX_URL = 2000;

    public const MAX_LINK_ATTRIBUTE = 200;

    // ── Rich text ────────────────────────────────────────────────────────────────────────────

    /**
     * Which children each node of a text block's document may hold.
     *
     * @var array<string, list<string>>
     */
    public const TEXT_CHILDREN = [
        'doc' => ['paragraph', 'bulletList', 'orderedList'],
        'paragraph' => ['text', 'hardBreak'],
        'bulletList' => ['listItem'],
        'orderedList' => ['listItem'],
        // ProseMirror's listItem is `paragraph block*`: it must open with a paragraph.
        'listItem' => ['paragraph', 'bulletList', 'orderedList'],
        'text' => [],
        'hardBreak' => [],
    ];

    /**
     * A footer's document: paragraphs of text and line breaks, no lists.
     *
     * @var array<string, list<string>>
     */
    public const FOOTER_CHILDREN = [
        'doc' => ['paragraph'],
        'paragraph' => ['text', 'hardBreak'],
        'text' => [],
        'hardBreak' => [],
    ];

    /**
     * Formatting mark → tag, also the canonical nesting order so mark order in the JSON never changes the
     * HTML. `link` is the one other mark, and always wraps these.
     *
     * @var array<string, string>
     */
    public const MARK_TAGS = ['bold' => 'strong', 'italic' => 'em', 'underline' => 'u', 'strike' => 's'];

    public const LINK_MARK = 'link';

    /**
     * Attributes Tiptap writes on a link mark besides `href`. Accepted so editor output validates as-is,
     * and never rendered: the renderer writes its own fixed target and rel.
     *
     * @var list<string>
     */
    public const LINK_IGNORED_ATTRIBUTES = ['target', 'rel', 'class', 'title'];

    /** @var list<string|null> */
    public const ORDERED_LIST_TYPES = [null, '1', 'a', 'A', 'i', 'I'];

    // ── Placeholders ─────────────────────────────────────────────────────────────────────────

    /** The substitution pattern — identical to TemplateRenderer's, so a token means the same thing in both. */
    public const TOKEN_PATTERN = '/\{\{\s*([a-z_]+)\s*\}\}/';

    /** Token values the signature area prints after the sign-off, one line each, in this order. */
    public const SIGNATURE_TOKENS = ['sender_name', 'sender_title', 'sender_phone'];

    /** @return list<string> */
    public static function blockTypes(): array
    {
        return array_keys(self::BLOCK_FIELDS);
    }

    /**
     * The placeholders design and content text may use by default: exactly the template catalog's whitelist.
     *
     * @return list<string>
     */
    public static function tokenKeys(): array
    {
        return array_keys(EmailTemplateCatalog::TOKENS);
    }

    /**
     * Where a VALIDATED logo block links: `website`, `custom` or `none`. An absent target is `custom` when
     * the block carries a link address and `website` otherwise.
     *
     * @param  array<string, mixed>  $block
     */
    public static function logoLinkTarget(array $block): string
    {
        $target = $block['link_target'] ?? null;
        if (is_string($target)) {
            return $target;
        }

        return ($block['link_url'] ?? null) !== null ? 'custom' : 'website';
    }

    /**
     * The asset ids the image blocks of VALIDATED documents reference — layouts and content alike — first
     * appearance first: what a caller resolves to public URLs before rendering.
     *
     * @param  array<string, mixed>  ...$documents
     * @return list<string>
     */
    public static function referencedAssetIds(array ...$documents): array
    {
        $ids = [];
        foreach ($documents as $document) {
            foreach ([self::HEADER, self::CONTENT, self::FOOTER] as $region) {
                $blocks = $document[$region] ?? [];
                foreach (is_array($blocks) ? $blocks : [] as $block) {
                    if (is_array($block) && ($block['type'] ?? null) === 'image' && is_string($block['asset_id'] ?? null)) {
                        $ids[$block['asset_id']] = true;
                    }
                }
            }
        }

        return array_keys($ids);
    }
}
