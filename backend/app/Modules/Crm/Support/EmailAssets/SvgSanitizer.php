<?php

declare(strict_types=1);

namespace App\Modules\Crm\Support\EmailAssets;

use DOMDocument;
use DOMElement;
use DOMNode;

/**
 * Makes an uploaded SVG safe to keep, and crops it without rasterizing it.
 *
 * ── Rebuilt, never filtered in place ──
 * The upload is parsed (no DTD, no entities, no network) and a NEW document is built by copying across only
 * allowlisted SVG elements and attributes. Nothing unknown survives — not because it was recognised as
 * dangerous, but because it was never copied. Specifically, none of these can reach the output:
 *
 *   scripts · event handler attributes · foreignObject · <image> (embedded or linked rasters) · <a> links
 *   (their children are kept) · animation (<animate>, <set>, …, which can rewrite attributes) · any href but a
 *   same-document `#fragment` · CSS with url() to anything but `#fragment`, @import, expression() or escapes
 *   · processing instructions, comments, CDATA tricks, DOCTYPEs and entity declarations
 *
 * What was dropped is counted and returned, so the admin sees what the upload lost.
 *
 * ── Size ──
 * The output always has a `viewBox`, and `width`/`height` equal to its size in user units, so a browser shows
 * it at a definite size and a crop can be expressed in those units. An upload with neither a viewBox nor a
 * pixel width and height is refused: there is no reliable size to crop.
 *
 * ── Cropping ──
 * crop() rewrites the root viewBox to the crop rectangle and the width and height to match, which is exactly a
 * crop for any SVG, keeps it vector, and needs no rasterizer.
 */
final class SvgSanitizer
{
    public const SVG_NS = 'http://www.w3.org/2000/svg';

    public const XLINK_NS = 'http://www.w3.org/1999/xlink';

    public const MAX_BYTES = 512_000;

    public const MAX_ELEMENTS = 5_000;

    public const MAX_DEPTH = 48;

    /** The largest user-unit size accepted for either side of the viewBox. */
    public const MAX_DIMENSION = 100_000;

    public const INVALID = 'This file is not a valid SVG image.';

    public const TOO_LARGE = 'The SVG must be 500 KB or smaller.';

    public const TOO_COMPLEX = 'This SVG is too complex to use as a logo.';

    public const HAS_DOCTYPE = 'SVG files with a DOCTYPE or entity declarations are not accepted.';

    public const NO_SIZE = 'The SVG needs a viewBox, or a width and height in pixels.';

    public const NOTHING_VISIBLE = 'Nothing drawable is left in this SVG once unsafe content is removed.';

    public const CROP_OUTSIDE = 'The crop must lie inside the logo.';

    /** Elements copied across, by local name. */
    private const ELEMENTS = [
        'svg', 'g', 'defs', 'symbol', 'use', 'title', 'desc', 'style',
        'path', 'rect', 'circle', 'ellipse', 'line', 'polyline', 'polygon', 'text', 'tspan',
        'linearGradient', 'radialGradient', 'stop', 'clipPath', 'mask', 'pattern', 'marker',
        'filter', 'feBlend', 'feColorMatrix', 'feComponentTransfer', 'feComposite', 'feDropShadow', 'feFlood',
        'feFuncA', 'feFuncB', 'feFuncG', 'feFuncR', 'feGaussianBlur', 'feMerge', 'feMergeNode', 'feMorphology',
        'feOffset',
    ];

    /** Containers that are dropped but whose children are kept. */
    private const UNWRAPPED = ['a', 'switch'];

    /** Elements that draw something — at least one must remain. */
    private const DRAWABLE = ['path', 'rect', 'circle', 'ellipse', 'line', 'polyline', 'polygon', 'text', 'use'];

    /** Attributes copied across (no namespace), besides `href`, which only a few elements may carry. */
    private const ATTRIBUTES = [
        'id', 'class', 'style', 'transform', 'viewBox', 'preserveAspectRatio', 'width', 'height',
        'x', 'y', 'x1', 'y1', 'x2', 'y2', 'cx', 'cy', 'r', 'rx', 'ry', 'fx', 'fy', 'fr', 'd', 'points', 'pathLength',
        'fill', 'fill-opacity', 'fill-rule', 'stroke', 'stroke-width', 'stroke-linecap', 'stroke-linejoin',
        'stroke-miterlimit', 'stroke-dasharray', 'stroke-dashoffset', 'stroke-opacity', 'opacity', 'color',
        'display', 'visibility', 'clip-path', 'clip-rule', 'mask', 'filter', 'overflow', 'vector-effect',
        'shape-rendering', 'text-rendering', 'paint-order', 'mix-blend-mode', 'isolation',
        'offset', 'stop-color', 'stop-opacity', 'gradientUnits', 'gradientTransform', 'spreadMethod',
        'patternUnits', 'patternContentUnits', 'patternTransform', 'clipPathUnits', 'maskUnits', 'maskContentUnits',
        'markerWidth', 'markerHeight', 'markerUnits', 'refX', 'refY', 'orient', 'marker-start', 'marker-mid', 'marker-end',
        'font-family', 'font-size', 'font-weight', 'font-style', 'font-variant', 'text-anchor', 'dominant-baseline',
        'alignment-baseline', 'baseline-shift', 'letter-spacing', 'word-spacing', 'text-decoration',
        'dx', 'dy', 'rotate', 'textLength', 'lengthAdjust',
        'filterUnits', 'primitiveUnits', 'in', 'in2', 'result', 'stdDeviation', 'mode', 'values', 'type', 'operator',
        'k1', 'k2', 'k3', 'k4', 'flood-color', 'flood-opacity', 'color-interpolation-filters', 'radius',
        'tableValues', 'slope', 'intercept', 'amplitude', 'exponent',
    ];

    /** Elements that may reference another element in the same document. */
    private const HREF_ELEMENTS = ['use', 'linearGradient', 'radialGradient', 'pattern', 'filter'];

    /** The root attributes the output sets itself. */
    private const ROOT_MANAGED = ['viewBox', 'width', 'height', 'preserveAspectRatio', 'x', 'y'];

    /**
     * @return array{svg: string, viewBox: array{float, float, float, float}, width: float, height: float, removed: array<string, int>}
     *
     * @throws LogoRejected
     */
    public function sanitize(string $bytes): array
    {
        if (strlen($bytes) > self::MAX_BYTES) {
            throw new LogoRejected(self::TOO_LARGE);
        }

        $text = str_starts_with($bytes, "\xEF\xBB\xBF") ? substr($bytes, 3) : $bytes;
        if ($text === '' || ! mb_check_encoding($text, 'UTF-8')) {
            throw new LogoRejected(self::INVALID);
        }
        if (preg_match('/<!\s*(DOCTYPE|ENTITY)/i', $text) === 1) {
            throw new LogoRejected(self::HAS_DOCTYPE);
        }

        $source = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        try {
            // No LIBXML_NOENT or LIBXML_DTDLOAD: entities are never substituted and no DTD is fetched.
            $loaded = $source->loadXML($text, LIBXML_NONET | LIBXML_COMPACT);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        $root = $source->documentElement;
        if (! $loaded || $source->doctype !== null || ! $root instanceof DOMElement || $root->localName !== 'svg' || ! $this->isSvgElement($root, $root)) {
            throw new LogoRejected(self::INVALID);
        }

        $viewBox = $this->sizeOf($root);

        $output = new DOMDocument('1.0', 'UTF-8');
        $svg = $output->createElementNS(self::SVG_NS, 'svg');
        $output->appendChild($svg);

        $removed = [];
        $state = ['elements' => 0, 'drawable' => 0];

        $this->copyAttributes($root, $svg, $removed, true);
        foreach (iterator_to_array($root->childNodes) as $child) {
            $this->copyNode($child, $svg, $root, 1, $removed, $state);
        }

        if ($state['drawable'] === 0) {
            throw new LogoRejected(self::NOTHING_VISIBLE);
        }

        $this->setBox($svg, $viewBox);
        ksort($removed);

        return [
            'svg' => (string) $output->saveXML($svg),
            'viewBox' => $viewBox,
            'width' => $viewBox[2],
            'height' => $viewBox[3],
            'removed' => $removed,
        ];
    }

    /**
     * A sanitized SVG cropped to a rectangle given relative to the top-left of its viewBox, in user units.
     *
     * @param  array{x: float, y: float, width: float, height: float}  $crop
     * @return array{svg: string, viewBox: array{float, float, float, float}}
     *
     * @throws LogoRejected
     */
    public function crop(string $sanitizedSvg, array $crop): array
    {
        $document = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        try {
            $loaded = $document->loadXML($sanitizedSvg, LIBXML_NONET | LIBXML_COMPACT);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        $svg = $document->documentElement;
        if (! $loaded || ! $svg instanceof DOMElement || $svg->localName !== 'svg') {
            throw new LogoRejected(self::INVALID);
        }

        [$vx, $vy, $vw, $vh] = $this->sizeOf($svg);
        $tolerance = max($vw, $vh) * 0.0005;

        if ($crop['width'] <= 0 || $crop['height'] <= 0 || $crop['x'] < -$tolerance || $crop['y'] < -$tolerance
            || $vw + $tolerance < $crop['x'] + $crop['width'] || $vh + $tolerance < $crop['y'] + $crop['height']) {
            throw new LogoRejected(self::CROP_OUTSIDE);
        }

        $box = [
            $vx + max(0.0, $crop['x']),
            $vy + max(0.0, $crop['y']),
            min($crop['width'], $vw - max(0.0, $crop['x'])),
            min($crop['height'], $vh - max(0.0, $crop['y'])),
        ];
        $this->setBox($svg, $box);

        return ['svg' => (string) $document->saveXML($svg), 'viewBox' => $box];
    }

    /**
     * @param  array<string, int>  $removed
     * @param  array{elements: int, drawable: int}  $state
     */
    private function copyNode(DOMNode $node, DOMElement $into, DOMElement $root, int $depth, array &$removed, array &$state): void
    {
        if ($node->nodeType === XML_TEXT_NODE || $node->nodeType === XML_CDATA_SECTION_NODE) {
            $parent = $into->localName;
            if (in_array($parent, ['text', 'tspan', 'title', 'desc'], true)) {
                $into->appendChild($into->ownerDocument->createTextNode((string) $node->nodeValue));
            } elseif ($parent === 'style') {
                if ($this->isSafeCss((string) $node->nodeValue)) {
                    $into->appendChild($into->ownerDocument->createTextNode((string) $node->nodeValue));
                } else {
                    $this->count($removed, 'unsafe style');
                    $into->setAttribute('data-removed', '1');
                }
            }

            return;
        }

        if (! $node instanceof DOMElement) {
            if ($node->nodeType === XML_PI_NODE) {
                $this->count($removed, 'processing instruction');
            }

            return;
        }

        if (++$state['elements'] > self::MAX_ELEMENTS || $depth > self::MAX_DEPTH) {
            throw new LogoRejected(self::TOO_COMPLEX);
        }

        $name = (string) $node->localName;

        if (! $this->isSvgElement($node, $root)) {
            $this->count($removed, 'non-SVG element');

            return;
        }

        if (in_array($name, self::UNWRAPPED, true)) {
            $this->count($removed, $name === 'a' ? 'link' : $name);
            foreach (iterator_to_array($node->childNodes) as $child) {
                $this->copyNode($child, $into, $root, $depth + 1, $removed, $state);
            }

            return;
        }

        if (! in_array($name, self::ELEMENTS, true)) {
            $this->count($removed, match (true) {
                $name === 'script' => 'script',
                $name === 'foreignObject' => 'foreignObject',
                $name === 'image' || $name === 'feImage' => 'image',
                in_array($name, ['animate', 'animateMotion', 'animateTransform', 'set', 'mpath'], true) => 'animation',
                default => 'unsupported element',
            });

            return;
        }

        $copy = $into->ownerDocument->createElementNS(self::SVG_NS, $name);
        $this->copyAttributes($node, $copy, $removed, false);

        foreach (iterator_to_array($node->childNodes) as $child) {
            $this->copyNode($child, $copy, $root, $depth + 1, $removed, $state);
        }

        if ($name === 'style' && $copy->hasAttribute('data-removed')) {
            return;
        }

        if (in_array($name, self::DRAWABLE, true)) {
            $state['drawable']++;
        }

        $into->appendChild($copy);
    }

    /** @param  array<string, int>  $removed */
    private function copyAttributes(DOMElement $from, DOMElement $to, array &$removed, bool $isRoot): void
    {
        foreach (iterator_to_array($from->attributes) as $attribute) {
            $name = (string) $attribute->localName;
            $value = (string) $attribute->value;
            $namespace = $attribute->namespaceURI;

            if (str_starts_with(strtolower($name), 'on')) {
                $this->count($removed, 'event handler');

                continue;
            }

            if ($name === 'href' && ($namespace === null || $namespace === self::XLINK_NS)) {
                if (in_array($from->localName, self::HREF_ELEMENTS, true) && preg_match('/^#[A-Za-z_][A-Za-z0-9_.:-]*$/', trim($value)) === 1) {
                    $to->setAttribute('href', trim($value));
                } else {
                    $this->count($removed, 'external reference');
                }

                continue;
            }

            if ($namespace !== null || ! in_array($name, self::ATTRIBUTES, true)) {
                if ($namespace === null || $namespace === self::XLINK_NS) {
                    $this->count($removed, 'unsupported attribute');
                }

                continue;
            }

            if ($isRoot && in_array($name, self::ROOT_MANAGED, true)) {
                continue;
            }

            if (! $this->isSafeValue($value, $name === 'style')) {
                $this->count($removed, $name === 'style' ? 'unsafe style' : 'unsafe attribute');

                continue;
            }

            $to->setAttribute($name, $value);
        }
    }

    /** Whether an element belongs to the SVG namespace — or to no namespace inside an SVG without one. */
    private function isSvgElement(DOMElement $element, DOMElement $root): bool
    {
        return $element->namespaceURI === self::SVG_NS || ($element->namespaceURI === null && $root->namespaceURI === null);
    }

    private function isSafeValue(string $value, bool $css): bool
    {
        if (strlen($value) > 50_000 || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F<>\\\\]/', $value) === 1) {
            return false;
        }

        return $css || str_contains($value, 'url(') || str_contains($value, ':') ? $this->isSafeCss($value) : true;
    }

    /** CSS that can reach nothing outside the document and run nothing. */
    private function isSafeCss(string $css): bool
    {
        if (strlen($css) > 200_000 || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F<\\\\]/', $css) === 1) {
            return false;
        }

        $lower = strtolower($css);
        foreach (['@import', '@font-face', 'expression', 'javascript:', 'vbscript:', 'data:', 'behavior', '-moz-binding', 'image-set', '@namespace'] as $needle) {
            if (str_contains($lower, $needle)) {
                return false;
            }
        }

        preg_match_all('/url\s*\(/i', $css, $all);
        preg_match_all('/url\(\s*["\']?#[A-Za-z_][A-Za-z0-9_.:-]*["\']?\s*\)/i', $css, $local);

        return count($all[0]) === count($local[0]);
    }

    /**
     * The SVG's box in user units: its viewBox, or its pixel width and height.
     *
     * @return array{float, float, float, float}
     *
     * @throws LogoRejected
     */
    private function sizeOf(DOMElement $svg): array
    {
        $viewBox = trim($svg->getAttribute('viewBox'));
        if ($viewBox !== '') {
            $parts = preg_split('/[\s,]+/', $viewBox);
            if (is_array($parts) && count($parts) === 4 && array_filter($parts, fn (string $part): bool => is_numeric($part)) === $parts) {
                [$x, $y, $w, $h] = array_map('floatval', $parts);
                if ($this->isUsableSize($w, $h) && abs($x) <= self::MAX_DIMENSION && abs($y) <= self::MAX_DIMENSION) {
                    return [$x, $y, $w, $h];
                }
            }

            throw new LogoRejected(self::NO_SIZE);
        }

        $pixels = fn (string $value): ?float => preg_match('/^\s*(\d+(?:\.\d+)?)\s*(px)?\s*$/', $value, $m) === 1 ? (float) $m[1] : null;
        $w = $pixels($svg->getAttribute('width'));
        $h = $pixels($svg->getAttribute('height'));

        if ($w === null || $h === null || ! $this->isUsableSize($w, $h)) {
            throw new LogoRejected(self::NO_SIZE);
        }

        return [0.0, 0.0, $w, $h];
    }

    private function isUsableSize(float $w, float $h): bool
    {
        return $w > 0 && $h > 0 && $w <= self::MAX_DIMENSION && $h <= self::MAX_DIMENSION;
    }

    /** @param  array{float, float, float, float}  $box */
    private function setBox(DOMElement $svg, array $box): void
    {
        foreach (self::ROOT_MANAGED as $attribute) {
            $svg->removeAttribute($attribute);
        }
        $svg->setAttribute('viewBox', implode(' ', array_map(self::number(...), $box)));
        $svg->setAttribute('width', self::number($box[2]));
        $svg->setAttribute('height', self::number($box[3]));
        $svg->setAttribute('preserveAspectRatio', 'xMidYMid meet');
    }

    /** @param  array<string, int>  $removed */
    private function count(array &$removed, string $what): void
    {
        $removed[$what] = ($removed[$what] ?? 0) + 1;
    }

    public static function number(float $value): string
    {
        $formatted = rtrim(rtrim(number_format($value, 4, '.', ''), '0'), '.');

        return $formatted === '-0' || $formatted === '' ? '0' : $formatted;
    }
}
