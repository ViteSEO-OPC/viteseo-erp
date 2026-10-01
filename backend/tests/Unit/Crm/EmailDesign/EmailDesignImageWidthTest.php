<?php

namespace Tests\Unit\Crm\EmailDesign;

use App\Modules\Crm\Support\EmailDesign\EmailDesignRenderer;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The width chosen for a logo or image is the width it renders at. It is carried by the width attribute and the
 * inline `width` — never only by max-width, which a client or viewer forcing `img{max-width:100%}` overrides, so
 * the image would fill the column whatever width was chosen. max-width:100% remains only to shrink it on a
 * narrow screen.
 */
class EmailDesignImageWidthTest extends TestCase
{
    use BuildsEmailDesigns;

    /** @return array<string, array{int}> */
    public static function widths(): array
    {
        return ['80px' => [80], '200px' => [200], 'the widest logo' => [300]];
    }

    #[DataProvider('widths')]
    public function test_a_logo_renders_at_the_chosen_width(int $width): void
    {
        $html = app(EmailDesignRenderer::class)->toHtml(
            $this->layout([$this->logo(['width' => $width])], []),
            $this->content([$this->heading()]),
            $this->context(),
        );

        $this->assertStringContainsString('width="'.$width.'" alt="Vite SEO" style="display:block;width:'.$width.'px;max-width:100%;height:auto;', $html);
        $this->assertStringNotContainsString('width:100%;max-width:'.$width.'px', $html);
    }

    public function test_different_logo_widths_render_differently(): void
    {
        $render = fn (int $width): string => app(EmailDesignRenderer::class)->toHtml(
            $this->layout([$this->logo(['width' => $width])], []),
            $this->content([$this->heading()]),
            $this->context(),
        );

        $this->assertStringContainsString('width:80px;', $render(80));
        $this->assertStringNotContainsString('width:200px;', $render(80));
        $this->assertStringContainsString('width:200px;', $render(200));
    }

    public function test_an_image_block_renders_at_the_chosen_width(): void
    {
        $html = app(EmailDesignRenderer::class)->toHtml(
            $this->layout([], []),
            $this->content([$this->heading(), $this->image(['width' => 240])]),
            $this->context(),
        );

        $this->assertStringContainsString('width="240"', $html);
        $this->assertStringContainsString('style="display:block;width:240px;max-width:100%;height:auto;', $html);
    }
}
