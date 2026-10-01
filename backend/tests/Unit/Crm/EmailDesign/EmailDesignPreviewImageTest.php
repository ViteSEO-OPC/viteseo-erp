<?php

namespace Tests\Unit\Crm\EmailDesign;

use App\Modules\Crm\Support\EmailDesign\EmailDesignContext;
use App\Modules\Crm\Support\EmailDesign\EmailDesignRenderer;
use Tests\TestCase;

/**
 * Settings previews show the organization logo inline, because the preview frame loads no remote image. That
 * must not loosen what a context URL may be: the PNG bytes are inlined only for a URL that already passed the
 * renderer's check, and a data URI is still never accepted AS a URL.
 */
class EmailDesignPreviewImageTest extends TestCase
{
    use BuildsEmailDesigns;

    private const PNG = "\x89PNG\r\n\x1a\n-pixels-";

    /** @param  array<string, string>  $previewImages */
    private function html(array $previewImages, ?string $logoUrl = self::LOGO_URL): string
    {
        $base = $this->context(logoUrl: $logoUrl);

        return app(EmailDesignRenderer::class)->toHtml(
            $this->layout([$this->logo()], []),
            $this->content([$this->heading()]),
            new EmailDesignContext(tokens: $base->tokens, assetUrls: $base->assetUrls, logoUrl: $base->logoUrl, logoAlt: $base->logoAlt, previewImages: $previewImages),
        );
    }

    public function test_a_preview_inlines_the_png_the_server_resolved_for_the_logo(): void
    {
        $html = $this->html([self::LOGO_URL => self::PNG]);

        $this->assertStringContainsString('<img src="data:image/png;base64,'.base64_encode(self::PNG).'"', $html);
        $this->assertStringNotContainsString(self::LOGO_URL, $html);
    }

    public function test_without_preview_bytes_the_public_url_is_used(): void
    {
        $this->assertStringContainsString('<img src="'.self::LOGO_URL.'"', $this->html([]));
    }

    public function test_bytes_that_are_not_a_png_are_ignored(): void
    {
        $this->assertStringContainsString('<img src="'.self::LOGO_URL.'"', $this->html([self::LOGO_URL => 'GIF89a<svg onload=alert(1)>']));
    }

    public function test_preview_bytes_for_another_url_change_nothing(): void
    {
        $this->assertStringContainsString('<img src="'.self::LOGO_URL.'"', $this->html(['https://other.example/x.png' => self::PNG]));
    }

    public function test_a_data_uri_is_still_never_accepted_as_a_url(): void
    {
        $dataUri = 'data:image/png;base64,iVBORw0KGgo=';

        $this->assertStringNotContainsString('<img', $this->html([$dataUri => self::PNG], $dataUri));
    }

    public function test_without_a_logo_url_preview_bytes_render_nothing(): void
    {
        $this->assertStringNotContainsString('<img', $this->html([self::LOGO_URL => self::PNG], null));
    }
}
