<?php

namespace App\Modules\Crm\Requests\Concerns;

use App\Modules\Crm\Models\EmailDesign;
use App\Modules\Crm\Models\EmailTemplate;
use App\Modules\Crm\Services\Outreach\EmailDesignService;
use App\Modules\Crm\Services\Outreach\EmailTemplateDesignService;
use App\Modules\Crm\Services\Outreach\EmailTemplateManagementService;
use Illuminate\Validation\Validator;

/**
 * A designed template's `content` and the `email_design_id` it is shown in.
 *
 * ── One JSON string ──
 * Content arrives as ONE JSON-encoded string field, never as nested request fields. Laravel's global
 * TrimStrings middleware trims every string in a request; inside the content that would delete the spaces
 * between differently formatted words. A single string is trimmed only at its ends, which JSON ignores, so
 * no global middleware needs an exception.
 *
 * ── Checked in the design it will be shown in ──
 * The design is the one named, or the template's current one, or the organization's default. Content is
 * checked by EmailTemplateDesignService against that design's layout — the same validator, renderer and Gmail
 * size guard the preview uses. Naming a design without sending content re-checks the stored content in the
 * new design. A design is only for a designed template, and must belong to the current organization.
 */
trait ValidatesTemplateContent
{
    protected function validateContent(Validator $validator, ?EmailTemplate $template): void
    {
        // A field already failing its own rules (not a string, not an integer) reports that first.
        if ($validator->errors()->hasAny(['content', 'email_design_id'])) {
            return;
        }

        $content = $this->input('content');
        $designId = $this->input('email_design_id');

        $design = null;
        if ($designId !== null) {
            $design = EmailDesign::query()->find($designId);
            if ($design === null) {
                $validator->errors()->add('email_design_id', EmailTemplateManagementService::UNKNOWN_DESIGN);

                return;
            }
        }

        $sendsContent = is_string($content);
        $designed = $sendsContent || ($this->missing('content') && $template?->isDesigned() === true);

        if ($design !== null && ! $designed) {
            $validator->errors()->add('email_design_id', EmailTemplateManagementService::DESIGN_ONLY_FOR_DESIGNED);

            return;
        }
        if (! $sendsContent && $design === null) {
            return;
        }

        $rendering = app(EmailTemplateDesignService::class);
        $layout = ($design ?? $template?->design ?? app(EmailDesignService::class)->defaultDesign())->layout;

        if ($sendsContent) {
            $problem = $rendering->contentProblem($content, $layout);
            if ($problem !== null) {
                $validator->errors()->add($problem['key'], $problem['message']);
            }

            return;
        }

        // Only the design changes: the template's stored content must still make a sendable email in it.
        $message = $rendering->composedProblem($layout, $template->content);
        if ($message !== null) {
            $validator->errors()->add('email_design_id', $message);
        }
    }

    /** @return array<string, string> */
    protected function contentMessages(): array
    {
        return ['content.string' => 'Send the template content as a JSON-encoded string.'];
    }
}
