<?php

declare(strict_types=1);

namespace App\Modules\Crm\Support\EmailDesign;

use JsonException;

/**
 * Decides whether a design's layout, or a template's content, may be stored or rendered — the server-side
 * gate for everything the browser sends. The frontend may validate too, for feedback; this class is the one
 * that counts.
 *
 * ── The check ORDER is a security requirement ──
 * bytes → depth → structure, each with an early return. The size ceiling is enforced before a byte is
 * walked, and depth is rejected before any node or field below it is inspected, so no later check ever runs
 * on a payload big or deep enough to hide something from it. The same order is used for a raw JSON string
 * (`…ProblemInJson()`) and an already-decoded array (`…Problem()`), for both documents.
 *
 * ── Closed, not filtered ──
 * Nothing is stripped or repaired. An unknown field, block type, node, mark, font, color format or URL
 * scheme — or a block in a part of the email that may not hold it — makes the whole document invalid, so
 * what validates is exactly what the renderer was written for.
 *
 * ── The answer ──
 * Null when the document is valid; otherwise the FIRST problem as `{path, message}`. The path is dot notation
 * into the document — `header.0.link_url`, `signature.sign_off`, `blocks.2.url`, `style_overrides.font` —
 * built only from numeric indexes and schema field names (an unknown key is reduced to safe characters), and
 * the message is a fixed string safe to show a user.
 */
final class EmailDesignValidator
{
    public const MALFORMED = 'This design is not well-formed.';

    public const TOO_LARGE = 'This design is too large (limit 100 KB).';

    public const TOO_DEEP = 'This design is nested too deeply.';

    public const UNSUPPORTED_VERSION = 'This design version is not supported.';

    public const UNKNOWN_FIELD = 'This field is not part of the email design format.';

    public const MISSING_FIELD = 'This field is required.';

    public const NO_BLOCKS = 'Add at least one block.';

    public const TOO_MANY_BLOCKS = 'This part of the email can hold at most %d blocks.';

    public const UNKNOWN_BLOCK = 'This block type is not supported.';

    public const BLOCK_NOT_ALLOWED = 'This block cannot be used in this part of the email.';

    public const INVALID_ID = 'A block id must be 1 to 64 letters, digits, dashes or underscores.';

    public const DUPLICATE_ID = 'Each block id must be unique.';

    public const INVALID_FONT = 'Choose one of the available fonts.';

    public const INVALID_COLOR = 'Use a six-digit hex color such as #1a2b3c.';

    public const INVALID_SWITCH = 'This setting must be on or off.';

    public const INVALID_VARIANT = 'Choose a primary or secondary button.';

    public const INVALID_ALIGNMENT = 'Alignment must be left, center or right.';

    public const OUT_OF_RANGE = 'Use a whole number from %d to %d.';

    public const INVALID_TEXT = 'This text contains characters that are not allowed.';

    public const TEXT_REQUIRED = 'This text cannot be empty.';

    public const TEXT_TOO_LONG = 'This text is too long (limit %d characters).';

    public const UNKNOWN_PLACEHOLDER = 'This text uses a placeholder that is not available.';

    public const STRAY_BRACES = 'This text has an incomplete placeholder: a lone {{ or }}.';

    public const PLACEHOLDER_NOT_ALLOWED = 'Placeholders are not allowed in this field.';

    public const PLACEHOLDER_IN_URL = 'Placeholders are not allowed in links.';

    public const UNSAFE_URL = 'Links must be a full https://, mailto: or tel: address.';

    public const INVALID_ASSET = 'This image reference is not valid.';

    public const UNSUPPORTED_FORMATTING = 'This text contains formatting that email designs do not support.';

    public const INVALID_LINK_TARGET = 'Choose where the logo links: the organization website, a custom address or nowhere.';

    public const LINK_URL_NOT_ALLOWED = 'A link address is only used when the logo links to a custom address.';

    /**
     * C0/C1 control characters (tab and newline included), Unicode line separators and bidirectional
     * overrides/isolates. None belongs in design text; a failed match on invalid UTF-8 counts as found.
     */
    private const DISALLOWED_TEXT = '/[\x00-\x1F\x7F\x{0080}-\x{009F}\x{2028}\x{2029}\x{202A}-\x{202E}\x{2066}-\x{2069}]/u';

    /** Anything outside printable ASCII, and the characters that could end or escape an attribute. */
    private const DISALLOWED_URL = '/[^\x21-\x7E]|["\'<>\\\\`]/';

    /** A dotted DNS name: labels of letters, digits and inner hyphens. */
    private const HOST = '/^(?!-)[A-Za-z0-9-]{1,63}(?<!-)(?:\.(?!-)[A-Za-z0-9-]{1,63}(?<!-))+\z/';

    private const MAILTO = '/^mailto:([^?]+)(?:\?[A-Za-z0-9%._~=&+-]*)?\z/';

    private const TEL = '/^tel:\+?[0-9][0-9().-]{2,31}\z/';

    /** A lowercase canonical UUID — the only form an email asset id takes. */
    private const ASSET_ID = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\z/';

    private const BLOCK_ID = '/^[A-Za-z0-9_-]{1,64}\z/';

    private const COLOR = '/^#[0-9A-Fa-f]{6}\z/';

    /** @var list<string> */
    private readonly array $tokens;

    /**
     * @param  list<string>|null  $tokens  the placeholder keys design text may use; defaults to the
     *                                     template catalog's whitelist
     */
    public function __construct(?array $tokens = null)
    {
        $this->tokens = $tokens ?? EmailDesignSchema::tokenKeys();
    }

    // ── Documents ────────────────────────────────────────────────────────────────────────────

    /**
     * A design layout exactly as it arrives over the wire.
     *
     * @return array{path: string, message: string}|null
     */
    public function layoutProblemInJson(string $json): ?array
    {
        return $this->decodedProblem($json, fn (mixed $layout): ?array => $this->layoutProblem($layout));
    }

    /**
     * A template's content exactly as it arrives over the wire.
     *
     * @return array{path: string, message: string}|null
     */
    public function contentProblemInJson(string $json): ?array
    {
        return $this->decodedProblem($json, fn (mixed $content): ?array => $this->contentProblem($content));
    }

    /**
     * An already-decoded design layout.
     *
     * @return array{path: string, message: string}|null
     */
    public function layoutProblem(mixed $layout): ?array
    {
        $problem = $this->envelopeProblem($layout);
        if ($problem !== null) {
            return $problem;
        }

        return $this->fieldsProblem($layout, EmailDesignSchema::LAYOUT_FIELDS, [], '')
            ?? $this->versionProblem($layout['version'])
            ?? $this->stylesProblem($layout['styles'], 'styles')
            ?? $this->regionsProblem($layout, [EmailDesignSchema::HEADER, EmailDesignSchema::FOOTER])
            ?? $this->signatureProblem($layout['signature']);
    }

    /**
     * An already-decoded template content.
     *
     * @return array{path: string, message: string}|null
     */
    public function contentProblem(mixed $content): ?array
    {
        $problem = $this->envelopeProblem($content);
        if ($problem !== null) {
            return $problem;
        }

        return $this->fieldsProblem($content, EmailDesignSchema::CONTENT_FIELDS['required'], EmailDesignSchema::CONTENT_FIELDS['optional'], '')
            ?? $this->versionProblem($content['version'])
            ?? $this->optionalProblem($content, 'style_overrides', fn (mixed $styles): ?array => $this->stylesProblem($styles, 'style_overrides'))
            ?? $this->optionalProblem($content, 'preheader', fn (mixed $text): ?array => $this->lineProblem($text, 'preheader', EmailDesignSchema::MAX_PREHEADER, required: false, placeholders: true))
            ?? $this->optionalProblem($content, 'sign_off', fn (mixed $text): ?array => $this->lineProblem($text, 'sign_off', EmailDesignSchema::MAX_SIGN_OFF, required: false, placeholders: true))
            ?? $this->regionsProblem($content, [EmailDesignSchema::CONTENT]);
    }

    /** Whether `$url` is a link this format accepts — the same rule as every link field in a document. */
    public function isAllowedLinkUrl(string $url): bool
    {
        return $this->urlProblem($url, '') === null;
    }

    /**
     * @param  callable(mixed): ?array{path: string, message: string}  $validate
     * @return array{path: string, message: string}|null
     */
    private function decodedProblem(string $json, callable $validate): ?array
    {
        // 1 ── bytes, before anything is decoded.
        if (strlen($json) > EmailDesignSchema::MAX_BYTES) {
            return $this->fail('', self::TOO_LARGE);
        }

        // 2 ── depth, enforced by the decoder itself (which counts the scalar level too, hence + 1).
        try {
            $document = json_decode($json, true, EmailDesignSchema::MAX_DEPTH + 1, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            return $this->fail('', $e->getCode() === JSON_ERROR_DEPTH ? self::TOO_DEEP : self::MALFORMED);
        }

        return $validate($document);
    }

    /**
     * The checks every document passes before its structure is walked: an object, within the byte ceiling,
     * within the depth limit — in that order; see the class docblock.
     *
     * @return array{path: string, message: string}|null
     */
    private function envelopeProblem(mixed $document): ?array
    {
        if (! $this->isObject($document)) {
            return $this->fail('', self::MALFORMED);
        }

        // 1 ── bytes
        $encoded = json_encode($document);
        if ($encoded === false) {
            return $this->fail('', json_last_error() === JSON_ERROR_DEPTH ? self::TOO_DEEP : self::MALFORMED);
        }
        if (strlen($encoded) > EmailDesignSchema::MAX_BYTES) {
            return $this->fail('', self::TOO_LARGE);
        }

        // 2 ── depth
        return $this->exceedsDepth($document, 1) ? $this->fail('', self::TOO_DEEP) : null;
    }

    /** @param  array<array-key, mixed>  $value */
    private function exceedsDepth(array $value, int $level): bool
    {
        if ($level > EmailDesignSchema::MAX_DEPTH) {
            return true;
        }

        foreach ($value as $child) {
            if (is_array($child) && $this->exceedsDepth($child, $level + 1)) {
                return true;
            }
        }

        return false;
    }

    /** @return array{path: string, message: string}|null */
    private function versionProblem(mixed $version): ?array
    {
        return $version === EmailDesignSchema::VERSION ? null : $this->fail('version', self::UNSUPPORTED_VERSION);
    }

    /**
     * A styles object: optional keys from EmailDesignSchema::styleKeys(), each absent, null, a known font key,
     * a hex color, or — for a numeric style — a whole number in its range.
     *
     * @return array{path: string, message: string}|null
     */
    private function stylesProblem(mixed $styles, string $path): ?array
    {
        if (! $this->isObject($styles)) {
            return $this->fail($path, self::MALFORMED);
        }

        $problem = $this->fieldsProblem($styles, [], EmailDesignSchema::styleKeys(), $path);
        if ($problem !== null) {
            return $problem;
        }

        foreach (EmailDesignSchema::styleKeys() as $key) {
            $value = $styles[$key] ?? null;
            if ($value === null) {
                continue;
            }

            $problem = match (true) {
                $key === 'font' => $this->fontProblem($value, $path.'.font'),
                array_key_exists($key, EmailDesignSchema::NUMERIC_STYLES) => $this->rangeProblem($value, EmailDesignSchema::NUMERIC_STYLES[$key][1], $path.'.'.$key),
                default => $this->colorProblem($value, $path.'.'.$key),
            };
            if ($problem !== null) {
                return $problem;
            }
        }

        return null;
    }

    /** @return array{path: string, message: string}|null */
    private function signatureProblem(mixed $signature): ?array
    {
        if (! $this->isObject($signature)) {
            return $this->fail('signature', self::MALFORMED);
        }

        $problem = $this->fieldsProblem($signature, EmailDesignSchema::SIGNATURE_FIELDS, EmailDesignSchema::SIGNATURE_OPTIONS, 'signature')
            ?? (is_bool($signature['enabled']) ? null : $this->fail('signature.enabled', self::MALFORMED))
            ?? $this->lineProblem($signature['sign_off'], 'signature.sign_off', EmailDesignSchema::MAX_SIGN_OFF, required: false, placeholders: true)
            ?? $this->alignmentProblem($signature['align'], 'signature.align');
        if ($problem !== null) {
            return $problem;
        }

        foreach (EmailDesignSchema::SIGNATURE_OPTIONS as $option) {
            if (array_key_exists($option, $signature) && ! is_bool($signature[$option])) {
                return $this->fail('signature.'.$option, self::INVALID_SWITCH);
            }
        }

        return null;
    }

    /**
     * The document's block lists, in order. Block ids are unique across all of them.
     *
     * @param  array<array-key, mixed>  $document
     * @param  list<string>  $regions
     * @return array{path: string, message: string}|null
     */
    private function regionsProblem(array $document, array $regions): ?array
    {
        $ids = [];
        foreach ($regions as $region) {
            $problem = $this->regionProblem($document[$region], $region, $ids);
            if ($problem !== null) {
                return $problem;
            }
        }

        return null;
    }

    /**
     * @param  array<string, true>  $ids
     * @return array{path: string, message: string}|null
     */
    private function regionProblem(mixed $blocks, string $region, array &$ids): ?array
    {
        if (! is_array($blocks) || ! array_is_list($blocks)) {
            return $this->fail($region, self::MALFORMED);
        }

        $isContent = $region === EmailDesignSchema::CONTENT;
        if ($isContent && $blocks === []) {
            return $this->fail($region, self::NO_BLOCKS);
        }

        $max = $isContent ? EmailDesignSchema::MAX_CONTENT_BLOCKS : EmailDesignSchema::MAX_REGION_BLOCKS;
        if (count($blocks) > $max) {
            return $this->fail($region, sprintf(self::TOO_MANY_BLOCKS, $max));
        }

        foreach ($blocks as $i => $block) {
            $path = $region.'.'.$i;

            $problem = $this->blockProblem($block, $path, EmailDesignSchema::REGION_BLOCKS[$region]);
            if ($problem !== null) {
                return $problem;
            }
            if (isset($ids[$block['id']])) {
                return $this->fail($path.'.id', self::DUPLICATE_ID);
            }
            $ids[$block['id']] = true;
        }

        return null;
    }

    /**
     * @param  list<string>  $allowed  the block types this part of the email may hold
     * @return array{path: string, message: string}|null
     */
    private function blockProblem(mixed $block, string $path, array $allowed): ?array
    {
        if (! $this->isObject($block)) {
            return $this->fail($path, self::MALFORMED);
        }
        if (! array_key_exists('type', $block)) {
            return $this->fail($path.'.type', self::MISSING_FIELD);
        }

        $type = $block['type'];
        if (! is_string($type) || ! array_key_exists($type, EmailDesignSchema::BLOCK_FIELDS)) {
            return $this->fail($path.'.type', self::UNKNOWN_BLOCK);
        }
        if (! in_array($type, $allowed, true)) {
            return $this->fail($path.'.type', self::BLOCK_NOT_ALLOWED);
        }

        $fields = EmailDesignSchema::BLOCK_FIELDS[$type];

        return $this->fieldsProblem($block, $fields['required'], $fields['optional'], $path)
            ?? (is_string($block['id']) && preg_match(self::BLOCK_ID, $block['id']) === 1 ? null : $this->fail($path.'.id', self::INVALID_ID))
            ?? match ($type) {
                'logo' => $this->rangeProblem($block['width'], EmailDesignSchema::LOGO_WIDTH, $path.'.width')
                    ?? $this->alignmentProblem($block['align'], $path.'.align')
                    ?? $this->logoLinkProblem($block, $path),
                'heading' => $this->rangeProblem($block['level'], EmailDesignSchema::HEADING_LEVELS, $path.'.level')
                    ?? $this->lineProblem($block['text'], $path.'.text', EmailDesignSchema::MAX_HEADING_TEXT, required: true, placeholders: true)
                    ?? $this->alignmentProblem($block['align'], $path.'.align')
                    ?? $this->optionalColorProblem($block, 'color', $path)
                    ?? $this->optionalProblem($block, 'font_size', fn (mixed $size): ?array => $this->rangeProblem($size, EmailDesignSchema::HEADING_FONT_SIZE, $path.'.font_size')),
                'text' => $this->richTextProblem($block['content'], EmailDesignSchema::TEXT_CHILDREN, $path.'.content')
                    ?? $this->alignmentProblem($block['align'], $path.'.align')
                    ?? $this->rangeProblem($block['font_size'], EmailDesignSchema::TEXT_FONT_SIZE, $path.'.font_size')
                    ?? $this->optionalColorProblem($block, 'color', $path),
                'button' => $this->lineProblem($block['label'], $path.'.label', EmailDesignSchema::MAX_BUTTON_LABEL, required: true, placeholders: true)
                    ?? $this->urlProblem($block['url'], $path.'.url')
                    ?? $this->alignmentProblem($block['align'], $path.'.align')
                    ?? $this->optionalColorProblem($block, 'background_color', $path)
                    ?? $this->optionalColorProblem($block, 'text_color', $path)
                    ?? $this->rangeProblem($block['radius'], EmailDesignSchema::BUTTON_RADIUS, $path.'.radius')
                    ?? $this->optionalProblem($block, 'variant', fn (mixed $variant): ?array => in_array($variant, EmailDesignSchema::BUTTON_VARIANTS, true) ? null : $this->fail($path.'.variant', self::INVALID_VARIANT)),
                'image' => (is_string($block['asset_id']) && preg_match(self::ASSET_ID, $block['asset_id']) === 1 ? null : $this->fail($path.'.asset_id', self::INVALID_ASSET))
                    ?? $this->lineProblem($block['alt'], $path.'.alt', EmailDesignSchema::MAX_ALT_TEXT, required: false, placeholders: false)
                    ?? $this->rangeProblem($block['width'], EmailDesignSchema::IMAGE_WIDTH, $path.'.width')
                    ?? $this->alignmentProblem($block['align'], $path.'.align')
                    ?? $this->optionalUrlProblem($block, 'link_url', $path),
                'divider' => $this->optionalColorProblem($block, 'color', $path)
                    ?? $this->rangeProblem($block['thickness'], EmailDesignSchema::DIVIDER_THICKNESS, $path.'.thickness'),
                'spacer' => $this->rangeProblem($block['height'], EmailDesignSchema::SPACER_HEIGHT, $path.'.height'),
                'footer' => $this->richTextProblem($block['content'], EmailDesignSchema::FOOTER_CHILDREN, $path.'.content')
                    ?? $this->alignmentProblem($block['align'], $path.'.align')
                    ?? $this->rangeProblem($block['font_size'], EmailDesignSchema::FOOTER_FONT_SIZE, $path.'.font_size')
                    ?? $this->optionalColorProblem($block, 'color', $path),
                default => $this->fail($path.'.type', self::UNKNOWN_BLOCK),
            };
    }

    // ── Rich text ────────────────────────────────────────────────────────────────────────────

    /**
     * @param  array<string, list<string>>  $children
     * @return array{path: string, message: string}|null
     */
    private function richTextProblem(mixed $doc, array $children, string $path): ?array
    {
        if (! $this->isObject($doc) || ($doc['type'] ?? null) !== 'doc') {
            return $this->fail($path, self::MALFORMED);
        }
        if (($doc['content'] ?? []) === []) {
            return $this->fail($path, self::TEXT_REQUIRED);
        }

        $characters = 0;
        $problem = $this->nodeProblem($doc, null, $children, $path, $characters);
        if ($problem !== null) {
            return $problem;
        }

        return $characters > EmailDesignSchema::MAX_BLOCK_TEXT
            ? $this->fail($path, sprintf(self::TEXT_TOO_LONG, EmailDesignSchema::MAX_BLOCK_TEXT))
            : null;
    }

    /**
     * @param  array<array-key, mixed>  $node
     * @param  array<string, list<string>>  $children
     * @return array{path: string, message: string}|null
     */
    private function nodeProblem(array $node, ?string $parent, array $children, string $path, int &$characters): ?array
    {
        $type = $node['type'] ?? null;
        if (! is_string($type)) {
            return $this->fail($path, self::MALFORMED);
        }
        if (! array_key_exists($type, $children)) {
            return $this->fail($path, self::UNSUPPORTED_FORMATTING);
        }
        if ($parent !== null && ! in_array($type, $children[$parent], true)) {
            return $this->fail($path, self::MALFORMED);
        }

        $problem = $this->fieldsProblem(
            $node,
            $type === 'text' ? ['type', 'text'] : ['type'],
            match ($type) {
                'text', 'hardBreak' => ['marks'],
                'orderedList' => ['content', 'attrs'],
                default => ['content'],
            },
            $path,
        )
            ?? (array_key_exists('marks', $node) ? $this->marksProblem($node['marks'], $path.'.marks') : null)
            ?? (array_key_exists('attrs', $node) ? $this->listAttributesProblem($node['attrs'], $path.'.attrs') : null);
        if ($problem !== null) {
            return $problem;
        }

        if ($type === 'text') {
            if (! is_string($node['text']) || $node['text'] === '') {
                return $this->fail($path.'.text', self::MALFORMED);
            }

            $problem = $this->textProblem($node['text'], $path.'.text', placeholders: true);
            if ($problem === null) {
                $characters += mb_strlen($node['text']);
            }

            return $problem;
        }

        if ($type === 'hardBreak') {
            $characters++;

            return null;
        }

        $content = $node['content'] ?? [];
        if (! is_array($content) || ! array_is_list($content)) {
            return $this->fail($path.'.content', self::MALFORMED);
        }
        // A document, a list and a list item cannot be empty; a paragraph can (a blank line).
        if ($content === [] && $type !== 'paragraph') {
            return $this->fail($path, self::MALFORMED);
        }
        if ($type === 'listItem' && ! (is_array($content[0]) && ($content[0]['type'] ?? null) === 'paragraph')) {
            return $this->fail($path, self::MALFORMED);
        }

        foreach ($content as $i => $child) {
            $childPath = $path.'.content.'.$i;
            if (! $this->isObject($child)) {
                return $this->fail($childPath, self::MALFORMED);
            }

            $problem = $this->nodeProblem($child, $type, $children, $childPath, $characters);
            if ($problem !== null) {
                return $problem;
            }
        }

        return null;
    }

    /** @return array{path: string, message: string}|null */
    private function marksProblem(mixed $marks, string $path): ?array
    {
        if (! is_array($marks) || ! array_is_list($marks)) {
            return $this->fail($path, self::MALFORMED);
        }

        $seen = [];
        foreach ($marks as $i => $mark) {
            $markPath = $path.'.'.$i;
            $type = $this->isObject($mark) ? ($mark['type'] ?? null) : null;

            if (! is_string($type)) {
                return $this->fail($markPath, self::MALFORMED);
            }
            if ($type !== EmailDesignSchema::LINK_MARK && ! array_key_exists($type, EmailDesignSchema::MARK_TAGS)) {
                return $this->fail($markPath, self::UNSUPPORTED_FORMATTING);
            }
            if (isset($seen[$type])) {
                return $this->fail($markPath, self::MALFORMED);
            }
            $seen[$type] = true;

            $problem = $type === EmailDesignSchema::LINK_MARK
                ? ($this->fieldsProblem($mark, ['type', 'attrs'], [], $markPath)
                    ?? $this->linkAttributesProblem($mark['attrs'], $markPath.'.attrs'))
                : ($this->fieldsProblem($mark, ['type'], ['attrs'], $markPath)
                    ?? (($mark['attrs'] ?? []) === [] ? null : $this->fail($markPath.'.attrs', self::UNKNOWN_FIELD)));
            if ($problem !== null) {
                return $problem;
            }
        }

        return null;
    }

    /** @return array{path: string, message: string}|null */
    private function linkAttributesProblem(mixed $attrs, string $path): ?array
    {
        if (! $this->isObject($attrs)) {
            return $this->fail($path, self::MALFORMED);
        }

        $problem = $this->fieldsProblem($attrs, ['href'], EmailDesignSchema::LINK_IGNORED_ATTRIBUTES, $path)
            ?? $this->urlProblem($attrs['href'], $path.'.href');
        if ($problem !== null) {
            return $problem;
        }

        foreach (EmailDesignSchema::LINK_IGNORED_ATTRIBUTES as $key) {
            $value = $attrs[$key] ?? null;
            if ($value === null) {
                continue;
            }
            if (! is_string($value) || strlen($value) > EmailDesignSchema::MAX_LINK_ATTRIBUTE) {
                return $this->fail($path.'.'.$key, self::MALFORMED);
            }

            $problem = $this->textProblem($value, $path.'.'.$key, placeholders: false);
            if ($problem !== null) {
                return $problem;
            }
        }

        return null;
    }

    /** @return array{path: string, message: string}|null */
    private function listAttributesProblem(mixed $attrs, string $path): ?array
    {
        if (! $this->isObject($attrs)) {
            return $this->fail($path, self::MALFORMED);
        }

        $problem = $this->fieldsProblem($attrs, [], ['start', 'type'], $path)
            ?? (($attrs['start'] ?? null) === null ? null : $this->rangeProblem($attrs['start'], EmailDesignSchema::LIST_START, $path.'.start'));
        if ($problem !== null) {
            return $problem;
        }

        return in_array($attrs['type'] ?? null, EmailDesignSchema::ORDERED_LIST_TYPES, true)
            ? null
            : $this->fail($path.'.type', self::MALFORMED);
    }

    // ── Values ───────────────────────────────────────────────────────────────────────────────

    /**
     * A single line of text: a heading, a button label, an alt text, a sign-off, a preheader.
     *
     * @return array{path: string, message: string}|null
     */
    private function lineProblem(mixed $value, string $path, int $max, bool $required, bool $placeholders): ?array
    {
        if (! is_string($value)) {
            return $this->fail($path, self::MALFORMED);
        }

        $problem = $this->textProblem($value, $path, $placeholders);
        if ($problem !== null) {
            return $problem;
        }
        if ($required && trim($value) === '') {
            return $this->fail($path, self::TEXT_REQUIRED);
        }

        return mb_strlen($value) > $max ? $this->fail($path, sprintf(self::TEXT_TOO_LONG, $max)) : null;
    }

    /**
     * Characters, then placeholders. Where placeholders are allowed, every `{{…}}` must be a whitelisted
     * key in TemplateRenderer's form and no lone `{{` / `}}` may remain; where they are not, no braces pair
     * may appear at all.
     *
     * @return array{path: string, message: string}|null
     */
    private function textProblem(string $text, string $path, bool $placeholders): ?array
    {
        if (preg_match(self::DISALLOWED_TEXT, $text) !== 0) {
            return $this->fail($path, self::INVALID_TEXT);
        }

        if (! $placeholders) {
            return str_contains($text, '{{') || str_contains($text, '}}')
                ? $this->fail($path, self::PLACEHOLDER_NOT_ALLOWED)
                : null;
        }

        preg_match_all('/\{\{(.*?)\}\}/s', $text, $matches);
        foreach ($matches[1] as $inner) {
            $key = trim($inner);
            if (preg_match('/^[a-z_]+\z/', $key) !== 1 || ! in_array($key, $this->tokens, true)) {
                return $this->fail($path, self::UNKNOWN_PLACEHOLDER);
            }
        }

        $rest = (string) preg_replace('/\{\{.*?\}\}/s', '', $text);

        return str_contains($rest, '{{') || str_contains($rest, '}}') ? $this->fail($path, self::STRAY_BRACES) : null;
    }

    /**
     * An https, mailto or tel URL — nothing else. No placeholders (raw or percent-encoded), nothing outside
     * printable ASCII, no quote, angle bracket, backslash or backtick; https must name a dotted host with no
     * credentials; mailto is one valid address with an optional query that cannot add a recipient.
     *
     * @return array{path: string, message: string}|null
     */
    private function urlProblem(mixed $url, string $path): ?array
    {
        if (! is_string($url) || $url === '' || strlen($url) > EmailDesignSchema::MAX_URL) {
            return $this->fail($path, self::UNSAFE_URL);
        }
        if (str_contains($url, '{{') || str_contains($url, '}}') || stripos($url, '%7B%7B') !== false || stripos($url, '%7D%7D') !== false) {
            return $this->fail($path, self::PLACEHOLDER_IN_URL);
        }
        if (preg_match(self::DISALLOWED_URL, $url) !== 0) {
            return $this->fail($path, self::UNSAFE_URL);
        }

        $safe = match (true) {
            str_starts_with($url, 'https://') => $this->isSafeHttps($url),
            str_starts_with($url, 'mailto:') => preg_match(self::MAILTO, $url, $m) === 1 && filter_var($m[1], FILTER_VALIDATE_EMAIL) !== false,
            str_starts_with($url, 'tel:') => preg_match(self::TEL, $url) === 1,
            default => false,
        };

        return $safe ? null : $this->fail($path, self::UNSAFE_URL);
    }

    private function isSafeHttps(string $url): bool
    {
        $parts = parse_url($url);

        return is_array($parts)
            && ($parts['scheme'] ?? null) === 'https'
            && is_string($parts['host'] ?? null)
            && preg_match(self::HOST, $parts['host']) === 1
            && ! isset($parts['user'])
            && ! isset($parts['pass']);
    }

    /**
     * @param  array<array-key, mixed>  $block
     * @return array{path: string, message: string}|null
     */
    private function optionalUrlProblem(array $block, string $key, string $path): ?array
    {
        return ($block[$key] ?? null) === null ? null : $this->urlProblem($block[$key], $path.'.'.$key);
    }

    /**
     * A logo's link: a known `link_target`, a valid address when the target is custom (stated, or implied by
     * an address with no target), and no address for any other target.
     *
     * @param  array<array-key, mixed>  $block
     * @return array{path: string, message: string}|null
     */
    private function logoLinkProblem(array $block, string $path): ?array
    {
        $target = $block['link_target'] ?? null;
        if ($target !== null && ! in_array($target, EmailDesignSchema::LOGO_LINK_TARGETS, true)) {
            return $this->fail($path.'.link_target', self::INVALID_LINK_TARGET);
        }

        if (EmailDesignSchema::logoLinkTarget($block) === 'custom') {
            return $this->urlProblem($block['link_url'] ?? null, $path.'.link_url');
        }

        return ($block['link_url'] ?? null) === null ? null : $this->fail($path.'.link_url', self::LINK_URL_NOT_ALLOWED);
    }

    /**
     * @param  array<array-key, mixed>  $document
     * @param  callable(mixed): ?array{path: string, message: string}  $check
     * @return array{path: string, message: string}|null
     */
    private function optionalProblem(array $document, string $key, callable $check): ?array
    {
        return ($document[$key] ?? null) === null ? null : $check($document[$key]);
    }

    /**
     * @param  array{int, int}  $range
     * @return array{path: string, message: string}|null
     */
    private function rangeProblem(mixed $value, array $range, string $path): ?array
    {
        return is_int($value) && $value >= $range[0] && $value <= $range[1]
            ? null
            : $this->fail($path, sprintf(self::OUT_OF_RANGE, $range[0], $range[1]));
    }

    /**
     * An optional block color: absent or null is inherited, anything else must be a six-digit hex.
     *
     * @param  array<array-key, mixed>  $block
     * @return array{path: string, message: string}|null
     */
    private function optionalColorProblem(array $block, string $field, string $path): ?array
    {
        return ($block[$field] ?? null) === null ? null : $this->colorProblem($block[$field], $path.'.'.$field);
    }

    /** @return array{path: string, message: string}|null */
    private function colorProblem(mixed $value, string $path): ?array
    {
        return is_string($value) && preg_match(self::COLOR, $value) === 1 ? null : $this->fail($path, self::INVALID_COLOR);
    }

    /** @return array{path: string, message: string}|null */
    private function fontProblem(mixed $value, string $path): ?array
    {
        return is_string($value) && array_key_exists($value, EmailDesignSchema::FONTS) ? null : $this->fail($path, self::INVALID_FONT);
    }

    /** @return array{path: string, message: string}|null */
    private function alignmentProblem(mixed $value, string $path): ?array
    {
        return in_array($value, EmailDesignSchema::ALIGNMENTS, true) ? null : $this->fail($path, self::INVALID_ALIGNMENT);
    }

    // ── Helpers ──────────────────────────────────────────────────────────────────────────────

    /**
     * Unknown keys first, then missing required ones.
     *
     * @param  array<array-key, mixed>  $object
     * @param  list<string>  $required
     * @param  list<string>  $optional
     * @return array{path: string, message: string}|null
     */
    private function fieldsProblem(array $object, array $required, array $optional, string $path): ?array
    {
        foreach (array_keys($object) as $key) {
            if (! is_string($key) || (! in_array($key, $required, true) && ! in_array($key, $optional, true))) {
                return $this->fail($this->join($path, $this->label((string) $key)), self::UNKNOWN_FIELD);
            }
        }

        foreach ($required as $key) {
            if (! array_key_exists($key, $object)) {
                return $this->fail($this->join($path, $key), self::MISSING_FIELD);
            }
        }

        return null;
    }

    /** A JSON object once decoded: an array that is not a non-empty list (`{}` decodes to `[]`). */
    private function isObject(mixed $value): bool
    {
        return is_array($value) && ($value === [] || ! array_is_list($value));
    }

    /** A client-supplied key as it may appear in a path: bounded and reduced to safe characters. */
    private function label(string $key): string
    {
        $clean = mb_substr((string) preg_replace('/[^A-Za-z0-9_-]/', '', $key), 0, 40);

        return $clean === '' ? 'unknown' : $clean;
    }

    private function join(string $path, string $key): string
    {
        return $path === '' ? $key : $path.'.'.$key;
    }

    /** @return array{path: string, message: string} */
    private function fail(string $path, string $message): array
    {
        return ['path' => $path, 'message' => $message];
    }
}
