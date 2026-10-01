<?php

declare(strict_types=1);

namespace Tests\Unit\Crm\EmailDesign;

use App\Modules\Crm\Support\EmailDesign\EmailDesignContext;
use App\Modules\Crm\Support\EmailDesign\EmailDesignRenderer;
use PHPUnit\Framework\TestCase;

/**
 * The placeholder-preserving context: how a designed template's stored plain-text body keeps its
 * `{{tokens}}` for TemplateRenderer to fill in per lead.
 */
class EmailDesignContextTest extends TestCase
{
    use BuildsEmailDesigns;

    public function test_preserving_placeholders_maps_each_key_to_itself_and_nothing_else(): void
    {
        $context = EmailDesignContext::preservingPlaceholders(['first_name', 'sender_name']);

        $this->assertSame(['first_name' => '{{first_name}}', 'sender_name' => '{{sender_name}}'], $context->tokens);
        $this->assertSame([], $context->assetUrls);
        $this->assertNull($context->logoUrl);
        $this->assertSame([], EmailDesignContext::preservingPlaceholders([])->tokens);
    }

    public function test_the_plain_text_form_keeps_placeholders_normalized_for_template_renderer(): void
    {
        $layout = $this->layout([], [$this->footer(['content' => $this->doc($this->para($this->text('Sent by {{ sender_name }}')))])]);
        $content = $this->content(
            [$this->heading(['text' => 'Hi {{ first_name }} from {{city}}']), $this->button(['label' => 'Talk to {{sender_name}}'])],
            ['sign_off' => 'Thanks, {{first_name}}'],
        );

        $text = (new EmailDesignRenderer)->toText($layout, $content, EmailDesignContext::preservingPlaceholders(['first_name', 'city', 'sender_name']));

        // sender_title and sender_phone are not keys here, so the signature prints only the name placeholder.
        $this->assertSame(
            "Hi {{first_name}} from {{city}}\n\nTalk to {{sender_name}}: https://viteseo.ph/book\n\nThanks, {{first_name}}\n{{sender_name}}\n\nSent by {{sender_name}}",
            $text,
        );
    }
}
