<?php

namespace App\Modules\Hr\Support;

use DOMDocument;
use DOMElement;
use DOMNode;

/**
 * An allow-list sanitiser for the HTML a rich-text editor produces.
 *
 * HR composes candidate emails in a WYSIWYG editor, so the body arrives as HTML from the
 * browser. Trusting it would be trusting whatever a request body contains — the editor is
 * not the only thing that can POST to the endpoint, and the stored body is later rendered
 * back into the compose modal and the sent-history view. So the server keeps its own idea
 * of what a candidate email may contain and discards everything else.
 *
 * WHY NOT HTMLPURIFIER. Adding a composer dependency needs sign-off, and the surface here is
 * small and fixed: the marks and blocks the toolbar can emit, nothing more. DOMDocument is in
 * the standard library and the allow-list below is short enough to read in one sitting.
 *
 * The policy: unknown ELEMENTS are unwrapped (their text survives, the tag does not) rather
 * than deleted, so a paste from Word loses its formatting instead of its words. Script-bearing
 * elements are the exception — they are removed whole, contents and all. Unknown ATTRIBUTES are
 * always dropped, which is what kills `onclick`, `style`-based exfiltration and `srcdoc`.
 */
final class HtmlSanitizer
{
    /** Tags the editor can produce, plus the ones a reasonable paste brings with it. */
    private const ALLOWED_TAGS = [
        'p', 'br', 'div', 'span',
        'strong', 'b', 'em', 'i', 'u', 's', 'strike', 'del', 'mark', 'code',
        'ul', 'ol', 'li',
        'h1', 'h2', 'h3', 'h4',
        'blockquote', 'pre', 'hr', 'a',
    ];

    /** Per-tag attribute allow-list. Anything not named here is dropped. */
    private const ALLOWED_ATTRIBUTES = [
        'a' => ['href', 'target', 'rel'],
    ];

    /** Removed with their contents — unwrapping these would leak code as text. */
    private const VOID_SUBTREES = ['script', 'style', 'iframe', 'object', 'embed', 'form', 'template'];

    /** Only these may appear in an href. `javascript:` and `data:` are the reason this list exists. */
    private const ALLOWED_SCHEMES = ['http', 'https', 'mailto', 'tel'];

    /**
     * Return the input reduced to the allow-list above. Always returns a string; an input
     * that sanitises to nothing comes back empty, which callers treat as "no body".
     */
    public static function clean(string $html): string
    {
        $html = trim($html);
        if ($html === '') {
            return '';
        }

        $doc = new DOMDocument;

        // Force UTF-8: DOMDocument assumes ISO-8859-1 for a fragment with no meta charset,
        // which turns every non-ASCII character into mojibake. The XML declaration is the
        // encoding hint that survives LIBXML_HTML_NODEFDTD.
        $previous = libxml_use_internal_errors(true);
        $doc->loadHTML(
            '<?xml encoding="UTF-8">' . $html,
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NONET,
        );
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        foreach (iterator_to_array($doc->childNodes) as $child) {
            self::cleanNode($child);
        }

        $out = '';
        foreach ($doc->childNodes as $child) {
            // The XML declaration above comes back as a processing instruction; skip it.
            if ($child->nodeType === XML_PI_NODE) {
                continue;
            }
            $out .= $doc->saveHTML($child);
        }

        return trim($out);
    }

    /**
     * Strip every tag and collapse to plain text — used for the text/plain alternative part
     * of the outgoing email, so clients that refuse HTML still get a readable message.
     */
    public static function toPlainText(string $html): string
    {
        // A link's whole point is its destination, and stripping the tag would take the URL
        // with it — leaving "Join: meet link" and nowhere to go. Spell it out instead.
        $text = preg_replace_callback(
            '#<a\b[^>]*href="([^"]*)"[^>]*>(.*?)</a>#is',
            function (array $m): string {
                $label = trim(strip_tags($m[2]));
                $href = trim($m[1]);

                return $label === '' || $label === $href ? $href : "{$label} ({$href})";
            },
            $html,
        ) ?? $html;

        // Turn the block boundaries into newlines before dropping the tags, otherwise
        // paragraphs run together into one wall of text.
        $text = preg_replace('/<br\s*\/?>/i', "\n", $text) ?? $text;
        $text = preg_replace('#<(ul|ol|p|div|blockquote|pre|h[1-4])\b[^>]*>#i', "\n", $text) ?? $text;
        $text = preg_replace('#</(p|div|li|h[1-4]|blockquote|pre)>#i', "\n", $text) ?? $text;
        $text = preg_replace('#<li\b[^>]*>#i', '• ', $text) ?? $text;
        $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        // The editor wraps each list item's content in its own <p>, whose opening tag has
        // just become a newline — which would leave every bullet stranded on its own line.
        $text = preg_replace('/•[ \t]*\n+[ \t]*/u', '• ', $text) ?? $text;
        $text = preg_replace("/[ \t]+\n/", "\n", $text) ?? $text;
        $text = preg_replace("/\n{3,}/", "\n\n", $text) ?? $text;

        return trim($text);
    }

    /** Recursively apply the policy to one node and its descendants. */
    private static function cleanNode(DOMNode $node): void
    {
        if (! $node instanceof DOMElement) {
            // Text, CDATA and entities pass through; comments are noise and can carry
            // conditional markup, so they go.
            if ($node->nodeType === XML_COMMENT_NODE) {
                $node->parentNode?->removeChild($node);
            }

            return;
        }

        $tag = strtolower($node->nodeName);

        if (in_array($tag, self::VOID_SUBTREES, true)) {
            $node->parentNode?->removeChild($node);

            return;
        }

        // Depth-first: children are cleaned before the parent is possibly unwrapped, so a
        // disallowed wrapper can never smuggle its subtree past the policy.
        foreach (iterator_to_array($node->childNodes) as $child) {
            self::cleanNode($child);
        }

        if (! in_array($tag, self::ALLOWED_TAGS, true)) {
            self::unwrap($node);

            return;
        }

        $allowed = self::ALLOWED_ATTRIBUTES[$tag] ?? [];
        foreach (iterator_to_array($node->attributes ?? []) as $attribute) {
            $name = strtolower($attribute->nodeName);
            if (! in_array($name, $allowed, true)) {
                $node->removeAttribute($attribute->nodeName);
            }
        }

        if ($tag === 'a') {
            self::cleanAnchor($node);
        }
    }

    /**
     * An anchor keeps its href only if the scheme is one a candidate email should contain.
     * A link that fails that test loses the href but keeps its text, so the reader still sees
     * what was written — just not something clickable that we could not vouch for.
     */
    private static function cleanAnchor(DOMElement $node): void
    {
        $href = trim($node->getAttribute('href'));

        if ($href === '' || ! self::schemeAllowed($href)) {
            $node->removeAttribute('href');
            $node->removeAttribute('target');
            $node->removeAttribute('rel');

            return;
        }

        $node->setAttribute('href', $href);

        // Mail clients open links in a browser anyway, but the stored body is also rendered
        // back into the app — where a bare target=_blank is a reverse-tabnabbing handle.
        if ($node->getAttribute('target') !== '') {
            $node->setAttribute('target', '_blank');
            $node->setAttribute('rel', 'noopener noreferrer');
        }
    }

    private static function schemeAllowed(string $href): bool
    {
        // A relative or anchor-only href has no scheme and nowhere sensible to point from an
        // email, so it is rejected along with the dangerous ones.
        $scheme = parse_url($href, PHP_URL_SCHEME);

        return is_string($scheme) && in_array(strtolower($scheme), self::ALLOWED_SCHEMES, true);
    }

    /** Replace an element with its children, in place. */
    private static function unwrap(DOMElement $node): void
    {
        $parent = $node->parentNode;
        if (! $parent) {
            return;
        }

        while ($node->firstChild) {
            $parent->insertBefore($node->firstChild, $node);
        }

        $parent->removeChild($node);
    }
}
