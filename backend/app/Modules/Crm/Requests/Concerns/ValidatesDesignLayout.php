<?php

namespace App\Modules\Crm\Requests\Concerns;

use App\Modules\Crm\Services\Outreach\EmailTemplateDesignService;
use Illuminate\Validation\Validator;

/**
 * An email design's `layout`: ONE JSON-encoded string, for the same TrimStrings reason as template content
 * (see ValidatesTemplateContent), checked by EmailTemplateDesignService with the same validator the renderer
 * relies on. Errors are keyed `layout` or `layout.` and the path inside it.
 */
trait ValidatesDesignLayout
{
    protected function validateLayout(Validator $validator): void
    {
        $layout = $this->input('layout');

        // A layout already failing its own rules (not a string, missing) reports that first.
        if (! is_string($layout) || $validator->errors()->has('layout')) {
            return;
        }

        $problem = app(EmailTemplateDesignService::class)->layoutProblem($layout);
        if ($problem !== null) {
            $validator->errors()->add($problem['key'], $problem['message']);
        }
    }

    /** @return array<string, string> */
    protected function layoutMessages(): array
    {
        return ['layout.string' => 'Send the design layout as a JSON-encoded string.'];
    }
}
