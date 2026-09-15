<?php

declare(strict_types=1);

namespace Tests\Unit\Crm\EmailDesign;

use App\Modules\Crm\Support\EmailDesign\EmailDesignContext;
use App\Modules\Crm\Support\EmailDesign\EmailDesignSchema;
use App\Modules\Crm\Support\EmailDesign\EmailDesignValidator;
use LogicException;

/**
 * Builders for the two email design documents: a complete design layout and template content, one factory
 * per block taking the fields a test wants to change, the rich-text node helpers, a render context with
 * realistic values, and the validator's answer for a block placed in a part of the email.
 */
trait BuildsEmailDesigns
{
    protected const ASSET_ID = '0b9f5a8e-3c2d-4e1f-9a7b-6c5d4e3f2a1b';

    protected const ASSET_URL = 'https://system.viteseo.ph/api/v1/crm/email-assets/0b9f5a8e-3c2d-4e1f-9a7b-6c5d4e3f2a1b.png';

    protected const LOGO_URL = 'https://system.viteseo.ph/api/v1/crm/email-assets/7d2c1b0a-9e8f-4a6b-8c5d-4e3f2a1b0c9d.png';

    /**
     * A design layout: explicit styles, a logo header, the signature area and a footer.
     *
     * @param  list<array<string, mixed>>|null  $header
     * @param  list<array<string, mixed>>|null  $footer
     * @param  array<string, mixed>  $styles
     * @param  array<string, mixed>  $signature
     * @return array<string, mixed>
     */
    protected function layout(?array $header = null, ?array $footer = null, array $styles = [], array $signature = []): array
    {
        return [
            'version' => 1,
            'styles' => [...$this->styles(), ...$styles],
            'header' => $header ?? [$this->logo()],
            'signature' => [...['enabled' => true, 'sign_off' => 'Best regards,', 'align' => 'left'], ...$signature],
            'footer' => $footer ?? [$this->divider(['id' => 'footer-divider']), $this->footer()],
        ];
    }

    /**
     * Template content: every block content may hold, unless others are given.
     *
     * @param  list<array<string, mixed>>|null  $blocks
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    protected function content(?array $blocks = null, array $extra = []): array
    {
        return ['version' => 1, 'blocks' => $blocks ?? $this->contentBlocks(), ...$extra];
    }

    /** @return array<string, string> */
    protected function styles(): array
    {
        return [
            'font' => 'arial',
            'background_color' => '#F4F4F5',
            'content_background_color' => '#FFFFFF',
            'text_color' => '#18181B',
            'link_color' => '#0D9488',
        ];
    }

    /** @return list<array<string, mixed>> */
    protected function contentBlocks(): array
    {
        return [$this->heading(), $this->textBlock(), $this->button(), $this->image(), $this->divider(), $this->spacer()];
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    protected function block(string $type, array $overrides = []): array
    {
        return match ($type) {
            'logo' => $this->logo($overrides),
            'heading' => $this->heading($overrides),
            'text' => $this->textBlock($overrides),
            'button' => $this->button($overrides),
            'image' => $this->image($overrides),
            'divider' => $this->divider($overrides),
            'spacer' => $this->spacer($overrides),
            'footer' => $this->footer($overrides),
            default => throw new LogicException("There is no {$type} block."),
        };
    }

    /** @param  array<string, mixed>  $overrides */
    protected function logo(array $overrides = []): array
    {
        return [...['type' => 'logo', 'id' => 'logo', 'width' => 160, 'align' => 'center'], ...$overrides];
    }

    /** @param  array<string, mixed>  $overrides */
    protected function heading(array $overrides = []): array
    {
        return [...['type' => 'heading', 'id' => 'heading', 'level' => 1, 'text' => 'Hi {{first_name}},', 'align' => 'left', 'color' => '#18181B'], ...$overrides];
    }

    /** @param  array<string, mixed>  $overrides */
    protected function textBlock(array $overrides = []): array
    {
        return [...[
            'type' => 'text',
            'id' => 'text',
            'content' => $this->doc($this->para(
                $this->text('We would like to help '),
                $this->text('{{business_name}}', [['type' => 'bold']]),
                $this->text(' grow online.'),
            )),
            'align' => 'left',
            'font_size' => 14,
            'color' => '#3F3F46',
        ], ...$overrides];
    }

    /** @param  array<string, mixed>  $overrides */
    protected function button(array $overrides = []): array
    {
        return [...[
            'type' => 'button',
            'id' => 'button',
            'label' => 'Book a call',
            'url' => 'https://viteseo.ph/book',
            'align' => 'center',
            'background_color' => '#0D9488',
            'text_color' => '#FFFFFF',
            'radius' => 6,
        ], ...$overrides];
    }

    /** @param  array<string, mixed>  $overrides */
    protected function image(array $overrides = []): array
    {
        return [...['type' => 'image', 'id' => 'image', 'asset_id' => self::ASSET_ID, 'alt' => 'Our work', 'width' => 552, 'align' => 'center'], ...$overrides];
    }

    /** @param  array<string, mixed>  $overrides */
    protected function divider(array $overrides = []): array
    {
        return [...['type' => 'divider', 'id' => 'divider', 'color' => '#E4E4E7', 'thickness' => 1], ...$overrides];
    }

    /** @param  array<string, mixed>  $overrides */
    protected function spacer(array $overrides = []): array
    {
        return [...['type' => 'spacer', 'id' => 'spacer', 'height' => 24], ...$overrides];
    }

    /** @param  array<string, mixed>  $overrides */
    protected function footer(array $overrides = []): array
    {
        return [...[
            'type' => 'footer',
            'id' => 'footer',
            'content' => $this->doc($this->para(
                $this->text('Vite SEO Digital Advertising OPC'),
                $this->hardBreak(),
                $this->text('Visit us', [$this->link('https://viteseo.ph')]),
            )),
            'align' => 'center',
            'font_size' => 12,
            'color' => '#71717A',
        ], ...$overrides];
    }

    // ── Placement ────────────────────────────────────────────────────────────────────────────

    /** The part of an email a block of `$type` is tested in: content when it may go there, then the footer, then the header. */
    protected function placement(string $type): string
    {
        foreach ([EmailDesignSchema::CONTENT, EmailDesignSchema::FOOTER, EmailDesignSchema::HEADER] as $region) {
            if (in_array($type, EmailDesignSchema::REGION_BLOCKS[$region], true)) {
                return $region;
            }
        }

        throw new LogicException("No part of an email holds a {$type} block.");
    }

    /**
     * The validator's answer for `$block` as the only block of `$region`.
     *
     * @param  array<string, mixed>  $block
     * @return array{path: string, message: string}|null
     */
    protected function problemIn(string $region, array $block): ?array
    {
        $validator = new EmailDesignValidator;

        return match ($region) {
            EmailDesignSchema::CONTENT => $validator->contentProblem($this->content([$block])),
            EmailDesignSchema::FOOTER => $validator->layoutProblem($this->layout([], [$block])),
            default => $validator->layoutProblem($this->layout([$block], [])),
        };
    }

    /**
     * The validator's answer for `$block` placed where its type is tested.
     *
     * @param  array<string, mixed>  $block
     * @return array{path: string, message: string}|null
     */
    protected function problemFor(array $block): ?array
    {
        return $this->problemIn($this->placement($block['type']), $block);
    }

    // ── Rich text nodes ──────────────────────────────────────────────────────────────────────

    protected function doc(array ...$nodes): array
    {
        return ['type' => 'doc', 'content' => $nodes];
    }

    protected function para(array ...$inline): array
    {
        return $inline === [] ? ['type' => 'paragraph'] : ['type' => 'paragraph', 'content' => $inline];
    }

    /** @param  list<array<string, mixed>>  $marks */
    protected function text(string $text, array $marks = []): array
    {
        return $marks === [] ? ['type' => 'text', 'text' => $text] : ['type' => 'text', 'text' => $text, 'marks' => $marks];
    }

    protected function hardBreak(): array
    {
        return ['type' => 'hardBreak'];
    }

    /** A link mark exactly as Tiptap writes it, ignored attributes included. */
    protected function link(string $href): array
    {
        return ['type' => 'link', 'attrs' => ['href' => $href, 'target' => '_blank', 'rel' => 'noopener noreferrer nofollow', 'class' => null]];
    }

    protected function bullets(array ...$items): array
    {
        return ['type' => 'bulletList', 'content' => $items];
    }

    protected function numbered(array ...$items): array
    {
        return ['type' => 'orderedList', 'attrs' => ['start' => 1, 'type' => null], 'content' => $items];
    }

    protected function item(array ...$children): array
    {
        return ['type' => 'listItem', 'content' => $children];
    }

    // ── Context ──────────────────────────────────────────────────────────────────────────────

    /**
     * @param  array<string, string>  $tokens
     * @param  array<string, string>|null  $assetUrls
     * @param  array<string, mixed>  $brandStyles
     */
    protected function context(
        array $tokens = [],
        ?array $assetUrls = null,
        ?string $logoUrl = self::LOGO_URL,
        string $logoAlt = 'Vite SEO',
        array $brandStyles = [],
        ?string $brandWebsite = null,
    ): EmailDesignContext {
        return new EmailDesignContext(
            tokens: [...[
                'first_name' => 'Maria',
                'contact_name' => 'Maria Santos',
                'business_name' => 'Santos Bakery',
                'industry' => 'Bakery',
                'city' => 'Quezon City',
                'sender_name' => 'Alex Reyes',
                'sender_title' => 'Growth Consultant',
                'sender_phone' => '+63 917 123 4567',
            ], ...$tokens],
            assetUrls: $assetUrls ?? [self::ASSET_ID => self::ASSET_URL],
            logoUrl: $logoUrl,
            logoAlt: $logoAlt,
            brandStyles: $brandStyles,
            brandWebsite: $brandWebsite,
        );
    }
}
